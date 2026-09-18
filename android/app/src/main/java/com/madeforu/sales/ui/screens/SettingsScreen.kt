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
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
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
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.Settings
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.theme.negativeColor
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
    var settings by remember { mutableStateOf(Settings()) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

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
                title = { Text("Settings") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item { ErrorBanner(error) }
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
                    shape = RoundedCornerShape(18.dp),
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
                    OutlinedButton(
                        onClick = {
                            busy = true
                            scope.launch {
                                prefs.setBaseUrl(baseUrl)
                                when (repository.ping()) {
                                    is ApiResult.Success -> {
                                        message = "Connected. The server answered."
                                        error = null
                                    }
                                    is ApiResult.Failure ->
                                        error = "No answer from that address. Check it and try again."
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
