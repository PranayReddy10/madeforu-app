@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ElevatedButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Order
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.BackButton
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.PayStatusPill
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.SoldLine
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor
import kotlinx.coroutines.launch

@Composable
fun OrderDetailScreen(
    repository: Repository,
    orderId: Int,
    onBack: () -> Unit,
    onEdit: () -> Unit,
    onOpenBill: () -> Unit,
    onDeleted: (String) -> Unit,
    onSessionExpired: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var order by remember { mutableStateOf<Order?>(null) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var showPaymentSheet by remember { mutableStateOf(false) }
    var showDispatchSheet by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }

    // Today's catalogue, held only so a line sold at a different price can
    // say so. It never changes what the order is worth — the order keeps
    // the prices it was sold at.
    var catalogueNow by remember { mutableStateOf<Map<String, Double>>(emptyMap()) }

    fun refresh() {
        scope.launch {
            when (val result = repository.order(orderId)) {
                is ApiResult.Success -> { order = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    LaunchedEffect(orderId) { refresh() }

    LaunchedEffect(Unit) {
        // Best effort: a failure here just means no "the catalogue has
        // since moved" note, which is a nicety, not the order.
        repository.products(includeHidden = true).successOrNull?.let { list ->
            catalogueNow = list.associate { it.name to it.price }
        }
    }

    /** Every write returns the fresh order, so one helper covers them all. */
    fun act(block: suspend () -> ApiResult<com.madeforu.sales.data.OrderResponse>) {
        busy = true
        scope.launch {
            when (val result = block()) {
                is ApiResult.Success -> {
                    order = result.value.order
                    error = null
                    if (result.value.message.isNotBlank()) {
                        Toast.makeText(context, result.value.message, Toast.LENGTH_SHORT).show()
                    }
                }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            busy = false
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text(order?.orderNo ?: "Order") },
                navigationIcon = {
                    BackButton(onClick = onBack)
                },
                actions = {
                    IconButton(onClick = onEdit) {
                        Icon(Icons.Filled.Edit, contentDescription = "Edit order")
                    }
                    IconButton(onClick = { confirmDelete = true }) {
                        Icon(Icons.Filled.Delete, contentDescription = "Delete order")
                    }
                },
            )
        },
    ) { padding ->
        val current = order
        if (loading || current == null) {
            LoadingBox(Modifier.padding(padding))
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            errorBannerItem(error, onRetry = { refresh() })

            item { MoneyHeader(current) }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (current.balance > 0.5) {
                        Button(
                            onClick = { showPaymentSheet = true },
                            enabled = !busy,
                            modifier = Modifier.weight(1f),
                        ) { Text("Take payment") }
                    }
                    ElevatedButton(
                        onClick = onOpenBill,
                        modifier = Modifier.weight(1f),
                    ) {
                        Icon(Icons.Filled.Receipt, contentDescription = null, Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(if (current.hasBill) "Bill" else "Make bill")
                    }
                }
            }

            item { SectionHeader("Status") }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    // Chips rather than switches: the two are coupled
                    // (delivered implies ready) and the server may change
                    // both from one tap, which a switch would misrepresent
                    // until the response lands.
                    FilterChip(
                        selected = current.isReady,
                        onClick = { act { repository.toggle(orderId, "is_ready") } },
                        enabled = !busy,
                        label = { Text(if (current.isReady) "Ready" else "Mark ready") },
                    )
                    FilterChip(
                        selected = current.isDelivered,
                        onClick = { act { repository.toggle(orderId, "is_delivered") } },
                        enabled = !busy,
                        label = { Text(if (current.isDelivered) "Delivered" else "Mark delivered") },
                    )
                }
            }

            item {
                Card(
                    shape = RoundedCornerShape(20.dp),
                    colors = softCardColors(),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        DetailRow("Customer", if (current.isWalkIn) "Walk-in (no details)" else current.name)
                        if (current.phone.isNotBlank()) DetailRow("Phone", current.phone)
                        DetailRow("Channel", current.eventName ?: "Direct / walk-up")
                        DetailRow("Taken", Dates.pretty(current.createdAt, withTime = true))
                        current.createdBy?.let { DetailRow("By", it) }
                        if (!current.notes.isNullOrBlank()) DetailRow("Notes", current.notes)
                    }
                }
            }

            item { SectionHeader("Items") }

            item {
                Card(
                    shape = RoundedCornerShape(20.dp),
                    colors = softCardColors(),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        current.items.forEach { line ->
                            // The unit price is on the line because it is
                            // the price this sale was made at, which is not
                            // necessarily what the catalogue says today —
                            // and someone checking the total against the
                            // price list needs to see which is which.
                            val now = catalogueNow[line.item]
                            SoldLine(
                                label = line.item + " × " + line.quantity,
                                soldAt = line.unitPrice,
                                catalogueNow = if (now != null &&
                                    kotlin.math.abs(now - line.unitPrice) > 0.005) now else null,
                                amount = Money.full(line.lineTotal),
                            )
                        }
                        ThinDivider(Modifier.padding(vertical = 8.dp))
                        DetailRow("Subtotal", Money.full(current.subtotal))
                        if (current.extraCharge > 0.001) {
                            DetailRow(
                                current.extraChargeReason ?: "Extra charge",
                                "+" + Money.full(current.extraCharge),
                            )
                        }
                        if (current.discount > 0.001) {
                            DetailRow(
                                current.discountReason ?: "Discount",
                                "-" + Money.full(current.discount),
                                valueColor = positiveColor(),
                            )
                        }
                        ThinDivider(Modifier.padding(vertical = 8.dp))
                        DetailRow("Total", Money.full(current.total), emphasise = true)
                    }
                }
            }

            item {
                SectionHeader(
                    "Payments",
                    action = {
                        if (current.balance > 0.5) {
                            TextButton(onClick = { showPaymentSheet = true }) { Text("Add") }
                        }
                    },
                )
            }

            if (current.payments.isEmpty()) {
                item {
                    Text(
                        "Nothing collected yet.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            } else {
                items(current.payments.size) { index ->
                    val payment = current.payments[index]
                    Card(
                        shape = RoundedCornerShape(14.dp),
                        colors = softCardColors(),
                        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                    ) {
                        Row(
                            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(Money.full(payment.amount), fontWeight = FontWeight.SemiBold)
                                Text(
                                    payment.mode.uppercase() + " · " +
                                        Dates.pretty(payment.createdAt, withTime = true) +
                                        (payment.takenBy?.let { " · $it" } ?: ""),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                if (!payment.note.isNullOrBlank()) {
                                    Text(
                                        payment.note,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                            IconButton(
                                onClick = { act { repository.deletePayment(orderId, payment.id) } },
                                enabled = !busy,
                            ) {
                                Icon(
                                    Icons.Filled.Delete,
                                    contentDescription = "Remove payment",
                                    tint = negativeColor(),
                                )
                            }
                        }
                    }
                }
            }

            // ── Delivery ───────────────────────────────────────────
            if (current.eventId == null) {
                item { SectionHeader("Delivery") }
                item {
                    Card(
                        shape = RoundedCornerShape(20.dp),
                        colors = softCardColors(),
                        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            if (current.awb != null) {
                                DetailRow("Tracking number", current.awb)
                                DetailRow("Dispatched", Dates.pretty(current.dispatchDate))
                                Spacer(Modifier.height(8.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    current.trackUrl?.let { url ->
                                        OutlinedButton(
                                            onClick = { openUrl(context, url) },
                                            modifier = Modifier.weight(1f),
                                        ) { Text("Track parcel") }
                                    }
                                    OutlinedButton(
                                        onClick = { showDispatchSheet = true },
                                        modifier = Modifier.weight(1f),
                                    ) { Text("Change") }
                                }
                            } else {
                                Text(
                                    "Not shipped. Add a Delhivery tracking number when the parcel goes out.",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                Spacer(Modifier.height(10.dp))
                                OutlinedButton(onClick = { showDispatchSheet = true }) {
                                    Icon(Icons.Filled.LocalShipping, contentDescription = null, Modifier.size(18.dp))
                                    Spacer(Modifier.width(8.dp))
                                    Text("Add tracking number")
                                }
                            }
                        }
                    }
                }
            }

            // ── WhatsApp ───────────────────────────────────────────
            val whatsapp = current.whatsapp
            if (whatsapp != null && current.phone.isNotBlank()) {
                item { SectionHeader("Tell the customer") }
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        whatsapp.orderLink?.let { link ->
                            OutlinedButton(
                                onClick = { openUrl(context, link) },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Icon(Icons.Filled.Chat, contentDescription = null, Modifier.size(18.dp))
                                Spacer(Modifier.width(8.dp))
                                Text("Send order summary on WhatsApp")
                            }
                        }
                        whatsapp.dispatchLink?.let { link ->
                            OutlinedButton(
                                onClick = { openUrl(context, link) },
                                modifier = Modifier.fillMaxWidth(),
                            ) {
                                Icon(Icons.Filled.LocalShipping, contentDescription = null, Modifier.size(18.dp))
                                Spacer(Modifier.width(8.dp))
                                Text("Send tracking details")
                            }
                        }
                    }
                }
            }

            item { Spacer(Modifier.height(32.dp)) }
        }
    }

    if (showPaymentSheet && order != null) {
        PaymentSheet(
            balance = order!!.balance,
            busy = busy,
            onDismiss = { showPaymentSheet = false },
            onSubmit = { amount, mode, note ->
                showPaymentSheet = false
                act { repository.addPayment(orderId, amount, mode, note) }
            },
        )
    }

    if (showDispatchSheet && order != null) {
        DispatchSheet(
            currentAwb = order!!.awb.orEmpty(),
            currentDate = order!!.dispatchDate ?: Dates.today(),
            busy = busy,
            onDismiss = { showDispatchSheet = false },
            onSubmit = { awb, date ->
                showDispatchSheet = false
                act { repository.dispatch(orderId, awb, date) }
            },
        )
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Delete this order?") },
            text = {
                Text(
                    "The order, its items and its payments are removed for good. " +
                        "This cannot be undone.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    scope.launch {
                        when (val result = repository.deleteOrder(orderId)) {
                            is ApiResult.Success -> onDeleted(result.value)
                            is ApiResult.Failure -> error = result.message
                        }
                    }
                }) { Text("Delete", color = negativeColor()) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = false }) { Text("Keep it") }
            },
        )
    }
}

@Composable
private fun MoneyHeader(order: Order) {
    val settled = order.balance <= 0.5
    Card(
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (settled) MaterialTheme.colorScheme.primaryContainer
            else MaterialTheme.colorScheme.errorContainer,
        ),
    ) {
        Column(Modifier.fillMaxWidth().padding(20.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    if (settled) "FULLY PAID" else "BALANCE DUE",
                    style = MaterialTheme.typography.labelSmall,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    PayStatusPill(order.payStatus)
                    if (order.isWalkIn) Pill("Walk-in", MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            Spacer(Modifier.height(6.dp))
            Text(
                if (settled) Money.full(order.total) else Money.full(order.balance),
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.Bold,
            )
            Text(
                Money.full(order.paidAmount) + " collected of " + Money.full(order.total),
                style = MaterialTheme.typography.bodyMedium,
            )
        }
    }
}

@Composable
private fun PaymentSheet(
    balance: Double,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (Double, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState()
    var amountText by remember { mutableStateOf("") }
    var mode by remember { mutableStateOf("cash") }
    var note by remember { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp)) {
            Text("Take payment", style = MaterialTheme.typography.titleLarge)
            Text(
                "Balance " + Money.full(balance),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(16.dp))

            Row(verticalAlignment = Alignment.CenterVertically) {
                OutlinedTextField(
                    value = amountText,
                    onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Amount") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                TextButton(onClick = {
                    amountText = if (balance == balance.toLong().toDouble()) balance.toLong().toString()
                    else String.format(java.util.Locale.US, "%.2f", balance)
                }) { Text("Full") }
            }

            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("cash" to "Cash", "upi" to "UPI", "card" to "Card", "other" to "Other")
                    .forEach { (key, label) ->
                        FilterChip(
                            selected = mode == key,
                            onClick = { mode = key },
                            label = { Text(label) },
                        )
                    }
            }

            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = note,
                onValueChange = { note = it },
                label = { Text("Note (optional)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(16.dp))
            Button(
                onClick = {
                    val amount = amountText.toDoubleOrNull() ?: 0.0
                    if (amount > 0) onSubmit(amount, mode, note)
                },
                enabled = !busy && (amountText.toDoubleOrNull() ?: 0.0) > 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Record payment") }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun DispatchSheet(
    currentAwb: String,
    currentDate: String,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState()
    var awb by remember { mutableStateOf(currentAwb) }
    var date by remember { mutableStateOf(currentDate) }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp)) {
            Text("Delhivery tracking", style = MaterialTheme.typography.titleLarge)
            Text(
                "Saving a tracking number marks the order ready — a parcel that has shipped was necessarily made.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(16.dp))

            OutlinedTextField(
                value = awb,
                onValueChange = { awb = it.trim() },
                label = { Text("AWB / tracking number") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = date,
                onValueChange = { date = it },
                label = { Text("Dispatch date (YYYY-MM-DD)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (currentAwb.isNotBlank()) {
                    OutlinedButton(
                        onClick = { onSubmit("", "") },
                        enabled = !busy,
                        modifier = Modifier.weight(1f),
                    ) { Text("Clear") }
                }
                Button(
                    onClick = { onSubmit(awb, date) },
                    enabled = !busy && awb.isNotBlank(),
                    modifier = Modifier.weight(1f),
                ) { Text("Save") }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

/**
 * Opens a wa.me or tracking link. Wrapped because a phone without a
 * browser or without WhatsApp throws ActivityNotFoundException, and
 * crashing on a missing app would be a poor trade for a convenience link.
 */
private fun openUrl(context: android.content.Context, url: String) {
    runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    }.onFailure {
        Toast.makeText(context, "No app on this phone can open that link.", Toast.LENGTH_SHORT).show()
    }
}
