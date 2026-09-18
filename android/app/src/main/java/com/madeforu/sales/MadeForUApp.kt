package com.madeforu.sales

import android.app.Application

/**
 * Nothing to set up at process start: the repository is created lazily on
 * first use, so a cold launch goes straight to drawing the first frame.
 */
class MadeForUApp : Application()
