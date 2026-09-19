@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Movement
import com.madeforu.sales.data.MovementTotals
import com.madeforu.sales.data.PartnerFinance
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.numberText
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * The account ledger, on its own page — movements.php.
 *
 * Every figure on the Money screen is built from these rows, so this is
 * where they are added, corrected and removed. It was a read-only strip
 * at the bottom of Money; a ledger you cannot write to is a report, and
 * the partner who needs to record a payout is the one holding the phone.
 */
@Composable
fun MovementsScreen(
    repository: Repository,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var movements by remember { mutableStateOf<List<Movement>>(emptyList()) }
    var totals by remember { mutableStateOf(MovementTotals()) }
    var partners by remember { mutableStateOf<List<PartnerFinance>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }

    var partnerFilter by remember { mutableStateOf(0) }          // 0 = everyone
    var editing by remember { mutableStateOf<Movement?>(null) }  // Movement() = add
    var confirmDelete by remember { mutableStateOf<Movement?>(null) }

    fun refresh() {
        scope.launch {
            when (val r = repository.movements(partnerId = partnerFilter)) {
                is ApiResult.Success -> {
                    movements = r.value.movements
                    totals = r.value.totals
                    error = null
                }
                is ApiResult.Failure ->
                    if (r.isAuthFailure()) onSessionExpired() else error = r.message
            }
            repository.financeOverview().successOrNull?.let { partners = it.partners }
            loading = false
        }
    }

    LaunchedEffect(partnerFilter) { refresh() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Movements") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.surface,
                ),
            )
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = { editing = Movement() },
                icon = { Icon(Icons.Filled.Add, contentDescription = null) },
                text = { Text("Add movement") },
            )
        },
    ) { padding ->
        if (loading) {
            LoadingBox(Modifier.fillMaxSize().padding(padding))
        } else {
            LazyColumn(
                Modifier.fillMaxSize().padding(padding),
                contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 96.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                item { ErrorBanner(error, onRetry = { refresh() }) }

                message?.let { text ->
                    item {
                        Card(shape = RoundedCornerShape(18.dp), colors = softCardColors()) {
                            Column(Modifier.fillMaxWidth().padding(16.dp)) {
                                Text(text, style = MaterialTheme.typography.bodyMedium)
                                TextButton(onClick = { message = null }) { Text("Got it") }
                            }
                        }
                    }
                }

                // Credits in, debits out and the net — for the whole
                // filtered set, not the rows on screen, which is the
                // basis movements.php uses.
                item {
                    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
                        Column(Modifier.fillMaxWidth().padding(16.dp)) {
                            Row(Modifier.fillMaxWidth()) {
                                LedgerTotal("Credits in", totals.credits, positiveColor(),
                                            Modifier.weight(1f))
                                LedgerTotal("Debits out", totals.debits, negativeColor(),
                                            Modifier.weight(1f))
                            }
                            Spacer(Modifier.height(10.dp))
                            Row(
                                Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                            ) {
                                Text(
                                    "Net across " + totals.count + " movements",
                                    style = MaterialTheme.typography.bodyMedium,
                                )
                                Text(
                                    (if (totals.net >= 0) "+" else "-") +
                                        Money.full(kotlin.math.abs(totals.net)),
                                    style = MaterialTheme.typography.titleMedium,
                                    fontWeight = FontWeight.Bold,
                                    color = if (totals.net >= 0) positiveColor() else negativeColor(),
                                )
                            }
                        }
                    }
                }

                item {
                    Spacer(Modifier.height(8.dp))
                    ChipRow(
                        options = listOf(0 to "Everyone").plus(partners.map { it.id to it.name })
                            .map { it.first.toString() to it.second },
                        selected = partnerFilter.toString(),
                        onSelect = { partnerFilter = it.toIntOrNull() ?: 0 },
                    )
                }

                item { SectionHeader(totals.count.toString() + " movements") }

                if (movements.isEmpty()) {
                    item {
                        Text(
                            "Nothing recorded for this filter yet.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }

                items(movements.size) { index ->
                    val m = movements[index]
                    MovementRow(
                        movement = m,
                        onEdit = {
                            if (m.editable) editing = m
                            else message = "A settlement, or a credit raised from offline sales, " +
                                "cannot be edited — delete it and record it again."
                        },
                        onDelete = { confirmDelete = m },
                    )
                }
            }
        }
    }

    editing?.let { target ->
        MovementSheet(
            existing = if (target.id > 0) target else null,
            partners = partners,
            busy = busy,
            onDismiss = { editing = null },
            onSubmit = { partnerId, direction, amount, date, source, note ->
                editing = null
                busy = true
                scope.launch {
                    val r = if (target.id > 0) {
                        repository.updateMovement(target.id, partnerId, direction, amount,
                                                  date, source, note)
                    } else {
                        repository.addMovement(partnerId, direction, amount, date, source, note)
                    }
                    when (r) {
                        is ApiResult.Success -> { message = r.value; refresh() }
                        is ApiResult.Failure -> error = r.message
                    }
                    busy = false
                }
            },
        )
    }

    confirmDelete?.let { target ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("Delete this movement?") },
            text = {
                Text(
                    if (!target.editable) {
                        "This is one side of a settlement — both sides will go."
                    } else {
                        Money.full(
                            if (target.amount > 0.001) target.amount else target.investAdjust
                        ) + " " + target.direction + " for " + target.partner +
                            ". Any offline orders credited by it can be credited again."
                    }
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    val id = target.id
                    confirmDelete = null
                    busy = true
                    scope.launch {
                        when (val r = repository.deleteMovement(id)) {
                            is ApiResult.Success -> { message = r.value; refresh() }
                            is ApiResult.Failure -> error = r.message
                        }
                        busy = false
                    }
                }) { Text("Delete") }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = null }) { Text("Keep") }
            },
        )
    }
}

/**
 * Add or correct one movement.
 *
 * Credit is money coming IN to a partner's account; debit is money spent
 * out of it. The wording matches movements.php on the website exactly
 * rather than being reworded into something that means subtly something
 * else — this ledger is what every figure on the Money screen is built
 * from.
 */
@Composable
private fun MovementSheet(
    existing: Movement?,
    partners: List<PartnerFinance>,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (Int, String, Double, String, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var partnerId by remember { mutableStateOf(existing?.partnerId ?: partners.firstOrNull()?.id ?: 0) }
    var direction by remember { mutableStateOf(existing?.direction ?: "credit") }
    var amountText by remember {
        mutableStateOf(
            existing?.let { if (it.amount > 0.001) numberText(it.amount) else "" } ?: ""
        )
    }
    var dateText by remember { mutableStateOf(existing?.date ?: Dates.today()) }
    var source by remember { mutableStateOf(existing?.source ?: "") }
    var note by remember { mutableStateOf(existing?.note ?: "") }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                if (existing == null) "Add a movement" else "Correct this movement",
                style = MaterialTheme.typography.titleLarge,
            )

            Text("Partner", style = MaterialTheme.typography.bodySmall)
            ChipRow(
                options = partners.map { it.id.toString() to it.name },
                selected = partnerId.toString(),
                onSelect = { partnerId = it.toIntOrNull() ?: partnerId },
            )

            Text("Direction", style = MaterialTheme.typography.bodySmall)
            ChipRow(
                options = listOf(
                    "credit" to "Credit — money in",
                    "debit" to "Debit — spent from account",
                ),
                selected = direction,
                onSelect = { direction = it },
            )

            OutlinedTextField(
                value = amountText,
                onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' } },
                label = { Text("Amount (₹)") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = dateText,
                onValueChange = { dateText = it },
                label = { Text("Date (YYYY-MM-DD)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = source,
                onValueChange = { source = it },
                label = { Text("Source / purpose") },
                placeholder = { Text("e.g. Meesho payout") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = note,
                onValueChange = { note = it },
                label = { Text("Note (optional)") },
                modifier = Modifier.fillMaxWidth(),
            )

            val amount = amountText.toDoubleOrNull() ?: 0.0
            Button(
                onClick = {
                    onSubmit(partnerId, direction, amount, dateText.trim(),
                             source.trim(), note.trim())
                },
                enabled = !busy && amount > 0 && partnerId > 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text(if (existing == null) "Add movement" else "Save changes") }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun LedgerTotal(label: String, value: Double, tint: androidx.compose.ui.graphics.Color,
                        modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(
            label.uppercase(),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            Money.full(value),
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.Bold,
            color = tint,
        )
    }
}

/** One ledger row: who, what for, and what moved. */
@Composable
private fun MovementRow(movement: Movement, onEdit: () -> Unit, onDelete: () -> Unit) {
    val credit = movement.direction == "credit"
    // A settle-up carries amount 0 and an invest_adjust instead; showing
    // ₹0 would look like a bug, so show what actually moved.
    val shown = if (movement.amount > 0.001) movement.amount else movement.investAdjust

    Card(
        shape = RoundedCornerShape(18.dp),
        colors = softCardColors(),
        modifier = Modifier.fillMaxWidth().clickable { onEdit() },
    ) {
        Row(
            Modifier.fillMaxWidth().padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconTile(label = movement.partner, size = 40.dp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(movement.partner, style = MaterialTheme.typography.titleSmall)
                    movementKindLabel(movement.kind)?.let {
                        Spacer(Modifier.width(6.dp))
                        Pill(it, tint = movementKindTint(movement.kind))
                    }
                }
                Text(
                    Dates.pretty(movement.date) +
                        (movement.source.takeIf { it.isNotBlank() }?.let { " · $it" } ?: ""),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                movement.note?.takeIf { it.isNotBlank() }?.let {
                    Text(
                        it,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            Text(
                (if (credit) "+" else "-") + Money.full(kotlin.math.abs(shown)),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                color = if (credit) positiveColor() else negativeColor(),
            )
            IconButton(onClick = onDelete) {
                Icon(
                    Icons.Filled.Delete,
                    contentDescription = "Delete this movement",
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/**
 * The pill's colour, matching the ones movements.php uses: personal in
 * red, a settlement in blue, profit in green, a settle-up in purple.
 */
@Composable
private fun movementKindTint(kind: String): Color = when (kind) {
    "personal" -> negativeColor()
    "transfer" -> MaterialTheme.colorScheme.primary
    "profit" -> positiveColor()
    "invest" -> MaterialTheme.colorScheme.tertiary
    else -> MaterialTheme.colorScheme.onSurfaceVariant
}

/** The pill movements.php puts against each kind; null for a plain one. */
private fun movementKindLabel(kind: String): String? = when (kind) {
    "personal" -> "personal"
    "transfer" -> "settlement"
    "profit" -> "profit"
    "invest" -> "settle-up"
    else -> null
}
