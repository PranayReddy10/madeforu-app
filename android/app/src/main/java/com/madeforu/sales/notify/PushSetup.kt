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
    private const val PROBLEM = "problem"

    private fun store(context: Context) =
        context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    /** Why this phone is not on real-time push, in words; empty when it is. */
    fun problem(context: Context): String = store(context).getString(PROBLEM, "").orEmpty()

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

    /** Null when Firebase is configured, or why it could not be. */
    private fun configure(context: Context, c: AndroidPushConfig): String? {
        if (FirebaseApp.getApps(context).isNotEmpty()) {
            // Firebase starts once per launch. New settings from the website
            // only take effect after the app is closed and opened again.
            val running = FirebaseApp.getInstance().options
            return if (running.applicationId == c.appId && running.apiKey == c.apiKey) null
            else "The Firebase settings changed on the website. Close the app fully (swipe it away) and open it again."
        }
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
            null
        } catch (e: Exception) {
            "Firebase would not start with the Android settings saved on the website " +
                "(${e.message}). Check the App ID, apiKey and projectId on the Notifications page."
        }
    }

    /**
     * Ask the server whether it pushes, and if so register this phone.
     * Returns null when push is now active, or the reason it is not, in
     * words Settings can show. Any failure leaves the app on its polling
     * fallback.
     */
    suspend fun setup(context: Context): String? {
        val app = context.applicationContext
        val problem = trySetup(app)
        store(app).edit()
            .putBoolean(ACTIVE, problem == null)
            .putString(PROBLEM, problem.orEmpty())
            .apply()
        return problem
    }

    private suspend fun trySetup(app: Context): String? {
        val repository = ServiceLocator.repository(app)
        val config = when (val r = repository.pushConfig()) {
            is ApiResult.Success -> r.value
            is ApiResult.Failure -> return "Could not ask the server about push: " + r.message
        }
        val android = config.android
        if (!config.enabled || android == null || android.appId.isBlank()) {
            return (repository.pushStatus() as? ApiResult.Success)?.value
                ?: "Push is not set up on the server yet (website → Notifications)."
        }
        store(app).edit()
            .putString(API_KEY, android.apiKey)
            .putString(APP_ID, android.appId)
            .putString(PROJECT_ID, android.projectId)
            .putString(SENDER_ID, android.messagingSenderId)
            .apply()
        configure(app, android)?.let { return it }

        val token = try {
            FirebaseMessaging.getInstance().token.await()
        } catch (e: Exception) {
            return tokenHint(e, app.packageName)
        }
        return register(app, token)
    }

    /** Firebase's refusal, with what to change for the common ones. */
    private fun tokenHint(e: Exception, pkg: String): String {
        val msg = generateSequence<Throwable>(e) { it.cause }.mapNotNull { it.message }.joinToString(" / ")
        val fix = when {
            msg.contains("SERVICE_NOT_AVAILABLE") ->
                "This phone could not reach Google. Check the connection, and that Google Play services is installed and up to date."
            msg.contains("AUTHENTICATION_FAILED") || msg.contains("INVALID_SENDER") ->
                "The Android settings do not match this project. Save the Android app again on the website (App ID and messagingSenderId from the same Firebase project)."
            msg.contains("API key", ignoreCase = true) || msg.contains("403") || msg.contains("PERMISSION_DENIED") ->
                "Google refused the apiKey. On the website's Notifications page, upload google-services.json for the Android app so its own apiKey is used."
            msg.contains("MISSING_INSTANCEID_SERVICE") || msg.contains("Play", ignoreCase = true) ->
                "Google Play services is missing or out of date on this phone."
            else -> null
        }
        val debugNote = if (pkg.endsWith(".debug")) {
            " This is a debug build ($pkg): add an Android app with that package in Firebase too, " +
                "and save its App ID on the website."
        } else ""
        return "Firebase refused this phone. " + (fix?.let { "$it " } ?: "") + "Details: " + msg.take(200) + debugNote
    }

    /** Tell the server this token is this phone. Also used when Firebase rotates it. Null when accepted. */
    suspend fun register(context: Context, token: String): String? {
        val app = context.applicationContext
        return when (val r = ServiceLocator.repository(app).pushRegister(token, Build.MANUFACTURER + " " + Build.MODEL)) {
            is ApiResult.Success -> null
            is ApiResult.Failure -> "The server did not accept this phone: " + r.message
        }
    }

    private suspend fun <T> Task<T>.await(): T = suspendCancellableCoroutine { cont ->
        addOnCompleteListener { task ->
            if (task.isSuccessful) cont.resume(task.result)
            else cont.resumeWithException(task.exception ?: IllegalStateException("Firebase task failed"))
        }
    }
}
