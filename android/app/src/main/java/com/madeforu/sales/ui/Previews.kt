package com.madeforu.sales.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.Bill
import com.madeforu.sales.data.BillBusiness
import com.madeforu.sales.data.BillCustomer
import com.madeforu.sales.data.BillOrder
import com.madeforu.sales.data.BillPayment
import com.madeforu.sales.data.Order
import com.madeforu.sales.data.OrderItem
import com.madeforu.sales.data.Product
import com.madeforu.sales.ui.components.DonutChart
import com.madeforu.sales.ui.components.HorizontalBars
import com.madeforu.sales.ui.components.HourStrip
import com.madeforu.sales.ui.components.KpiCard
import com.madeforu.sales.ui.components.PayStatusPill
import com.madeforu.sales.ui.components.ProductThumb
import com.madeforu.sales.ui.components.RevenueLineChart
import com.madeforu.sales.ui.screens.BillPreview
import com.madeforu.sales.ui.screens.OrderRow
import com.madeforu.sales.ui.screens.ProductPickerRow
import com.madeforu.sales.ui.theme.MadeForUTheme

/**
 * Design-time previews.
 *
 * This app has no XML layouts — the UI is Compose, so Android Studio's
 * Layout Editor has nothing to open. These are the equivalent: open any
 * file with a `@Preview` in it and switch the editor to **Split** or
 * **Design** (top right of the editor), and the panel renders the real
 * composables against the sample data below.
 *
 * Each preview is declared twice, light and dark, because the two are what
 * partners actually see and a colour that works in one can disappear in
 * the other.
 *
 * The data here is fake but shaped like the real thing: a part-paid
 * walk-in order, a bill with a balance, products with and without a photo.
 * Nothing here ships behaviour — it is only ever rendered by the IDE.
 */

// ── Sample data ────────────────────────────────────────────────────

private val sampleProducts = listOf(
    Product(
        id = 1, name = "Square Magnet", price = 50.0, unitCost = 15.0,
        margin = 35.0, marginPct = 70.0,
        imageUrl = "",            // most products have no photo yet
        productUrl = "https://madeforu.co.in/product/square-magnet/",
    ),
    Product(id = 3, name = "MDF Magnet", price = 100.0, unitCost = 22.0, margin = 78.0, marginPct = 78.0),
    Product(id = 8, name = "T-shirts", price = 150.0, unitCost = 90.0, margin = 60.0, marginPct = 40.0),
)

private val sampleOrder = Order(
    id = 121,
    orderNo = "ORD260918001",
    name = "Walk-in",
    phone = "",
    isWalkIn = true,
    subtotal = 550.0,
    extraCharge = 40.0,
    extraChargeReason = "Gift wrap",
    discount = 50.0,
    discountReason = "Stall offer",
    total = 540.0,
    paidAmount = 440.0,
    balance = 100.0,
    payStatus = "partial",
    isReady = true,
    isDelivered = false,
    itemsText = "MDF Magnet x4, Oval KeyChain x3",
    createdAt = "2026-09-18 12:57:51",
)

private val sampleNamedOrder = sampleOrder.copy(
    id = 120,
    orderNo = "ORD260918002",
    name = "Padma",
    phone = "9494574706",
    isWalkIn = false,
    total = 300.0,
    paidAmount = 300.0,
    balance = 0.0,
    payStatus = "paid",
    isDelivered = true,
    itemsText = "Acrylic Magnet x3",
)

private val sampleBill = Bill(
    billNo = "MFU/26-27/0001",
    revision = 1,
    issuedAt = "2026-09-18 12:58:02",
    publicUrl = "https://sale.madeforu.co.in/api/bills.php?action=html&token=…",
    business = BillBusiness(
        name = "MadeForU",
        tag = "Handmade personalised gifts",
        addr = "Nizampet, Hyderabad 500090",
        phone = "+91 93810 24794",
        site = "madeforu.co.in",
    ),
    footer = "Thank you for shopping with MadeForU!",
    terms = "Custom-made items are not returnable.",
    upiIntent = "upi://pay?pa=madeforu@upi&pn=MadeForU&am=100.00&cu=INR",
    order = BillOrder(
        orderId = 121,
        orderNo = "ORD260918001",
        orderDate = "2026-09-18 12:57:51",
        customer = BillCustomer(name = "Walk-in customer", phone = "", isWalkIn = true),
        channel = "Direct",
        lines = listOf(
            OrderItem(item = "MDF Magnet", quantity = 4, unitPrice = 100.0, lineTotal = 400.0),
            OrderItem(item = "Oval KeyChain", quantity = 3, unitPrice = 50.0, lineTotal = 150.0),
        ),
        totalQty = 7,
        subtotal = 550.0,
        extraCharge = 40.0,
        extraChargeReason = "Gift wrap",
        discount = 50.0,
        discountReason = "Stall offer",
        total = 540.0,
        paid = 440.0,
        balance = 100.0,
        payStatus = "partial",
        payments = listOf(
            BillPayment(amount = 300.0, mode = "cash", paidAt = "2026-09-18 12:57:51"),
            BillPayment(amount = 140.0, mode = "upi", paidAt = "2026-09-18 12:58:00"),
        ),
        amountWords = "Five Hundred and Forty Rupees Only",
    ),
)

/** Every preview renders inside the real theme, on the real surface. */
@Composable
private fun PreviewShell(dark: Boolean, content: @Composable () -> Unit) {
    MadeForUTheme(themeChoice = if (dark) "dark" else "light") {
        Surface(color = MaterialTheme.colorScheme.background) {
            Column(Modifier.padding(16.dp)) { content() }
        }
    }
}

// ── The bill ───────────────────────────────────────────────────────

@Preview(name = "Bill · light", showBackground = true, widthDp = 400, heightDp = 900)
@Composable
private fun BillLight() = PreviewShell(dark = false) { BillPreview(sampleBill) }

@Preview(name = "Bill · dark", showBackground = true, widthDp = 400, heightDp = 900)
@Composable
private fun BillDark() = PreviewShell(dark = true) { BillPreview(sampleBill) }

// ── Order list ─────────────────────────────────────────────────────

@Preview(name = "Order rows · light", showBackground = true, widthDp = 400)
@Composable
private fun OrderRowsLight() = PreviewShell(dark = false) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        OrderRow(sampleOrder) {}
        OrderRow(sampleNamedOrder) {}
    }
}

@Preview(name = "Order rows · dark", showBackground = true, widthDp = 400)
@Composable
private fun OrderRowsDark() = PreviewShell(dark = true) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        OrderRow(sampleOrder) {}
        OrderRow(sampleNamedOrder) {}
    }
}

// ── Taking a sale ──────────────────────────────────────────────────

@Preview(name = "Product picker", showBackground = true, widthDp = 400)
@Composable
private fun ProductPickerPreview() = PreviewShell(dark = false) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        // One selected, two not: the two states side by side are the point.
        ProductPickerRow(sampleProducts[0], quantity = 2) {}
        ProductPickerRow(sampleProducts[1], quantity = 0) {}
        ProductPickerRow(sampleProducts[2], quantity = 0) {}
    }
}

@Preview(name = "Product picker · dark", showBackground = true, widthDp = 400)
@Composable
private fun ProductPickerDarkPreview() = PreviewShell(dark = true) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        ProductPickerRow(sampleProducts[0], quantity = 2) {}
        ProductPickerRow(sampleProducts[1], quantity = 0) {}
    }
}

// ── Dashboard pieces ───────────────────────────────────────────────

@Preview(name = "KPI cards", showBackground = true, widthDp = 400)
@Composable
private fun KpiPreview() = PreviewShell(dark = false) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            KpiCard("Revenue", Money.short(36098.0), change = 12.4, modifier = Modifier.weight(1f))
            KpiCard("Orders", "98", change = -3.0, modifier = Modifier.weight(1f))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            KpiCard(
                "Outstanding", Money.short(150.0),
                caption = "still to collect", modifier = Modifier.weight(1f),
            )
            KpiCard(
                "Avg order", Money.short(370.08),
                caption = "across the period", modifier = Modifier.weight(1f),
            )
        }
    }
}

@Preview(name = "Charts · light", showBackground = true, widthDp = 400, heightDp = 700)
@Composable
private fun ChartsLight() = ChartGallery(dark = false)

@Preview(name = "Charts · dark", showBackground = true, widthDp = 400, heightDp = 700)
@Composable
private fun ChartsDark() = ChartGallery(dark = true)

@Composable
private fun ChartGallery(dark: Boolean) = PreviewShell(dark) {
    Column(verticalArrangement = Arrangement.spacedBy(20.dp)) {
        Text("Revenue trend", style = MaterialTheme.typography.titleMedium)
        RevenueLineChart(
            values = listOf(1200.0, 2400.0, 800.0, 3600.0, 2900.0, 5400.0, 4100.0),
            labels = listOf("12 Sep", "13 Sep", "14 Sep", "15 Sep", "16 Sep", "17 Sep", "18 Sep"),
        )
        Text("How customers paid", style = MaterialTheme.typography.titleMedium)
        DonutChart(
            slices = listOf("UPI" to 27198.0, "CASH" to 9290.0),
            centreLabel = "collected",
            centreValue = Money.compact(36488.0),
        )
        Text("What sold", style = MaterialTheme.typography.titleMedium)
        HorizontalBars(
            entries = listOf(
                Triple("Square Magnet  (517)", 25850.0, "₹25.9k"),
                Triple("Acrylic Magnet  (68)", 6800.0, "₹6.8k"),
                Triple("MDF Magnet  (27)", 2700.0, "₹2.7k"),
            ),
        )
        Text("Busiest hours", style = MaterialTheme.typography.titleMedium)
        HourStrip(listOf(0, 0, 0, 0, 0, 0, 0, 0, 1, 3, 6, 9, 12, 8, 5, 7, 11, 14, 9, 4, 2, 1, 0, 0))
    }
}

// ── Small pieces ───────────────────────────────────────────────────

@Preview(name = "Status pills & thumbs", showBackground = true, widthDp = 400)
@Composable
private fun PiecesPreview() = PreviewShell(dark = false) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PayStatusPill("paid")
            PayStatusPill("partial")
            PayStatusPill("unpaid")
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            ProductThumb("Square Magnet", "")
            ProductThumb("Bottle", "")
            ProductThumb("T-shirts", "")
        }
    }
}
