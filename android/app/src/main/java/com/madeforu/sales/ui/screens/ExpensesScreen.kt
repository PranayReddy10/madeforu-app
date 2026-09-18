@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

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
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Category
import com.madeforu.sales.data.Expense
import com.madeforu.sales.data.ExpenseSummary
import com.madeforu.sales.data.Partner
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DonutChart
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.KpiCard
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.SectionHeader
import kotlinx.coroutines.launch

/**
 * Expenses.
 *
 * Recording one always writes a payment row against the partner who paid,
 * because that is what makes it count towards their investment. An expense
 * with nobody recorded as paying it would quietly distort every partner's
 * fair share.
 */
@Composable
fun ExpensesScreen(
    repository: Repository,
    onOpenExpense: (Int) -> Unit,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var range by remember { mutableStateOf(RangePreset.MONTH) }
    var expenses by remember { mutableStateOf<List<Expense>>(emptyList()) }
    var summary by remember { mutableStateOf(ExpenseSummary()) }
    var byCategory by remember { mutableStateOf<List<Pair<String, Double>>>(emptyList()) }
    var partners by remember { mutableStateOf<List<Partner>>(emptyList()) }
    var categories by remember { mutableStateOf<List<Category>>(emptyList()) }
    var message by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var showAdd by remember { mutableStateOf(false) }

    fun refresh() {
        scope.launch {
            loading = true
            when (val result = repository.expenses(range.from(), range.to())) {
                is ApiResult.Success -> {
                    expenses = result.value.expenses
                    summary = result.value.summary
                    byCategory = result.value.byCategory.map { it.category to it.net }
                    error = null
                }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        repository.bootstrap().let {
            if (it is ApiResult.Success) {
                partners = it.value.partners.filter { partner -> partner.isActive }
                categories = it.value.categories
            }
        }
    }
    LaunchedEffect(range) { refresh() }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Expenses") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = { showAdd = true },
                icon = { Icon(Icons.Filled.Add, contentDescription = null) },
                text = { Text("Add expense") },
            )
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            ChipRow(
                options = RangePreset.entries.map { it to it.label },
                selected = range,
                onSelect = { range = it },
            )

            if (loading) {
                LoadingBox()
                return@Column
            }

            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 96.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                item { ErrorBanner(error, onRetry = { refresh() }) }
                message?.let { text ->
                    item {
                        Card(
                            shape = RoundedCornerShape(14.dp),
                            colors = CardDefaults.cardColors(
                                containerColor = MaterialTheme.colorScheme.secondaryContainer,
                            ),
                        ) { Text(text, Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium) }
                    }
                }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        KpiCard(
                            "Spent", Money.short(summary.net),
                            caption = "${summary.count} expenses",
                            modifier = Modifier.weight(1f),
                        )
                        KpiCard(
                            "Saved on discounts", Money.short(summary.discount),
                            caption = "off " + Money.short(summary.gross),
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

                if (byCategory.isNotEmpty()) {
                    item { SectionHeader("Where it went") }
                    item {
                        Card(
                            shape = RoundedCornerShape(20.dp),
                            colors = softCardColors(),
                        ) {
                            Column(Modifier.padding(16.dp)) {
                                DonutChart(
                                    slices = byCategory.take(6),
                                    centreLabel = "spent",
                                    centreValue = Money.compact(summary.net),
                                )
                            }
                        }
                    }
                }

                item { SectionHeader("Every expense") }
                item {
                    Text(
                        "Tap an expense to see who paid, edit it or delete it.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                items(expenses.size) { index ->
                    val expense = expenses[index]
                    Card(
                        onClick = { onOpenExpense(expense.id) },
                        shape = RoundedCornerShape(16.dp),
                        colors = softCardColors(),
                        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                    ) {
                        Row(
                            Modifier.fillMaxWidth().padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            // Coloured by category, so the same kind of
                            // spend is the same colour down the list.
                            IconTile(label = expense.category.ifBlank { expense.item })
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(expense.item, style = MaterialTheme.typography.titleMedium)
                                Text(
                                    Dates.pretty(expense.date) + " · " + expense.category +
                                        (expense.paidBy?.let { " · paid by $it" } ?: ""),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                // Say up front that there is a breakdown to
                                // open, and when more than one partner paid.
                                if (expense.itemCount > 0 || expense.payerCount > 1) {
                                    Text(
                                        listOfNotNull(
                                            expense.itemCount.takeIf { it > 0 }
                                                ?.let { "$it item${if (it == 1) "" else "s"}" },
                                            expense.payerCount.takeIf { it > 1 }
                                                ?.let { "$it partners paid" },
                                        ).joinToString(" · "),
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.primary,
                                    )
                                }
                            }
                            Column(horizontalAlignment = Alignment.End) {
                                Text(Money.short(expense.net), fontWeight = FontWeight.SemiBold)
                                if (expense.discount > 0.5) {
                                    Text(
                                        "−" + Money.short(expense.discount),
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (showAdd) {
        ExpenseSheet(
            existing = null,
            partners = partners,
            categories = categories.map { it.name },
            onDismiss = { showAdd = false },
            onSubmit = { form ->
                showAdd = false
                scope.launch {
                    val result = repository.saveExpense(
                        id = null,
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
                        is ApiResult.Success -> { message = result.value; refresh() }
                        is ApiResult.Failure -> error = result.message
                    }
                }
            },
        )
    }
}
