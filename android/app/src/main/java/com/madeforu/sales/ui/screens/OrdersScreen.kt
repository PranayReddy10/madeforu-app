@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

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
import androidx.compose.material.icons.filled.Close
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
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Order
import com.madeforu.sales.data.OrderSummary
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.EmptyState
import com.madeforu.sales.ui.components.ErrorBanner
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

@OptIn(FlowPreview::class)
@Composable
fun OrdersScreen(
    repository: Repository,
    initialPayFilter: String,
    onOpenOrder: (Int) -> Unit,
    onNewOrder: () -> Unit,
    onSessionExpired: () -> Unit,
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
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = onNewOrder,
                icon = { Icon(Icons.Filled.Add, contentDescription = null) },
                text = { Text("New sale") },
            )
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
                shape = RoundedCornerShape(16.dp),
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
                    else "Nothing matches these filters yet.",
                    actionLabel = "Take a new order",
                    onAction = onNewOrder,
                )

                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 96.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(orders.size) { index ->
                        OrderRow(orders[index], onClick = { onOpenOrder(orders[index].id) })
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
        shape = RoundedCornerShape(14.dp),
        color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.4f),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp),
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
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
            color = tint ?: MaterialTheme.colorScheme.onSurface,
        )
    }
}

@Composable
private fun OrderRow(order: Order, onClick: () -> Unit) {
    Card(
        onClick = onClick,
        shape = RoundedCornerShape(18.dp),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.32f),
        ),
    ) {
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        if (order.isWalkIn) {
                            Icon(
                                Icons.Filled.PersonOff,
                                contentDescription = "Walk-in sale",
                                modifier = Modifier.size(15.dp),
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            Spacer(Modifier.width(5.dp))
                        }
                        Text(
                            if (order.isWalkIn) "Walk-in" else order.name,
                            style = MaterialTheme.typography.titleMedium,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    Text(
                        order.orderNo + " · " + Dates.relativeDay(order.createdAt),
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(
                        Money.short(order.total),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                    )
                    if (order.balance > 0.5) {
                        Text(
                            Money.short(order.balance) + " due",
                            style = MaterialTheme.typography.bodySmall,
                            color = negativeColor(),
                        )
                    }
                }
            }

            if (!order.itemsText.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(
                    order.itemsText,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }

            Spacer(Modifier.height(8.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                PayStatusPill(order.payStatus)
                when {
                    order.isDelivered -> Pill("Delivered", positiveColor())
                    order.isReady -> Pill("Ready", MaterialTheme.colorScheme.primary)
                    else -> Pill("To make", warnColor())
                }
                if (order.awb != null) {
                    Icon(
                        Icons.Filled.LocalShipping,
                        contentDescription = "Shipped",
                        modifier = Modifier.size(15.dp),
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                order.eventName?.let {
                    Text(
                        it,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
    }
}
