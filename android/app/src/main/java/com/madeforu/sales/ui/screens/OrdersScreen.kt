@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Inventory2
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.LocalShipping
import androidx.compose.material.icons.filled.PersonOff
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Order
import com.madeforu.sales.data.OrderSummary
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.BackButton
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.EmptyState
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.ListSegment
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.PayStatusPill
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import com.madeforu.sales.ui.theme.warnColor
import kotlinx.coroutines.FlowPreview
import kotlinx.coroutines.flow.debounce
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch

private const val PAGE_SIZE = 30

/**
 * The Orders tab, and also one event's orders: given `eventId`, `title`
 * and `onBack`, it is the same search, filters and rows limited to that
 * event, with a title bar and a way back instead of the New sale button.
 */
@OptIn(FlowPreview::class)
@Composable
fun OrdersScreen(
    repository: Repository,
    initialPayFilter: String,
    onOpenOrder: (Int) -> Unit,
    onNewOrder: () -> Unit,
    onSessionExpired: () -> Unit,
    eventId: String = "all",
    title: String? = null,
    onBack: (() -> Unit)? = null,
) {
    val scope = rememberCoroutineScope()

    var query by remember { mutableStateOf("") }
    var payFilter by remember { mutableStateOf(initialPayFilter) }
    var statusFilter by remember { mutableStateOf("all") }
    var orders by remember { mutableStateOf<List<Order>>(emptyList()) }
    var summary by remember { mutableStateOf(OrderSummary()) }
    var hasMore by remember { mutableStateOf(false) }
    var offset by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var loadingMore by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    /** @param append true when paging, false when the filters changed. */
    fun load(append: Boolean) {
        scope.launch {
            if (append) loadingMore = true else loading = true
            val nextOffset = if (append) offset else 0
            val result = repository.orders(
                query = query,
                payFilter = payFilter,
                statusFilter = statusFilter,
                event = eventId,
                limit = PAGE_SIZE,
                offset = nextOffset,
            )
            when (result) {
                is ApiResult.Success -> {
                    orders = if (append) orders + result.value.orders else result.value.orders
                    summary = result.value.summary
                    hasMore = result.value.hasMore
                    offset = nextOffset + result.value.orders.size
                    error = null
                }
                is ApiResult.Failure -> {
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
                }
            }
            loading = false
            loadingMore = false
        }
    }

    // Search as you type, but only once typing pauses: a request per
    // keystroke would put eight in flight for "Shravani" and show whichever
    // came back last.
    LaunchedEffect(Unit) {
        snapshotFlow { query }
            .debounce(350)
            .distinctUntilChanged()
            .collect { load(append = false) }
    }
    LaunchedEffect(payFilter, statusFilter) { load(append = false) }

    Scaffold(
        topBar = {
            if (onBack != null) {
                TopAppBar(
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = MaterialTheme.colorScheme.background,
                    ),
                    title = {
                        Text(title ?: "Orders", maxLines = 1, overflow = TextOverflow.Ellipsis)
                    },
                    navigationIcon = { BackButton(onClick = onBack) },
                )
            }
        },
        floatingActionButton = {
            // A new sale is taken from the tab, where it picks its own event.
            if (onBack == null) {
                ExtendedFloatingActionButton(
                    onClick = onNewOrder,
                    icon = { Icon(Icons.Filled.Add, contentDescription = null) },
                    text = { Text("New sale") },
                )
            }
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {

            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                placeholder = { Text("Order no, name, phone, item or AWB") },
                leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                trailingIcon = {
                    if (query.isNotEmpty()) {
                        IconButton(onClick = { query = "" }) {
                            Icon(Icons.Filled.Close, contentDescription = "Clear search")
                        }
                    }
                },
                singleLine = true,
                shape = RoundedCornerShape(14.dp),
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            )

            ChipRow(
                options = listOf(
                    "all" to "All payments",
                    "unpaid" to "Unpaid",
                    "partial" to "Part paid",
                    "paid" to "Paid",
                ),
                selected = payFilter,
                onSelect = { payFilter = it },
            )
            Spacer(Modifier.height(8.dp))
            ChipRow(
                options = listOf(
                    "all" to "Any status",
                    "pending" to "To make",
                    "ready" to "Ready",
                    "delivered" to "Delivered",
                    "dispatched" to "Shipped",
                ),
                selected = statusFilter,
                onSelect = { statusFilter = it },
            )

            Spacer(Modifier.height(10.dp))
            SummaryStrip(summary)

            ErrorBanner(error, Modifier.padding(horizontal = 16.dp), onRetry = { load(false) })

            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }

                orders.isEmpty() -> EmptyState(
                    title = "No orders here",
                    body = if (query.isNotBlank()) "Nothing matches \"$query\". Try a shorter search."
                    else if (onBack != null) "No orders at this event match these filters."
                    else "Nothing matches these filters yet.",
                    actionLabel = if (onBack == null) "Take a new order" else null,
                    onAction = if (onBack == null) onNewOrder else null,
                )

                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 96.dp),
                ) {
                    items(orders.size) { index ->
                        ListSegment(index, orders.size) {
                            OrderRow(orders[index], onClick = { onOpenOrder(orders[index].id) })
                        }
                    }
                    if (hasMore) {
                        item {
                            Box(
                                Modifier.fillMaxWidth().padding(16.dp),
                                contentAlignment = Alignment.Center,
                            ) {
                                if (loadingMore) {
                                    CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp)
                                } else {
                                    // A button rather than infinite scroll:
                                    // the list is usually short, and an
                                    // explicit tap does not fire requests
                                    // while a thumb is still moving.
                                    androidx.compose.material3.TextButton(onClick = { load(append = true) }) {
                                        Text("Load older orders")
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SummaryStrip(summary: OrderSummary) {
    Surface(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
        shape = RoundedCornerShape(20.dp),
        color = MaterialTheme.colorScheme.surface,
        shadowElevation = 1.dp,
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            StripStat("Orders", summary.count.toString())
            StripStat("Billed", Money.compact(summary.total))
            StripStat("Collected", Money.compact(summary.paid), positiveColor())
            StripStat(
                "Balance",
                Money.compact(summary.balance),
                if (summary.balance > 0.5) negativeColor() else null,
            )
        }
    }
}

@Composable
private fun StripStat(label: String, value: String, tint: androidx.compose.ui.graphics.Color? = null) {
    Column(horizontalAlignment = Alignment.Start) {
        Text(
            label.uppercase(),
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            value,
            style = MaterialTheme.typography.titleMedium.copy(fontSize = 17.sp),
            fontWeight = FontWeight.Bold,
            color = tint ?: MaterialTheme.colorScheme.onSurface,
        )
    }
}

/**
 * An order as a row of the list card, laid out as the web app's orderRow:
 * a tile carrying the making state, who it is for, what it is, and the
 * total with what is still due under it.
 *
 * It draws no card of its own. The list around it does (ListSegment),
 * so a screen of orders is one surface rather than a stack of boxes.
 */
@Composable
internal fun OrderRow(order: Order, onClick: () -> Unit) {
    val state = when {
        order.isDelivered -> "Delivered"
        order.isReady -> "Ready"
        else -> "To make"
    }
    val (payLabel, payTint) = when (order.payStatus) {
        "paid" -> "Paid" to positiveColor()
        "partial" -> "Part paid" to warnColor()
        else -> "Unpaid" to negativeColor()
    }
    val items = splitItems(order.itemsText)
    Row(
        Modifier
            .fillMaxWidth()
            .clickable { onClick() }
            .padding(vertical = 11.dp),
        verticalAlignment = Alignment.Top,
    ) {
        // The tile carries the state, so a glance down the list reads as
        // work-to-do rather than a wall of text.
        IconTile(
            label = if (order.isWalkIn) "Walk in" else order.name,
            icon = when {
                order.isDelivered -> Icons.Filled.CheckCircle
                order.isReady -> Icons.Filled.Inventory2
                else -> Icons.Filled.Schedule
            },
            tint = when {
                order.isDelivered -> positiveColor()
                order.isReady -> MaterialTheme.colorScheme.primary
                else -> warnColor()
            },
            size = 42.dp,
        )
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (order.isWalkIn) {
                    Icon(
                        Icons.Filled.PersonOff,
                        contentDescription = "Walk-in sale",
                        modifier = Modifier.size(14.dp),
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.width(4.dp))
                }
                Text(
                    if (order.isWalkIn) "Walk-in" else order.name,
                    style = MaterialTheme.typography.titleMedium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (order.awb != null) {
                    Spacer(Modifier.width(5.dp))
                    Icon(
                        Icons.Filled.LocalShipping,
                        contentDescription = "Shipped",
                        modifier = Modifier.size(14.dp),
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            if (!order.isWalkIn && order.phone.isNotBlank()) {
                Text(
                    order.phone,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                )
            }
            Text(
                order.orderNo + " · " + Dates.relativeDay(order.createdAt) + " · " + state +
                    (order.eventName?.let { " · $it" } ?: ""),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            // One item per line, two at most; the rest are counted.
            if (items.isNotEmpty()) Spacer(Modifier.height(3.dp))
            items.take(2).forEach { line ->
                Text(
                    "• $line",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurface,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            if (items.size > 2) {
                val more = items.size - 2
                Text(
                    "+$more more item" + if (more == 1) "" else "s",
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.primary,
                )
            }
        }
        Spacer(Modifier.width(10.dp))
        Column(horizontalAlignment = Alignment.End) {
            Text(
                Money.short(order.total),
                style = MaterialTheme.typography.bodyLarge,
                fontWeight = FontWeight.Bold,
            )
            Text(
                if (order.balance > 0.5) Money.short(order.balance) + " due" else payLabel,
                style = MaterialTheme.typography.bodySmall,
                color = payTint,
            )
        }
    }
}

/**
 * "Round Magnet x2, Keychain x1" -> ["Round Magnet x2", "Keychain x1"].
 *
 * The server joins an order's lines with ", ", and a product name may
 * carry a comma of its own, so a split only counts where it follows a
 * quantity. The web app's splitItems() does the same.
 */
internal fun splitItems(text: String?): List<String> {
    if (text.isNullOrBlank()) return emptyList()
    val found = Regex(".*? x\\d+(?=, |$)").findAll(text).map { it.value.removePrefix(", ") }.toList()
    return found.ifEmpty { listOf(text) }
}
