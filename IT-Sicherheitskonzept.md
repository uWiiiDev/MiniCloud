# IT-Sicherheitskonzept – MiniCloud

**Dokumentversion:** 1.0  
**Stand:** 22.10.2026  
**System:** MiniCloud – Leichtgewichtiger, selbstgehosteter Cloud-Speicher (PHP 8.2+ / MySQL & MariaDB)

---

## 1. Zielsetzung und Geltungsbereich

Dieses IT-Sicherheitskonzept beschreibt die technischen und organisatorischen Maßnahmen (TOM) zum Schutz der Vertraulichkeit, Integrität, Verfügbarkeit und Authentizität aller in **MiniCloud** gespeicherten Dateien, Ordnerstrukturen, Freigaben und Benutzerdaten. Es umfasst sowohl die architektonischen Grundsätze als auch die Ergebnisse der durchgeführten Quellcode-Sicherheitsüberprüfung (Security Audit) und deren Härtungsmaßnahmen.

---

## 2. Schutzbedarf und Sicherheitsziele

1. **Vertraulichkeit (Confidentiality):**
   - Persönliche Dateien und Ordner sind ausschließlich für den jeweiligen Eigentümer einsehbar, sofern keine explizite Freigabe (per Link oder Direktfreigabe) vorliegt.
   - Gruppenordner sind nur für berechtigte Gruppenmitglieder, explizit freigeschaltete Einzelbenutzer und Administratoren zugänglich.
2. **Integrität (Integrity):**
   - Schutz vor unbefugter Veränderung, Überschreibung oder Löschung von Dateien und Ordnern durch rollenbasierte Schreibberechtigungen (`can_write`) sowie CSRF- und SQL-Injection-Schutz.
3. **Verfügbarkeit (Availability):**
   - Schutz vor unbeabsichtigtem Datenverlust durch ein mehrstufiges Löschkonzept mit 30-tägiger Papierkorb-Aufbewahrung (Soft-Delete) sowie Benutzer-Speicherkontingente (Quotas).
4. **Authentizität & Nachvollziehbarkeit:**
   - Eindeutige Zuordnung jeder hochgeladenen Datei (`user_id`), jedes Gruppenordners (`created_by`) und jeder Freigabe (`owner_id`) zu einem authentifizierten Benutzerkonto.

---

## 3. Systemarchitektur & Technische Sicherheitsmaßnahmen

### 3.1 Authentifizierung & Passwortsicherheit (`login.php`, `admin.php`, `register.php`)
- **Geschlossener Benutzerkreis:** Eine öffentliche Selbstregistrierung (`register.php`) ist deaktiviert. Neue Benutzerkonten können ausschließlich durch authentifizierte Administratoren in `admin.php` angelegt werden.
- **Passwort-Hashing:** Passwörter von Benutzern sowie optionale Passwörter für öffentliche Freigabelinks werden ausschließlich mit **BCRYPT (`PASSWORD_BCRYPT`, Cost-Faktor 12)** gehasht gespeichert. Klartextpasswörter werden niemals protokolliert oder persistiert.
- **Schutz gegen Timing-Angriffe:** Bei Anmeldeversuchen mit einem nicht existierenden Benutzernamen führt `login.php` dennoch eine `password_verify()`-Operation gegen einen konstanten Dummy-BCRYPT-Hash aus, um Benutzernamen-Enumeration über Antwortzeiten zu verhindern.
- **Brute-Force-Schutz (Rate Limiting):** Sowohl beim Benutzer-Login (`login.php`) als auch bei passwortgeschützten Freigabelinks (`share.php`) werden fehlgeschlagene Passworteingaben gezählt. Nach **5 Fehlversuchen innerhalb von 5 Minuten** wird die weitere Passwortprüfung temporär gesperrt.
- **Optionale Zwei-Faktor-Authentifizierung per E-Mail (2FA):** Für jedes Benutzerkonto kann in `admin.php` eine E-Mail-Adresse hinterlegt und die E-Mail-2FA (`two_factor_enabled = 1`) aktiviert werden. Beim Login wird nach erfolgreicher Passwortprüfung ein kryptografisch zufälliger 6-stelliger Einmalcode (`random_int`) erzeugt, als BCRYPT-Hash mit 10 Minuten Gültigkeit gespeichert und per E-Mail versendet. Nach maximal 5 Fehlversuchen wird der Code automatisch gesperrt.

### 3.2 Session-Management & Echtzeit-Rechteabgleich (`includes/auth.php`, `logout.php`)
- **Gehärtete Session-Konfiguration:**
  - `session.use_strict_mode = 1` (verhindert die Annahme nicht initialisierter Session-IDs)
  - `session.use_only_cookies = 1` (keine Session-IDs in URLs)
  - Cookie-Flags: `HttpOnly = true` (kein Zugriff per JavaScript), `SameSite = Lax`, `Secure = true` (automatisch bei HTTPS).
- **Session-Fixation-Schutz:** Bei jedem erfolgreichen Login wird die Session-ID mittels `session_regenerate_id(true)` vollständig erneuert und ein neues 256-Bit-CSRF-Token (`bin2hex(random_bytes(32))`) erzeugt.
- **Echtzeit-Konten- und Rollenabgleich:** In `isLoggedIn()` wird einmal pro HTTP-Request gegen die Datenbank geprüft, ob das Benutzerkonto noch existiert und ob das Administrator-Recht (`is_admin`) weiterhin besteht. Wird ein Konto gelöscht oder ein Admin herabgestuft, greift der Entzug sofort beim nächsten Seitenaufruf.
- **CSRF-geschützter Logout:** Die Abmeldung (`logout.php`) akzeptiert ausschließlich `POST`-Anfragen mit gültigem CSRF-Token, leert `$_SESSION`, invalidiert das Session-Cookie und zerstört die Server-Session.

### 3.3 Autorisierung & Zugriffskontrolle (`includes/functions.php`, `download.php`, `folder_action.php`, `delete.php`)
- **Strikte Mandantentrennung:** Jede Datei- und Ordneroperation prüft serverseitig die Eigentümerschaft (`user_id = :uid`) bzw. die Gruppenordner-Berechtigung über `canUserAccessGroupFolder()` oder `getAuthorizedFileForDownload()`.
- **Abgestufte Gruppenordner-Rechte:** Gruppenordner unterstützen über `group_folder_permissions` getrennte Lese- (`can_write = 0`) und Schreibberechtigungen (`can_write = 1`), sowohl für ganze Benutzergruppen (`group_id`) als auch für einzelne Benutzer (`user_id`). Bei überlappenden Berechtigungen gilt `MAX(can_write)`.
- **Kryptografische Freigabelinks (`share_action.php`, `share.php`):** Öffentliche Freigabelinks verwenden 128-Bit-Zufallstokens (`bin2hex(random_bytes(16))`), unterstützen ein optionales Ablaufdatum (`expires_at`) sowie einen optionalen BCRYPT-Passwortschutz.

### 3.4 Dateisystem- & Upload-Sicherheit (`upload.php`, `uploads/.htaccess`, `download.php`)
- **Entkopplung von Dateiname und Speichername:** Hochgeladene Dateien werden niemals unter ihrem ursprünglichen Dateinamen im Dateisystem abgelegt. Stattdessen erzeugt `generateSafeStoredFilename()` einen eindeutigen, nicht ausführbaren Dateinamen mit der Endung `.bin` (Format: `mc_<uniqid>_<16-stelliger-hex-zufallswert>.bin`) und setzt die Dateirechte auf `0640`.
- **Verzeichnis-Isolierung (`uploads/.htaccess`):** Das Verzeichnis `uploads/` verbietet per `.htaccess` jeglichen direkten HTTP-Zugriff (`Require all denied` / `Deny from all`), deaktiviert Directory-Listing und CGI-Ausführung (`Options -Indexes -ExecCGI`) und blockiert zusätzlich ausführbare Dateiendungen (`.php`, `.phtml`, `.phar`, `.sh`, `.py`, `.pl`, `.cgi`).
- **Serverseitige MIME-Erkennung:** Der MIME-Typ wird nicht aus dem vom Browser gesendeten Header übernommen, sondern serverseitig über die Dateisignatur (`finfo(FILEINFO_MIME_TYPE)`) ermittelt.
- **Sichere Auslieferung & Inline-Vorschau (`download.php`):**
  - Standard-Downloads erzwingen `Content-Type: application/octet-stream`, `Content-Disposition: attachment` und `X-Content-Type-Options: nosniff`.
  - Die Inline-Vorschau (`?inline=1`) lässt nur eine strikte Whitelist sicherer Medienformate (Bilder ohne SVG, PDF, Audio, Video) zu und setzt eine restriktive `Content-Security-Policy`.
  - Textvorschauen (`?as_text=1`) werden als `text/plain; charset=UTF-8` mit `Content-Security-Policy: default-src 'none'; sandbox` ausgeliefert (maximal 512 KB).
- **Path-Traversal-Schutz:** Alle Ordner- und Dateinamen durchlaufen `sanitizeOriginalFilename()` und `extractSanitizedFolderPath()`. Steuerzeichen (`\x00-\x1F`), Backslashes, Segmente wie `.` oder `..` sowie SQL-Wildcard-Zeichen (`%`) werden konsequent entfernt.

### 3.5 Schutz gegen Web-Schwachstellen (SQLi, XSS, CSRF, HTTP-Header)
- **SQL-Injection-Schutz:** Ausschließliche Verwendung von **PDO Prepared Statements** mit deaktivierter Emulation (`PDO::ATTR_EMULATE_PREPARES => false`). Sortierspalten und Sortierrichtungen in `index.php` werden strikt gegen eine feste Whitelist (`$allowedSortCols`) geprüft.
- **Cross-Site-Scripting (XSS):**
  - Konsequentes HTML-Escaping sämtlicher Ausgaben über `e()` (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`).
  - Sichere JSON-Einbettung in `<script>`-Blöcken mittels `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
  - Serverseitige Prüfung hochgeladener SVG-Logos in `admin.php` auf aktive Skript- oder Event-Handler-Strukturen (`<script>`, `onload`, `onerror`, `javascript:`, `<foreignObject>`).
- **Cross-Site-Request-Forgery (CSRF):** Alle zustandsändernden Endpunkte (`upload.php`, `delete.php`, `folder_action.php`, `share_action.php`, `trash.php`, `admin.php`, `theme.php`, `logout.php`) prüfen per `verifyCsrfToken()` (`hash_equals`) ein sitzungsgebundenes 256-Bit-CSRF-Token.
- **Sicherheits-Header (`includes/auth.php`):**
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN` (Clickjacking-Schutz)
  - `Referrer-Policy: strict-origin-when-cross-origin`

---

## 4. Ergebnisse der durchgeführten Sicherheitsüberprüfung (Security Audit)

Im Rahmen der Sicherheitsüberprüfung des Quellcodes wurden folgende potenzielle Schwachstellen identifiziert und **vollständig behoben**:

| Nr. | Betroffene Komponente | Identifiziertes Risiko | Umgesetzte Härtungsmaßnahme | Status |
| :--- | :--- | :--- | :--- | :--- |
| **1** | `index.php` (Vorschau-JSON) | **Stored XSS / Script-Tag-Ausbruch:** `json_encode()` verwendete `JSON_UNESCAPED_SLASHES` ohne `JSON_HEX_TAG`. Ein Dateiname mit `</script>` hätte den `<script>`-Block vorzeitig schließen können. | Aktivierung von `JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT` für alle eingebetteten JSON-Daten. | **Behoben** |
| **2** | `index.php` (`loadShareDetails`) | **DOM-basiertes XSS:** Im Teilen-Modal wurden `l.url`, `l.expires_at` und `u.username` ohne HTML-Maskierung in `innerHTML` und ein Inline-`onclick`-Attribut eingesetzt. | Maskierung sämtlicher dynamischer Werte mittels `escapeHtml()` und Übergabe der URL über ein `data-copy-url`-Attribut. | **Behoben** |
| **3** | `includes/functions.php` (`getBaseUrl`) | **Host-Header-Injection:** `$_SERVER['HTTP_HOST']` wurde ungeprüft zur Erzeugung von Freigabelinks herangezogen. | Strikte Regex-Validierung (`/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/`) mit sicherem Fallback auf `localhost`. | **Behoben** |
| **4** | `theme.php` | **Open Redirect:** Weiterleitung über `$_SERVER['HTTP_REFERER']` ohne Prüfung der Zieldomain. | Whitelist-Prüfung auf lokale `.php`-Dateien derselben Domain (Fallback: `index.php`). | **Behoben** |
| **5** | `logout.php` | **Forced-Logout (CSRF via GET):** `logout.php` prüfte das CSRF-Token nur bei `POST`, führte den Logout aber auch bei `GET`-Aufrufen aus. | Strikte Beschränkung auf `POST`-Anfragen mit gültigem CSRF-Token. | **Behoben** |
| **6** | `login.php` & `share.php` | **Brute-Force-Angriffe:** Keine Begrenzung fehlgeschlagener Passwortversuche bei Login und passwortgeschützten Freigabelinks. | Session-basiertes Rate-Limiting (max. 5 Fehlversuche innerhalb von 5 Minuten, danach temporäre Sperre). | **Behoben** |
| **7** | `includes/auth.php` (`isLoggedIn`) | **Verzögerter Rechteentzug:** Gelöschte Benutzer oder entzogene Admin-Rechte blieben in bestehenden Sessions bis zum Logout wirksam. | Einmaliger Datenbankabgleich pro Request zur sofortigen Prüfung von Kontoexistenz und `is_admin`-Status. | **Behoben** |
| **8** | `includes/functions.php` & `delete.php` | **SQL-`LIKE`-Wildcard in Ordnerpfaden:** `%` in Ordnernamen konnte bei `LIKE :prefix`-Abfragen als Wildcard wirken; zudem Tippfehler bei `sanitizeFolderPathInput()` in `delete.php`. | Herausfiltern von `%` in `extractSanitizedFolderPath()` und Korrektur des Funktionsaufrufs in `delete.php`. | **Behoben** |
| **9** | `admin.php` & Verzeichnisrechte | **SVG-Logo-Upload & Dateisystemrechte:** SVG-Logos konnten ungeprüft aktive Tags enthalten; Verzeichnisse hatten `0777`-Rechte. | Abweisung von SVGs mit `<script>`, `onload`, `onerror`, `javascript:` oder `<foreignObject>`; Verzeichnisrechte auf `0750` gehärtet. | **Behoben** |

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
