@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Storefront
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Event
import com.madeforu.sales.data.Partner
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.BackButton
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.EmptyState
import com.madeforu.sales.ui.components.IconTile
import com.madeforu.sales.ui.components.ListCard
import com.madeforu.sales.ui.components.ListRow
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.theme.BrandGradient
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * Events and stalls: what each one took, at a glance.
 *
 * Laid out like the rest of the app: a hero with the totals, a filter,
 * and one list card. Tapping an event opens its orders on their own page,
 * which is the Orders screen limited to that event, so searching and
 * filtering them works exactly as it does on the Orders tab. Crediting
 * and closing an event sit in each row's menu, out of the way of the
 * thing people open this page for.
 */
@Composable
fun EventsScreen(
    repository: Repository,
    onBack: () -> Unit,
    onOpenEvent: (Event) -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()

    var events by remember { mutableStateOf<List<Event>>(emptyList()) }
    var partners by remember { mutableStateOf<List<Partner>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var showAdd by remember { mutableStateOf(false) }
    var creditingEvent by remember { mutableStateOf<Event?>(null) }
    var filter by remember { mutableStateOf("all") }
    var query by remember { mutableStateOf("") }

    fun load() {
        scope.launch {
            when (val result = repository.events()) {
                is ApiResult.Success -> { events = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    fun setActive(event: Event) {
        busy = true
        scope.launch {
            when (val result = repository.setEventActive(event.id, !event.isActive)) {
                is ApiResult.Success -> events = result.value
                is ApiResult.Failure -> error = result.message
            }
            busy = false
        }
    }

    LaunchedEffect(Unit) {
        load()
        repository.bootstrap().let {
            if (it is ApiResult.Success) partners = it.value.partners.filter { p -> p.isActive }
        }
    }

    val shown = events
        .filter {
            when (filter) {
                "open" -> it.isActive
                "closed" -> !it.isActive
                else -> true
            }
        }
        .filter { query.isBlank() || it.name.contains(query.trim(), ignoreCase = true) }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Events & stalls") },
                navigationIcon = { BackButton(onClick = onBack) },
            )
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = { showAdd = true },
                icon = { Icon(Icons.Filled.Add, contentDescription = null) },
                text = { Text("New event") },
            )
        },
    ) { padding ->
        if (loading) {
            LoadingBox(Modifier.padding(padding))
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 96.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            errorBannerItem(error, onRetry = { load() })
            message?.let {
                item {
                    Card(
                        shape = RoundedCornerShape(14.dp),
                        colors = CardDefaults.cardColors(
                            containerColor = MaterialTheme.colorScheme.secondaryContainer,
                        ),
                    ) { Text(it, Modifier.padding(14.dp)) }
                }
            }

            if (events.isNotEmpty()) {
                item { EventsHero(events) }

                item {
                    OutlinedTextField(
                        value = query,
                        onValueChange = { query = it },
                        placeholder = { Text("Search events") },
                        leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                        trailingIcon = {
                            if (query.isNotEmpty()) {
                                IconButton(onClick = { query = "" }) {
                                    Icon(Icons.Filled.Close, contentDescription = "Clear search")
                                }
                            }
                        },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }

                item {
                    ChipRow(
                        options = listOf(
                            "all" to "All (${events.size})",
                            "open" to "Open (${events.count { it.isActive }})",
                            "closed" to "Closed (${events.count { !it.isActive }})",
                        ),
                        selected = filter,
                        onSelect = { filter = it },
                        contentPadding = PaddingValues(0.dp),
                    )
                }
            }

            when {
                events.isEmpty() -> item {
                    EmptyState(
                        title = "No events yet",
                        body = "Create one before a stall so its sales are kept separate from " +
                            "walk-up orders.",
                        actionLabel = "New event",
                        onAction = { showAdd = true },
                    )
                }
                shown.isEmpty() -> item {
                    EmptyState(
                        title = "Nothing here",
                        body = if (query.isNotBlank()) "No event is called \"$query\"."
                        else "No events match this filter.",
                    )
                }
                else -> item {
                    ListCard {
                        shown.forEachIndexed { index, event ->
                            EventRow(
                                event = event,
                                busy = busy,
                                divider = index < shown.lastIndex,
                                onOpen = { onOpenEvent(event) },
                                onCredit = { creditingEvent = event },
                                onToggleActive = { setActive(event) },
                            )
                        }
                    }
                }
            }
        }
    }

    creditingEvent?.let { event ->
        CreditEventSheet(
            event = event,
            partners = partners,
            busy = busy,
            onDismiss = { creditingEvent = null },
            onSubmit = { partnerId ->
                creditingEvent = null
                busy = true
                scope.launch {
                    when (val result = repository.creditEvent(event.id, partnerId)) {
                        is ApiResult.Success -> { message = result.value; load() }
                        is ApiResult.Failure -> error = result.message
                    }
                    busy = false
                }
            },
        )
    }

    if (showAdd) {
        AddEventSheet(
            busy = busy,
            onDismiss = { showAdd = false },
            onSubmit = { name, isPaid, entryCost, start, end, notes ->
                showAdd = false
                busy = true
                scope.launch {
                    val result = repository.addEvent(name, isPaid, entryCost, start, end, notes)
                    when (result) {
                        is ApiResult.Success -> events = result.value
                        is ApiResult.Failure -> error = result.message
                    }
                    busy = false
                }
            },
        )
    }
}

/** What every event together has taken, on the brand gradient. */
@Composable
private fun EventsHero(events: List<Event>) {
    val revenue = events.sumOf { it.revenue }
    val orders = events.sumOf { it.orderCount }
    val open = events.count { it.isActive }
    Card(
        shape = RoundedCornerShape(26.dp),
        colors = CardDefaults.cardColors(containerColor = Color.Transparent),
        elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
    ) {
        Column(
            Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(BrandGradient))
                .padding(22.dp),
        ) {
            Text(
                "TAKEN AT EVENTS",
                style = MaterialTheme.typography.labelSmall,
                color = Color.White.copy(alpha = 0.82f),
            )
            Spacer(Modifier.height(4.dp))
            Text(
                Money.full(revenue),
                style = MaterialTheme.typography.displaySmall,
                color = Color.White,
            )
            Text(
                "$orders orders · ${events.size} events · $open open",
                style = MaterialTheme.typography.bodyMedium,
                color = Color.White.copy(alpha = 0.88f),
            )
        }
    }
}

/** One event: tile, name, what it took; its menu holds the rarer actions. */
@Composable
private fun EventRow(
    event: Event,
    busy: Boolean,
    divider: Boolean,
    onOpen: () -> Unit,
    onCredit: () -> Unit,
    onToggleActive: () -> Unit,
) {
    var menu by remember { mutableStateOf(false) }
    val dates = event.startDate?.takeIf { it.isNotBlank() }?.let { start ->
        Dates.pretty(start) + (event.endDate?.takeIf { it.isNotBlank() }?.let { " – " + Dates.pretty(it) } ?: "")
    }
    ListRow(
        title = event.name,
        subtitle = listOfNotNull(
            "${event.orderCount} orders",
            dates,
            if (event.isPaid) "stall " + Money.short(event.entryCost) else null,
        ).joinToString(" · "),
        amount = Money.short(event.revenue),
        amountCaption = if (event.isActive) "Open" else "Closed",
        amountCaptionColor = if (event.isActive) positiveColor() else MaterialTheme.colorScheme.onSurfaceVariant,
        leading = {
            IconTile(
                label = event.name,
                icon = Icons.Filled.Storefront,
                size = 42.dp,
                tint = if (event.isActive) null else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        },
        trailing = {
            Box {
                IconButton(onClick = { menu = true }, enabled = !busy) {
                    Icon(
                        Icons.Filled.MoreVert,
                        contentDescription = "More for ${event.name}",
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    DropdownMenuItem(
                        text = { Text("See orders") },
                        onClick = { menu = false; onOpen() },
                    )
                    if (event.revenue > 0.5) {
                        DropdownMenuItem(
                            text = { Text("Credit revenue to a partner") },
                            onClick = { menu = false; onCredit() },
                        )
                    }
                    DropdownMenuItem(
                        text = { Text(if (event.isActive) "Close event" else "Reopen event") },
                        onClick = { menu = false; onToggleActive() },
                    )
                }
            }
        },
        divider = divider,
        onClick = onOpen,
    )
}

@Composable
private fun CreditEventSheet(
    event: Event,
    partners: List<Partner>,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (Int) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState()
    var partnerId by remember { mutableStateOf(partners.firstOrNull()?.id ?: 0) }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Credit ${event.name}", style = MaterialTheme.typography.titleLarge)
            Text(
                "Credits everything this event took that has not been credited already — " +
                    "crediting a second time adds nothing rather than doubling it.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            ChipRow(
                options = partners.map { it.id to it.name },
                selected = partnerId,
                onSelect = { partnerId = it },
                contentPadding = PaddingValues(0.dp),
            )
            Button(
                onClick = { onSubmit(partnerId) },
                enabled = !busy && partnerId > 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Credit to this partner") }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun AddEventSheet(
    busy: Boolean,
    onDismiss: () -> Unit,
    onSubmit: (String, Boolean, Double, String, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var name by remember { mutableStateOf("") }
    var isPaid by remember { mutableStateOf(false) }
    var entryCostText by remember { mutableStateOf("") }
    var start by remember { mutableStateOf(Dates.today()) }
    var end by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("New event", style = MaterialTheme.typography.titleLarge)

            OutlinedTextField(
                value = name,
                onValueChange = { name = it },
                label = { Text("Event name") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Row {
                OutlinedTextField(
                    value = start,
                    onValueChange = { start = it },
                    label = { Text("Starts") },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                OutlinedTextField(
                    value = end,
                    onValueChange = { end = it },
                    label = { Text("Ends (optional)") },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(checked = isPaid, onCheckedChange = { isPaid = it })
                Spacer(Modifier.width(12.dp))
                Text("We paid to take a stall here")
            }
            if (isPaid) {
                OutlinedTextField(
                    value = entryCostText,
                    onValueChange = { entryCostText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Stall fee") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            OutlinedTextField(
                value = notes,
                onValueChange = { notes = it },
                label = { Text("Notes") },
                modifier = Modifier.fillMaxWidth(),
            )
            Button(
                onClick = {
                    onSubmit(
                        name.trim(), isPaid, entryCostText.toDoubleOrNull() ?: 0.0,
                        start, end, notes,
                    )
                },
                enabled = !busy && name.isNotBlank(),
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Create event") }
            Spacer(Modifier.height(24.dp))
        }
    }
}
