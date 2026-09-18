package com.madeforu.sales.ui.theme

import android.app.Activity
import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat

/**
 * The MadeForU palette.
 *
 * Violet is the brand; everything else is chosen for meaning rather than
 * decoration, and each pair is checked to stay legible on its own surface
 * in both themes:
 *   money in  -> green, money out -> red, waiting on someone -> amber.
 * The same three colours mean the same three things on every screen, so a
 * partner learns them once.
 */
private val BrandLight = Color(0xFF6B2FE0)
private val BrandDark = Color(0xFFCBB2FF)

val PositiveLight = Color(0xFF166534)
val PositiveDark = Color(0xFF6EE7A8)
val NegativeLight = Color(0xFFB3261E)
val NegativeDark = Color(0xFFFFB4AB)
val WarnLight = Color(0xFF9A6300)
val WarnDark = Color(0xFFFFC96B)

private val LightColors = lightColorScheme(
    primary = BrandLight,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFEADDFF),
    onPrimaryContainer = Color(0xFF23005C),
    secondary = Color(0xFF00696E),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFF9EF0F6),
    onSecondaryContainer = Color(0xFF002022),
    tertiary = Color(0xFF984061),
    onTertiary = Color.White,
    tertiaryContainer = Color(0xFFFFD9E2),
    onTertiaryContainer = Color(0xFF3E001D),
    // The background is a shade cooler than the cards on purpose: a white
    // card on a white page has to be drawn with a border to read as a
    // card, and borders everywhere make a screen look like a form.
    background = Color(0xFFF3F3F7),
    onBackground = Color(0xFF1C1B1F),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF1C1B1F),
    surfaceVariant = Color(0xFFE7E0EB),
    onSurfaceVariant = Color(0xFF49454E),
    outline = Color(0xFF7A757F),
    error = NegativeLight,
    onError = Color.White,
    errorContainer = Color(0xFFFFDAD6),
    onErrorContainer = Color(0xFF410002),
)

private val DarkColors = darkColorScheme(
    primary = BrandDark,
    onPrimary = Color(0xFF3A0094),
    primaryContainer = Color(0xFF5218CC),
    onPrimaryContainer = Color(0xFFEADDFF),
    secondary = Color(0xFF82D3D9),
    onSecondary = Color(0xFF00363A),
    secondaryContainer = Color(0xFF004F53),
    onSecondaryContainer = Color(0xFF9EF0F6),
    tertiary = Color(0xFFFFB0C8),
    onTertiary = Color(0xFF5E1133),
    tertiaryContainer = Color(0xFF7B2949),
    onTertiaryContainer = Color(0xFFFFD9E2),
    background = Color(0xFF0F0E13),
    onBackground = Color(0xFFE6E1E6),
    surface = Color(0xFF1A1820),
    onSurface = Color(0xFFE6E1E6),
    surfaceVariant = Color(0xFF49454E),
    onSurfaceVariant = Color(0xFFCAC4CF),
    outline = Color(0xFF948F99),
    error = NegativeDark,
    onError = Color(0xFF690005),
    errorContainer = Color(0xFF93000A),
    onErrorContainer = Color(0xFFFFDAD6),
)

/** Green for money coming in, red for money going out — theme-aware. */
@Composable
fun positiveColor(): Color = if (isDarkNow()) PositiveDark else PositiveLight

@Composable
fun negativeColor(): Color = if (isDarkNow()) NegativeDark else NegativeLight

@Composable
fun warnColor(): Color = if (isDarkNow()) WarnDark else WarnLight

@Composable
private fun isDarkNow(): Boolean = MaterialTheme.colorScheme.background.luminance() < 0.5f

private fun Color.luminance(): Float = (0.299f * red + 0.587f * green + 0.114f * blue)

private val AppTypography = Typography(
    // The hero number on a card is the thing people look at first, so it
    // is sized to be read at arm's length across a stall table.
    displaySmall = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Bold,
        fontSize = 38.sp, lineHeight = 44.sp, letterSpacing = (-1).sp,
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Bold,
        fontSize = 26.sp, lineHeight = 32.sp, letterSpacing = (-0.4).sp,
    ),
    headlineSmall = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold,
        fontSize = 22.sp, lineHeight = 28.sp, letterSpacing = (-0.2).sp,
    ),
    titleLarge = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold,
        fontSize = 19.sp, lineHeight = 26.sp,
    ),
    titleMedium = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold,
        fontSize = 16.sp, lineHeight = 22.sp,
    ),
    bodyLarge = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Normal,
        fontSize = 15.sp, lineHeight = 22.sp,
    ),
    bodyMedium = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Normal,
        fontSize = 14.sp, lineHeight = 20.sp,
    ),
    bodySmall = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Normal,
        fontSize = 12.sp, lineHeight = 16.sp,
    ),
    labelLarge = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.SemiBold,
        fontSize = 14.sp, letterSpacing = 0.1.sp,
    ),
    labelSmall = TextStyle(
        fontFamily = FontFamily.SansSerif, fontWeight = FontWeight.Medium,
        fontSize = 11.sp, letterSpacing = 0.5.sp,
    ),
)

/**
 * @param themeChoice "system", "light" or "dark".
 * Dynamic colour is deliberately NOT used: the bills, the brand and the
 * app should look the same on every partner's phone, and a wallpaper-
 * derived palette would make two partners' screens disagree about what
 * "the app colour" is.
 */
@Composable
fun MadeForUTheme(themeChoice: String = "system", content: @Composable () -> Unit) {
    val dark = when (themeChoice) {
        "dark" -> true
        "light" -> false
        else -> isSystemInDarkTheme()
    }
    val colors = if (dark) DarkColors else LightColors

    val view = LocalView.current
    if (!view.isInEditMode) {
        val context = LocalContext.current
        SideEffect {
            val window = (context as? Activity)?.window ?: return@SideEffect
            // Draw behind the bars and flip the icon colour to match, so the
            // status bar never becomes a grey stripe in dark mode.
            WindowCompat.setDecorFitsSystemWindows(window, false)
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = !dark
            WindowCompat.getInsetsController(window, view).isAppearanceLightNavigationBars = !dark
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.VANILLA_ICE_CREAM) {
                @Suppress("DEPRECATION")
                window.statusBarColor = android.graphics.Color.TRANSPARENT
            }
        }
    }

    MaterialTheme(colorScheme = colors, typography = AppTypography, content = content)
}
