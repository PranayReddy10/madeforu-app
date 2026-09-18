@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.screens

import android.content.Context
import android.content.Intent
import android.print.PrintAttributes
import android.print.PrintManager
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.compose.foundation.Image
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
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Autorenew
import androidx.compose.material.icons.filled.Print
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.madeforu.sales.bill.BillPdf
import com.madeforu.sales.bill.Qr
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.core.Dates
import com.madeforu.sales.core.Money
import com.madeforu.sales.core.isAuthFailure
import com.madeforu.sales.data.Bill
import com.madeforu.sales.data.Repository
import com.madeforu.sales.ui.components.DetailRow
import com.madeforu.sales.ui.components.ErrorBanner
import com.madeforu.sales.ui.components.LoadingBox
import com.madeforu.sales.ui.components.Pill
import com.madeforu.sales.ui.components.ThinDivider
import com.madeforu.sales.ui.theme.negativeColor
import com.madeforu.sales.ui.theme.positiveColor
import kotlinx.coroutines.launch

/**
 * One order's bill: a preview of exactly what the customer will receive,
 * and the four things anyone wants to do with it — send it, print it,
 * hand over the link, or re-issue it after the order changed.
 */
@Composable
fun BillScreen(
    repository: Repository,
    orderId: Int,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var bill by remember { mutableStateOf<Bill?>(null) }
    var loading by remember { mutableStateOf(true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun load(reissue: Boolean) {
        scope.launch {
            if (reissue) busy = true else loading = true
            val result = if (reissue) repository.reissueBill(orderId) else repository.bill(orderId)
            when (result) {
                is ApiResult.Success -> { bill = result.value; error = null }
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
            busy = false
        }
    }

    LaunchedEffect(orderId) { load(reissue = false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(bill?.billNo ?: "Bill") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(onClick = { load(reissue = true) }, enabled = !busy) {
                        Icon(Icons.Filled.Autorenew, contentDescription = "Re-issue bill")
                    }
                },
            )
        },
    ) { padding ->
        val current = bill
        if (loading || current == null) {
            Column(Modifier.padding(padding)) {
                ErrorBanner(error, Modifier.padding(16.dp), onRetry = { load(false) })
                if (error == null) LoadingBox()
            }
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item { ErrorBanner(error, onRetry = { load(false) }) }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick = {
                            val file = BillPdf.write(context, current)
                            if (file != null) BillPdf.share(context, file, current)
                            else Toast.makeText(
                                context,
                                "Could not build the PDF. Free up some storage and try again.",
                                Toast.LENGTH_LONG,
                            ).show()
                        },
                        modifier = Modifier.weight(1f),
                    ) {
                        Icon(Icons.Filled.Share, contentDescription = null, Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Send bill")
                    }
                    OutlinedButton(
                        onClick = {
                            scope.launch {
                                printBill(context, repository.billUrl(orderId, thermal = false), current.billNo)
                            }
                        },
                        modifier = Modifier.weight(1f),
                    ) {
                        Icon(Icons.Filled.Print, contentDescription = null, Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Print")
                    }
                }
            }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(
                        onClick = { shareText(context, billLinkMessage(current)) },
                        modifier = Modifier.weight(1f),
                    ) { Text("Share link") }
                    OutlinedButton(
                        onClick = {
                            scope.launch {
                                printBill(context, repository.billUrl(orderId, thermal = true), current.billNo)
                            }
                        },
                        modifier = Modifier.weight(1f),
                    ) { Text("Counter roll") }
                }
            }

            item { BillPreview(current) }

            item { Spacer(Modifier.height(32.dp)) }
        }
    }
}

/**
 * The bill as the customer sees it, drawn with the app's own components
 * rather than a WebView. A WebView here would mean a blank rectangle until
 * the page loads, on the one screen where a partner is usually about to
 * hand the phone to a customer.
 */
@Composable
internal fun BillPreview(bill: Bill) {
    val order = bill.order
    Card(
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(20.dp)) {
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Column {
                    if (bill.logoUrl.isNotBlank()) {
                        AsyncImage(
                            model = bill.logoUrl,
                            contentDescription = null,
                            contentScale = ContentScale.Fit,
                            modifier = Modifier.height(40.dp).padding(bottom = 6.dp),
                        )
                    }
                    Text(
                        bill.business.name,
                        style = MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        bill.business.tag,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(
                        "INVOICE",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(bill.billNo, style = MaterialTheme.typography.titleMedium)
                }
            }

            ThinDivider(Modifier.padding(vertical = 12.dp))

            DetailRow("Billed to", order.customer.name)
            if (order.customer.phone.isNotBlank()) DetailRow("Phone", order.customer.phone)
            DetailRow("Order", order.orderNo)
            DetailRow("Date", Dates.pretty(order.orderDate))
            DetailRow("Channel", order.channel)

            ThinDivider(Modifier.padding(vertical = 12.dp))

            order.lines.forEach { line ->
                DetailRow(
                    "${line.item} × ${line.quantity}  @ ${Money.full(line.unitPrice)}",
                    Money.full(line.lineTotal),
                )
            }

            ThinDivider(Modifier.padding(vertical = 12.dp))

            DetailRow("Subtotal", Money.full(order.subtotal))
            if (order.extraCharge > 0.001) {
                DetailRow(
                    order.extraChargeReason?.takeIf { it.isNotBlank() } ?: "Additional charges",
                    "+" + Money.full(order.extraCharge),
                )
            }
            if (order.discount > 0.001) {
                DetailRow(
                    order.discountReason?.takeIf { it.isNotBlank() } ?: "Discount",
                    "−" + Money.full(order.discount),
                    valueColor = positiveColor(),
                )
            }
            DetailRow("Total", Money.full(order.total), emphasise = true)

            Text(
                order.amountWords,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(top = 6.dp),
            )

            if (order.payments.isNotEmpty()) {
                ThinDivider(Modifier.padding(vertical = 12.dp))
                Text("Payments received", style = MaterialTheme.typography.labelSmall)
                Spacer(Modifier.height(4.dp))
                order.payments.forEach { payment ->
                    DetailRow(
                        Dates.pretty(payment.paidAt) + " · " + payment.mode.uppercase(),
                        Money.full(payment.amount),
                    )
                }
            }

            Spacer(Modifier.height(12.dp))

            val settled = order.balance <= 0.001
            Surface(
                shape = RoundedCornerShape(12.dp),
                color = (if (settled) positiveColor() else negativeColor()).copy(alpha = 0.12f),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(
                    if (settled) "PAID IN FULL" else "Balance due  " + Money.full(order.balance),
                    style = MaterialTheme.typography.titleMedium,
                    color = if (settled) positiveColor() else negativeColor(),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
                )
            }

            // The QR only appears when there is something to pay and a UPI
            // id is configured; an empty box on a paid bill helps nobody.
            val intent = bill.upiIntent
            if (!settled && !intent.isNullOrBlank()) {
                Spacer(Modifier.height(16.dp))
                val qr = remember(intent) { Qr.bitmap(intent, 400) }
                if (qr != null) {
                    Column(
                        Modifier.fillMaxWidth(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Image(
                            bitmap = qr.asImageBitmap(),
                            contentDescription = "UPI QR code for the balance",
                            modifier = Modifier.size(160.dp),
                        )
                        Text(
                            "Scan with any UPI app",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }

            Spacer(Modifier.height(16.dp))
            Text(
                bill.footer,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

/** The bill book: every bill issued, newest first. */
@Composable
fun BillsScreen(
    repository: Repository,
    onOpenBill: (Int) -> Unit,
    onBack: () -> Unit,
    onSessionExpired: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var bills by remember { mutableStateOf<List<com.madeforu.sales.data.BillListItem>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        scope.launch {
            when (val result = repository.bills()) {
                is ApiResult.Success -> bills = result.value.bills
                is ApiResult.Failure ->
                    if (result.isAuthFailure()) onSessionExpired() else error = result.message
            }
            loading = false
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Bill book") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        if (loading) {
            LoadingBox(Modifier.padding(padding))
            return@Scaffold
        }
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item { ErrorBanner(error) }
            if (bills.isEmpty()) {
                item {
                    Text(
                        "No bills issued yet. Open an order and tap “Make bill”.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
            items(bills.size) { index ->
                val item = bills[index]
                Card(
                    onClick = { onOpenBill(item.orderId) },
                    shape = RoundedCornerShape(16.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.3f),
                    ),
                ) {
                    Row(
                        Modifier.fillMaxWidth().padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(item.billNo, style = MaterialTheme.typography.titleMedium)
                            Text(
                                item.customer + " · " + item.orderNo + " · " + Dates.pretty(item.issuedAt),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(Money.short(item.total), fontWeight = FontWeight.SemiBold)
                            if (item.balance > 0.5) {
                                Pill(Money.short(item.balance) + " due", negativeColor())
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun billLinkMessage(bill: Bill): String = buildString {
    append("Bill ").append(bill.billNo).append(" from ").append(bill.business.name).append("\n")
    append("Order ").append(bill.order.orderNo).append(" · ").append(Money.full(bill.order.total))
    if (bill.order.balance > 0.001) {
        append("\nBalance due: ").append(Money.full(bill.order.balance))
    }
    if (bill.publicUrl.isNotBlank()) append("\n\n").append(bill.publicUrl)
}

private fun shareText(context: Context, text: String) {
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, text)
    }
    context.startActivity(Intent.createChooser(intent, "Share bill"))
}

/**
 * Hands the server's printable HTML to Android's print stack, which also
 * covers "Save as PDF" and any Bluetooth thermal printer the phone has a
 * print service for.
 */
private fun printBill(context: Context, url: String, billNo: String) {
    val webView = WebView(context)
    webView.settings.javaScriptEnabled = false
    // The WebView must outlive this function: nothing in the view tree
    // holds it, and if it is collected mid-load the print adapter renders
    // a blank page. Parking it here keeps it alive until the adapter has
    // been handed to the print manager, after which it is released.
    printingWebViews.add(webView)
    webView.webViewClient = object : WebViewClient() {
        override fun onPageFinished(view: WebView, finishedUrl: String) {
            val printManager = context.getSystemService(Context.PRINT_SERVICE) as? PrintManager
            if (printManager == null) {
                Toast.makeText(context, "This phone has no printing service.", Toast.LENGTH_SHORT).show()
                printingWebViews.remove(view)
                return
            }
            val adapter = view.createPrintDocumentAdapter("MadeForU $billNo")
            printManager.print(
                "MadeForU $billNo",
                adapter,
                PrintAttributes.Builder()
                    .setMediaSize(PrintAttributes.MediaSize.ISO_A4)
                    .build(),
            )
            printingWebViews.remove(view)
        }
    }
    webView.loadUrl(url)
}

/** Print jobs in flight. Empty except during the second a page loads. */
private val printingWebViews = mutableListOf<WebView>()
