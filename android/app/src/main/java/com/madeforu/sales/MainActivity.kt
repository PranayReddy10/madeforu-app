package com.madeforu.sales

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
import androidx.compose.runtime.produceState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.ui.AppRoot
import com.madeforu.sales.ui.theme.MadeForUTheme
import kotlinx.coroutines.flow.first

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent { MadeForURoot() }
    }
}

@Composable
private fun MadeForURoot() {
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
                AppRoot(signedIn = startSignedIn == true)
            }
        }
    }
}
