package com.madeforu.sales.ui.components

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.core.Money
import androidx.compose.foundation.background
import kotlin.math.max

/**
 * Charts are drawn on a Canvas rather than pulled in from a library.
 *
 * Three reasons: the app ships one visual language and a chart library
 * brings its own; every chart here is simple enough that the drawing code
 * is shorter than the configuration would be; and an APK a partner
 * downloads over mobile data should not carry a rendering engine to draw
 * four shapes.
 *
 * A categorical palette used consistently: series 1 is always the same
 * colour on every screen, so "the purple one" means the same thing twice.
 */
object ChartPalette {
    val series = listOf(
        Color(0xFF7A3DF5),
        Color(0xFF00A0A8),
        Color(0xFFE8833A),
        Color(0xFF2E8B57),
        Color(0xFFC2185B),
        Color(0xFF5C6BC0),
        Color(0xFF8D6E63),
        Color(0xFF00897B),
    )

    fun at(index: Int): Color = series[index % series.size]
}

/**
 * Revenue over time. Draws a filled area under a smoothed line, with the
 * highest point marked — the peak is the thing anyone actually looks for.
 *
 * A single data point draws as a flat line rather than nothing: "one day
 * of sales" is a real answer to "how did this week go".
 */
@Composable
fun RevenueLineChart(
    values: List<Double>,
    labels: List<String>,
    modifier: Modifier = Modifier,
    lineColor: Color = MaterialTheme.colorScheme.primary,
    height: androidx.compose.ui.unit.Dp = 180.dp,
) {
    if (values.isEmpty()) {
        EmptyChart("No sales in this period", modifier)
        return
    }

    val maxValue = max(values.maxOrNull() ?: 0.0, 1.0)
    val gridColor = MaterialTheme.colorScheme.outline.copy(alpha = 0.18f)
    val labelColor = MaterialTheme.colorScheme.onSurfaceVariant
    val progress by animateFloatAsState(
        targetValue = 1f,
        animationSpec = tween(650),
        label = "line-grow",
    )

    Column(modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(height)) {
            Canvas(Modifier.fillMaxWidth().height(height)) {
                val w = size.width
                val h = size.height
                val bottom = h - 18f       // room for the baseline labels
                val stepX = if (values.size > 1) w / (values.size - 1) else 0f

                // Four horizontal guides. More turns the card into graph
                // paper; fewer makes the scale unreadable.
                repeat(4) { i ->
                    val y = bottom * i / 3f
                    drawLine(gridColor, Offset(0f, y), Offset(w, y), strokeWidth = 1f)
                }

                fun pointAt(index: Int): Offset {
                    val x = if (values.size > 1) index * stepX else w / 2f
                    val ratio = (values[index] / maxValue).toFloat().coerceIn(0f, 1f)
                    return Offset(x, bottom - bottom * ratio * progress)
                }

                val line = Path()
                val area = Path()
                values.indices.forEach { i ->
                    val p = pointAt(i)
                    if (i == 0) {
                        line.moveTo(p.x, p.y)
                        area.moveTo(p.x, bottom)
                        area.lineTo(p.x, p.y)
                    } else {
                        // A gentle cubic through the midpoint: enough to
                        // look considered, not enough to invent values
                        // between the real ones.
                        val prev = pointAt(i - 1)
                        val midX = (prev.x + p.x) / 2f
                        line.cubicTo(midX, prev.y, midX, p.y, p.x, p.y)
                        area.cubicTo(midX, prev.y, midX, p.y, p.x, p.y)
                    }
                }
                if (values.size == 1) {
                    val p = pointAt(0)
                    line.lineTo(w, p.y)
                    area.lineTo(w, p.y)
                }
                area.lineTo(if (values.size > 1) w else w, bottom)
                area.close()

                drawPath(
                    area,
                    brush = Brush.verticalGradient(
                        listOf(lineColor.copy(alpha = 0.32f), lineColor.copy(alpha = 0f)),
                        startY = 0f,
                        endY = bottom,
                    ),
                )
                drawPath(line, color = lineColor, style = Stroke(width = 3.5f, cap = StrokeCap.Round))

                // Mark the peak.
                val peakIndex = values.indexOf(values.maxOrNull())
                if (peakIndex >= 0) {
                    val p = pointAt(peakIndex)
                    drawCircle(lineColor, radius = 6f, center = p)
                    drawCircle(Color.White, radius = 2.5f, center = p)
                }
            }
        }

        if (labels.isNotEmpty()) {
            Row(
                Modifier.fillMaxWidth().padding(top = 4.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                // Only the ends and the middle: a label per point turns to
                // mush on a phone as soon as there are more than a week's.
                val shown = listOfNotNull(
                    labels.firstOrNull(),
                    labels.getOrNull(labels.size / 2).takeIf { labels.size > 2 },
                    labels.lastOrNull().takeIf { labels.size > 1 },
                )
                shown.forEach {
                    Text(it, style = MaterialTheme.typography.labelSmall, color = labelColor)
                }
            }
        }
    }
}

/** Horizontal bars: best for named things (products, partners, channels). */
@Composable
fun HorizontalBars(
    entries: List<Triple<String, Double, String>>,   // label, value, trailing text
    modifier: Modifier = Modifier,
    scaleMax: Double = entries.maxOfOrNull { it.second } ?: 1.0,
) {
    if (entries.isEmpty()) {
        EmptyChart("Nothing to compare yet", modifier)
        return
    }
    Column(modifier.fillMaxWidth()) {
        entries.forEachIndexed { index, (label, value, trailing) ->
            val fraction = (value / max(scaleMax, 0.0001)).toFloat().coerceIn(0f, 1f)
            val animated by animateFloatAsState(fraction, tween(500), label = "bar-$index")
            val color = ChartPalette.at(index)

            Column(Modifier.padding(vertical = 6.dp)) {
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text(label, style = MaterialTheme.typography.bodyMedium, maxLines = 1)
                    Text(
                        trailing,
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
                Spacer(Modifier.height(5.dp))
                Box(
                    Modifier
                        .fillMaxWidth()
                        .height(8.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.surfaceVariant),
                ) {
                    Box(
                        Modifier
                            .fillMaxWidth(animated)
                            .height(8.dp)
                            .clip(CircleShape)
                            .background(color),
                    )
                }
            }
        }
    }
}

/**
 * A donut for splits that sum to a whole (payment modes, channels,
 * expense categories) with the total in the middle, which is the number
 * people look for first.
 */
@Composable
fun DonutChart(
    slices: List<Pair<String, Double>>,
    centreLabel: String,
    centreValue: String,
    modifier: Modifier = Modifier,
    size: androidx.compose.ui.unit.Dp = 160.dp,
) {
    val total = slices.sumOf { it.second }
    if (slices.isEmpty() || total <= 0.0) {
        EmptyChart("Nothing recorded yet", modifier)
        return
    }
    val progress by animateFloatAsState(1f, tween(700), label = "donut")
    val trackColor = MaterialTheme.colorScheme.surfaceVariant

    Row(modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(size), contentAlignment = Alignment.Center) {
            Canvas(Modifier.size(size)) {
                val stroke = this.size.minDimension * 0.17f
                val inset = stroke / 2f
                val arcSize = Size(this.size.width - stroke, this.size.height - stroke)

                drawArc(
                    color = trackColor,
                    startAngle = 0f,
                    sweepAngle = 360f,
                    useCenter = false,
                    topLeft = Offset(inset, inset),
                    size = arcSize,
                    style = Stroke(width = stroke),
                )

                var start = -90f
                slices.forEachIndexed { index, (_, value) ->
                    val sweep = (value / total * 360.0).toFloat() * progress
                    drawArc(
                        color = ChartPalette.at(index),
                        startAngle = start + 1f,
                        // The 2° gap makes adjacent slices readable; tiny
                        // slices keep a sliver rather than vanishing.
                        sweepAngle = (sweep - 2f).coerceAtLeast(0.6f),
                        useCenter = false,
                        topLeft = Offset(inset, inset),
                        size = arcSize,
                        style = Stroke(width = stroke, cap = StrokeCap.Round),
                    )
                    start += sweep
                }
            }
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(
                    centreValue,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    centreLabel,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Spacer(Modifier.width(16.dp))

        Column(Modifier.weight(1f)) {
            slices.forEachIndexed { index, (label, value) ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 3.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(
                        Modifier
                            .size(10.dp)
                            .clip(CircleShape)
                            .background(ChartPalette.at(index)),
                    )
                    Spacer(Modifier.width(8.dp))
                    Text(
                        label,
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.weight(1f),
                        maxLines = 1,
                    )
                    Text(
                        Money.compact(value),
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }
    }
}

/** A compact 24-bar strip for "when do we sell?". */
@Composable
fun HourStrip(values: List<Int>, modifier: Modifier = Modifier) {
    if (values.isEmpty() || values.all { it == 0 }) {
        EmptyChart("No hourly pattern yet", modifier)
        return
    }
    val peak = max(values.maxOrNull() ?: 1, 1)
    val barColor = MaterialTheme.colorScheme.primary
    val labelColor = MaterialTheme.colorScheme.onSurfaceVariant

    Column(modifier.fillMaxWidth()) {
        Row(
            Modifier.fillMaxWidth().height(64.dp),
            verticalAlignment = Alignment.Bottom,
            horizontalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            values.forEach { v ->
                val fraction = (v.toFloat() / peak).coerceIn(0f, 1f)
                Box(
                    Modifier
                        .weight(1f)
                        // A busy hour and an empty one must not look the
                        // same, so an empty bar keeps a 3dp stub.
                        .height((3 + 58 * fraction).dp)
                        .clip(CircleShape)
                        .background(if (v == 0) barColor.copy(alpha = 0.15f) else barColor),
                )
            }
        }
        Row(
            Modifier.fillMaxWidth().padding(top = 4.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            listOf("12am", "6am", "12pm", "6pm", "11pm").forEach {
                Text(it, style = MaterialTheme.typography.labelSmall, color = labelColor)
            }
        }
    }
}

@Composable
private fun EmptyChart(message: String, modifier: Modifier = Modifier) {
    Box(
        modifier.fillMaxWidth().height(120.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
