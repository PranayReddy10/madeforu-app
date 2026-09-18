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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Category
import androidx.compose.material.icons.filled.Event
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.FinanceOverview
import com.madeforu.sales.data.Movement
import com.madeforu.sales.data.PartnerFinance
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.KpiCard
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.ThinDivider
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
    onOpenExpenses: () -> Unit,
    onOpenEvents: () -> Unit,
    onOpenCatalog: () -> Unit,
    onOpenSettings: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var overview by remember { mutableStateOf<FinanceOverview?>(null) }
    var movements by remember { mutableStateOf<List<Movement>>(emptyList()) }
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
                if (it is ApiResult.Success) movements = it.value.movements.take(12)
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { refresh() }

    Column(Modifier.fillMaxSize()) {
        TopAppBar(title = { Text("Money") })

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
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        KpiCard(
                            "Put in by partners", Money.short(data.totals.paid),
                            caption = "${data.totals.partners} partners · ${data.totals.sharePct}% each",
                            modifier = Modifier.weight(1f),
                        )
                        KpiCard(
                            "In partner accounts", Money.short(data.totals.balance),
                            caption = "credited less drawn",
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

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

                // The full model, in the same words the website uses, so a
                // partner can check a figure they are being asked to pay
                // rather than taking it on trust.
                item { SectionHeader("How the split works") }
                item { EqualShareSection(data) }

                item { SectionHeader("Each partner") }

                items(data.partners.size) { index ->
                    PartnerCard(data.partners[index])
                }

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

                // ── Business profit ────────────────────────────────
                item { SectionHeader("Business profit") }
                item {
                    Card(
                        shape = RoundedCornerShape(18.dp),
                        colors = softCardColors(),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            DetailRow("Revenue, all time", Money.full(data.business.revenue))
                            DetailRow("Expenses, all time", Money.full(data.business.expenses))
                            ThinDivider(Modifier.padding(vertical = 8.dp))
                            DetailRow(
                                "Profit",
                                Money.full(data.business.profit),
                                valueColor = if (data.business.profit >= 0) positiveColor() else negativeColor(),
                                emphasise = true,
                            )
                            DetailRow("Already distributed", Money.full(data.business.distributed))
                            DetailRow(
                                "Left to distribute",
                                Money.full(data.business.remaining),
                                valueColor = if (data.business.remaining > 0) positiveColor() else null,
                            )
                            if (data.business.remaining <= 0) {
                                Text(
                                    "Nothing to distribute while expenses exceed revenue — the money " +
                                        "spent on machinery and stock has not been earned back yet.",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.padding(top = 6.dp),
                                )
                            }
                        }
                    }
                }

                // ── Recent ledger ──────────────────────────────────
                item { SectionHeader("Recent movements") }
                items(movements.size) { index ->
                    val movement = movements[index]
                    val credit = movement.direction == "credit"
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 6.dp),
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
                            (if (credit) "+" else "−") + Money.short(kotlin.math.abs(shown)),
                            style = MaterialTheme.typography.titleMedium,
                            color = if (credit) positiveColor() else negativeColor(),
                        )
                    }
                }

                // ── Shortcuts ──────────────────────────────────────
                item { SectionHeader("Manage") }
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        ManageButton("Expenses", Icons.Filled.Payments, onOpenExpenses)
                        ManageButton("Events & stalls", Icons.Filled.Event, onOpenEvents)
                        ManageButton("Products & prices", Icons.Filled.Category, onOpenCatalog)
                        ManageButton("Settings", Icons.Filled.Settings, onOpenSettings)
                    }
                }

                item { Spacer(Modifier.height(32.dp)) }
            }
        }
    }
}

@Composable
private fun PartnerCard(partner: PartnerFinance) {
    val owes = partner.gap < -0.5
    val owed = partner.gap > 0.5
    Card(
        shape = RoundedCornerShape(18.dp),
        colors = softCardColors(),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconTile(label = partner.name, size = 38.dp)
                Spacer(Modifier.width(12.dp))
                Text(
                    partner.name,
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )
                when {
                    owes -> Pill("Owes " + Money.short(-partner.gap), negativeColor())
                    owed -> Pill("Is owed " + Money.short(partner.gap), positiveColor())
                    else -> Pill("Square", MaterialTheme.colorScheme.primary)
                }
            }
            Spacer(Modifier.height(8.dp))
            DetailRow("Paid from own pocket", Money.full(partner.paid))
            DetailRow("Credited back", Money.full(partner.credited))
            if (partner.adj != 0.0) {
                DetailRow("Settlements", Money.full(partner.adj))
            }
            DetailRow("Net contribution", Money.full(partner.contribution), emphasise = true)
            DetailRow("Fair share", Money.full(partner.fairShare))
            DetailRow(
                "Account balance",
                Money.full(partner.balance),
                valueColor = if (partner.balance < -0.5) negativeColor() else null,
            )
        }
    }
}

@Composable
private fun ManageButton(
    label: String,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    onClick: () -> Unit,
) {
    OutlinedButton(onClick = onClick, modifier = Modifier.fillMaxWidth()) {
        Icon(icon, contentDescription = null)
        Spacer(Modifier.width(10.dp))
        Text(label, modifier = Modifier.weight(1f))
    }
}
