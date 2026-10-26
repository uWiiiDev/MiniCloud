# ☁️ MiniCloud – Leichtgewichtiger, sicherer Cloud-Speicher (PHP 8.2+ & MySQL)

**🇩🇪 Deutsch:** Eine schnelle, sichere und ressourcenschonende Nextcloud-Alternative für eigene Server und Standard-Webspaces (z. B. STRATO, IONOS, All-Inkl, Netcup) auf Basis von **PHP 8.2+** und **MySQL / MariaDB** – **100 % DSGVO-konform**, inklusive Datei- & Ordnerverwaltung, Tresor-Verschlüsselung (AES-256), Kalender mit `.ics`-Abo, integriertem Messenger mit System-Benachrichtigungen und **1-Klick-GitHub-Updater**.  
**🇬🇧 English:** A fast, secure, and lightweight self-hosted cloud storage, calendar, and messenger suite built with **PHP 8.2+** and **MySQL / MariaDB** — featuring AES-256 Vault encryption, 3-level folder/calendar sharing, iCal/ICS feed subscriptions, real-time chat with system notifications, 2FA via Email or Google Authenticator (RFC 6238 TOTP with local offline QR code), password reset, 1-Click GitHub Updater, 13 color themes, PWA support (Android & iOS), and **6 built-in languages** (🇩🇪 DE, 🇬🇧 EN, 🇫🇷 FR, 🇪🇸 ES, 🇮🇹 IT, 🇳🇱 NL). *(See [English Quickstart Guide](#-english-quickstart--installation-guide) below).*

---

## ✨ Kernfunktionen im Überblick

1. **Dateiverwaltung, MiniCloud Office Light, ZIP-Entpacker, Tresor-Modus & Direkt-Vorschau (`index.php`, `office.php`, `vault_action.php`)**
   - **📥 Direkte Ordner-Drag-&-Drop-Zonen, 📋 `Strg+V` Zwischenablage-Upload & kompakte Schnell-Buttons:** Jede Ordner-Zeile und Ordner-Kachel ist eine direkte Drop-Zone – ziehen Sie Dateien oder ganze Ordner vom Computer direkt auf einen Ordner, um sie ohne vorheriges Öffnen sofort dorthin hochzuladen (oder ziehen Sie vorhandene Cloud-Dateien per Drag & Drop in einen Unterordner). Screenshots und kopierte Bilder lassen sich jederzeit direkt per **`Strg+V`** in den geöffneten Ordner einfügen. Kompakte Buttons (`+ Dateien`, `📁+ Ordner`, `📄+ Office`) direkt in der Kopfzeile der Dateiliste sparen insbesondere auf Smartphones wertvollen Platz.
   - **📦 ZIP-Archive direkt im Browser inspizieren & entpacken:** Im Vorschau-Fenster jeder `.zip`-Datei zeigt der integrierte **ZIP-Archiv-Inspektor** alle enthaltenen Dateien samt Größe an und entpackt das Archiv per 1-Klick direkt auf dem Server (wahlweise in einen eigenen Unterordner oder direkt in das aktuelle Verzeichnis).
   - **📄 MiniCloud Office Light (Word `.docx`, Excel `.xlsx`, `.csv`, `.txt`, `.md`):** Direkt im Browser ohne externe Server neue Word-Dokumente, Excel-Tabellen oder Notizen anlegen sowie bestehende `.docx`- und `.xlsx`-Dateien online öffnen, bearbeiten (inkl. Rich-Text-Formatierung, Tabellen, mehreren Excel-Tabellenblättern und Live-Formeln wie `=SUMME(A1:A10)` / `=MITTELWERT(...)`) und per `Strg+S` speichern.
   - **🕓 Automatische Dateiversionierung („Zeitreise“) & 💬 Datei-Kommentare:** Beim Speichern in Office Light, beim erneuten Hochladen einer gleichnamigen Datei oder via WebDAV werden automatisch bis zu 5 frühere Versionen aufbewahrt (jederzeit per 1-Klick herunterladbar oder wiederherstellbar). Über das **`💬`-Symbol** können zudem direkt an jeder Datei Kommentare und Team-Notizen hinterlegt werden.
   - **🔒 Tresor-Modus (AES-256-GCM Zero-Knowledge):** Sensible Einzeldateien jederzeit mit einem persönlichen Tresor-Passwort verschlüsseln (Schlüssel wird niemals auf dem Server gespeichert).
   - **🖼️ Foto-Galerie / Kachel-Ansicht, ⭐ Favoriten & 🔎 Globale Suche:** Nahtloser Wechsel zwischen Tabellenansicht (`📋 Liste`) und Kachel-Ansicht (`🖼️ Kacheln`) mit Live-Bild-Thumbnails, Anheften wichtiger Dateien per Stern (`⭐ Favoriten`) sowie ordnerübergreifende Volltextsuche über alle Unterordner hinweg.
   - **☑️ Mehrfachauswahl (Sammel-/Batch-Aktionen):** Mehrere Dateien gleichzeitig per Checkbox auswählen und über die schwebende Aktionsleiste gemeinsam als `.zip` herunterladen, verschieben/kopieren oder in den Papierkorb legen.
   - Integriertes Vorschau-Modal für Bilder, PDFs, Videos, Audio, Text-/Codedateien und ZIP-Archive (inkl. Tastaturnavigation und Touch-Wischgesten auf Smartphones).
   - **⭐ Flexibel anheftbare Favoriten-Icons in Kopfzeile & Dateiliste:** Sowohl im neuen **`⊞ Module`-App-Launcher** der Kopfzeile als auch im **`⋯`-Datei-Aktionsmenü** (auch per **Rechtsklick** auf jede Zeile erreichbar) kann jedes Modul bzw. jede Datei-Aktion per Klick auf **`⭐` / `☆`** individuell nach außen gelegt oder wieder ins Menü aufgeräumt werden.
   - Verschieben, Kopieren und Umbenennen von Dateien und Unterordnern sowie Download ganzer Ordner als `.zip`-Archiv.
2. **Öffentliche Freigabelinks, 📱 Lokaler QR-Code, 🔥 Selbstzerstörung („Burn after reading“), 📥 Digitaler Briefkasten & 3-stufige Direktfreigaben (`share.php`, `share_action.php`)**
   - Kryptografisch sichere Freigabelinks (`bin2hex(random_bytes(16))`), optionaler BCRYPT-Passwortschutz, Ablaufdatum, **🔥 Download-Limit / Selbstzerstörung** (Link löscht sich z. B. nach `1×`, `3×`, `5×` oder `10×` Downloads automatisch selbst) sowie **📱 100 % lokal generierter SVG-QR-Code** zum direkten Scannen mit dem Smartphone.
   - **📥 Digitaler Briefkasten (File-Drop) für Ordner-Links:** Für öffentliche Ordner-Links wählbar zwischen **👁️ Nur Download**, **📤 Digitaler Briefkasten / Nur Hochladen** (Gäste können Dateien/Fotos ohne eigenes Konto zusenden, ohne andere Dateien im Ordner zu sehen) und **🔄 Download & Upload**.
   - Direktfreigabe einzelner Dateien oder ganzer Ordner an andere Cloud-Benutzer (*„Für mich freigegeben“*) mit **3 Berechtigungsstufen** für Ordner: **👁️ Nur Lesen**, **✏️ Lesen & Schreiben** (eigene Dateien hochladen & Unterordner anlegen) oder **🗑️ Lesen, Schreiben & Löschen**.
   - **Datenschutz-Standard (`allow_user_sharing = 0`):** Benutzer sind standardmäßig in der Teilen- und Chat-Auswahl anderer Nutzer verborgen und können ihre Sichtbarkeit selbst in den Einstellungen freigeben.
3. **Kalender, Terminserien & Smartphone-.ics-Abo (`calendar.php`)**
   - Übersichtliche Monats- und Listenansicht für Einzel- und Ganztagestermine mit Farbkodierung, Wiederholungen (täglich, wöchentlich, monatlich, jährlich) und verknüpften Cloud-Dateien.
   - **Kalender teilen (3 Berechtigungsstufen):** Eigenen Kalender für sichtbare Benutzer oder Benutzergruppen freigeben (*Nur Lesen*, *Lesen & Bearbeiten*, *Lesen, Bearbeiten & Löschen*) – private Termine (`🔒 Privat`) bleiben geschützt.
   - `.ics`-Import/Export sowie persönlicher **`.ics`-Abo-Link** zur Live-Synchronisation mit Smartphone (iOS/Android), Thunderbird oder Outlook.
4. **Integrierter Messenger, 🎙️ Sprachnachrichten, 📋 `Strg+V`-Screenshots, 📎 Dateianhänge & System-Benachrichtigungen (`messages.php`)**
   - 1:1-Echtzeit-Chat mit sichtbaren Cloud-Benutzern, direkte **🎙️ Sprachnachrichten-Aufnahme** über das Mikrofon (inkl. Inline-Audio-Player in der Sprechblase), sofortiges Einfügen von Screenshots per **`Strg+V`**, Versand von **📎 Datei- und Bildanhängen** sowie offizieller **Admin-Rundnachrichten-Kanal (`📢 Ankündigungen an alle`)**.
   - Ein-Klick-Funktion **„🗑️ Verlauf leeren“**, automatische **mobile 1-Spalten-Ansicht** auf Smartphones (mit ausgeblendetem Footer und dynamischer Anpassung an die Bildschirmtastatur) sowie konfigurierbare **System-Benachrichtigungen am PC & Mobilgerät** (Web Notifications API & Service Worker).
5. **📋 Aufgaben & Kanban-Board, 📌 Notizwand, 📇 Adressbuch (CardDAV & vCard-Abo) & 📡 WebDAV-Netzlaufwerk (`tasks.php`, `notes.php`, `contacts.php`, `carddav.php`, `webdav.php`)**
   - **📋 Aufgaben & Kanban-Board (`tasks.php`):** 3-Spalten-Projektboard (*📌 Offen*, *⏳ In Arbeit*, *✅ Erledigt*) mit HTML5 Drag & Drop, Prioritäten, Fälligkeitsdaten, interaktiven Unteraufgaben-Checklisten, Cloud-Dateianhängen und Teilen mit Benutzern oder Gruppen.
   - **📌 Notizwand / Pinboard (`notes.php`):** Farbige digitale Haftnotizen mit Anpinnen, `#Tags`, Live-Suche, Teilen im Team und 1-Klick-Export als Markdown-Datei (`.md`) direkt in den Cloud-Ordner `Notizen`.
   - **📇 Adressbuch, CardDAV-Server & `.vcf`-Abo (`contacts.php`, `carddav.php`):** Persönliche und geteilte Kontakte inkl. vCard (`.vcf`) Import/Export, automatischer Synchronisation von Kontakt-Geburtstagen (`🎂`) in den Kalender, **nativem CardDAV-Server (`carddav.php`)** für 2-Wege-Synchronisation mit iPhone/iPad, macOS, Thunderbird & Android (*DAVx⁵*) sowie **persönlichem `.vcf`-Abo-Link samt lokalem QR-Code**.
   - **📡 WebDAV-Schnittstelle (`webdav.php`):** Nativer WebDAV-Server-Endpunkt zum direkten Einbinden der Cloud als Netzlaufwerk unter Windows Explorer, macOS Finder, Linux und auf Smartphones.
6. **Papierkorb mit 30 Tagen Aufbewahrung (`trash.php`)**
   - Gelöschte Dateien wandern zunächst für 30 Tage in den Papierkorb und können jederzeit mit einem Klick wiederhergestellt werden.
6. **Passwort-Wiederherstellung, 2FA (E-Mail & Google Authenticator), Einstellungen & Optionale Registrierung (`forgot_password.php`, `login.php`, `settings.php`, `register.php`)**
   - **Passwort vergessen / Selbst-Reset (`forgot_password.php`):** Benutzer mit hinterlegter E-Mail-Adresse können auf der Login-Seite einen 30 Minuten gültigen, kryptografisch gesicherten Einmal-Link zum Zurücksetzen ihres Passworts anfordern.
   - **Zwei-Faktor-Authentifizierung (2FA) nach Wahl:** Pro Benutzer optional aktivierbarer 2FA-Schutz – wahlweise **per 6-stelligem E-Mail-Code** (lokales `mail()` oder externer SMTP-Server inkl. Test-E-Mail-Button) oder **per Authenticator-App (Google Authenticator, Microsoft Authenticator, Authy, Bitwarden nach RFC 6238 TOTP)** mit 100 % lokal auf dem eigenen Server erzeugtem SVG-QR-Code.
   - Individuelle Benutzereinstellungen für **Sitzungs-Timeout**, **Anzeigedauer von Statusmeldungen** (1–300 Sek. oder manuell), **Chat-Benachrichtigungsmodus** und **Teilen-Sichtbarkeit**.
   - Optional durch den Administrator zuschaltbare Selbstregistrierung mit **Einladungs-Code** und frei wählbarem Start-Speicherkontingent.
7. **1-Klick-GitHub-Updater, Rollback / Zeitreise, 💾 Datenbank-Backup (`.sql`), 📜 Sicherheits-Audit-Log & Live-Server-Monitoring (`admin.php`)**
   - Direkt in der Administration prüft MiniCloud auf neue Commits im GitHub-Repository und aktualisiert alle Programmdateien per 1-Klick-Update, während `uploads/` und die Datenbank-Zugangsdaten in `config/database.php` vollständig erhalten bleiben.
   - **🛡️ Automatischer Rollback & freie Versionsauswahl:** Vor jedem Update legt MiniCloud automatisch einen lokalen Sicherungspunkt (`uploads/.system_backups/`) an, sodass ein Update jederzeit per 1-Klick rückgängig gemacht werden kann. Zusätzlich lässt sich in der GitHub-Versionshistorie jeder beliebige frühere Stand gezielt auswählen und installieren (*„⏮️ Diesen Stand installieren“*).
   - **💾 1-Klick Datenbank-Backup (`.sql`) & 📜 Sicherheits- & Aktivitätsprotokoll (Audit-Log):** Vollständiger SQL-Export sämtlicher 15 Datenbanktabellen direkt im Admin-Bereich ohne phpMyAdmin sowie DSGVO-konformes Audit-Log (Logins, Freigaben, Selbstzerstörung, ZIP-Entpackung, Backups) mit anonymisierten IP-Adressen.
   - Echtzeit-Monitoring für CPU-Auslastung, Arbeitsspeicher (RAM), Festplattenspeicher und Cloud-Belegung.
8. **Smartphone-App (PWA), 6 Sprachen, 13 Farbthemes & 100 % DSGVO-konform**
   - Als Vollbild-App (PWA) auf Android, iPhone/iPad und Desktop installierbar.
   - **6 Sprachen integriert:** 🇩🇪 Deutsch, 🇬🇧 English, 🇫🇷 Français, 🇪🇸 Español, 🇮🇹 Italiano, 🇳🇱 Nederlands.
   - **13 augenschonende Farbthemes** (5 sanfte helle, 4 halbdunkle und 4 dunkle Designs inkl. *🌌 Mitternacht*).
   - Keine Google Fonts, keine externen CDNs (Tailwind CSS liegt lokal unter `assets/tailwind.js`) und keine Tracking-Cookies.

---

## 🛠️ Technische Voraussetzungen

Die Anforderungen sind bewusst minimal gehalten, damit MiniCloud auf nahezu jedem günstigen Webspace und jedem kleinen Server (selbst auf einem Raspberry Pi) flüssig läuft:

| Komponente | Voraussetzung | Hinweis für Einsteiger |
| :--- | :--- | :--- |
| **PHP** | **PHP 8.2** oder neuer (8.2, 8.3, 8.4) | Bei Hostern wie STRATO / IONOS im Kundenmenü einfach auf PHP 8.2+ einstellbar. |
| **PHP-Erweiterungen** | `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `openssl` | Auf Standard-Webspaces bereits ab Werk aktiviert. |
| **Datenbank** | **MySQL 5.7+ / 8.0+** oder **MariaDB 10.3+** | Eine einzige leere Datenbank genügt (Tabellen werden automatisch angelegt!). |
| **Webserver** | **Apache** (mit `.htaccess`) oder **Nginx** | HTTPS (SSL-Zertifikat, z. B. Let’s Encrypt) wird für die Smartphone-App (PWA) empfohlen. |

---

## 🚀 Schritt-für-Schritt-Installation (für Einsteiger)

Du brauchst **keine Programmierkenntnisse** und musst **keine Datenbank-Tabellen von Hand importieren**. MiniCloud erkennt beim ersten Aufruf automatisch, wenn die Datenbank noch leer ist, und legt alle 10 Tabellen sowie das erste Admin-Konto von selbst an (*Zero-Click-Setup*).

---

### Variante A: Installation auf einem normalen Webspace (z. B. STRATO, IONOS, All-Inkl)

#### Schritt 1: Neue Datenbank beim Hoster anlegen
1. Melde dich im Kundenmenü deines Webhosters (z. B. STRATO oder IONOS) an.
2. Gehe auf **Datenbanken / MySQL** und klicke auf **Neue Datenbank erstellen**.
3. Notiere dir diese **4 Angaben**, die dir dein Hoster anzeigt:
   - **Datenbank-Server / Hostname** *(bei STRATO z. B. `rdbms.strato.de`, bei vielen anderen `localhost` oder `127.0.0.1`)*
   - **Datenbank-Name** *(z. B. `dbs1234567`)*
   - **Benutzername** *(z. B. `dbu1234567`)*
   - **Passwort** *(das von dir vergebene Datenbank-Passwort)*

#### Schritt 2: Projekt herunterladen & Datenbank-Zugangsdaten eintragen
1. Lade dieses Projekt oben auf GitHub über den grünen Button **`<> Code` $\rightarrow$ `Download ZIP`** herunter und entpacke die ZIP-Datei auf deinem Computer.
2. Öffne die Datei **`config/database.php`** mit einem einfachen Texteditor (z. B. Editor / Notepad) und trage in **Zeile 11 bis 16** deine 4 Daten aus Schritt 1 ein:
   ```php
   define('DB_HOST', getenv('MINICLOUD_DB_HOST') ?: 'rdbms.strato.de'); // Dein Datenbank-Host
   define('DB_PORT', getenv('MINICLOUD_DB_PORT') ?: '3306');
   define('DB_SOCKET', getenv('MINICLOUD_DB_SOCKET') ?: $defaultSocket);
   define('DB_NAME', getenv('MINICLOUD_DB_NAME') ?: 'dein_datenbankname');
   define('DB_USER', getenv('MINICLOUD_DB_USER') ?: 'dein_datenbankbenutzer');
   define('DB_PASS', getenv('MINICLOUD_DB_PASS') ?: 'dein_datenbankpasswort');
   ```
3. Speichere die Datei `config/database.php`.

#### Schritt 3: Dateien auf deinen Webspace hochladen
1. Verbinde dich mit einem kostenlosen Programm wie **FileZilla** (per SFTP) mit deinem Webspace.
2. Lade alle Dateien und Ordner aus dem `MiniCloud`-Ordner in das gewünschte Verzeichnis deines Webspaces hoch (z. B. in einen Unterordner `/cloud/` oder direkt ins Hauptverzeichnis).
   - *Wichtig:* Achte darauf, dass der Ordner `uploads/` inklusive der Schutzdatei `uploads/.htaccess` mit hochgeladen wird.

#### Schritt 4: Im Browser öffnen – Fertig! 🎉
Öffne deine Webadresse im Browser (z. B. `https://deine-domain.de/cloud/`).  
MiniCloud richtet die Datenbank jetzt im Hintergrund komplett automatisch ein und zeigt dir direkt die Anmeldeseite!

---

### Variante B: Installation auf einem eigenen Linux-Server / VPS (Debian / Ubuntu)

Wenn du einen eigenen virtuellen Server (VPS) mit **Debian 12/13** oder **Ubuntu 22.04/24.04** nutzt, kannst du folgende Befehle direkt per Copy & Paste als `root` ausführen:

#### 1. Benötigte Pakete installieren (PHP, MariaDB, Git, Webserver)
```bash
apt update && apt install -y nginx mariadb-server git \
  php-fpm php-mysql php-mbstring php-gd php-zip php-xml php-curl
```

#### 2. Datenbank und Datenbank-Benutzer anlegen
*(Ersetze `StarkesPasswortHier!` durch ein eigenes sicheres Passwort)*
```bash
mysql -e "CREATE DATABASE minicloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'minicloud_user'@'localhost' IDENTIFIED BY 'StarkesPasswortHier!';"
mysql -e "GRANT ALL PRIVILEGES ON minicloud.* TO 'minicloud_user'@'localhost'; FLUSH PRIVILEGES;"
```

#### 3. MiniCloud herunterladen und Berechtigungen setzen
```bash
cd /var/www/html
git clone https://github.com/uWiiiMiniCloud/MiniCloud.git cloud
chown -R www-data:www-data /var/www/html/cloud
chmod 750 /var/www/html/cloud/uploads
```

#### 4. Datenbank-Passwort in `config/database.php` eintragen
Öffne `/var/www/html/cloud/config/database.php` (z. B. mit `nano /var/www/html/cloud/config/database.php`) und trage dein gewähltes Datenbank-Passwort bei `DB_PASS` ein.

#### 5. Wichtig bei Nutzung von Nginx (Upload-Ordner schützen & WebDAV/CardDAV aktivieren)
Wenn du **Nginx** statt Apache verwendest, füge in deiner Nginx-Konfiguration (`/etc/nginx/sites-available/...`) diese beiden Anpassungen ein:

1. **Upload-Ordner direkt sperren:**
```nginx
location ^~ /cloud/uploads/ {
    deny all;
    return 403;
}
```
2. **PHP-Block für WebDAV (`webdav.php/...`) & CardDAV (`carddav.php/...`) mit `PATH_INFO` erweitern:**
Damit Nginx auch Unterpfade hinter `.php` (z. B. `/cloud/webdav.php/Dokument.pdf` oder `/cloud/carddav.php/addressbooks/...`) an PHP-FPM weiterleitet und nicht auf die Startseite umleitet, ersetze `location ~ \.php$` durch:
```nginx
location ~ [^/]\.php(/|$) {
    fastcgi_split_path_info ^(.+?\.php)(/.*)$;
    if (!-f $document_root$fastcgi_script_name) {
        return 404;
    }
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php-fpm.sock; # Pfad ggf. an deine PHP-FPM-Version anpassen (z. B. php8.2-fpm.sock)
}
```
*(Bei Apache erledigen die mitgelieferten Dateien `.htaccess` und `uploads/.htaccess` beides bereits automatisch.)*

---

## 🔐 Erste Schritte nach der Installation (Wichtig!)

1. **Erster Login als Administrator:**
   Beim allerersten Start legt MiniCloud automatisch folgendes Standard-Administratorkonto an:
   - **Benutzername:** `admin`
   - **Initial-Passwort:** `Admin1234!`
2. **Sofort das Admin-Passwort ändern:**
   - Klicke oben rechts auf **Admin**.
   - Scrolle zum Abschnitt **„Bestehende Benutzer verwalten“**.
   - Gib in der Zeile von `admin` im Feld *„Neues Passwort (optional)“* dein eigenes, sicheres Passwort ein und klicke auf **Speichern**.
3. **Eigenen Namen, Logo & 2FA (Google Authenticator oder E-Mail) einrichten:**
   - Im übersichtlichen **5-Reiter-Admin-Bereich** kannst du unter **„🎨 Darstellung & Impressum“** deiner Cloud einen eigenen Namen und ein eigenes Logo geben.
   - Jeder Benutzer kann in seinen **Einstellungen (⚙️)** die **Zwei-Faktor-Authentifizierung (2FA) per Google Authenticator / TOTP-App** direkt über einen **lokal erzeugten QR-Code** aktivieren – ganz ohne E-Mail-Server!
   - Wenn du zusätzlich die **2FA per E-Mail** oder die **Passwort-vergessen-Funktion** nutzen möchtest, wähle im Admin-Reiter **„✉️ E-Mail & SMTP“** entweder den lokalen Server oder **Externer SMTP-Server** (und trage z. B. dein Postfach bei STRATO, IONOS oder Gmail ein).
   - Mit dem Button **„✉️ Test-E-Mail senden“** kannst du sofort prüfen, ob die E-Mail-Zustellung klappt!
4. **Große Dateien hochladen (Upload-Limit erhöhen):**
   - Im Projekt liegt bereits eine fertige Datei **`.user.ini`** (`upload_max_filesize = 512M`, `post_max_size = 512M`), die von den meisten Webhostern automatisch erkannt wird.

---

## 🌍 English Quickstart & Installation Guide

### System Requirements
- **PHP 8.2+** with standard extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `openssl`
- **MySQL 5.7+ / 8.0+** or **MariaDB 10.3+**
- **Apache** (with `.htaccess`) or **Nginx** (HTTPS recommended for PWA support)

### 3-Minute Setup
1. **Create an empty MySQL/MariaDB database** on your hosting panel or server.
2. **Configure database credentials** in `config/database.php` (lines 11–16: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
3. **Upload all files** to your web server and ensure the web server user (`www-data`) has write permissions on the `uploads/` directory.
4. **Open the URL in your browser:** MiniCloud automatically creates all required database tables on the first visit (*Zero-Click Auto-Migration*) and creates the initial admin account:
   - **Username:** `admin`
   - **Initial Password:** `Admin1234!` *(Please change immediately in the **Admin** panel!)*
5. **Switch Language:** Use the language selector in the top navigation bar to switch between **🇩🇪 Deutsch, 🇬🇧 English, 🇫🇷 Français, 🇪🇸 Español, 🇮🇹 Italiano, and 🇳🇱 Nederlands**.

---

## 📂 Projektstruktur

```text
MiniCloud/
├── assets/
│   └── tailwind.js           # Lokal eingebundenes Tailwind CSS (100 % DSGVO-konform ohne externe CDN-Aufrufe)
├── config/
│   └── database.php          # PDO-Datenbankverbindung, Auto-Migration aller 14 Tabellen & Konstanten
├── includes/
│   ├── auth.php              # Session-Management, Timeout, Admin-/Login-Guards, 13 Themes & CSRF-Schutz
│   ├── functions.php         # Hilfsfunktionen (Quota, 3-Stufen-Freigaben, AES-256-Tresor, TOTP/QR-Code, 2FA-Mail/SMTP)
│   ├── office_functions.php  # OpenXML-Parser & -Generator für Word (.docx), Excel (.xlsx) und CSV ohne Zusatz-Server
│   ├── i18n.php              # Mehrsprachigkeits-Modul für 6 Sprachen (DE, EN, FR, ES, IT, NL)
│   ├── header.php            # Responsives Layout, 13 Farbthemes, Sprachwahl, Statusmeldungen & PWA-Header
│   └── footer.php            # Footer mit Versionsanzeige, PWA-Dialog, Theme-Umschalter & Chat-System-Benachrichtigungen
├── sql/
│   └── schema.sql            # Vollständige MySQL-Datenbankstruktur (wird beim Erststart automatisch angelegt)
├── uploads/
│   ├── .htaccess             # Verhindert direkten Web-Zugriff & Skriptausführung auf Apache
│   └── index.php             # 403-Fallback-Schutz
├── index.php                 # Geschütztes Datei-Dashboard (Uploads, Office-Light-Start, Vorschau-Modal, Tresor, Freigaben)
├── office.php                # MiniCloud Office Light (Online-Editor für Word .docx, Excel .xlsx, .csv, .txt & .md)
├── calendar.php              # Kalender-Modul (Monats-/Listenansicht, Serien, 3-Stufen-Kalender-Freigabe, .ics-Abo)
├── messages.php              # Integrierter Messenger (1:1-Chat, Sprachnachrichten, Admin-Rundnachrichten, Mobil-Ansicht)
├── tasks.php                 # Aufgaben & Kanban-Board (Drag & Drop, Prioritäten, Checklisten, Dateianhänge, Teilen)
├── notes.php                 # Digitale Haftnotizwand / Pinboard (Farben, #Tags, Anpinnen, .md-Cloud-Export)
├── contacts.php              # Adressbuch & Kontakte (vCard Import/Export, .vcf-Abo mit QR-Code, Kalender-Geburtstage)
├── carddav.php               # Nativer CardDAV-Server (RFC 6352) für 2-Wege-Sync mit iOS, macOS, Thunderbird & Android
├── webdav.php                # Nativer WebDAV-Server zum Einbinden als Netzlaufwerk (Windows, macOS, Linux, Mobil)
├── settings.php              # Benutzereinstellungen (Sprache, 13 Themes, Timeout, Meldungsdauer, 2FA per E-Mail & Google Auth)
├── trash.php                 # Papierkorb mit 30 Tagen Aufbewahrungsfrist
├── folder_action.php         # Ordner erstellen, verschieben, kopieren und umbenennen
├── vault_action.php          # Einzeldatei-Verschlüsselung & Entschlüsselung mit AES-256-GCM (Zero-Knowledge)
├── share.php                 # Öffentliche Freigabe-Seite (optionaler BCRYPT-Passwortschutz & Ablaufdatum)
├── share_action.php          # API-Endpunkt für öffentliche Freigabelinks & 3-stufige Benutzer-Direktfreigaben
├── admin.php                 # 5-Reiter-Administration (Benutzer, Module, Branding, SMTP, 1-Klick-GitHub-Updater & Monitor)
├── login.php                 # Login mit Brute-Force-Schutz, Passwort-vergessen-Link & 2FA (E-Mail oder Google Authenticator)
├── forgot_password.php       # Passwort-Wiederherstellung per 30-Minuten-Einmal-Link (SHA-256-Token)
├── logout.php                # Sicheres Beenden der PHP-Session (CSRF-geschützt)
├── register.php              # Optionale Selbstregistrierung (standardmäßig aus, optional mit Einladungs-Code)
├── upload.php                # Upload-Controller (Einzeldatei, Mehrfachdateien, ganze Ordner, freigegebene Ordner)
├── download.php              # Geschützter Datei- & Ordner-ZIP-Download mit Berechtigungs- & Tresor-Prüfung
├── delete.php                # Soft-Delete in den Papierkorb & Löschen von Ordnern (inkl. 3-Stufen-Rechteprüfung)
├── theme.php                 # Speichert das gewählte Farbschema pro Benutzer
├── lang.php                  # Speichert die gewählte Sprache (DE, EN, FR, ES, IT, NL)
├── help.php                  # Integriertes Benutzer- und Administratorenhandbuch (6-sprachig)
├── impressum.php             # Im Admin-Bereich frei konfigurierbares Impressum
├── manifest.php              # Dynamisches Web-App-Manifest (PWA für Android & iOS)
├── app-icon.php              # Dynamischer PNG-Icon-Generator (180x180, 192x192, 512x512 & Maskable)
├── sw.js                     # Service Worker für PWA-Installation & native Chat-System-Benachrichtigungen
├── Datenverzeichnis.md       # Vollständige Dokumentation aller Datenbanktabellen und Felder
├── IT-Sicherheitskonzept.md  # Sicherheitsarchitektur, Kryptografie und Ergebnisse des Security Audits
├── LICENSE                   # MIT Open-Source-Lizenz
└── start.sh                  # Lokales Startskript für MariaDB + PHP-Entwicklungsserver
```

---

## 📜 Lizenz / License

Dieses Projekt steht unter der **[MIT-Lizenz](LICENSE)** (Copyright (c) 2026 Uwe Benzelrath).
