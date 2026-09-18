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
import androidx.compose.material.icons.filled.Chat
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
import androidx.compose.material3.OutlinedButton
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
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Product
import com.madeforu.sales.data.PriceHistory
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.ProductThumb
import com.madeforu.sales.ui.components.softCardColors
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * Products: prices, costs and what is on the menu.
 *
 * Renaming and deleting are not here on purpose. The database joins these
 * items by NAME across seven tables, so a rename has to propagate through
 * all of them in one transaction — work that belongs on the website's
 * products page, not on a phone in a noisy stall. Everything safe (add,
 * reprice, recost, hide) is here.
 */
@Composable
fun CatalogScreen(
    repository: Repository,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val context = LocalContext.current

    var products by remember { mutableStateOf<List<Product>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf<Product?>(null) }
    var showAdd by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            when (val result = repository.products(includeHidden = true)) {
                is ApiResult.Success -> { products = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.background,
                ),
                title = { Text("Products & prices") },
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
                text = { Text("New product") },
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
            item { ErrorBanner(error, onRetry = { load() }) }

            // A price change answers a question, so the answer stays on
            // screen until it is dismissed rather than flashing past in a
            // toast that nobody finishes reading.
            message?.let { text ->
                item {
                    Card(shape = RoundedCornerShape(18.dp), colors = softCardColors()) {
                        Column(Modifier.fillMaxWidth().padding(16.dp)) {
                            Text(text, style = MaterialTheme.typography.bodyMedium)
                            Spacer(Modifier.height(8.dp))
                            TextButton(onClick = { message = null }) { Text("Got it") }
                        }
                    }
                }
            }

            items(products.size) { index ->
                val product = products[index]
                Card(
                    onClick = { editing = product },
                    shape = RoundedCornerShape(16.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(
                            alpha = if (product.isActive) 0.32f else 0.15f,
                        ),
                    ),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        ProductThumb(product.name, product.imageUrl)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(
                                    product.name,
                                    style = MaterialTheme.typography.titleMedium,
                                    color = if (product.isActive) MaterialTheme.colorScheme.onSurface
                                    else MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                if (!product.isActive) {
                                    Spacer(Modifier.width(8.dp))
                                    Pill("Hidden", MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                            }
                            Text(
                                "Costs " + Money.full(product.unitCost) +
                                    (product.marginPct?.let { "  ·  $it% margin" } ?: ""),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(
                                Money.full(product.price),
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold,
                            )
                            Text(
                                "+" + Money.short(product.margin),
                                style = MaterialTheme.typography.bodySmall,
                                color = positiveColor(),
                            )
                            // Same message share.php sends, so a customer
                            // gets identical text whichever tool was used.
                            if (product.productUrl.isNotBlank()) {
                                IconButton(onClick = { shareProduct(context, product) }) {
                                    Icon(
                                        Icons.Filled.Chat,
                                        contentDescription = "Send this product on WhatsApp",
                                        tint = MaterialTheme.colorScheme.primary,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    editing?.let { product ->
        EditProductSheet(
            product = product,
            busy = busy,
            onDismiss = { editing = null },
            onLoadHistory = { repository.priceHistory(product.name).successOrNull },
            onSave = { price, cost, active, imageUrl, productUrl ->
                editing = null
                busy = true
                scope.launch {
                    val result = repository.updateProductDetailed(
                        product.id, price, cost, active, imageUrl, productUrl,
                    )
                    when (result) {
                        is ApiResult.Success -> {
                            products = result.value.products
                            // The server's sentence says what the change
                            // does and does not touch; showing it is how
                            // the guarantee reaches the person who needs it.
                            message = result.value.message.ifBlank { null }
                        }
                        is ApiResult.Failure -> error = result.message
                    }
                    busy = false
                }
            },
        )
    }

    if (showAdd) {
        AddProductSheet(
            busy = busy,
            onDismiss = { showAdd = false },
            onSave = { name, price, cost ->
                showAdd = false
                busy = true
                scope.launch {
                    when (val result = repository.addProduct(name, price, cost)) {
                        is ApiResult.Success -> products = result.value
                        is ApiResult.Failure -> error = result.message
                    }
                    busy = false
                }
            },
        )
    }
}

@Composable
private fun EditProductSheet(
    product: Product,
    busy: Boolean,
    onDismiss: () -> Unit,
    onLoadHistory: suspend () -> PriceHistory?,
    onSave: (Double, Double, Boolean, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var priceText by remember { mutableStateOf(numberText(product.price)) }
    var history by remember { mutableStateOf<PriceHistory?>(null) }
    var costText by remember { mutableStateOf(numberText(product.unitCost)) }
    var active by remember { mutableStateOf(product.isActive) }
    var imageUrl by remember { mutableStateOf(product.imageUrl) }
    var productUrl by remember { mutableStateOf(product.productUrl) }

    // An older server has no price_history route; the sheet then simply
    // shows the general guarantee instead of the specific numbers.
    LaunchedEffect(product.id) { history = onLoadHistory() }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(product.name, style = MaterialTheme.typography.titleLarge)
            Text(
                if (history == null) {
                    "Changing the price affects new sales only. Orders already taken keep " +
                        "the price they were sold at."
                } else if (history!!.pastOrders > 0) {
                    "Changing the price affects new sales only. The " + history!!.pastOrders +
                        " order" + (if (history!!.pastOrders == 1) "" else "s") +
                        " that already include this product keep the price " +
                        (if (history!!.pastOrders == 1) "it was" else "they were") +
                        " sold at — their totals, bills and the partner accounts built on " +
                        "them do not move."
                } else {
                    "Nothing has been sold with this product yet, so there is no history " +
                        "to protect."
                },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Row {
                OutlinedTextField(
                    value = priceText,
                    onValueChange = { priceText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Selling price") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                OutlinedTextField(
                    value = costText,
                    onValueChange = { costText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Unit cost") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
            }

            val price = priceText.toDoubleOrNull() ?: 0.0
            val cost = costText.toDoubleOrNull() ?: 0.0
            Text(
                "Margin: " + Money.full(price - cost) +
                    if (price > 0) "  (${((price - cost) / price * 100).toInt()}%)" else "",
                style = MaterialTheme.typography.bodyMedium,
                color = positiveColor(),
            )

            Row(verticalAlignment = Alignment.CenterVertically) {
                Switch(checked = active, onCheckedChange = { active = it })
                Spacer(Modifier.width(12.dp))
                Column {
                    Text("Show on the order screen")
                    Text(
                        "Hiding keeps every past order intact; it only takes the item off the list.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            OutlinedTextField(
                value = imageUrl,
                onValueChange = { imageUrl = it.trim() },
                label = { Text("Photo URL") },
                placeholder = { Text("https://madeforu.co.in/wp-content/…") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = productUrl,
                onValueChange = { productUrl = it.trim() },
                label = { Text("Shop page URL") },
                placeholder = { Text("https://madeforu.co.in/product/…") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Text(
                "These are the same two links the website's Products page sets. " +
                    "They feed the public catalogue at menu.php and the WhatsApp " +
                    "share button here.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Button(
                onClick = { onSave(price, cost, active, imageUrl, productUrl) },
                enabled = !busy && price >= 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Save") }

            // What it used to sell for. Shown under the form because the
            // question "what was it before?" comes up the moment someone
            // is about to change it.
            history?.history?.takeIf { it.isNotEmpty() }?.let { rows ->
                Spacer(Modifier.height(4.dp))
                Text("Price history", style = MaterialTheme.typography.titleSmall)
                rows.take(8).forEach { h ->
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(Money.full(h.price), style = MaterialTheme.typography.bodyMedium)
                            Text(
                                Dates.pretty(h.changedAt) +
                                    (h.changedBy?.let { " · " + it } ?: "") +
                                    (h.note?.let { " · " + it } ?: ""),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Text(
                            "cost " + Money.full(h.unitCost),
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun AddProductSheet(
    busy: Boolean,
    onDismiss: () -> Unit,
    onSave: (String, Double, Double) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState()
    var name by remember { mutableStateOf("") }
    var priceText by remember { mutableStateOf("") }
    var costText by remember { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("New product", style = MaterialTheme.typography.titleLarge)
            Text(
                "Pick the name carefully: costs, stock and past orders are all matched " +
                    "by name, so renaming later is a job for the website.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            OutlinedTextField(
                value = name,
                onValueChange = { name = it },
                label = { Text("Name") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            Row {
                OutlinedTextField(
                    value = priceText,
                    onValueChange = { priceText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Selling price") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                OutlinedTextField(
                    value = costText,
                    onValueChange = { costText = it.filter { c -> c.isDigit() || c == '.' } },
                    label = { Text("Unit cost") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    modifier = Modifier.weight(1f),
                )
            }

            Button(
                onClick = {
                    onSave(
                        name.trim(),
                        priceText.toDoubleOrNull() ?: 0.0,
                        costText.toDoubleOrNull() ?: 0.0,
                    )
                },
                enabled = !busy && name.isNotBlank() && (priceText.toDoubleOrNull() ?: -1.0) >= 0,
                modifier = Modifier.fillMaxWidth().height(50.dp),
            ) { Text("Add product") }
            TextButton(onClick = onDismiss, modifier = Modifier.fillMaxWidth()) { Text("Cancel") }
            Spacer(Modifier.height(24.dp))
        }
    }
}

private fun numberText(value: Double): String =
    if (value == value.toLong().toDouble()) value.toLong().toString()
    else String.format(java.util.Locale.US, "%.2f", value)

/**
 * Sends a product to a customer on WhatsApp.
 *
 * The text is character-for-character what share.php sends — name, newline,
 * "👉", link, and deliberately no price, which that page calls out in a
 * comment. A partner sharing from the app and one sharing from the website
 * must produce the same message, or the same product arrives two ways.
 *
 * Falls back to the system share sheet when WhatsApp is not installed.
 */
private fun shareProduct(context: Context, product: Product) {
    val message = product.name + "\n\uD83D\uDC49 " + product.productUrl
    val whatsapp = Intent(Intent.ACTION_VIEW).apply {
        data = Uri.parse("https://wa.me/?text=" + Uri.encode(message))
    }
    runCatching { context.startActivity(whatsapp) }.onFailure {
        runCatching {
            context.startActivity(
                Intent.createChooser(
                    Intent(Intent.ACTION_SEND).apply {
                        type = "text/plain"
                        putExtra(Intent.EXTRA_TEXT, message)
                    },
                    "Send product",
                ),
            )
        }.onFailure {
            Toast.makeText(context, "Nothing on this phone can share that.", Toast.LENGTH_SHORT).show()
        }
    }
}
