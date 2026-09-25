package com.madeforu.sales.core

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.madeforu.sales.BuildConfig
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "madeforu")

/**
 * What survives the app being killed: the server URL, the session token,
 * who is signed in, and the theme choice. Nothing else — every business
 * number is read from the server, so there is no stale copy to reconcile.
 */
class Prefs(private val context: Context) {

    private object Keys {
        val BASE_URL = stringPreferencesKey("base_url")
        val TOKEN = stringPreferencesKey("token")
        val ADMIN_NAME = stringPreferencesKey("admin_name")
        val ADMIN_PHONE = stringPreferencesKey("admin_phone")
        val THEME = stringPreferencesKey("theme")
    }

    val baseUrl: Flow<String> = context.dataStore.data.map {
        it[Keys.BASE_URL]?.takeIf { url -> url.isNotBlank() } ?: BuildConfig.DEFAULT_BASE_URL
    }

    val token: Flow<String?> = context.dataStore.data.map { it[Keys.TOKEN] }

    val adminName: Flow<String> = context.dataStore.data.map { it[Keys.ADMIN_NAME] ?: "" }

    /** "system" (default), "light" or "dark". */
    val theme: Flow<String> = context.dataStore.data.map { it[Keys.THEME] ?: "system" }

    /**
     * The API address, in the shape the client can actually concatenate.
     *
     * Normalised on READ, not only on write. setBaseUrl() has always
     * added the trailing slash, but a value saved by an older build sits
     * in DataStore untouched forever — and ApiClient builds its URLs as
     * "$base$endpoint", so a missing slash turns api + orders.php into
     * apiorders.php. The server then serves its 404 page, the app says
     * "returned a web page instead of data. Check the server address",
     * and the address looks perfectly correct to whoever checks it.
     *
     * A missing scheme is added too: someone typing the address from
     * memory writes sale.madeforu.co.in/api, which is not a URL OkHttp
     * can use.
     */
    suspend fun currentBaseUrl(): String = normaliseBaseUrl(baseUrl.first())
    suspend fun currentToken(): String? = token.first()

    suspend fun saveSession(token: String, name: String, phone: String) {
        context.dataStore.edit {
            it[Keys.TOKEN] = token
            it[Keys.ADMIN_NAME] = name
            it[Keys.ADMIN_PHONE] = phone
        }
    }

    suspend fun clearSession() {
        // The base URL and theme deliberately survive a sign-out: the next
        // person to sign in on this phone is almost always the same person,
        // on the same server.
        context.dataStore.edit {
            it.remove(Keys.TOKEN)
            it.remove(Keys.ADMIN_NAME)
            it.remove(Keys.ADMIN_PHONE)
        }
    }

    suspend fun setBaseUrl(url: String) {
        val cleaned = normaliseBaseUrl(url)
        context.dataStore.edit { it[Keys.BASE_URL] = cleaned }
    }

    companion object {
        /**
         * Trim it, give it a scheme, and end it with exactly one slash.
         *
         * Shared by the read and the write so the two cannot drift: an
         * address typed today and one saved two versions ago end up in
         * the same shape.
         */
        fun normaliseBaseUrl(raw: String): String {
            var url = raw.trim()
            if (url.isEmpty()) return BuildConfig.DEFAULT_BASE_URL
            if (!url.startsWith("http://") && !url.startsWith("https://")) {
                url = "https://" + url.removePrefix("//")
            }
            // trimEnd rather than a single removeSuffix: a pasted address
            // can arrive with two.
            return url.trimEnd('/') + "/"
        }
    }

    suspend fun setTheme(theme: String) {
        context.dataStore.edit { it[Keys.THEME] = theme }
    }
}
