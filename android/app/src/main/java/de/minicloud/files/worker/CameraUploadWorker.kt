package de.minicloud.files.worker

import android.app.NotificationManager
import android.content.ContentUris
import android.content.Context
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import androidx.core.app.NotificationCompat
import androidx.work.*
import de.minicloud.files.MiniCloudApp
import de.minicloud.files.api.MiniCloudApi
import de.minicloud.files.data.PreferencesManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.*
import java.util.concurrent.TimeUnit

class CameraUploadWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    private val prefs = PreferencesManager(context)
    private val api = MiniCloudApi(prefs)

    companion object {
        const val WORK_NAME_PERIODIC = "minicloud_camera_sync_periodic"
        const val WORK_NAME_ONETIME = "minicloud_camera_sync_onetime"

        fun schedulePeriodicSync(context: Context, prefs: PreferencesManager) {
            val workManager = WorkManager.getInstance(context)

            if (!prefs.autoUploadEnabled || !prefs.isLoggedIn()) {
                workManager.cancelUniqueWork(WORK_NAME_PERIODIC)
                return
            }

            val networkType = if (prefs.wifiOnly) NetworkType.UNMETERED else NetworkType.CONNECTED
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(networkType)
                .setRequiresCharging(prefs.chargingOnly)
                .build()

            val syncRequest = PeriodicWorkRequestBuilder<CameraUploadWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 1, TimeUnit.MINUTES)
                .build()

            workManager.enqueueUniquePeriodicWork(
                WORK_NAME_PERIODIC,
                ExistingPeriodicWorkPolicy.UPDATE,
                syncRequest
            )
        }

        fun runOneTimeSync(context: Context) {
            val prefs = PreferencesManager(context)
            if (!prefs.isLoggedIn()) return

            val networkType = if (prefs.wifiOnly) NetworkType.UNMETERED else NetworkType.CONNECTED
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(networkType)
                .build()

            val syncRequest = OneTimeWorkRequestBuilder<CameraUploadWorker>()
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniqueWork(
                WORK_NAME_ONETIME,
                ExistingWorkPolicy.REPLACE,
                syncRequest
            )
        }
    }

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        if (!prefs.autoUploadEnabled || !prefs.isLoggedIn()) {
            return@withContext Result.success()
        }

        try {
            val newMedia = queryNewMedia(prefs.lastSyncTimestamp)
            if (newMedia.isEmpty()) {
                return@withContext Result.success()
            }

            var uploadedCount = 0
            var maxTimestampSeen = prefs.lastSyncTimestamp
            val yearMonthFormat = SimpleDateFormat("yyyy/MM", Locale.getDefault())

            for (item in newMedia) {
                try {
                    val dateAdded = item.dateAddedMs
                    val folder = "Kamera/" + yearMonthFormat.format(Date(dateAdded))

                    val bytes = readBytesFromUri(item.uri) ?: continue
                    val uploadResult = api.uploadFile(bytes, item.displayName, folder, item.mimeType)

                    if (uploadResult.isSuccess) {
                        uploadedCount++
                        if (dateAdded > maxTimestampSeen) {
                            maxTimestampSeen = dateAdded
                        }
                    }
                } catch (e: Exception) {
                    // Einzeldatei überspringen, nächstes Foto versuchen
                }
            }

            if (maxTimestampSeen > prefs.lastSyncTimestamp) {
                prefs.lastSyncTimestamp = maxTimestampSeen
            }

            if (uploadedCount > 0) {
                showSuccessNotification(uploadedCount)
            }

            Result.success()
        } catch (e: Exception) {
            Result.retry()
        }
    }

    private data class MediaItem(
        val uri: Uri,
        val displayName: String,
        val mimeType: String,
        val dateAddedMs: Long
    )

    private fun queryNewMedia(sinceTimestampMs: Long): List<MediaItem> {
        val items = mutableListOf<MediaItem>()
        val projection = arrayOf(
            MediaStore.MediaColumns._ID,
            MediaStore.MediaColumns.DISPLAY_NAME,
            MediaStore.MediaColumns.MIME_TYPE,
            MediaStore.MediaColumns.DATE_ADDED
        )

        // Bilder & Videos abfragen
        val uris = listOf(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI
        )

        val sinceSec = sinceTimestampMs / 1000L
        val selection = "${MediaStore.MediaColumns.DATE_ADDED} > ?"
        val selectionArgs = arrayOf(sinceSec.toString())
        val sortOrder = "${MediaStore.MediaColumns.DATE_ADDED} ASC LIMIT 50"

        for (baseUri in uris) {
            try {
                context.contentResolver.query(baseUri, projection, selection, selectionArgs, sortOrder)?.use { cursor ->
                    val idCol = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID)
                    val nameCol = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DISPLAY_NAME)
                    val mimeCol = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE)
                    val dateCol = cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DATE_ADDED)

                    while (cursor.moveToNext()) {
                        val id = cursor.getLong(idCol)
                        val name = cursor.getString(nameCol) ?: "Foto_${System.currentTimeMillis()}"
                        val mime = cursor.getString(mimeCol) ?: "image/jpeg"
                        val dateAddedSec = cursor.getLong(dateCol)
                        val contentUri = ContentUris.withAppendedId(baseUri, id)

                        items.add(MediaItem(contentUri, name, mime, dateAddedSec * 1000L))
                    }
                }
            } catch (e: Exception) {
                // Berechtigung fehlt evtl.
            }
        }

        // Nach Datum sortieren
        items.sortBy { it.dateAddedMs }
        return items
    }

    private fun readBytesFromUri(uri: Uri): ByteArray? {
        return try {
            context.contentResolver.openInputStream(uri)?.use { input ->
                input.readBytes()
            }
        } catch (e: Exception) {
            null
        }
    }

    private fun showSuccessNotification(count: Int) {
        val notificationManager =
            context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        val notification = NotificationCompat.Builder(context, MiniCloudApp.CHANNEL_SYNC_ID)
            .setSmallIcon(android.R.drawable.stat_sys_upload_done)
            .setContentTitle("MiniCloud Kamera-Upload")
            .setContentText("$count neue Fotos/Videos erfolgreich synchronisiert")
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setAutoCancel(true)
            .build()

        notificationManager.notify(101, notification)
    }
}
