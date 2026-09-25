@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.ChevronLeft
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.layout.layout
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.madeforu.sales.core.Money
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor

/**
 * A headline number with its label and, when there is one, how it compares
 * to the previous period. The comparison is omitted rather than shown as
 * 0% when there is no baseline — "+100% vs nothing" is not information.
 */
/**
 * The card treatment every screen uses: a real surface lifted off the
 * tinted background, generously rounded. Defined once so a change lands
 * everywhere rather than in fourteen copies of cardColors().
 */
@Composable
fun softCardColors() = CardDefaults.cardColors(
    containerColor = MaterialTheme.colorScheme.surface,
)

@Composable
fun KpiCard(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    change: Double? = null,
    caption: String? = null,
    accent: Color? = null,
    onClick: (() -> Unit)? = null,
) {
    Card(
        modifier = modifier.then(if (onClick != null) Modifier.clickable { onClick() } else Modifier),
        colors = softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
        shape = RoundedCornerShape(20.dp),
    ) {
        // The web app's stat card: 14 padding, an 11sp caps label, a
        // 22sp figure 3 below it, and an 11sp caption.
        Column(Modifier.padding(14.dp)) {
            Text(
                label.uppercase(),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
            )
            Spacer(Modifier.height(3.dp))
            Text(
                value,
                style = MaterialTheme.typography.headlineMedium,
                color = accent ?: MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            if (change != null) {
                Spacer(Modifier.height(4.dp))
                DeltaBadge(change)
            } else if (caption != null) {
                Spacer(Modifier.height(2.dp))
                Text(
                    caption,
                    style = MaterialTheme.typography.labelSmall.copy(letterSpacing = 0.sp),
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 2,
                )
            }
        }
    }
}

@Composable
fun DeltaBadge(change: Double) {
    val up = change >= 0
    val tint = if (up) positiveColor() else negativeColor()
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            if (up) Icons.Filled.ArrowUpward else Icons.Filled.ArrowDownward,
            contentDescription = if (up) "up" else "down",
            tint = tint,
            modifier = Modifier.size(14.dp),
        )
        Spacer(Modifier.width(2.dp))
        Text(
            Money.percent(change),
            style = MaterialTheme.typography.labelSmall,
            color = tint,
        )
    }
}

/** paid / partial / unpaid, in the colours used everywhere else for money. */
@Composable
fun PayStatusPill(status: String, modifier: Modifier = Modifier) {
    val (label, tint) = when (status) {
        "paid" -> "Paid" to positiveColor()
        "partial" -> "Part paid" to warnColor()
        else -> "Unpaid" to negativeColor()
    }
    Pill(label, tint, modifier)
}

@Composable
fun Pill(label: String, tint: Color, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier,
        shape = RoundedCornerShape(50),
        color = tint.copy(alpha = 0.14f),
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelSmall,
            color = tint,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
        )
    }
}

@Composable
fun SectionHeader(title: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null) {
    Row(
        modifier = modifier.fillMaxWidth().padding(top = 18.dp, bottom = 6.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, style = MaterialTheme.typography.titleMedium)
        action?.invoke()
    }
}

/** What a screen shows when there is genuinely nothing to show. */
@Composable
fun EmptyState(
    title: String,
    body: String,
    modifier: Modifier = Modifier,
    actionLabel: String? = null,
    onAction: (() -> Unit)? = null,
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        Spacer(Modifier.height(6.dp))
        Text(
            body,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        if (actionLabel != null && onAction != null) {
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onAction) { Text(actionLabel) }
        }
    }
}

/**
 * An inline error with a retry, rather than a toast. A partner who missed
 * a toast has no way to find out what went wrong; a banner stays until the
 * problem is dealt with.
 */
@Composable
fun ErrorBanner(message: String?, modifier: Modifier = Modifier, onRetry: (() -> Unit)? = null) {
    AnimatedVisibility(visible = message != null) {
        Surface(
            modifier = modifier.fillMaxWidth().padding(vertical = 4.dp),
            shape = RoundedCornerShape(14.dp),
            color = MaterialTheme.colorScheme.errorContainer,
        ) {
            Row(
                Modifier.padding(horizontal = 14.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    Icons.Filled.ErrorOutline,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onErrorContainer,
                    modifier = Modifier.size(18.dp),
                )
                Spacer(Modifier.width(10.dp))
                Text(
                    message.orEmpty(),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onErrorContainer,
                    modifier = Modifier.weight(1f),
                )
                if (onRetry != null) {
                    TextButton(onClick = onRetry) { Text("Retry") }
                }
            }
        }
    }
}

/**
 * The error banner as a list item that only exists when there is an error.
 *
 * `item { ErrorBanner(error) }` looks harmless but is not: an item that
 * renders nothing is still an item, and a LazyColumn using
 * Arrangement.spacedBy gives it the full gap regardless of its height.
 * Every screen doing that carried a band of empty space above its first
 * card, with nothing on screen to explain it.
 */
fun LazyListScope.errorBannerItem(message: String?, onRetry: (() -> Unit)? = null) {
    if (message == null) return
    item { ErrorBanner(message, onRetry = onRetry) }
}

@Composable
fun LoadingBox(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize().padding(48.dp), contentAlignment = Alignment.Center) {
        CircularProgressIndicator()
    }
}

/** A horizontal strip of single-choice filter chips. */
@Composable
fun <T> ChipRow(
    options: List<Pair<T, String>>,
    selected: T,
    onSelect: (T) -> Unit,
    modifier: Modifier = Modifier,
    contentPadding: PaddingValues = PaddingValues(horizontal = 16.dp),
) {
    LazyRow(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        contentPadding = contentPadding,
    ) {
        items(options.size) { index ->
            val (value, label) = options[index]
            AppChip(label, selected = value == selected, onClick = { onSelect(value) })
        }
    }
}

/**
 * The web app's chip: a white pill lifted off the page, filled rose when
 * picked. Material's FilterChip draws an outline and a tick instead,
 * which is what made the app's filter rows read heavier than the web's.
 */
@Composable
fun AppChip(label: String, selected: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        onClick = onClick,
        modifier = modifier,
        shape = RoundedCornerShape(50),
        color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
        contentColor = if (selected) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
        shadowElevation = if (selected) 0.dp else 1.dp,
    ) {
        Text(
            label,
            style = MaterialTheme.typography.labelMedium,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Medium,
            maxLines = 1,
            modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
        )
    }
}

/**
 * An order line, showing what it was sold at.
 *
 * `catalogueNow` is passed only when today's price differs, and saying
 * both is the point: a completed sale keeps its own price, so a total
 * that does not match the current price list is the system working
 * rather than a mistake to go hunting for.
 */
@Composable
fun SoldLine(
    label: String,
    soldAt: Double,
    catalogueNow: Double?,
    amount: String,
) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
            Text(
                "at " + Money.full(soldAt) + " each" +
                    (if (catalogueNow != null)
                        " · now " + Money.full(catalogueNow) + " in the catalogue" else ""),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Spacer(Modifier.width(12.dp))
        Text(amount, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
    }
}

/**
 * A Double as a plain editable string: "60" not "60.0", "60.5" kept.
 *
 * Was private to CatalogScreen; the movement sheet wants the same thing,
 * and a private declaration is invisible to the rest of its package, so
 * it lives here rather than being copied.
 */
fun numberText(value: Double): String =
    if (value == value.toLong().toDouble()) value.toLong().toString()
    else String.format(java.util.Locale.US, "%.2f", value)

/**
 * A label/value row — the workhorse of every detail screen.
 *
 * Sized as the web app's detailRow: a muted label, the figure bold on the
 * right. `emphasise` is its total line, where the label is a title too.
 */
@Composable
fun DetailRow(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    valueColor: Color? = null,
    emphasise: Boolean = false,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(vertical = 6.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            label,
            style = if (emphasise) MaterialTheme.typography.titleMedium else MaterialTheme.typography.bodyMedium,
            color = if (emphasise) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.weight(1f),
        )
        Spacer(Modifier.width(12.dp))
        Text(
            value,
            style = MaterialTheme.typography.bodyLarge,
            fontWeight = if (emphasise) FontWeight.Bold else FontWeight.SemiBold,
            color = valueColor ?: MaterialTheme.colorScheme.onSurface,
        )
    }
}

/**
 * The web app's list row: a tile, a title over a muted line, and an amount
 * on the right, with a hairline under every row but the last.
 *
 * Rows go inside a [ListCard], one card per list, rather than a card per
 * row. That is most of the difference between the two apps' lists: the
 * web shows a list as one surface, the app used to show a stack of boxes.
 */
@Composable
fun ListRow(
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    amount: String? = null,
    amountCaption: String? = null,
    amountColor: Color? = null,
    amountCaptionColor: Color? = null,
    leading: (@Composable () -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
    divider: Boolean = true,
    onClick: (() -> Unit)? = null,
) {
    Column(modifier.fillMaxWidth()) {
        Row(
            Modifier
                .fillMaxWidth()
                .then(if (onClick != null) Modifier.clickable { onClick() } else Modifier)
                .padding(vertical = 11.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (leading != null) {
                leading()
                Spacer(Modifier.width(12.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(
                    title,
                    style = MaterialTheme.typography.titleMedium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (!subtitle.isNullOrBlank()) {
                    Text(
                        subtitle,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
            if (amount != null) {
                Spacer(Modifier.width(10.dp))
                Column(horizontalAlignment = Alignment.End) {
                    Text(
                        amount,
                        style = MaterialTheme.typography.bodyLarge,
                        fontWeight = FontWeight.Bold,
                        color = amountColor ?: MaterialTheme.colorScheme.onSurface,
                        maxLines = 1,
                    )
                    if (amountCaption != null) {
                        Text(
                            amountCaption,
                            style = MaterialTheme.typography.bodySmall,
                            color = amountCaptionColor ?: MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 1,
                        )
                    }
                }
            }
            if (trailing != null) {
                Spacer(Modifier.width(6.dp))
                trailing()
            }
        }
        if (divider) ThinDivider()
    }
}

/** One card holding a whole list of [ListRow]s, as the web app draws a list. */
@Composable
fun ListCard(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp)) {
            content()
        }
    }
}

/**
 * One row's slice of a card that runs down a LazyColumn.
 *
 * A lazy list cannot put one Card around many items, so each item draws
 * its own piece: the first rounds its top corners, the last its bottom,
 * and every row but the last carries the hairline. Put together with no
 * gap between items (or `gap` set to the list's spacing), they read as
 * the web app's single list card.
 */
@Composable
fun ListSegment(index: Int, count: Int, gap: Dp = 0.dp, content: @Composable () -> Unit) {
    val first = index == 0
    val last = index == count - 1
    val radius = 20.dp
    Surface(
        // In a list spaced with Arrangement.spacedBy, every row after the
        // first reports itself `gap` shorter and draws `gap` higher, closing
        // the space the list leaves above it. The screen keeps its spacing
        // between cards; the rows of this one card still touch.
        modifier = Modifier
            .fillMaxWidth()
            .layout { measurable, constraints ->
                val placeable = measurable.measure(constraints)
                val pull = if (first) 0 else gap.roundToPx()
                layout(placeable.width, placeable.height - pull) { placeable.place(0, -pull) }
            },
        shape = RoundedCornerShape(
            topStart = if (first) radius else 0.dp,
            topEnd = if (first) radius else 0.dp,
            bottomStart = if (last) radius else 0.dp,
            bottomEnd = if (last) radius else 0.dp,
        ),
        color = MaterialTheme.colorScheme.surface,
    ) {
        Column(
            Modifier.fillMaxWidth().padding(
                start = 16.dp, end = 16.dp,
                top = if (first) 4.dp else 0.dp,
                bottom = if (last) 4.dp else 0.dp,
            ),
        ) {
            content()
            if (!last) ThinDivider()
        }
    }
}

/**
 * The web app's back button: a small white rounded square lifted off
 * the page, rather than a bare arrow floating in the bar.
 */
@Composable
fun BackButton(onClick: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        onClick = onClick,
        modifier = modifier.padding(start = 12.dp, end = 4.dp).size(38.dp),
        shape = RoundedCornerShape(12.dp),
        color = MaterialTheme.colorScheme.surface,
        shadowElevation = 1.dp,
    ) {
        Box(contentAlignment = Alignment.Center) {
            Icon(
                Icons.Filled.ChevronLeft,
                contentDescription = "Back",
                modifier = Modifier.size(24.dp),
            )
        }
    }
}

@Composable
fun ThinDivider(modifier: Modifier = Modifier) {
    Box(
        modifier
            .fillMaxWidth()
            .height(1.dp)
            .background(MaterialTheme.colorScheme.outline.copy(alpha = 0.18f)),
    )
}
