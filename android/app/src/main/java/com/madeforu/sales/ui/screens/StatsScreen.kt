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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Breakdown
import com.madeforu.sales.data.Dashboard
import com.madeforu.sales.data.Repository
import com.madeforu.sales.data.SeriesResponse
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.DonutChart
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.HorizontalBars
import com.madeforu.sales.ui.components.HourStrip
import com.madeforu.sales.ui.components.KpiCard
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.RevenueLineChart
import com.madeforu.sales.ui.components.SectionHeader
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * The numbers behind the dashboard: what sold, how it was paid for, where
 * it sold and when.
 */
@Composable
fun StatsScreen(repository: Repository, onSessionExpired: () -> Unit) {
    val scope = rememberCoroutineScope()

    var range by remember { mutableStateOf(RangePreset.MONTH) }
    var dashboard by remember { mutableStateOf<Dashboard?>(null) }
    var series by remember { mutableStateOf<SeriesResponse?>(null) }
    var breakdown by remember { mutableStateOf<Breakdown?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(range) {
        scope.launch {
            loading = true
            val from = range.from()
            val to = range.to()
            when (val result = repository.dashboard(from, to)) {
                is ApiResult.Success -> { dashboard = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            repository.series(from, to, range.bucket()).let {
                if (it is ApiResult.Success) series = it.value
            }
            repository.breakdown(from, to).let {
                if (it is ApiResult.Success) breakdown = it.value
            }
            loading = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        TopAppBar(
            title = { Text("Statistics") },
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = MaterialTheme.colorScheme.background,
            ),
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
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            errorBannerItem(error)

            if (data != null) {
                item {
                    Text(
                        "${Dates.pretty(data.range.from)} – ${Dates.pretty(data.range.to)}",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        KpiCard(
                            "Revenue", Money.short(data.headline.revenue),
                            change = data.change.revenue, modifier = Modifier.weight(1f),
                        )
                        KpiCard(
                            "Item cost", Money.short(data.headline.cogs),
                            caption = "what the goods cost us", modifier = Modifier.weight(1f),
                        )
                    }
                }
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        KpiCard(
                            "Product profit", Money.short(data.headline.productProfit),
                            caption = data.headline.marginPct?.let { "$it% margin" } ?: "revenue − item cost",
                            accent = positiveColor(), modifier = Modifier.weight(1f),
                        )
                        KpiCard(
                            "Discounts given", Money.short(data.headline.discount),
                            caption = "across ${data.headline.orders} orders",
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

                item { SectionHeader("Revenue") }
                item {
                    ChartCard {
                        RevenueLineChart(
                            values = series?.points?.map { it.revenue } ?: emptyList(),
                            labels = series?.points?.map { it.label } ?: emptyList(),
                        )
                    }
                }

                val parts = breakdown
                if (parts != null) {
                    if (parts.products.isNotEmpty()) {
                        item { SectionHeader("What sold") }
                        item {
                            ChartCard {
                                HorizontalBars(
                                    entries = parts.products.take(6).map {
                                        Triple("${it.item}  (${it.qty})", it.revenue, Money.compact(it.revenue))
                                    },
                                )
                                ThinDivider(Modifier.padding(vertical = 12.dp))
                                parts.products.take(6).forEach {
                                    DetailRow(
                                        it.item,
                                        Money.short(it.profit) + " profit" +
                                            (it.marginPct?.let { pct -> "  ·  $pct%" } ?: ""),
                                    )
                                }
                            }
                        }
                    }

                    if (parts.modes.isNotEmpty()) {
                        item { SectionHeader("How customers paid") }
                        item {
                            ChartCard {
                                DonutChart(
                                    slices = parts.modes.map { it.mode.uppercase() to it.amount },
                                    centreLabel = "collected",
                                    centreValue = Money.compact(parts.modes.sumOf { it.amount }),
                                )
                            }
                        }
                    }

                    if (parts.channels.isNotEmpty()) {
                        item { SectionHeader("Where it sold") }
                        item {
                            ChartCard {
                                HorizontalBars(
                                    entries = parts.channels.map {
                                        Triple("${it.channel}  (${it.orders})", it.revenue, Money.compact(it.revenue))
                                    },
                                )
                            }
                        }
                    }

                    item { SectionHeader("Busiest hours") }
                    item {
                        ChartCard {
                            HourStrip(parts.hours.map { it.orders })
                        }
                    }

                    if (parts.customers.isNotEmpty()) {
                        item { SectionHeader("Top customers") }
                        item {
                            ChartCard {
                                parts.customers.take(8).forEach {
                                    DetailRow(
                                        "${it.name}  ·  ${it.phone}",
                                        Money.short(it.spent) + "  (${it.orders})",
                                    )
                                }
                                Text(
                                    "Walk-in sales have no phone number, so they are not counted here.",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.padding(top = 8.dp),
                                )
                            }
                        }
                    }
                }

                item { SectionHeader("Whole business") }
                item {
                    ChartCard {
                        DetailRow("Revenue in this period", Money.full(data.headline.revenue))
                        DetailRow("Expenses in this period", Money.full(data.headline.expenses))
                        ThinDivider(Modifier.padding(vertical = 8.dp))
                        DetailRow(
                            "Revenue − expenses",
                            Money.full(data.headline.businessProfit),
                            valueColor = if (data.headline.businessProfit >= 0) positiveColor() else negativeColor(),
                            emphasise = true,
                        )
                        Text(
                            "This is the basis the website distributes to partners. It counts every " +
                                "expense in the period, including one-off purchases like machinery, so a " +
                                "month with a big purchase reads negative even when the stall did well. " +
                                "Product profit above is the day-to-day trading number.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.padding(top = 8.dp),
                        )
                    }
                }

                item { Spacer(Modifier.height(32.dp)) }
            }
        }
    }
}

@Composable
private fun ChartCard(content: @Composable () -> Unit) {
    Card(
        shape = RoundedCornerShape(20.dp),
        colors = softCardColors(),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) { content() }
    }
}
