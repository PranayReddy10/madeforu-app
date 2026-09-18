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

    suspend fun currentBaseUrl(): String = baseUrl.first()
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
        val cleaned = url.trim().let { if (it.endsWith("/")) it else "$it/" }
        context.dataStore.edit { it[Keys.BASE_URL] = cleaned }
    }

    suspend fun setTheme(theme: String) {
        context.dataStore.edit { it[Keys.THEME] = theme }
    }
}
