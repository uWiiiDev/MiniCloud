package de.minicloud.files.data

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream

data class OfflineFileRecord(
    val id: Int,
    val name: String,
    val folderPath: String,
    val size: Long,
    val sizeFormatted: String,
    val mimeType: String,
    val isImage: Boolean,
    val isVideo: Boolean,
    val localFileName: String,
    val savedAt: Long = System.currentTimeMillis()
)

class OfflineManager(private val context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("minicloud_offline_prefs", Context.MODE_PRIVATE)

    val offlineDir: File
        get() {
            val baseDir = context.getExternalFilesDir("offline") ?: File(context.filesDir, "offline")
            if (!baseDir.exists()) {
                baseDir.mkdirs()
            }
            return baseDir
        }

    companion object {
        private const val KEY_OFFLINE_FILES = "offline_files_json"
        private const val KEY_OFFLINE_FOLDERS = "offline_folders_set"
    }

    // ----------------------------------------------------
    // ORDNER-VERWALTUNG
    // ----------------------------------------------------

    fun isFolderOffline(folderPath: String): Boolean {
        val folders = getOfflineFolders()
        return folders.contains(folderPath)
    }

    fun getOfflineFolders(): Set<String> {
        return prefs.getStringSet(KEY_OFFLINE_FOLDERS, emptySet()) ?: emptySet()
    }

    fun markFolderOffline(folderPath: String) {
        val current = getOfflineFolders().toMutableSet()
        current.add(folderPath)
        prefs.edit().putStringSet(KEY_OFFLINE_FOLDERS, current).apply()
    }

    fun unmarkFolderOffline(folderPath: String, removeContainedFiles: Boolean = true) {
        val current = getOfflineFolders().toMutableSet()
        current.remove(folderPath)
        prefs.edit().putStringSet(KEY_OFFLINE_FOLDERS, current).apply()

        if (removeContainedFiles) {
            val files = getAllOfflineFiles()
            for (f in files) {
                if (f.folderPath == folderPath || f.folderPath.startsWith("$folderPath/")) {
                    removeFileOffline(f.id)
                }
            }
        }
    }

    // ----------------------------------------------------
    // DATEIEN-VERWALTUNG
    // ----------------------------------------------------

    fun isFileOffline(fileId: Int): Boolean {
        val record = getOfflineRecord(fileId) ?: return false
        val localFile = File(offlineDir, record.localFileName)
        return localFile.exists() && localFile.length() > 0
    }

    fun getOfflineFile(fileId: Int): File? {
        val record = getOfflineRecord(fileId) ?: return null
        val localFile = File(offlineDir, record.localFileName)
        return if (localFile.exists() && localFile.length() > 0) localFile else null
    }

    fun getOfflineRecord(fileId: Int): OfflineFileRecord? {
        return getAllOfflineFiles().find { it.id == fileId }
    }

    fun getAllOfflineFiles(): List<OfflineFileRecord> {
        val raw = prefs.getString(KEY_OFFLINE_FILES, "[]") ?: "[]"
        val list = mutableListOf<OfflineFileRecord>()
        try {
            val jsonArray = JSONArray(raw)
            for (i in 0 until jsonArray.length()) {
                val obj = jsonArray.getJSONObject(i)
                list.add(
                    OfflineFileRecord(
                        id = obj.getInt("id"),
                        name = obj.getString("name"),
                        folderPath = obj.optString("folder_path", ""),
                        size = obj.optLong("size", 0L),
                        sizeFormatted = obj.optString("size_formatted", ""),
                        mimeType = obj.optString("mime_type", "application/octet-stream"),
                        isImage = obj.optBoolean("is_image", false),
                        isVideo = obj.optBoolean("is_video", false),
                        localFileName = obj.getString("local_file_name"),
                        savedAt = obj.optLong("saved_at", System.currentTimeMillis())
                    )
                )
            }
        } catch (_: Exception) {}
        return list
    }

    fun saveFileOffline(item: FileItem, sourceFile: File): File {
        val safeName = item.name.replace(Regex("[^a-zA-Z0-9._-]"), "_")
        val localName = "${item.id}_$safeName"
        val targetFile = File(offlineDir, localName)

        if (sourceFile.absolutePath != targetFile.absolutePath) {
            FileInputStream(sourceFile).use { input ->
                FileOutputStream(targetFile).use { output ->
                    input.copyTo(output)
                }
            }
        }

        val all = getAllOfflineFiles().filter { it.id != item.id }.toMutableList()
        all.add(
            OfflineFileRecord(
                id = item.id,
                name = item.name,
                folderPath = item.folderPath,
                size = item.size,
                sizeFormatted = item.sizeFormatted,
                mimeType = item.mimeType,
                isImage = item.isImage,
                isVideo = item.isVideo,
                localFileName = localName,
                savedAt = System.currentTimeMillis()
            )
        )
        persistOfflineFiles(all)
        return targetFile
    }

    fun removeFileOffline(fileId: Int) {
        val all = getAllOfflineFiles().toMutableList()
        val found = all.find { it.id == fileId }
        if (found != null) {
            val localFile = File(offlineDir, found.localFileName)
            if (localFile.exists()) {
                localFile.delete()
            }
            all.removeAll { it.id == fileId }
            persistOfflineFiles(all)
        }
    }

    private fun persistOfflineFiles(list: List<OfflineFileRecord>) {
        val jsonArray = JSONArray()
        for (item in list) {
            val obj = JSONObject().apply {
                put("id", item.id)
                put("name", item.name)
                put("folder_path", item.folderPath)
                put("size", item.size)
                put("size_formatted", item.sizeFormatted)
                put("mime_type", item.mimeType)
                put("is_image", item.isImage)
                put("is_video", item.isVideo)
                put("local_file_name", item.localFileName)
                put("saved_at", item.savedAt)
            }
            jsonArray.put(obj)
        }
        prefs.edit().putString(KEY_OFFLINE_FILES, jsonArray.toString()).apply()
    }

    fun toFileItem(record: OfflineFileRecord): FileItem {
        return FileItem(
            id = record.id,
            name = record.name,
            folderPath = record.folderPath,
            size = record.size,
            sizeFormatted = record.sizeFormatted,
            mimeType = record.mimeType,
            isImage = record.isImage,
            isVideo = record.isVideo,
            isFavorite = false,
            uploadDate = "",
            thumbnailUrl = null,
            downloadUrl = ""
        )
    }

    fun getOfflineItemsForFolder(folderPath: String): Pair<List<FolderItem>, List<FileItem>> {
        val allFiles = getAllOfflineFiles()
        val allFolders = getOfflineFolders()

        val matchingFiles = allFiles
            .filter { it.folderPath == folderPath }
            .map { toFileItem(it) }

        val subfolders = mutableSetOf<String>()
        // 1. Aus den offline markierten Ordnern
        for (f in allFolders) {
            if (folderPath.isEmpty()) {
                val rootPart = f.split('/').firstOrNull().orEmpty()
                if (rootPart.isNotEmpty()) subfolders.add(rootPart)
            } else if (f.startsWith("$folderPath/")) {
                val rel = f.removePrefix("$folderPath/")
                val nextPart = rel.split('/').firstOrNull().orEmpty()
                if (nextPart.isNotEmpty()) subfolders.add(nextPart)
            }
        }
        // 2. Aus den Ordnerpfaden vorhandener Offline-Dateien
        for (f in allFiles) {
            if (folderPath.isEmpty()) {
                val rootPart = f.folderPath.split('/').firstOrNull().orEmpty()
                if (rootPart.isNotEmpty()) subfolders.add(rootPart)
            } else if (f.folderPath.startsWith("$folderPath/")) {
                val rel = f.folderPath.removePrefix("$folderPath/")
                val nextPart = rel.split('/').firstOrNull().orEmpty()
                if (nextPart.isNotEmpty()) subfolders.add(nextPart)
            }
        }

        val folderItems = subfolders.map { name ->
            val p = if (folderPath.isEmpty()) name else "$folderPath/$name"
            FolderItem(name = name, path = p)
        }.sortedBy { it.name }

        return Pair(folderItems, matchingFiles)
    }
}
