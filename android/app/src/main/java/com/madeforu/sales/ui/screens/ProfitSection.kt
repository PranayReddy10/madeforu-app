@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.ProfitAndLoss
import com.madeforu.sales.ui.components.HorizontalBars
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.BrandGradient
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor

/**
 * Profit and loss, laid out the way the question is actually asked.
 *
 * The old single "business profit" number was revenue minus every expense,
 * which reads as a catastrophic loss and is not what anyone means by "did
 * we make money". Two things were wrong with it as a trading figure:
 *
 *   Meesho was invisible — it only counted the orders table, so every
 *   marketplace sale was missing from revenue.
 *
 *   Buying stock and buying a machine were charged against the month they
 *   were bought. Stock on a shelf is still money you have, and a heat
 *   press prints for years; neither is a cost of this month's trading.
 *
 * So this shows the running-down: revenue, what the goods cost, what the
 * running costs were, and what is left. Then, separately and clearly
 * labelled, the money that went into stock and equipment — because that
 * is where the cash went, and a partner looking at an empty bank account
 * deserves to see it rather than have it buried in "profit".
 */
@Composable
fun ProfitSection(data: ProfitAndLoss) {
    var showDetail by remember { mutableStateOf(false) }
    val profitable = data.operatingProfit >= 0

    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {

        // ── The headline ───────────────────────────────────────────
        Card(
            shape = RoundedCornerShape(26.dp),
            colors = CardDefaults.cardColors(containerColor = Color.Transparent),
            elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
        ) {
            Column(
                Modifier
                    .background(Brush.linearGradient(BrandGradient))
                    .fillMaxWidth()
                    .padding(22.dp),
            ) {
                Text(
                    "OPERATING PROFIT",
                    style = MaterialTheme.typography.labelSmall,
                    color = Color.White.copy(alpha = 0.8f),
                )
                Spacer(Modifier.height(6.dp))
                Text(
                    Money.full(data.operatingProfit),
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                    color = Color.White,
                )
                Text(
                    if (profitable) {
                        "What trading earned after the goods and the running costs."
                    } else {
                        "Trading has not covered the running costs yet."
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.85f),
                )
            }
        }

        // ── The running-down ───────────────────────────────────────
        Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
            Column(Modifier.fillMaxWidth().padding(16.dp)) {
                Text("How it adds up", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(10.dp))

                Line("Sales, all channels", data.revenueTotal)
                Line("What those goods cost", -(data.direct.cogs + data.meesho.cost))
                if (data.meesho.ads > 0.005) Line("Meesho ads", -data.meesho.ads)
                ThinDivider(Modifier.padding(vertical = 8.dp))
                Line("Gross profit", data.grossTotal, bold = true)
                Line("Running costs", -data.operating.total)
                ThinDivider(Modifier.padding(vertical = 8.dp))
                Line("Operating profit", data.operatingProfit, bold = true, big = true)
            }
        }

        // ── Where the sales came from ──────────────────────────────
        Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
            Column(Modifier.fillMaxWidth().padding(16.dp)) {
                Text("Where the sales came from", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(12.dp))

                ChannelRow(
                    name = "Stalls, walk-ups & website",
                    subtitle = "${data.direct.orders} orders · " +
                        Money.short(data.direct.cogs) + " of goods",
                    revenue = data.direct.revenue,
                    profit = data.direct.gross,
                )
                if (data.meesho.available) {
                    Spacer(Modifier.height(10.dp))
                    ChannelRow(
                        name = "Meesho",
                        subtitle = "${data.meesho.orders} settled orders · " +
                            Money.short(data.meesho.ads) + " ads",
                        revenue = data.meesho.settlement,
                        profit = data.meesho.net,
                    )
                    if (data.meesho.costsMissing) {
                        Spacer(Modifier.height(8.dp))
                        Note(
                            "Meesho products have no unit cost set, so their profit above is " +
                                "only settlement less ads — it is flattering. Set the costs on " +
                                "the website's Meesho products page.",
                            warnColor(),
                        )
                    }
                }
                if (data.direct.outstanding > 0.5) {
                    Spacer(Modifier.height(8.dp))
                    Note(
                        Money.full(data.direct.outstanding) + " of that is billed but not yet " +
                            "collected.",
                        MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }

        // ── Not costs of trading ───────────────────────────────────
        if (data.stock.total > 0.5 || data.capital.total > 0.5) {
            Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
                Column(Modifier.fillMaxWidth().padding(16.dp)) {
                    Text("Money put into the business", style = MaterialTheme.typography.titleMedium)
                    Text(
                        "Spent, but not a cost of trading — which is why it is not in the profit " +
                            "above. Stock on the shelf is still money you have, and equipment " +
                            "keeps working for years.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(12.dp))
                    if (data.stock.total > 0.5) {
                        BucketRow("Stock bought", data.stock.total, warnColor())
                    }
                    if (data.capital.total > 0.5) {
                        Spacer(Modifier.height(8.dp))
                        BucketRow("Equipment bought", data.capital.total, MaterialTheme.colorScheme.primary)
                    }
                }
            }
        }

        // ── Cash ───────────────────────────────────────────────────
        Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
            Column(Modifier.fillMaxWidth().padding(16.dp)) {
                Text("Cash in and out", style = MaterialTheme.typography.titleMedium)
                Text(
                    "A different question from profit, and the one that explains the bank " +
                        "balance.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(10.dp))
                Line("Collected", data.cash.cashIn)
                Line("Spent, everything", -data.cash.cashOut)
                ThinDivider(Modifier.padding(vertical = 8.dp))
                Line("Net cash", data.cash.net, bold = true)
            }
        }

        // ── The detail, folded away ────────────────────────────────
        Card(
            shape = RoundedCornerShape(20.dp),
            colors = softCardColors(),
        ) {
            Column(Modifier.fillMaxWidth()) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable { showDetail = !showDetail }
                        .padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text("Every cost, by category", style = MaterialTheme.typography.titleMedium)
                        Text(
                            "And why this differs from the website's figure",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Icon(
                        if (showDetail) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                        contentDescription = if (showDetail) "Hide" else "Show",
                    )
                }

                AnimatedVisibility(visible = showDetail) {
                    Column(Modifier.padding(start = 16.dp, end = 16.dp, bottom = 16.dp)) {
                        if (data.operating.byCategory.isNotEmpty()) {
                            Text("Running costs", style = MaterialTheme.typography.titleSmall)
                            Spacer(Modifier.height(6.dp))
                            HorizontalBars(
                                entries = data.operating.byCategory.map {
                                    Triple(it.category, it.net, Money.compact(it.net))
                                },
                            )
                        }
                        if (data.stock.byCategory.isNotEmpty()) {
                            Spacer(Modifier.height(14.dp))
                            Text("Stock", style = MaterialTheme.typography.titleSmall)
                            Spacer(Modifier.height(6.dp))
                            HorizontalBars(
                                entries = data.stock.byCategory.map {
                                    Triple(it.category, it.net, Money.compact(it.net))
                                },
                            )
                        }
                        if (data.capital.byCategory.isNotEmpty()) {
                            Spacer(Modifier.height(14.dp))
                            Text("Equipment", style = MaterialTheme.typography.titleSmall)
                            Spacer(Modifier.height(6.dp))
                            HorizontalBars(
                                entries = data.capital.byCategory.map {
                                    Triple(it.category, it.net, Money.compact(it.net))
                                },
                            )
                        }

                        Spacer(Modifier.height(16.dp))
                        ThinDivider()
                        Spacer(Modifier.height(12.dp))
                        Text(
                            "Why the website says " + Money.full(data.distribution.profit),
                            style = MaterialTheme.typography.titleSmall,
                        )
                        Spacer(Modifier.height(6.dp))
                        Text(
                            "That figure is sales from the orders table less every expense, " +
                                "whatever it bought. It is the basis the website distributes " +
                                "profit on, so it is kept exactly as it is — but as a picture " +
                                "of trading it is misleading twice over: it leaves out " +
                                Money.full(data.meesho.settlement) + " of Meesho settlement " +
                                "entirely, and it charges " +
                                Money.full(data.stock.total + data.capital.total) +
                                " of stock and equipment against trading when both are still " +
                                "money the business holds.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Spacer(Modifier.height(10.dp))
                        Line("Distribution basis", data.distribution.profit)
                        Line("Already distributed", data.distribution.distributed)
                        Line("Left to distribute", data.distribution.remaining)
                    }
                }
            }
        }
    }
}

/** One line of the running-down. Negative renders with a minus and in red. */
@Composable
private fun Line(label: String, value: Double, bold: Boolean = false, big: Boolean = false) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            label,
            style = if (big) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium,
            color = if (bold) MaterialTheme.colorScheme.onSurface
            else MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.weight(1f),
        )
        Text(
            (if (value < 0) "−" else "") + Money.full(kotlin.math.abs(value)),
            style = if (big) MaterialTheme.typography.titleLarge else MaterialTheme.typography.bodyLarge,
            fontWeight = if (bold) FontWeight.Bold else FontWeight.Medium,
            color = when {
                !bold && value < 0 -> MaterialTheme.colorScheme.onSurfaceVariant
                value < 0 -> negativeColor()
                bold -> positiveColor()
                else -> MaterialTheme.colorScheme.onSurface
            },
        )
    }
}

@Composable
private fun ChannelRow(name: String, subtitle: String, revenue: Double, profit: Double) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        IconTile(label = name, size = 40.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(name, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
            Text(
                subtitle,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(Money.short(revenue), fontWeight = FontWeight.SemiBold)
            Text(
                Money.short(profit) + " profit",
                style = MaterialTheme.typography.bodySmall,
                color = if (profit >= 0) positiveColor() else negativeColor(),
            )
        }
    }
}

@Composable
private fun BucketRow(label: String, amount: Double, tint: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        IconTile(label = label, size = 40.dp, tint = tint)
        Spacer(Modifier.width(12.dp))
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Text(Money.full(amount), fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun Note(text: String, tint: Color) {
    Text(text, style = MaterialTheme.typography.bodySmall, color = tint)
}
