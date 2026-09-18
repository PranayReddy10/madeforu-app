package com.madeforu.sales.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage

/**
 * The tinted rounded square that fronts a list row — a photo when there is
 * one, an icon when the row has a natural one, initials otherwise.
 *
 * The colour is derived from the label rather than assigned, so "Raw
 * material" is the same colour on every screen and every phone, and a new
 * category gets a sensible colour without anyone picking one. Rows without
 * a tile look unfinished next to rows with one, which is why there is
 * always a fallback rather than an empty space.
 */
@Composable
fun IconTile(
    label: String,
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    imageUrl: String = "",
    size: Dp = 44.dp,
    tint: Color? = null,
) {
    val shape = RoundedCornerShape(percent = 30)
    val colour = tint ?: tileColour(label)

    Box(
        modifier
            .size(size)
            .clip(shape)
            .background(colour.copy(alpha = 0.18f)),
        contentAlignment = Alignment.Center,
    ) {
        when {
            imageUrl.isNotBlank() -> AsyncImage(
                model = imageUrl,
                contentDescription = label,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(size).clip(shape),
            )

            icon != null -> Icon(
                icon,
                contentDescription = label,
                tint = colour,
                modifier = Modifier.size(size * 0.5f),
            )

            else -> Text(
                initials(label),
                style = MaterialTheme.typography.labelLarge,
                fontWeight = FontWeight.Bold,
                color = colour,
            )
        }
    }
}

/**
 * A stable colour per label. The hash runs over the whole string so two
 * similar names ("Round Magnet", "Square Magnet") land on different
 * colours rather than colliding on a shared prefix.
 *
 * It is widened to Long before the sign is stripped. An Int hash can be
 * Int.MIN_VALUE, and negating that in Int arithmetic gives Int.MIN_VALUE
 * back — still negative, and a negative modulo would index off the front
 * of the palette.
 */
fun tileColour(label: String): Color {
    if (label.isBlank()) return TilePalette[0]
    val hash = label.lowercase().fold(7) { acc, ch -> acc * 31 + ch.code }
    val index = (hash.toLong().let { if (it < 0) -it else it } % TilePalette.size).toInt()
    return TilePalette[index]
}

/**
 * Chosen to stay legible at 18% opacity behind an icon in light mode and
 * as a solid tint in dark — the two places these are actually used.
 */
private val TilePalette = listOf(
    Color(0xFF7A3DF5),   // violet, the brand
    Color(0xFF00A0A8),   // teal
    Color(0xFFE8833A),   // amber
    Color(0xFF2E8B57),   // green
    Color(0xFFC2185B),   // magenta
    Color(0xFF5C6BC0),   // indigo
    Color(0xFF00897B),   // deep teal
    Color(0xFF8D6E63),   // clay
)

private fun initials(name: String): String {
    val words = name.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }
    return when {
        words.isEmpty() -> "?"
        words.size == 1 -> words[0].take(2).uppercase()
        else -> (words[0].take(1) + words[1].take(1)).uppercase()
    }
}
