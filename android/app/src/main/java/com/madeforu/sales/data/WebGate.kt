package com.madeforu.sales.data

import android.content.Context
import android.webkit.CookieManager
import android.webkit.WebSettings
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl

/**
 * Getting past the bot check that sits in front of the server.
 *
 * sale.madeforu.co.in is served through Cloudflare. Its bot protection
 * does not go by the User-Agent -- it looks at how the connection itself
 * is made, and OkHttp does not look like a browser however it introduces
 * itself. A browser is sent a short JavaScript check, passes it, and is
 * given a `cf_clearance` cookie that lets every later request through.
 * That is why the web app, opened in Chrome, works against the very same
 * api/ files that answer the Android app with a 403.
 *
 * So the app does what the browser does: when Cloudflare asks, it opens
 * the check in a WebView, lets it pass, and keeps the cookie. Two things
 * make that cookie count for OkHttp's requests too:
 *
 *  - OkHttp reads and writes the WebView's own cookie store, so a cookie
 *    earned in the WebView is sent with the next API call.
 *  - OkHttp sends the WebView's own User-Agent, because Cloudflare ties
 *    the clearance to the agent that earned it.
 */
object WebGate {

    private val _needed = MutableStateFlow(false)

    /** True while a request has been stopped by the check and it has not been passed yet. */
    val needed: StateFlow<Boolean> = _needed.asStateFlow()

    fun raise() { _needed.value = true }

    fun clear() { _needed.value = false }

    /** The cookie Cloudflare hands out once the check has been passed. */
    const val CLEARANCE_COOKIE = "cf_clearance"

    @Volatile private var agent: String? = null

    /**
     * The User-Agent every WebView in this app sends, and so the one OkHttp
     * must send for the clearance cookie to be honoured.
     */
    fun userAgent(context: Context): String =
        agent ?: (
            try {
                WebSettings.getDefaultUserAgent(context)
            } catch (e: Exception) {
                // No WebView on the phone (it is being updated, say). The
                // check cannot be passed without one anyway, so any
                // browser-shaped agent will do until it is back.
                System.getProperty("http.agent")
                    ?: "Mozilla/5.0 (Linux; Android) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36"
            }
            ).also { agent = it }

    /** True once the WebView's cookie store holds a clearance for this address. */
    fun hasClearance(url: String): Boolean =
        cookies()?.getCookie(url)?.contains("$CLEARANCE_COOKIE=") == true

    private fun cookies(): CookieManager? = try {
        CookieManager.getInstance()
    } catch (e: Exception) {
        null // no WebView installed right now; carry on without cookies
    }

    /** OkHttp's cookies, kept in the WebView's store so the two share them. */
    val cookieJar: CookieJar = object : CookieJar {
        override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
            val store = cookies() ?: return
            val address = url.toString()
            cookies.forEach { store.setCookie(address, it.toString()) }
            store.flush()
        }

        override fun loadForRequest(url: HttpUrl): List<Cookie> {
            val header = cookies()?.getCookie(url.toString()) ?: return emptyList()
            return header.split(';').mapNotNull { Cookie.parse(url, it.trim()) }
        }
    }
}
