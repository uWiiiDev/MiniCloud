package de.minicloud.files.data

import android.content.Context
import android.content.SharedPreferences

class PreferencesManager(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("minicloud_settings", Context.MODE_PRIVATE)

    companion object {
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_API_TOKEN = "api_token"
        private const val KEY_USERNAME = "username"
        private const val KEY_AUTO_UPLOAD = "auto_upload_enabled"
        private const val KEY_WIFI_ONLY = "wifi_only"
        private const val KEY_CHARGING_ONLY = "charging_only"
        private const val KEY_LAST_SYNC_TS = "last_sync_timestamp"

        fun cleanServerUrl(input: String): String {
            var url = input.trim()
            if (url.isEmpty()) return ""
            if (!url.startsWith("http://", ignoreCase = true) && !url.startsWith("https://", ignoreCase = true)) {
                url = "https://$url"
            }
            url = url.removeSuffix("/")
            // Falls Benutzer die URL aus der Adresszeile mit Dateinamen kopiert hat (z.B. /index.php, /settings.php, /files.php)
            val phpFileRegex = Regex("/[a-zA-Z0-9_-]+\\.php.*$", RegexOption.IGNORE_CASE)
            url = url.replace(phpFileRegex, "")
            // Falls /api oder /api/ am Ende steht
            if (url.endsWith("/api", ignoreCase = true)) {
                url = url.dropLast(4)
            }
            return url.removeSuffix("/")
        }
    }

    var serverUrl: String
        get() = prefs.getString(KEY_SERVER_URL, "") ?: ""
        set(value) {
            val clean = cleanServerUrl(value)
            prefs.edit().putString(KEY_SERVER_URL, clean).apply()
        }

    var apiToken: String
        get() = prefs.getString(KEY_API_TOKEN, "") ?: ""
        set(value) = prefs.edit().putString(KEY_API_TOKEN, value).apply()

    var username: String
        get() = prefs.getString(KEY_USERNAME, "") ?: ""
        set(value) = prefs.edit().putString(KEY_USERNAME, value).apply()

    var autoUploadEnabled: Boolean
        get() = prefs.getBoolean(KEY_AUTO_UPLOAD, false)
        set(value) = prefs.edit().putBoolean(KEY_AUTO_UPLOAD, value).apply()

    var wifiOnly: Boolean
        get() = prefs.getBoolean(KEY_WIFI_ONLY, true)
        set(value) = prefs.edit().putBoolean(KEY_WIFI_ONLY, value).apply()

    var chargingOnly: Boolean
        get() = prefs.getBoolean(KEY_CHARGING_ONLY, false)
        set(value) = prefs.edit().putBoolean(KEY_CHARGING_ONLY, value).apply()

    var lastSyncTimestamp: Long
        get() = prefs.getLong(KEY_LAST_SYNC_TS, 0L)
        set(value) = prefs.edit().putLong(KEY_LAST_SYNC_TS, value).apply()

    fun isLoggedIn(): Boolean {
        return serverUrl.isNotBlank() && apiToken.isNotBlank()
    }

    fun clearAuth() {
        prefs.edit()
            .remove(KEY_API_TOKEN)
            .remove(KEY_USERNAME)
            .apply()
    }
}
