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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Event
import com.madeforu.sales.data.Partner
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ChipRow
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.errorBannerItem
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * Events and stalls: what each one sold, and crediting that revenue to the
 * partner who collected it.
 */
@Composable
fun EventsScreen(
    repository: Repository,
    onBack: () -> Unit,
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

    LaunchedEffect(Unit) {
        load()
        repository.bootstrap().let {
            if (it is ApiResult.Success) partners = it.value.partners.filter { p -> p.isActive }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Events & stalls") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
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
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 96.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
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

            if (events.isEmpty()) {
                item {
                    Text(
                        "No events yet. Create one before a stall so its sales are kept separate " +
                            "from walk-up orders.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            items(events.size) { index ->
                val event = events[index]
                Card(
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(
                            alpha = if (event.isActive) 0.32f else 0.15f,
                        ),
                    ),
                ) {
                    Column(Modifier.fillMaxWidth().padding(16.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                event.name,
                                style = MaterialTheme.typography.titleMedium,
                                modifier = Modifier.weight(1f),
                            )
                            if (event.isActive) Pill("Open", positiveColor())
                            else Pill("Closed", MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Spacer(Modifier.height(8.dp))
                        DetailRow("Orders", event.orderCount.toString())
                        DetailRow("Revenue", Money.full(event.revenue))
                        if (event.isPaid) DetailRow("Stall fee", Money.full(event.entryCost))
                        if (!event.startDate.isNullOrBlank()) {
                            DetailRow(
                                "Dates",
                                Dates.pretty(event.startDate) +
                                    (event.endDate?.takeIf { it.isNotBlank() }
                                        ?.let { " – " + Dates.pretty(it) } ?: ""),
                            )
                        }
                        Spacer(Modifier.height(8.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            TextButton(onClick = {
                                busy = true
                                scope.launch {
                                    val result = repository.setEventActive(event.id, !event.isActive)
                                    when (result) {
                                        is ApiResult.Success -> events = result.value
                                        is ApiResult.Failure -> error = result.message
                                    }
                                    busy = false
                                }
                            }, enabled = !busy) {
                                Text(if (event.isActive) "Close event" else "Reopen")
                            }
                            if (event.revenue > 0.5) {
                                TextButton(onClick = { creditingEvent = event }) {
                                    Text("Credit revenue")
                                }
                            }
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
