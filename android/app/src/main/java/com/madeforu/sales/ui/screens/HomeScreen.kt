@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.foundation.background
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.material3.ButtonDefaults
import com.madeforu.sales.ui.theme.BrandGradient
import androidx.compose.material.icons.filled.Payments
import androidx.compose.material.icons.filled.Sell
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.ui.text.style.TextOverflow
import com.madeforu.sales.ui.components.IconTile
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.ServiceLocator
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Dashboard
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.SeriesResponse
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.KpiCard
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.RevenueLineChart
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.UpdateBanner
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/** The ranges the dashboard offers, and the API dates each one means. */
enum class RangePreset(val label: String) {
    TODAY("Today"),
    WEEK("7 days"),
    MONTH("This month"),
    QUARTER("90 days"),
    YEAR("This year"),
    ;

    fun from(): String = when (this) {
        TODAY -> Dates.today()
        WEEK -> Dates.daysAgo(6)
        MONTH -> Dates.startOfMonth()
        QUARTER -> Dates.daysAgo(89)
        YEAR -> Dates.today().take(4) + "-01-01"
    }

    fun to(): String = Dates.today()

    /** Days are too fine for a year and too coarse for a week. */
    fun bucket(): String = when (this) {
        TODAY, WEEK, MONTH -> "day"
        QUARTER -> "week"
        YEAR -> "month"
    }
}

@Composable
fun HomeScreen(
    repository: Repository,
    onOpenOrders: (String) -> Unit,
    onNewOrder: () -> Unit,
    onOpenSettings: () -> Unit,
    onOpenBills: () -> Unit,
    onOpenExpenses: () -> Unit,
    onOpenEvents: () -> Unit,
    onOpenCatalog: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val context = LocalContext.current
    val prefs = remember { ServiceLocator.prefs(context) }
    val scope = rememberCoroutineScope()

    var range by remember { mutableStateOf(RangePreset.MONTH) }
    var dashboard by remember { mutableStateOf<Dashboard?>(null) }
    var series by remember { mutableStateOf<SeriesResponse?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    var greetingName by remember { mutableStateOf("") }

    val load: () -> Unit = {
        scope.launch {
            loading = true
            error = null
            val from = range.from()
            val to = range.to()
            when (val result = repository.dashboard(from, to)) {
                is ApiResult.Success -> dashboard = result.value
                is ApiResult.Failure -> {
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
                }
            }
            when (val result = repository.series(from, to, range.bucket())) {
                is ApiResult.Success -> series = result.value
                is ApiResult.Failure -> Unit   // the headline still works without the chart
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { greetingName = prefs.adminName.first() }
    LaunchedEffect(range) { load() }

    Column(Modifier.fillMaxWidth()) {
        TopAppBar(
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.background,
            ),
            title = {
                Column {
                    Text(
                        greeting() + if (greetingName.isNotBlank()) ", ${greetingName.substringBefore(' ')}" else "",
                        style = MaterialTheme.typography.titleLarge,
                    )
                    Text(
                        Dates.pretty(Dates.today()),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            },
            actions = {
                IconButton(onClick = { load() }) {
                    Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                }
                IconButton(onClick = onOpenBills) {
                    Icon(Icons.Filled.ReceiptLong, contentDescription = "Bill book")
                }
                IconButton(onClick = onOpenSettings) {
                    Icon(Icons.Filled.Settings, contentDescription = "Settings")
                }
            },
        )

        ChipRow(
            options = RangePreset.entries.map { it to it.label },
            selected = range,
            onSelect = { range = it },
        )

        val data = dashboard
        if (loading && data == null) {
            LoadingBox()
            return@Column
        }

        LazyColumn(
            modifier = Modifier.fillMaxWidth(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item { ErrorBanner(error, onRetry = { load() }) }
            // Quiet unless a newer build has actually been published.
            item { UpdateBanner(repository) }

            if (data != null) {
                item { TodayCard(data, onNewOrder = onNewOrder) }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        KpiCard(
                            label = "Revenue",
                            value = Money.short(data.headline.revenue),
                            change = data.change.revenue,
                            modifier = Modifier.weight(1f),
                        )
                        KpiCard(
                            label = "Orders",
                            value = data.headline.orders.toString(),
                            change = data.change.orders,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        KpiCard(
                            label = "Collected",
                            value = Money.short(data.headline.collected),
                            caption = "of " + Money.short(data.headline.revenue) + " billed",
                            modifier = Modifier.weight(1f),
                            accent = positiveColor(),
                        )
                        KpiCard(
                            label = "Outstanding",
                            value = Money.short(data.headline.outstanding),
                            caption = if (data.headline.outstanding > 0.5) "still to collect" else "nothing pending",
                            modifier = Modifier.weight(1f),
                            accent = if (data.headline.outstanding > 0.5) negativeColor() else null,
                            onClick = { onOpenOrders("unpaid") },
                        )
                    }
                }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        KpiCard(
                            label = "Product profit",
                            value = Money.short(data.headline.productProfit),
                            caption = data.headline.marginPct?.let { "${Money.percent(it).removePrefix("+")} margin" }
                                ?: "after item costs",
                            modifier = Modifier.weight(1f),
                            accent = positiveColor(),
                        )
                        KpiCard(
                            label = "Avg order",
                            value = Money.short(data.headline.avgOrder),
                            change = data.change.avgOrder,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

                // The places a partner goes between sales. On Home because
                // that is the screen that opens; at the bottom of Money they
                // were behind every partner balance.
                item { SectionHeader("Manage") }
                item {
                    QuickActions(
                        onExpenses = onOpenExpenses,
                        onEvents = onOpenEvents,
                        onCatalog = onOpenCatalog,
                        onBills = onOpenBills,
                        onSettings = onOpenSettings,
                    )
                }

                item {
                    SectionHeader(
                        "Revenue trend",
                        action = {
                            Text(
                                "${Dates.prettyShort(data.range.from)} – ${Dates.prettyShort(data.range.to)}",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        },
                    )
                }

                item {
                    Card(
                        shape = RoundedCornerShape(20.dp),
                        colors = softCardColors(),
                    ) {
                        Column(Modifier.padding(16.dp)) {
                            RevenueLineChart(
                                values = series?.points?.map { it.revenue } ?: emptyList(),
                                labels = series?.points?.map { it.label } ?: emptyList(),
                            )
                        }
                    }
                }

                item { SectionHeader("Needs attention") }

                item {
                    WorkQueueCard(
                        label = "To make",
                        count = data.queues.toMake,
                        detail = "orders not marked ready",
                        tint = warnColor(),
                        onClick = { onOpenOrders("all") },
                    )
                }
                item {
                    WorkQueueCard(
                        label = "To hand over",
                        count = data.queues.toHandOver,
                        detail = "ready, waiting for the customer",
                        tint = MaterialTheme.colorScheme.primary,
                        onClick = { onOpenOrders("all") },
                    )
                }
                item {
                    WorkQueueCard(
                        label = "Money owed",
                        count = data.queues.owing,
                        detail = Money.full(data.queues.owedAmount) + " across unpaid orders",
                        tint = negativeColor(),
                        onClick = { onOpenOrders("unpaid") },
                    )
                }

                item { Spacer(Modifier.height(24.dp)) }
            }
        }
    }
}

/**
 * The day's takings, on the brand gradient.
 *
 * This is the first thing anyone opens the app to see, so it gets the
 * colour and the biggest number on the screen. White text throughout
 * because the gradient's mid-point is dark enough that theme colours
 * would wash out against it.
 */
@Composable
private fun TodayCard(data: Dashboard, onNewOrder: () -> Unit) {
    Card(
        shape = RoundedCornerShape(26.dp),
        colors = CardDefaults.cardColors(containerColor = Color.Transparent),
        elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
    ) {
        Column(
            Modifier
                .background(Brush.linearGradient(BrandGradient))
                .padding(22.dp),
        ) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "TODAY",
                    style = MaterialTheme.typography.labelSmall,
                    color = Color.White.copy(alpha = 0.8f),
                )
                Pill("${data.today.orders} orders", Color.White)
            }
            Spacer(Modifier.height(8.dp))
            Text(
                Money.full(data.today.revenue),
                style = MaterialTheme.typography.displaySmall,
                fontWeight = FontWeight.Bold,
                color = Color.White,
            )
            Text(
                Money.short(data.today.collected) + " collected · " +
                    Money.short(data.today.productProfit) + " profit",
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White.copy(alpha = 0.85f),
            )
            Spacer(Modifier.height(10.dp))
            TextButton(
                onClick = onNewOrder,
                colors = ButtonDefaults.textButtonColors(contentColor = Color.White),
            ) {
                Icon(Icons.Filled.Add, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("New sale", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun WorkQueueCard(
    label: String,
    count: Int,
    detail: String,
    tint: androidx.compose.ui.graphics.Color,
    onClick: () -> Unit,
) {
    Card(
        onClick = onClick,
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.4f),
        ),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(Modifier.weight(1f)) {
                Text(label, style = MaterialTheme.typography.titleMedium)
                Text(
                    detail,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Text(
                count.toString(),
                style = MaterialTheme.typography.headlineMedium,
                fontWeight = FontWeight.Bold,
                color = if (count == 0) MaterialTheme.colorScheme.onSurfaceVariant else tint,
            )
        }
    }
}

/** A small courtesy: the header should match the time of day. */
private fun greeting(): String {
    val hour = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)
    return when {
        hour < 12 -> "Good morning"
        hour < 17 -> "Good afternoon"
        else -> "Good evening"
    }
}

/**
 * The things a partner opens between sales: expenses, events, the price
 * list, the bill book and settings.
 *
 * These used to sit at the very bottom of the Money screen, which meant
 * scrolling past every partner balance to reach the price list. They
 * belong on the screen that opens first.
 *
 * Laid out as a grid of tiles rather than a stack of buttons: five full
 * width buttons would push the revenue chart off the screen, and these are
 * destinations rather than actions — a tile reads as a place to go.
 */
@Composable
private fun QuickActions(
    onExpenses: () -> Unit,
    onEvents: () -> Unit,
    onCatalog: () -> Unit,
    onBills: () -> Unit,
    onSettings: () -> Unit,
) {
    val actions = listOf(
        QuickAction("Expenses", Icons.Filled.Payments, onExpenses),
        QuickAction("Events & stalls", Icons.Filled.Storefront, onEvents),
        QuickAction("Products & prices", Icons.Filled.Sell, onCatalog),
        QuickAction("Bill book", Icons.Filled.ReceiptLong, onBills),
        QuickAction("Settings", Icons.Filled.Settings, onSettings),
    )

    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        // Two per row, and the odd one out takes the full width rather
        // than sitting next to a hole.
        actions.chunked(2).forEach { pair ->
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                pair.forEach { action ->
                    QuickActionTile(action, Modifier.weight(1f))
                }
                if (pair.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

private data class QuickAction(
    val label: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
    val onClick: () -> Unit,
)

@Composable
private fun QuickActionTile(action: QuickAction, modifier: Modifier = Modifier) {
    Card(
        onClick = action.onClick,
        modifier = modifier,
        shape = RoundedCornerShape(20.dp),
        colors = softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconTile(label = action.label, icon = action.icon, size = 38.dp)
            Spacer(Modifier.width(10.dp))
            Text(
                action.label,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.Medium,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}
