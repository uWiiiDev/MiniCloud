# MiniCloud – Leichtgewichtiger, sicherer Cloud-Speicher (PHP 8.2+ & MySQL)

**🇩🇪 Deutsch:** Eine schnelle, sichere und ressourcenschonende Nextcloud-Alternative für eigene Server und Standard-Webspaces auf Basis von **PHP 8.2+** und **MySQL / MariaDB** (100 % DSGVO-konform, ohne externe Abhängigkeiten).  
**🇬🇧 English:** A fast, secure, and lightweight self-hosted cloud storage alternative built with **PHP 8.2+** and **MySQL / MariaDB** — featuring optional Email 2FA, group folders, public/internal file sharing, trash retention, 12 color themes, PWA support (Android & iOS), and **5 built-in languages** (🇩🇪 DE, 🇬🇧 EN, 🇫🇷 FR, 🇪🇸 ES, 🇳🇱 NL).

---

## Projektstruktur

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
│   └── schema.sql            # Vollständige MySQL-Datenbankstruktur (Zero-Click Auto-Migration integriert)
├── uploads/
│   ├── .htaccess             # Verhindert direkten Web-Zugriff & Skriptausführung auf Apache
│   └── index.php             # 403-Fallback-Schutz
├── index.php                 # Geschütztes Dashboard (Uploads, Vorschau-Modal mit Touch-Swipe, Teilen, Gruppenordner)
├── trash.php                 # Papierkorb mit konfigurierbarer Aufbewahrungsfrist (Standard: 30 Tage)
├── folder_action.php         # Ordner erstellen, verschieben, kopieren und umbenennen
├── share.php                 # Öffentliche Freigabe-Seite (optionaler BCRYPT-Passwortschutz & Ablaufdatum)
├── share_action.php          # API-Endpunkt für öffentliche Freigabelinks & interne Direktfreigaben
├── admin.php                 # Administrationsbereich (Benutzer, 2FA, Quotas, Gruppen, Gruppenordner, Branding)
├── login.php                 # Login mit Brute-Force-Schutz & optionaler 2FA per E-Mail-Sicherheitscode
├── logout.php                # Sicheres Beenden der PHP-Session
├── register.php              # Deaktivierte Selbstregistrierung (Verwaltung ausschließlich durch Admins)
├── upload.php                # Upload-Controller (Einzeldatei, Mehrfachdateien, ganze Ordner, Gruppenordner)
├── download.php              # Geschützter Datei- & Ordner-ZIP-Download mit Berechtigungsprüfung
├── delete.php                # Soft-Delete in den Papierkorb & Löschen von Ordnern
├── theme.php                 # Speichert das gewählte Farbschema pro Benutzer
├── help.php                  # Integriertes Benutzer- und Administratorenhandbuch
├── impressum.php             # Im Admin-Bereich frei konfigurierbares Impressum
├── manifest.php              # Dynamisches Web-App-Manifest (PWA für Android & iOS)
├── app-icon.php              # Dynamischer PNG-Icon-Generator (180x180, 192x192, 512x512 & Maskable)
├── sw.js                     # Service Worker für PWA-Installation & Offline-Fallback
├── Datenverzeichnis.md       # Vollständige Dokumentation aller Datenbanktabellen und Felder
├── IT-Sicherheitskonzept.md  # Sicherheitsarchitektur, Kryptografie und Schutzmaßnahmen
└── start.sh                  # Lokales Startskript für MariaDB + PHP-Entwicklungsserver
```

---

## Kernfunktionen

1. **Dateiverwaltung & Vorschau (`index.php`, `upload.php`, `download.php`, `folder_action.php`)**:
   - Drag-&-Drop-Upload für einzelne Dateien, Mehrfachauswahl und komplette Ordnerstrukturen.
   - Integriertes Vorschau-Modal für Bilder, PDFs, Videos, Audio und Text-/Codedateien (inkl. Tastaturnavigation und Touch-Wischgesten auf Smartphones).
   - Verschieben und Kopieren von Dateien und Unterordnern sowie Download ganzer Ordner als `.zip`-Archiv.
2. **Papierkorb mit automatischer Bereinigung (`trash.php`, `delete.php`)**:
   - Gelöschte Dateien wandern zunächst für 30 Tage in den Papierkorb und können jederzeit an ihren ursprünglichen Ort wiederhergestellt werden.
3. **Öffentliche Freigabelinks & Interne Direktfreigaben (`share.php`, `share_action.php`)**:
   - Kryptografisch sichere Tokens (`bin2hex(random_bytes(16))`), optionaler BCRYPT-Passwortschutz und Ablaufdatum.
   - Direktfreigabe einzelner Dateien oder ganzer Ordner an andere Cloud-Benutzer (*„Für mich freigegeben“*).
4. **Gruppen & Gruppenordner (`admin.php`)**:
   - Administratoren können Gruppenordner anlegen und gezielt einzelne Benutzer oder ganze Benutzergruppen berechtigen (*Lesen & Schreiben* oder *Nur Lesen*).
5. **Zwei-Faktor-Authentifizierung (2FA) per E-Mail (`login.php`, `admin.php`)**:
   - Pro Benutzer im Admin-Bereich optional aktivierbarer 2FA-Schutz mit 6-stelligem Einmalcode (BCRYPT-gehasht, 10 Minuten Gültigkeit, max. 5 Versuche).
6. **Progressive Web App (PWA) & Mobile Optimierung (`manifest.php`, `app-icon.php`, `sw.js`)**:
   - Direkt installierbar als Vollbild-App auf **Android** und **Apple iPhone/iPad** inklusive automatischer App-Icon-Generierung aus dem eigenen Logo.
7. **12 Farbthemes & Eigenes Branding (`theme.php`, `admin.php`)**:
   - 5 angenehm abgedunkelte helle Themes und 7 dunkle Themes sowie frei anpassbarer Anwendungstitel, Untertitel, eigenes Logo (inkl. Browser-Tab-Favicon) und Impressum.
8. **100 % DSGVO-konform & ohne externe Abhängigkeiten (`assets/tailwind.js`)**:
   - Keine externen Schriftarten (Google Fonts), keine externen CDNs und keine Tracking-Cookies – sämtliche Skripte und Styles werden direkt vom eigenen Server ausgeliefert.

---

## Lizenz

Dieses Projekt steht unter der **[MIT-Lizenz](LICENSE)** (Copyright (c) 2026 Uwe Benzelrath).

