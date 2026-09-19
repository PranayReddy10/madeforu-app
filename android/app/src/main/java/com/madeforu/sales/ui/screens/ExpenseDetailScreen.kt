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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Expense
import com.madeforu.sales.data.Partner
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.warnColor
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * One expense in full: what was bought, what it cost, and — the part that
 * matters for partner accounts — exactly who put the money in.
 *
 * Editing and deleting live here rather than on the list. A swipe action
 * on a row is easy to trigger by accident, and an expense carries a
 * partner's investment: deleting one silently changes what three other
 * people are owed.
 */
@Composable
fun ExpenseDetailScreen(
    repository: Repository,
    expenseId: Int,
    onBack: () -> Unit,
    onChanged: (String) -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var expense by remember { mutableStateOf<Expense?>(null) }
    var partners by remember { mutableStateOf<List<Partner>>(emptyList()) }
    var categories by remember { mutableStateOf<List<String>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            when (val result = repository.expense(expenseId)) {
                is ApiResult.Success -> { expense = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    LaunchedEffect(expenseId) {
        load()
        repository.bootstrap().let {
            if (it is ApiResult.Success) {
                partners = it.value.partners.filter { p -> p.isActive }
                categories = it.value.categories.map { c -> c.name }
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Expense") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(onClick = { editing = true }, enabled = expense != null) {
                        Icon(Icons.Filled.Edit, contentDescription = "Edit expense")
                    }
                    IconButton(onClick = { confirmDelete = true }, enabled = expense != null) {
                        Icon(Icons.Filled.Delete, contentDescription = "Delete expense")
                    }
                },
            )
        },
    ) { padding ->
        val current = expense
        if (loading || current == null) {
            Column(Modifier.padding(padding)) {
                ErrorBanner(error, Modifier.padding(16.dp), onRetry = { load() })
                if (error == null) LoadingBox()
            }
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            errorBannerItem(error, onRetry = { load() })

            item {
                Card(
                    shape = RoundedCornerShape(22.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.errorContainer,
                    ),
                ) {
                    Column(Modifier.fillMaxWidth().padding(20.dp)) {
                        Text("SPENT", style = MaterialTheme.typography.labelSmall)
                        Spacer(Modifier.height(4.dp))
                        Text(
                            Money.full(current.net),
                            style = MaterialTheme.typography.displaySmall,
                            fontWeight = FontWeight.Bold,
                        )
                        Text(current.item, style = MaterialTheme.typography.titleMedium)
                        if (current.discount > 0.5) {
                            Spacer(Modifier.height(6.dp))
                            Pill(Money.full(current.discount) + " discount", positiveColor())
                        }
                    }
                }
            }

            if (current.items.isNotEmpty()) {
                item { SectionHeader("What was in it") }
                item { ExpenseBreakdown(current) }
            }

            item { SectionHeader("Details") }
            item {
                Card(
                    shape = RoundedCornerShape(18.dp),
                    colors = softCardColors(),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        DetailRow("Date", Dates.pretty(current.date))
                        DetailRow("Category", current.category)
                        if (current.paidTo.isNotBlank()) DetailRow("Paid to", current.paidTo)
                        DetailRow("Amount", Money.full(current.amount))
                        if (current.discount > 0.5) {
                            DetailRow("Discount", "-" + Money.full(current.discount), valueColor = positiveColor())
                        }
                        ThinDivider(Modifier.padding(vertical = 8.dp))
                        DetailRow("Net", Money.full(current.net), emphasise = true)
                        if (current.details.isNotBlank()) {
                            ThinDivider(Modifier.padding(vertical = 8.dp))
                            Text(
                                current.details,
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }

            item {
                SectionHeader("Who paid, from pocket")
            }
            item {
                Card(
                    shape = RoundedCornerShape(18.dp),
                    colors = softCardColors(),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        if (current.payers.isEmpty()) {
                            Text(
                                "Nobody is recorded as paying for this, so it counts towards " +
                                    "no partner's investment. Edit it to say who paid.",
                                style = MaterialTheme.typography.bodyMedium,
                                color = negativeColor(),
                            )
                        } else {
                            current.payers.forEach { payer ->
                                DetailRow(payer.partner, Money.full(payer.amount))
                            }
                            if (current.payers.size > 1) {
                                ThinDivider(Modifier.padding(vertical = 8.dp))
                                DetailRow(
                                    "Total put in",
                                    Money.full(current.payers.sumOf { it.amount }),
                                    emphasise = true,
                                )
                            }
                            Spacer(Modifier.height(8.dp))
                            Text(
                                "This is what counts towards each partner's investment on the " +
                                    "Money screen.",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }
            }

            if (current.hasReceipt) {
                item {
                    Text(
                        "A receipt is attached to this expense. Receipts are uploaded and viewed " +
                            "on the website — the app does not fetch them yet.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            item {
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick = { editing = true },
                        enabled = !busy,
                        modifier = Modifier.weight(1f),
                    ) {
                        Icon(Icons.Filled.Edit, contentDescription = null, Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Edit")
                    }
                    TextButton(
                        onClick = { confirmDelete = true },
                        enabled = !busy,
                        modifier = Modifier.weight(1f),
                    ) { Text("Delete", color = negativeColor()) }
                }
                Spacer(Modifier.height(32.dp))
            }
        }
    }

    if (editing && expense != null) {
        ExpenseSheet(
            existing = expense,
            partners = partners,
            categories = categories,
            onDismiss = { editing = false },
            onSubmit = { form ->
                editing = false
                busy = true
                scope.launch {
                    val result = repository.saveExpense(
                        id = expenseId,
                        date = form.date,
                        item = form.item,
                        amount = form.amount,
                        discount = form.discount,
                        paidBy = form.paidBy,
                        category = form.category,
                        paidTo = form.paidTo,
                        details = form.details,
                        split = form.split,
                    )
                    when (result) {
                        is ApiResult.Success -> { onChanged(result.value); load() }
                        is ApiResult.Failure -> error = result.message
                    }
                    busy = false
                }
            },
        )
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Delete this expense?") },
            text = {
                Text(
                    "It is removed along with the record of who paid for it, so every " +
                        "partner's investment figure changes. This cannot be undone.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    busy = true
                    scope.launch {
                        when (val result = repository.deleteExpense(expenseId)) {
                            is ApiResult.Success -> onChanged(result.value)
                            is ApiResult.Failure -> error = result.message
                        }
                        busy = false
                    }
                }) { Text("Delete", color = negativeColor()) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = false }) { Text("Keep it") }
            },
        )
    }
}

/**
 * The line items behind an expense — what was actually bought, at what
 * quantity and price.
 *
 * A single "Machine · ₹74,010" tells a partner nothing they can check. The
 * nineteen lines behind it are the whole point, and they were being
 * fetched from the server and then thrown away.
 *
 * Long lists fold after six rows, because an expense with twenty lines
 * would otherwise bury the payers and the actions below it.
 */
@Composable
private fun ExpenseBreakdown(expense: Expense) {
    var expanded by remember { mutableStateOf(false) }
    val lines = expense.items
    val shown = if (expanded || lines.size <= 6) lines else lines.take(6)
    val linesTotal = lines.sumOf { it.lineTotal }
    // The lines should add up to the amount. When they do not, the expense
    // was edited on one side only, and saying so is more useful than
    // quietly showing two numbers that disagree.
    val matches = kotlin.math.abs(linesTotal - expense.amount) < 0.01

    Card(
        shape = RoundedCornerShape(18.dp),
        colors = softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            shown.forEach { line ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            line.descr,
                            style = MaterialTheme.typography.bodyMedium,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Text(
                            // Whole quantities read as "5", not "5.0" — a
                            // fractional quantity is rare and only shown
                            // when it is real.
                            qtyText(line.qty) + " × " + Money.full(line.unitCost),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Spacer(Modifier.width(10.dp))
                    Text(
                        Money.full(line.lineTotal),
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.Medium,
                    )
                }
                ThinDivider()
            }

            if (lines.size > 6) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable { expanded = !expanded }
                        .padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        if (expanded) "Show fewer" else "Show all ${lines.size} items",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.weight(1f),
                    )
                    Icon(
                        if (expanded) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                    )
                }
            }

            Spacer(Modifier.height(6.dp))
            DetailRow(
                "${lines.size} item${if (lines.size == 1) "" else "s"}",
                Money.full(linesTotal),
                emphasise = true,
            )
            if (!matches) {
                Text(
                    "These lines add up to " + Money.full(linesTotal) + ", but the expense is " +
                        Money.full(expense.amount) + ". One side was edited without the other — " +
                        "the expense total is what counts towards partner accounts.",
                    style = MaterialTheme.typography.bodySmall,
                    color = warnColor(),
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }
    }
}

/** 5.0 -> "5", 2.5 -> "2.5" — a trailing .0 on a quantity is noise. */
private fun qtyText(qty: Double): String =
    if (qty == qty.toLong().toDouble()) qty.toLong().toString()
    else String.format(java.util.Locale.US, "%.2f", qty)
