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
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
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
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.toMutableStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.WholesaleDetail
import com.madeforu.sales.data.WholesaleLine
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.ProductThumb
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import kotlinx.coroutines.launch

/**
 * One wholesale buyer: what they take, and every visit.
 *
 * "What they take" is the part worth having. When somebody walks in
 * again, the question is not what the last bill came to — it is what
 * this person buys and what you last charged them for it. The price
 * range is there because a wholesale price moves, and where it has moved
 * to is the thing you need to remember.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WholesaleBuyerScreen(
    buyerId: Int,
    repository: Repository,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var data by remember { mutableStateOf<WholesaleDetail?>(null) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var recording by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf<Int?>(null) }

    fun refresh() {
        scope.launch {
            when (val r = repository.wholesaleBuyer(buyerId)) {
                is ApiResult.Success -> { data = r.value; error = null }
                is ApiResult.Failure ->
                    if (r.isAuthFailure()) onSessionExpired() else error = r.message
            }
            loading = false
        }
    }

    LaunchedEffect(buyerId) { refresh() }

    val current = data
    Scaffold(
        modifier = Modifier.imePadding(),
        topBar = {
            TopAppBar(
                title = { Text(current?.customer?.name ?: "Buyer") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(onClick = { editing = true }, enabled = current != null) {
                        Icon(Icons.Filled.Edit, contentDescription = "Edit details")
                    }
                    IconButton(onClick = { recording = true }, enabled = current != null) {
                        Icon(Icons.Filled.Add, contentDescription = "Record what they took")
                    }
                },
            )
        },
    ) { padding ->
        if (loading || current == null) {
            Column(Modifier.padding(padding)) {
                if (error != null) {
                    Card(
                        shape = RoundedCornerShape(18.dp),
                        colors = softCardColors(),
                        modifier = Modifier.padding(16.dp),
                    ) {
                        Text(error!!, style = MaterialTheme.typography.bodyMedium,
                             modifier = Modifier.padding(16.dp))
                    }
                } else {
                    LoadingBox()
                }
            }
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            errorBannerItem(error, onRetry = { refresh() })

            message?.let {
                item {
                    Text(
                        it,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.primary,
                    )
                }
            }

            item {
                Card(shape = RoundedCornerShape(22.dp), colors = softCardColors()) {
                    Column(Modifier.fillMaxWidth().padding(16.dp)) {
                        if (current.customer.where.isNotBlank()) {
                            Text(
                                current.customer.where,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            Spacer(Modifier.height(4.dp))
                        }
                        Text(
                            "${current.totals.visits} " +
                                (if (current.totals.visits == 1) "visit" else "visits") +
                                " · ${current.totals.units} pieces",
                            style = MaterialTheme.typography.bodyMedium,
                        )
                        Text(
                            Money.full(current.totals.value),
                            style = MaterialTheme.typography.headlineSmall,
                            fontWeight = FontWeight.Bold,
                        )
                        current.customer.phone?.takeIf { it.isNotBlank() }?.let { phone ->
                            Spacer(Modifier.height(4.dp))
                            Text(
                                phone,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        current.customer.notes?.takeIf { it.isNotBlank() }?.let { note ->
                            Spacer(Modifier.height(6.dp))
                            Text(
                                note,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }

            if (current.summary.isNotEmpty()) {
                item { SectionHeader("What they take") }
                item {
                    Card(shape = RoundedCornerShape(22.dp), colors = softCardColors()) {
                        Column(Modifier.fillMaxWidth().padding(14.dp)) {
                            current.summary.forEachIndexed { index, row ->
                                if (index > 0) {
                                    Spacer(Modifier.height(10.dp))
                                    ThinDivider()
                                    Spacer(Modifier.height(10.dp))
                                }
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    ProductThumb(row.item, null)
                                    Spacer(Modifier.width(12.dp))
                                    Column(Modifier.weight(1f)) {
                                        Text(
                                            row.item,
                                            style = MaterialTheme.typography.bodyLarge,
                                            fontWeight = FontWeight.SemiBold,
                                        )
                                        Text(
                                            "${row.qty} pieces over ${row.times} " +
                                                if (row.times == 1) "visit" else "visits",
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                        Text(
                                            (if (row.low == row.high) Money.full(row.low)
                                             else "${Money.full(row.low)} – ${Money.full(row.high)}") +
                                                " each · last ${Dates.pretty(row.lastDate)}",
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                    Text(
                                        Money.full(row.total),
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Bold,
                                    )
                                }
                            }
                        }
                    }
                }
            }

            item {
                SectionHeader(
                    "${current.visits.size} " +
                        if (current.visits.size == 1) "visit" else "visits"
                )
            }

            if (current.visits.isEmpty()) {
                item {
                    Text(
                        "Nothing recorded yet. Use + above to write down what they took.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            items(current.visits.size) { index ->
                val visit = current.visits[index]
                Card(shape = RoundedCornerShape(18.dp), colors = softCardColors()) {
                    Column(Modifier.fillMaxWidth().padding(14.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(
                                    Dates.pretty(visit.date),
                                    style = MaterialTheme.typography.bodyLarge,
                                    fontWeight = FontWeight.SemiBold,
                                )
                                visit.note?.takeIf { it.isNotBlank() }?.let {
                                    Text(
                                        it,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                            Text(
                                Money.full(visit.total),
                                style = MaterialTheme.typography.bodyLarge,
                                fontWeight = FontWeight.Bold,
                            )
                            IconButton(onClick = { confirmDelete = visit.id }) {
                                Icon(
                                    Icons.Filled.Delete,
                                    contentDescription = "Remove this entry",
                                    tint = MaterialTheme.colorScheme.error,
                                )
                            }
                        }
                        Spacer(Modifier.height(4.dp))
                        visit.items.forEach { line ->
                            DetailRow(
                                "${line.item} × ${line.quantity}",
                                "${Money.full(line.unitPrice)}  ·  ${Money.full(line.lineTotal)}",
                            )
                        }
                    }
                }
            }
        }
    }

    if (recording) {
        VisitSheet(
            buyerName = current?.customer?.name ?: "",
            busy = busy,
            onDismiss = { recording = false },
            onSubmit = { date, note, lines ->
                recording = false
                busy = true
                scope.launch {
                    when (val r = repository.addWholesaleVisit(buyerId, date, note, lines)) {
                        is ApiResult.Success -> { data = r.value; message = r.value.message; error = null }
                        is ApiResult.Failure -> error = r.message
                    }
                    busy = false
                }
            },
        )
    }

    if (editing && current != null) {
        BuyerSheet(
            existing = current.customer,
            busy = busy,
            onDismiss = { editing = false },
            onSubmit = { name, phone, shop, place, notes ->
                editing = false
                busy = true
                scope.launch {
                    when (
                        val r = repository.updateWholesaleCustomer(
                            buyerId, name, phone, shop, place, notes
                        )
                    ) {
                        is ApiResult.Success -> { message = r.value.message; refresh() }
                        is ApiResult.Failure -> error = r.message
                    }
                    busy = false
                }
            },
        )
    }

    confirmDelete?.let { visitId ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("Remove this entry?") },
            text = { Text("What they took that day will be removed from the notebook.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = null
                    scope.launch {
                        when (val r = repository.deleteWholesaleVisit(visitId)) {
                            is ApiResult.Success -> { data = r.value; message = r.value.message }
                            is ApiResult.Failure -> error = r.message
                        }
                    }
                }) { Text("Remove") }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = null }) { Text("Keep") }
            },
        )
    }
}

/**
 * Record one visit.
 *
 * Every line is priced by hand. There is deliberately no lookup from the
 * catalogue: the whole point of this notebook is the price that was
 * agreed on the day, and a price that could be refreshed from somewhere
 * else would stop being that.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun VisitSheet(
    buyerName: String,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (String, String, List<WholesaleLine>) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var date by remember { mutableStateOf(Dates.today()) }
    var note by remember { mutableStateOf("") }

    // Kept as text, not numbers: a Double turns "12." into "12" while it
    // is still being typed and the caret jumps to the end.
    val lines = remember { mutableListOf(VisitDraftLine()).toMutableStateList() }

    val total = lines.sumOf {
        (it.qty.toIntOrNull() ?: 0) * (it.price.toDoubleOrNull() ?: 0.0)
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        LazyColumn(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp),
            contentPadding = PaddingValues(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                Text(
                    if (buyerName.isBlank()) "What did they take?" else "What did $buyerName take?",
                    style = MaterialTheme.typography.titleLarge,
                )
            }
            item {
                OutlinedTextField(
                    value = date, onValueChange = { date = it },
                    label = { Text("Date (YYYY-MM-DD)") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            item {
                OutlinedTextField(
                    value = note, onValueChange = { note = it },
                    label = { Text("Note (optional)") },
                    placeholder = { Text("e.g. paid cash, collected himself") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            items(lines.size) { index ->
                val line = lines[index]
                Card(shape = RoundedCornerShape(16.dp), colors = softCardColors()) {
                    Column(Modifier.fillMaxWidth().padding(12.dp)) {
                        OutlinedTextField(
                            value = line.item,
                            onValueChange = { lines[index] = line.copy(item = it) },
                            label = { Text("Product") },
                            placeholder = { Text("type anything") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth(),
                        )
                        Spacer(Modifier.height(8.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            OutlinedTextField(
                                value = line.qty,
                                onValueChange = {
                                    lines[index] = line.copy(qty = it.filter { c -> c.isDigit() })
                                },
                                label = { Text("Qty") }, singleLine = true,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                                modifier = Modifier.weight(1f),
                            )
                            OutlinedTextField(
                                value = line.price,
                                onValueChange = {
                                    lines[index] = line.copy(
                                        price = it.filter { c -> c.isDigit() || c == '.' }
                                    )
                                },
                                label = { Text("Price each (₹)") }, singleLine = true,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                modifier = Modifier.weight(1f),
                            )
                        }
                        if (lines.size > 1) {
                            Spacer(Modifier.height(6.dp))
                            OutlinedButton(onClick = { lines.removeAt(index) }) { Text("Remove") }
                        }
                    }
                }
            }

            item {
                OutlinedButton(
                    onClick = { lines.add(VisitDraftLine()) },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("+ Another product") }
            }

            item {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("Total", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    Text(
                        Money.full(total),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            item {
                val ready = lines.any {
                    it.item.isNotBlank() && (it.qty.toIntOrNull() ?: 0) >= 1
                }
                Button(
                    onClick = {
                        onSubmit(
                            date.trim(),
                            note.trim(),
                            lines.filter { it.item.isNotBlank() }.map {
                                WholesaleLine(
                                    item = it.item.trim(),
                                    quantity = it.qty.toIntOrNull() ?: 0,
                                    unitPrice = it.price.toDoubleOrNull() ?: 0.0,
                                )
                            },
                        )
                    },
                    enabled = !busy && ready,
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                ) { Text("Save entry") }
            }
        }
    }

}

/** One line being typed into the visit sheet. */
private data class VisitDraftLine(
    val item: String = "",
    val qty: String = "1",
    val price: String = "",
)
