@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.madeforu.sales.ui.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.ButtonColors
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CardColors
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CardElevation
import androidx.compose.material3.FloatingActionButtonDefaults
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.ProvideTextStyle
import androidx.compose.material3.SheetState
import androidx.compose.material3.TextFieldColors
import androidx.compose.material3.TopAppBarColors
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/*
 * The web app's widgets, for the Android app.
 *
 * Each function here has the name and the parameters of the Material
 * widget it stands in for, so a screen switches over by changing one
 * import line and nothing else: `androidx.compose.material3.Button`
 * becomes `com.madeforu.sales.ui.components.Button`. What changes is only
 * the look, taken from sale/app/app.css:
 *
 *   top bar     .head      no bar at all: a 22 bold title on the page colour
 *   text field  .field     label ABOVE a white box, 14 corners, hairline border,
 *                          2dp rose outline when focused
 *   button      .btn       solid rose, 16 corners, 48+ tall (Material: 40, a pill)
 *   ghost       .btn.ghost white with a soft shadow (Material: a bordered pill)
 *   card        .card      white, 20 corners, the soft navy shadow
 *   add button  .fab       solid rose pill, white text (Material: pale pink, dark text)
 *   sheet       .sheetwrap the page colour, 26 corners, a short grey grabber
 *   tab bar     .tabbar    white, 64 tall, a hairline on top, rose when picked
 *                          (Material: 80 tall, a tinted bar and a pill behind the icon)
 *
 * The Material originals are called by their full names inside this file.
 */

/** The shadow every web-app card has (--shadow): soft, wide and navy, not grey. */
private val ShadowTint = Color(0xFF202946)

fun Modifier.pwaShadow(shape: Shape, elevation: androidx.compose.ui.unit.Dp = 3.dp): Modifier =
    this.shadow(
        elevation = elevation,
        shape = shape,
        clip = false,
        ambientColor = ShadowTint.copy(alpha = 0.55f),
        spotColor = ShadowTint.copy(alpha = 0.55f),
    )

// ── Top bar (.head) ────────────────────────────────────────────────────

/**
 * The web app has no app bar, only a heading row on the page: a back
 * square, a 22 bold title and any buttons on the right. `colors` is
 * accepted so existing calls compile; the row always sits on the page
 * colour.
 */
@Composable
fun TopAppBar(
    title: @Composable () -> Unit,
    modifier: Modifier = Modifier,
    navigationIcon: (@Composable () -> Unit)? = null,
    actions: @Composable RowScope.() -> Unit = {},
    colors: TopAppBarColors? = null,
) {
    Row(
        modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.background)
            .windowInsetsPadding(WindowInsets.systemBars.only(WindowInsetsSides.Horizontal + WindowInsetsSides.Top))
            .padding(start = 4.dp, end = 8.dp, top = 12.dp, bottom = 10.dp)
            .heightIn(min = 40.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // BackButton brings its own 12 start / 4 end; with the row's 4 that
        // is the web's 16 gutter and 12 gap.
        if (navigationIcon != null) navigationIcon()
        Box(
            Modifier
                .weight(1f)
                .padding(start = if (navigationIcon != null) 8.dp else 12.dp, end = 4.dp),
        ) {
            CompositionLocalProvider(LocalContentColor provides MaterialTheme.colorScheme.onBackground) {
                ProvideTextStyle(MaterialTheme.typography.titleLarge) { title() }
            }
        }
        CompositionLocalProvider(LocalContentColor provides MaterialTheme.colorScheme.onSurfaceVariant) {
            Row(verticalAlignment = Alignment.CenterVertically, content = actions)
        }
    }
}

// ── Text field (label.field + input) ───────────────────────────────────

/** White box, hairline border, rose when focused: the web's input. */
@Composable
fun pwaFieldColors(): TextFieldColors {
    val c = MaterialTheme.colorScheme
    return OutlinedTextFieldDefaults.colors(
        focusedContainerColor = c.surface,
        unfocusedContainerColor = c.surface,
        disabledContainerColor = c.surface,
        errorContainerColor = c.surface,
        focusedBorderColor = c.primary,
        unfocusedBorderColor = c.outlineVariant,
        disabledBorderColor = c.outlineVariant,
        errorBorderColor = c.error,
        cursorColor = c.primary,
        focusedTextColor = c.onSurface,
        unfocusedTextColor = c.onSurface,
        focusedPlaceholderColor = c.onSurfaceVariant.copy(alpha = 0.7f),
        unfocusedPlaceholderColor = c.onSurfaceVariant.copy(alpha = 0.7f),
    )
}

/**
 * The label sits above the box in small muted text, as on the web,
 * instead of inside the border where Material floats it. Everything else
 * is passed straight through to Material's field.
 */
@Composable
fun OutlinedTextField(
    value: String,
    onValueChange: (String) -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    readOnly: Boolean = false,
    textStyle: TextStyle? = null,
    label: (@Composable () -> Unit)? = null,
    placeholder: (@Composable () -> Unit)? = null,
    leadingIcon: (@Composable () -> Unit)? = null,
    trailingIcon: (@Composable () -> Unit)? = null,
    prefix: (@Composable () -> Unit)? = null,
    suffix: (@Composable () -> Unit)? = null,
    supportingText: (@Composable () -> Unit)? = null,
    isError: Boolean = false,
    visualTransformation: VisualTransformation = VisualTransformation.None,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    keyboardActions: KeyboardActions = KeyboardActions.Default,
    singleLine: Boolean = false,
    maxLines: Int = if (singleLine) 1 else Int.MAX_VALUE,
    minLines: Int = 1,
    shape: Shape? = null,
    colors: TextFieldColors? = null,
) {
    // 16 so the text reads at the web input's size.
    val style = textStyle ?: MaterialTheme.typography.bodyLarge.copy(fontSize = 16.sp, lineHeight = 22.sp)
    val field: @Composable (Modifier) -> Unit = { m ->
        androidx.compose.material3.OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            modifier = m,
            enabled = enabled,
            readOnly = readOnly,
            textStyle = style,
            label = null,
            placeholder = placeholder,
            leadingIcon = leadingIcon,
            trailingIcon = trailingIcon,
            prefix = prefix,
            suffix = suffix,
            supportingText = supportingText,
            isError = isError,
            visualTransformation = visualTransformation,
            keyboardOptions = keyboardOptions,
            keyboardActions = keyboardActions,
            singleLine = singleLine,
            maxLines = maxLines,
            minLines = minLines,
            shape = shape ?: RoundedCornerShape(14.dp),
            colors = colors ?: pwaFieldColors(),
        )
    }
    if (label == null) {
        field(modifier)
        return
    }
    Column(modifier) {
        val labelColor = if (isError) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant
        ProvideTextStyle(MaterialTheme.typography.bodySmall.copy(color = labelColor)) {
            Box(Modifier.padding(start = 2.dp, bottom = 5.dp)) { label() }
        }
        field(Modifier.fillMaxWidth())
    }
}

// ── Buttons (.btn, .btn.ghost) ─────────────────────────────────────────

private val ButtonPadding = PaddingValues(horizontal = 20.dp, vertical = 13.dp)
private val ButtonShape = RoundedCornerShape(16.dp)

/** Solid rose; a disabled one fades, as the web's does, rather than turning grey. */
@Composable
fun pwaButtonColors(): ButtonColors {
    val c = MaterialTheme.colorScheme
    return ButtonDefaults.buttonColors(
        containerColor = c.primary,
        contentColor = c.onPrimary,
        disabledContainerColor = c.primary.copy(alpha = 0.45f),
        disabledContentColor = c.onPrimary.copy(alpha = 0.9f),
    )
}

@Composable
fun Button(
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    shape: Shape = ButtonShape,
    colors: ButtonColors? = null,
    contentPadding: PaddingValues = ButtonPadding,
    content: @Composable RowScope.() -> Unit,
) {
    androidx.compose.material3.Button(
        onClick = onClick,
        modifier = modifier.heightIn(min = 48.dp),
        enabled = enabled,
        shape = shape,
        colors = colors ?: pwaButtonColors(),
        elevation = null,
        contentPadding = contentPadding,
        content = content,
    )
}

/** The web's secondary button: white, lifted by a shadow, no outline. */
@Composable
fun OutlinedButton(
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    shape: Shape = ButtonShape,
    contentPadding: PaddingValues = ButtonPadding,
    content: @Composable RowScope.() -> Unit,
) {
    val c = MaterialTheme.colorScheme
    androidx.compose.material3.Button(
        onClick = onClick,
        modifier = modifier.heightIn(min = 48.dp).pwaShadow(shape, 2.dp),
        enabled = enabled,
        shape = shape,
        colors = ButtonDefaults.buttonColors(
            containerColor = c.surface,
            contentColor = c.onSurface,
            disabledContainerColor = c.surface,
            disabledContentColor = c.onSurface.copy(alpha = 0.4f),
        ),
        elevation = null,
        border = BorderStroke(1.dp, c.outlineVariant),
        contentPadding = contentPadding,
        content = content,
    )
}

// ── Card (.card) ───────────────────────────────────────────────────────

/**
 * White, 20 corners, the soft navy shadow. A card that passes its own
 * colours (the gradient hero, a tinted warning) keeps them; the shadow is
 * the web's whatever elevation the call asks for, so every card on a
 * screen sits at the same height, as on the web.
 */
@Composable
fun Card(
    modifier: Modifier = Modifier,
    shape: Shape = RoundedCornerShape(20.dp),
    colors: CardColors? = null,
    elevation: CardElevation? = null,
    border: BorderStroke? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    androidx.compose.material3.Card(
        modifier = modifier.pwaShadow(shape),
        shape = shape,
        colors = colors ?: softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
        border = border,
        content = content,
    )
}

@Composable
fun Card(
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    shape: Shape = RoundedCornerShape(20.dp),
    colors: CardColors? = null,
    elevation: CardElevation? = null,
    border: BorderStroke? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    androidx.compose.material3.Card(
        onClick = onClick,
        modifier = modifier.pwaShadow(shape),
        enabled = enabled,
        shape = shape,
        colors = colors ?: softCardColors(),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
        border = border,
        content = content,
    )
}

// ── Add button (.fab) ──────────────────────────────────────────────────

@Composable
fun ExtendedFloatingActionButton(
    text: @Composable () -> Unit,
    icon: @Composable () -> Unit,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    expanded: Boolean = true,
) {
    androidx.compose.material3.ExtendedFloatingActionButton(
        text = { ProvideTextStyle(MaterialTheme.typography.labelLarge) { text() } },
        icon = icon,
        onClick = onClick,
        modifier = modifier,
        expanded = expanded,
        shape = RoundedCornerShape(50),
        containerColor = MaterialTheme.colorScheme.primary,
        contentColor = MaterialTheme.colorScheme.onPrimary,
        elevation = FloatingActionButtonDefaults.elevation(defaultElevation = 6.dp, pressedElevation = 3.dp),
    )
}

// ── Sheet (.sheetwrap) ─────────────────────────────────────────────────

@Composable
fun ModalBottomSheet(
    onDismissRequest: () -> Unit,
    modifier: Modifier = Modifier,
    sheetState: SheetState = rememberModalBottomSheetState(),
    content: @Composable ColumnScope.() -> Unit,
) {
    androidx.compose.material3.ModalBottomSheet(
        onDismissRequest = onDismissRequest,
        modifier = modifier,
        sheetState = sheetState,
        shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
        containerColor = MaterialTheme.colorScheme.background,
        contentColor = MaterialTheme.colorScheme.onBackground,
        tonalElevation = 0.dp,
        scrimColor = Color(0x800F0C12),
        dragHandle = {
            Box(
                Modifier
                    .padding(top = 10.dp, bottom = 8.dp)
                    .size(width = 40.dp, height = 4.dp)
                    .background(MaterialTheme.colorScheme.outlineVariant, RoundedCornerShape(50)),
            )
        },
        content = content,
    )
}

// ── Tab bar (.tabbar) ──────────────────────────────────────────────────

@Composable
fun TabBar(modifier: Modifier = Modifier, content: @Composable RowScope.() -> Unit) {
    Column(modifier.fillMaxWidth().background(MaterialTheme.colorScheme.surface)) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(MaterialTheme.colorScheme.outlineVariant))
        Row(
            Modifier
                .fillMaxWidth()
                .windowInsetsPadding(WindowInsets.navigationBars)
                .height(64.dp),
            horizontalArrangement = Arrangement.SpaceEvenly,
            verticalAlignment = Alignment.CenterVertically,
            content = content,
        )
    }
}

/** An icon over a 10sp label; rose and bolder when it is the current tab. */
@Composable
fun RowScope.TabBarItem(
    selected: Boolean,
    onClick: () -> Unit,
    icon: @Composable () -> Unit,
    label: String,
) {
    val tint = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant
    Column(
        Modifier
            .weight(1f)
            .fillMaxHeight()
            .selectable(selected = selected, role = Role.Tab, onClick = onClick),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        CompositionLocalProvider(LocalContentColor provides tint) {
            Box(Modifier.size(24.dp), contentAlignment = Alignment.Center) { icon() }
            Spacer(Modifier.height(3.dp))
            androidx.compose.material3.Text(
                label,
                fontSize = 10.sp,
                lineHeight = 13.sp,
                fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                color = tint,
                maxLines = 1,
            )
        }
    }
}
