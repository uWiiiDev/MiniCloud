# ☁️ MiniCloud — Fast, Secure & Self-Hosted Cloud Workspace (PHP 8.2+ & MySQL)

**MiniCloud** is a fast, lightweight, and privacy-first self-hosted cloud storage and collaboration suite built with **PHP 8.2+** and **MySQL / MariaDB**. Designed as a zero-bloat alternative to heavy cloud suites, it runs effortlessly on standard shared web hosting (e.g., STRATO, IONOS, All-Inkl, Netcup, cPanel) as well as any Linux VPS or Raspberry Pi — **100% GDPR-compliant**, zero external CDNs, and **Zero-Click Auto-Migration**.

🌍 **11 Built-in Languages:** 🇩🇪 Deutsch · 🇬🇧 English · 🇫🇷 Français · 🇪🇸 Español · 🇮🇹 Italiano · 🇳🇱 Nederlands · 🇵🇱 Polski · 🇹🇷 Türkçe · 🇺🇦 Українська · 🇸🇦 العربية (RTL) · 🇷🇺 Русский  
🎨 **13 Eye-Friendly Color Themes:** 5 Soft Light, 4 Balanced Mid-Tone & 4 Dark/Obsidian themes  
📱 **Installable Progressive Web App (PWA):** Works on Android, iOS (iPhone/iPad), Windows, macOS & Linux

---

## ✨ Complete Feature Suite (22 Integrated Modules)

### 1. 📁 File Manager, AES-256 Vault, ZIP Inspector & Smart Sharing (`index.php`, `vault_action.php`, `share.php`)
- **Direct Folder Drag & Drop, `Ctrl+V` Clipboard Upload & Action Toolbar:** Drop files or entire folder hierarchies directly onto any subfolder row or onto the page. Paste screenshots straight from your clipboard via `Ctrl+V`.
- **🔒 AES-256-GCM Zero-Knowledge Vault:** Encrypt sensitive individual files with a personal Vault password (`PBKDF2-HMAC-SHA256`, 100,000 iterations + 96-bit IV + 128-bit Auth Tag). Your encryption password is never stored on the server.
- **📦 In-Browser ZIP Inspector & Server-Side Extractor:** Inspect the contents of any `.zip` archive in the preview modal and extract it directly on the server with full Zip-Slip & quota protection.
- **🕓 Automatic File Versioning ("Time Travel" up to 5 versions) & 💬 File Comments:** Overwriting files via upload, Office Light, or WebDAV automatically preserves up to 5 previous snapshots that can be restored or downloaded with 1 click.
- **🔗 Public Share Links with Local SVG QR Code, 🔥 Self-Destruct ("Burn after reading") & 📥 File-Drop Mailbox:** Share files or folders via cryptographically secure links (`random_bytes(16)`), optional BCRYPT password, expiration date, maximum download limit, and 3 folder modes (*Download Only*, *Digital Mailbox / Upload Only*, or *Read & Write*).
- **👥 3-Tier Internal User & Group Sharing:** Share folders and files with other users or groups using granular permissions (*Read Only*, *Read & Write*, or *Read, Write & Delete*).

### 2. 📄 MiniCloud Office Light 5-in-1 Suite with Live Team Collaboration (`office.php`, `mindmap.php`)
- **100% Local 5-in-1 Document Suite:** Create and edit **Word (`.docx`)**, **Excel (`.xlsx`)**, **PowerPoint (`.pptx`)**, **Visio Diagrams & Org Charts (`.drawio`)**, **Interactive Mind Maps**, **CSV (`.csv`)**, **Markdown (`.md`)**, and **Text (`.txt`)** directly in the browser without external servers (no Microsoft 365 or Google Docs required).
- **📙 PowerPoint Light (`.pptx` Slide Editor & Fullscreen Beamer Mode):** Build 16:9 widescreen presentations with 6 slide layouts (*Title*, *Bullets*, *Two Columns*, *Cloud Image + Text*, *Big Stat / Quote*, *Section Header*), 6 presentation color themes, speaker notes, printable PDF handouts, and a fullscreen presenter view with a built-in laser pointer (`L` key). Generates standards-compliant OpenXML `.pptx` archives compatible with Microsoft PowerPoint, LibreOffice Impress, and Apple Keynote.
- **📐 Visio Light (`.drawio` Flowcharts, Process Maps & Org Charts):** Interactive vector canvas with 6 shape types (*Rectangle*, *Rounded*, *Decision Diamond*, *Start/End Stadium*, *Person / Org Role*, *Note Card*), smart connector arrows (*curved*, *orthogonal*, or *straight*; solid or dashed with custom labels), 6 color palettes, 1-click hierarchical auto-layout, and standalone SVG vector storage.
- **🧠 Integrated Mind Map Hub (`mindmap.php`):** Manage and launch all personal and shared Mind Maps directly from the Office Light hub, complete with infinite pan/zoom, cubic Bézier branches, 1-click conversion of nodes into Kanban tasks or sticky notes, and `.svg`/`.md`/`.json` cloud export.
- **🟢 Real-Time Team Presence & Cell/Paragraph/Slide/Shape Locking:** When multiple users edit a shared document simultaneously, Office Light displays active collaborators in real time, locks currently focused Excel cells, Word paragraphs, PowerPoint slides, or Visio shapes against accidental overwrites, and streams changes live to all screens.

### 3. 📅 Calendar, Recurring Events, 2-Way CalDAV & `.ics` Feed (`calendar.php`, `caldav.php`)
- Monthly and agenda views, recurring events, file attachments, automatic contact birthdays (`🎂`), 3-tier calendar sharing, personal `.ics` subscription feeds, and a **native 2-way CalDAV server (`caldav.php`)** for iOS, macOS Calendar, Thunderbird, and Android (*DAVx⁵*).

### 4. 💬 Real-Time Messenger, 1:1 & Group Voice Calls (WebRTC + Push-to-Talk) & Notifications (`messages.php`)
- **📞 1:1 Voice Calls & 🎧 Group Voice Rooms (STRATO-Optimized Hybrid):**
  - **⚡ Direct WebRTC-Mesh Audio & 🖥️ Screen Sharing:** Zero-latency peer-to-peer voice calls for 1:1 chats, user groups (`👥`), club boards (`🏛️`), and the open Cloud Lounge (`🌐`) with live speaking indicators (`🟢`) and zero audio bandwidth load on your PHP server.
  - **📻 Push-to-Talk ("Walkie-Talkie") Fallback:** 100% firewall-safe & large-group radio mode (hold button or `Spacebar` to transmit compact Opus audio packets to all room members).
  - **🔔 Global Incoming Call Ringing Banner:** Piggybacks onto the existing header poll with 0 extra HTTP requests and rings across all pages of MiniCloud.
- **Instant Chat & Voice Notes:** 1:1 instant chat with visible cloud users, **🎙️ microphone voice message recording** with inline audio playback, `Ctrl+V` screenshot pasting, cloud file attachments, official **Admin Broadcast channel (`📢 Announcements`)**, and native Web/Service-Worker desktop & mobile notifications.

### 5. 🏠 "My Day" Cockpit (`dashboard.php`) & ⊞ Dedicated Module Hub (`modules.php`)
- **My Day Cockpit (`dashboard.php`):** Customizable daily overview of upcoming appointments, birthdays, open Kanban tasks, active polls, pinned notes, and recent files.
- **Dedicated Module Hub (`modules.php`):** Full-page app directory (`⊞ Module`) with live search, category filters, **sorting by usage frequency (*🔥 Most used*, *🔤 A–Z*, or *✋ Custom Drag & Drop*)**, and **`⭐` pinning** to keep your favorite modules right in the top navigation bar.

### 6. 📋 Kanban Boards (`tasks.php`) & 🚀 Project Management (`projects.php`)
- **Multi-Board Kanban (`tasks.php`):** Drag-and-drop columns (*To Do*, *In Progress*, *Done*), priorities, due dates, interactive subtask checklists, cloud file attachments, and public/team board sharing.
- **Project Management (`projects.php`):** Visual **Gantt timeline**, milestones with progress bars, **time & expense tracking** (`hours` / `cost_amount`) against project budgets, and direct linking to Kanban boards and group folders.

### 7. 🧠 Interactive Mind Map Editor (`mindmap.php`)
- Infinite pan & zoom canvas with smooth cubic Bézier curves, keyboard shortcuts (`Tab` = child node, `Enter` = sibling node, `Del` = delete, `Space` = collapse/expand branch), 8 color palettes, node notes, **direct links to cloud files**, 4 starter templates, team/guest sharing, and **1-click export as `.svg`, `.md` (outline), or `.json` directly to your cloud storage**.

### 8. 💰 Budget & Household Planner (`budget.php`)
- Manage personal, family, or shared group budget books (`€`, `CHF`, `$`, `£`), log income and expenses across 13 categories with **direct cloud receipt attachment (`📎 PDF/Photo`)**, track **recurring subscriptions & cancellation notice periods (`🔄`)**, monitor **visual savings goals (`🎯`)**, and export monthly reports to **CSV (Excel)** directly into your cloud folder.

### 9. 🏛️ Club Management, Statistics & Board Role Delegation (`club.php`)
- Complete digital club & association administration with **granular multi-user board role delegation** (*1st Chairman = Full Access*, *Secretary = Member Directory & Minutes*, *Treasurer = Fee Tiers, Yearly Fee Generator & 1-Click Sync to `budget.php`*, *Equipment Manager = Volunteer Work Hours & Key/Equipment Lending*, *Auditor = Read-Only Inspection*).
- Manage active/passive/honorary members, SEPA mandate references, yearly fee runs, volunteer work hour quotas, club inventory & key handovers, and cloud-linked board minutes/bylaws with 1-click CSV export to your cloud storage.
- **📊 Club Statistics & Analytics:** Visual age distribution (7 age brackets + average/min/max age), department sizes, status & fee-tier breakdown, 5-year join/leave balance, and automatic member birthday & jubilee/anniversary lists (5, 10, 25, 40, 50+ years).
- **📤 Multi-Source Member Import:** Import members via CSV/Excel upload (auto-detects `;`/`,`/tab delimiters, German `Windows-1252` encoding, and `DD.MM.YYYY` dates), direct spreadsheet Copy & Paste (`Ctrl+V`), or 1-click sync from Cloud Contacts (`contacts.php`).

### 10. 🖼️ Photo Albums & Media Gallery (`gallery.php`)
- **Chronological Photo Wall & Virtual Albums:** Browse all non-encrypted images across your personal folders and shared group folders, and organize them into virtual albums (`gallery_albums` & `gallery_album_items`) without duplicating physical disk usage.
- **Fullscreen Lightbox, Auto-Slideshow & Local EXIF Inspector:** View photos in fullscreen with zoom, keyboard navigation, configurable automatic slideshow (3s / 5s / 8s), and 100% local PHP EXIF metadata inspection (date taken, camera model, aperture, exposure time, ISO, focal length, resolution).
- **📱 Public Guest Links, Local SVG QR Code & Party/Club Collection Albums:** Share albums via QR code (optionally password-protected) and optionally enable **📸 Guest Photo Upload (`allow_guest_upload`)** so event guests can upload photos directly from their smartphones into your shared album.

### 11. 📝 Online Forms, Event Registration & Membership Applications (`forms.php`)
- **Self-Hosted Form Builder (GDPR-Compliant Google/Microsoft Forms Alternative):** Create custom forms with text, multiline, dropdown, checkbox, number, date, and email fields, optional capacity limits (`max_submissions`), and registration deadlines (`closes_at`).
- **4 One-Click Starter Templates:** *🎉 Event / Club Festival Registration*, *🤝 Volunteer & Shift Sign-Up List*, *🏛️ Online Club Membership Application*, and *💬 Feedback & Survey Form*.
- **📱 Guest QR Code Participation, CSV Cloud Export & 1-Click Club Import:** Share forms with external guests via link or locally generated SVG QR code (`forms.php?token=...`), export responses to CSV or directly to your cloud (`Formulare/`), and import submitted membership applications into **Club Management (`club.php`)** with a single click!

### 12. 📚 E-Book Library & Built-in Reader (`ebooks.php`)
- Automatic catalog of all `.epub`, `.pdf`, `.txt`, and `.md` books in your cloud, local **EPUB ZIP/OPF cover & metadata extraction**, distraction-free reader with 4 eye-friendly reading themes (*Light*, *Sepia*, *Dusk*, *Night*), adjustable typography, **cross-device reading progress sync (`%`)**, and personal bookmarks with notes.

### 13. 🎮 Browser Games & Multiplayer Break Room (`games.php`)
- 100% offline-capable HTML5 games with cloud highscores, automatic save states, and **live turn-based multiplayer matches** against other cloud users:
  - **Solo Classics:** *2048 Cloud*, *Sudoku (4 difficulty levels + pencil notes)*, *Memory*, *Snake Retro*, *Block Fall (Tetris)*, *Minesweeper*, and *Solitaire (Klondike)*.
  - **👥 Live Multiplayer & AI Duels:** *Chess (with full move validation & AI)*, *Connect Four*, *Battleship*, and *Tic-Tac-Toe*.

### 14. 📊 Polls (`polls.php`), 🎤 Live Mentimeter (`menti.php`), 📄 PDF Tools (`pdf_tools.php`), 🔐 Password Manager (`passwords.php`), 📌 Notes (`notes.php`), 📇 Contacts/CardDAV (`contacts.php`, `carddav.php`), 📡 WebDAV (`webdav.php`) & 🤖 Help Assistant (`assistant.php`)
- **Polls & Date Finder (`polls.php`):** Self-hosted Doodle alternative with guest voting links and 1-click calendar transfer.
- **Live Mentimeter (`menti.php`):** Real-time audience voting via QR code & 6-digit PIN (Word Cloud, Bar Chart, Scale, Q&A with Upvoting, Live Quiz Leaderboard).
- **PDF Toolbox & Document Scanner (`pdf_tools.php`):** Camera/photo-to-PDF scanner with high-contrast B/W filter, PDF Merge, Split, Compression, and **irreversible GDPR Pixel Redaction (`⬛`)**.
- **Password Keychain (`passwords.php`):** Server-side AES-256-GCM encrypted password vault with password generator, age warnings, and team sharing.
- **Smart Help Assistant (`assistant.php`):** Instant offline Q&A assistant answering questions about all MiniCloud features in **11 languages**.

### 15. 🛡️ Enterprise-Grade Administration, User Locking & Delegated Admin Roles (`admin.php`)
- **Account Lockout (`🔒 Lock / 🔓 Unlock`):** Immediately lock compromised or inactive user accounts (with an optional lock reason). Locked users are blocked in real time across web sessions, login, password reset, `.ics`/`.vcf` feeds, and WebDAV/CardDAV/CalDAV.
- **🛡️ Delegated Partial Admin Permissions (`admin_permissions`):** Grant trusted non-admin users granular access to specific admin tabs (*👥 Users & Groups*, *🧩 Modules & Registration*, *🎨 Branding & Imprint*, *✉️ Email & SMTP*, or *🔄 Updates, Backup & System*) without granting full root administrator rights.
- **1-Click GitHub Updater & Instant Rollback Snapshots:** Update directly from GitHub while preserving `uploads/` and `config/database.php`, with automatic local `.zip` snapshots (`uploads/.system_backups/`) for 1-click rollback.

---

## 🛠️ System Requirements

MiniCloud is engineered to run smoothly on any affordable shared web hosting plan or Linux server:

| Component | Requirement | Notes |
| :--- | :--- | :--- |
| **PHP** | **PHP 8.2** or newer (8.2, 8.3, 8.4) | Standard on modern shared hosting (STRATO, IONOS, cPanel, Plesk) and Linux distributions. |
| **PHP Extensions** | `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `openssl` | Enabled by default on almost all web hosts. |
| **Database** | **MySQL 5.7+ / 8.0+** or **MariaDB 10.3+** | A single empty database is sufficient — all tables are created automatically on first run! |
| **Web Server** | **Apache** (with `.htaccess`) or **Nginx** | HTTPS (e.g., Let's Encrypt) is recommended for PWA installation and Web Notifications. |

---

## 🚀 Quickstart & Installation Guide

You do **not** need to manually import any SQL files or edit PHP files by hand. When you open MiniCloud for the first time without configured database credentials, it automatically launches the interactive **Web Installer (`setup.php`)**!

### Option A: Shared Web Hosting (e.g., STRATO, IONOS, All-Inkl, cPanel)

1. **Create a MySQL/MariaDB database** in your hosting control panel and note down your 4 credentials:
   - **Database Host** *(e.g., `localhost`, `127.0.0.1`, or `rdbms.strato.de`)*
   - **Database Name**
   - **Database Username**
   - **Database Password**
2. **Download MiniCloud** (`<> Code` $\rightarrow$ `Download ZIP`), extract it, and **upload all files via SFTP** (e.g., using FileZilla) to your webspace (including `uploads/.htaccess` and `config/.htaccess`).
3. **Open your URL in the browser** — MiniCloud automatically redirects to the **First-Run Web Installer (`setup.php`)**:
   - Click **🔌 Test Connection**, enter your desired **Cloud Name** and **Administrator username & password**, and click **🚀 Install & Start**.
   - MiniCloud saves your credentials in `config/db_credentials.php` (protected from web access and future updates), initializes all tables, and locks the installer automatically.

---

### Option B: Linux VPS / Dedicated Server (Debian 12/13 or Ubuntu 22.04/24.04)

#### 1. Install Required Packages
```bash
apt update && apt install -y nginx mariadb-server git \
  php-fpm php-mysql php-mbstring php-gd php-zip php-xml php-curl qpdf ghostscript
```

#### 2. Create Database & User
*(Replace `StrongPasswordHere!` with your own secure password)*
```bash
mysql -e "CREATE DATABASE minicloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'minicloud_user'@'localhost' IDENTIFIED BY 'StrongPasswordHere!';"
mysql -e "GRANT ALL PRIVILEGES ON minicloud.* TO 'minicloud_user'@'localhost'; FLUSH PRIVILEGES;"
```

#### 3. Clone Repository & Set Permissions
```bash
cd /var/www/html
git clone https://github.com/uWiiiMiniCloud/MiniCloud.git cloud
chown -R www-data:www-data /var/www/html/cloud
chmod 750 /var/www/html/cloud/uploads
```

#### 4. Configure `config/database.php`
Edit `/var/www/html/cloud/config/database.php` and set `DB_NAME`, `DB_USER`, and `DB_PASS`.

#### 5. Nginx Configuration (Protect `uploads/` & Enable `PATH_INFO` for WebDAV/CardDAV/CalDAV)
If you use **Nginx** instead of Apache, add these two blocks inside your `server { ... }` block:
```nginx
# 1. Block direct web access to uploaded binary files, config directory & hidden dotfiles (.git, .env)
location ^~ /cloud/uploads/ {
    deny all;
    return 403;
}
location ^~ /cloud/config/ {
    deny all;
    return 403;
}
location ~ /\.(?!well-known) {
    deny all;
    return 403;
}

# 2. Pass PATH_INFO and HTTP_AUTHORIZATION for WebDAV, CardDAV & CalDAV
location ~ [^/]\.php(/|$) {
    fastcgi_split_path_info ^(.+?\.php)(/.*)$;
    if (!-f $document_root$fastcgi_script_name) {
        return 404;
    }
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php-fpm.sock; # Adjust socket path if needed (e.g. php8.2-fpm.sock)
}
```
*(On Apache, the included `.htaccess` and `uploads/.htaccess` files handle both automatically.)*

---

## 🔐 First Steps After Installation

1. **Sign in with the initial Administrator account:**
   - **Username:** `admin`
   - **Initial Password:** `Admin1234!`
2. **Change the Admin Password Immediately:**
   - Open **Admin** in the top navigation bar $\rightarrow$ tab **👥 Users & Groups**, enter a strong new password for `admin`, and click **Save**.
3. **Enable Two-Factor Authentication (2FA):**
   - Go to **Settings (`⚙️`)** to enable **TOTP Authenticator App 2FA** (Google Authenticator, Authy, Bitwarden, etc. via locally generated SVG QR code) or **Email 2FA**.
4. **Customize Language, Theme & Modules:**
   - Switch between **11 languages** and **13 color themes** in the header or user settings, and click **`⊞ Module`** (`modules.php`) to pin your favorite tools to the top bar with a star (`⭐`).

---

## 🔒 Security & Performance Architecture

- **Strict SQL Injection Prevention:** 100% native PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`).
- **Pseudonymized Storage & Zero-Knowledge Vault:** Uploaded files are stored outside direct web reach as `uploads/mc_<uniqid>_<hex16>.bin` (`0640`) protected by `.htaccess` (`Require all denied`), with optional AES-256-GCM encryption.
- **Hardened Security Headers & CSRF Tokens:** Every state-changing request verifies a 256-bit session CSRF token; responses enforce `Content-Security-Policy: frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and `Permissions-Policy`.
- **Real-Time Lockout & Rate Limiting:** IP-based brute-force protection (`login_attempts`, max 5 failed attempts per 5 minutes) across web login, 2FA, Mentimeter PINs, and WebDAV/CardDAV/CalDAV endpoints, plus instant account lockout enforcement (`is_locked`).
- **High-Performance Request & Session Caching:** Global settings (`getAppSettings()`) are cached per request, and schema migration checks are cached per session so AJAX polling endpoints execute with minimal database overhead.

For full technical details, see [`IT-Sicherheitskonzept.md`](IT-Sicherheitskonzept.md), [`Datenverzeichnis.md`](Datenverzeichnis.md), and [`sql/schema.sql`](sql/schema.sql).

---

## 📜 License

Released under the **[MIT License](LICENSE)** — Copyright (c) 2026 Uwe Benzelrath.
