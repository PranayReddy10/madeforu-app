@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.DraftLine
import com.madeforu.sales.data.Event
import com.madeforu.sales.data.OrderDraft
import com.madeforu.sales.data.Product
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.ProductThumb
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * Take a sale.
 *
 * Built around the stall case: tap products, watch the total, take the
 * money, done. Customer details are one collapsed section that stays shut
 * unless someone wants a bill in their name — a walk-in sale should not
 * cost two extra fields.
 *
 * The same screen edits an existing order when `editOrderId` is set.
 */
@Composable
fun NewOrderScreen(
    repository: Repository,
    editOrderId: Int?,
    onSaved: (Int, String) -> Unit,
    onCancel: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var products by remember { mutableStateOf<List<Product>>(emptyList()) }
    var events by remember { mutableStateOf<List<Event>>(emptyList()) }
    var draft by remember { mutableStateOf(OrderDraft()) }
    var loading by remember { mutableStateOf(true) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var showProducts by remember { mutableStateOf(true) }
    var showCustomer by remember { mutableStateOf(false) }
    var showAdjustments by remember { mutableStateOf(false) }

    // Text fields keep their own string state: a Double turns "12." into
    // "12" mid-typing and the cursor jumps.
    var discountText by remember { mutableStateOf("") }
    var extraText by remember { mutableStateOf("") }
    var paidText by remember { mutableStateOf("") }

    LaunchedEffect(editOrderId) {
        loading = true
        when (val result = repository.bootstrap()) {
            is ApiResult.Success -> {
                products = result.value.products
                events = result.value.events
            }
            is ApiResult.Failure -> {
                if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
        }
        if (editOrderId != null) {
            when (val result = repository.order(editOrderId)) {
                is ApiResult.Success -> {
                    val order = result.value
                    draft = OrderDraft(
                        name = if (order.isWalkIn) "" else order.name,
                        phone = order.phone,
                        notes = order.notes.orEmpty(),
                        eventId = order.eventId,
                        lines = order.items.map { DraftLine(it.item, it.unitPrice, it.quantity) },
                        extraCharge = order.extraCharge,
                        extraChargeReason = order.extraChargeReason.orEmpty(),
                        discount = order.discount,
                        discountReason = order.discountReason.orEmpty(),
                        isReady = order.isReady,
                        isDelivered = order.isDelivered,
                        // Carried through deliberately: an update that
                        // omits the AWB clears the dispatch, which would
                        // silently un-ship a parcel that is already out.
                        awb = order.awb.orEmpty(),
                        dispatchDate = order.dispatchDate.orEmpty(),
                    )
                    if (order.items.isNotEmpty()) showProducts = false
                    if (!order.isWalkIn) showCustomer = true
                    if (order.discount > 0 || order.extraCharge > 0) showAdjustments = true
                    discountText = if (order.discount > 0) trimNumber(order.discount) else ""
                    extraText = if (order.extraCharge > 0) trimNumber(order.extraCharge) else ""
                }
                is ApiResult.Failure -> error = result.message
            }
        }
        loading = false
    }

    fun setQuantity(product: Product, quantity: Int) {
        val existing = draft.lines.toMutableList()
        val index = existing.indexOfFirst { it.item == product.name }
        when {
            quantity <= 0 && index >= 0 -> existing.removeAt(index)
            index >= 0 -> existing[index] = existing[index].copy(quantity = quantity)
            quantity > 0 -> existing.add(DraftLine(product.name, product.price, quantity))
        }
        draft = draft.copy(lines = existing)
    }

    val save: () -> Unit = save@{
        if (draft.lines.isEmpty()) {
            error = "Add at least one product."
            return@save
        }
        saving = true
        error = null
        scope.launch {
            val result = if (editOrderId == null) repository.createOrder(draft)
            else repository.updateOrder(editOrderId, draft)
            when (result) {
                is ApiResult.Success -> {
                    saving = false
                    onSaved(result.value.order.id, result.value.message)
                }
                is ApiResult.Failure -> {
                    saving = false
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
                }
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (editOrderId == null) "New sale" else "Edit order") },
                navigationIcon = {
                    IconButton(onClick = onCancel) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
        bottomBar = {
            // The total and the save button are pinned: at a counter, the
            // number you are about to charge should never be scrolled away.
            Surface(tonalElevation = 4.dp) {
                Column(Modifier.fillMaxWidth().padding(16.dp)) {
                    Row(
                        Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column {
                            Text(
                                "TOTAL",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            Text(
                                Money.full(draft.total),
                                style = MaterialTheme.typography.headlineSmall,
                                fontWeight = FontWeight.Bold,
                            )
                        }
                        if (draft.balance > 0.5 && draft.paidAmount > 0.0) {
                            Text(
                                "Balance " + Money.full(draft.balance),
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Button(
                        onClick = save,
                        enabled = !saving && draft.lines.isNotEmpty(),
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        shape = RoundedCornerShape(14.dp),
                    ) {
                        if (saving) {
                            CircularProgressIndicator(
                                Modifier.size(20.dp),
                                strokeWidth = 2.dp,
                                color = MaterialTheme.colorScheme.onPrimary,
                            )
                        } else {
                            Text(
                                if (editOrderId == null) {
                                    if (draft.isWalkIn) "Save walk-in sale" else "Save order"
                                } else "Save changes",
                                style = MaterialTheme.typography.titleMedium,
                            )
                        }
                    }
                }
            }
        },
    ) { padding ->
        if (loading) {
            LoadingBox(Modifier.padding(padding))
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding).imePadding(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item { ErrorBanner(error) }

            if (events.isNotEmpty()) {
                item {
                    Column {
                        Text(
                            "Where is this sale?",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Spacer(Modifier.height(6.dp))
                        ChipRow(
                            options = listOf<Pair<Int?, String>>(null to "Direct / walk-up") +
                                events.map { it.id as Int? to it.name },
                            selected = draft.eventId,
                            onSelect = { draft = draft.copy(eventId = it) },
                            contentPadding = PaddingValues(0.dp),
                        )
                    }
                }
            }

            // Products collapse like every other section. With a couple of
            // dozen in the catalogue, leaving them always open means
            // scrolling past all of them to reach the payment and customer
            // fields underneath. The header keeps the count and the running
            // total visible, so collapsing never hides what was picked.
            item {
                SectionToggle(
                    title = "Products",
                    subtitle = if (draft.lines.isEmpty()) {
                        "${products.size} to choose from"
                    } else {
                        val units = draft.lines.sumOf { it.quantity }
                        "$units item${if (units == 1) "" else "s"} · " + Money.full(draft.subtotal)
                    },
                    expanded = showProducts,
                    onToggle = { showProducts = !showProducts },
                )
            }

            if (showProducts) {
                // Still emitted as lazy items rather than stuffed into a
                // Column inside the section: only the rows on screen compose.
                items(products.size) { index ->
                    val product = products[index]
                    val quantity = draft.lines.firstOrNull { it.item == product.name }?.quantity ?: 0
                    ProductPickerRow(
                        product = product,
                        quantity = quantity,
                        onChange = { setQuantity(product, it) },
                    )
                }
            } else if (draft.lines.isNotEmpty()) {
                // Collapsed, the chosen lines stay reachable: quantities can
                // still be corrected without reopening the whole catalogue.
                items(draft.lines.size) { index ->
                    val line = draft.lines[index]
                    val product = products.firstOrNull { it.name == line.item }
                        ?: Product(name = line.item, price = line.price)
                    ProductPickerRow(
                        product = product,
                        quantity = line.quantity,
                        onChange = { setQuantity(product, it) },
                    )
                }
            }

            item { Spacer(Modifier.height(4.dp)) }

            // ── Customer (collapsed by default: a walk-in needs nothing) ──
            item {
                ExpandableSection(
                    title = "Customer details",
                    subtitle = if (draft.isWalkIn) "Optional — leave empty for a walk-in sale"
                    else listOf(draft.name, draft.phone).filter { it.isNotBlank() }.joinToString(" · "),
                    expanded = showCustomer,
                    onToggle = { showCustomer = !showCustomer },
                ) {
                    OutlinedTextField(
                        value = draft.name,
                        onValueChange = { draft = draft.copy(name = it) },
                        label = { Text("Name") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = draft.phone,
                        onValueChange = { draft = draft.copy(phone = it.filter { c -> c.isDigit() }.take(10)) },
                        label = { Text("Phone (for WhatsApp updates)") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                        supportingText = {
                            Text(
                                if (draft.phone.isEmpty()) "Leave empty for a walk-in sale"
                                else "${draft.phone.length}/10 digits",
                            )
                        },
                        isError = draft.phone.isNotEmpty() && draft.phone.length != 10,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = draft.notes,
                        onValueChange = { draft = draft.copy(notes = it) },
                        label = { Text("Notes (name on the mug, collection time…)") },
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }

            // ── Discount and extra charge ──────────────────────────
            item {
                ExpandableSection(
                    title = "Discount & extra charges",
                    subtitle = buildString {
                        if (draft.discount > 0) append("−" + Money.short(draft.discount))
                        if (draft.extraCharge > 0) {
                            if (isNotEmpty()) append(" · ")
                            append("+" + Money.short(draft.extraCharge))
                        }
                        if (isEmpty()) append("None")
                    },
                    expanded = showAdjustments,
                    onToggle = { showAdjustments = !showAdjustments },
                ) {
                    OutlinedTextField(
                        value = extraText,
                        onValueChange = {
                            extraText = it.filter { c -> c.isDigit() || c == '.' }
                            draft = draft.copy(extraCharge = extraText.toDoubleOrNull() ?: 0.0)
                        },
                        label = { Text("Extra charge (delivery, rush, packing)") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = draft.extraChargeReason,
                        onValueChange = { draft = draft.copy(extraChargeReason = it) },
                        label = { Text("Reason for the extra charge") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(12.dp))
                    OutlinedTextField(
                        value = discountText,
                        onValueChange = {
                            discountText = it.filter { c -> c.isDigit() || c == '.' }
                            draft = draft.copy(discount = discountText.toDoubleOrNull() ?: 0.0)
                        },
                        label = { Text("Discount (₹ off)") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        // The server clamps this too; catching it here just
                        // saves a round trip and a red banner.
                        isError = draft.discount > draft.subtotal + draft.extraCharge + 0.001,
                        supportingText = {
                            if (draft.discount > draft.subtotal + draft.extraCharge + 0.001) {
                                Text("More than the order value of " + Money.full(draft.subtotal + draft.extraCharge))
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = draft.discountReason,
                        onValueChange = { draft = draft.copy(discountReason = it) },
                        label = { Text("Reason for the discount") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }

            // ── Payment taken now (creation only) ──────────────────
            if (editOrderId == null) {
                item {
                    Card(
                        shape = RoundedCornerShape(18.dp),
                        colors = softCardColors(),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            Text("Money taken now", style = MaterialTheme.typography.titleMedium)
                            Spacer(Modifier.height(10.dp))
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                OutlinedTextField(
                                    value = paidText,
                                    onValueChange = {
                                        paidText = it.filter { c -> c.isDigit() || c == '.' }
                                        draft = draft.copy(paidAmount = paidText.toDoubleOrNull() ?: 0.0)
                                    },
                                    label = { Text("Amount") },
                                    singleLine = true,
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                    modifier = Modifier.weight(1f),
                                )
                                Spacer(Modifier.width(8.dp))
                                TextButton(onClick = {
                                    // "Paid in full" is the common case at a
                                    // stall, and typing the total again is
                                    // how a wrong number gets entered.
                                    paidText = trimNumber(draft.total)
                                    draft = draft.copy(paidAmount = draft.total)
                                }) { Text("Full") }
                            }
                            Spacer(Modifier.height(8.dp))
                            ChipRow(
                                options = listOf(
                                    "cash" to "Cash", "upi" to "UPI",
                                    "card" to "Card", "other" to "Other",
                                ),
                                selected = draft.paymentMode,
                                onSelect = { draft = draft.copy(paymentMode = it) },
                                contentPadding = PaddingValues(0.dp),
                            )
                        }
                    }
                }
            }

            // ── Running bill ───────────────────────────────────────
            if (draft.lines.isNotEmpty()) {
                item {
                    Card(
                        shape = RoundedCornerShape(18.dp),
                        colors = softCardColors(),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            Text("This bill", style = MaterialTheme.typography.titleMedium)
                            Spacer(Modifier.height(8.dp))
                            draft.lines.forEach { line ->
                                DetailRow(
                                    "${line.item} × ${line.quantity}",
                                    Money.full(line.price * line.quantity),
                                )
                            }
                            ThinDivider(Modifier.padding(vertical = 6.dp))
                            DetailRow("Subtotal", Money.full(draft.subtotal))
                            if (draft.extraCharge > 0) {
                                DetailRow(
                                    draft.extraChargeReason.ifBlank { "Extra charge" },
                                    "+" + Money.full(draft.extraCharge),
                                )
                            }
                            if (draft.discount > 0) {
                                DetailRow(
                                    draft.discountReason.ifBlank { "Discount" },
                                    "−" + Money.full(draft.discount),
                                    valueColor = positiveColor(),
                                )
                            }
                            ThinDivider(Modifier.padding(vertical = 6.dp))
                            DetailRow("Total", Money.full(draft.total), emphasise = true)
                        }
                    }
                }
            }

            item { Spacer(Modifier.height(80.dp)) }
        }
    }
}

@Composable
internal fun ProductPickerRow(product: Product, quantity: Int, onChange: (Int) -> Unit) {
    val selected = quantity > 0
    Card(
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (selected) MaterialTheme.colorScheme.primaryContainer
            else MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.3f),
        ),
        onClick = { if (quantity == 0) onChange(1) },
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ProductThumb(product.name, product.imageUrl)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    product.name,
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    Money.full(product.price) + if (selected) "  ·  " + Money.full(product.price * quantity) else "",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            if (selected) {
                FilledTonalIconButton(
                    onClick = { onChange(quantity - 1) },
                    modifier = Modifier.size(36.dp),
                ) {
                    Icon(Icons.Filled.Remove, contentDescription = "One fewer", Modifier.size(18.dp))
                }
                Text(
                    quantity.toString(),
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(horizontal = 14.dp),
                )
            }
            FilledTonalIconButton(
                onClick = { onChange(quantity + 1) },
                modifier = Modifier.size(36.dp),
            ) {
                Icon(Icons.Filled.Add, contentDescription = "One more", Modifier.size(18.dp))
            }
        }
    }
}

/**
 * A collapsible section header for content that lives outside it — used
 * for the product list, whose rows have to stay lazy rather than being
 * nested inside a card.
 */
@Composable
private fun SectionToggle(
    title: String,
    subtitle: String,
    expanded: Boolean,
    onToggle: () -> Unit,
) {
    Card(
        shape = RoundedCornerShape(18.dp),
        colors = softCardColors(),
        onClick = onToggle,
    ) {
        Row(
            Modifier.fillMaxWidth().padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Text(
                    subtitle,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Icon(
                if (expanded) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                contentDescription = if (expanded) "Hide products" else "Show products",
            )
        }
    }
}

@Composable
private fun ExpandableSection(
    title: String,
    subtitle: String,
    expanded: Boolean,
    onToggle: () -> Unit,
    content: @Composable () -> Unit,
) {
    Card(
        shape = RoundedCornerShape(18.dp),
        colors = softCardColors(),
        onClick = onToggle,
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(title, style = MaterialTheme.typography.titleMedium)
                    Text(
                        subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                Icon(
                    if (expanded) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                    contentDescription = if (expanded) "Collapse" else "Expand",
                )
            }
            AnimatedVisibility(visible = expanded) {
                Column(Modifier.padding(top = 12.dp)) { content() }
            }
        }
    }
}

/** 540.0 -> "540", 540.5 -> "540.5" — what a person would type. */
private fun trimNumber(value: Double): String =
    if (value == value.toLong().toDouble()) value.toLong().toString()
    else String.format(java.util.Locale.US, "%.2f", value)
