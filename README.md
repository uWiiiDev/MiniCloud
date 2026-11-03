# ☁️ MiniCloud – Files-Only Edition (`files-only` Branch)

Willkommen zur **schlanken Datei-Edition (`files-only`)** von **MiniCloud**!
Dieser Git-Zweig enthält ausschließlich den reinen **Cloud-Dateimanager & File-Server** – komplett befreit von allen Zusatzmodulen (wie Office, Kalender, Spiele, Radio, Wetter, Finanzen oder KI-Assistent).

> 💡 **Hinweis:** Die vollständige All-in-One-Suite mit über 40 Modulen befindet sich weiterhin im Hauptzweig (`main`).

---

## ✨ Enthaltene Kernfunktionen (`files-only`)

- 📂 **Hierarchischer Datei- & Ordner-Manager** (`index.php`)
  - Drag & Drop Multi-File-Upload, Chunked-Uploads für große Dateien & Ordnerstruktur
  - Datei-Vorschau (Bilder, Audio, Video, PDF, Text/Code), Umbenennen, Verschieben & Kopieren
  - **ZIP-Archiv-Inspektor** (Inhalte von `.zip`-Archiven direkt im Browser ansehen & entpacken)
  - **Dateiversionierung** (automatische Historie & Wiederherstellung früherer Dateiversionen)
  - Favoriten / Sterne, Farb-Tags, Notizen & Kommentare pro Datei
- 🔗 **Sicheres Teilen & Gast-Abgabe (Dropzone)** (`share.php`, `drop.php`)
  - Öffentliche Download-Links für Dateien & ganze Ordner
  - Optionaler Passwortschutz, Ablaufdatum & Download-Limit
  - **Gäste-Upload-Links (Dropzone)**: Externe Personen können Dateien per Browser in einen freigegebenen Ordner hochladen, ohne ein eigenes Benutzerkonto zu benötigen
- 📡 **WebDAV-Netzlaufwerk** (`webdav.php`)
  - Einbindung der Cloud als direktes Netzlaufwerk unter Windows (Explorer), macOS (Finder), Linux (GNOME Files / Dolphin) sowie iOS & Android
- 🔍 **Blitzschnelle Dateisuche (`Strg + K`)** (`search_api.php`)
  - Globale Suche über Dateinamen, Ordner, Tags und den Volltext-Dokumentenindex
- 🗑️ **Papierkorb & Wiederherstellung** (`trash.php`)
  - Gelöschte Dateien wiederherstellen oder endgültig bereinigen
- 👥 **Benutzer-, Gruppen- & Quotenverwaltung** (`admin.php`, `settings.php`)
  - Rollen (`admin` / `user`), Speicherplatz-Kontingente (Quotas) pro Benutzer, Gruppen-Ordnerfreigaben
  - **2-Faktor-Authentifizierung (TOTP)**, Passkeys / WebAuthn & App-Passwörter für WebDAV
  - Audit-Log & Sicherheits-Protokollierung

---

## 🚀 Installation & Anforderungen

- **Webserver:** Nginx oder Apache mit PHP 8.1+ (`pdo_mysql` oder `pdo_sqlite`, `mbstring`, `zip`, `gd`)
- **Datenbank:** MariaDB / MySQL oder SQLite
- **Installation:**
  1. Repository im Zweig `files-only` klonen:
     ```bash
     git clone -b files-only https://github.com/uWiiiDev/MiniCloud.git
     ```
  2. Schreibrechte für `uploads/` und `includes/` setzen (`chown -R www-data:www-data uploads includes`).
  3. Im Browser `setup.php` aufrufen und den Datenbank- & Admin-Assistenten abschließen.
