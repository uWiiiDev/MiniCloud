# IT-Sicherheitskonzept – MiniCloud

**Dokumentversion:** 1.6  
**Stand:** 25.10.2026  
**System:** MiniCloud – Leichtgewichtiger, selbstgehosteter Cloud-Speicher mit Kalender & Messenger (PHP 8.2+ / MySQL & MariaDB)

---

## 1. Zielsetzung und Geltungsbereich

Dieses IT-Sicherheitskonzept beschreibt die technischen und organisatorischen Maßnahmen (TOM) zum Schutz der Vertraulichkeit, Integrität, Verfügbarkeit und Authentizität aller in **MiniCloud** gespeicherten Dateien, Ordnerstrukturen, Freigaben, Kalendertermine, Chat-Nachrichten und Benutzerdaten. Es umfasst sowohl die architektonischen Grundsätze als auch die Ergebnisse der durchgeführten Quellcode-Sicherheitsüberprüfung (Security Audit) und deren Härtungsmaßnahmen.

---

## 2. Schutzbedarf und Sicherheitsziele

1. **Vertraulichkeit (Confidentiality):**
   - Persönliche Dateien, Ordner, Kalendertermine und Direktnachrichten sind ausschließlich für den jeweiligen Eigentümer bzw. explizit berechtigte Empfänger einsehbar.
   - Standardmäßig sind Benutzerkonten in der Teilen- und Chat-Auswahl anderer Nutzer verborgen (`allow_user_sharing = 0`, Privacy-by-Default) und müssen aktiv vom jeweiligen Benutzer freigeschaltet werden.
   - Über den **🔒 Tresor-Modus** können Dateien zusätzlich einzeln mit **AES-256-GCM (Zero-Knowledge)** verschlüsselt werden, sodass selbst bei direktem Serverzugriff kein Klartext vorliegt.
2. **Integrität (Integrity):**
   - Schutz vor unbefugter Veränderung, Überschreibung oder Löschung durch 3-stufige Berechtigungsprüfungen (`Nur Lesen`, `Lesen & Schreiben/Bearbeiten`, `Lesen, Schreiben/Bearbeiten & Löschen`) sowie CSRF- und SQL-Injection-Schutz.
3. **Verfügbarkeit (Availability):**
   - Schutz vor unbeabsichtigtem Datenverlust durch 30-tägige Papierkorb-Aufbewahrung (Soft-Delete), Benutzer-Speicherkontingente (Quotas) sowie einen abgesicherten **1-Klick-GitHub-Updater**, der `uploads/` und Datenbank-Zugangsdaten niemals überschreibt.
4. **Authentizität & Nachvollziehbarkeit:**
   - Eindeutige Zuordnung jeder Datei (`user_id`), jeder Freigabe (`owner_id`), jedes Termins (`user_id`) und jeder Nachricht (`sender_id`) zu einem authentifizierten Benutzerkonto.

---

## 3. Systemarchitektur & Technische Sicherheitsmaßnahmen

### 3.1 Authentifizierung, 2FA & Optionale Registrierung (`login.php`, `admin.php`, `register.php`)
- **Geschlossener Benutzerkreis (Standard):** Eine öffentliche Selbstregistrierung (`register.php`) ist standardmäßig deaktiviert (`allow_registration = 0`). Wenn der Administrator sie aktiviert, kann sie zusätzlich durch einen verpflichtenden **Einladungs-Code** (`hash_equals`), ein Honeypot-Feld gegen Bots und eine strikte Ratenbegrenzung (max. 5 Versuche pro 10 Minuten) abgesichert werden.
- **Passwort-Hashing:** Passwörter von Benutzern sowie optionale Passwörter für öffentliche Freigabelinks werden ausschließlich mit **BCRYPT (`PASSWORD_BCRYPT`, Cost-Faktor 12)** gehasht gespeichert.
- **Schutz gegen Timing-Angriffe:** Bei Anmeldeversuchen mit einem nicht existierenden Benutzernamen führt `login.php` dennoch eine `password_verify()`-Operation gegen einen konstanten Dummy-BCRYPT-Hash aus.
- **Brute-Force-Schutz (Rate Limiting):** Sowohl beim Benutzer-Login (`login.php`) als auch bei passwortgeschützten Freigabelinks (`share.php`) werden fehlgeschlagene Passworteingaben nach **5 Fehlversuchen innerhalb von 5 Minuten** temporär gesperrt.
- **Optionale Zwei-Faktor-Authentifizierung (2FA) per E-Mail oder Google Authenticator (TOTP):**
  - **Modus 1 – Per E-Mail-Code (`two_factor_enabled = 1`):** Kryptografisch zufälliger 6-stelliger Einmalcode (`random_int`), als BCRYPT-Hash mit 10 Minuten Gültigkeit gespeichert und nach 5 Fehlversuchen gesperrt.
  - **Modus 2 – Per Authenticator-App / Google Authenticator (`two_factor_enabled = 2`, RFC 6238 TOTP):** Kryptografisch zufälliger 160-Bit-Base32-Schlüssel (`random_bytes(20)`), zeitbasierter 6-stelliger HMAC-SHA1-Code (30-Sekunden-Intervall, `±1` Zeitfenster Toleranz, zeitkonstanter Vergleich via `hash_equals()`). Der `otpauth://`-QR-Code wird **100 % lokal auf dem Server** als SVG nach ISO/IEC 18004 berechnet – es erfolgt keinerlei Übertragung des Geheimschlüssels an externe QR-APIs. Falls das Smartphone beim Login nicht verfügbar ist und eine E-Mail-Adresse hinterlegt wurde, steht ein gesicherter E-Mail-Notfallcode-Fallback bereit.
- **Sichere Passwort-Wiederherstellung (`forgot_password.php`):** Benutzer mit hinterlegter E-Mail-Adresse können einen Einmal-Reset-Link anfordern. Das 256-Bit-Zufallstoken (`bin2hex(random_bytes(32))`) wird in der Datenbank ausschließlich als `SHA-256`-Hash (`reset_token_hash`) mit 30 Minuten Gültigkeit (`reset_expires_at`) gespeichert, zeitkonstant per `hash_equals()` geprüft und nach einmaliger Verwendung sofort gelöscht. Eine einheitliche Bestätigungsmeldung verhindert Benutzer-/E-Mail-Enumeration.

### 3.2 Session-Management, Inaktivitäts-Timeout & Echtzeit-Rechteabgleich (`includes/auth.php`, `logout.php`)
- **Gehärtete Session-Konfiguration:** `session.use_strict_mode = 1`, `session.use_only_cookies = 1`, Cookie-Flags `HttpOnly = true`, `SameSite = Lax`, `Secure = true` (bei HTTPS).
- **Konfigurierbares Inaktivitäts-Timeout:** Jedes Benutzerkonto besitzt ein einstellbares Session-Timeout (`session_timeout`, Standard: 30 Minuten). Bei Überschreitung der Inaktivitätsdauer wird die Sitzung serverseitig automatisch beendet.
- **Session-Fixation-Schutz & Echtzeit-Rechteabgleich:** `session_regenerate_id(true)` beim Login sowie Echtzeit-Datenbankabgleich von Kontoexistenz und `is_admin`-Rolle bei jedem Seitenaufruf.

### 3.3 Autorisierung & 3-stufige Zugriffskontrolle (`includes/functions.php`, `share_action.php`, `calendar.php`, `messages.php`)
- **3-stufige Ordner- und Kalender-Freigaben:**
  - Stufe 1: **👁️ Nur Lesen** (`can_write = 0, can_delete = 0`)
  - Stufe 2: **✏️ Lesen & Schreiben/Bearbeiten** (`can_write = 1, can_delete = 0`)
  - Stufe 3: **🗑️ Lesen, Schreiben/Bearbeiten & Löschen** (`can_write = 1, can_delete = 1`)
- **Privacy-by-Default (`allow_user_sharing`):** Sowohl in `share_action.php`, `calendar.php` als auch in `messages.php` wird serverseitig geprüft, ob der Zielbenutzer direkte Freigaben/Nachrichten in seinen Datenschutzeinstellungen erlaubt hat (`allow_user_sharing = 1`).
- **Kryptografische Tokens:** Öffentliche Freigabelinks nutzen 128-Bit-Zufallstokens (`random_bytes(16)`), persönliche `.ics`-Kalender-Feeds nutzen 192-Bit-Zufallstokens (`random_bytes(24)`), verglichen per `hash_equals()`.

### 3.4 Dateisystem-, Upload- & Tresor-Sicherheit (`upload.php`, `vault_action.php`, `download.php`)
- **Pseudonymisierte `.bin`-Speicherung & `.htaccess`-Sperre:** Dateien werden als `mc_<uniqid>_<hex16>.bin` mit Modus `0640` abgelegt; `uploads/.htaccess` blockiert jeden direkten Webzugriff.
- **AES-256-GCM Zero-Knowledge-Tresor (`vault_action.php`):** Schlüsselableitung mittels `hash_pbkdf2('sha256', $password, $salt, 100000, 32, true)`, authentifizierte Verschlüsselung via `openssl_encrypt(..., 'aes-256-gcm', ...)` mit 96-Bit-IV und 128-Bit-Authentication-Tag. Das Passwort wird niemals gespeichert.
- **Abgesicherter 1-Klick-GitHub-Updater (`admin.php`):** Lädt Updates ausschließlich über HTTPS mit aktivierter TLS-Zertifikatsprüfung (`CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`), verhindert Pfad-Ausbrüche (`..`-Prüfung gegen Zip-Slip) und schützt `uploads/` sowie die Datenbank-Konstanten in `config/database.php`.

### 3.5 Schutz gegen Web-Schwachstellen (SQLi, XSS, CSRF, HTTP-Header)
- **SQL-Injection-Schutz:** Ausschließliche Verwendung echter **PDO Prepared Statements** (`PDO::ATTR_EMULATE_PREPARES => false`).
- **Cross-Site-Scripting (XSS):** Konsequentes HTML-Escaping über `e()` sowie `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` bei allen Inline-`<script>`-JSON-Variablen (`index.php`, `messages.php`, `calendar.php`) und `escapeHtml()` im DOM.
- **Cross-Site-Request-Forgery (CSRF):** Alle zustandsändernden `POST`-Endpunkte prüfen ein sitzungsgebundenes 256-Bit-CSRF-Token via `verifyCsrfToken()`.

---

## 4. Ergebnisse der durchgeführten Sicherheitsüberprüfungen (Security Audits)

| Nr. | Betroffene Komponente | Identifiziertes Risiko | Umgesetzte Härtungsmaßnahme | Status |
| :--- | :--- | :--- | :--- | :--- |
| **1** | `index.php` & `messages.php` | **Script-Tag-Ausbruch in Inline-JSON:** `json_encode()` ohne `JSON_HEX_TAG` bei Dateinamen oder `activeChannel` hätte `</script>` injizieren können. | Aktivierung von `JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT` in allen `<script>`-Blöcken. | **Behoben** |
| **2** | `index.php`, `messages.php`, `admin.php` | **DOM-basiertes XSS:** Dynamisch nachgeladene Daten im Teilen-Dialog, Chat-Polling und GitHub-Updater-Status. | Konsequente HTML-Maskierung (`escapeHtml` / `esc`) sämtlicher dynamischer Strings vor Zuweisung an `innerHTML`. | **Behoben** |
| **3** | `includes/functions.php` (`getBaseUrl`) | **Host-Header-Injection:** `$_SERVER['HTTP_HOST']` bei Link-Erzeugung. | Strikte Regex-Validierung (`/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/`) mit Fallback auf `localhost`. | **Behoben** |
| **4** | `theme.php` & `lang.php` | **Open Redirect:** Weiterleitung über `HTTP_REFERER`. | Whitelist-Prüfung auf lokale `.php`-Dateien derselben Domain. | **Behoben** |
| **5** | `logout.php` | **Forced-Logout (CSRF via GET):** Logout bei `GET`-Aufrufen. | Strikte Beschränkung auf `POST`-Anfragen mit gültigem CSRF-Token. | **Behoben** |
| **6** | `login.php`, `register.php`, `share.php` | **Brute-Force-Angriffe:** Unbegrenzte Passwort-/Code-Versuche. | Session-basiertes Rate-Limiting (max. 5 Fehlversuche) sowie Honeypot-Schutz bei der Registrierung. | **Behoben** |
| **7** | `calendar.php` & `share_action.php` | **Datenschutz & 3-Stufen-Rechte:** Umgehung der Sichtbarkeit oder fehlende Gruppen-Löschberechtigung bei Kalenderfreigaben. | Serverseitige Erzwingung von `allow_user_sharing = 1` sowie vollständige Prüfung von `can_write`/`can_edit` und `can_delete` für Benutzer und Gruppen. | **Behoben** |
| **8** | `admin.php` (`performGitHubUpdate`) | **Supply-Chain / Zip-Slip / Datenverlust beim Update:** Überschreiben von Nutzerdaten oder DB-Zugangsdaten. | Strikte TLS-Prüfung, `..`-Pfadfilter, Ausschluss von `uploads/` & `.git/` sowie automatische Beibehaltung von `DB_HOST`..`DB_PASS`. | **Behoben** |

---

## 5. Datensicherungs-, Papierkorb- und Löschkonzept

1. **Zweistufiges Löschen (Soft-Delete & Papierkorb):**
   - Beim Löschen einer Datei oder eines Ordners wird zunächst der Zeitstempel `deleted_at = NOW()` gesetzt.
   - Die Datei ist ab diesem Zeitpunkt nicht mehr über normale Ansichten oder Freigabelinks abrufbar, kann aber vom Eigentümer unter `trash.php` innerhalb von **30 Tagen (`TRASH_RETENTION_DAYS`)** wiederhergestellt werden.
2. **Automatische und endgültige Löschung (Purge):**
   - Die Funktion `purgeExpiredTrashFiles()` entfernt bei der Datenbankinitialisierung sowie beim Aufruf des Papierkorbs automatisch alle Dateien, deren `deleted_at` länger als 30 Tage zurückliegt, sowohl physisch aus `uploads/` (`unlink`) als auch aus der Datenbank (`DELETE FROM files`).
   - Benutzer können einzelne Dateien oder den gesamten Papierkorb jederzeit manuell endgültig leeren.
3. **Vollständige Kontolöschung:**
   - Löscht ein Administrator in `admin.php` ein Benutzerkonto, werden zuerst alle physischen Dateien dieses Benutzers im Verzeichnis `uploads/` gelöscht und anschließend der Benutzerdatensatz entfernt. Durch `ON DELETE CASCADE` werden sämtliche abhängigen Einträge (`files`, `user_folders`, `group_members`, `group_folder_permissions`, `shares`, `user_shares`) rückstandsfrei aus der Datenbank gelöscht.
