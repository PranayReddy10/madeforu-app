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
import com.madeforu.sales.data.Movement
import com.madeforu.sales.data.MovementTotals
import com.madeforu.sales.data.PartnerFinance
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.IconTile
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
    onOpenMovements: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var overview by remember { mutableStateOf<FinanceOverview?>(null) }
    var movements by remember { mutableStateOf<List<Movement>>(emptyList()) }
    var movementTotals by remember { mutableStateOf(MovementTotals()) }
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
            repository.movements().let {
                if (it is ApiResult.Success) {
                    movements = it.value.movements.take(12)
                    movementTotals = it.value.totals
                }
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
            item { ErrorBanner(error, onRetry = { refresh() }) }
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
                            shape = RoundedCornerShape(18.dp),
                            colors = CardDefaults.cardColors(
                                containerColor = MaterialTheme.colorScheme.tertiaryContainer,
                            ),
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
                    items(data.settleInvest.size) { index ->
                        val step = data.settleInvest[index]
                        Card(
                            shape = RoundedCornerShape(16.dp),
                            colors = softCardColors(),
                        ) {
                            Row(
                                Modifier.fillMaxWidth().padding(14.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        "${step.from} → ${step.to}",
                                        style = MaterialTheme.typography.titleMedium,
                                    )
                                    Text(
                                        "closes the contribution gap",
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text(
                                        Money.short(step.amount),
                                        style = MaterialTheme.typography.titleMedium,
                                        fontWeight = FontWeight.Bold,
                                    )
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
                                }
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

                // ── Recent ledger ──────────────────────────────────
                item {
                    SectionHeader(
                        "Recent movements",
                        action = {
                            TextButton(onClick = onOpenMovements) { Text("Open ledger") }
                        },
                    )
                }
                item {
                    // The same three figures movements.php heads its
                    // ledger with, for the whole set rather than the
                    // twelve rows shown.
                    Row(
                        Modifier.fillMaxWidth().padding(bottom = 4.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Text(
                            "In " + Money.short(movementTotals.credits) +
                                " · out " + Money.short(movementTotals.debits) +
                                " · across " + movementTotals.count + " movements",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Text(
                            (if (movementTotals.net >= 0) "+" else "-") +
                                Money.short(kotlin.math.abs(movementTotals.net)),
                            style = MaterialTheme.typography.bodyMedium,
                            color = if (movementTotals.net >= 0) positiveColor() else negativeColor(),
                        )
                    }
                }
                items(movements.size) { index ->
                    val movement = movements[index]
                    val credit = movement.direction == "credit"
                    Row(
                        Modifier.fillMaxWidth()
                            // A row opens for editing. The ones that cannot
                            // be edited say so when tapped rather than
                            // looking broken.
                            // Adding and correcting happens on the ledger
                            // page, where the whole set is visible and
                            // filterable; this strip is a summary.
                            .clickable { onOpenMovements() }
                            .padding(vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                movement.partner + " · " + movement.source,
                                style = MaterialTheme.typography.bodyMedium,
                            )
                            Text(
                                Dates.pretty(movement.date) +
                                    (movement.note?.takeIf { it.isNotBlank() }?.let { " · $it" } ?: ""),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        // A settle-up row carries amount 0 and an
                        // invest_adjust instead; showing "₹0" would look
                        // like a bug, so show what actually moved.
                        val shown = if (movement.amount > 0.001) movement.amount else movement.investAdjust
                        Text(
                            (if (credit) "+" else "-") + Money.short(kotlin.math.abs(shown)),
                            style = MaterialTheme.typography.titleMedium,
                            color = if (credit) positiveColor() else negativeColor(),
                        )
                    }
                }

                item { Spacer(Modifier.height(32.dp)) }
            }
        }
    }
}
