<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$appName     = $appSettings['app_title'] ?: APP_NAME;
$isAdminUser = isAdmin();
$lang        = getCurrentLang();
$dateStr     = date('d.m.Y');

$pageTitle = 'Hilfe & Dokumentation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto">
<?php if ($lang === 'en'): ?>
    <!-- Header (EN) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Help &amp; User Guide – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Overview of all features, keyboard shortcuts, security information, and the data dictionary.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Back to my files
        </a>
    </div>

    <!-- Quick Navigation (EN) -->
    <nav aria-label="Table of Contents" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Jump to:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Upload &amp; Folders</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Preview &amp; Sorting</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Move &amp; Copy</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Share &amp; Permissions</a>
            <a href="#kalender-chat" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📅 Calendar &amp; 💬 Chat</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Vault Mode</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Trash &amp; Settings</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administration</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ IT Security Concept</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Data Dictionary</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Upload Files &amp; Folders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Instant Drag &amp; Drop Upload:</strong> Drag individual files or entire folders from your file manager directly onto the dashed upload bar. The upload starts automatically.</li>
                <li><strong>Selection Buttons:</strong> Use <em>“+ Files”</em> to select multiple files, or <em>“Folder”</em> to upload an entire folder hierarchy including subfolders.</li>
                <li><strong>Set Target Folder:</strong> The <em>“Target folder (optional)”</em> field defaults to your currently open folder. You can also enter a new path (e.g. <code>Projects/2026</code>) directly.</li>
                <li><strong>Create Subfolders:</strong> Use the folder bar to create new folders or subfolders inside the currently active directory at any time.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">👁️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Preview, Browsing &amp; Sorting</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Instant Preview:</strong> Click the eye icon (<span class="text-violet-600 dark:text-violet-400 font-semibold">Preview</span>), the file type badge, or the file name to open the preview modal for images, PDFs, videos, audio files, and text/source code files.</li>
                <li><strong>Browse Through Folder:</strong> When multiple files are in the current folder, use the Next/Previous buttons or arrow keys <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> and <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> closes the preview).</li>
                <li><strong>Table Sorting:</strong> Click the column headers <em>Name</em>, <em>Folder</em>, <em>Size</em>, or <em>Type / Date</em> to sort ascending or descending (<code>↑</code> / <code>↓</code>).</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Move or Copy Files &amp; Folders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Compact ⇄ Icon in Actions Column:</strong> Click the <strong>⇄</strong> icon button in the <em>Actions</em> column of any file or subfolder row to open the move/copy dialog and choose the desired target folder under <strong>⇄ Move</strong> or <strong>📋 Copy</strong>.</li>
                <li><strong>Entire Folders Including Contents:</strong> Both via the <strong>⇄</strong> icon in the top folder bar and in every subfolder row, complete folders including all subfolders and files can be moved or copied.</li>
                <li><strong>Shared Folders:</strong> Move or copy files and folders between your personal folders and shared folders where you have write permissions.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Sharing &amp; 3-Level Permissions</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Public Share Links:</strong> Click the share icon to generate a cryptographically secure link for files or folders – optionally protected by a <strong>password</strong> and <strong>expiration date</strong>.</li>
                <li><strong>Direct Sharing with Cloud Users (3 Levels):</strong> Share files or folders directly with other users who have enabled their privacy visibility in Settings. For shared folders, choose between <strong>👁️ Read Only</strong>, <strong>✏️ Read &amp; Write</strong> (upload &amp; create subfolders), or <strong>🗑️ Read, Write &amp; Delete</strong>.</li>
                <li><strong>Privacy Protection by Default:</strong> New accounts are hidden from the sharing/chat user list by default and can opt in anytime in Settings.</li>
            </ul>
        </section>

        <section id="kalender-chat" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3 md:col-span-2">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 font-bold">📅</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Calendar &amp; Integrated Messenger (Chat)</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Calendar &amp; Appointments (<a href="calendar.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Calendar</a>):</strong> Manage personal, group, and shared events in month or list view with recurrence rules (daily, weekly, monthly, yearly), colors, file attachments, <code>.ics</code> import/export, and a personal <code>.ics</code> subscription link for smartphones, Thunderbird, or Outlook. Share your calendar with users or groups using 3 permission levels (Read, Edit, Edit &amp; Delete).</li>
                <li><strong>Messenger &amp; Announcements (<a href="messages.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Chat</a>):</strong> Exchange real-time 1:1 messages with visible cloud users or receive official admin broadcasts. Includes one-click chat history clearing, automatic 1-column mobile view, and configurable desktop/mobile system notifications.</li>
            </ul>
        </section>
    </div>

    <!-- Vault Mode (EN) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vault Mode – Single-File Encryption (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Protect sensitive documents with a password known only to you (Zero-Knowledge principle)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Encrypt via Lock Icon:</strong> After uploading a file, click the <strong>🔒 lock icon</strong> in the <em>Actions</em> column of the file list to encrypt it using <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> with your personal Vault password.</li>
            <li><strong>Decrypt &amp; Share:</strong> Vault-protected files carry the <strong>🔒 Vault</strong> badge. When downloading (including via public share links or direct shares), the Vault password is requested. You can also permanently remove Vault protection at any time via the lock icon.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Important Notice Regarding Your Vault Password (Zero-Knowledge):</strong><br>
            Your chosen Vault password is <strong>never stored anywhere on the server</strong> – neither in the database nor in any configuration file. Immediately after encryption, the key is wiped from memory. <strong>If you forget the Vault password for a file, nobody – not even the administrator – can recover the file!</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Trash, Themes, Notifications &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30-Day Trash Retention:</strong> Deleted files go to the <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Trash</a> first, where they can be restored with one click for 30 days or permanently deleted.</li>
                <li><strong>Settings (⚙️ Gear Icon):</strong> In <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Settings</a>, choose between 5 languages, 13 eye-friendly color themes (5 light, 8 dark incl. <em>🌌 Midnight</em>), configure status message display duration (1–300s or manual), session timeout, chat system notifications, privacy visibility, and password.</li>
                <li><strong>2FA via Email or Google Authenticator (TOTP):</strong> Under <em>Security &amp; 2FA</em> in <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Settings</a>, you can choose between <strong>✉️ Email code</strong> (6-digit code via email) or <strong>📱 Authenticator App (Google Authenticator, Microsoft Authenticator, Authy, Bitwarden, Apple Passwords)</strong>. Simply scan the 100% locally generated QR code (or enter the Base32 key) and confirm with the 6-digit app code. If your phone is unavailable at login, you can optionally request an emergency code via email.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Administrator Notes</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 Thematic Admin Tabs:</strong> <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a> is organized into 5 clear tabs: <em>👥 Users &amp; Registration</em>, <em>🧩 Modules &amp; Features</em>, <em>🎨 Branding &amp; Legal Notice</em>, <em>✉️ Email &amp; SMTP</em>, and <em>🖥️ System, Update &amp; Status</em>.</li>
                <li><strong>1-Click GitHub System Updater &amp; Server Monitor:</strong> Check for new commits on GitHub and update all application files with one click while preserving <code>uploads/</code> and database credentials. Monitor CPU, RAM, and disk usage live.</li>
                <li><strong>Users, Groups, Quotas &amp; 2FA Reset:</strong> Create accounts, assign individual storage quotas (in MB), manage user groups, reset passwords, or switch/disable a user’s 2FA method (Email / TOTP).</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <!-- IT Security Concept (EN) -->
    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT Security Concept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technical and organizational security measures (TOM) and audit results</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Status: <?= e($dateStr) ?> &bull; Audited &amp; Hardened
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentication, 2FA (Email &amp; TOTP) &amp; Brute-Force Protection</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Closed user system without public self-registration. Passwords are hashed exclusively with <strong>BCRYPT (cost factor 12)</strong>. Users can optionally enable Two-Factor Authentication via <strong>Email code</strong> or <strong>Authenticator App (Google Authenticator / RFC 6238 TOTP with local offline QR code generation)</strong>. After <strong>5 failed attempts within 5 minutes</strong>, an automatic temporary lockout is enforced.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Session &amp; Access Control (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Sessions enforce <code>session.use_strict_mode</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>, and <code>Secure</code> over HTTPS. Upon login, <code>session_regenerate_id(true)</code> prevents session fixation. Every request verifies user existence and admin status in real time against the database.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. File Storage Isolation &amp; Vault Encryption</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Uploaded files are stored with cryptographically random filenames and a non-executable <code>.bin</code> extension (permissions <code>0640</code>). The <code>uploads/</code> directory is blocked from direct web access via <code>.htaccess</code>. Sensitive files can additionally be encrypted with <strong>AES-256-GCM (Zero-Knowledge)</strong>.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protection Against SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    All database queries use native <strong>PDO Prepared Statements</strong>. All outputs are escaped via <code>htmlspecialchars</code> or <code>JSON_HEX_TAG</code>. Every state-changing request requires a <code>POST</code> request with a 256-bit CSRF token (<code>hash_equals</code>). Paths are sanitized against <code>..</code> and SQL wildcards.
                </p>
            </div>
        </div>
    </section>

    <!-- Data Dictionary (EN) -->
    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Data Dictionary &amp; Storage Inventory</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Overview of all processed data entities, database tables, storage locations, and retention periods</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Table / Storage Location</th>
                        <th class="py-3 px-4">Key Fields / Contents</th>
                        <th class="py-3 px-4">Purpose &amp; Personal Data Reference</th>
                        <th class="py-3 px-4">Retention / Deletion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, session_timeout, flash_duration, chat_notify_mode, allow_user_sharing, calendar_token, storage_quota</code></td>
                        <td class="py-3 px-4">User authentication, optional 2FA (Email or Google Authenticator / TOTP), password reset, role control, quota, UI preferences &amp; privacy settings</td>
                        <td class="py-3 px-4">Until user account deletion by administrator (cascade delete)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>id, user_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date, deleted_at</code></td>
                        <td class="py-3 px-4">Metadata of uploaded files &amp; directory hierarchy (incl. empty folders)</td>
                        <td class="py-3 px-4">Active until deleted; automatic permanent deletion after 30 days in Trash (<code>deleted_at</code>)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">shares &amp; user_shares</td>
                        <td class="py-3 px-4"><code>share_token, password_hash, expires_at</code> / <code>owner_id, shared_with_user_id, can_write, can_delete</code></td>
                        <td class="py-3 px-4">Public share links and internal 3-level direct shares between cloud users</td>
                        <td class="py-3 px-4">Until manual revocation, expiration (<code>expires_at</code>), or deletion of file/folder</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">calendar_events &amp; calendar_shares</td>
                        <td class="py-3 px-4"><code>title, start_at, end_at, recurrence, group_id, shared_user_id, attached_file_id</code> / <code>owner_user_id, target_user_id, target_group_id, can_edit, can_delete</code></td>
                        <td class="py-3 px-4">Appointments, recurring events, and 3-level calendar sharing permissions</td>
                        <td class="py-3 px-4">Until event/share deletion or user account deletion</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">messages &amp; message_broadcast_reads</td>
                        <td class="py-3 px-4"><code>sender_id, recipient_id, is_broadcast, message_text, is_read, created_at</code></td>
                        <td class="py-3 px-4">Direct 1:1 chat messages and administrator announcements to all users</td>
                        <td class="py-3 px-4">Until individual deletion, chat history clearing, or user account deletion</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Filesystem: uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Physical binary data of uploaded files (pseudonymized filename, optionally AES-256 encrypted)</td>
                        <td class="py-3 px-4">Physical deletion (<code>unlink</code>) when emptying Trash, after 30 days in Trash, or upon account deletion</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'fr'): ?>
    <!-- Header (FR) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Aide &amp; Manuel d’utilisation – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Toutes les fonctionnalités, raccourcis clavier, informations de sécurité et dictionnaire des données.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Retour à mes fichiers
        </a>
    </div>

    <nav aria-label="Sommaire" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Accès rapide :</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Envoi &amp; Dossiers</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Aperçu &amp; Tri</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Déplacer &amp; Copier</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Partage &amp; Groupes</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Mode Coffre</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Corbeille &amp; Paramètres</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administration</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ Concept de sécurité</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Dictionnaire des données</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Téléverser des fichiers &amp; dossiers</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Glisser-déposer instantané :</strong> Faites glisser des fichiers ou des dossiers entiers directement sur la zone en pointillés. L’envoi démarre automatiquement.</li>
                <li><strong>Boutons de sélection :</strong> Utilisez <em>« + Fichiers »</em> pour plusieurs fichiers ou <em>« Dossier »</em> pour envoyer toute une arborescence.</li>
                <li><strong>Dossier cible :</strong> Le champ <em>« Dossier cible (optionnel) »</em> correspond par défaut au dossier actif, mais vous pouvez saisir un nouveau chemin (ex. <code>Projets/2026</code>).</li>
                <li><strong>Sous-dossiers :</strong> Créez de nouveaux sous-dossiers à tout moment via la barre de dossier active.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">👁️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Aperçu, Navigation &amp; Tri</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Aperçu direct :</strong> Cliquez sur l’icône d’œil (<span class="text-violet-600 dark:text-violet-400 font-semibold">Aperçu</span>), le badge de type ou le nom du fichier pour prévisualiser images, PDF, vidéos, fichiers audio et textes/codes sources.</li>
                <li><strong>Navigation :</strong> Parcourez les fichiers du dossier avec les flèches <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> et <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> ferme l’aperçu).</li>
                <li><strong>Tri du tableau :</strong> Cliquez sur les en-têtes <em>Nom</em>, <em>Dossier</em>, <em>Taille</em> ou <em>Type / Date</em> pour trier par ordre croissant ou décroissant.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Déplacer ou copier des fichiers &amp; dossiers</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Icône ⇄ dans la colonne Actions :</strong> Cliquez sur le bouton <strong>⇄</strong> dans la colonne <em>Actions</em> d’un fichier ou sous-dossier pour ouvrir la boîte de dialogue et choisir le dossier cible sous <strong>⇄ Déplacer</strong> ou <strong>📋 Copier</strong>.</li>
                <li><strong>Dossiers complets :</strong> Déplacez ou copiez des dossiers entiers avec tout leur contenu via l’icône <strong>⇄</strong> de la barre de dossier active ou de la ligne du sous-dossier.</li>
                <li><strong>Entre cloud personnel &amp; dossiers de groupe :</strong> Transférez librement vos fichiers et dossiers entre votre espace personnel et les dossiers de groupe (avec droit d’écriture).</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Partages &amp; Dossiers de groupe</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Liens publics :</strong> Créez un lien sécurisé pour un fichier ou un dossier – avec <strong>mot de passe</strong> et <strong>date d’expiration</strong> optionnels.</li>
                <li><strong>Partage direct entre utilisateurs :</strong> Partagez des fichiers ou dossiers avec d’autres membres enregistrés (visibles sous <em>« Partagés avec moi »</em>).</li>
                <li><strong>Dossiers de groupe :</strong> Les dossiers d’équipe offrent un accès en lecture seule (👁️ Lecture seule) ou complet (✏️ Lecture &amp; Écriture).</li>
            </ul>
        </section>
    </div>

    <!-- Mode Coffre (FR) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Mode Coffre – Chiffrement individuel (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Protégez vos documents sensibles avec un mot de passe connu de vous seul (principe Zero-Knowledge)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Chiffrer via l’icône cadenas :</strong> Une fois votre fichier téléversé, cliquez sur l’<strong>icône cadenas 🔒</strong> dans la colonne <em>Actions</em> pour le chiffrer avec <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong>.</li>
            <li><strong>Déchiffrer &amp; Partager :</strong> Les fichiers protégés portent le badge <strong>🔒 Coffre</strong>. Le mot de passe du coffre est demandé au téléchargement. Vous pouvez aussi supprimer définitivement la protection à tout moment via l’icône cadenas.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Avertissement important concernant le mot de passe du coffre (Zero-Knowledge) :</strong><br>
            Votre mot de passe Coffre n’est <strong>jamais enregistré sur le serveur</strong> – ni dans la base de données, ni dans un fichier de configuration. <strong>Si vous oubliez le mot de passe d’un fichier, personne – pas même l’administrateur – ne pourra restaurer le fichier !</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Corbeille, Thèmes, Langue &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Conservation 30 jours dans la corbeille :</strong> Les fichiers supprimés sont conservés 30 jours dans la <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Corbeille</a> avant suppression définitive.</li>
                <li><strong>Paramètres &amp; 2FA (E-mail ou Google Authenticator) :</strong> Cliquez sur l’engrenage en haut à droite (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Paramètres</a>) pour choisir la langue, le thème visuel, modifier votre mot de passe ou activer l’<strong>authentification à deux facteurs (2FA par code e-mail ou par application Google Authenticator / TOTP avec QR code généré localement)</strong>.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Notes pour les administrateurs</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 onglets thématiques :</strong> Dans <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a>, gérez les utilisateurs, les modules, l’apparence/mentions légales, le serveur SMTP et les mises à jour GitHub en 1 clic.</li>
                <li><strong>Utilisateurs, Quotas &amp; 2FA :</strong> Créez des comptes, attribuez des quotas en Mo, gérez les groupes et modifiez ou désactivez la méthode 2FA des utilisateurs.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Concept de sécurité informatique</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Mesures techniques et organisationnelles (TOM) et résultats de l’audit</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                État : <?= e($dateStr) ?> &bull; Vérifié &amp; Durci
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentification, 2FA (E-mail &amp; TOTP) &amp; Anti-Brute-Force</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Système fermé sans inscription publique. Mots de passe hachés avec <strong>BCRYPT (coût 12)</strong>, 2FA optionnelle par e-mail ou application d’authentification (Google Authenticator / RFC 6238 TOTP avec QR code local) et blocage temporaire après 5 échecs en 5 minutes.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Protection des sessions &amp; RBAC</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sessions protégées par <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> et régénération d’ID après connexion. Vérification en temps réel des droits en base de données.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Isolation des fichiers &amp; Chiffrement Coffre</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Stockage pseudonymisé en <code>.bin</code> (droits <code>0640</code>), répertoire <code>uploads/</code> protégé par <code>.htaccess</code> et chiffrement individuel <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protection SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Requêtes préparées PDO natives, échappement HTML systématique et jetons CSRF 256 bits sur toutes les actions POST.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Dictionnaire des données</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Aperçu des tables de base de données, emplacements de stockage et délais de suppression</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Table / Stockage</th>
                        <th class="py-3 px-4">Champs principaux</th>
                        <th class="py-3 px-4">Finalité</th>
                        <th class="py-3 px-4">Conservation / Suppression</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Authentification, 2FA optionnelle (e-mail ou Google Authenticator / TOTP), réinitialisation du mot de passe, rôle, quota &amp; thème</td>
                        <td class="py-3 px-4">Jusqu’à la suppression du compte par l’administrateur</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Métadonnées des fichiers et arborescence des dossiers</td>
                        <td class="py-3 px-4">Suppression automatique après 30 jours dans la corbeille</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Données binaires pseudonymisées (optionnellement chiffrées AES-256)</td>
                        <td class="py-3 px-4">Suppression physique (<code>unlink</code>) lors du vidage de la corbeille ou après 30 jours</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'es'): ?>
    <!-- Header (ES) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Ayuda y Manual de Usuario – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Resumen de todas las funciones, atajos de teclado, información de seguridad y diccionario de datos.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Volver a mis archivos
        </a>
    </div>

    <nav aria-label="Índice" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Ir a:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Subida y Carpetas</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Vista previa y Orden</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Mover y Copiar</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Compartir y Grupos</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Modo Bóveda</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Papelera y Ajustes</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administración</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ Seguridad TI</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Diccionario de datos</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Subir archivos y carpetas</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Subida instantánea con arrastrar y soltar:</strong> Arrastre archivos o carpetas completas directamente al área punteada. La subida comienza automáticamente.</li>
                <li><strong>Botones de selección:</strong> Use <em>«+ Archivos»</em> para seleccionar varios archivos o <em>«Carpeta»</em> para subir toda una estructura de directorios.</li>
                <li><strong>Carpeta de destino:</strong> El campo <em>«Carpeta de destino (opcional)»</em> usa por defecto la carpeta actual, o puede escribir una ruta nueva (p. ej. <code>Proyectos/2026</code>).</li>
                <li><strong>Subcarpetas:</strong> Cree nuevas carpetas o subcarpetas en cualquier momento desde la barra de carpeta activa.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">👁️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vista previa y Ordenación</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Vista previa directa:</strong> Haga clic en el icono del ojo (<span class="text-violet-600 dark:text-violet-400 font-semibold">Vista previa</span>), la insignia de tipo o el nombre para abrir imágenes, PDF, vídeos, audio y archivos de texto/código.</li>
                <li><strong>Navegación:</strong> Use las teclas <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> y <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> para pasar de un archivo a otro (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> cierra la vista previa).</li>
                <li><strong>Ordenar tabla:</strong> Haga clic en los encabezados <em>Nombre</em>, <em>Carpeta</em>, <em>Tamaño</em> o <em>Tipo / Fecha</em> para ordenar de forma ascendente o descendente.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Mover o copiar archivos y carpetas</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Icono compacto ⇄ en la columna Acciones:</strong> Haga clic en el botón <strong>⇄</strong> en la columna <em>Acciones</em> de cualquier archivo o subcarpeta para abrir el diálogo y elegir la carpeta de destino bajo <strong>⇄ Mover</strong> o <strong>📋 Copiar</strong>.</li>
                <li><strong>Carpetas completas:</strong> Tanto desde el icono <strong>⇄</strong> de la barra superior como en cada fila de subcarpeta, puede mover o copiar carpetas completas con todo su contenido.</li>
                <li><strong>Entre nube personal y carpetas de grupo:</strong> Mueva o copie archivos y carpetas en ambas direcciones entre sus carpetas personales y las carpetas de grupo con permiso de escritura.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Compartir y Carpetas de grupo</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Enlaces públicos:</strong> Cree un enlace seguro para archivos o carpetas, opcionalmente con <strong>contraseña</strong> y <strong>fecha de caducidad</strong>.</li>
                <li><strong>Compartir con usuarios:</strong> Comparta archivos o carpetas directamente con otros usuarios registrados (aparecen en <em>«Compartido conmigo»</em>).</li>
                <li><strong>Carpetas de grupo:</strong> Las carpetas de equipo permiten acceso de solo lectura (👁️ Solo lectura) o completo (✏️ Lectura y Escritura).</li>
            </ul>
        </section>
    </div>

    <!-- Modo Bóveda (ES) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Modo Bóveda – Cifrado individual de archivos (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Proteja documentos sensibles con una contraseña conocida solo por usted (principio Zero-Knowledge)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Cifrar mediante el icono de candado:</strong> Tras subir un archivo, haga clic en el <strong>icono de candado 🔒</strong> en la columna <em>Acciones</em> para cifrarlo con <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong>.</li>
            <li><strong>Descifrar y Compartir:</strong> Los archivos protegidos llevan la insignia <strong>🔒 Bóveda</strong>. Al descargarlos se solicita la contraseña de bóveda. También puede eliminar la protección permanentemente en cualquier momento desde el icono de candado.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Aviso importante sobre la contraseña de Bóveda (Zero-Knowledge):</strong><br>
            La contraseña de Bóveda elegida <strong>no se guarda en ningún lugar del servidor</strong>, ni en la base de datos ni en archivos de configuración. <strong>Si olvida la contraseña de Bóveda de un archivo, nadie —ni siquiera el administrador— podrá recuperarlo.</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Papelera, Temas, Idioma y 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 días en la papelera:</strong> Los archivos eliminados pasan primero a la <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Papelera</a> durante 30 días antes de su borrado definitivo.</li>
                <li><strong>Ajustes y 2FA (Correo o Google Authenticator):</strong> Haga clic en el engranaje superior (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Ajustes</a>) para cambiar el idioma, el tema de color, su contraseña o activar la <strong>autenticación de dos factores (2FA por correo o mediante Google Authenticator / app TOTP con código QR generado localmente)</strong>.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Notas para administradores</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 pestañas temáticas:</strong> En <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administración</a> puede gestionar usuarios, módulos, diseño/aviso legal, servidor SMTP y actualizaciones desde GitHub en 1 clic.</li>
                <li><strong>Usuarios, Grupos, Cuotas y 2FA:</strong> Cree cuentas, asigne cuotas en MB, gestione grupos y cambie o desactive el método 2FA de los usuarios.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Concepto de Seguridad TI</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Medidas técnicas y organizativas (TOM) y resultados de auditoría</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Estado: <?= e($dateStr) ?> &bull; Verificado y Endurecido
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Autenticación, 2FA (Correo y TOTP) y Protección contra fuerza bruta</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sistema cerrado sin autorregistro público. Contraseñas cifradas con <strong>BCRYPT (coste 12)</strong>, 2FA opcional por correo o app autenticadora (Google Authenticator / RFC 6238 TOTP con QR local) y bloqueo tras 5 intentos fallidos en 5 minutos.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Sesiones y Control de acceso (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sesiones con <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> y verificación de permisos en tiempo real en cada petición.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Aislamiento de archivos y Modo Bóveda</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Almacenamiento con nombres aleatorios <code>.bin</code> (permisos <code>0640</code>), directorio <code>uploads/</code> bloqueado mediante <code>.htaccess</code> y cifrado <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protección contra SQLi, XSS, CSRF y Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Consultas preparadas PDO nativas, escape HTML completo y tokens CSRF de 256 bits para todas las acciones POST.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Diccionario de datos</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Resumen de tablas de base de datos, ubicaciones de almacenamiento y plazos de eliminación</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabla / Ubicación</th>
                        <th class="py-3 px-4">Campos principales</th>
                        <th class="py-3 px-4">Propósito</th>
                        <th class="py-3 px-4">Conservación / Eliminación</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Autenticación, 2FA opcional (correo o Google Authenticator / TOTP), restablecimiento de contraseña, rol, cuota y tema visual</td>
                        <td class="py-3 px-4">Hasta la eliminación de la cuenta por el administrador</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Metadatos de archivos y estructura de carpetas</td>
                        <td class="py-3 px-4">Eliminación automática tras 30 días en la papelera</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Datos binarios seudonimizados (opcionalmente cifrados con AES-256)</td>
                        <td class="py-3 px-4">Borrado físico (<code>unlink</code>) al vaciar la papelera o tras 30 días</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'nl'): ?>
    <!-- Header (NL) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Hulp &amp; Gebruikershandleiding – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Alle functies, sneltoetsen, beveiligingsinformatie en het gegevensoverzicht in één oogopslag.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Terug naar mijn bestanden
        </a>
    </div>

    <nav aria-label="Inhoudsopgave" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Direct naar:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Upload &amp; Mappen</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Voorbeeld &amp; Sorteren</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Verplaatsen &amp; Kopiëren</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Delen &amp; Groepsmappen</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Kluismodus</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Prullenbak &amp; Instellingen</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Beheer</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ IT-beveiligingsconcept</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Gegevensoverzicht</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Bestanden &amp; mappen uploaden</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direct uploaden via Drag &amp; Drop:</strong> Sleep losse bestanden of complete mappen rechtstreeks naar het gestippelde uploadvlak. De upload start automatisch.</li>
                <li><strong>Keuzeknoppen:</strong> Gebruik <em>“+ Bestanden”</em> voor meerdere bestanden of <em>“Map”</em> om een volledige mappenstructuur inclusief submappen te uploaden.</li>
                <li><strong>Doelmap instellen:</strong> In het veld <em>“Doelmap (optioneel)”</em> staat automatisch uw huidige map ingevuld, of typ direct een nieuw pad (bijv. <code>Projecten/2026</code>).</li>
                <li><strong>Submappen aanmaken:</strong> Maak via de mappenbalk op elk moment nieuwe mappen of submappen aan.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">👁️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Voorbeeld, Bladeren &amp; Sorteren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direct voorbeeld:</strong> Klik op het oog-icoon (<span class="text-violet-600 dark:text-violet-400 font-semibold">Voorbeeld</span>), de bestandstype-badge of de bestandsnaam om afbeeldingen, pdf’s, video’s, audio en tekst-/codebestanden te bekijken.</li>
                <li><strong>Bladeren in de map:</strong> Gebruik de knoppen Vorige/Volgende of de pijltjestoetsen <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> en <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> sluit het voorbeeld).</li>
                <li><strong>Tabel sorteren:</strong> Klik op de kolomkoppen <em>Naam</em>, <em>Map</em>, <em>Grootte</em> of <em>Type / Datum</em> om oplopend of aflopend te sorteren.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Bestanden &amp; mappen verplaatsen of kopiëren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Compact ⇄ icoon in de kolom Acties:</strong> Klik op het <strong>⇄</strong> icoon in de kolom <em>Acties</em> van een bestand of submap om het dialoogvenster te openen en kies onder <strong>⇄ Verplaatsen</strong> of <strong>📋 Kopiëren</strong> de gewenste doelmap.</li>
                <li><strong>Volledige mappen inclusief inhoud:</strong> Zowel via het <strong>⇄</strong> icoon in de bovenste mappenbalk als in elke submap-rij kunt u complete mappen inclusief alle bestanden verplaatsen of kopiëren.</li>
                <li><strong>Tussen persoonlijke cloud &amp; groepsmappen:</strong> Verplaats of kopieer bestanden en mappen in beide richtingen tussen uw persoonlijke mappen en groepsmappen met schrijfrechten.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Delen &amp; Groepsmappen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Openbare deellinks:</strong> Maak via het deel-icoon een beveiligde link voor bestanden of mappen – optioneel met <strong>wachtwoord</strong> en <strong>vervaldatum</strong>.</li>
                <li><strong>Direct delen met cloudgebruikers:</strong> Deel bestanden of mappen rechtstreeks met andere geregistreerde gebruikers (zichtbaar onder <em>“Gedeeld met mij”</em>).</li>
                <li><strong>Groepsmappen:</strong> Gedeelde teammappen vindt u onder <em>“Groepsmappen”</em> met alleen-lezen (👁️ Alleen lezen) of schrijfrechten (✏️ Lezen &amp; Schrijven).</li>
            </ul>
        </section>
    </div>

    <!-- Kluismodus (NL) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Kluismodus – Individuele bestandsversleuteling (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Bescherm gevoelige documenten met een wachtwoord dat alleen u kent (Zero-Knowledge-principe)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Versleutelen via het slot-icoon:</strong> Klik na het uploaden op het <strong>🔒 slot-icoon</strong> in de kolom <em>Acties</em> van de bestandslijst om een bestand met <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> te versleutelen.</li>
            <li><strong>Ontsleutelen &amp; Delen:</strong> Kluisbestanden zijn gemarkeerd met de badge <strong>🔒 Kluis</strong>. Bij het downloaden wordt om het kluiswachtwoord gevraagd. U kunt de kluisbeveiliging ook op elk moment permanent opheffen via het slot-icoon.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Belangrijke waarschuwing over het kluiswachtwoord (Zero-Knowledge):</strong><br>
            Het door u gekozen kluiswachtwoord wordt <strong>nergens op de server opgeslagen</strong> – noch in de database, noch in een configuratiebestand. <strong>Als u het kluiswachtwoord voor een bestand vergeet, kan niemand – ook de beheerder niet – het bestand herstellen!</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Prullenbak, Thema’s, Taal &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 dagen bewaartijd in prullenbak:</strong> Verwijderde bestanden blijven 30 dagen herstelbaar in de <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Prullenbak</a>.</li>
                <li><strong>Instellingen &amp; 2FA (E-mail of Google Authenticator):</strong> Klik rechtsboven op het tandwiel (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Instellingen</a>) om uw taal, kleurthema of wachtwoord te wijzigen, of om optionele <strong>tweefactorauthenticatie (2FA per e-mail of via Google Authenticator / TOTP-app met lokaal gegenereerde QR-code)</strong> in te schakelen.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Tips voor beheerders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 overzichtelijke tabbladen:</strong> Beheer onder <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Beheer</a> gebruikers, modules, weergave/colofon, SMTP en 1-klik GitHub-updates.</li>
                <li><strong>Gebruikers, Groepen, Quota &amp; 2FA:</strong> Maak accounts aan, wijs opslagruimte (in MB) toe, beheer groepen en wijzig of reset de 2FA-methode van gebruikers.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT-beveiligingsconcept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technische en organisatorische maatregelen (TOM) en controleresultaten</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Status: <?= e($dateStr) ?> &bull; Gecontroleerd &amp; Gehard
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authenticatie, 2FA (E-mail &amp; TOTP) &amp; Brute-Force-bescherming</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Gesloten systeem zonder openbare zelfregistratie. Wachtwoorden gehasht met <strong>BCRYPT (cost 12)</strong>, optionele 2FA per e-mail of Authenticator-app (Google Authenticator / RFC 6238 TOTP met lokale QR-code) en tijdelijke blokkering na 5 foutieve pogingen binnen 5 minuten.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Sessie- &amp; Toegangsbeveiliging (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sessies met <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> en realtime controle van gebruikersrechten bij elke pagina-aanroep.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Opslagisolatie &amp; Kluisversleuteling</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Bestanden worden opgeslagen met willekeurige <code>.bin</code>-namen (rechten <code>0640</code>), afgeschermd via <code>.htaccess</code> en optioneel versleuteld met <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Bescherming tegen SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Echte PDO Prepared Statements, consequente HTML-maskering en 256-bit CSRF-tokens voor alle POST-acties.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Gegevensoverzicht (Data Dictionary)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Overzicht van alle databasetabellen, opslaglocaties en bewaartermijnen</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabel / Locatie</th>
                        <th class="py-3 px-4">Belangrijkste velden</th>
                        <th class="py-3 px-4">Doel</th>
                        <th class="py-3 px-4">Bewaring / Verwijdering</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Authenticatie, optionele 2FA (e-mail of Google Authenticator / TOTP), wachtwoordherstel, rol, quota &amp; thema</td>
                        <td class="py-3 px-4">Tot verwijdering van het account door de beheerder</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Bestandsmetadata en mappenstructuur</td>
                        <td class="py-3 px-4">Automatische definitieve verwijdering na 30 dagen in de prullenbak</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Fysieke binaire gegevens (gepseudonimiseerd, optioneel AES-256 versleuteld)</td>
                        <td class="py-3 px-4">Fysieke verwijdering (<code>unlink</code>) bij legen van prullenbak of na 30 dagen</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <!-- Standard: Deutsch (DE) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Hilfe &amp; Benutzerhandbuch – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Alle Funktionen, Tastenkürzel, Sicherheitsinformationen und das Datenverzeichnis im Überblick.
            </p>
        </div>

        <a
            href="index.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto"
        >
            &larr; Zurück zu meinen Dateien
        </a>
    </div>

    <!-- Schnellnavigation -->
    <nav aria-label="Inhaltsverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Direkt zu:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📤 Upload &amp; Ordner
            </a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                👁️ Vorschau &amp; Sortierung
            </a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                ⇄ Verschieben &amp; Kopieren
            </a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🔗 Teilen &amp; Berechtigungen
            </a>
            <a href="#kalender-termine" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📅 Kalender &amp; .ics-Abo
            </a>
            <a href="#chat-nachrichten" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                💬 Chat &amp; Meldungen
            </a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                🔒 Tresor-Modus
            </a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🗑️ Papierkorb &amp; Einstellungen
            </a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                    ⚙️ Administration &amp; Update
                </a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">
                🛡️ IT-Sicherheitskonzept
            </a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">
                🗂️ Datenverzeichnis
            </a>
        </div>
    </nav>

    <!-- 1. Dateien & Ordner hochladen und verwalten -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner hochladen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Sofort-Upload per Drag &amp; Drop:</strong> Ziehen Sie einzelne Dateien oder komplette Ordner aus Ihrem Dateimanager direkt auf die gestrichelte Upload-Fläche. Der Upload startet automatisch.</li>
                <li><strong>Auswahl-Buttons:</strong> Über <em>„+ Dateien“</em> wählen Sie mehrere Einzeldateien aus, über <em>„Ordner“</em> laden Sie eine komplette Ordnerstruktur samt Unterordnern hoch.</li>
                <li><strong>Zielordner festlegen:</strong> Im Feld <em>„Zielordner (optional)“</em> ist automatisch Ihr aktuell geöffneter Ordner voreingestellt. Sie können dort auch direkt einen neuen Pfad (z. B. <code>Projekte/2026</code>) eintragen.</li>
                <li><strong>Unterordner anlegen:</strong> Über die Ordnerleiste erstellen Sie jederzeit neue Ordner oder Unterordner im aktuell geöffneten Verzeichnis (auch in für Sie mit Schreibrecht freigegebenen Ordnern).</li>
            </ul>
        </section>

        <!-- 2. Dateivorschau, Office Light & Sortierung -->
        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vorschau, Office Light (Word &amp; Excel) &amp; Sortierung</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (Word, Excel &amp; Notizen online bearbeiten):</strong>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>Neu erstellen:</strong> Über den Button <strong>„📄+ Office Light“</strong> in der Upload-Leiste legen Sie direkt im aktuellen Ordner neue <strong>Word-Dokumente (<code>.docx</code>)</strong>, <strong>Excel-Tabellen (<code>.xlsx</code>)</strong>, <strong>CSV-Listen (<code>.csv</code>)</strong> oder <strong>Text-/Markdown-Notizen (<code>.txt</code> / <code>.md</code>)</strong> an.</li>
                        <li><strong>Online bearbeiten:</strong> Klicken Sie in der Dateiliste auf das <strong>✏️ Stift-Symbol</strong> (oder im Vorschau-Fenster auf <em>„Office Light“</em>), um Word-Dokumente mit Formatierungsleiste (Überschriften, Fett/Kursiv, Listen, Tabellen, Druck/PDF) oder Excel-Tabellen mit mehreren Tabellenblättern und Rechenformeln (<code>=SUMME(A1:A10)</code>, <code>=MITTELWERT(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=A1*B1</code>) direkt im Browser zu bearbeiten und per <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+S</kbd> zu speichern.</li>
                    </ul>
                </li>
                <li><strong>Direktvorschau &amp; Durchblättern:</strong> Ein Klick auf das Augen-Symbol (<span class="text-violet-600 dark:text-violet-400 font-semibold">Vorschau</span>) oder den Dateinamen öffnet Bilder, PDFs, Videos, Audio sowie Text-/Codedateien; mit <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> / <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> blättern Sie durch den Ordner.</li>
                <li><strong>Tabellensortierung:</strong> Klicken Sie auf <em>Name</em>, <em>Ordner</em>, <em>Größe</em> oder <em>Typ / Datum</em>, um auf- oder absteigend zu sortieren.</li>
            </ul>
        </section>

        <!-- 3. Verschieben & Kopieren -->
        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner verschieben oder kopieren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Kompaktes ⇄ Symbol in der Aktionsspalte:</strong> Klicken Sie in der Spalte <em>„Aktionen“</em> einer Datei oder eines Unterordners auf das <strong>⇄</strong>-Symbol, um im Dialogfenster unter <strong>⇄ Verschieben</strong> oder <strong>📋 Kopieren</strong> den gewünschten Zielordner auszuwählen.</li>
                <li><strong>Ganze Ordner samt Inhalt:</strong> Sowohl über das <strong>⇄</strong>-Symbol in der oberen Ordnerleiste als auch direkt in jeder Unterordner-Zeile der Tabelle können komplette Ordner inklusive aller Unterordner und Dateien verschoben oder kopiert werden.</li>
                <li><strong>In freigegebene Ordner übertragen:</strong> Haben andere Benutzer Ihnen für einen Ordner Schreibrechte (<strong>✏️ Lesen &amp; Schreiben</strong>) eingeräumt, können Sie Dateien direkt dorthin hochladen, kopieren oder verschieben.</li>
            </ul>
        </section>

        <!-- 4. Teilen & 3-stufiges Berechtigungssystem -->
        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Freigaben &amp; 3-stufige Berechtigungen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Öffentliche Freigabelinks:</strong> Über das Teilen-Symbol erstellen Sie für Dateien oder ganze Ordner einen kryptografisch gesicherten Link – optional mit <strong>Passwortschutz</strong> und <strong>Ablaufdatum</strong>.</li>
                <li><strong>Direktes Teilen mit Cloud-Benutzern (3 Stufen):</strong> Geben Sie Dateien oder Ordner gezielt für andere Benutzer frei (sichtbar unter <em>„Für mich freigegeben“</em>). Für Ordner wählen Sie zwischen:
                    <ul class="list-disc pl-4 mt-1 space-y-0.5">
                        <li><strong>👁️ Nur Lesen:</strong> Ansehen &amp; Herunterladen</li>
                        <li><strong>✏️ Lesen &amp; Schreiben:</strong> Zusätzlich eigene Dateien hochladen &amp; Unterordner anlegen</li>
                        <li><strong>🗑️ Lesen, Schreiben &amp; Löschen:</strong> Vollzugriff inkl. Löschen von Dateien &amp; Unterordnern</li>
                    </ul>
                </li>
                <li><strong>Datenschutz-Standard:</strong> Neue Benutzerkonten sind standardmäßig in der Teilen- und Chat-Auswahl anderer Nutzer verborgen und können ihre Sichtbarkeit jederzeit in den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> aktivieren.</li>
            </ul>
        </section>

        <!-- 4a. Kalender & Terminverwaltung -->
        <section id="kalender-termine" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 font-bold">📅</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Kalender, Freigaben &amp; .ics-Abo</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Monats- &amp; Listenansicht (<a href="calendar.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Kalender</a>):</strong> Erstellen Sie Einzel- oder Ganztagestermine mit Farbkodierung, Ort, Beschreibung, Wiederholungen (täglich, wöchentlich, monatlich, jährlich) und verknüpften Cloud-Dateien.</li>
                <li><strong>Kalender teilen (3 Berechtigungsstufen):</strong> Teilen Sie Ihren Kalender mit sichtbaren Benutzern oder ganzen Benutzergruppen – wahlweise mit <strong>👁️ Nur Lesen</strong>, <strong>✏️ Lesen &amp; Bearbeiten</strong> oder <strong>🗑️ Lesen, Bearbeiten &amp; Löschen</strong>. Termine mit dem Häkchen <em>„🔒 Privat“</em> bleiben trotz Kalender-Freigabe nur für Sie selbst sichtbar.</li>
                <li><strong>.ics-Import, Export &amp; Smartphone-Abo:</strong> Importieren oder exportieren Sie <code>.ics</code>-Kalenderdateien oder aktivieren Sie Ihren persönlichen <strong>.ics-Abo-Link</strong> zur Live-Synchronisation mit iPhone/iPad, Android, Thunderbird oder Outlook.</li>
            </ul>
        </section>

        <!-- 4b. Integrierter Messenger / Chat & System-Benachrichtigungen -->
        <section id="chat-nachrichten" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400 font-bold">💬</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Chat, Rundnachrichten &amp; Meldungen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direktnachrichten &amp; Ankündigungen (<a href="messages.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Chat</a>):</strong> Schreiben Sie 1:1-Nachrichten mit freigegebenen Cloud-Benutzern oder lesen Sie offizielle Rundnachrichten des Administrators im Kanal <em>„📢 Ankündigungen (Alle)“</em>.</li>
                <li><strong>Verlauf leeren &amp; Mobil-Darstellung:</strong> Über den Button <em>„🗑️ Verlauf leeren“</em> können Sie einen Chatverlauf jederzeit vollständig bereinigen. Auf Smartphones schaltet der Messenger automatisch in eine übersichtliche 1-Spalten-Navigation (Kontaktliste &leftrightarrow; Vollbild-Chat).</li>
                <li><strong>System-Benachrichtigungen am PC &amp; Mobilgerät:</strong> Bei neuen Nachrichten blendet MiniCloud auf Wunsch native Betriebssystem-/Smartphone-Meldungen ein. In den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> legen Sie fest, ob Sie <em>Nur System-Benachrichtigungen</em> (Standard), zusätzlich ein <em>In-App-Banner</em> oder gar keine Meldungen wünschen.</li>
            </ul>
        </section>
    </div>

    <!-- 4c. TRESOR-MODUS (Einzeldatei-Verschlüsselung AES-256 Zero-Knowledge) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Tresor-Modus – Einzeldatei-Verschlüsselung (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Sensible Dokumente mit einem nur Ihnen bekannten Passwort schützen (Zero-Knowledge-Prinzip)</p>
            </div>
        </div>

        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Über das Schloss-Symbol verschlüsseln:</strong> Sie können hochgeladene Dateien jederzeit über das <strong>🔒 Schloss-Symbol</strong> in der Spalte <em>„Aktionen“</em> der Dateiliste mit <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> und Ihrem persönlichen Tresor-Passwort verschlüsseln.</li>
            <li><strong>Entschlüsseln &amp; Teilen:</strong> Tresor-Dateien sind mit dem Abzeichen <strong>🔒 Tresor</strong> markiert. Beim Herunterladen (auch über geteilte Links oder Freigaben an andere Benutzer) wird das Tresor-Passwort abgefragt. Zudem können Sie den Tresor-Schutz über das Schloss-Symbol jederzeit wieder dauerhaft aufheben.</li>
        </ul>

        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Wichtiger Hinweis zum Tresor-Passwort (Zero-Knowledge):</strong><br>
            Das von Ihnen gewählte Tresor-Passwort wird <strong>nirgendwo auf dem Server gespeichert</strong> – weder in der Datenbank noch in einer Konfigurationsdatei. Nach dem Verschlüsseln wird der Schlüssel sofort aus dem Arbeitsspeicher verworfen. <strong>Wenn Sie das Tresor-Passwort für eine Datei vergessen, kann niemand – auch nicht der Administrator – die Datei wiederherstellen!</strong>
        </div>
    </section>

    <!-- 5. Papierkorb, Einstellungen & Admin-Hilfe -->
    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Papierkorb, Farbdesigns, Einstellungen &amp; 2FA (E-Mail / Google Auth)</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 Tage Papierkorb-Aufbewahrung:</strong> Gelöschte Dateien wandern zunächst in den <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Papierkorb</a>. Dort können sie 30 Tage lang mit einem Klick wiederhergestellt oder sofort endgültig gelöscht werden.</li>
                <li><strong>13 Farbdesigns &amp; 5 Sprachen:</strong> In den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> (⚙️ Zahnrad-Symbol) wählen Sie zwischen 5 Sprachen sowie 13 augenfreundlichen Farbschemata (5 helle und 8 dunkle Designs inkl. <em>🌌 Mitternacht</em>).</li>
                <li><strong>Individuelle Komfort- &amp; Datenschutzoptionen:</strong> Legen Sie Ihre persönliche <strong>Anzeigedauer für Statusmeldungen</strong> (1–300 Sek. oder manuell), Ihr <strong>automatisches Sitzungs-Timeout</strong> (5 Min. bis 24 Std. oder dauerhaft), Ihren <strong>Chat-Benachrichtigungsmodus</strong>, Ihre <strong>Teilen-Sichtbarkeit</strong> sowie Ihre <strong>E-Mail-Adresse</strong> fest.</li>
                <li><strong>Zwei-Faktor-Authentifizierung (2FA) – E-Mail oder Google Authenticator:</strong> Unter <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen &rarr; Sicherheit &amp; 2FA</a> stehen Ihnen zwei vollwertige Methoden zur Auswahl:
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>✉️ Per E-Mail-Code:</strong> Bei jeder Anmeldung wird ein 6-stelliger Einmalcode (10 Min. gültig) an Ihre hinterlegte E-Mail-Adresse gesendet.</li>
                        <li><strong>📱 Per Authenticator-App (Google Authenticator / TOTP):</strong> Wählen Sie <em>„Per Authenticator-App“</em>, scannen Sie den <strong>100 % lokal auf dem Server erzeugten QR-Code</strong> (oder kopieren Sie den Base32-Schlüssel) mit <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, <strong>Authy</strong>, <strong>Aegis</strong>, <strong>Bitwarden</strong> oder <strong>Apple Passwörter</strong> und geben Sie zur Aktivierung einmalig den 6-stelligen App-Code ein. Die Codes werden alle 30 Sekunden offline auf Ihrem Smartphone berechnet.</li>
                        <li><strong>Notfall-Fallback beim Login:</strong> Sollte Ihr Smartphone beim Login einmal nicht griffbereit sein und Sie haben eine E-Mail-Adresse hinterlegt, können Sie auf der 2FA-Abfrageseite per Klick auf <em>„✉️ Stattdessen Code per E-Mail senden“</em> einen Einmalcode per E-Mail anfordern.</li>
                    </ul>
                </li>
                <li><strong>Vergessenes Passwort zurücksetzen:</strong> Haben Sie eine E-Mail-Adresse hinterlegt, können Sie auf der Anmeldeseite jederzeit über <em>„Passwort vergessen?“</em> (<a href="forgot_password.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Passwort zurücksetzen</a>) einen 30 Minuten gültigen Einmal-Link anfordern.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Hinweise für Administratoren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Thematisch geordnete 5-Reiter-Administration:</strong> Die <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a> ist übersichtlich in 5 Themenbereiche gegliedert: <strong>👥 Benutzer &amp; Registrierung</strong>, <strong>🧩 Module &amp; Funktionen</strong>, <strong>🎨 Darstellung &amp; Impressum</strong>, <strong>✉️ E-Mail &amp; SMTP</strong> sowie <strong>🖥️ System, Update &amp; Status</strong> (plus Gesamtansicht <em>📋 Alle Bereiche</em>).</li>
                <li><strong>1-Klick-System-Update über GitHub:</strong> Im Reiter <em>🖥️ System, Update &amp; Status</em> prüft MiniCloud per Klick den aktuellen Commit-Stand auf GitHub und aktualisiert alle Programmdateien vollautomatisch, während <code>uploads/</code> und die Datenbank-Zugangsdaten in <code>config/database.php</code> geschützt bleiben.</li>
                <li><strong>Module, Selbstregistrierung &amp; Branding:</strong> Schalten Sie das Kalender- oder Chat-Modul global ein/aus, aktivieren Sie bei Bedarf die Selbstregistrierung (optional mit Einladungs-Code &amp; Start-Kontingent) und passen Sie Titel, Logo, SMTP-Server und Impressum an.</li>
                <li><strong>Benutzer, Quotas &amp; 2FA-Verwaltung:</strong> Verwalten Sie Benutzerkonten, Speicher-Quotas (in MB) und Benutzergruppen. In der Benutzertabelle sehen Sie am Badge (<code>🔒 2FA</code> bzw. <code>📱 TOTP</code>) die aktive 2FA-Methode und können die 2FA eines Benutzers bei Geräteverlust jederzeit umstellen oder deaktivieren.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <!-- 6. IT-SICHERHEITSKONZEPT (inkl. Ergebnis des Sicherheitsaudits) -->
    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT-Sicherheitskonzept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technische und organisatorische Schutzmaßnahmen (TOM) sowie Ergebnisse der Sicherheitsprüfung</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Stand: <?= e($dateStr) ?> &bull; Geprüft &amp; Gehärtet
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentifizierung, 2FA (E-Mail &amp; TOTP) &amp; Brute-Force-Schutz</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Geschlossenes Benutzersystem (Selbstregistrierung standardmäßig deaktiviert und optional nur per Einladungs-Code). Passwörter werden ausschließlich mittels <strong>BCRYPT (Cost-Faktor 12)</strong> gehasht. Benutzer können wahlweise eine <strong>2FA per E-Mail-Code</strong> oder per <strong>Authenticator-App (Google Authenticator / TOTP nach RFC 6238 mit 100 % lokaler QR-Code-Erzeugung)</strong> aktivieren. Nach <strong>5 Fehlversuchen innerhalb von 5 Minuten</strong> greift eine automatische temporäre Sperre.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Session-, Timeout- &amp; Zugriffsschutz (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Sessions nutzen <code>session.use_strict_mode</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>, bei HTTPS <code>Secure</code> sowie ein konfigurierbares Inaktivitäts-Timeout (Standard: 30 Min.). Nach erfolgreichem Login erfolgt <code>session_regenerate_id(true)</code> gegen Session-Fixation. Bei jedem Seitenaufruf gleicht das System Benutzerexistenz und Administrator-Status in Echtzeit mit der Datenbank ab.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Dateispeicher-Isolierung &amp; Tresor-Verschlüsselung</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hochgeladene Dateien werden niemals unter ihrem Originalnamen im Dateisystem abgelegt, sondern erhalten einen kryptografisch zufälligen Dateinamen mit der nicht ausführbaren Endung <code>.bin</code> (z. B. <code>mc_..._a1b2c3d4.bin</code>) und restriktiven Dateirechten (<code>0640</code>). Das Verzeichnis <code>uploads/</code> ist per <code>.htaccess</code> gegen direkten Web-Zugriff gesperrt. Zusätzlich können Dateien mit <strong>AES-256-GCM (Zero-Knowledge)</strong> verschlüsselt werden.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Schutz vor SQLi, XSS, CSRF &amp; Path-Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Alle Datenbankabfragen nutzen echte <strong>PDO Prepared Statements</strong> (<code>ATTR_EMULATE_PREPARES = false</code>). Sämtliche Ausgaben werden per <code>htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')</code> bzw. <code>JSON_HEX_TAG</code> maskiert. Jede zustandsändernde Aktion erfordert einen <code>POST</code>-Request mit kryptografischem 256-Bit-CSRF-Token (<code>hash_equals</code>). Pfadangaben und ZIP-Update-Archive werden strikt gegen <code>..</code> und SQL-Wildcards (<code>%</code>) bereinigt.
                </p>
            </div>
        </div>

        <!-- Ergebnisse des Sicherheitsaudits -->
        <div class="p-4 sm:p-5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-800/60">
            <h3 class="text-sm sm:text-base font-bold text-emerald-950 dark:text-emerald-200 mb-2">
                Ergebnisse der durchgeführten Sicherheitsüberprüfung &amp; Härtung
            </h3>
            <ul class="text-xs sm:text-sm text-emerald-900 dark:text-emerald-200/90 space-y-1.5 list-disc pl-5">
                <li><strong>Script-Kontext- &amp; DOM-XSS-Härtung:</strong> JSON-Serialisierung in Inline-Skripten (Dateivorschau, Chat-Kanal, CSRF-Tokens) mit <code>JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT</code> sowie vollständige HTML-Maskierung (<code>escapeHtml</code>) im Teilen-Dialog, Chat-Polling und GitHub-Updater.</li>
                <li><strong>Datenschutz-Sichtbarkeit &amp; 3-Stufen-Rechteprüfung:</strong> Strikte serverseitige Prüfung von <code>allow_user_sharing = 1</code> sowie granulare Berechtigungsprüfung (<code>can_write</code>, <code>can_delete</code>, <code>can_edit</code>) für Datei-, Ordner- und Kalender-Freigaben.</li>
                <li><strong>Abgesicherter GitHub-Updater:</strong> Zertifikatsvalidierung (<code>CURLOPT_SSL_VERIFYPEER</code> / <code>CURLOPT_SSL_VERIFYHOST = 2</code>), Schutz vor Zip-Slip (<code>..</code>-Filter) sowie automatischer Erhalt von <code>uploads/</code> und <code>config/database.php</code>-Zugangsdaten.</li>
                <li><strong>Host-Header-, Open-Redirect- &amp; Forced-Logout-Schutz:</strong> Strikte Regex-Validierung von <code>HTTP_HOST</code>, Whitelist-Prüfung lokaler Zielseiten und CSRF-geschützter <code>POST</code>-Logout.</li>
                <li><strong>Brute-Force-Sperre &amp; SVG-Sanitisierung:</strong> Ratenbegrenzung für Login, Registrierung und Freigabelinks sowie Abwehr aktiver Skriptelemente in SVG-Logos.</li>
            </ul>
        </div>
    </section>

    <!-- 7. DATENVERZEICHNIS -->
    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Datenverzeichnis (Data Dictionary &amp; Bestandsübersicht)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Übersicht aller verarbeiteten Datenbestände, Datenbanktabellen, Speicherorte und Löschfristen</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabelle / Speicherort</th>
                        <th class="py-3 px-4">Wesentliche Felder / Inhalte</th>
                        <th class="py-3 px-4">Zweck &amp; Personenbezug</th>
                        <th class="py-3 px-4">Aufbewahrung / Löschung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, reset_expires_at, is_admin, theme, session_timeout, flash_duration, chat_notify_mode, allow_user_sharing, calendar_token, storage_quota, created_at</code></td>
                        <td class="py-3 px-4">Benutzerauthentifizierung, optionale 2FA (E-Mail-Code oder Google Authenticator / TOTP), Passwort-Wiederherstellung, Rollensteuerung, Kontingent, UI-Präferenzen &amp; Datenschutz-Sichtbarkeit (personenbezogen)</td>
                        <td class="py-3 px-4">Bis zur Löschung des Benutzerkontos durch den Administrator (Kaskadenlöschung)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files</td>
                        <td class="py-3 px-4"><code>id, user_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date, deleted_at</code></td>
                        <td class="py-3 px-4">Metadaten hochgeladener Dateien &amp; Zuordnung zu Benutzern und Ordnerpfaden</td>
                        <td class="py-3 px-4">Aktiv bis zum Löschen; im Papierkorb (<code>deleted_at</code>) automatische Endlöschung nach 30 Tagen</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_folders</td>
                        <td class="py-3 px-4"><code>id, user_id, folder_path, created_at</code></td>
                        <td class="py-3 px-4">Verzeichnisstruktur (inkl. leerer Ordner) in der persönlichen Cloud</td>
                        <td class="py-3 px-4">Bis zum Löschen/Verschieben des Ordners oder Löschung des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">groups &amp; group_members</td>
                        <td class="py-3 px-4"><code>id, name, description</code> / <code>group_id, user_id</code></td>
                        <td class="py-3 px-4">Benutzergruppen und Mitgliedschaftszuordnung (z. B. für gemeinsame Kalender-Termine &amp; Kalender-Freigaben)</td>
                        <td class="py-3 px-4">Bis zum Entfernen der Mitgliedschaft oder Löschen der Gruppe / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, user_id, share_token, password_hash, expires_at, created_at</code></td>
                        <td class="py-3 px-4">Öffentliche Freigabelinks (Einzeldatei oder Ordner) inkl. optionalem BCRYPT-Passwort &amp; Ablaufdatum</td>
                        <td class="py-3 px-4">Bis zum manuellen Widerruf, Ablauf (<code>expires_at</code>) oder Löschen der Datei / des Ordners</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, owner_id, shared_with_user_id, can_write, can_delete, created_at</code></td>
                        <td class="py-3 px-4">Interne Direktfreigaben von Dateien oder Ordnern zwischen Cloud-Benutzern mit 3-stufigen Rechten (Nur Lesen, Schreiben, Löschen)</td>
                        <td class="py-3 px-4">Bis zum Widerruf durch den Eigentümer oder Löschung der Datei / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">calendar_events &amp; calendar_shares</td>
                        <td class="py-3 px-4"><code>user_id, title, start_at, end_at, all_day, recurrence, group_id, shared_user_id, attached_file_id, is_private</code> / <code>owner_user_id, target_user_id, target_group_id, can_edit, can_delete</code></td>
                        <td class="py-3 px-4">Persönliche und geteilte Kalendertermine, Serien-Wiederholungen sowie 3-stufige Kalender-Freigaben an Benutzer oder Gruppen</td>
                        <td class="py-3 px-4">Bis zur Löschung des Termins / Widerruf der Kalender-Freigabe oder Löschung des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">messages &amp; message_broadcast_reads</td>
                        <td class="py-3 px-4"><code>id, sender_id, recipient_id, is_broadcast, message_text, is_read, created_at</code> / <code>user_id, last_read_broadcast_id</code></td>
                        <td class="py-3 px-4">Direkte 1:1-Chat-Nachrichten zwischen Cloud-Benutzern sowie offizielle Admin-Rundnachrichten samt Lesestatus</td>
                        <td class="py-3 px-4">Bis zur Einzellöschung, Leerung des Chatverlaufs (<em>„Verlauf leeren“</em>) oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">settings</td>
                        <td class="py-3 px-4"><code>setting_key, setting_value, updated_at</code></td>
                        <td class="py-3 px-4">Systemeinstellungen (Branding, SMTP, Modulschalter <code>enable_calendar</code>/<code>enable_chat</code>, Registrierung, <code>installed_commit_sha</code>)</td>
                        <td class="py-3 px-4">Dauerhaft (durch Administrator jederzeit änderbar)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Dateisystem: uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Physische Binärdaten der hochgeladenen Dateien (pseudonymisierter Dateiname, optional AES-256-verschlüsselt)</td>
                        <td class="py-3 px-4">Physische Löschung (<code>unlink</code>) bei Leerung des Papierkorbs, nach 30 Tagen im Papierkorb oder bei Kontolöschung</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
