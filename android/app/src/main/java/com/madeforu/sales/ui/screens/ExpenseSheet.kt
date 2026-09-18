@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.Expense
import com.madeforu.sales.data.Partner
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor

/** Everything the add/edit sheet collects. */
data class ExpenseForm(
    val date: String,
    val item: String,
    val amount: Double,
    val discount: Double,
    val paidBy: Int,
    val category: String,
    val paidTo: String,
    val details: String,
    /** Empty means the whole net amount against [paidBy]. */
    val split: List<Pair<Int, Double>>,
)

/**
 * Add or edit an expense. One sheet for both, because the fields and the
 * rules are identical and two copies would drift the moment one changed.
 *
 * The part worth care is "who paid from pocket". An expense can be split
 * between partners — two people chipping in for a machine is normal here —
 * and those rows are what the Money screen counts as each partner's
 * investment. The split must add up to the net amount, and the sheet says
 * so as you type rather than letting the server reject it after a round
 * trip.
 */
@Composable
fun ExpenseSheet(
    existing: Expense?,
    partners: List<Partner>,
    categories: List<String>,
    onDismiss: () -> Unit,
    onSubmit: (ExpenseForm) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    var date by remember { mutableStateOf(existing?.date ?: Dates.today()) }
    var item by remember { mutableStateOf(existing?.item ?: "") }
    var amountText by remember { mutableStateOf(existing?.amount?.let(::plain) ?: "") }
    var discountText by remember {
        mutableStateOf(existing?.discount?.takeIf { it > 0 }?.let(::plain) ?: "")
    }
    var paidTo by remember { mutableStateOf(existing?.paidTo ?: "") }
    var details by remember { mutableStateOf(existing?.details ?: "") }
    var paidBy by remember {
        mutableStateOf(existing?.paidById?.takeIf { it > 0 } ?: partners.firstOrNull()?.id ?: 0)
    }
    var category by remember {
        mutableStateOf(existing?.category?.takeIf { it.isNotBlank() } ?: categories.firstOrNull() ?: "Other")
    }

    // A split is only "on" when more than one partner actually paid.
    var splitting by remember { mutableStateOf((existing?.payers?.size ?: 0) > 1) }
    var splitAmounts by remember {
        mutableStateOf(
            partners.associate { partner ->
                partner.id to (
                    existing?.payers?.firstOrNull { it.partnerId == partner.id }
                        ?.amount?.let(::plain) ?: ""
                    )
            },
        )
    }

    val amount = amountText.toDoubleOrNull() ?: 0.0
    val discount = discountText.toDoubleOrNull() ?: 0.0
    val net = (amount - discount).coerceAtLeast(0.0)
    val splitRows = splitAmounts.mapNotNull { (id, text) ->
        val value = text.toDoubleOrNull() ?: 0.0
        if (value > 0) id to value else null
    }
    val splitTotal = splitRows.sumOf { it.second }
    val splitBalances = !splitting || kotlin.math.abs(splitTotal - net) < 0.01
    val valid = item.isNotBlank() && amount > 0 && discount <= amount &&
        (if (splitting) splitRows.isNotEmpty() && splitBalances else paidBy > 0)

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(
                if (existing == null) "Add an expense" else "Edit expense",
                style = MaterialTheme.typography.titleLarge,
            )

            OutlinedTextField(
                value = item,
                onValueChange = { item = it },
                label = { Text("What was bought") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Row {
                OutlinedTextField(
                    value = amountText,
                    onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Amount") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                OutlinedTextField(
                    value = discountText,
                    onValueChange = { discountText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Discount") },
                    singleLine = true,
                    isError = discount > amount && amount > 0,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
            }
            if (discount > 0 && amount > 0) {
                Text(
                    "Net " + Money.full(net),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            OutlinedTextField(
                value = date,
                onValueChange = { date = it },
                label = { Text("Date (YYYY-MM-DD)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = paidTo,
                onValueChange = { paidTo = it },
                label = { Text("Paid to (shop, dealer)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            // ── Who paid ───────────────────────────────────────────
            Text("Who paid (from pocket)", style = MaterialTheme.typography.titleSmall)
            Text(
                "This is what counts towards a partner's investment, so it has to be whoever " +
                    "actually put the money in.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(checked = splitting, onCheckedChange = { splitting = it })
                Spacer(Modifier.width(12.dp))
                Text("More than one partner paid")
            }

            if (!splitting) {
                ChipRow(
                    options = partners.map { it.id to it.name },
                    selected = paidBy,
                    onSelect = { paidBy = it },
                    contentPadding = PaddingValues(0.dp),
                )
            }

            AnimatedVisibility(visible = splitting) {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    partners.forEach { partner ->
                        OutlinedTextField(
                            value = splitAmounts[partner.id].orEmpty(),
                            onValueChange = { raw ->
                                val cleaned = raw.filter { c -> c.isDigit() || c == '.' }
                                splitAmounts = splitAmounts + (partner.id to cleaned)
                            },
                            label = { Text(partner.name) },
                            placeholder = { Text("0") },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    Row(Modifier.fillMaxWidth()) {
                        TextButton(onClick = {
                            // An even split is the usual case when two
                            // partners go halves on something.
                            val each = if (partners.isEmpty()) 0.0 else net / partners.size
                            splitAmounts = partners.associate { it.id to plain(each) }
                        }) { Text("Split evenly") }
                        Spacer(Modifier.width(8.dp))
                        TextButton(onClick = {
                            splitAmounts = partners.associate { it.id to "" }
                        }) { Text("Clear") }
                    }
                    Text(
                        if (splitBalances) {
                            "Split adds up to " + Money.full(splitTotal) + " ✓"
                        } else {
                            "Split adds up to " + Money.full(splitTotal) + ", but the expense is " +
                                Money.full(net)
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = if (splitBalances) positiveColor() else negativeColor(),
                    )
                }
            }

            if (categories.isNotEmpty()) {
                Text("Category", style = MaterialTheme.typography.titleSmall)
                ChipRow(
                    options = categories.map { it to it },
                    selected = category,
                    onSelect = { category = it },
                    contentPadding = PaddingValues(0.dp),
                )
            }

            OutlinedTextField(
                value = details,
                onValueChange = { details = it },
                label = { Text("Notes") },
                modifier = Modifier.fillMaxWidth(),
            )

            Button(
                onClick = {
                    onSubmit(
                        ExpenseForm(
                            date = date,
                            item = item.trim(),
                            amount = amount,
                            discount = discount,
                            // With a split, paid_by is still required by the
                            // server as the headline payer; the largest
                            // contributor is the honest choice.
                            paidBy = if (splitting) {
                                splitRows.maxByOrNull { it.second }?.first ?: paidBy
                            } else paidBy,
                            category = category,
                            paidTo = paidTo.trim(),
                            details = details.trim(),
                            split = if (splitting) splitRows else emptyList(),
                        ),
                    )
                },
                enabled = valid,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text(if (existing == null) "Save expense" else "Save changes") }

            Text(
                "Receipts are uploaded on the website — this sheet does not attach files yet.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(24.dp))
        }
    }
}

/** 1200.0 -> "1200", 1200.5 -> "1200.50" — what a person would type. */
private fun plain(value: Double): String =
    if (value == value.toLong().toDouble()) value.toLong().toString()
    else String.format(java.util.Locale.US, "%.2f", value)
