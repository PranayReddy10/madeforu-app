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
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.FinanceOverview
import com.madeforu.sales.data.PartnerDetail
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.components.IconTile
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

            // Split, because "revenue" that only counts orders was
            // understating what the business took in by every Meesho
            // payout and every other channel that writes no order.
            StatLine("Sales through orders", Money.full(b.revenueOrders))
            StatLine("Other channels", Money.full(b.revenueOther))
            b.sources.bySource.forEach { line ->
                Row(
                    Modifier.fillMaxWidth().padding(start = 14.dp, top = 2.dp, bottom = 2.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text(
                        line.source + (if (line.count > 0) " · " + line.count else ""),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(
                        Money.full(line.amount),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            StatLine("Revenue (all sales)", Money.full(b.revenue), bold = true)
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

            Spacer(Modifier.height(8.dp))
            Text(
                "Money credited into partner accounts from offline sales (" +
                    Money.full(b.sources.alreadyCounted.offlineCredits) + ") and event sales (" +
                    Money.full(b.sources.alreadyCounted.eventCredits) + ") is order money moving " +
                    "into an account, not new money, so it is counted once — in the orders line.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/**
 * Why "Revenue (all sales)" is the number it is.
 *
 * Four figures in this app get called revenue, and comparing two screens
 * gives no clue which is which: the all-time billed total shown above,
 * what has actually been collected, the part credited into partner
 * accounts, and whatever range the Stats screen is set to. Rather than
 * answer that question one partner at a time, the screen does its own
 * arithmetic out loud — the lines that add up to the headline, then every
 * nearby figure it is deliberately not.
 */
@Composable
fun RevenueWorking(data: FinanceOverview) {
    val r = data.revenueBreakdown
    var expanded by remember { mutableStateOf(false) }

    // An older api/finance.php sends no revenue_breakdown, and the DTO
    // defaults it to zero orders. Returning early here is what made a
    // stale upload on the server look like an app that had not changed,
    // so it names the file that is behind instead of vanishing.
    if (r.orders == 0) {
        Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
            Column(Modifier.fillMaxWidth().padding(16.dp)) {
                Text("Where this number comes from", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(6.dp))
                Text(
                    "The working behind this figure needs a newer api/finance.php than the " +
                        "server has. Upload the api/ folder to sale.madeforu.co.in and it will " +
                        "appear here. Settings shows which parts are behind.",
                    style = MaterialTheme.typography.bodySmall,
                    color = warnColor(),
                )
            }
        }
        return
    }

    Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Row(
                Modifier.fillMaxWidth().clickable { expanded = !expanded },
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text("Where this number comes from", style = MaterialTheme.typography.titleMedium)
                    Text(
                        Money.full(r.total) + " across " + r.orders + " orders",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Icon(
                    if (expanded) Icons.Filled.ExpandLess else Icons.Filled.ExpandMore,
                    contentDescription = if (expanded) "Hide the working" else "Show the working",
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            AnimatedVisibility(expanded) {
                Column(Modifier.fillMaxWidth()) {
                    Spacer(Modifier.height(12.dp))
                    Text(
                        "Every order ever booked, at its billed total — not what has been " +
                            "collected, and not only this year." +
                            (if (r.firstOrder != null) " " + r.orders + " orders from " +
                                Dates.prettyShort(r.firstOrder) + " to " + Dates.prettyShort(r.lastOrder ?: r.firstOrder) + "." else ""),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(10.dp))

                    StatLine("Items, before adjustments", Money.full(r.subtotal))
                    StatLine("Less discounts given", "-" + Money.full(r.discount))
                    StatLine("Plus delivery and extras", "+" + Money.full(r.extra))
                    ThinDivider(Modifier.padding(vertical = 8.dp))
                    StatLine("Revenue (all sales)", Money.full(r.total), bold = true)

                    Spacer(Modifier.height(16.dp))
                    Text(
                        "Numbers this is often confused with",
                        style = MaterialTheme.typography.titleSmall,
                    )
                    Spacer(Modifier.height(6.dp))

                    WorkingLine(
                        "Collected so far",
                        if (r.outstanding > 0.5) Money.full(r.outstanding) + " still owed"
                        else "nothing outstanding",
                        Money.full(r.collected),
                    )
                    WorkingLine(
                        "Credited to partner accounts",
                        r.creditedOrders.toString() + " of " + r.orders + " orders · " +
                            Money.full(r.uncredited) + " across " + r.uncreditedOrders +
                            " orders is in nobody's account yet",
                        Money.full(r.credited),
                    )
                    WorkingLine(
                        "This financial year (" + r.yearLabel + ")",
                        r.thisYear.orders.toString() + " orders since April",
                        Money.full(r.thisYear.amount),
                    )
                    WorkingLine(
                        "This month",
                        r.thisMonth.orders.toString() + " orders — close to what Stats shows by default",
                        Money.full(r.thisMonth.amount),
                    )

                    Spacer(Modifier.height(12.dp))
                    Text(
                        "The Stats screen totals only the orders inside the range picked at the " +
                            "top of it, so its revenue is smaller than this one unless that range " +
                            "covers everything. Business profit above subtracts all expenses ever " +
                            "recorded (" + Money.full(data.business.expenses) + ") from all " +
                            "revenue, so a young business reads negative until the stock and " +
                            "equipment it has already paid for have been sold on.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

/** A label, the sentence that qualifies it, and the amount. */
@Composable
private fun WorkingLine(label: String, caption: String, value: String) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
            Text(
                caption,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Spacer(Modifier.width(10.dp))
        Text(value, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Bold)
    }
}

/**
 * One card per partner, with every figure that concerns them.
 *
 * Four sections, kept apart because they are the things most easily
 * confused with each other:
 *
 *   what they paid out of their own pocket, against an equal share;
 *   their quarter of what the business earned;
 *   what has actually reached their account, and what is left in it;
 *   what still has to move to even everyone up.
 *
 * A partner can be owed profit, have overpaid their share, and hold a
 * zero balance all at once — which is why these are never added together
 * into a single "what I'm owed" number.
 */
@Composable
fun PartnerBoxes(detail: PartnerDetail) {
    if (detail.partners.isEmpty()) return

    Column(Modifier.fillMaxWidth()) {
        Text(
            detail.count.toString() + " partners, " + sharePctText(detail.sharePct) +
                " each. Every total below divides " + detail.count +
                " ways — what was put in, and what was earned.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(10.dp))

        detail.partners.forEach { p ->
            Card(shape = RoundedCornerShape(20.dp), colors = softCardColors()) {
                Column(Modifier.fillMaxWidth().padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        IconTile(label = p.name, size = 40.dp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(p.name, style = MaterialTheme.typography.titleMedium)
                            Text(
                                when (p.position) {
                                    "even" -> "square with the others"
                                    "owed" -> "owed " + Money.full(p.investmentGap)
                                    else   -> "owes " + Money.full(-p.investmentGap)
                                },
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }

                    BoxHeading("Paid from own pocket")
                    StatLine("They paid", Money.full(p.paid))
                    StatLine("An equal share would be", Money.full(p.fairPaid))
                    StatLine(
                        "Investment gap", signedMoney(p.investmentGap), bold = true,
                        tint = if (p.investmentGap >= 0) positiveColor() else negativeColor(),
                    )

                    BoxHeading("Share of profit")
                    StatLine(
                        "Their " + sharePctText(detail.sharePct) + " of " + Money.full(detail.profit),
                        Money.full(p.profitShare),
                    )
                    StatLine("Already distributed", Money.full(p.profitDistributed))
                    StatLine(
                        "Still to come", Money.full(p.profitPending), bold = true,
                        tint = if (p.profitPending >= 0) positiveColor() else negativeColor(),
                    )

                    BoxHeading("Money in their account")
                    StatLine("Credited to them", Money.full(p.credited))
                    StatLine("Drawn out", Money.full(p.debited))
                    StatLine(
                        "Balance", Money.full(p.balance), bold = true,
                        tint = if (p.balance >= 0) positiveColor() else negativeColor(),
                    )

                    BoxHeading("Settlement")
                    if (kotlin.math.abs(p.settledAdjust) > 0.005) {
                        StatLine("Already settled", signedMoney(p.settledAdjust))
                    }
                    p.owes.forEach {
                        StatLine("pays " + (it.to ?: ""), Money.full(it.amount), tint = negativeColor())
                    }
                    p.owed.forEach {
                        StatLine("receives from " + (it.from ?: ""), Money.full(it.amount),
                                 tint = positiveColor())
                    }
                    if (p.owes.isEmpty() && p.owed.isEmpty() &&
                        kotlin.math.abs(p.settledAdjust) <= 0.005) {
                        Text(
                            "Nothing outstanding.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
        }

        Text(
            "Investment is money put in. Profit share is money earned. The account balance is " +
                "what has actually been taken in and not drawn out — three different things, " +
                "which is why they are listed separately.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/** "25%" — a share, so no leading sign. */
private fun sharePctText(pct: Double): String {
    val rounded = kotlin.math.round(pct * 10) / 10.0
    return (if (rounded == kotlin.math.floor(rounded)) rounded.toLong().toString()
            else rounded.toString()) + "%"
}

/** The small rose heading that separates the four parts of a partner box. */
@Composable
private fun BoxHeading(text: String) {
    Spacer(Modifier.height(12.dp))
    Text(
        text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        fontWeight = FontWeight.Bold,
        color = MaterialTheme.colorScheme.primary,
    )
    Spacer(Modifier.height(2.dp))
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

/**
 * "+₹20.72" / "-₹8.56" — the sign is the whole point of a gap column.
 *
 * The hyphen is the one Money.full() already uses for a negative, so a
 * gap and a negative amount beside it do not read as two different kinds
 * of number because of two different characters.
 */
private fun signedMoney(value: Double): String =
    if (value >= 0) "+" + Money.full(value) else "-" + Money.full(-value)
