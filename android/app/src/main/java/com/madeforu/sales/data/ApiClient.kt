package com.madeforu.sales.data

import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Prefs
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.decodeFromJsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit

/**
 * The one place that talks HTTP.
 *
 * Every response the API sends is an envelope: {"ok":true, ...} or
 * {"ok":false,"error":{"code","message"}}. Unwrapping it here means no
 * screen ever has to think about transport — a screen gets either the
 * payload it asked for or a message it can show.
 */
class ApiClient(private val prefs: Prefs) {

    private val json = Json {
        ignoreUnknownKeys = true      // the server may grow fields; old builds keep working
        coerceInputValues = true      // a null where a number is expected becomes 0, not a crash
        isLenient = true
        encodeDefaults = true
    }

    private val http = OkHttpClient.Builder()
        // A stall runs on patchy mobile data. These are long enough to ride
        // out a bad moment and short enough that a partner is not left
        // staring at a spinner wondering whether to tap again.
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    private val jsonMedia = "application/json; charset=utf-8".toMediaType()

    /** GET endpoint.php?action=…&extra… */
    suspend fun get(endpoint: String, action: String, params: Map<String, String> = emptyMap()): ApiResult<JsonObject> =
        withContext(Dispatchers.IO) {
            val base = prefs.currentBaseUrl()
            val query = buildString {
                append("action=").append(encode(action))
                params.forEach { (k, v) ->
                    if (v.isNotBlank()) append("&").append(encode(k)).append("=").append(encode(v))
                }
            }
            val request = Request.Builder()
                .url("$base$endpoint?$query")
                .get()
                .applyAuth()
                .build()
            execute(request)
        }

    /** POST endpoint.php?action=… with a JSON body. */
    suspend fun post(endpoint: String, action: String, body: JsonObject = JsonObject(emptyMap())): ApiResult<JsonObject> =
        withContext(Dispatchers.IO) {
            val base = prefs.currentBaseUrl()
            val request = Request.Builder()
                .url("$base$endpoint?action=${encode(action)}")
                .post(json.encodeToString(JsonObject.serializer(), body).toRequestBody(jsonMedia))
                .applyAuth()
                .build()
            execute(request)
        }

    /** Decode a successful envelope into a typed model. */
    inline fun <reified T> decode(payload: JsonObject): T = jsonParser.decodeFromJsonElement<T>(payload)

    @PublishedApi
    internal val jsonParser: Json get() = json

    /** The absolute URL of a bill page, for the WebView preview and printing. */
    suspend fun billUrl(orderId: Int, thermal: Boolean): String {
        val base = prefs.currentBaseUrl()
        val token = prefs.currentToken().orEmpty()
        val size = if (thermal) "&size=thermal" else ""
        // Auth rides in the query because a WebView load cannot carry a
        // header — but as `auth`, never `token`. On bills.php `token` means
        // a bill's own public link token, so sending a session token there
        // looked up a bill that does not exist and the printer rendered
        // {"ok":false,...} instead of the document.
        return "${base}bills.php?action=html&order_id=$orderId$size&auth=${encode(token)}"
    }

    private suspend fun Request.Builder.applyAuth(): Request.Builder {
        val token = prefs.currentToken()
        if (!token.isNullOrBlank()) header("Authorization", "Bearer $token")
        header("Accept", "application/json")
        return this
    }

    private fun execute(request: Request): ApiResult<JsonObject> = try {
        http.newCall(request).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            parseEnvelope(raw, response.code, request.url.toString())
        }
    } catch (e: UnknownHostException) {
        ApiResult.Failure(ApiResult.CODE_NETWORK, "No internet connection.")
    } catch (e: SocketTimeoutException) {
        ApiResult.Failure(ApiResult.CODE_NETWORK, "The server took too long to answer. Try again.")
    } catch (e: IOException) {
        ApiResult.Failure(ApiResult.CODE_NETWORK, "Could not reach the server. Check the connection.")
    }

    /**
     * Turn a reply into the payload, or into a sentence worth reading.
     *
     * The message now names the URL that was actually called. "Check the
     * server address in Settings" on its own sent somebody to look at an
     * address that read perfectly correctly, because the fault was in
     * what the app appended to it, not in what they had typed. The URL
     * is the one thing they cannot see and the one thing that settles it.
     */
    private fun parseEnvelope(raw: String, code: Int, url: String): ApiResult<JsonObject> {
        if (raw.isBlank()) {
            return ApiResult.Failure(
                "empty",
                "$url returned HTTP $code with an empty reply. That is usually a PHP " +
                    "fatal error with error display switched off — a file that is missing " +
                    "or half-uploaded. Re-upload the api/ folder.",
            )
        }
        val element: JsonElement = try {
            json.parseToJsonElement(raw)
        } catch (e: Exception) {
            // Shared hosting loves to prepend a warning or an HTML error
            // page. Say so plainly instead of "Unexpected character".
            val body = raw.trim()
            return ApiResult.Failure(
                "bad_response",
                when {
                    // 404 is worth its own sentence: it is not the address
                    // being wrong, it is one file not being on the server,
                    // which is what a part-finished upload looks like.
                    body.startsWith("<") && code == 404 ->
                        "$url is not on the server (HTTP 404). Upload the api/ folder again — " +
                            "that file is missing from it."
                    body.startsWith("<") ->
                        "$url returned a web page instead of data (HTTP $code). " +
                            "Either the address is wrong or that file is not on the server."
                    else ->
                        "$url replied with something that is not data (HTTP $code). " +
                            "It begins: " + body.lineSequence().first().take(160)
                },
            )
        }
        val obj = element as? JsonObject
            ?: return ApiResult.Failure(
                "bad_response",
                "$url replied with data the app could not use (HTTP $code).",
            )

        val ok = (obj["ok"] as? JsonPrimitive)?.content == "true"
        if (ok) return ApiResult.Success(obj)

        val error = obj["error"]?.jsonObject
        return ApiResult.Failure(
            error?.get("code")?.jsonPrimitive?.content ?: "failed",
            error?.get("message")?.jsonPrimitive?.content
                ?: "Something went wrong (HTTP $code) at $url.",
        )
    }

    private fun encode(v: String): String = java.net.URLEncoder.encode(v, "UTF-8")

    companion object {
        /** Small helper so call sites read as `body { "id" to 4 }`. */
        fun body(build: MutableMap<String, JsonElement>.() -> Unit): JsonObject {
            val map = LinkedHashMap<String, JsonElement>()
            map.build()
            return JsonObject(map)
        }

        fun str(v: String?): JsonElement = if (v == null) JsonPrimitive("") else JsonPrimitive(v)
        fun num(v: Number): JsonElement = JsonPrimitive(v)
        fun bool(v: Boolean): JsonElement = JsonPrimitive(v)
        fun empty(): JsonObject = buildJsonObject { }
    }
}
