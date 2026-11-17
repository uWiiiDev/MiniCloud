package de.minicloud.files.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.*
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import de.minicloud.files.api.MiniCloudApi
import de.minicloud.files.data.PreferencesManager
import de.minicloud.files.ui.theme.*
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoginScreen(
    prefs: PreferencesManager,
    api: MiniCloudApi,
    onLoginSuccess: () -> Unit
) {
    var serverUrl by remember { mutableStateOf(prefs.serverUrl.ifEmpty { "https://" }) }
    var username by remember { mutableStateOf(prefs.username) }
    var password by remember { mutableStateOf("") }
    var passwordVisible by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }

    val coroutineScope = rememberCoroutineScope()
    val focusManager = LocalFocusManager.current

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Slate900)
            .padding(24.dp),
        contentAlignment = Alignment.Center
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            // App Icon
            Surface(
                modifier = Modifier.size(80.dp),
                shape = RoundedCornerShape(20.dp),
                color = Blue600
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Icon(
                        imageVector = Icons.Default.Cloud,
                        contentDescription = "MiniCloud",
                        tint = White,
                        modifier = Modifier.size(48.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(16.dp))

            Text(
                text = "MiniCloud",
                fontSize = 28.sp,
                fontWeight = FontWeight.Bold,
                color = White
            )

            Text(
                text = "Eigene private Cloud & Foto-Sync",
                fontSize = 14.sp,
                color = Slate400
            )

            Spacer(modifier = Modifier.height(32.dp))

            // Card Container
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Slate800)
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(20.dp)
                ) {
                    // Server URL
                    OutlinedTextField(
                        value = serverUrl,
                        onValueChange = {
                            serverUrl = it
                            errorMessage = null
                        },
                        label = { Text("Server-URL") },
                        placeholder = { Text("https://cloud.beispiel.de") },
                        leadingIcon = {
                            Icon(Icons.Default.Dns, contentDescription = null, tint = Slate400)
                        },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Uri,
                            imeAction = ImeAction.Next
                        ),
                        keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
                        modifier = Modifier.fillMaxWidth(),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = Blue500,
                            unfocusedBorderColor = Slate700,
                            focusedTextColor = White,
                            unfocusedTextColor = Slate200
                        )
                    )

                    Spacer(modifier = Modifier.height(14.dp))

                    // Username
                    OutlinedTextField(
                        value = username,
                        onValueChange = {
                            username = it
                            errorMessage = null
                        },
                        label = { Text("Benutzername") },
                        leadingIcon = {
                            Icon(Icons.Default.Person, contentDescription = null, tint = Slate400)
                        },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Text,
                            imeAction = ImeAction.Next
                        ),
                        keyboardActions = KeyboardActions(onNext = { focusManager.moveFocus(FocusDirection.Down) }),
                        modifier = Modifier.fillMaxWidth(),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = Blue500,
                            unfocusedBorderColor = Slate700,
                            focusedTextColor = White,
                            unfocusedTextColor = Slate200
                        )
                    )

                    Spacer(modifier = Modifier.height(14.dp))

                    // Password
                    OutlinedTextField(
                        value = password,
                        onValueChange = {
                            password = it
                            errorMessage = null
                        },
                        label = { Text("Passwort / App-Token") },
                        leadingIcon = {
                            Icon(Icons.Default.Lock, contentDescription = null, tint = Slate400)
                        },
                        trailingIcon = {
                            IconButton(onClick = { passwordVisible = !passwordVisible }) {
                                Icon(
                                    imageVector = if (passwordVisible) Icons.Default.Visibility else Icons.Default.VisibilityOff,
                                    contentDescription = "Passwort anzeigen",
                                    tint = Slate400
                                )
                            }
                        },
                        visualTransformation = if (passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Password,
                            imeAction = ImeAction.Done
                        ),
                        keyboardActions = KeyboardActions(onDone = { focusManager.clearFocus() }),
                        modifier = Modifier.fillMaxWidth(),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = Blue500,
                            unfocusedBorderColor = Slate700,
                            focusedTextColor = White,
                            unfocusedTextColor = Slate200
                        )
                    )

                    if (errorMessage != null) {
                        Spacer(modifier = Modifier.height(14.dp))
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = Red500.copy(alpha = 0.15f),
                            border = androidx.compose.foundation.BorderStroke(1.dp, Red500)
                        ) {
                            Text(
                                text = errorMessage.orEmpty(),
                                color = Red500,
                                fontSize = 13.sp,
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(10.dp)
                            )
                        }
                    }

                    Spacer(modifier = Modifier.height(22.dp))

                    Button(
                        onClick = {
                            if (serverUrl.isBlank() || username.isBlank() || password.isBlank()) {
                                errorMessage = "Bitte alle Felder ausfüllen."
                                return@Button
                            }

                            isLoading = true
                            errorMessage = null
                            focusManager.clearFocus()

                            coroutineScope.launch {
                                val result = api.login(serverUrl, username, password)
                                isLoading = false
                                result.fold(
                                    onSuccess = { (token, _) ->
                                        prefs.serverUrl = serverUrl
                                        prefs.username = username
                                        prefs.apiToken = token
                                        onLoginSuccess()
                                    },
                                    onFailure = { error ->
                                        errorMessage = error.localizedMessage ?: "Verbindungsfehler"
                                    }
                                )
                            }
                        },
                        enabled = !isLoading,
                        shape = RoundedCornerShape(10.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Blue600),
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(50.dp)
                    ) {
                        if (isLoading) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(22.dp),
                                color = White,
                                strokeWidth = 2.dp
                            )
                        } else {
                            Icon(Icons.Default.Login, contentDescription = null, tint = White)
                            Spacer(modifier = Modifier.width(8.dp))
                            Text("Mit Server verbinden", fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = White)
                        }
                    }
                }
            }
        }
    }
}
