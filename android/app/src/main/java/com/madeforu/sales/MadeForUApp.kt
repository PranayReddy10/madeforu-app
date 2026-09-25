package com.madeforu.sales

import android.app.Application
import com.madeforu.sales.notify.ActivityWorker
import com.madeforu.sales.notify.Notifier
import com.madeforu.sales.notify.PushSetup

/**
 * Almost nothing at process start: the repository is created lazily on
 * first use, so a cold launch goes straight to drawing the first frame.
 *
 * The exceptions are notifications: Firebase is configured from the
 * settings the server last gave (a push may be what started the process),
 * and the background activity check is scheduled. Scheduling it is a
 * single cheap call that keeps an existing schedule, and it does nothing
 * while no one is signed in.
 */
class MadeForUApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Notifier.ensureChannel(this)
        // Before anything else: a push may be what started this process.
        PushSetup.initFromCache(this)
        ActivityWorker.schedule(this)
    }
}
