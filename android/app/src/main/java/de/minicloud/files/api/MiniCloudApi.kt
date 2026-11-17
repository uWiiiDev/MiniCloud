package de.minicloud.files.api

import android.content.Context
import android.net.Uri
import de.minicloud.files.data.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.io.InputStream
import java.util.concurrent.TimeUnit

class MiniCloudApi(private val prefs: PreferencesManager) {

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .build()

    fun getAuthenticatedUrl(relativeUrl: String): String {
        val base = prefs.serverUrl.removeSuffix("/")
        val path = relativeUrl.removePrefix("/")
        val token = prefs.apiToken
        val sep = if (path.contains("?")) "&" else "?"
        return "$base/$path${sep}token=$token"
    }

    suspend fun login(
        serverUrl: String,
        username: String,
        pass: String,
        deviceName: String = "Android Gerät"
    ): Result<Pair<String, Quota>> = withContext(Dispatchers.IO) {
        try {
            val cleanServer = serverUrl.trim().removeSuffix("/")
            val url = "$cleanServer/api/auth.php"

            val jsonBody = JSONObject().apply {
                put("action", "login")
                put("username", username)
                put("password", pass)
                put("device_name", deviceName)
            }

            val requestBody = jsonBody.toString()
                .toRequestBody("application/json; charset=utf-8".toMediaTypeOrNull())

            val request = Request.Builder()
                .url(url)
                .post(requestBody)
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    val errMsg = parseError(bodyStr, "Server-Fehler: HTTP ${response.code}")
                    return@withContext Result.failure(Exception(errMsg))
                }

                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Anmeldung fehlgeschlagen.")))
                }

                val token = json.getString("token")
                val quotaObj = json.optJSONObject("quota")
                val quota = if (quotaObj != null) parseQuota(quotaObj) else Quota(0, 0, 0, 0.0, "", "", "")

                Result.success(Pair(token, quota))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun fetchFiles(folder: String): Result<FileListData> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val encodedFolder = java.net.URLEncoder.encode(folder, "UTF-8")
            val url = "$base/api/files.php?folder=$encodedFolder"

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    return@withContext Result.failure(Exception(parseError(bodyStr, "Fehler beim Laden (${response.code})")))
                }

                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Fehler beim Abrufen der Dateien.")))
                }

                val currentFolder = json.optString("current_folder", folder)

                // Breadcrumbs
                val bcArray = json.optJSONArray("breadcrumbs") ?: JSONArray()
                val breadcrumbs = mutableListOf<BreadcrumbItem>()
                for (i in 0 until bcArray.length()) {
                    val obj = bcArray.getJSONObject(i)
                    breadcrumbs.add(BreadcrumbItem(obj.getString("name"), obj.getString("path")))
                }

                // Folders
                val fArray = json.optJSONArray("folders") ?: JSONArray()
                val folders = mutableListOf<FolderItem>()
                for (i in 0 until fArray.length()) {
                    val obj = fArray.getJSONObject(i)
                    folders.add(FolderItem(obj.getString("name"), obj.getString("path")))
                }

                // Files
                val filesArray = json.optJSONArray("files") ?: JSONArray()
                val files = parseFilesArray(filesArray)

                // Quota
                val quotaObj = json.optJSONObject("quota")
                val quota = if (quotaObj != null) parseQuota(quotaObj) else null

                Result.success(FileListData(currentFolder, breadcrumbs, folders, files, quota))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun searchFiles(query: String): Result<List<FileItem>> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val encodedQuery = java.net.URLEncoder.encode(query, "UTF-8")
            val url = "$base/api/files.php?q=$encodedQuery"

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Suche fehlgeschlagen.")))
                }
                val files = parseFilesArray(json.optJSONArray("files") ?: JSONArray())
                Result.success(files)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun createFolder(parentFolder: String, name: String): Result<FolderItem> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/files.php"

            val jsonBody = JSONObject().apply {
                put("action", "mkdir")
                put("folder", parentFolder)
                put("name", name)
            }

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .post(jsonBody.toString().toRequestBody("application/json".toMediaTypeOrNull()))
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Ordner konnte nicht erstellt werden.")))
                }
                val fo = json.getJSONObject("folder")
                Result.success(FolderItem(fo.getString("name"), fo.getString("path")))
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun renameFile(id: Int, newName: String): Result<Boolean> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/files.php"

            val jsonBody = JSONObject().apply {
                put("action", "rename")
                put("id", id)
                put("new_name", newName)
            }

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .post(jsonBody.toString().toRequestBody("application/json".toMediaTypeOrNull()))
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Datei konnte nicht umbenannt werden.")))
                }
                Result.success(true)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun deleteFile(id: Int): Result<Boolean> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/files.php"

            val jsonBody = JSONObject().apply {
                put("action", "delete")
                put("id", id)
            }

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .post(jsonBody.toString().toRequestBody("application/json".toMediaTypeOrNull()))
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Datei konnte nicht gelöscht werden.")))
                }
                Result.success(true)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun deleteFolder(folderPath: String): Result<Boolean> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/files.php"

            val jsonBody = JSONObject().apply {
                put("action", "delete_folder")
                put("folder", folderPath)
            }

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .post(jsonBody.toString().toRequestBody("application/json".toMediaTypeOrNull()))
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Ordner konnte nicht gelöscht werden.")))
                }
                Result.success(true)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun uploadFile(
        fileBytes: ByteArray,
        filename: String,
        folder: String,
        mimeType: String = "application/octet-stream"
    ): Result<FileItem> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/upload.php"

            val fileRequestBody = fileBytes.toRequestBody(mimeType.toMediaTypeOrNull())

            val multipartBody = MultipartBody.Builder()
                .setType(MultipartBody.FORM)
                .addFormDataPart("folder", folder)
                .addFormDataPart("file", filename, fileRequestBody)
                .build()

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .post(multipartBody)
                .build()

            client.newCall(request).execute().use { response ->
                val bodyStr = response.body?.string().orEmpty()
                if (!response.isSuccessful) {
                    return@withContext Result.failure(Exception(parseError(bodyStr, "Upload fehlgeschlagen (${response.code})")))
                }

                val json = JSONObject(bodyStr)
                if (!json.optBoolean("ok", false)) {
                    return@withContext Result.failure(Exception(json.optString("error", "Upload fehlgeschlagen.")))
                }

                val fObj = json.getJSONObject("file")
                val item = FileItem(
                    id = fObj.getInt("id"),
                    name = fObj.getString("name"),
                    folderPath = fObj.optString("folder_path", folder),
                    size = fObj.optLong("size", fileBytes.size.toLong()),
                    sizeFormatted = fObj.optString("size_formatted", ""),
                    mimeType = fObj.optString("mime_type", mimeType),
                    isImage = fObj.optBoolean("is_image", false),
                    isVideo = fObj.optBoolean("is_video", false),
                    isFavorite = false,
                    uploadDate = "",
                    thumbnailUrl = fObj.optString("thumbnail_url", null),
                    downloadUrl = fObj.optString("download_url", "")
                )
                Result.success(item)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    suspend fun downloadToFile(fileId: Int, destFile: File): Result<File> = withContext(Dispatchers.IO) {
        try {
            val base = prefs.serverUrl.removeSuffix("/")
            val url = "$base/api/download.php?id=$fileId"

            val request = Request.Builder()
                .url(url)
                .addHeader("Authorization", "Bearer ${prefs.apiToken}")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) {
                    return@withContext Result.failure(Exception("Download-Fehler: HTTP ${response.code}"))
                }
                val body = response.body ?: return@withContext Result.failure(Exception("Leere Server-Antwort"))
                body.byteStream().use { input ->
                    FileOutputStream(destFile).use { output ->
                        input.copyTo(output)
                    }
                }
                Result.success(destFile)
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    private fun parseFilesArray(arr: JSONArray): List<FileItem> {
        val list = mutableListOf<FileItem>()
        for (i in 0 until arr.length()) {
            val o = arr.getJSONObject(i)
            list.add(
                FileItem(
                    id = o.getInt("id"),
                    name = o.getString("name"),
                    folderPath = o.optString("folder_path", ""),
                    size = o.optLong("size", 0L),
                    sizeFormatted = o.optString("size_formatted", ""),
                    mimeType = o.optString("mime_type", "application/octet-stream"),
                    isImage = o.optBoolean("is_image", false),
                    isVideo = o.optBoolean("is_video", false),
                    isFavorite = o.optBoolean("is_favorite", false),
                    uploadDate = o.optString("upload_date", ""),
                    thumbnailUrl = if (o.isNull("thumbnail_url")) null else o.optString("thumbnail_url"),
                    downloadUrl = o.optString("download_url", "")
                )
            )
        }
        return list
    }

    private fun parseQuota(q: JSONObject): Quota {
        return Quota(
            used = q.optLong("used", 0L),
            quota = q.optLong("quota", 0L),
            free = q.optLong("free", 0L),
            percent = q.optDouble("percent", 0.0),
            usedFormatted = q.optString("used_formatted", ""),
            quotaFormatted = q.optString("quota_formatted", ""),
            freeFormatted = q.optString("free_formatted", "")
        )
    }

    private fun parseError(body: String, defaultMsg: String): String {
        return try {
            val j = JSONObject(body)
            j.optString("error", defaultMsg)
        } catch (e: Exception) {
            defaultMsg
        }
    }
}
