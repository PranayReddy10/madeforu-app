package com.madeforu.sales.notify

import android.content.Context
import android.os.Build
import com.google.android.gms.tasks.Task
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.AndroidPushConfig
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/**
 * Real-time push through Firebase Cloud Messaging.
 *
 * The app carries no google-services.json. Its Firebase settings come
 * from the server (api/push.php, filled from firebase-config.php), so
 * the same build works before and after Firebase is set up, and a
 * project change needs no rebuild. The settings are kept in plain
 * SharedPreferences because a push can start the process cold, and
 * Firebase has to be configured synchronously in Application.onCreate
 * before the message is handed over.
 *
 * While push is active, the 15-minute background check and the
 * 30-second in-app check stand down, so nothing is announced twice.
 */
object PushSetup {

    private const val FILE = "push"
    private const val API_KEY = "api_key"
    private const val APP_ID = "app_id"
    private const val PROJECT_ID = "project_id"
    private const val SENDER_ID = "sender_id"
    private const val ACTIVE = "active"

    private fun store(context: Context) =
        context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    /** True once this phone is registered with the server for push. */
    fun isActive(context: Context): Boolean = store(context).getBoolean(ACTIVE, false)

    /** Signed out: fall back to checking until the next sign-in registers again. */
    fun reset(context: Context) {
        store(context).edit().putBoolean(ACTIVE, false).apply()
    }

    /** From Application.onCreate: configure Firebase from the last settings the server gave. */
    fun initFromCache(context: Context) {
        val s = store(context)
        val appId = s.getString(APP_ID, null) ?: return
        configure(
            context,
            AndroidPushConfig(
                apiKey = s.getString(API_KEY, "").orEmpty(),
                appId = appId,
                projectId = s.getString(PROJECT_ID, "").orEmpty(),
                messagingSenderId = s.getString(SENDER_ID, "").orEmpty(),
            ),
        )
    }

    private fun configure(context: Context, c: AndroidPushConfig): Boolean {
        if (FirebaseApp.getApps(context).isNotEmpty()) return true
        return try {
            FirebaseApp.initializeApp(
                context,
                FirebaseOptions.Builder()
                    .setApiKey(c.apiKey)
                    .setApplicationId(c.appId)
                    .setProjectId(c.projectId)
                    .setGcmSenderId(c.messagingSenderId)
                    .build(),
            )
            true
        } catch (e: Exception) {
            false
        }
    }

    /**
     * Ask the server whether it pushes, and if so register this phone.
     * Returns whether push is now active. Any failure leaves the app on
     * its polling fallback; nothing here is worth an error on screen.
     */
    suspend fun setup(context: Context): Boolean {
        val app = context.applicationContext
        val repository = ServiceLocator.repository(app)
        val config = (repository.pushConfig() as? ApiResult.Success)?.value
        val android = config?.android
        if (config == null || !config.enabled || android == null || android.appId.isBlank()) {
            reset(app)
            return false
        }
        store(app).edit()
            .putString(API_KEY, android.apiKey)
            .putString(APP_ID, android.appId)
            .putString(PROJECT_ID, android.projectId)
            .putString(SENDER_ID, android.messagingSenderId)
            .apply()
        if (!configure(app, android)) return false

        val token = try {
            FirebaseMessaging.getInstance().token.await()
        } catch (e: Exception) {
            return false
        }
        return register(app, token)
    }

    /** Tell the server this token is this phone. Also used when Firebase rotates it. */
    suspend fun register(context: Context, token: String): Boolean {
        val app = context.applicationContext
        val result = ServiceLocator.repository(app).pushRegister(token, Build.MANUFACTURER + " " + Build.MODEL)
        val ok = result is ApiResult.Success
        store(app).edit().putBoolean(ACTIVE, ok).apply()
        return ok
    }

    private suspend fun <T> Task<T>.await(): T = suspendCancellableCoroutine { cont ->
        addOnCompleteListener { task ->
            if (task.isSuccessful) cont.resume(task.result)
            else cont.resumeWithException(task.exception ?: IllegalStateException("Firebase task failed"))
        }
    }
}
