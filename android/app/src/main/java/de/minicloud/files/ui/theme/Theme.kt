package de.minicloud.files.ui.theme

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val Blue600 = Color(0xFF2563EB)
val Blue500 = Color(0xFF3B82F6)
val Blue700 = Color(0xFF1D4ED8)
val Slate900 = Color(0xFF0F172A)
val Slate800 = Color(0xFF1E293B)
val Slate700 = Color(0xFF334155)
val Slate400 = Color(0xFF94A3B8)
val Slate200 = Color(0xFFE2E8F0)
val White = Color(0xFFFFFFFF)
val Red500 = Color(0xFFEF4444)
val Emerald500 = Color(0xFF10B981)

private val DarkColorScheme = darkColorScheme(
    primary = Blue500,
    onPrimary = White,
    primaryContainer = Blue700,
    onPrimaryContainer = White,
    secondary = Blue600,
    onSecondary = White,
    background = Slate900,
    onBackground = Slate200,
    surface = Slate800,
    onSurface = Slate200,
    surfaceVariant = Slate700,
    onSurfaceVariant = Slate400,
    error = Red500,
    onError = White
)

@Composable
fun MiniCloudTheme(
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = DarkColorScheme,
        content = content
    )
}
