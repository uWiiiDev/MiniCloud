# Datenverzeichnis (Data Dictionary & Bestandsverzeichnis) – MiniCloud

**Dokumentversion:** 2.0  
**Stand:** 27.10.2026  
**System:** MiniCloud (Relationale Datenbank MySQL / MariaDB, Zeichensatz `utf8mb4_unicode_ci`, sowie Dateisystemspeicher)

---

## 1. Übersicht der Datenbestände

MiniCloud speichert strukturierte Anwendungs-, Berechtigungs-, Kalender-, Chat-, Aufgaben-, Notiz-, Kontakt-, Umfrage-, Schlüsselbund-, App-Passwort- und Metadaten in einer relationalen MySQL-/MariaDB-Datenbank (**26 Tabellen**) sowie die eigentlichen Dateiinhalte in pseudonymisierter Binärform im lokalen Verzeichnis **`uploads/`**.

---

## 2. Datenbanktabellen im Detail

### 2.1 Tabelle `users` (Benutzerkonten)
Speichert alle registrierten Benutzerkonten, deren Rollen, Darstellungspräferenzen, Datenschutzeinstellungen und Speicherkontingente.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung & Personenbezug |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Benutzer-ID |
| `username` | `VARCHAR(64)` | Nein | – | `UNIQUE` (`idx_users_username`) | Anmeldename des Benutzers (personenbezogen) |
| `email` | `VARCHAR(190)` | Ja | `NULL` | – | Optionale E-Mail-Adresse des Benutzers für E-Mail-2FA, Notfall-Fallback bei TOTP-2FA sowie Passwort-Wiederherstellung |
| `password_hash` | `VARCHAR(255)` | Nein | – | – | BCRYPT-Hash (Cost 12) des Benutzerpassworts |
| `two_factor_enabled` | `TINYINT(1)` | Nein | `0` | – | Gewählte 2FA-Methode (`0` = deaktiviert, `1` = per E-Mail-Code, `2` = per Google Authenticator / TOTP-App nach RFC 6238) |
| `totp_secret` | `VARCHAR(64)` | Ja | `NULL` | – | 160-Bit Base32-Geheimschlüssel (32 Zeichen) für die TOTP-Authenticator-App (RFC 6238) |
| `two_factor_code_hash` | `VARCHAR(255)` | Ja | `NULL` | – | Temporärer BCRYPT-Hash des 6-stelligen 2FA-E-Mail-Einmalcodes |
| `two_factor_expires_at` | `DATETIME` | Ja | `NULL` | – | Ablaufzeitpunkt des aktuellen 2FA-E-Mail-Codes (10 Minuten Gültigkeit) |
| `two_factor_attempts` | `TINYINT UNSIGNED` | Nein | `0` | – | Zähler für Fehlversuche beim aktuellen 2FA-Code (max. 5 Versuche) |
| `reset_token_hash` | `VARCHAR(255)` | Ja | `NULL` | – | Temporärer SHA-256-Hash des 256-Bit-Einmaltokens zum Zurücksetzen eines vergessenen Passworts |
| `reset_expires_at` | `DATETIME` | Ja | `NULL` | – | Ablaufzeitpunkt des aktuellen Passwort-Reset-Links (30 Minuten Gültigkeit) |
| `is_admin` | `TINYINT(1)` | Nein | `0` | – | Administrator-Rolle (`1` = Admin, `0` = Standardbenutzer) |
| `theme` | `VARCHAR(24)` | Nein | `'light'` | – | Gewähltes Farbschema (13 Themes: `light`, `sand`, `sage`, `nordic`, `lavender`, `dusk`, `mocha`, `petrol`, `plum`, `dark`, `ocean`, `forest`, `obsidian`) |
| `session_timeout` | `INT UNSIGNED` | Nein | `30` | – | Inaktivitäts-Timeout in Minuten (`0` = dauerhaft eingeloggt) |
| `flash_duration` | `SMALLINT UNSIGNED` | Nein | `5` | – | Anzeigedauer für Statusmeldungen in Sekunden (`1`–`300`, `0` = manuell schließen) |
| `chat_notify_mode` | `TINYINT UNSIGNED` | Nein | `1` | – | Chat-Benachrichtigungsmodus (`1` = Nur System-Meldung, `2` = System + In-App-Banner, `3` = Nur In-App-Banner, `0` = Aus) |
| `allow_user_sharing` | `TINYINT(1)` | Nein | `0` | – | Datenschutz-Sichtbarkeit in der Teilen- & Chat-Auswahl anderer Benutzer (`0` = verborgen [Standard], `1` = sichtbar) |
| `calendar_token` | `VARCHAR(64)` | Ja | `NULL` | – | Optionales persönliches Token für den `.ics`-Kalender-Abo-Feed |
| `contacts_token` | `VARCHAR(64)` | Ja | `NULL` | – | Optionales persönliches Token für den `.vcf`-Adressbuch-Abo-Feed (`contacts.php?vcf_token=...`) |
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
| `is_favorite` | `TINYINT(1)` | Nein | `0` | – | Favoriten-Markierung (`1` = als Favorit ⭐ angeheftet, `0` = normal) |
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
| `share_mode` | `VARCHAR(20)` | Nein | `'read'` | – | Modus für öffentliche Ordner-Links (`'read'` = Nur Download, `'upload_only'` = Digitaler Briefkasten / Nur Hochladen, `'read_write'` = Download & Upload) |
| `password_hash` | `VARCHAR(255)` | Ja | `NULL` | – | Optionaler BCRYPT-Hash für Passwortschutz |
| `expires_at` | `DATETIME` | Ja | `NULL` | – | Optionales Ablaufdatum des Links (`NULL` = unbegrenzt) |
| `max_downloads` | `INT UNSIGNED` | Ja | `NULL` | – | Optionales Download-Limit für selbstzerstörende Freigabelinks („Burn after reading“, `NULL` = unbegrenzt) |
| `download_count` | `INT UNSIGNED` | Nein | `0` | – | Anzahl der bisherigen Datei-/ZIP-Downloads über diesen Freigabelink |
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
| `messages` | `id`, `sender_id`, `recipient_id`, `is_broadcast`, `message_text`, `attached_file_id`, `is_read`, `created_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Chat-Nachrichten (max. 5.000 Zeichen) inkl. optionalem Datei-/Bildanhang (`attached_file_id`); über *„Verlauf leeren“* jederzeit löschbar |
| `message_broadcast_reads` | `user_id`, `last_read_broadcast_id`, `updated_at` | `PRIMARY KEY (user_id)`, `FK -> users(id) ON DELETE CASCADE` | Speichert pro Benutzer die zuletzt gelesene Admin-Rundnachricht |

---

### 2.12 Tabelle `audit_logs` (Sicherheits- & Aktivitätsprotokoll)
Speichert sicherheitsrelevante Ereignisse (Logins, 2FA-/Login-Fehlversuche, Passwort-Resets, öffentliche Freigaben, selbstzerstörte Freigabelinks, Gast-Uploads, ZIP-Entpackungen, Backups und Admin-Aktionen) DSGVO-konform mit anonymisierten IP-Adressen.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Protokoll-ID |
| `user_id` | `INT UNSIGNED` | Ja | `NULL` | Index (`idx_audit_user`) | Zugeordneter Benutzer (`NULL` bei Gästen oder fehlgeschlagenem Login mit unbekanntem Namen) |
| `username` | `VARCHAR(64)` | Ja | `NULL` | – | Benutzername zum Zeitpunkt des Ereignisses |
| `event_type` | `VARCHAR(40)` | Nein | – | Index (`idx_audit_type`) | Ereigniskategorie (`login_success`, `login_failed`, `password_reset`, `share_create`, `share_burn`, `file_drop`, `zip_extract`, `db_backup`, `user_create`, `user_delete`, `system_update`, `snapshot_restore`, `audit_clear`) |
| `description` | `VARCHAR(500)` | Nein | – | – | Beschreibung des Sicherheits-/Aktivitätsereignisses |
| `ip_address` | `VARCHAR(45)` | Ja | `NULL` | – | DSGVO-konform anonymisierte IP-Adresse (letztes IPv4-Oktett bzw. hintere IPv6-Blöcke maskiert, z. B. `192.168.1.xxx`) |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | Index (`idx_audit_created`) | Zeitpunkt des Ereignisses (automatische Bereinigung nach 90 Tagen oder manuell durch Admin) |

---

### 2.13 Tabelle `file_versions` & 2.14 Tabelle `file_comments` (Dateiversionierung & Datei-Kommentare)
Speichern bis zu 5 frühere Dateistände („Zeitreise“) bei Änderungen in Office Light, WebDAV oder gleichnamigem Upload sowie Kommentare/Notizen direkt an Dateien.

| Tabelle | Wesentliche Spalten | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- |
| `file_versions` | `id`, `file_id`, `user_id`, `stored_name`, `file_size`, `mime_type`, `version_note`, `created_at` | `PRIMARY KEY (id)`, `FK -> files(id) ON DELETE CASCADE` | Physischer Versions-Snapshot in `uploads/`; automatische Rotation ab 5 Versionen |
| `file_comments` | `id`, `file_id`, `user_id`, `comment_text`, `created_at` | `PRIMARY KEY (id)`, `FK -> files(id) ON DELETE CASCADE` | Kommentare und Notizen direkt an einer Datei |

---

### 2.15 Tabelle `tasks`, 2.16 Tabelle `notes` & 2.17 Tabelle `contacts` (Aufgaben, Notizwand & Adressbuch)
Speichern Kanban-Aufgaben, farbige Haftnotizen und Kontakte.

| Tabelle | Wesentliche Spalten | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- |
| `tasks` | `id`, `user_id`, `title`, `description`, `status` (`todo`, `in_progress`, `done`), `priority`, `due_date`, `checklist_json`, `attached_file_id`, `shared_user_id`, `group_id`, `created_at`, `updated_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Aufgaben & Kanban-Board inkl. interaktiver Unteraufgaben-Checkliste, Dateianhang und Teilen |
| `notes` | `id`, `user_id`, `title`, `content`, `color`, `tags`, `is_pinned`, `shared_user_id`, `group_id`, `created_at`, `updated_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Farbige Haftnotizen (Pinboard) mit `#Tags`, Anpinnen und 1-Klick-Export als `.md` in die Cloud |
| `contacts` | `id`, `user_id`, `first_name`, `last_name`, `organization`, `role_title`, `email`, `phone`, `mobile`, `street`, `postal_code`, `city`, `country`, `website`, `birthday`, `notes`, `is_favorite`, `vcard_uid`, `shared_user_id`, `group_id`, `created_at`, `updated_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Persönliche und geteilte Kontakte inkl. CardDAV-Synchronisation (`carddav.php`), `.vcf`-Abo-Feed, vCard Import/Export und automatischer Geburtstagsanzeige im Kalender |
| `polls`, `poll_options` & `poll_votes` | `id`, `user_id`, `title`, `poll_type`, `public_token`, `is_closed`, `shared_user_id`, `group_id` / `poll_id`, `label_text`, `option_date`, `option_time` / `poll_id`, `option_id`, `voter_user_id`, `voter_name`, `vote_value` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE`, `FK -> polls(id) ON DELETE CASCADE` | Terminfinder & Abstimmungen (`polls.php`) mit interner Freigabe, öffentlichem Gäste-Abstimmungslink (`public_token`) und 1-Klick-Übernahme des Gewinner-Termins in `calendar_events` |
| `passwords` | `id`, `user_id`, `title`, `category`, `website_url`, `username_enc`, `password_enc`, `notes_enc`, `is_favorite`, `shared_user_id`, `group_id`, `password_changed_at`, `created_at`, `updated_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Serverseitig AES-256-GCM-verschlüsselter Passwort-Schlüsselbund (`passwords.php`) für Logins, WLAN, Server, Lizenzen und PINs |
| `app_passwords` | `id`, `user_id`, `label`, `password_hash`, `token_prefix`, `last_used_at`, `last_used_service`, `created_at` | `PRIMARY KEY (id)`, `FK -> users(id) ON DELETE CASCADE` | Separate, pro Gerät erzeugbare und einzeln widerrufbare App-Passwörter (BCRYPT Cost 12) für WebDAV (`webdav.php`), CardDAV (`carddav.php`) und CalDAV (`caldav.php`) |
| `login_attempts` | `id`, `ip_address`, `username`, `attempted_at` | `PRIMARY KEY (id)`, `KEY (ip_address, attempted_at)` | Temporäre Brute-Force-Sperrtabelle (max. 5 Fehlversuche pro 5 Minuten für Login, WebDAV, CardDAV & CalDAV; automatische Bereinigung nach 15 Minuten) |

---

### 2.18 Tabelle `settings` (System-, Modul-, Registrierungs- & Update-Einstellungen)
Speichert globale Konfigurationseinstellungen als Schlüssel-Wert-Paare.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `setting_key` | `VARCHAR(64)` | Nein | – | `PRIMARY KEY` | Schlüssel (`app_title`, `app_subtitle`, `logo_data_uri`, `imprint_text`, `enable_calendar`, `enable_chat`, `enable_tasks`, `enable_notes`, `enable_contacts`, `enable_webdav`, `allow_registration`, `registration_code`, `registration_default_quota_mb`, `installed_commit_sha`, `installed_commit_date`, `mail_from`, `mail_transport`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass`) |
| `setting_value` | `MEDIUMTEXT` | Ja | `NULL` | – | Zugehöriger Konfigurationswert |
| `updated_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Letzte Änderung |

---

## 3. Dateisystem-, Session- und Cookie-Verzeichnis

| Speicherort / Name | Art der Daten | Schutzmaßnahme | Aufbewahrungsdauer |
| :--- | :--- | :--- | :--- |
| `uploads/mc_<uniqid>_<hex16>.bin` | Physische Binärdateien hochgeladener Nutzerdateien (inkl. Office-Light-Dokumente `.docx`/`.xlsx`) | Pseudonymisierter Dateiname, Dateimodus `0640`, `.htaccess`-Zugriffssperre, optional AES-256-GCM verschlüsselt | Bis zur endgültigen Löschung aus dem Papierkorb (max. 30 Tage nach Soft-Delete) oder Kontolöschung |
| `uploads/.system_backups/snapshot_*.zip` | Lokale Programm-Sicherungspunkte (Snapshots vor Updates für 1-Klick-Rollback, ohne Nutzerdateien und ohne `config/database.php`) | Eigene `.htaccess`-Zugriffssperre (`Require all denied`), Dateimodus `0640`, Zugriff nur für Administratoren über `admin.php` | Automatische Rotation: Es werden maximal die 8 neuesten Sicherungspunkte aufbewahrt (jederzeit manuell löschbar) |
| `/tmp/mc_zip_*` / `/tmp/mc_upd_*` / `/tmp/mc_office_*` | Temporäre ZIP-/OpenXML-Arbeitsdateien (Ordner-Download, GitHub-Update, Office Light) | Zufälliger Dateiname über `tempnam()`, Auslieferung nur an autorisierte Nutzer | Sofortige Löschung (`unlink`) unmittelbar nach Abschluss der Operation |
| PHP-Session (`PHPSESSID`) | `user_id`, `username`, `is_admin`, `theme`, `session_timeout`, `flash_duration`, `chat_notify_mode`, `csrf_token` | Serverseitige Ablage, Cookie mit `HttpOnly`, `SameSite=Lax`, `Secure` (bei HTTPS) | Bis zum Logout (`logout.php`), Ablauf des Inaktivitäts-Timeouts oder Schließen des Browsers |
| Cookies `mc_theme`, `mc_lang` | Gewähltes Farbschema (`light`..`obsidian`) & Sprache (`de`..`nl`) | Reine UI-Präferenz ohne Personenbezug, `SameSite=Lax` | 365 Tage |
| Browser `localStorage` (`mc_pinned_header_nav_u<id>`, `mc_pinned_file_actions_u<id>`, `mc_file_view_mode`) | Vom Benutzer per `⭐` nach außen gelegte Favoriten-Module in der Kopfzeile, Favoriten-Aktions-Icons in der Dateiliste sowie Listen-/Kachel-Ansicht | Reine lokale UI-Präferenz im Browser des Endgeräts (keine Übertragung an Dritte) | Bis zum Zurücksetzen auf „Standard“ oder Löschen der Browserdaten |
