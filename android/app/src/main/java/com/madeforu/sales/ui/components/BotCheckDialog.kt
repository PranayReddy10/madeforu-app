package com.madeforu.sales.ui.components

import android.annotation.SuppressLint
import android.webkit.CookieManager
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.WebGate

/**
 * Cloudflare's "checking your browser" page, shown to be passed.
 *
 * It opens the API's ping in a WebView -- the same thing Chrome does when
 * the web app loads -- and closes itself once Cloudflare has handed over
 * its clearance cookie. Usually that takes a couple of seconds with
 * nothing to tap; if Cloudflare wants a tick in a box, the box is right
 * there on screen.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun BotCheckDialog(onPassed: () -> Unit, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val pingUrl by produceState<String?>(initialValue = null) {
        value = ServiceLocator.prefs(context).currentBaseUrl() + "auth.php?action=ping"
    }

    Dialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().statusBarsPadding()) {
                Row(
                    modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 4.dp, top = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        "Checking the connection",
                        style = MaterialTheme.typography.titleMedium,
                        modifier = Modifier.weight(1f),
                    )
                    IconButton(onClick = onDismiss) {
                        Icon(Icons.Filled.Close, contentDescription = "Close")
                    }
                }
                Text(
                    "The server's security check wants to see a browser once. Wait for it to " +
                        "finish, or tick the box if it shows one. This closes by itself.",
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
                )
                val url = pingUrl
                if (url != null) {
                    AndroidView(
                        modifier = Modifier.fillMaxWidth().weight(1f),
                        factory = { viewContext ->
                            WebView(viewContext).apply {
                                settings.javaScriptEnabled = true   // the check is a script
                                settings.domStorageEnabled = true
                                // Left at its default on purpose: OkHttp sends
                                // this same agent, and the clearance only
                                // counts for the agent that earned it.
                                val cookies = CookieManager.getInstance()
                                cookies.setAcceptCookie(true)
                                cookies.setAcceptThirdPartyCookies(this, true)
                                webViewClient = object : WebViewClient() {
                                    private var done = false

                                    private fun pass() {
                                        if (done) return
                                        done = true
                                        cookies.flush()
                                        onPassed()
                                    }

                                    override fun onPageFinished(view: WebView, finishedUrl: String) {
                                        if (WebGate.hasClearance(url)) {
                                            pass()
                                            return
                                        }
                                        // No cookie, but the API answered: the
                                        // check has been switched off, or let
                                        // this phone through without one.
                                        view.evaluateJavascript("document.body ? document.body.innerText : ''") { text ->
                                            if (text != null && text.contains("\\\"ok\\\"")) pass()
                                        }
                                    }
                                }
                                loadUrl(url)
                            }
                        },
                    )
                }
            }
        }
    }
}
