package de.minicloud.files.ui.screens

import android.Manifest
import android.os.Build
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import de.minicloud.files.data.PreferencesManager
import de.minicloud.files.ui.theme.*
import de.minicloud.files.worker.CameraUploadWorker
import java.text.SimpleDateFormat
import java.util.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CameraSyncScreen(
    prefs: PreferencesManager,
    onNavigateBack: () -> Unit
) {
    val context = LocalContext.current
    var autoUpload by remember { mutableStateOf(prefs.autoUploadEnabled) }
    var wifiOnly by remember { mutableStateOf(prefs.wifiOnly) }
    var chargingOnly by remember { mutableStateOf(prefs.chargingOnly) }
    var lastSyncTs by remember { mutableStateOf(prefs.lastSyncTimestamp) }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val granted = permissions.values.all { it }
        if (granted) {
            prefs.autoUploadEnabled = true
            autoUpload = true
            CameraUploadWorker.schedulePeriodicSync(context, prefs)
            Toast.makeText(context, "Kamera-Synchronisation aktiviert", Toast.LENGTH_SHORT).show()
        } else {
            Toast.makeText(context, "Berechtigung für Fotos/Videos verweigert.", Toast.LENGTH_LONG).show()
            prefs.autoUploadEnabled = false
            autoUpload = false
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Kamera-Upload", fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    IconButton(onClick = onNavigateBack) {
                        Icon(Icons.Default.ArrowBack, contentDescription = "Zurück", tint = Slate200)
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Slate900)
            )
        },
        containerColor = Slate900
    ) { paddingValues ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(paddingValues)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            // Infokarte
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = Slate800)
            ) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(
                        imageVector = Icons.Default.CloudSync,
                        contentDescription = null,
                        tint = Blue500,
                        modifier = Modifier.size(36.dp)
                    )
                    Spacer(modifier = Modifier.width(14.dp))
                    Column {
                        Text(
                            text = "Automatische Sicherung",
                            fontWeight = FontWeight.Bold,
                            fontSize = 15.sp,
                            color = White
                        )
                        Spacer(modifier = Modifier.height(2.dp))
                        Text(
                            text = "Fotos und Videos werden vollautomatisch im Hintergrund nach Kamera/JJJJ/MM in Ihre MiniCloud gesichert.",
                            fontSize = 12.sp,
                            color = Slate400
                        )
                    }
                }
            }

            // Einstellungen Karte
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = Slate800)
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    // Hauptschalter
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                text = "Kamera-Upload aktivieren",
                                fontSize = 15.sp,
                                fontWeight = FontWeight.SemiBold,
                                color = White
                            )
                            Text(
                                text = "Regelmäßig im Hintergrund synchronisieren",
                                fontSize = 12.sp,
                                color = Slate400
                            )
                        }
                        Switch(
                            checked = autoUpload,
                            onCheckedChange = { isChecked ->
                                if (isChecked) {
                                    val permissions = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                                        arrayOf(
                                            Manifest.permission.READ_MEDIA_IMAGES,
                                            Manifest.permission.READ_MEDIA_VIDEO,
                                            Manifest.permission.POST_NOTIFICATIONS
                                        )
                                    } else {
                                        arrayOf(Manifest.permission.READ_EXTERNAL_STORAGE)
                                    }
                                    permissionLauncher.launch(permissions)
                                } else {
                                    prefs.autoUploadEnabled = false
                                    autoUpload = false
                                    CameraUploadWorker.schedulePeriodicSync(context, prefs)
                                }
                            },
                            colors = SwitchDefaults.colors(
                                checkedThumbColor = White,
                                checkedTrackColor = Blue600,
                                uncheckedThumbColor = Slate400,
                                uncheckedTrackColor = Slate700
                            )
                        )
                    }

                    Divider(color = Slate700, modifier = Modifier.padding(vertical = 12.dp))

                    // Nur WLAN
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(text = "Nur im WLAN hochladen", fontSize = 14.sp, color = White)
                            Text(text = "Schont Ihr mobiles Datenvolumen", fontSize = 11.sp, color = Slate400)
                        }
                        Switch(
                            checked = wifiOnly,
                            onCheckedChange = {
                                wifiOnly = it
                                prefs.wifiOnly = it
                                CameraUploadWorker.schedulePeriodicSync(context, prefs)
                            },
                            enabled = autoUpload,
                            colors = SwitchDefaults.colors(
                                checkedThumbColor = White,
                                checkedTrackColor = Blue600,
                                uncheckedThumbColor = Slate400,
                                uncheckedTrackColor = Slate700
                            )
                        )
                    }

                    Spacer(modifier = Modifier.height(10.dp))

                    // Nur beim Laden
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(text = "Nur beim Laden synchronisieren", fontSize = 14.sp, color = White)
                            Text(text = "Spart Smartphone-Akkulaufzeit", fontSize = 11.sp, color = Slate400)
                        }
                        Switch(
                            checked = chargingOnly,
                            onCheckedChange = {
                                chargingOnly = it
                                prefs.chargingOnly = it
                                CameraUploadWorker.schedulePeriodicSync(context, prefs)
                            },
                            enabled = autoUpload,
                            colors = SwitchDefaults.colors(
                                checkedThumbColor = White,
                                checkedTrackColor = Blue600,
                                uncheckedThumbColor = Slate400,
                                uncheckedTrackColor = Slate700
                            )
                        )
                    }
                }
            }

            // Status & Manueller Trigger
            Card(
                shape = RoundedCornerShape(14.dp),
                colors = CardDefaults.cardColors(containerColor = Slate800)
            ) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(
                        text = "Synchronisations-Status",
                        fontSize = 14.sp,
                        fontWeight = FontWeight.SemiBold,
                        color = White
                    )
                    Spacer(modifier = Modifier.height(6.dp))
                    val dateStr = if (lastSyncTs > 0) {
                        SimpleDateFormat("dd.MM.yyyy HH:mm 'Uhr'", Locale.GERMANY).format(Date(lastSyncTs))
                    } else {
                        "Noch nicht ausgeführt"
                    }
                    Text(
                        text = "Zuletzt geprüft: $dateStr",
                        fontSize = 12.sp,
                        color = Slate400
                    )

                    Spacer(modifier = Modifier.height(16.dp))

                    Button(
                        onClick = {
                            CameraUploadWorker.runOneTimeSync(context)
                            Toast.makeText(context, "Kamera-Synchronisation im Hintergrund gestartet", Toast.LENGTH_SHORT).show()
                            lastSyncTs = System.currentTimeMillis()
                        },
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Blue600),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Icon(Icons.Default.Sync, contentDescription = null, tint = White)
                        Spacer(modifier = Modifier.width(8.dp))
                        Text("Jetzt sofort synchronisieren", color = White)
                    }
                }
            }
        }
    }
}
