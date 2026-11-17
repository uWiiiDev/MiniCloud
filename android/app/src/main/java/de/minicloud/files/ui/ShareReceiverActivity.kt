package de.minicloud.files.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.OpenableColumns
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import de.minicloud.files.api.MiniCloudApi
import de.minicloud.files.data.PreferencesManager
import de.minicloud.files.ui.theme.*
import kotlinx.coroutines.launch

class ShareReceiverActivity : ComponentActivity() {

    private lateinit var prefs: PreferencesManager
    private lateinit var api: MiniCloudApi

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = PreferencesManager(this)
        api = MiniCloudApi(prefs)

        if (!prefs.isLoggedIn()) {
            Toast.makeText(this, "Bitte melden Sie sich zuerst in der MiniCloud-App an.", Toast.LENGTH_LONG).show()
            startActivity(Intent(this, MainActivity::class.java))
            finish()
            return
        }

        val incomingUris = mutableListOf<Uri>()
        when (intent?.action) {
            Intent.ACTION_SEND -> {
                (intent.getParcelableExtra<Uri>(Intent.EXTRA_STREAM))?.let {
                    incomingUris.add(it)
                }
            }
            Intent.ACTION_SEND_MULTIPLE -> {
                intent.getParcelableArrayListExtra<Uri>(Intent.EXTRA_STREAM)?.let {
                    incomingUris.addAll(it)
                }
            }
        }

        if (incomingUris.isEmpty()) {
            Toast.makeText(this, "Keine Dateien zum Teilen gefunden.", Toast.LENGTH_SHORT).show()
            finish()
            return
        }

        setContent {
            MiniCloudTheme {
                ShareDialog(
                    fileCount = incomingUris.size,
                    onConfirm = { targetFolder ->
                        uploadSharedFiles(incomingUris, targetFolder)
                    },
                    onDismiss = { finish() }
                )
            }
        }
    }

    private fun uploadSharedFiles(uris: List<Uri>, targetFolder: String) {
        val coroutineScope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.Main)
        coroutineScope.launch {
            Toast.makeText(this@ShareReceiverActivity, "Lade ${uris.size} Datei(en) in MiniCloud hoch...", Toast.LENGTH_SHORT).show()
            var successCount = 0
            for (uri in uris) {
                try {
                    val name = getFileName(uri) ?: "geteilt_${System.currentTimeMillis()}"
                    val mime = contentResolver.getType(uri) ?: "application/octet-stream"
                    val bytes = contentResolver.openInputStream(uri)?.use { it.readBytes() }
                    if (bytes != null) {
                        val res = api.uploadFile(bytes, name, targetFolder, mime)
                        if (res.isSuccess) successCount++
                    }
                } catch (e: Exception) {
                    // ignore
                }
            }
            Toast.makeText(this@ShareReceiverActivity, "$successCount von ${uris.size} erfolgreich hochgeladen!", Toast.LENGTH_LONG).show()
            finish()
        }
    }

    private fun getFileName(uri: Uri): String? {
        return try {
            contentResolver.query(uri, null, null, null, null)?.use { cursor ->
                val nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (nameIndex != -1 && cursor.moveToFirst()) {
                    cursor.getString(nameIndex)
                } else null
            }
        } catch (e: Exception) {
            null
        }
    }
}

@Composable
fun ShareDialog(
    fileCount: Int,
    onConfirm: (targetFolder: String) -> Unit,
    onDismiss: () -> Unit
) {
    var folderName by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Default.CloudUpload, contentDescription = null, tint = Blue500)
                Spacer(modifier = Modifier.width(8.dp))
                Text("In MiniCloud sichern", fontWeight = FontWeight.Bold)
            }
        },
        text = {
            Column {
                Text(
                    text = "$fileCount Datei(en) empfangen.",
                    color = White,
                    fontSize = 14.sp
                )
                Spacer(modifier = Modifier.height(12.dp))
                OutlinedTextField(
                    value = folderName,
                    onValueChange = { folderName = it },
                    label = { Text("Zielordner (optional)") },
                    placeholder = { Text("z.B. Geteilt oder leer lassen") },
                    singleLine = true,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Blue500,
                        unfocusedBorderColor = Slate700,
                        focusedTextColor = White,
                        unfocusedTextColor = Slate200
                    )
                )
            }
        },
        confirmButton = {
            Button(
                onClick = { onConfirm(folderName.trim()) },
                colors = ButtonDefaults.buttonColors(containerColor = Blue600)
            ) {
                Text("Hochladen", color = White)
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("Abbrechen", color = Slate400)
            }
        },
        containerColor = Slate800,
        titleContentColor = White,
        textContentColor = Slate200
    )
}
