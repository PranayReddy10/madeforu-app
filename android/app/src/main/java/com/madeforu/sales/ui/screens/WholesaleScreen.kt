package com.madeforu.sales.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.ApiResult
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.WholesaleCustomer
import com.madeforu.sales.data.isAuthFailure
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.ProductThumb
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import kotlinx.coroutines.launch

/**
 * Wholesale buyers — a notebook, kept away from the money.
 *
 * Nothing on this screen or the one behind it is an order. None of it is
 * counted in revenue or profit, none of it shows on Money or Stats, and
 * none of it moves stock. It exists to answer one question: what has this
 * person bought from me, and at what.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WholesaleScreen(
    repository: Repository,
    onBack: () -> Unit,
    onOpenBuyer: (Int) -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var customers by remember { mutableStateOf<List<WholesaleCustomer>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notReady by remember { mutableStateOf<String?>(null) }
    var adding by remember { mutableStateOf(false) }

    fun refresh() {
        scope.launch {
            when (val r = repository.wholesaleList()) {
                is ApiResult.Success -> {
                    if (r.value.ready) {
                        customers = r.value.customers
                        notReady = null
                        error = null
                    } else {
                        // Said out loud rather than shown as an empty
                        // list: a missing migration should not look like
                        // a screen with nothing in it.
                        notReady = r.value.message
                            ?: "Wholesale is not set up on the server yet."
                    }
                }
                is ApiResult.Failure ->
                    if (r.isAuthFailure()) onSessionExpired() else error = r.message
            }
            loading = false
        }
    }

    androidx.compose.runtime.LaunchedEffect(Unit) { refresh() }

    Scaffold(
        modifier = Modifier.imePadding(),
        topBar = {
            TopAppBar(
                title = { Text("Wholesale buyers") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(onClick = { adding = true }, enabled = notReady == null) {
                        Icon(Icons.Filled.Add, contentDescription = "Add a buyer")
                    }
                },
            )
        },
    ) { padding ->
        if (loading) {
            LoadingBox(Modifier.padding(padding))
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            errorBannerItem(error, onRetry = { refresh() })

            notReady?.let { message ->
                item {
                    Card(shape = RoundedCornerShape(18.dp), colors = softCardColors()) {
                        Text(
                            message,
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.padding(16.dp),
                        )
                    }
                }
                return@LazyColumn
            }

            item {
                Text(
                    "What each bulk buyer has taken, and at what. Not counted in " +
                        "revenue or profit.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            if (customers.isEmpty()) {
                item {
                    Text(
                        "No buyers yet. Add one with the + above, then record what they " +
                            "take each time they come.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(top = 24.dp),
                    )
                }
            }

            items(customers.size) { index ->
                val c = customers[index]
                Card(
                    shape = RoundedCornerShape(18.dp),
                    colors = softCardColors(),
                    onClick = { onOpenBuyer(c.id) },
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        ProductThumb(c.name, null)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                c.name,
                                style = MaterialTheme.typography.bodyLarge,
                                fontWeight = FontWeight.SemiBold,
                            )
                            if (c.where.isNotBlank()) {
                                Text(
                                    c.where,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            Text(
                                buildString {
                                    append(c.visits)
                                    append(if (c.visits == 1) " visit" else " visits")
                                    c.lastVisit?.let { append(" · last ${Dates.pretty(it)}") }
                                },
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Text(
                            Money.full(c.taken),
                            style = MaterialTheme.typography.bodyLarge,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }
            }
        }
    }

    if (adding) {
        BuyerSheet(
            existing = null,
            busy = busy,
            onDismiss = { adding = false },
            onSubmit = { name, phone, shop, place, notes ->
                adding = false
                busy = true
                scope.launch {
                    when (val r = repository.addWholesaleCustomer(name, phone, shop, place, notes)) {
                        is ApiResult.Success -> { customers = r.value.customers; error = null }
                        is ApiResult.Failure -> error = r.message
                    }
                    busy = false
                }
            },
        )
    }
}

/**
 * Add or edit a buyer.
 *
 * Only the name is required — a bulk buyer who is "Ravi from the market"
 * and nothing else is still worth writing down.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun BuyerSheet(
    existing: WholesaleCustomer?,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (String, String, String, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var name by remember { mutableStateOf(existing?.name ?: "") }
    var phone by remember { mutableStateOf(existing?.phone ?: "") }
    var shop by remember { mutableStateOf(existing?.shop ?: "") }
    var place by remember { mutableStateOf(existing?.place ?: "") }
    var notes by remember { mutableStateOf(existing?.notes ?: "") }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                if (existing == null) "Add a buyer" else "Edit buyer",
                style = MaterialTheme.typography.titleLarge,
            )
            OutlinedTextField(
                value = name, onValueChange = { name = it },
                label = { Text("Name") }, singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = phone,
                onValueChange = { phone = it.filter { c -> c.isDigit() }.take(10) },
                label = { Text("Phone") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = shop, onValueChange = { shop = it },
                label = { Text("Shop") }, singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = place, onValueChange = { place = it },
                label = { Text("Place") }, singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = notes, onValueChange = { notes = it },
                label = { Text("Note (optional)") },
                modifier = Modifier.fillMaxWidth(),
            )
            Button(
                onClick = { onSubmit(name.trim(), phone.trim(), shop.trim(), place.trim(), notes.trim()) },
                enabled = !busy && name.isNotBlank(),
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text(if (existing == null) "Add buyer" else "Save") }
            Spacer(Modifier.height(24.dp))
        }
    }
}
