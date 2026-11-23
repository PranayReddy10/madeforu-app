package com.madeforu.sales.ui.theme

import android.app.Activity
import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
import com.madeforu.sales.R

/**
 * The MadeForU palette, taken from the logo: the rose of the gift mark and
 * the navy of the wordmark. Sampled from the artwork rather than guessed,
 * so the app, the bills and the printed logo are the same two colours.
 */
private val Rose = Color(0xFFF54A77)          // the gift box and "foru"
private val RoseDeep = Color(0xFFC81F52)      // pressed states, text on light rose
private val RoseSoft = Color(0xFFFFD9E2)      // containers in light mode
private val Navy = Color(0xFF202946)          // the "made" wordmark

val PositiveLight = Color(0xFF127A4B)
val PositiveDark = Color(0xFF6EE7A8)
val NegativeLight = Color(0xFFB3261E)
val NegativeDark = Color(0xFFFFB4AB)
val WarnLight = Color(0xFF9A6300)
val WarnDark = Color(0xFFFFC96B)

/** The web app's --line: every hairline, field border and divider. */
private val LineLight = Color(0xFFE7E1E8)
private val LineDark = Color(0xFF2C3142)

/** The hero gradient: the logo's rose falling into its navy. */
val BrandGradient = listOf(Rose, Color(0xFF8E2C63), Navy)

private val LightColors = lightColorScheme(
    primary = Rose,
    onPrimary = Color.White,
    primaryContainer = RoseSoft,
    onPrimaryContainer = Color(0xFF3E0018),
    secondary = Navy,
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFDCE2F2),
    onSecondaryContainer = Color(0xFF111A30),
    tertiary = Color(0xFF00696E),
    onTertiary = Color.White,
    tertiaryContainer = Color(0xFF9EF0F6),
    onTertiaryContainer = Color(0xFF002022),
    // A shade cooler than the cards: a white card on a white page has to be
    // drawn with a border to read as a card, and borders everywhere make a
    // screen look like a form.
    background = Color(0xFFF6F4F7),
    onBackground = Navy,
    surface = Color.White,
    onSurface = Navy,
    surfaceVariant = Color(0xFFEDE7EC),
    onSurfaceVariant = Color(0xFF5B5560),
    outline = Color(0xFF8C8490),
    outlineVariant = LineLight,
    error = NegativeLight,
    onError = Color.White,
    errorContainer = Color(0xFFFFDAD6),
    onErrorContainer = Color(0xFF410002),
    // Material draws its bars, sheets, menus and dialogs on these
    // "container" roles, which default to a lavender grey, and tints any
    // raised surface with the primary colour. Neither exists in the web
    // app — everything there is white on the page colour — so all of them
    // are the plain surface, and the tint is switched off.
    surfaceTint = Color.White,
    surfaceBright = Color.White,
    surfaceDim = Color(0xFFF6F4F7),
    surfaceContainerLowest = Color.White,
    surfaceContainerLow = Color.White,
    surfaceContainer = Color.White,
    surfaceContainerHigh = Color.White,
    surfaceContainerHighest = Color.White,
    // The toast (.toast): navy with white text.
    inverseSurface = Navy,
    inverseOnSurface = Color.White,
    inversePrimary = Color(0xFFFF9DB6),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFFFF9DB6),
    onPrimary = Color(0xFF5E1133),
    primaryContainer = RoseDeep,
    onPrimaryContainer = Color(0xFFFFD9E2),
    secondary = Color(0xFFB6C4E8),
    onSecondary = Color(0xFF1F2A44),
    secondaryContainer = Color(0xFF2E3A5C),
    onSecondaryContainer = Color(0xFFDCE2F2),
    tertiary = Color(0xFF82D3D9),
    onTertiary = Color(0xFF00363A),
    tertiaryContainer = Color(0xFF004F53),
    onTertiaryContainer = Color(0xFF9EF0F6),
    background = Color(0xFF12151F),
    onBackground = Color(0xFFE6E1E6),
    surface = Color(0xFF1B1F2C),
    onSurface = Color(0xFFE6E1E6),
    surfaceVariant = Color(0xFF3A3F4E),
    onSurfaceVariant = Color(0xFFC6C2CC),
    outline = Color(0xFF8F8A96),
    outlineVariant = LineDark,
    error = NegativeDark,
    onError = Color(0xFF690005),
    errorContainer = Color(0xFF93000A),
    onErrorContainer = Color(0xFFFFDAD6),
    surfaceTint = Color(0xFF1B1F2C),
    surfaceBright = Color(0xFF242938),
    surfaceDim = Color(0xFF12151F),
    surfaceContainerLowest = Color(0xFF12151F),
    surfaceContainerLow = Color(0xFF1B1F2C),
    surfaceContainer = Color(0xFF1B1F2C),
    surfaceContainerHigh = Color(0xFF1B1F2C),
    surfaceContainerHighest = Color(0xFF1B1F2C),
    inverseSurface = Navy,
    inverseOnSurface = Color.White,
    inversePrimary = Rose,
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

/**
 * Roboto, bundled. The web app asks for Roboto by name and Chrome finds it
 * on every Android phone; the app used the phone's *default* font, which on
 * a Xiaomi is MiSans and on a Samsung is SamsungOne. That one difference
 * made the same screen read differently in the two apps. Five weights, the
 * five the stylesheet uses (400/500/600/700/800), cut from Roboto 3
 * (SIL Open Font License; assets/licenses/roboto-OFL.txt).
 */
val Roboto = FontFamily(
    Font(R.font.roboto_regular, FontWeight.Normal),
    Font(R.font.roboto_medium, FontWeight.Medium),
    Font(R.font.roboto_semibold, FontWeight.SemiBold),
    Font(R.font.roboto_bold, FontWeight.Bold),
    Font(R.font.roboto_extrabold, FontWeight.ExtraBold),
)

/**
 * Type sizes, taken from the web app's stylesheet so the two read alike:
 *
 *   hero figure 38 · page title 22 · stat figure 22 · section 15
 *   row title 15 · body 14 · secondary line 12 · caps label 11
 *
 * The app used to run a size larger almost everywhere (26 for a stat,
 * 16 for a row), which is what made its cards feel crowded next to the
 * web app's on the same phone.
 */
private val SizedTypography = Typography(
    // The hero number on a card is the thing people look at first, so it
    // is sized to be read at arm's length across a stall table.
    displaySmall = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.ExtraBold,
        fontSize = 38.sp, lineHeight = 44.sp, letterSpacing = (-1).sp,
    ),
    // A stat card's figure (.stat .v).
    headlineMedium = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Bold,
        fontSize = 22.sp, lineHeight = 28.sp, letterSpacing = (-0.4).sp,
    ),
    headlineSmall = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Bold,
        fontSize = 20.sp, lineHeight = 26.sp, letterSpacing = (-0.3).sp,
    ),
    // Page titles: the top bar uses this (.head h1).
    titleLarge = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Bold,
        fontSize = 22.sp, lineHeight = 28.sp, letterSpacing = (-0.3).sp,
    ),
    // Row titles and section headings (.row .t, h2.section).
    titleMedium = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.SemiBold,
        fontSize = 15.sp, lineHeight = 21.sp,
    ),
    titleSmall = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.SemiBold,
        fontSize = 14.sp, lineHeight = 20.sp,
    ),
    bodyLarge = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Normal,
        fontSize = 15.sp, lineHeight = 22.sp,
    ),
    bodyMedium = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Normal,
        fontSize = 14.sp, lineHeight = 20.sp,
    ),
    // The muted second line under a row title (.row .s).
    bodySmall = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Normal,
        fontSize = 12.sp, lineHeight = 17.sp,
    ),
    // Buttons (.btn is 16/600; 15 keeps two-word labels on one line).
    labelLarge = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.SemiBold,
        fontSize = 15.sp, lineHeight = 20.sp, letterSpacing = 0.sp,
    ),
    // Chips (.chip).
    labelMedium = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Medium,
        fontSize = 13.sp, lineHeight = 18.sp,
    ),
    // Caps labels over a figure (.stat .l) and pills.
    labelSmall = TextStyle(
        fontFamily = Roboto, fontWeight = FontWeight.Medium,
        fontSize = 11.sp, lineHeight = 15.sp, letterSpacing = 0.5.sp,
    ),
)

/**
 * The three styles not sized above keep Material's sizes but still need
 * the font: without it, anything using them falls back to the phone's.
 */
private val AppTypography = SizedTypography.copy(
    displayLarge = SizedTypography.displayLarge.copy(fontFamily = Roboto),
    displayMedium = SizedTypography.displayMedium.copy(fontFamily = Roboto),
    headlineLarge = SizedTypography.headlineLarge.copy(fontFamily = Roboto),
)

/**
 * Corner radii, also the web app's: 14 for fields, 16 for buttons, 20 for
 * cards, 26 for sheets and the hero. Material's defaults (4 for a text
 * field, 12 for a card) are what made the app look like a different
 * product from the web app.
 */
private val AppShapes = Shapes(
    extraSmall = RoundedCornerShape(14.dp),
    small = RoundedCornerShape(16.dp),
    medium = RoundedCornerShape(20.dp),
    large = RoundedCornerShape(26.dp),
    extraLarge = RoundedCornerShape(28.dp),
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

    MaterialTheme(colorScheme = colors, typography = AppTypography, shapes = AppShapes, content = content)
}
