package de.minicloud.files.ui

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.*
import de.minicloud.files.api.MiniCloudApi
import de.minicloud.files.data.PreferencesManager
import de.minicloud.files.ui.screens.CameraSyncScreen
import de.minicloud.files.ui.screens.FileBrowserScreen
import de.minicloud.files.ui.screens.LoginScreen
import de.minicloud.files.ui.theme.MiniCloudTheme

enum class Screen {
    LOGIN,
    FILE_BROWSER,
    CAMERA_SYNC
}

class MainActivity : ComponentActivity() {

    private lateinit var prefs: PreferencesManager
    private lateinit var api: MiniCloudApi

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = PreferencesManager(this)
        api = MiniCloudApi(prefs)

        setContent {
            MiniCloudTheme {
                var currentScreen by remember {
                    mutableStateOf(if (prefs.isLoggedIn()) Screen.FILE_BROWSER else Screen.LOGIN)
                }

                when (currentScreen) {
                    Screen.LOGIN -> {
                        LoginScreen(
                            prefs = prefs,
                            api = api,
                            onLoginSuccess = {
                                currentScreen = Screen.FILE_BROWSER
                            }
                        )
                    }
                    Screen.FILE_BROWSER -> {
                        FileBrowserScreen(
                            prefs = prefs,
                            api = api,
                            onNavigateCameraSync = {
                                currentScreen = Screen.CAMERA_SYNC
                            },
                            onLogout = {
                                prefs.clearAuth()
                                currentScreen = Screen.LOGIN
                            }
                        )
                    }
                    Screen.CAMERA_SYNC -> {
                        CameraSyncScreen(
                            prefs = prefs,
                            onNavigateBack = {
                                currentScreen = Screen.FILE_BROWSER
                            }
                        )
                    }
                }
            }
        }
    }
}
