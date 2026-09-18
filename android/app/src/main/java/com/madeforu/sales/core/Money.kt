package com.madeforu.sales.core

import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import kotlin.math.abs
import kotlin.math.roundToLong

/**
 * Money and date formatting.
 *
 * Rupees are grouped the Indian way — 1,23,456, not 123,456 — because
 * every partner reads the number as lakhs. Locale("en", "IN") does this
 * correctly on modern Android, but the grouping is done by hand here so a
 * phone set to another locale still shows the familiar shape.
 */
object Money {

    /** ₹1,23,456.50 — full precision, for bills and ledgers. */
    fun full(value: Double): String = signed(value, decimals = true)

    /** ₹1,23,457 — no paise, for cards and lists where the paise are noise. */
    fun short(value: Double): String = signed(value, decimals = false)

    /**
     * The minus goes before the symbol — "-₹2,500", not "₹-2,500" — which
     * is how it is written and how compact() already rendered it. Business
     * profit goes negative whenever a month carries a big one-off purchase,
     * so this is a number partners really do see.
     */
    private fun signed(value: Double, decimals: Boolean): String {
        val body = "₹" + grouped(abs(value), decimals)
        return if (value < 0) "-$body" else body
    }

    /**
     * ₹1.2L / ₹12.5k — for chart axes and tiles, where six digits would
     * overflow the box. Falls back to the plain number when it is short
     * enough to read exactly, because an exact number always beats an
     * approximation that fits.
     */
    fun compact(value: Double): String {
        val v = abs(value)
        val sign = if (value < 0) "-" else ""
        return when {
            v >= 10_000_000 -> "$sign₹${trim(v / 10_000_000)}Cr"
            v >= 100_000    -> "$sign₹${trim(v / 100_000)}L"
            v >= 10_000     -> "$sign₹${trim(v / 1_000)}k"
            else            -> sign + "₹" + grouped(v, decimals = false)
        }
    }

    /** +12.4% / -3% — null renders as a dash, never as "0%". */
    fun percent(value: Double?): String =
        if (value == null) "—" else (if (value > 0) "+" else "") + trim(value) + "%"

    private fun trim(v: Double): String {
        val rounded = (v * 10).roundToLong() / 10.0
        return if (rounded == rounded.toLong().toDouble()) rounded.toLong().toString()
        else String.format(Locale.US, "%.1f", rounded)
    }

    /**
     * Indian digit grouping: the last three digits, then pairs.
     * 1234567.5 -> "12,34,567.50"
     */
    private fun grouped(value: Double, decimals: Boolean): String {
        val negative = value < 0
        val abs = abs(value)
        val whole = abs.toLong()
        val paise = ((abs - whole) * 100).roundToLong()

        // Rounding the paise can carry into the rupees (99.999 -> 100.00).
        val carried = if (paise == 100L) whole + 1 else whole
        val shownPaise = if (paise == 100L) 0L else paise

        val digits = carried.toString()
        val head = if (digits.length > 3) digits.dropLast(3) else ""
        val tail = if (digits.length > 3) digits.takeLast(3) else digits

        val grouped = buildString {
            if (head.isNotEmpty()) {
                // Walk the head in pairs from the right.
                val chunks = ArrayList<String>()
                var rest = head
                while (rest.length > 2) {
                    chunks.add(0, rest.takeLast(2))
                    rest = rest.dropLast(2)
                }
                if (rest.isNotEmpty()) chunks.add(0, rest)
                append(chunks.joinToString(","))
                append(",")
            }
            append(tail)
        }

        val body = if (decimals) "$grouped.${shownPaise.toString().padStart(2, '0')}" else grouped
        return if (negative) "-$body" else body
    }
}

/** Dates the app shows and the dates the API expects. */
object Dates {
    private val iso = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val isoDateTime = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
    private val dayMonth = SimpleDateFormat("d MMM", Locale.US)
    private val dayMonthYear = SimpleDateFormat("d MMM yyyy", Locale.US)
    private val dayTime = SimpleDateFormat("d MMM, h:mm a", Locale.US)

    fun today(): String = iso.format(Date())

    fun isoOf(cal: Calendar): String = iso.format(cal.time)

    fun startOfMonth(): String {
        val c = Calendar.getInstance()
        c.set(Calendar.DAY_OF_MONTH, 1)
        return isoOf(c)
    }

    fun daysAgo(days: Int): String {
        val c = Calendar.getInstance()
        c.add(Calendar.DAY_OF_YEAR, -days)
        return isoOf(c)
    }

    /**
     * Parses the API's "2026-09-18 12:57:51" and renders it for a human.
     * An unparseable value is returned untouched rather than swallowed —
     * showing the raw string beats showing nothing when a server changes
     * format.
     */
    fun pretty(raw: String?, withTime: Boolean = false): String {
        if (raw.isNullOrBlank()) return "—"
        val parsed = runCatching { isoDateTime.parse(raw) }.getOrNull()
            ?: runCatching { iso.parse(raw) }.getOrNull()
            ?: return raw
        return if (withTime) dayTime.format(parsed) else dayMonthYear.format(parsed)
    }

    fun prettyShort(raw: String?): String {
        if (raw.isNullOrBlank()) return "—"
        val parsed = runCatching { isoDateTime.parse(raw) }.getOrNull()
            ?: runCatching { iso.parse(raw) }.getOrNull()
            ?: return raw
        return dayMonth.format(parsed)
    }

    /** "Today" / "Yesterday" / "12 Sep" — list headers a partner scans. */
    fun relativeDay(raw: String?): String {
        if (raw.isNullOrBlank()) return "—"
        val date = runCatching { isoDateTime.parse(raw) }.getOrNull()
            ?: runCatching { iso.parse(raw) }.getOrNull() ?: return raw
        val then = Calendar.getInstance().apply { time = date }
        val now = Calendar.getInstance()
        val sameYear = then.get(Calendar.YEAR) == now.get(Calendar.YEAR)
        val dayDiff = now.get(Calendar.DAY_OF_YEAR) - then.get(Calendar.DAY_OF_YEAR)
        return when {
            sameYear && dayDiff == 0 -> "Today"
            sameYear && dayDiff == 1 -> "Yesterday"
            sameYear -> dayMonth.format(date)
            else -> dayMonthYear.format(date)
        }
    }
}
