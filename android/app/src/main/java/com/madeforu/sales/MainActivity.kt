package com.madeforu.sales

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.notify.Notifier
import com.madeforu.sales.ui.AppRoot
import com.madeforu.sales.ui.theme.MadeForUTheme
import kotlinx.coroutines.flow.first

class MainActivity : ComponentActivity() {

    /** The order a tapped notification asked for, until AppRoot has opened it. */
    private var pendingOrder by mutableStateOf<Int?>(null)

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        pendingOrder = orderFrom(intent)
        setContent { MadeForURoot(pendingOrder, onOrderOpened = { pendingOrder = null }) }
    }

    // singleTop: a notification tapped while the app is open lands here.
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        orderFrom(intent)?.let { pendingOrder = it }
    }

    private fun orderFrom(intent: Intent?): Int? =
        intent?.getIntExtra(Notifier.EXTRA_ORDER_ID, 0)?.takeIf { it > 0 }
}

@Composable
private fun MadeForURoot(openOrderId: Int?, onOrderOpened: () -> Unit) {
    val context = LocalContext.current
    val prefs = ServiceLocator.prefs(context)

    // Read the stored theme and session before the first frame that could
    // show the wrong one. `null` means "still reading", and is drawn as a
    // bare surface — a flash of the login screen for an already-signed-in
    // partner would be worse than a few milliseconds of nothing.
    val theme by produceState<String?>(initialValue = null) {
        value = prefs.theme.first()
    }
    val startSignedIn by produceState<Boolean?>(initialValue = null) {
        value = !prefs.token.first().isNullOrBlank()
    }

    MadeForUTheme(themeChoice = theme ?: "system") {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = MaterialTheme.colorScheme.background,
        ) {
            if (theme == null || startSignedIn == null) {
                Box(Modifier.fillMaxSize())
            } else {
                AppRoot(
                    signedIn = startSignedIn == true,
                    openOrderId = openOrderId,
                    onOrderOpened = onOrderOpened,
                )
            }
        }
    }
}
