# Datenverzeichnis (Data Dictionary & Bestandsverzeichnis) – MiniCloud

**Dokumentversion:** 1.0  
**Stand:** 22.10.2026  
**System:** MiniCloud (Relationale Datenbank MySQL / MariaDB, Zeichensatz `utf8mb4_unicode_ci`, sowie Dateisystemspeicher)

---

## 1. Übersicht der Datenbestände

MiniCloud speichert strukturierte Anwendungs-, Berechtigungs- und Metadaten in einer relationalen MySQL-/MariaDB-Datenbank (**10 Tabellen**) sowie die eigentlichen Dateiinhalte in pseudonymisierter Binärform im lokalen Verzeichnis **`uploads/`**.

---

## 2. Datenbanktabellen im Detail

### 2.1 Tabelle `users` (Benutzerkonten)
Speichert alle registrierten Benutzerkonten, deren Rollen, Darstellungspräferenzen und Speicherkontingente.

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
| `theme` | `VARCHAR(24)` | Nein | `'light'` | – | Gewähltes Farbschema (`light`, `sand`, `sage`, `nordic`, `lavender`, `dark`, `ocean`, `forest`) |
| `storage_quota` | `BIGINT UNSIGNED` | Nein | `104857600` | – | Persönliches Speicherkontingent in Bytes (Standard: 100 MB) |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Kontoerstellung |

- **Löschfrist:** Aufbewahrung bis zur Löschung des Benutzerkontos durch den Administrator (`admin.php`).

---

### 2.2 Tabelle `files` (Dateimetadaten & Papierkorb-Status)
Speichert die Metadaten aller hochgeladenen Dateien in persönlichen Ordnern sowie in Gruppenordnern.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung & Personenbezug |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Datei-ID |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Eigentümer bzw. hochladender Benutzer |
| `group_folder_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> group_folders(id) ON DELETE CASCADE` | Zugeordneter Gruppenordner (`NULL` = persönliche Datei) |
| `filename` | `VARCHAR(255)` | Nein | – | `UNIQUE` | Pseudonymisierter physischer Dateiname in `uploads/` (`mc_..._.bin`) |
| `original_name` | `VARCHAR(255)` | Nein | – | – | Ursprünglicher Dateiname beim Upload |
| `folder_path` | `VARCHAR(500)` | Nein | `''` | Index | Relativer Ordnerpfad (z. B. `Projekte/2026`, `''` = Hauptverzeichnis) |
| `file_size` | `BIGINT UNSIGNED` | Nein | `0` | – | Dateigröße in Bytes |
| `mime_type` | `VARCHAR(128)` | Nein | `'application/octet-stream'` | – | Serverseitig per `finfo` ermittelter MIME-Typ |
| `upload_date` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt des Uploads |
| `deleted_at` | `DATETIME` | Ja | `NULL` | Index (`idx_files_deleted_at`) | Löschzeitpunkt im Papierkorb (`NULL` = aktive Datei) |

- **Löschfrist:** Aktive Dateien (`deleted_at IS NULL`) bleiben bis zur Löschung durch den Nutzer erhalten. Gelöschte Dateien (`deleted_at IS NOT NULL`) werden nach **30 Tagen** automatisch endgültig gelöscht (`purgeExpiredTrashFiles()`) oder sofort bei manueller Leerung des Papierkorbs.

---

### 2.3 Tabelle `user_folders` (Explizite Ordnerhierarchie)
Speichert angelegte Ordner und Unterordner (auch wenn diese noch keine Dateien enthalten).

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Ordner-ID |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Ersteller/Eigentümer des Ordners |
| `group_folder_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> group_folders(id) ON DELETE CASCADE` | Zugeordneter Gruppenordner (`NULL` = persönlicher Ordner) |
| `folder_path` | `VARCHAR(500)` | Nein | – | Index (`idx_uf_path`) | Bereinigter relativer Ordnerpfad |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Erstellungszeitpunkt |

---

### 2.4 Tabelle `groups` (Benutzergruppen)
Verwaltet vom Administrator angelegte Benutzergruppen für die Vergabe von Gruppenordner-Rechten.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Gruppen-ID |
| `name` | `VARCHAR(100)` | Nein | – | `UNIQUE` (`idx_groups_name`) | Name der Benutzergruppe |
| `description` | `VARCHAR(255)` | Nein | `''` | – | Optionale Beschreibung |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Erstellungszeitpunkt |

---

### 2.5 Tabelle `group_members` (Gruppenmitgliedschaften)
Verknüpft Benutzer (`users`) mit Benutzergruppen (`groups`).

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Zuordnungs-ID |
| `group_id` | `INT UNSIGNED` | Nein | – | `FK -> groups(id) ON DELETE CASCADE` | Referenz auf die Gruppe |
| `user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Referenz auf das Gruppenmitglied |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Zuordnung |

---

### 2.6 Tabelle `group_folders` (Gemeinsame Gruppenordner)
Definiert zentrale Gruppenordner für Teams und Abteilungen.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Gruppenordner-ID |
| `name` | `VARCHAR(120)` | Nein | – | `UNIQUE` (`idx_group_folders_name`) | Name des Gruppenordners |
| `description` | `VARCHAR(255)` | Nein | `''` | – | Optionale Beschreibung |
| `created_by` | `INT UNSIGNED` | Ja | `NULL` | `FK -> users(id) ON DELETE SET NULL` | Erstellender Administrator |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Erstellungszeitpunkt |

---

### 2.7 Tabelle `group_folder_permissions` (Zugriffsrechte auf Gruppenordner)
Steuert, welche Benutzergruppen oder Einzelbenutzer Lese- bzw. Schreibzugriff auf einen Gruppenordner besitzen.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Berechtigungs-ID |
| `group_folder_id` | `INT UNSIGNED` | Nein | – | `FK -> group_folders(id) ON DELETE CASCADE` | Referenz auf den Gruppenordner |
| `user_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> users(id) ON DELETE CASCADE` | Berechtigter Einzelbenutzer (falls Direktrecht) |
| `group_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> groups(id) ON DELETE CASCADE` | Berechtigte Benutzergruppe (falls Gruppenrecht) |
| `can_write` | `TINYINT(1)` | Nein | `1` | – | `1` = Lesen & Schreiben, `0` = Nur Lesen |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Rechtevergabe |

---

### 2.8 Tabelle `shares` (Öffentliche Freigabelinks)
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

### 2.9 Tabelle `user_shares` (Interne Freigaben zwischen Cloud-Benutzern)
Speichert Direktfreigaben von Dateien oder Ordnern an andere registrierte Benutzer („Für mich freigegeben“).

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | Nein | `AUTO_INCREMENT` | `PRIMARY KEY` | Eindeutige Direktfreigabe-ID |
| `file_id` | `INT UNSIGNED` | Ja | `NULL` | `FK -> files(id) ON DELETE CASCADE` | Freigegebene Einzeldatei (`NULL` bei Ordnerfreigabe) |
| `folder_path` | `VARCHAR(500)` | Ja | `NULL` | – | Freigegebener Ordnerpfad (`NULL` bei Einzeldatei) |
| `owner_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Freigebender Eigentümer |
| `shared_with_user_id` | `INT UNSIGNED` | Nein | – | `FK -> users(id) ON DELETE CASCADE` | Empfänger der Freigabe |
| `created_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Zeitpunkt der Freigabe |

---

### 2.10 Tabelle `settings` (System- & Branding-Einstellungen)
Speichert globale Konfigurationseinstellungen als Schlüssel-Wert-Paare.

| Spalte | Datentyp | Null | Standardwert | Schlüssel / Index | Beschreibung |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `setting_key` | `VARCHAR(64)` | Nein | – | `PRIMARY KEY` | Schlüssel (`app_title`, `app_subtitle`, `logo_data_uri`, `imprint_text`, `mail_from`, `mail_transport`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass`) |
| `setting_value` | `MEDIUMTEXT` | Ja | `NULL` | – | Zugehöriger Konfigurationswert |
| `updated_at` | `TIMESTAMP` | Nein | `CURRENT_TIMESTAMP` | – | Letzte Änderung |

---

## 3. Dateisystem-, Session- und Cookie-Verzeichnis

| Speicherort / Name | Art der Daten | Schutzmaßnahme | Aufbewahrungsdauer |
| :--- | :--- | :--- | :--- |
| `uploads/mc_<uniqid>_<hex16>.bin` | Physische Binärdateien hochgeladener Nutzerdateien | Pseudonymisierter Dateiname, Dateimodus `0640`, `.htaccess`-Zugriffssperre | Bis zur endgültigen Löschung aus dem Papierkorb (max. 30 Tage nach Soft-Delete) oder Kontolöschung |
| `/tmp/mc_zip_*` / `/tmp/mc_share_zip_*` | Temporäre ZIP-Archive beim Ordner-Download | Zufälliger Dateiname über `tempnam()`, Auslieferung nur an autorisierte Nutzer | Sofortige Löschung (`unlink`) unmittelbar nach Abschluss des Downloads |
| PHP-Session (`PHPSESSID`) | `user_id`, `username`, `is_admin`, `theme`, `csrf_token`, `unlocked_shares`, `login_attempts` | Serverseitige Ablage, Cookie mit `HttpOnly`, `SameSite=Lax`, `Secure` (bei HTTPS) | Bis zum Logout (`logout.php`) oder Schließen des Browsers (`lifetime = 0`) |
| Cookie `mc_theme` | Gewähltes Farbschema (z. B. `sand`, `nordic`, `dark`) | Reine UI-Präferenz ohne Personenbezug, `SameSite=Lax` | 365 Tage |
