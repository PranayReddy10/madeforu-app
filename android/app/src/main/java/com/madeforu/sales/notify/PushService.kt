package com.madeforu.sales.notify

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.madeforu.sales.core.ServiceLocator
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking

/**
 * Where Firebase delivers a push.
 *
 * With the app in the background or closed, Android draws the push's
 * notification itself (lib_push.php sends one), which works even on
 * phones that stop closed apps from starting, and a tap opens the order.
 * With the app open, Android hands the message here instead, and this
 * draws the same notification.
 */
class PushService : FirebaseMessagingService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        // The switch in Settings: off means off, pushed or not.
        val on = runBlocking { ServiceLocator.prefs(applicationContext).notificationsOn.first() }
        if (!on) return
        Notifier.showPush(
            context = applicationContext,
            title = data["title"].orEmpty().ifBlank { "MadeForU" },
            body = data["body"].orEmpty(),
            tag = data["tag"].orEmpty(),
            orderId = data["order_id"]?.toIntOrNull(),
        )
    }

    /** Firebase rotated this phone's token; tell the server the new one. */
    override fun onNewToken(token: String) {
        scope.launch {
            if (!ServiceLocator.prefs(applicationContext).token.first().isNullOrBlank()) {
                PushSetup.setup(applicationContext)
            }
        }
    }
}
