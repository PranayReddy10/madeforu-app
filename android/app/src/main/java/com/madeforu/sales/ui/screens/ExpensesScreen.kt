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
                            colors = CardDefaults.cardColors(
                                containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.35f),
                            ),
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

                items(expenses.size) { index ->
                    val expense = expenses[index]
                    Card(
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.3f),
                        ),
                    ) {
                        Row(
                            Modifier.fillMaxWidth().padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(expense.item, style = MaterialTheme.typography.titleMedium)
                                Text(
                                    Dates.pretty(expense.date) + " · " + expense.category +
                                        (expense.paidBy?.let { " · paid by $it" } ?: ""),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
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
        AddExpenseSheet(
            partners = partners,
            categories = categories,
            onDismiss = { showAdd = false },
            onSubmit = { date, item, amount, discount, paidBy, category, paidTo, details ->
                showAdd = false
                scope.launch {
                    val result = repository.addExpense(
                        date, item, amount, discount, paidBy, category, paidTo, details,
                    )
                    when (result) {
                        is ApiResult.Success -> refresh()
                        is ApiResult.Failure -> error = result.message
                    }
                }
            },
        )
    }
}

@Composable
private fun AddExpenseSheet(
    partners: List<Partner>,
    categories: List<Category>,
    onDismiss: () -> Unit,
    onSubmit: (String, String, Double, Double, Int, String, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var date by remember { mutableStateOf(Dates.today()) }
    var item by remember { mutableStateOf("") }
    var amountText by remember { mutableStateOf("") }
    var discountText by remember { mutableStateOf("") }
    var paidTo by remember { mutableStateOf("") }
    var details by remember { mutableStateOf("") }
    var paidBy by remember { mutableStateOf(partners.firstOrNull()?.id ?: 0) }
    var category by remember {
        mutableStateOf(categories.firstOrNull()?.name ?: "Other")
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text("Add an expense", style = MaterialTheme.typography.titleLarge)

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
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
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

            Text("Who paid", style = MaterialTheme.typography.labelSmall)
            ChipRow(
                options = partners.map { it.id to it.name },
                selected = paidBy,
                onSelect = { paidBy = it },
                contentPadding = PaddingValues(0.dp),
            )

            if (categories.isNotEmpty()) {
                Text("Category", style = MaterialTheme.typography.labelSmall)
                ChipRow(
                    options = categories.map { it.name to it.name },
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
                        date,
                        item,
                        amountText.toDoubleOrNull() ?: 0.0,
                        discountText.toDoubleOrNull() ?: 0.0,
                        paidBy,
                        category,
                        paidTo,
                        details,
                    )
                },
                enabled = item.isNotBlank() && (amountText.toDoubleOrNull() ?: 0.0) > 0 && paidBy > 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Save expense") }

            Text(
                "The full amount is recorded against whoever paid, which is what counts " +
                    "towards their investment in the business.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(24.dp))
        }
    }
}
