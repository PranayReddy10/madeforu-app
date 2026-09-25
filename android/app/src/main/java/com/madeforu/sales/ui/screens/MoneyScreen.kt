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
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.FinanceOverview
import com.madeforu.sales.data.PartnerFinance
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.ListCard
import com.madeforu.sales.ui.components.ListRow
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor
import kotlinx.coroutines.launch

/**
 * Partner money: who has put in what, who is owed, and the shortest set of
 * transfers that makes everyone square.
 *
 * The wording is deliberate. "Gap" is what the website calls it, and a
 * partner reading both should not have to translate.
 */
@Composable
fun MoneyScreen(
    repository: Repository,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var overview by remember { mutableStateOf<FinanceOverview?>(null) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var creditPartnerId by remember { mutableStateOf<Int?>(null) }
    var creditFrom by remember { mutableStateOf(Dates.daysAgo(30)) }
    var creditTo by remember { mutableStateOf(Dates.today()) }
    var showCreditForm by remember { mutableStateOf(false) }

    fun refresh() {
        scope.launch {
            when (val result = repository.financeOverview()) {
                is ApiResult.Success -> { overview = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { refresh() }

    Column(Modifier.fillMaxSize()) {
        TopAppBar(
            title = { Text("Money") },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.background,
            ),
        )

        val data = overview
        if (loading && data == null) {
            LoadingBox()
            return@Column
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            errorBannerItem(error, onRetry = { refresh() })
            message?.let { text ->
                item {
                    Card(
                        shape = RoundedCornerShape(14.dp),
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.secondaryContainer,
                        ),
                    ) {
                        Text(text, Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium)
                    }
                }
            }

            if (data != null) {
                item { InvestmentHeadline(data) }

                // ── Uncredited offline sales ───────────────────────
                if (data.uncreditedOffline.orders > 0) {
                    item {
                        Card(
                            shape = RoundedCornerShape(20.dp),
                            colors = softCardColors(),
                            elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
                        ) {
                            Column(Modifier.padding(16.dp)) {
                                Text(
                                    "Offline sales not yet credited",
                                    style = MaterialTheme.typography.titleMedium,
                                )
                                Text(
                                    Money.full(data.uncreditedOffline.amount) +
                                        " across ${data.uncreditedOffline.orders} walk-up orders is sitting " +
                                        "outside anyone's account.",
                                    style = MaterialTheme.typography.bodyMedium,
                                )
                                Spacer(Modifier.height(10.dp))
                                TextButton(onClick = { showCreditForm = !showCreditForm }) {
                                    Text(if (showCreditForm) "Hide" else "Credit to a partner")
                                }

                                if (showCreditForm) {
                                    Spacer(Modifier.height(4.dp))
                                    ChipRow(
                                        options = data.partners.map { it.id as Int? to it.name },
                                        selected = creditPartnerId,
                                        onSelect = { creditPartnerId = it },
                                        contentPadding = PaddingValues(0.dp),
                                    )
                                    Spacer(Modifier.height(8.dp))
                                    Row {
                                        OutlinedTextField(
                                            value = creditFrom,
                                            onValueChange = { creditFrom = it },
                                            label = { Text("From") },
                                            singleLine = true,
                                            modifier = Modifier.weight(1f),
                                        )
                                        Spacer(Modifier.width(8.dp))
                                        OutlinedTextField(
                                            value = creditTo,
                                            onValueChange = { creditTo = it },
                                            label = { Text("To") },
                                            singleLine = true,
                                            modifier = Modifier.weight(1f),
                                        )
                                    }
                                    Spacer(Modifier.height(10.dp))
                                    Button(
                                        onClick = {
                                            val partner = creditPartnerId ?: return@Button
                                            busy = true
                                            scope.launch {
                                                when (val result = repository.creditOffline(partner, creditFrom, creditTo)) {
                                                    is ApiResult.Success -> {
                                                        message = result.value
                                                        showCreditForm = false
                                                        refresh()
                                                    }
                                                    is ApiResult.Failure -> error = result.message
                                                }
                                                busy = false
                                            }
                                        },
                                        enabled = !busy && creditPartnerId != null,
                                        modifier = Modifier.fillMaxWidth(),
                                    ) { Text("Credit these sales") }
                                    Text(
                                        "Each order is stamped when it is credited, so the same sale can " +
                                            "never be credited twice.",
                                        style = MaterialTheme.typography.bodySmall,
                                        modifier = Modifier.padding(top = 6.dp),
                                    )
                                }
                            }
                        }
                    }
                }

                item { SectionHeader("Each partner in detail") }
                item { PartnerBoxes(data.partnerDetail) }

                // ── Settle up ──────────────────────────────────────
                item { SectionHeader("Settle up") }

                if (data.settleInvest.isEmpty()) {
                    item {
                        Text(
                            "Everyone has contributed their share. Nothing to settle.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                } else {
                    item {
                        ListCard {
                            data.settleInvest.forEachIndexed { index, step ->
                                ListRow(
                                    title = "${step.from} → ${step.to}",
                                    subtitle = "closes the contribution gap",
                                    amount = Money.short(step.amount),
                                    leading = { IconTile(label = step.from, size = 42.dp) },
                                    trailing = {
                                        TextButton(
                                            onClick = {
                                                busy = true
                                                scope.launch {
                                                    val result = repository.settle(
                                                        step.fromId, step.toId, step.amount,
                                                        "Settled from the app",
                                                    )
                                                    when (result) {
                                                        is ApiResult.Success -> {
                                                            message = result.value
                                                            refresh()
                                                        }
                                                        is ApiResult.Failure -> error = result.message
                                                    }
                                                    busy = false
                                                }
                                            },
                                            enabled = !busy,
                                        ) { Text("Record") }
                                    },
                                    divider = index < data.settleInvest.lastIndex,
                                )
                            }
                        }
                    }
                    item {
                        Text(
                            "Recording a settlement moves the contribution figures only. Nobody's " +
                                "account balance changes, because the cash passes between partners " +
                                "rather than in or out of the business.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }

                item { SectionHeader("Account balance") }
                item {
                    AccountBalanceShare(
                        data = data,
                        busy = busy,
                        onSettle = { _, _, _ -> },
                    )
                }

                item { SectionHeader("Profit & distribution") }
                item {
                    ProfitAndDistribution(
                        data = data,
                        busy = busy,
                        onDistribute = { amount ->
                            busy = true
                            scope.launch {
                                when (val result = repository.distributeProfit(amount)) {
                                    is ApiResult.Success -> { message = result.value; refresh() }
                                    is ApiResult.Failure -> error = result.message
                                }
                                busy = false
                            }
                        },
                    )
                }

                // Directly under the profit card, because the question it
                // answers is always asked about the number just above it.
                item { RevenueWorking(data) }

                item { SectionHeader("Expenses by category") }
                item { ExpensesByCategory(data) }

                item { Spacer(Modifier.height(32.dp)) }
            }
        }
    }
}
