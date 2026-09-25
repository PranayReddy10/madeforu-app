package com.madeforu.sales.ui.screens

import android.os.Build
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.ExperimentalComposeUiApi
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.madeforu.sales.R
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.theme.BrandGradient
import kotlinx.coroutines.launch

/**
 * Sign in, with the brand on it.
 *
 * The gradient runs the logo's rose into its navy and fills the whole
 * window, including behind the status bar — the sheet that carries the
 * form sits on top of it. That is why this screen handles its own insets
 * rather than being padded from outside: the colour is supposed to run to
 * the very top of the glass.
 *
 * The server address is editable here as well as in Settings, because a
 * partner who cannot sign in cannot reach Settings, and a mistyped server
 * is one of the two things that causes that.
 */
@OptIn(ExperimentalComposeUiApi::class)
@Composable
fun LoginScreen(repository: Repository, onSignedIn: () -> Unit) {
    val context = LocalContext.current
    val prefs = remember { ServiceLocator.prefs(context) }
    val scope = rememberCoroutineScope()
    val keyboard = LocalSoftwareKeyboardController.current

    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var showServerField by remember { mutableStateOf(false) }
    var serverUrl by remember { mutableStateOf("") }

    val submit: () -> Unit = submit@{
        keyboard?.hide()
        if (phone.length != 10) {
            error = "Enter the 10-digit phone number you sign in with."
            return@submit
        }
        if (password.isBlank()) {
            error = "Enter your password."
            return@submit
        }
        busy = true
        error = null
        scope.launch {
            if (serverUrl.isNotBlank()) prefs.setBaseUrl(serverUrl)
            val device = "${Build.MANUFACTURER} ${Build.MODEL}".trim().take(60)
            when (val result = repository.login(phone, password, device)) {
                is ApiResult.Success -> { busy = false; onSignedIn() }
                is ApiResult.Failure -> { busy = false; error = result.message }
            }
        }
    }

    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(BrandGradient)),
    ) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(36.dp))

            // The logo keeps its own white field: the artwork is drawn for
            // light backgrounds, and recolouring a brand mark to suit a
            // gradient is not ours to do.
            Surface(
                shape = RoundedCornerShape(32.dp),
                color = Color.White,
                modifier = Modifier.size(132.dp),
            ) {
                Image(
                    painter = painterResource(R.drawable.logo_madeforu),
                    contentDescription = "MadeForU",
                    modifier = Modifier.padding(10.dp),
                )
            }

            Spacer(Modifier.height(20.dp))
            Text(
                "Made for your moments",
                style = MaterialTheme.typography.titleMedium,
                color = Color.White.copy(alpha = 0.92f),
                textAlign = TextAlign.Center,
            )
            Text(
                "Sales, orders and partner accounts",
                style = MaterialTheme.typography.bodySmall,
                color = Color.White.copy(alpha = 0.72f),
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(28.dp))

            // The form sits on a sheet so the fields have a calm surface to
            // live on, whatever the gradient is doing behind them.
            Surface(
                shape = RoundedCornerShape(topStart = 32.dp, topEnd = 32.dp),
                color = MaterialTheme.colorScheme.surface,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(
                    Modifier.padding(horizontal = 24.dp, vertical = 28.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(
                        "Welcome back",
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        "Same phone and password as the website",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )

                    Spacer(Modifier.height(22.dp))

                    OutlinedTextField(
                        value = phone,
                        onValueChange = { input ->
                            // Keep only digits, only ten: pasted numbers
                            // arrive as "+91 98765 43210" more often than not.
                            phone = input.filter { it.isDigit() }.take(10)
                        },
                        label = { Text("Phone number") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Phone,
                            imeAction = ImeAction.Next,
                        ),
                        shape = RoundedCornerShape(16.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = MaterialTheme.colorScheme.primary,
                        ),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(Modifier.height(12.dp))

                    OutlinedTextField(
                        value = password,
                        onValueChange = { password = it },
                        label = { Text("Password") },
                        singleLine = true,
                        visualTransformation = if (showPassword) VisualTransformation.None
                        else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Password,
                            imeAction = ImeAction.Done,
                        ),
                        keyboardActions = KeyboardActions(onDone = { submit() }),
                        trailingIcon = {
                            IconButton(onClick = { showPassword = !showPassword }) {
                                Icon(
                                    if (showPassword) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                                    contentDescription = if (showPassword) "Hide password" else "Show password",
                                )
                            }
                        },
                        shape = RoundedCornerShape(16.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = MaterialTheme.colorScheme.primary,
                        ),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    if (showServerField) {
                        Spacer(Modifier.height(12.dp))
                        OutlinedTextField(
                            value = serverUrl,
                            onValueChange = { serverUrl = it },
                            label = { Text("Server address") },
                            placeholder = { Text("https://sale.madeforu.co.in/api/") },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                            shape = RoundedCornerShape(14.dp),
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }

                    Spacer(Modifier.height(10.dp))
                    ErrorBanner(error)
                    Spacer(Modifier.height(10.dp))

                    Button(
                        onClick = submit,
                        enabled = !busy,
                        shape = RoundedCornerShape(16.dp),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = MaterialTheme.colorScheme.primary,
                        ),
                        modifier = Modifier.fillMaxWidth().height(54.dp),
                    ) {
                        if (busy) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(20.dp),
                                strokeWidth = 2.dp,
                                color = MaterialTheme.colorScheme.onPrimary,
                            )
                        } else {
                            Text(
                                "Sign in",
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                        }
                    }

                    TextButton(onClick = { showServerField = !showServerField }) {
                        Text(
                            if (showServerField) "Hide server address" else "Use a different server",
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }

                    Spacer(Modifier.height(8.dp))
                }
            }
        }
    }
}
