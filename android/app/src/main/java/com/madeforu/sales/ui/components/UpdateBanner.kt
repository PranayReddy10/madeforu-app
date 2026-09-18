package com.madeforu.sales.ui.components

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.SystemUpdate
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.madeforu.sales.BuildConfig
import com.madeforu.sales.core.ApiResult
import com.madeforu.sales.data.Release
import com.madeforu.sales.data.Repository

/**
 * Tells a partner when a newer build has been published, and links to it.
 *
 * There is no Play Store here — the APK is shared directly — so without
 * this a partner has no way of knowing their copy is stale, and four
 * people end up on four different versions of the order form.
 *
 * The check is deliberately quiet: it never blocks the screen, it can be
 * dismissed, and a failed check (no network, nothing published yet) shows
 * nothing at all. Downloading opens the link in the browser rather than
 * installing silently, because installing an APK is the user's decision
 * and Android will ask them to confirm it anyway.
 */
@Composable
fun UpdateBanner(repository: Repository, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    var release by remember { mutableStateOf<Release?>(null) }
    var dismissed by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        val result = repository.release()
        if (result is ApiResult.Success) {
            val candidate = result.value
            // Only a strictly higher code counts. Equal means this is the
            // current build; lower means someone published a rollback and
            // nagging about it would be wrong.
            if (candidate.versionCode > BuildConfig.VERSION_CODE && candidate.apkUrl.isNotBlank()) {
                release = candidate
            }
        }
    }

    val update = release
    AnimatedVisibility(visible = update != null && !dismissed, modifier = modifier) {
        if (update != null) {
            Card(
                shape = RoundedCornerShape(18.dp),
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.tertiaryContainer,
                ),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            Icons.Filled.SystemUpdate,
                            contentDescription = null,
                            modifier = Modifier.size(20.dp),
                        )
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                "Update available" +
                                    (if (update.versionName.isNotBlank()) " · ${update.versionName}" else ""),
                                style = MaterialTheme.typography.titleSmall,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                "You are on ${BuildConfig.VERSION_NAME}",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                        IconButton(onClick = { dismissed = true }) {
                            Icon(Icons.Filled.Close, contentDescription = "Not now")
                        }
                    }
                    if (update.notes.isNotBlank()) {
                        Spacer(Modifier.height(6.dp))
                        Text(update.notes, style = MaterialTheme.typography.bodySmall)
                    }
                    Spacer(Modifier.height(10.dp))
                    Button(
                        onClick = { openLink(context, update.apkUrl) },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Download the new version") }
                }
            }
        }
    }
}

private fun openLink(context: Context, url: String) {
    runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    }.onFailure {
        Toast.makeText(context, "Could not open the download link.", Toast.LENGTH_SHORT).show()
    }
}
