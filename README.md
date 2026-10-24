# ☁️ MiniCloud – Leichtgewichtiger, sicherer Cloud-Speicher (PHP 8.2+ & MySQL)

**🇩🇪 Deutsch:** Eine schnelle, sichere und ressourcenschonende Nextcloud-Alternative für eigene Server und Standard-Webspaces (z. B. STRATO, IONOS, All-Inkl, Netcup) auf Basis von **PHP 8.2+** und **MySQL / MariaDB** – **100 % DSGVO-konform**, ohne externe Abhängigkeiten und ohne komplizierte Einrichtung.  
**🇬🇧 English:** A fast, secure, and lightweight self-hosted cloud storage alternative built with **PHP 8.2+** and **MySQL / MariaDB** — featuring optional Email 2FA (Postfix or SMTP), group folders, public/internal file sharing, trash retention, 12 color themes, PWA support (Android & iOS), and **5 built-in languages** (🇩🇪 DE, 🇬🇧 EN, 🇫🇷 FR, 🇪🇸 ES, 🇳🇱 NL). *(See [English Quickstart Guide](#-english-quickstart--installation-guide) below).*

---

## ✨ Kernfunktionen im Überblick

1. **Dateiverwaltung & Direkt-Vorschau (`index.php`)**
   - Drag-&-Drop-Upload für einzelne Dateien, Mehrfachauswahl und komplette Ordnerstrukturen.
   - Integriertes Vorschau-Modal für Bilder, PDFs, Videos, Audio und Text-/Codedateien (inkl. Tastaturnavigation und Touch-Wischgesten auf Smartphones).
   - Verschieben, Kopieren und Umbenennen von Dateien und Unterordnern sowie Download ganzer Ordner als `.zip`-Archiv.
2. **Papierkorb mit 30 Tagen Aufbewahrung (`trash.php`)**
   - Gelöschte Dateien wandern zunächst für 30 Tage in den Papierkorb und können jederzeit mit einem Klick an ihren ursprünglichen Ort wiederhergestellt werden.
3. **Öffentliche Freigabelinks & Interne Direktfreigaben (`share.php`)**
   - Kryptografisch sichere Freigabelinks (`bin2hex(random_bytes(16))`), optionaler BCRYPT-Passwortschutz und Ablaufdatum.
   - Direktfreigabe einzelner Dateien oder ganzer Ordner an andere Cloud-Benutzer (*„Für mich freigegeben“*).
4. **Gruppen & Gruppenordner (`admin.php`)**
   - Administratoren können gemeinsame Gruppenordner anlegen und gezielt einzelne Benutzer oder ganze Benutzergruppen berechtigen (*Lesen & Schreiben* oder *Nur Lesen*).
5. **Zwei-Faktor-Authentifizierung (2FA) per E-Mail (`login.php`, `admin.php`)**
   - Pro Benutzer optional aktivierbarer 2FA-Schutz mit 6-stelligem Einmalcode per E-Mail.
   - Unterstützt sowohl lokalen Versand (`PHP mail()` / Postfix) als auch **externe SMTP-Server** (z. B. `smtp.strato.de`, `smtp.ionos.de`) inklusive integriertem **Test-E-Mail-Button** im Admin-Bereich.
6. **Smartphone-App (PWA) für Android & Apple iOS (`manifest.php`, `app-icon.php`)**
   - Direkt über den Browser als Vollbild-App auf Android-Smartphones und iPhones/iPads installierbar – inklusive automatischer App-Icon-Erzeugung aus dem eigenen Logo.
7. **5 Sprachen, 12 Farbthemes & Eigenes Branding**
   - **5 Sprachen integriert:** 🇩🇪 Deutsch, 🇬🇧 English, 🇫🇷 Français, 🇪🇸 Español, 🇳🇱 Nederlands.
   - **12 augenschonende Farbthemes** (5 sanfte helle, 4 halbdunkle und 3 dunkle Designs).
   - Frei anpassbarer Anwendungstitel, Untertitel, eigenes Logo (inkl. Browser-Tab-Favicon) und Impressum.
8. **100 % DSGVO-konform & ohne externe Serveraufrufe**
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
git clone https://github.com/uwebenzelrath/MiniCloud.git cloud
chown -R www-data:www-data /var/www/html/cloud
chmod 750 /var/www/html/cloud/uploads
```

#### 4. Datenbank-Passwort in `config/database.php` eintragen
Öffne `/var/www/html/cloud/config/database.php` (z. B. mit `nano /var/www/html/cloud/config/database.php`) und trage dein gewähltes Datenbank-Passwort bei `DB_PASS` ein.

#### 5. Wichtig bei Nutzung von Nginx (Upload-Ordner schützen)
Wenn du **Nginx** statt Apache verwendest, füge in deiner Nginx-Konfiguration (`/etc/nginx/sites-available/...`) diesen Block ein, damit niemand Dateien im Ordner `uploads/` direkt per URL aufrufen kann:
```nginx
location ^~ /cloud/uploads/ {
    deny all;
    return 403;
}
```
*(Bei Apache erledigt das die mitgelieferte Datei `uploads/.htaccess` bereits automatisch.)*

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
3. **Eigenen Namen, Logo & E-Mail-Versand (2FA) einrichten:**
   - Ganz oben im **Admin-Bereich** kannst du deiner Cloud einen eigenen Namen und ein eigenes Logo geben.
   - Wenn du die **Zwei-Faktor-Authentifizierung (2FA) per E-Mail** nutzen möchtest, wähle unter *„✉️ E-Mail-Versand für 2FA-Codes“* entweder den lokalen Server oder **Externer SMTP-Server** (und trage z. B. dein Postfach bei STRATO, IONOS oder Gmail ein).
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
5. **Switch Language:** Use the language selector in the top navigation bar to switch between **🇩🇪 Deutsch, 🇬🇧 English, 🇫🇷 Français, 🇪🇸 Español, and 🇳🇱 Nederlands**.

---

## 📂 Projektstruktur

```text
MiniCloud/
├── assets/
│   └── tailwind.js           # Lokal eingebundenes Tailwind CSS (100 % DSGVO-konform ohne externe CDN-Aufrufe)
├── config/
│   └── database.php          # PDO-Datenbankverbindung, Auto-Migration, Speicher-Quota & Konstanten
├── includes/
│   ├── auth.php              # Session-Management, Admin-/Login-Guards, Theme-State & CSRF-Schutz
│   ├── functions.php         # Hilfsfunktionen (Quota, Gruppenordner-Rechte, 2FA-Mail/SMTP, Auto-Versionierung)
│   ├── i18n.php              # Mehrsprachigkeits-Modul für 5 Sprachen (DE, EN, FR, ES, NL)
│   ├── header.php            # Voll responsives Tailwind-CSS-Layout, 12 Themes, Sprachwahl, PWA-Header
│   └── footer.php            # Footer mit automatischer Versionsanzeige, PWA-Installationsdialog & Theme-Umschalter
├── sql/
│   └── schema.sql            # Vollständige MySQL-Datenbankstruktur (wird beim Erststart automatisch angelegt)
├── uploads/
│   ├── .htaccess             # Verhindert direkten Web-Zugriff & Skriptausführung auf Apache
│   └── index.php             # 403-Fallback-Schutz
├── index.php                 # Geschütztes Dashboard (Uploads, Vorschau-Modal mit Touch-Swipe, Teilen, Gruppenordner)
├── trash.php                 # Papierkorb mit konfigurierbarer Aufbewahrungsfrist (Standard: 30 Tage)
├── folder_action.php         # Ordner erstellen, verschieben, kopieren und umbenennen
├── share.php                 # Öffentliche Freigabe-Seite (optionaler BCRYPT-Passwortschutz & Ablaufdatum)
├── share_action.php          # API-Endpunkt für öffentliche Freigabelinks & interne Direktfreigaben
├── admin.php                 # Administrationsbereich (Benutzer, 2FA, SMTP, Quotas, Gruppen, Gruppenordner, Branding)
├── login.php                 # Login mit Brute-Force-Schutz & optionaler 2FA per E-Mail-Sicherheitscode
├── logout.php                # Sicheres Beenden der PHP-Session
├── register.php              # Deaktivierte Selbstregistrierung (Verwaltung ausschließlich durch Admins)
├── upload.php                # Upload-Controller (Einzeldatei, Mehrfachdateien, ganze Ordner, Gruppenordner)
├── download.php              # Geschützter Datei- & Ordner-ZIP-Download mit Berechtigungsprüfung
├── delete.php                # Soft-Delete in den Papierkorb & Löschen von Ordnern
├── theme.php                 # Speichert das gewählte Farbschema pro Benutzer
├── lang.php                  # Speichert die gewählte Sprache (DE, EN, FR, ES, NL)
├── help.php                  # Integriertes Benutzer- und Administratorenhandbuch
├── impressum.php             # Im Admin-Bereich frei konfigurierbares Impressum (inkl. Vorlage für Privat-Clouds)
├── manifest.php              # Dynamisches Web-App-Manifest (PWA für Android & iOS)
├── app-icon.php              # Dynamischer PNG-Icon-Generator (180x180, 192x192, 512x512 & Maskable)
├── sw.js                     # Service Worker für PWA-Installation & Offline-Fallback
├── Datenverzeichnis.md       # Vollständige Dokumentation aller Datenbanktabellen und Felder
├── IT-Sicherheitskonzept.md  # Sicherheitsarchitektur, Kryptografie und Schutzmaßnahmen
├── LICENSE                   # MIT Open-Source-Lizenz
└── start.sh                  # Lokales Startskript für MariaDB + PHP-Entwicklungsserver
```

---

## 📜 Lizenz / License

Dieses Projekt steht unter der **[MIT-Lizenz](LICENSE)** (Copyright (c) 2026 Uwe Benzelrath).
