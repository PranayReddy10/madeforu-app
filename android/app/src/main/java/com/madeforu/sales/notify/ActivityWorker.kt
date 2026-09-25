package com.madeforu.sales.notify

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.madeforu.sales.core.ServiceLocator
import kotlinx.coroutines.flow.first
import java.util.concurrent.TimeUnit

/**
 * Checks for new activity while the app is closed.
 *
 * Every 15 minutes, which is as often as Android lets a background job
 * run; the phone may stretch that further to save battery. While the app
 * is open it checks every 30 seconds itself (see AppRoot), so the delay
 * only applies to a phone in a pocket.
 */
class ActivityWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val items = ActivitySync.check(applicationContext)
        // With push active the server has already announced these; the
        // check still runs so the cursor stays current for a fallback.
        if (!PushSetup.isActive(applicationContext) &&
            ServiceLocator.prefs(applicationContext).notificationsOn.first()
        ) {
            Notifier.show(applicationContext, items)
        }
        return Result.success()
    }

    companion object {
        private const val NAME = "activity-check"

        /** Idempotent: a schedule that already exists is kept, not restarted. */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<ActivityWorker>(15, TimeUnit.MINUTES)
                .setConstraints(
                    Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build(),
                )
                .build()
            WorkManager.getInstance(context)
                .enqueueUniquePeriodicWork(NAME, ExistingPeriodicWorkPolicy.KEEP, request)
        }
    }
}
