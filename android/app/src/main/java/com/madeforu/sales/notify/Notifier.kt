package com.madeforu.sales.notify

import android.Manifest
import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.madeforu.sales.MainActivity
import com.madeforu.sales.R
import com.madeforu.sales.data.ActivityItem

/**
 * Turns activity into system notifications.
 *
 * A handful of changes get one notification each, so each can be read
 * and tapped on its own. A burst (crediting an event touches every order
 * in it) becomes a single summary instead of a wall of alerts.
 */
object Notifier {

    const val CHANNEL_ID = "activity"
    private const val GROUP = "com.madeforu.sales.ACTIVITY"
    private const val SUMMARY_ID = 1
    private const val BURST = 4

    /** Tapping an order's notification opens that order. */
    const val EXTRA_ORDER_ID = "open_order_id"

    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(CHANNEL_ID) != null) return
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL_ID, "Sales, expenses and movements", NotificationManager.IMPORTANCE_HIGH)
                .apply {
                    description = "New sales, payments, order changes, expenses and account " +
                        "movements, whether made on the website, the web app or this app."
                },
        )
    }

    fun canPost(context: Context): Boolean =
        (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED) &&
            NotificationManagerCompat.from(context).areNotificationsEnabled()

    @SuppressLint("MissingPermission") // checked in canPost()
    fun show(context: Context, items: List<ActivityItem>) {
        if (items.isEmpty() || !canPost(context)) return
        ensureChannel(context)
        val manager = NotificationManagerCompat.from(context)

        if (items.size >= BURST) {
            val style = NotificationCompat.InboxStyle()
            items.takeLast(6).forEach { style.addLine(it.title + " — " + it.body.lineSequence().first()) }
            if (items.size > 6) style.setSummaryText("+${items.size - 6} more")
            manager.notify(
                SUMMARY_ID,
                base(context, null)
                    .setContentTitle("${items.size} updates")
                    .setContentText(items.last().title)
                    .setStyle(style)
                    .build(),
            )
            return
        }

        items.forEach { item ->
            manager.notify(
                // Stable per change, so a repeat replaces rather than stacks.
                (item.kind + ":" + item.id).hashCode(),
                base(context, item.orderId)
                    .setContentTitle(item.title)
                    .setContentText(item.body.lineSequence().first())
                    .setStyle(NotificationCompat.BigTextStyle().bigText(item.body))
                    .setGroup(GROUP)
                    .build(),
            )
        }
    }

    /** One pushed message, as lib_push.php sent it. */
    @SuppressLint("MissingPermission") // checked in canPost()
    fun showPush(context: Context, title: String, body: String, tag: String, orderId: Int?) {
        if (!canPost(context)) return
        ensureChannel(context)
        NotificationManagerCompat.from(context).notify(
            // Stable per change, so a repeat replaces rather than stacks.
            (tag.ifBlank { title + body }).hashCode(),
            base(context, orderId)
                .setContentTitle(title)
                .setContentText(body.lineSequence().firstOrNull().orEmpty())
                .setStyle(NotificationCompat.BigTextStyle().bigText(body))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setGroup(GROUP)
                .build(),
        )
    }

    private fun base(context: Context, orderId: Int?): NotificationCompat.Builder {
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (orderId != null) putExtra(EXTRA_ORDER_ID, orderId)
        }
        val pending = PendingIntent.getActivity(
            context,
            orderId ?: 0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_stat_notify)
            .setColor(0xFFF54A77.toInt())
            .setContentIntent(pending)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
    }
}
