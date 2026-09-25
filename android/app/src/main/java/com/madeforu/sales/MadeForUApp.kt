package com.madeforu.sales

import android.app.Application
import com.madeforu.sales.notify.ActivityWorker
import com.madeforu.sales.notify.Notifier

/**
 * Almost nothing at process start: the repository is created lazily on
 * first use, so a cold launch goes straight to drawing the first frame.
 *
 * The one exception is the background activity check. Scheduling it is a
 * single cheap call that keeps an existing schedule, and it does nothing
 * while no one is signed in.
 */
class MadeForUApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Notifier.ensureChannel(this)
        ActivityWorker.schedule(this)
    }
}
