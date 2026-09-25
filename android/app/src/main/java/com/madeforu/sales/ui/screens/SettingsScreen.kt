@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Alignment
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.madeforu.sales.BuildConfig
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Prefs
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.ServerInfo
import com.madeforu.sales.data.Settings
import com.madeforu.sales.notify.PushSetup
import com.madeforu.sales.ui.components.BackButton
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * Settings: who is signed in, what goes on the bills, which server, and
 * how the app looks.
 *
 * The bill fields are stored on the server, not on the phone, so every
 * partner's bills carry the same header whichever device issued them.
 */
@Composable
fun SettingsScreen(
    repository: Repository,
    onBack: () -> Unit,
    onSignedOut: () -> Unit,
) {
    val context = LocalContext.current
    val prefs = remember { ServiceLocator.prefs(context) }
    val scope = rememberCoroutineScope()

    var adminName by remember { mutableStateOf("") }
    var baseUrl by remember { mutableStateOf("") }
    var theme by remember { mutableStateOf("system") }
    var notificationsOn by remember { mutableStateOf(true) }
    var pushActive by remember { mutableStateOf(PushSetup.isActive(context)) }
    var settings by remember { mutableStateOf(Settings()) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var serverInfo by remember { mutableStateOf<ServerInfo?>(null) }

    // Bill fields, edited locally then saved in one go.
    var businessName by remember { mutableStateOf("") }
    var businessAddr by remember { mutableStateOf("") }
    var businessPhone by remember { mutableStateOf("") }
    var gstin by remember { mutableStateOf("") }
    var upiId by remember { mutableStateOf("") }
    var billPrefix by remember { mutableStateOf("") }
    var billFooter by remember { mutableStateOf("") }
    var billTerms by remember { mutableStateOf("") }
    var logoUrl by remember { mutableStateOf("") }
    var apkUrl by remember { mutableStateOf("") }
    var apkVersionName by remember { mutableStateOf("") }
    var apkVersionCode by remember { mutableStateOf("") }
    var apkNotes by remember { mutableStateOf("") }

    LaunchedEffect(Unit) {
        adminName = prefs.adminName.first()
        baseUrl = prefs.currentBaseUrl()
        theme = prefs.theme.first()
        notificationsOn = prefs.notificationsOn.first()
        // Asked on open, not on a button: the whole point is that someone
        // who thinks the app did not update finds the answer already on
        // the screen. A failure here is not worth an error banner — the
        // Version card just keeps saying "not checked yet".
        repository.ping().successOrNull?.let { serverInfo = it }
        repository.bootstrap().let { result ->
            if (result is ApiResult.Success) {
                settings = result.value.settings
                businessName = settings.businessName
                businessAddr = settings.businessAddr
                businessPhone = settings.businessPhone
                gstin = settings.gstin
                upiId = settings.upiId
                billPrefix = settings.billPrefix
                billFooter = settings.billFooter
                billTerms = settings.billTerms
                logoUrl = settings.logoUrl
                apkUrl = settings.apkUrl
                apkVersionName = settings.apkVersionName
                apkVersionCode = settings.apkVersionCode
                apkNotes = settings.apkNotes
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Settings") },
                navigationIcon = {
                    BackButton(onClick = onBack)
                },
            )
        },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            errorBannerItem(error)
            message?.let {
                item {
                    Card(
                        shape = RoundedCornerShape(14.dp),
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.secondaryContainer,
                        ),
                    ) { Text(it, Modifier.padding(14.dp)) }
                }
            }

            item {
                Card(
                    shape = RoundedCornerShape(20.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.primaryContainer,
                    ),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        Text("Signed in as", style = MaterialTheme.typography.labelSmall)
                        Text(
                            adminName.ifBlank { "this device" },
                            style = MaterialTheme.typography.titleLarge,
                        )
                    }
                }
            }

            item { SectionHeader("Appearance") }
            item {
                ChipRow(
                    options = listOf(
                        "system" to "Follow phone",
                        "light" to "Light",
                        "dark" to "Dark",
                    ),
                    selected = theme,
                    onSelect = {
                        theme = it
                        scope.launch { prefs.setTheme(it) }
                        message = "Theme saved. It applies next time the app opens."
                    },
                    contentPadding = PaddingValues(0.dp),
                )
            }

            item { SectionHeader("Notifications") }
            item {
                Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
                    Row(
                        Modifier.fillMaxWidth().padding(16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text("New activity", style = MaterialTheme.typography.titleMedium)
                            Text(
                                "Sales, payments, order changes, expenses and movements from the " +
                                    "website, the web app or another phone. " +
                                    if (pushActive) "Real time: each arrives the moment it is saved."
                                    else "Real-time push is not set up on the server yet, so the " +
                                        "app checks every 30 seconds while open and about every " +
                                        "15 minutes when closed.",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Spacer(Modifier.width(12.dp))
                        Switch(
                            checked = notificationsOn,
                            onCheckedChange = {
                                notificationsOn = it
                                scope.launch { prefs.setNotificationsOn(it) }
                            },
                        )
                    }
                    if (notificationsOn) {
                        TextButton(
                            onClick = {
                                scope.launch {
                                    pushActive = PushSetup.setup(context)
                                    message = if (!pushActive) {
                                        "Real-time push is not available yet: firebase-config.php is " +
                                            "not on the server, or Google Play services is missing."
                                    } else {
                                        when (val r = repository.pushTest()) {
                                            is ApiResult.Success -> r.value
                                            is ApiResult.Failure -> r.message
                                        }
                                    }
                                }
                            },
                            modifier = Modifier.padding(start = 8.dp, bottom = 8.dp),
                        ) { Text(if (pushActive) "Send a test notification" else "Try real-time push") }
                    }
                }
            }

            item { SectionHeader("What appears on bills") }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    SettingField("Logo image URL (printed on every bill)", logoUrl) { logoUrl = it }
                    SettingField("Business name", businessName) { businessName = it }
                    SettingField("Address", businessAddr) { businessAddr = it }
                    SettingField("Phone", businessPhone) { businessPhone = it }
                    SettingField("GSTIN (leave empty if not registered)", gstin) { gstin = it }
                    SettingField("UPI id for the payment QR", upiId) { upiId = it }
                    SettingField("Bill number prefix", billPrefix) { billPrefix = it }
                    SettingField("Footer line", billFooter) { billFooter = it }
                    SettingField("Terms", billTerms) { billTerms = it }

                    Text(
                        "The UPI id puts a scannable QR on any bill with a balance, pre-filled " +
                            "with the amount due. Leave it empty to hide the QR.\n\n" +
                            "The logo is any public image URL — the WordPress media library is " +
                            "the easy place to host it. It prints above the business name.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Button(
                        onClick = {
                            busy = true
                            scope.launch {
                                val result = repository.saveSettings(
                                    mapOf(
                                        "logo_url" to logoUrl,
                                        "business_name" to businessName,
                                        "business_addr" to businessAddr,
                                        "business_phone" to businessPhone,
                                        "gstin" to gstin,
                                        "upi_id" to upiId,
                                        "bill_prefix" to billPrefix,
                                        "bill_footer" to billFooter,
                                        "bill_terms" to billTerms,
                                    ),
                                )
                                when (result) {
                                    is ApiResult.Success -> {
                                        settings = result.value
                                        message = "Bill details saved for everyone."
                                        error = null
                                    }
                                    is ApiResult.Failure -> error = result.message
                                }
                                busy = false
                            }
                        },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                    ) { Text("Save bill details") }
                }
            }

            // Three things update separately — this build, the api/ folder
            // on the server, and the phone's copy of the app — and none of
            // them says when it is the one that is behind. Printing all of
            // it turns "I updated and nothing changed" into a fact.
            item { SectionHeader("Version") }
            item {
                Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
                    Column(Modifier.fillMaxWidth().padding(16.dp)) {
                        DetailRow(
                            "This app",
                            BuildConfig.VERSION_NAME + " (code " + BuildConfig.VERSION_CODE + ")",
                        )
                        DetailRow(
                            "Server API",
                            serverInfo?.apiVersion?.ifBlank { "older than 1.1.0" } ?: "not checked yet",
                        )
                        val info = serverInfo
                        if (info != null && info.isStale) {
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "The server is running older API files. The app is fine — but " +
                                    "screens that need " +
                                    (if (info.missing.isNotEmpty()) info.missing.joinToString(", ")
                                     else "the newer API") +
                                    " stay blank until the api/ folder is uploaded to " +
                                    "sale.madeforu.co.in again.",
                                style = MaterialTheme.typography.bodySmall,
                                color = warnColor(),
                            )
                        } else if (info != null) {
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "Server and app are in step.",
                                style = MaterialTheme.typography.bodySmall,
                                color = positiveColor(),
                            )
                        }
                    }
                }
            }

            item { SectionHeader("App updates for partners") }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(
                        "Every partner's app checks these on launch. Raise the version code, " +
                            "paste the new APK link, and they are offered the update the next " +
                            "time they open it. This build is version " +
                            BuildConfig.VERSION_NAME + " (code " + BuildConfig.VERSION_CODE + ").",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    SettingField("Latest version name (e.g. 1.1.0)", apkVersionName) { apkVersionName = it }
                    SettingField("Latest version code (a whole number)", apkVersionCode) {
                        apkVersionCode = it.filter { c -> c.isDigit() }
                    }
                    SettingField("APK download link", apkUrl) { apkUrl = it }
                    SettingField("What changed (shown in the prompt)", apkNotes) { apkNotes = it }

                    Button(
                        onClick = {
                            busy = true
                            scope.launch {
                                val result = repository.saveSettings(
                                    mapOf(
                                        "apk_version_name" to apkVersionName,
                                        "apk_version_code" to apkVersionCode.ifBlank { "0" },
                                        "apk_url" to apkUrl,
                                        "apk_notes" to apkNotes,
                                    ),
                                )
                                when (result) {
                                    is ApiResult.Success -> {
                                        message = "Release details saved. Partners will be offered it."
                                        error = null
                                    }
                                    is ApiResult.Failure -> error = result.message
                                }
                                busy = false
                            }
                        },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth().height(50.dp),
                    ) { Text("Publish this version") }
                }
            }

            item { SectionHeader("Server") }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedTextField(
                        value = baseUrl,
                        onValueChange = { baseUrl = it },
                        label = { Text("API address") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    // The address the app will actually call, spelled out.
                    // An address can read perfectly correctly and still be
                    // missing its trailing slash, and that is invisible
                    // until you see what gets appended to it.
                    Text(
                        "Calls will go to " +
                            Prefs.normaliseBaseUrl(baseUrl) + "orders.php",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    OutlinedButton(
                        onClick = {
                            busy = true
                            scope.launch {
                                prefs.setBaseUrl(baseUrl)
                                // Show it back tidied, so what is on
                                // screen is what is stored.
                                baseUrl = prefs.currentBaseUrl()
                                when (val r = repository.ping()) {
                                    is ApiResult.Success -> {
                                        serverInfo = r.value
                                        // A reachable server is not
                                        // necessarily an up-to-date one,
                                        // and saying only "connected"
                                        // here is what let a stale api/
                                        // folder pass for a working one.
                                        message = if (r.value.isStale) {
                                            "Connected, but the server is running older API files" +
                                                (if (r.value.missing.isNotEmpty())
                                                    " — missing " + r.value.missing.joinToString(", ")
                                                 else "") +
                                                ". Upload the api/ folder again."
                                        } else {
                                            "Connected. Server API " + r.value.apiVersion + "."
                                        }
                                        error = null
                                    }
                                    is ApiResult.Failure ->
                                        // The reason, not a guess at it:
                                        // ApiClient now names the URL it
                                        // called and what came back.
                                        error = r.message
                                }
                                busy = false
                            }
                        },
                        enabled = !busy,
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Save and test connection") }
                }
            }

            item { SectionHeader("Account") }
            item {
                OutlinedButton(
                    onClick = {
                        scope.launch {
                            repository.logout()
                            onSignedOut()
                        }
                    },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("Sign out", color = negativeColor()) }
            }

            item {
                Spacer(Modifier.height(24.dp))
                Text(
                    "MadeForU ${BuildConfig.VERSION_NAME}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Text(
                    "Orders, bills and partner accounts share one database with " +
                        "sale.madeforu.co.in — a change here shows there, and the other way round.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(32.dp))
            }
        }
    }
}

@Composable
private fun SettingField(label: String, value: String, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
}
