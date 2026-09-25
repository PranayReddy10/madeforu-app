package com.madeforu.sales.core

import android.content.Context
import com.madeforu.sales.data.ApiClient
import com.madeforu.sales.data.Repository

/**
 * Dependency wiring, by hand.
 *
 * The app has exactly three long-lived objects and no build variants that
 * swap them, so a DI framework would add an annotation processor, a build
 * step and a layer of indirection to save writing these ten lines.
 */
object ServiceLocator {

    @Volatile private var repository: Repository? = null

    fun repository(context: Context): Repository =
        repository ?: synchronized(this) {
            repository ?: buildRepository(context.applicationContext).also { repository = it }
        }

    private fun buildRepository(appContext: Context): Repository {
        val prefs = Prefs(appContext)
        return Repository(ApiClient(appContext, prefs), prefs)
    }

    fun prefs(context: Context): Prefs = Prefs(context.applicationContext)
}
