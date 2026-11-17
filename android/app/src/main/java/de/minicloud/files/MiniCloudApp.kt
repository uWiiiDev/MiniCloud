package de.minicloud.files

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build

class MiniCloudApp : Application() {

    companion object {
        const val CHANNEL_SYNC_ID = "minicloud_sync_channel"
        lateinit var instance: MiniCloudApp
            private set
    }

    override fun onCreate() {
        super.onCreate()
        instance = this
        createNotificationChannels()
    }

    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val name = "MiniCloud Foto-Sync"
            val descriptionText = "Benachrichtigungen über den automatischen Upload von Smartphone-Fotos"
            val importance = NotificationManager.IMPORTANCE_LOW
            val channel = NotificationChannel(CHANNEL_SYNC_ID, name, importance).apply {
                description = descriptionText
            }
            val notificationManager: NotificationManager =
                getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            notificationManager.createNotificationChannel(channel)
        }
    }
}
