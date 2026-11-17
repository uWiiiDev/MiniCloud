package de.minicloud.files.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.webkit.MimeTypeMap
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import coil.request.ImageRequest
import de.minicloud.files.api.MiniCloudApi
import de.minicloud.files.data.*
import de.minicloud.files.ui.theme.*
import kotlinx.coroutines.launch
import java.io.File

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FileBrowserScreen(
    prefs: PreferencesManager,
    api: MiniCloudApi,
    onNavigateCameraSync: () -> Unit,
    onLogout: () -> Unit
) {
    val context = LocalContext.current
    val coroutineScope = rememberCoroutineScope()

    var currentFolder by remember { mutableStateOf("") }
    var fileData by remember { mutableStateOf<FileListData?>(null) }
    var isLoading by remember { mutableStateOf(false) }
    var searchQuery by remember { mutableStateOf("") }
    var isSearching by remember { mutableStateOf(false) }

    // Dialog-Zustände
    var showCreateFolderDialog by remember { mutableStateOf(false) }
    var newFolderName by remember { mutableStateOf("") }

    var selectedFileForAction by remember { mutableStateOf<FileItem?>(null) }
    var showRenameDialog by remember { mutableStateOf(false) }
    var renameFileName by remember { mutableStateOf("") }

    fun loadCurrentFolder(targetFolder: String = currentFolder) {
        isLoading = true
        coroutineScope.launch {
            val result = api.fetchFiles(targetFolder)
            isLoading = false
            result.fold(
                onSuccess = { data ->
                    currentFolder = targetFolder
                    fileData = data
                },
                onFailure = { error ->
                    Toast.makeText(context, error.localizedMessage ?: "Ladefehler", Toast.LENGTH_SHORT).show()
                }
            )
        }
    }

    val parentFolder = if (currentFolder.contains('/')) currentFolder.substringBeforeLast('/') else ""
    val parentName = if (parentFolder.isEmpty()) "Hauptverzeichnis" else parentFolder.substringAfterLast('/')

    // System-Zurück-Taste / Geste navigiert zur übergeordneten Ebene
    BackHandler(enabled = currentFolder.isNotEmpty()) {
        loadCurrentFolder(parentFolder)
    }

    LaunchedEffect(Unit) {
        loadCurrentFolder("")
    }

    // Datei-Upload Launcher
    val uploadFileLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.GetMultipleContents()
    ) { uris: List<Uri> ->
        if (uris.isNotEmpty()) {
            coroutineScope.launch {
                var count = 0
                var lastError: String? = null
                for (uri in uris) {
                    try {
                        val fileName = queryFileName(context, uri) ?: "upload_${System.currentTimeMillis()}"
                        val mimeType = context.contentResolver.getType(uri) ?: "application/octet-stream"
                        val bytes = context.contentResolver.openInputStream(uri)?.use { it.readBytes() }
                        if (bytes != null) {
                            val upRes = api.uploadFile(bytes, fileName, currentFolder, mimeType)
                            upRes.fold(
                                onSuccess = { count++ },
                                onFailure = { lastError = it.localizedMessage }
                            )
                        } else {
                            lastError = "Datei konnte nicht gelesen werden"
                        }
                    } catch (e: Exception) {
                        lastError = e.localizedMessage
                    }
                }
                if (count > 0) {
                    Toast.makeText(context, "$count Datei(en) erfolgreich hochgeladen", Toast.LENGTH_SHORT).show()
                } else {
                    Toast.makeText(context, "Upload fehlgeschlagen: ${lastError ?: "Unbekannter Fehler"}", Toast.LENGTH_LONG).show()
                }
                loadCurrentFolder(currentFolder)
            }
        }
    }

    fun openDownloadedFile(fileItem: FileItem) {
        coroutineScope.launch {
            Toast.makeText(context, "Lade \"${fileItem.name}\" herunter...", Toast.LENGTH_SHORT).show()
            val tempDir = File(context.cacheDir, "downloads").apply { mkdirs() }
            val localFile = File(tempDir, fileItem.name)
            val downloadResult = api.downloadToFile(fileItem.id, localFile)

            downloadResult.fold(
                onSuccess = { f ->
                    try {
                        val uri = FileProvider.getUriForFile(
                            context,
                            "${context.packageName}.fileprovider",
                            f
                        )
                        val viewIntent = Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, fileItem.mimeType)
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        }
                        context.startActivity(viewIntent)
                    } catch (e: Exception) {
                        Toast.makeText(context, "Keine passende App zum Öffnen gefunden.", Toast.LENGTH_SHORT).show()
                    }
                },
                onFailure = { err ->
                    Toast.makeText(context, "Download fehlgeschlagen: ${err.localizedMessage}", Toast.LENGTH_SHORT).show()
                }
            )
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                navigationIcon = {
                    if (currentFolder.isNotEmpty()) {
                        IconButton(onClick = { loadCurrentFolder(parentFolder) }) {
                            Icon(
                                imageVector = Icons.Default.ArrowBack,
                                contentDescription = "Übergeordneter Ordner",
                                tint = White
                            )
                        }
                    }
                },
                title = {
                    if (isSearching) {
                        TextField(
                            value = searchQuery,
                            onValueChange = {
                                searchQuery = it
                                if (it.isNotBlank()) {
                                    coroutineScope.launch {
                                        val sRes = api.searchFiles(it)
                                        sRes.getOrNull()?.let { sFiles ->
                                            fileData = fileData?.copy(files = sFiles)
                                        }
                                    }
                                } else {
                                    loadCurrentFolder(currentFolder)
                                }
                            },
                            placeholder = { Text("Dateien suchen...") },
                            singleLine = true,
                            colors = TextFieldDefaults.colors(
                                focusedContainerColor = Color.Transparent,
                                unfocusedContainerColor = Color.Transparent,
                                focusedTextColor = White,
                                unfocusedTextColor = Slate200
                            )
                        )
                    } else {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.Cloud, contentDescription = null, tint = Blue500)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text(
                                text = if (currentFolder.isEmpty()) "MiniCloud" else currentFolder.substringAfterLast('/'),
                                fontWeight = FontWeight.Bold,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                    }
                },
                actions = {
                    IconButton(onClick = {
                        isSearching = !isSearching
                        if (!isSearching) {
                            searchQuery = ""
                            loadCurrentFolder(currentFolder)
                        }
                    }) {
                        Icon(
                            imageVector = if (isSearching) Icons.Default.Close else Icons.Default.Search,
                            contentDescription = "Suche",
                            tint = Slate200
                        )
                    }
                    IconButton(onClick = onNavigateCameraSync) {
                        Icon(Icons.Default.PhotoCamera, contentDescription = "Foto-Sync", tint = Slate200)
                    }
                    IconButton(onClick = { loadCurrentFolder(currentFolder) }) {
                        Icon(Icons.Default.Refresh, contentDescription = "Aktualisieren", tint = Slate200)
                    }
                    IconButton(onClick = onLogout) {
                        Icon(Icons.Default.Logout, contentDescription = "Abmelden", tint = Slate400)
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Slate900)
            )
        },
        floatingActionButton = {
            Column(horizontalAlignment = Alignment.End) {
                SmallFloatingActionButton(
                    onClick = { showCreateFolderDialog = true },
                    containerColor = Slate700,
                    contentColor = White,
                    modifier = Modifier.padding(bottom = 12.dp)
                ) {
                    Icon(Icons.Default.CreateNewFolder, contentDescription = "Neuer Ordner")
                }

                FloatingActionButton(
                    onClick = { uploadFileLauncher.launch("*/*") },
                    containerColor = Blue600,
                    contentColor = White
                ) {
                    Icon(Icons.Default.Upload, contentDescription = "Datei hochladen")
                }
            }
        },
        containerColor = Slate900
    ) { paddingValues ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(paddingValues)
        ) {
            // Speicher-Quota Balken
            fileData?.quota?.let { q ->
                Surface(
                    color = Slate800,
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Text(
                                text = "Speicherplatz",
                                fontSize = 11.sp,
                                color = Slate400,
                                fontWeight = FontWeight.Medium
                            )
                            Text(
                                text = "${q.usedFormatted} von ${q.quotaFormatted} (${q.percent}%)",
                                fontSize = 11.sp,
                                color = Slate400
                            )
                        }
                        Spacer(modifier = Modifier.height(4.dp))
                        LinearProgressIndicator(
                            progress = (q.percent / 100.0).toFloat().coerceIn(0f, 1f),
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(5.dp)
                                .clip(RoundedCornerShape(3.dp)),
                            color = if (q.percent > 90.0) Red500 else Blue500,
                            trackColor = Slate700
                        )
                    }
                }
            }

            // Breadcrumbs Navigation
            fileData?.breadcrumbs?.let { bcs ->
                if (bcs.size > 1) {
                    LazyRow(
                        modifier = Modifier
                            .fillMaxWidth()
                            .background(Slate800.copy(alpha = 0.6f))
                            .padding(horizontal = 12.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        items(bcs) { bc ->
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                modifier = Modifier.clickable { loadCurrentFolder(bc.path) }
                            ) {
                                Text(
                                    text = bc.name,
                                    fontSize = 13.sp,
                                    fontWeight = if (bc.path == currentFolder) FontWeight.Bold else FontWeight.Normal,
                                    color = if (bc.path == currentFolder) Blue500 else Slate400
                                )
                                if (bc != bcs.last()) {
                                    Icon(
                                        imageVector = Icons.Default.ChevronRight,
                                        contentDescription = null,
                                        tint = Slate700,
                                        modifier = Modifier.size(16.dp)
                                    )
                                }
                            }
                        }
                    }
                }
            }

            // Prominente Verlinkung für den übergeordneten Ordner (Eine Ebene nach oben)
            if (currentFolder.isNotEmpty()) {
                Surface(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 12.dp, vertical = 6.dp)
                        .clickable { loadCurrentFolder(parentFolder) },
                    shape = RoundedCornerShape(12.dp),
                    color = Slate800,
                    border = androidx.compose.foundation.BorderStroke(1.dp, Blue500.copy(alpha = 0.35f))
                ) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 14.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Surface(
                            shape = CircleShape,
                            color = Blue600.copy(alpha = 0.2f),
                            modifier = Modifier.size(34.dp)
                        ) {
                            Box(contentAlignment = Alignment.Center) {
                                Icon(
                                    imageVector = Icons.Default.ArrowUpward,
                                    contentDescription = null,
                                    tint = Blue500,
                                    modifier = Modifier.size(18.dp)
                                )
                            }
                        }
                        Spacer(modifier = Modifier.width(12.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(
                                text = "Eine Ebene nach oben",
                                color = White,
                                fontSize = 13.sp,
                                fontWeight = FontWeight.Bold
                            )
                            Text(
                                text = "Zurück zu: $parentName",
                                color = Slate400,
                                fontSize = 11.sp
                            )
                        }
                        Icon(
                            imageVector = Icons.Default.SubdirectoryArrowLeft,
                            contentDescription = null,
                            tint = Blue500,
                            modifier = Modifier.size(20.dp)
                        )
                    }
                }
            }

            // Unterordner & Dateien Liste
            if (isLoading) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Blue500)
                }
            } else {
                val folders = fileData?.folders.orEmpty()
                val files = fileData?.files.orEmpty()

                if (folders.isEmpty() && files.isEmpty()) {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(32.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Icon(
                                Icons.Default.FolderOpen,
                                contentDescription = null,
                                tint = Slate700,
                                modifier = Modifier.size(64.dp)
                            )
                            Spacer(modifier = Modifier.height(12.dp))
                            Text("Dieser Ordner ist leer.", color = Slate400, fontSize = 15.sp)
                        }
                    }
                } else {
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        // Große Kacheln für Ordner (2-Spalten-Raster)
                        if (folders.isNotEmpty()) {
                            item {
                                Text(
                                    text = "ORDNER (${folders.size})",
                                    fontSize = 11.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = Slate400,
                                    modifier = Modifier.padding(horizontal = 4.dp, vertical = 4.dp)
                                )
                            }

                            items(folders.chunked(2)) { pair ->
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    horizontalArrangement = Arrangement.spacedBy(10.dp)
                                ) {
                                    for (f in pair) {
                                        Card(
                                            modifier = Modifier
                                                .weight(1f)
                                                .height(115.dp)
                                                .clickable { loadCurrentFolder(f.path) },
                                            shape = RoundedCornerShape(16.dp),
                                            colors = CardDefaults.cardColors(containerColor = Slate800),
                                            border = androidx.compose.foundation.BorderStroke(1.dp, Slate700.copy(alpha = 0.6f))
                                        ) {
                                            Box(
                                                modifier = Modifier
                                                    .fillMaxSize()
                                                    .padding(horizontal = 4.dp, vertical = 6.dp),
                                                contentAlignment = Alignment.Center
                                            ) {
                                                // Großes gelbes Ordnersymbol fast so groß wie die Kachel
                                                Icon(
                                                    imageVector = Icons.Default.Folder,
                                                    contentDescription = null,
                                                    tint = Color(0xFFF59E0B),
                                                    modifier = Modifier.size(width = 132.dp, height = 98.dp)
                                                )

                                                // Beschriftung direkt im Ordnersymbol integriert
                                                Box(
                                                    modifier = Modifier
                                                        .width(104.dp)
                                                        .padding(top = 18.dp, start = 4.dp, end = 4.dp),
                                                    contentAlignment = Alignment.Center
                                                ) {
                                                    Text(
                                                        text = f.name,
                                                        color = Color(0xFF0F172A),
                                                        fontSize = 12.sp,
                                                        fontWeight = FontWeight.ExtraBold,
                                                        textAlign = TextAlign.Center,
                                                        maxLines = 2,
                                                        overflow = TextOverflow.Ellipsis,
                                                        lineHeight = 15.sp
                                                    )
                                                }
                                            }
                                        }
                                    }
                                    if (pair.size == 1) {
                                        Spacer(modifier = Modifier.weight(1f))
                                    }
                                }
                            }
                        }

                        // Dateien
                        if (files.isNotEmpty()) {
                            item {
                                Spacer(modifier = Modifier.height(4.dp))
                                Text(
                                    text = "DATEIEN (${files.size})",
                                    fontSize = 11.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = Slate400,
                                    modifier = Modifier.padding(horizontal = 4.dp, vertical = 4.dp)
                                )
                            }

                            items(files) { item ->
                                Card(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clickable { openDownloadedFile(item) },
                                    shape = RoundedCornerShape(10.dp),
                                    colors = CardDefaults.cardColors(containerColor = Slate800)
                                ) {
                                    Row(
                                        modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(10.dp),
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    // Thumbnail oder Icon
                                    if (item.thumbnailUrl != null) {
                                        AsyncImage(
                                            model = ImageRequest.Builder(context)
                                                .data(api.getAuthenticatedUrl(item.thumbnailUrl))
                                                .crossfade(true)
                                                .build(),
                                            contentDescription = item.name,
                                            contentScale = ContentScale.Crop,
                                            modifier = Modifier
                                                .size(44.dp)
                                                .clip(RoundedCornerShape(8.dp))
                                                .background(Slate700)
                                        )
                                    } else {
                                        Surface(
                                            modifier = Modifier.size(44.dp),
                                            shape = RoundedCornerShape(8.dp),
                                            color = Slate700
                                        ) {
                                            Box(contentAlignment = Alignment.Center) {
                                                Icon(
                                                    imageVector = getFileIcon(item),
                                                    contentDescription = null,
                                                    tint = getFileIconTint(item),
                                                    modifier = Modifier.size(24.dp)
                                                )
                                            }
                                        }
                                    }

                                    Spacer(modifier = Modifier.width(12.dp))

                                    Column(modifier = Modifier.weight(1f)) {
                                        Text(
                                            text = item.name,
                                            color = White,
                                            fontSize = 14.sp,
                                            fontWeight = FontWeight.Medium,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis
                                        )
                                        Spacer(modifier = Modifier.height(2.dp))
                                        Text(
                                            text = "${item.sizeFormatted} • ${item.uploadDate.take(10)}",
                                            color = Slate400,
                                            fontSize = 11.sp
                                        )
                                    }

                                    // Aktionen (Umbenennen / Löschen)
                                    IconButton(onClick = {
                                        selectedFileForAction = item
                                        renameFileName = item.name
                                        showRenameDialog = true
                                    }) {
                                        Icon(Icons.Default.MoreVert, contentDescription = "Optionen", tint = Slate400)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // Dialog: Neuer Ordner
    if (showCreateFolderDialog) {
        AlertDialog(
            onDismissRequest = { showCreateFolderDialog = false },
            title = { Text("Neuen Ordner anlegen") },
            text = {
                OutlinedTextField(
                    value = newFolderName,
                    onValueChange = { newFolderName = it },
                    label = { Text("Ordnername") },
                    singleLine = true,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Blue500,
                        unfocusedBorderColor = Slate700,
                        focusedTextColor = White,
                        unfocusedTextColor = Slate200
                    )
                )
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        val name = newFolderName.trim()
                        if (name.isNotEmpty()) {
                            coroutineScope.launch {
                                val res = api.createFolder(currentFolder, name)
                                res.fold(
                                    onSuccess = {
                                        showCreateFolderDialog = false
                                        newFolderName = ""
                                        loadCurrentFolder(currentFolder)
                                    },
                                    onFailure = {
                                        Toast.makeText(context, it.localizedMessage, Toast.LENGTH_SHORT).show()
                                    }
                                )
                            }
                        }
                    }
                ) {
                    Text("Erstellen", color = Blue500)
                }
            },
            dismissButton = {
                TextButton(onClick = { showCreateFolderDialog = false }) {
                    Text("Abbrechen", color = Slate400)
                }
            },
            containerColor = Slate800,
            titleContentColor = White,
            textContentColor = Slate200
        )
    }

    // Dialog: Dateiaktionen (Umbenennen & Löschen)
    if (showRenameDialog && selectedFileForAction != null) {
        val activeItem = selectedFileForAction!!
        AlertDialog(
            onDismissRequest = { showRenameDialog = false },
            title = { Text("Datei bearbeiten") },
            text = {
                Column {
                    OutlinedTextField(
                        value = renameFileName,
                        onValueChange = { renameFileName = it },
                        label = { Text("Name") },
                        singleLine = true,
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = Blue500,
                            unfocusedBorderColor = Slate700,
                            focusedTextColor = White,
                            unfocusedTextColor = Slate200
                        )
                    )
                    Spacer(modifier = Modifier.height(16.dp))
                    Button(
                        onClick = {
                            coroutineScope.launch {
                                val delRes = api.deleteFile(activeItem.id)
                                showRenameDialog = false
                                if (delRes.isSuccess) {
                                    Toast.makeText(context, "In den Papierkorb verschoben", Toast.LENGTH_SHORT).show()
                                    loadCurrentFolder(currentFolder)
                                }
                            }
                        },
                        colors = ButtonDefaults.buttonColors(containerColor = Red500),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Icon(Icons.Default.Delete, contentDescription = null, tint = White)
                        Spacer(modifier = Modifier.width(6.dp))
                        Text("Datei löschen", color = White)
                    }
                }
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        val newName = renameFileName.trim()
                        if (newName.isNotEmpty() && newName != activeItem.name) {
                            coroutineScope.launch {
                                val renRes = api.renameFile(activeItem.id, newName)
                                showRenameDialog = false
                                if (renRes.isSuccess) {
                                    loadCurrentFolder(currentFolder)
                                }
                            }
                        } else {
                            showRenameDialog = false
                        }
                    }
                ) {
                    Text("Umbenennen", color = Blue500)
                }
            },
            dismissButton = {
                TextButton(onClick = { showRenameDialog = false }) {
                    Text("Schließen", color = Slate400)
                }
            },
            containerColor = Slate800,
            titleContentColor = White,
            textContentColor = Slate200
        )
    }
}
}

private fun getFileIcon(item: FileItem) = when {
    item.isImage -> Icons.Default.Image
    item.isVideo -> Icons.Default.VideoFile
    item.mimeType.contains("pdf") -> Icons.Default.PictureAsPdf
    item.mimeType.contains("audio") -> Icons.Default.AudioFile
    item.mimeType.contains("zip") || item.mimeType.contains("tar") -> Icons.Default.FolderZip
    else -> Icons.Default.Description
}

private fun getFileIconTint(item: FileItem) = when {
    item.isImage -> Color(0xFF10B981)
    item.isVideo -> Color(0xFFEC4899)
    item.mimeType.contains("pdf") -> Color(0xFFEF4444)
    item.mimeType.contains("audio") -> Color(0xFF8B5CF6)
    else -> Blue500
}

private fun queryFileName(context: Context, uri: Uri): String? {
    return try {
        context.contentResolver.query(uri, null, null, null, null)?.use { cursor ->
            val nameIndex = cursor.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME)
            if (nameIndex != -1 && cursor.moveToFirst()) {
                cursor.getString(nameIndex)
            } else null
        }
    } catch (e: Exception) {
        null
    }
}
