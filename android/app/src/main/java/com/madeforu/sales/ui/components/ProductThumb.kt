package com.madeforu.sales.ui.components

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

/**
 * A product's photo, or its initials on a tinted tile when there is none.
 *
 * Most products have no image_url yet — it is filled in per product on the
 * website's Products page — so the fallback is the normal case, not an
 * error state.
 */
@Composable
fun ProductThumb(
    name: String,
    imageUrl: String,
    modifier: Modifier = Modifier,
    size: Dp = 44.dp,
) = IconTile(label = name, imageUrl = imageUrl, modifier = modifier, size = size)
