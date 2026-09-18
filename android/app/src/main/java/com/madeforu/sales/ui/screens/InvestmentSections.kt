@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ExpandLess
import androidx.compose.material.icons.filled.ExpandMore
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.FinanceOverview
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.BrandGradient
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor

/**
 * The Money screen is the phone's version of the website's investment
 * summary, section for section, with the same definitions:
 *
 *   Remaining        = Paid − Credited        (put in, not yet come back)
 *   Net invested     = Remaining + settle-up transfers
 *   Account balance  = Credited − Debited
 *   Business profit  = all sales revenue − all expenses
 *
 * Every figure comes from the API, which computes them the way
 * investment.php does. Nothing is recalculated here, so the two cannot
 * drift apart and a partner can hold the phone next to the laptop and see
 * the same numbers.
 */

/** The four headline figures the website leads with. */
@Composable
fun InvestmentHeadline(data: FinanceOverview) {
    val t = data.totals
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
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
                    "TOTAL NET INVESTED",
                    style = MaterialTheme.typography.labelSmall,
                    color = Color.White.copy(alpha = 0.8f),
                )
                Spacer(Modifier.height(6.dp))
                Text(
                    Money.full(t.contribution),
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                    color = Color.White,
                )
                Text(
                    "across ${t.partners} partners · ${t.sharePct.toInt()}% share each",
                    style = MaterialTheme.typography.bodySmall,
                    color = Color.White.copy(alpha = 0.85f),
                )
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            MiniStat("Paid from pocket", Money.short(t.paid), Modifier.weight(1f))
            MiniStat(
                "Remaining",
                Money.short(t.remaining),
                Modifier.weight(1f),
                caption = Money.short(t.paid) + " − " + Money.short(t.credited),
            )
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            MiniStat("Credited back", Money.short(t.credited), Modifier.weight(1f))
            MiniStat(
                "In accounts",
                Money.short(t.balance),
                Modifier.weight(1f),
                tint = if (t.balance >= 0) positiveColor() else negativeColor(),
            )
        }
    }
}

/**
 * The per-partner table. Seven money columns cannot wrap on a phone, so it
 * scrolls sideways — a swipe keeps the rows readable in a way a wrapped
 * cell does not.
 */
@Composable
fun PartnerBreakdown(data: FinanceOverview) {
    val scroll = rememberScrollState()
    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Text("Per-partner breakdown", style = MaterialTheme.typography.titleMedium)
            Text(
                "Remaining = Paid − Credited (money put in that has not come back yet).  " +
                    "Net invested = Remaining + settle-up adjustments.  " +
                    "Account balance = Credited − Debited.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))

            Column(Modifier.fillMaxWidth().horizontalScroll(scroll)) {
                Row(Modifier.padding(bottom = 6.dp)) {
                    Head("Partner", 116.dp)
                    Head("Paid", 104.dp)
                    Head("Credited", 104.dp)
                    Head("Debited", 100.dp)
                    Head("Remaining", 108.dp)
                    Head("Net invested", 112.dp)
                    Head("Balance", 100.dp)
                }
                ThinDivider()
                data.partners.forEach { p ->
                    Row(Modifier.padding(vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
                        Body(p.name, 116.dp, bold = true)
                        Body(Money.full(p.paid), 104.dp)
                        Body(Money.full(p.credited), 104.dp)
                        Body(Money.full(p.debited), 100.dp)
                        Body(Money.full(p.remaining), 108.dp)
                        Body(Money.full(p.contribution), 112.dp, bold = true)
                        Body(
                            Money.full(p.balance), 100.dp,
                            tint = if (p.balance >= 0) positiveColor() else negativeColor(),
                        )
                    }
                    ThinDivider()
                }
                Row(Modifier.padding(top = 9.dp)) {
                    Body("Total", 116.dp, bold = true)
                    Body(Money.full(data.totals.paid), 104.dp, bold = true)
                    Body(Money.full(data.totals.credited), 104.dp, bold = true)
                    Body(Money.full(data.totals.debited), 100.dp, bold = true)
                    Body(Money.full(data.totals.remaining), 108.dp, bold = true)
                    Body(Money.full(data.totals.contribution), 112.dp, bold = true)
                    Body(
                        Money.full(data.totals.balance), 100.dp, bold = true,
                        tint = if (data.totals.balance >= 0) positiveColor() else negativeColor(),
                    )
                }
            }
        }
    }
}

/**
 * Account balance, split equally — the website's fourth card.
 * This is a different question from investment: not "who put in what" but
 * "who is holding the business's money right now".
 */
@Composable
fun AccountBalanceShare(data: FinanceOverview, onSettle: (Int, Int, Double) -> Unit, busy: Boolean) {
    val t = data.totals
    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Text("Account balance — equal share", style = MaterialTheme.typography.titleMedium)
            Text(
                "Business money currently in partner accounts totals " + Money.full(t.balance) +
                    ". Split ${t.sharePct.toInt()}% each, every partner's share is " +
                    Money.full(t.fairBalance) + ".",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))

            data.partners.forEach { p ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 5.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(p.name, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                    Text(
                        Money.full(p.balance),
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.width(96.dp),
                    )
                    Text(
                        signedMoney(p.balanceGap),
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = when {
                            p.balanceGap > 0.5 -> positiveColor()
                            p.balanceGap < -0.5 -> negativeColor()
                            else -> MaterialTheme.colorScheme.onSurfaceVariant
                        },
                        modifier = Modifier.width(100.dp),
                    )
                }
            }

            if (data.settleBalance.isNotEmpty()) {
                Spacer(Modifier.height(10.dp))
                ThinDivider()
                Spacer(Modifier.height(10.dp))
                Text("To even the accounts up", style = MaterialTheme.typography.titleSmall)
                Text(
                    "Here a partner holding more than their share pays the one holding less.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                data.settleBalance.forEach { step ->
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            "${step.from} → ${step.to}",
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.weight(1f),
                        )
                        Text(Money.full(step.amount), fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
    }
}

/**
 * Profit and distribution, exactly as the website defines it:
 * all sales revenue less all expenses. That includes stock and equipment,
 * which is why it can read as a large loss while trading is fine — it is
 * a "what is there to share out" figure, not a trading result.
 */
@Composable
fun ProfitAndDistribution(
    data: FinanceOverview,
    busy: Boolean,
    onDistribute: (Double) -> Unit,
) {
    val b = data.business
    val partners = data.totals.partners.coerceAtLeast(1)
    var amountText by remember { mutableStateOf("") }

    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Text("Profit & distribution", style = MaterialTheme.typography.titleMedium)
            Text(
                "Business profit = all sales revenue − all expenses. Distributing credits each " +
                    "investing partner an equal share to their account.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))

            StatLine("Revenue (all sales)", Money.full(b.revenue))
            StatLine("Expenses", Money.full(b.expenses))
            ThinDivider(Modifier.padding(vertical = 8.dp))
            StatLine(
                "Business profit",
                Money.full(b.profit),
                tint = if (b.profit >= 0) positiveColor() else negativeColor(),
                bold = true,
            )
            StatLine("Already distributed", Money.full(b.distributed))
            StatLine(
                "Undistributed",
                Money.full(b.remaining),
                tint = if (b.remaining > 0.5) warnColor() else MaterialTheme.colorScheme.onSurfaceVariant,
            )

            if (b.remaining > 0.5) {
                Spacer(Modifier.height(12.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(
                        value = amountText,
                        onValueChange = { amountText = it.filter { c -> c.isDigit() || c == '.' } },
                        label = { Text("Amount to distribute") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.width(8.dp))
                    Button(
                        onClick = { onDistribute(amountText.toDoubleOrNull() ?: 0.0) },
                        enabled = !busy && (amountText.toDoubleOrNull() ?: 0.0) > 0,
                    ) { Text("Distribute") }
                }
                Text(
                    "Each partner gets " + Money.full(b.remaining / partners) + " at the full amount.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                Spacer(Modifier.height(8.dp))
                Text(
                    "Nothing to distribute yet. This figure counts every expense, including " +
                        "stock and equipment, so it stays negative until those purchases have " +
                        "been earned back.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/** Expenses by category, with the website's share bars. */
@Composable
fun ExpensesByCategory(data: FinanceOverview) {
    var expanded by remember { mutableStateOf(false) }
    val total = data.categoryTotal
    val shown = if (expanded) data.categories else data.categories.take(4)

    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Text("Expenses by category", style = MaterialTheme.typography.titleMedium)
            Text(
                "Pocket-funded purchases only — total " + Money.full(total) + ".",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))

            shown.forEachIndexed { index, c ->
                val share = if (total > 0.001) (c.net / total).toFloat() else 0f
                Column(Modifier.padding(vertical = 6.dp)) {
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            c.category,
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.weight(1f),
                        )
                        Text(
                            "${c.count}×",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Spacer(Modifier.width(10.dp))
                        Text(Money.full(c.net), fontWeight = FontWeight.SemiBold)
                    }
                    Spacer(Modifier.height(5.dp))
                    Box(
                        Modifier
                            .fillMaxWidth()
                            .height(8.dp)
                            .clip(CircleShape)
                            .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.7f)),
                    ) {
                        Box(
                            Modifier
                                .fillMaxWidth(share)
                                .height(8.dp)
                                .clip(CircleShape)
                                .background(
                                    Brush.horizontalGradient(
                                        listOf(
                                            com.madeforu.sales.ui.components.tileColour(c.category)
                                                .copy(alpha = 0.75f),
                                            com.madeforu.sales.ui.components.tileColour(c.category),
                                        ),
                                    ),
                                ),
                        )
                    }
                    Text(
                        String.format(java.util.Locale.US, "%.1f%% of spending", share * 100),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            if (data.categories.size > 4) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clickable { expanded = !expanded }
                        .padding(top = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        if (expanded) "Show fewer" else "Show all ${data.categories.size} categories",
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
        }
    }
}

// ── Small shared pieces ────────────────────────────────────────────

@Composable
private fun MiniStat(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    caption: String? = null,
    tint: Color? = null,
) {
    Card(modifier, shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.padding(14.dp)) {
            Text(
                label.uppercase(),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
            )
            Spacer(Modifier.height(4.dp))
            Text(
                value,
                style = MaterialTheme.typography.titleLarge,
                fontWeight = FontWeight.Bold,
                color = tint ?: MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
            )
            if (caption != null) {
                Text(
                    caption,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                )
            }
        }
    }
}

@Composable
private fun StatLine(label: String, value: String, tint: Color? = null, bold: Boolean = false) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            value,
            style = if (bold) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyLarge,
            fontWeight = if (bold) FontWeight.Bold else FontWeight.Medium,
            color = tint ?: MaterialTheme.colorScheme.onSurface,
        )
    }
}

@Composable
private fun Head(text: String, width: Dp) {
    Text(
        text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.width(width),
        maxLines = 2,
    )
}

@Composable
private fun Body(text: String, width: Dp, bold: Boolean = false, tint: Color? = null) {
    Text(
        text,
        style = MaterialTheme.typography.bodySmall,
        fontWeight = if (bold) FontWeight.SemiBold else FontWeight.Normal,
        color = tint ?: MaterialTheme.colorScheme.onSurface,
        modifier = Modifier.width(width),
        maxLines = 1,
    )
}

/** "+₹20.72" / "−₹8.56" — the sign is the whole point of a gap column. */
private fun signedMoney(value: Double): String =
    if (value >= 0) "+" + Money.full(value) else "−" + Money.full(-value)
