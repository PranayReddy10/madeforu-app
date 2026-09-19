@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Money
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.data.FinanceOverview
import com.madeforu.sales.data.PartnerFinance
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor

/**
 * The equal-share explanation, shown in full on the Money screen.
 *
 * Every number here comes from the API rather than being recomputed, so
 * the app, the website's investment page and this table can never quietly
 * disagree. The point of showing the working is that a partner should be
 * able to check a figure they are being asked to pay, without anyone
 * having to explain the model out loud.
 *
 * The three components always sum to the net, and each column sums to
 * zero across partners — which is the property that makes "overpaid" and
 * "underpaid" meaningful rather than arbitrary.
 */
@Composable
fun EqualShareSection(data: FinanceOverview) {
    var showWorking by remember { mutableStateOf(false) }
    val share = if (data.totals.sharePct > 0) "${data.totals.sharePct.toInt()}%" else "an equal share"

    Card(
        shape = RoundedCornerShape(20.dp),
        colors = softCardColors(),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Text("Equal share & settle-up", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(8.dp))
            Text(
                "Each partner carries $share of both sides.\n\n" +
                    "Investment gap compares what they paid from pocket against " +
                    Money.full(data.totals.fairPaid) + " (total paid " +
                    Money.full(data.totals.paid) + " ÷ ${data.totals.partners}).\n\n" +
                    "Credit gap compares the sale money credited to them against " +
                    Money.full(data.totals.fairCredited) + " (total credited " +
                    Money.full(data.totals.credited) + " ÷ ${data.totals.partners}) — a partner who " +
                    "has drawn less than their share is owed the difference, so it counts in " +
                    "their favour.\n\n" +
                    "Settle-up is cash already transferred between partners to even things out: " +
                    "it moves the payer up and the receiver down, so gaps actually close as you " +
                    "settle.\n\n" +
                    "The three add up to the Net, which the settle-up plan below uses.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(16.dp))
            EqualShareTable(data)

            Spacer(Modifier.height(12.dp))
            Text(
                "Each gap column sums to zero across partners, so overpaid and underpaid net " +
                    "out. To even up, an underpaid partner pays an overpaid one — record it " +
                    "with the settle-up plan below and these figures update.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(12.dp))
            Row(
                Modifier
                    .fillMaxWidth()
                    .clickable { showWorking = !showWorking }
                    .padding(vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    "How each net is worked out",
                    style = MaterialTheme.typography.titleSmall,
                    modifier = Modifier.weight(1f),
                )
                Icon(
                    if (showWorking) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                    contentDescription = if (showWorking) "Hide the working" else "Show the working",
                )
            }
            Text(
                "The same numbers, step by step. Nothing new — just the arithmetic.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.clickable { showWorking = !showWorking },
            )

            AnimatedVisibility(visible = showWorking) {
                Column(Modifier.padding(top = 12.dp)) {
                    data.partners.forEach { partner ->
                        PartnerWorking(partner, data)
                        Spacer(Modifier.height(12.dp))
                    }
                    Text(
                        "Fair shares: total paid " + Money.full(data.totals.paid) +
                            " ÷ ${data.totals.partners} = " + Money.full(data.totals.fairPaid) +
                            " each  ·  total credited " + Money.full(data.totals.credited) +
                            " ÷ ${data.totals.partners} = " + Money.full(data.totals.fairCredited) + " each.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

/**
 * The table scrolls sideways rather than wrapping. Seven money columns
 * cannot be read on a phone otherwise, and a wrapped cell makes a
 * spreadsheet unreadable in a way a swipe does not.
 */
@Composable
private fun EqualShareTable(data: FinanceOverview) {
    val scroll = rememberScrollState()
    Column(Modifier.fillMaxWidth().horizontalScroll(scroll)) {
        Row(Modifier.padding(bottom = 6.dp)) {
            HeaderCell("Partner", 116.dp)
            HeaderCell("Paid", 104.dp)
            HeaderCell("Investment gap", 124.dp)
            HeaderCell("Credited", 104.dp)
            HeaderCell("Credit gap", 116.dp)
            HeaderCell("Settle-up", 100.dp)
            HeaderCell("Net", 112.dp)
        }
        ThinDivider()
        data.partners.forEach { p ->
            Row(Modifier.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Cell(p.name, 116.dp, bold = true)
                Cell(Money.full(p.paid), 104.dp)
                SignedCell(p.invGap, 124.dp)
                Cell(Money.full(p.credited), 104.dp)
                SignedCell(p.credGap, 116.dp)
                // A dash reads better than ₹0.00 for "no settlement yet".
                if (p.adjGap == 0.0) Cell("—", 100.dp) else SignedCell(p.adjGap, 100.dp)
                SignedCell(p.gap, 112.dp, bold = true)
            }
            ThinDivider()
        }
        Row(Modifier.padding(top = 8.dp)) {
            Cell("Total", 116.dp, bold = true)
            Cell(Money.full(data.totals.paid), 104.dp, bold = true)
            Cell(Money.full(0.0), 124.dp)
            Cell(Money.full(data.totals.credited), 104.dp, bold = true)
            Cell(Money.full(0.0), 116.dp)
            Cell(Money.full(0.0), 100.dp)
            Cell(Money.full(0.0), 112.dp)
        }
    }
}

@Composable
private fun PartnerWorking(p: PartnerFinance, data: FinanceOverview) {
    val owed = p.gap > 0.5
    Column {
        Text(p.name, style = MaterialTheme.typography.titleSmall)
        Working(
            "Investment gap",
            Money.full(p.paid) + " − " + Money.full(data.totals.fairPaid) + " = " + signed(p.invGap),
        )
        Working(
            "Credit gap",
            Money.full(data.totals.fairCredited) + " − " + Money.full(p.credited) + " = " + signed(p.credGap),
        )
        if (p.adjGap != 0.0) {
            Working("Settle-up already recorded", signed(p.adjGap))
        }
        Working(
            "Net",
            signed(p.invGap) + " + " + signed(p.credGap) +
                (if (p.adjGap != 0.0) " + " + signed(p.adjGap) else "") +
                " = " + signed(p.gap) + (if (owed) "  — owed" else if (p.gap < -0.5) "  — owes" else "  — square"),
        )
    }
}

@Composable
private fun Working(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(
            "$label: ",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(value, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun HeaderCell(text: String, width: androidx.compose.ui.unit.Dp) {
    Text(
        text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.width(width),
        maxLines = 2,
    )
}

@Composable
private fun Cell(
    text: String,
    width: androidx.compose.ui.unit.Dp,
    bold: Boolean = false,
) {
    Text(
        text,
        style = MaterialTheme.typography.bodySmall,
        fontWeight = if (bold) FontWeight.SemiBold else FontWeight.Normal,
        modifier = Modifier.width(width),
        maxLines = 1,
    )
}

/** Money that carries a direction: green when it is owed, red when owed by. */
@Composable
private fun SignedCell(value: Double, width: androidx.compose.ui.unit.Dp, bold: Boolean = false) {
    Text(
        signed(value),
        style = MaterialTheme.typography.bodySmall,
        fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal,
        color = when {
            value > 0.5 -> positiveColor()
            value < -0.5 -> negativeColor()
            else -> MaterialTheme.colorScheme.onSurfaceVariant
        },
        modifier = Modifier.width(width),
        maxLines = 1,
    )
}

/** "+₹7,185.89" / "−₹39,351.11" — the sign is the whole point of the column. */
private fun signed(value: Double): String =
    if (value >= 0) "+" + Money.full(value) else "-" + Money.full(-value)
