package com.madeforu.sales.bill

import android.content.Context
import android.content.Intent
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Rect
import android.graphics.pdf.PdfDocument
import androidx.core.content.FileProvider
import com.madeforu.sales.core.Money
import com.madeforu.sales.data.Bill
import java.io.File
import java.io.FileOutputStream

/**
 * Draws a bill into a real PDF on the phone.
 *
 * Why draw it rather than print the server's HTML: a partner standing at a
 * stall wants to send the customer a file on WhatsApp, and that has to
 * work with one tap and without waiting on a page load. The system print
 * dialog can also produce a PDF from the HTML, and the bill screen offers
 * that too — but it is three dialogs deep and needs a connection.
 *
 * Everything is laid out in PostScript points (72 per inch), which is what
 * PdfDocument's canvas uses: A4 is 595 x 842.
 */
object BillPdf {

    private const val PAGE_WIDTH = 595
    private const val PAGE_HEIGHT = 842
    private const val MARGIN = 42f

    private const val INK = 0xFF14161A.toInt()
    private const val MUTED = 0xFF6B7280.toInt()
    private const val RULE = 0xFFD1D5DB.toInt()
    private const val BRAND = 0xFF6B2FE0.toInt()
    private const val DUE = 0xFFB91C1C.toInt()
    private const val PAID = 0xFF15803D.toInt()

    /**
     * Writes the bill and returns the file, or null if the device refused
     * the write (no cache space is the realistic case).
     */
    fun write(context: Context, bill: Bill): File? = runCatching {
        val document = PdfDocument()
        val pageInfo = PdfDocument.PageInfo.Builder(PAGE_WIDTH, PAGE_HEIGHT, 1).create()
        val page = document.startPage(pageInfo)
        drawPage(context, page.canvas, bill)
        document.finishPage(page)

        val dir = File(context.cacheDir, "bills").apply { mkdirs() }
        // The bill number carries slashes (MFU/26-27/0001); a filename
        // cannot.
        val safeName = bill.billNo.replace("[^A-Za-z0-9._-]".toRegex(), "-")
        val file = File(dir, "$safeName.pdf")
        FileOutputStream(file).use { document.writeTo(it) }
        document.close()
        file
    }.getOrNull()

    /** Hands the PDF to WhatsApp, email or anything else that takes a file. */
    fun share(context: Context, file: File, bill: Bill) {
        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            file,
        )
        val summary = buildString {
            append("Bill ").append(bill.billNo).append(" from ").append(bill.business.name)
            append("\nOrder ").append(bill.order.orderNo)
            append("\nTotal ").append(Money.full(bill.order.total))
            if (bill.order.balance > 0.001) append("\nBalance due ").append(Money.full(bill.order.balance))
            if (bill.publicUrl.isNotBlank()) append("\n\nView online: ").append(bill.publicUrl)
        }
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "application/pdf"
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_SUBJECT, "Bill ${bill.billNo}")
            putExtra(Intent.EXTRA_TEXT, summary)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(intent, "Send bill"))
    }

    private fun drawPage(context: Context, canvas: Canvas, bill: Bill) {
        val order = bill.order
        val business = bill.business

        val title = paint(20f, INK, bold = true)
        val heading = paint(11f, MUTED, bold = true, tracking = 0.08f)
        val body = paint(10f, INK)
        val bodyMuted = paint(9f, MUTED)
        val strong = paint(12f, INK, bold = true)
        val rule = Paint().apply { color = RULE; strokeWidth = 0.8f }
        val heavyRule = Paint().apply { color = INK; strokeWidth = 1.6f }

        var y = MARGIN + 18f
        val right = PAGE_WIDTH - MARGIN

        // ── Header ─────────────────────────────────────────────────
        canvas.drawText(business.name, MARGIN, y, title)
        drawRightAligned(canvas, "INVOICE", right, y - 10f, heading)
        drawRightAligned(canvas, bill.billNo, right, y + 4f, strong)

        y += 16f
        canvas.drawText(business.tag, MARGIN, y, bodyMuted)
        drawRightAligned(
            canvas,
            com.madeforu.sales.core.Dates.pretty(bill.issuedAt, withTime = true),
            right, y, bodyMuted,
        )

        y += 13f
        val contact = listOf(business.addr, business.phone, business.site)
            .filter { it.isNotBlank() }
            .joinToString("  ·  ")
        canvas.drawText(contact, MARGIN, y, bodyMuted)
        if (bill.revision > 1) {
            drawRightAligned(canvas, "Revision ${bill.revision}", right, y, bodyMuted)
        }

        if (business.gstin.isNotBlank()) {
            y += 12f
            canvas.drawText("GSTIN: ${business.gstin}", MARGIN, y, bodyMuted)
        }

        y += 12f
        canvas.drawLine(MARGIN, y, right, y, heavyRule)

        // ── Who and what ───────────────────────────────────────────
        y += 24f
        canvas.drawText("BILLED TO", MARGIN, y, heading)
        canvas.drawText("ORDER", MARGIN + 200f, y, heading)
        canvas.drawText("CHANNEL", MARGIN + 350f, y, heading)

        y += 15f
        canvas.drawText(order.customer.name, MARGIN, y, body)
        canvas.drawText(order.orderNo, MARGIN + 200f, y, body)
        canvas.drawText(order.channel, MARGIN + 350f, y, body)

        y += 13f
        if (order.customer.phone.isNotBlank()) {
            canvas.drawText(order.customer.phone, MARGIN, y, bodyMuted)
        }
        canvas.drawText(
            com.madeforu.sales.core.Dates.pretty(order.orderDate),
            MARGIN + 200f, y, bodyMuted,
        )

        // ── Line items ─────────────────────────────────────────────
        y += 28f
        val colQty = MARGIN + 300f
        val colRate = MARGIN + 380f
        val colAmount = right

        canvas.drawText("ITEM", MARGIN, y, heading)
        drawRightAligned(canvas, "QTY", colQty, y, heading)
        drawRightAligned(canvas, "RATE", colRate, y, heading)
        drawRightAligned(canvas, "AMOUNT", colAmount, y, heading)

        y += 6f
        canvas.drawLine(MARGIN, y, right, y, rule)

        order.lines.forEach { line ->
            y += 18f
            canvas.drawText(line.item, MARGIN, y, body)
            drawRightAligned(canvas, line.quantity.toString(), colQty, y, body)
            drawRightAligned(canvas, plain(line.unitPrice), colRate, y, body)
            drawRightAligned(canvas, plain(line.lineTotal), colAmount, y, body)
            y += 6f
            canvas.drawLine(MARGIN, y, right, y, rule)
        }

        // ── Totals ─────────────────────────────────────────────────
        y += 20f
        drawRightAligned(canvas, "Subtotal", colRate, y, bodyMuted)
        drawRightAligned(canvas, plain(order.subtotal), colAmount, y, body)

        if (order.extraCharge > 0.001) {
            y += 16f
            drawRightAligned(
                canvas,
                order.extraChargeReason?.takeIf { it.isNotBlank() } ?: "Additional charges",
                colRate, y, bodyMuted,
            )
            drawRightAligned(canvas, "+" + plain(order.extraCharge), colAmount, y, body)
        }
        if (order.discount > 0.001) {
            y += 16f
            drawRightAligned(
                canvas,
                order.discountReason?.takeIf { it.isNotBlank() } ?: "Discount",
                colRate, y, bodyMuted,
            )
            drawRightAligned(canvas, "-" + plain(order.discount), colAmount, y, body)
        }

        y += 10f
        canvas.drawLine(colRate - 80f, y, right, y, heavyRule)
        y += 20f
        drawRightAligned(canvas, "TOTAL", colRate, y, heading)
        drawRightAligned(canvas, Money.full(order.total), colAmount, y, paint(15f, INK, bold = true))

        y += 18f
        canvas.drawText(order.amountWords, MARGIN, y, paint(9f, MUTED, italic = true))

        // ── Payments ───────────────────────────────────────────────
        if (order.payments.isNotEmpty()) {
            y += 28f
            canvas.drawText("PAYMENTS RECEIVED", MARGIN, y, heading)
            order.payments.forEach { payment ->
                y += 15f
                val label = com.madeforu.sales.core.Dates.pretty(payment.paidAt) +
                    "  ·  " + payment.mode.uppercase()
                canvas.drawText(label, MARGIN, y, bodyMuted)
                drawRightAligned(canvas, plain(payment.amount), colAmount, y, body)
            }
            y += 16f
            drawRightAligned(canvas, "Paid", colRate, y, bodyMuted)
            drawRightAligned(canvas, plain(order.paid), colAmount, y, body)
        }

        // ── Balance banner ─────────────────────────────────────────
        y += 26f
        val settled = order.balance <= 0.001
        val bannerPaint = Paint().apply {
            color = if (settled) 0xFFF0FDF4.toInt() else 0xFFFEF2F2.toInt()
            isAntiAlias = true
        }
        canvas.drawRect(MARGIN, y - 16f, right, y + 12f, bannerPaint)
        val bannerText = if (settled) "PAID IN FULL"
        else "BALANCE DUE  " + Money.full(order.balance)
        canvas.drawText(
            bannerText,
            MARGIN + 12f, y + 4f,
            paint(12f, if (settled) PAID else DUE, bold = true),
        )

        // ── UPI QR ─────────────────────────────────────────────────
        val intent = bill.upiIntent
        if (!settled && !intent.isNullOrBlank()) {
            val qr = Qr.bitmap(intent, 320)
            if (qr != null) {
                y += 34f
                val box = 92f
                canvas.drawBitmap(
                    qr,
                    null,
                    Rect(
                        (right - box).toInt(), y.toInt(),
                        right.toInt(), (y + box).toInt(),
                    ),
                    null,
                )
                canvas.drawText("Scan to pay the balance by UPI", MARGIN, y + 16f, body)
                canvas.drawText(
                    "Any UPI app · amount is pre-filled",
                    MARGIN, y + 30f, bodyMuted,
                )
                y += box
            }
        }

        // ── Tracking ───────────────────────────────────────────────
        if (!order.awb.isNullOrBlank()) {
            y += 24f
            canvas.drawText("Delhivery tracking: ${order.awb}", MARGIN, y, bodyMuted)
        }

        // ── Footer, pinned to the bottom of the page ───────────────
        val footerY = PAGE_HEIGHT - MARGIN - 26f
        canvas.drawLine(MARGIN, footerY - 16f, right, footerY - 16f, rule)
        drawCentred(canvas, bill.footer, PAGE_WIDTH / 2f, footerY, paint(9f, MUTED))
        drawCentred(canvas, bill.terms, PAGE_WIDTH / 2f, footerY + 12f, paint(7.5f, MUTED))
    }

    // ── Drawing helpers ────────────────────────────────────────────

    private fun paint(
        size: Float,
        colorInt: Int,
        bold: Boolean = false,
        italic: Boolean = false,
        tracking: Float = 0f,
    ): Paint = Paint().apply {
        isAntiAlias = true
        color = colorInt
        textSize = size
        letterSpacing = tracking
        typeface = android.graphics.Typeface.create(
            android.graphics.Typeface.SANS_SERIF,
            when {
                bold -> android.graphics.Typeface.BOLD
                italic -> android.graphics.Typeface.ITALIC
                else -> android.graphics.Typeface.NORMAL
            },
        )
    }

    private fun drawRightAligned(canvas: Canvas, text: String, rightX: Float, y: Float, paint: Paint) {
        canvas.drawText(text, rightX - paint.measureText(text), y, paint)
    }

    private fun drawCentred(canvas: Canvas, text: String, centreX: Float, y: Float, paint: Paint) {
        canvas.drawText(text, centreX - paint.measureText(text) / 2f, y, paint)
    }

    /** Amounts inside the table: grouped, but without a ₹ on every row. */
    private fun plain(value: Double): String = Money.full(value).removePrefix("₹")
}
