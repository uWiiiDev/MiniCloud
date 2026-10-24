# Datenverzeichnis (Data Dictionary & Bestandsverzeichnis) – MiniCloud

**Dokumentversion:** 1.5  
**Stand:** 24.10.2026  
**System:** MiniCloud (Relationale Datenbank MySQL / MariaDB, Zeichensatz `utf8mb4_unicode_ci`, sowie Dateisystemspeicher)

---

## 1. Übersicht der Datenbestände

MiniCloud speichert strukturierte Anwendungs-, Berechtigungs-, Kalender-, Chat- und Metadaten in einer relationalen MySQL-/MariaDB-Datenbank (**14 Tabellen**) sowie die eigentlichen Dateiinhalte in pseudonymisierter Binärform im lokalen Verzeichnis **`uploads/`**.

---

## 2. Datenbanktabellen im Detail

### 2.1 Tabelle `users` (Benutzerkonten)
Speichert alle registrierten Benutzerkonten, deren Rollen, Darstellungspräferenzen, Datenschutzeinstellungen und Speicherkontingente.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung & Personenbezug |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Benutzer-ID |
| `username` | `VARCHAR(64)` | Nein | – | `UNIQUE` (`idx_users_username`) | Anmeldename des Benutzers (personenbezogen) |
| `email` | `VARCHAR(190)` | Ja | `NULL` | – | Optionale E-Mail-Adresse des Benutzers für die Zwei-Faktor-Authentifizierung per E-Mail |
| `password_hash` | `VARCHAR(255)` | Nein | – | – | BCRYPT-Hash (Cost 12) des Benutzerpassworts |
| `two_factor_enabled` | `TINYINT(1)` | Nein | `0` | – | Status der optionalen E-Mail-2FA (`1` = aktiv, `0` = inaktiv) |
| `two_factor_code_hash` | `VARCHAR(255)` | Ja | `NULL` | – | Temporärer BCRYPT-Hash des 6-stelligen 2FA-Einmalcodes |
| `two_factor_expires_at` | `DATETIME` | Ja | `NULL` | – | Ablaufzeitpunkt des aktuellen 2FA-Codes (10 Minuten Gültigkeit) |
| `two_factor_attempts` | `TINYINT UNSIGNED` | Nein | `0` | – | Zähler für Fehlversuche beim aktuellen 2FA-Code (max. 5 Versuche) |
| `is_admin` | `TINYINT(1)` | Nein | `0` | – | Administrator-Rolle (`1` = Admin, `0` = Standardbenutzer) |
| `theme` | `VARCHAR(24)` | Nein | `'light'` | – | Gewähltes Farbschema (13 Themes: `light`, `sand`, `sage`, `nordic`, `lavender`, `dusk`, `mocha`, `petrol`, `plum`, `dark`, `ocean`, `forest`, `obsidian`) |
| `session_timeout` | `INT UNSIGNED` | Nein | `30` | – | Inaktivitäts-Timeout in Minuten (`0` = dauerhaft eingeloggt) |
| `flash_duration` | `SMALLINT UNSIGNED` | Nein | `5` | – | Anzeigedauer für Statusmeldungen in Sekunden (`1`–`300`, `0` = manuell schließen) |
| `chat_notify_mode` | `TINYINT UNSIGNED` | Nein | `1` | – | Chat-Benachrichtigungsmodus (`1` = Nur System-Meldung, `2` = System + In-App-Banner, `3` = Nur In-App-Banner, `0` = Aus) |
| `allow_user_sharing` | `TINYINT(1)` | Nein | `0` | – | Datenschutz-Sichtbarkeit in der Teilen- & Chat-Auswahl anderer Benutzer (`0` = verborgen [Standard], `1` = sichtbar) |
| `calendar_token` | `VARCHAR(64)` | Ja | `NULL` | – | Optionales persönliches Token für den `.ics`-Kalender-Abo-Feed |
| `storage_quota` | `BIGINT UNSIGNED` | Nein | `104857600` | – | Persönliches Speicherkontingent in Bytes (Standard: 100 MB) |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Kontoerstellung |

- **Löschfrist:** Aufbewahrung bis zur Löschung des Benutzerkontos durch den Administrator (`admin.php`).

---

### 2.2 Tabelle `files` (Dateimetadaten, Tresor-Status & Papierkorb-Status)
Speichert die Metadaten aller hochgeladenen Dateien.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung & Personenbezug |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Datei-ID |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Eigentümer der Datei |
| `group_folder_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> group_folders(id) ON DELETE CASCADE` | Legacy-Spalte (`NULL` für persönliche & direkt geteilte Ordner) |
| `filename` | `VARCHAR(255)` | Nein | – | `UNIQUE` | Pseudonymisierter physischer Dateiname in `uploads/` (`mc_..._.bin`) |
| `original_name` | `VARCHAR(255)` | Nein | – | – | Ursprünglicher Dateiname beim Upload |
| `folder_path` | `VARCHAR(500)` | Nein | `''` | Index | Relativer Ordnerpfad (z. B. `Projekte/2026`, `''` = Hauptverzeichnis) |
| `file_size` | `BIGINT UNSIGNED` | Nein | `0` | – | Dateigröße in Bytes |
| `mime_type` | `VARCHAR(128)` | Nein | `'application/octet-stream'` | – | Serverseitig per `finfo` ermittelter MIME-Typ |
| `is_encrypted` | `TINYINT(1)` | Nein | `0` | – | Tresor-Status (`1` = mit AES-256-GCM Zero-Knowledge verschlüsselt, `0` = unverschlüsselt) |
| `upload_date` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt des Uploads |
| `deleted_at` | `DATETIME` | Ja | `NULL` | Index (`idx_files_deleted_at`) | Löschzeitpunkt im Papierkorb (`NULL` = aktive Datei) |

- **Löschfrist:** Aktive Dateien (`deleted_at IS NULL`) bleiben bis zur Löschung durch den Nutzer erhalten. Gelöschte Dateien (`deleted_at IS NOT NULL`) werden nach **30 Tagen** automatisch endgültig gelöscht (`purgeExpiredTrashFiles()`) oder sofort bei manueller Leerung des Papierkorbs.

---

### 2.3 Tabelle `user_folders` (Explizite Ordnerhierarchie)
Speichert angelegte Ordner und Unterordner (auch wenn diese noch keine Dateien enthalten).

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Ordner-ID |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Eigentümer des Ordners |
| `group_folder_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> group_folders(id) ON DELETE CASCADE` | Legacy-Spalte (`NULL`) |
| `folder_path` | `VARCHAR(500)` | Nein | – | Index (`idx_uf_path`) | Bereinigter relativer Ordnerpfad |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Erstellungszeitpunkt |

---

### 2.4 Tabelle `groups` & 2.5 Tabelle `group_members` (Benutzergruppen)
Verwalten vom Administrator angelegte Benutzergruppen (z. B. für gemeinsame Kalender-Termine und Gruppen-Kalenderfreigaben).

| Tabelle | Wesentliche Spalten | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- |
| `groups` | `id`, `name`, `description`, `created_at` | `PRIMARY KEY (id)`, `UNIQUE (name)` | Name und Beschreibung der Benutzergruppe |
| `group_members` | `id`, `group_id`, `user_id`, `created_at` | `FK -> groups(id)`, `FK -> users(id) ON DELETE CASCADE` | Zuordnung von Benutzern zu Gruppen |

---

### 2.6 Tabelle `shares` (Öffentliche Freigabelinks)
Speichert externe Freigabelinks für einzelne Dateien oder ganze persönliche Ordner.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Freigabe-ID |
| `file_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> files(id) ON DELETE CASCADE` | Freigegebene Einzeldatei (`NULL` bei Ordnerfreigabe) |
| `folder_path` | `VARCHAR(500)` | Ja | `NULL` | – | Freigegebener Ordnerpfad (`NULL` bei Einzeldatei) |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Ersteller der Freigabe |
| `share_token` | `VARCHAR(64)` | Nein | – | `UNIQUE` (`idx_shares_token`) | Kryptografisches 32-Zeichen-Hex-Token (`random_bytes(16)`) |
| `password_hash` | `VARCHAR(255)` | Ja | `NULL` | – | Optionaler BCRYPT-Hash für Passwortschutz |
| `expires_at` | `DATETIME` | Ja | `NULL` | – | Optionales Ablaufdatum des Links (`NULL` = unbegrenzt) |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Erstellungszeitpunkt |

---

### 2.7 Tabelle `user_shares` (Interne 3-Stufen-Freigaben zwischen Cloud-Benutzern)
Speichert Direktfreigaben von Dateien oder Ordnern an andere registrierte Benutzer („Für mich freigegeben“) mit 3 Berechtigungsstufen.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Direktfreigabe-ID |
| `file_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> files(id) ON DELETE CASCADE` | Freigegebene Einzeldatei (`NULL` bei Ordnerfreigabe) |
| `folder_path` | `VARCHAR(500)` | Ja | `NULL` | – | Freigegebener Ordnerpfad (`NULL` bei Einzeldatei) |
| `owner_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Freigebender Eigentümer |
| `shared_with_user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Empfänger der Freigabe |
| `can_write` | `TINYINT(1)` | Nein | `0` | – | `1` = Lesen & Schreiben (Upload & Unterordner), `0` = Nur Lesen |
| `can_delete` | `TINYINT(1)` | Nein | `0` | – | `1` = Zusätzlich Berechtigung zum Löschen von Dateien/Unterordnern |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Freigabe |

---

### 2.8 Tabelle `calendar_events` & 2.9 Tabelle `calendar_shares` (Kalender & Termine)
Speichern persönliche und geteilte Termine sowie 3-stufige Kalender-Freigaben für Benutzer und Benutzergruppen.

| Tabelle | Wesentliche Spalten | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- |
| `calendar_events` | `id`, `user_id`, `title`, `description`, `location`, `start_at`, `end_at`, `all_day`, `recurrence`, `color`, `group_id`, `group_can_edit`, `shared_user_id`, `attached_file_id`, `is_private`, `created_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Termine inkl. Wiederholungsregeln (`none`, `daily`, `weekly`, `monthly`, `yearly`), angehängter Cloud-Datei und Privat-Flag |
| `calendar_shares` | `id`, `owner_user_id`, `target_user_id`, `target_group_id`, `can_edit`, `can_delete`, `created_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | 3-stufige Kalender-Freigaben (`Nur Lesen`, `Lesen & Bearbeiten`, `Lesen, Bearbeiten & Löschen`) |

---

### 2.10 Tabelle `messages` & 2.11 Tabelle `message_broadcast_reads` (Chat & Rundnachrichten)
Speichern direkte 1:1-Nachrichten zwischen Cloud-Benutzern sowie offizielle Admin-Rundnachrichten an alle Benutzer.

| Tabelle | Wesentliche Spalten | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- |
| `messages` | `id`, `sender_id`, `recipient_id`, `is_broadcast`, `message_text`, `is_read`, `created_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Chat-Nachrichten (max. 5.000 Zeichen); über *„Verlauf leeren“* jederzeit löschbar |
| `message_broadcast_reads` | `user_id`, `last_read_broadcast_id`, `updated_at` | `PRIMARY KEY (user_id)`, `FK -> users(id) ON DELETE CASCADE` | Speichert pro Benutzer die zuletzt gelesene Admin-Rundnachricht |

---

### 2.12 Tabelle `settings` (System-, Modul-, Registrierungs- & Update-Einstellungen)
Speichert globale Konfigurationseinstellungen als Schlüssel-Wert-Paare.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `setting_key` | `VARCHAR(64)` | Nein | – | `PRIMARY KEY` | Schlüssel (`app_title`, `app_subtitle`, `logo_data_uri`, `imprint_text`, `enable_calendar`, `enable_chat`, `allow_registration`, `registration_code`, `registration_default_quota_mb`, `installed_commit_sha`, `installed_commit_date`, `mail_from`, `mail_transport`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass`) |
| `setting_value` | `MEDIUMTEXT` | Ja | `NULL` | – | Zugehöriger Konfigurationswert |
| `updated_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Letzte Änderung |

---

## 3. Dateisystem-, Session- und Cookie-Verzeichnis

| Speicherort / Name | Art der Daten | Schutzmaßnahme | Aufbewahrungsdauer |
| :--- | :--- | :--- | :--- |
| `uploads/mc_<uniqid>_<hex16>.bin` | Physische Binärdateien hochgeladener Nutzerdateien | Pseudonymisierter Dateiname, Dateimodus `0640`, `.htaccess`-Zugriffssperre, optional AES-256-GCM verschlüsselt | Bis zur endgültigen Löschung aus dem Papierkorb (max. 30 Tage nach Soft-Delete) oder Kontolöschung |
| `/tmp/mc_zip_*` / `/tmp/mc_upd_*` | Temporäre ZIP-Archive (Ordner-Download / GitHub-Update) | Zufälliger Dateiname über `tempnam()`, Auslieferung nur an autorisierte Nutzer | Sofortige Löschung (`unlink`) unmittelbar nach Abschluss der Operation |
| PHP-Session (`PHPSESSID`) | `user_id`, `username`, `is_admin`, `theme`, `session_timeout`, `flash_duration`, `chat_notify_mode`, `csrf_token` | Serverseitige Ablage, Cookie mit `HttpOnly`, `SameSite=Lax`, `Secure` (bei HTTPS) | Bis zum Logout (`logout.php`), Ablauf des Inaktivitäts-Timeouts oder Schließen des Browsers |
| Cookies `mc_theme`, `mc_lang` | Gewähltes Farbschema (`light`..`obsidian`) & Sprache (`de`..`nl`) | Reine UI-Präferenz ohne Personenbezug, `SameSite=Lax` | 365 Tage |
