package com.madeforu.sales.notify

import android.content.Context
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.ActivityItem
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * One read of the activity feed, shared by the background worker and the
 * open app.
 *
 * Both move the same cursor, and the lock makes sure only one of them
 * does at a time, so a sale is announced once: by the app while it is
 * open, or by the worker while it is not, never by both.
 */
object ActivitySync {

    private val lock = Mutex()

    /**
     * New activity by someone else since the last check, oldest first.
     * Empty on the very first check (which only fetches the cursor), when
     * signed out, and when the server could not be reached; in that last
     * case the cursor stays put and the next check catches up.
     */
    suspend fun check(context: Context): List<ActivityItem> = lock.withLock {
        val app = context.applicationContext
        val prefs = ServiceLocator.prefs(app)
        if (prefs.token.first().isNullOrBlank()) return emptyList()

        val repository = ServiceLocator.repository(app)
        val cursor = prefs.activityCursor()
        when (val result = repository.activity(cursor)) {
            is ApiResult.Failure -> emptyList()
            is ApiResult.Success -> {
                val feed = result.value
                if (feed.now.isNotBlank()) prefs.setActivityCursor(feed.now)
                // Before the first cursor, everything is history.
                if (cursor.isBlank()) emptyList() else feed.items.filterNot { it.mine }
            }
        }
    }
}
