<?php
/**
 * uWiiiMiniCloud – Alltags-Werkzeuge & Toolbox (toolbox.php)
 *
 * 6-in-1 Schweizer Taschenmesser für Alltag, Familie, Verein & Büro (100 % lokal & werbefrei):
 *  1. 📶 QR-Code, Gäste-WLAN-Karten & GiroCode-Überweisungs-Studio (ISO/IEC 18004 Canvas-Engine)
 *  2. 🎡 Glücksrad, Auslosung, Team-/Turnier-Generator & Würfel-Studio (inkl. Sound & Konfetti)
 *  3. ⏱️ Stoppuhr (mit Rundenzeiten), Küchen-/Fokus-Timer & Weltzeituhr mit Zeitzonen-Schieberegler
 *  4. 🔍 Text-Werkstatt & Dokumenten-Vergleich (Zeilen-/Wort-Diff-Checker, Wortzähler & Text-Reiniger)
 *  5. 🎨 Farb-Studio, Harmonien, WCAG-Kontrast-Prüfer & CSS-Verlaufs-Generator
 *  6. 🔐 Generator- & Kodierungs-Werkbank (Passwörter/Passphrasen, UUID v4, Base64/URL, JSON & SHA-256)
 *
 * Inklusive 1-Klick Cloud-Speicherung (Ordner „Werkzeuge/“) und direkter Notizwand-Übernahme (notes.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo    = getDB();
$userId = currentUserId();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_toolbox'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Alltags-Werkzeuge & Toolbox“ ist durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

recordModuleUsage($pdo, 'toolbox');

// ============================================================================
// AJAX-Endpunkte: Ergebnisse als Haftnotiz (notes.php) oder Datei (Werkzeuge/) speichern
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ajaxAction = (string)$_POST['ajax_action'];

    // 1. Beliebigen Text (Auslosung, Stoppuhr-Runden, Text-Diff, Passphrasen) als Haftnotiz speichern
    if ($ajaxAction === 'save_tool_note') {
        $title   = trim((string)($_POST['title'] ?? 'Toolbox-Notiz vom ' . date('d.m.Y H:i')));
        $content = trim((string)($_POST['content'] ?? ''));
        $color   = trim((string)($_POST['color'] ?? 'sky'));
        if (!in_array($color, ['amber', 'emerald', 'sky', 'rose', 'violet', 'slate'], true)) {
            $color = 'sky';
        }
        if ($content === '') {
            echo json_encode(['ok' => false, 'error' => 'Kein Inhalt zum Speichern vorhanden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtNote = $pdo->prepare(
            'INSERT INTO notes (user_id, title, content, color, is_pinned)
             VALUES (:uid, :t, :c, :col, 0)'
        );
        $stmtNote->execute([
            ':uid' => $userId,
            ':t'   => mb_substr('🧰 ' . ($title !== '' ? $title : 'Toolbox-Notiz'), 0, 140),
            ':c'   => mb_substr($content . "\n\n#toolbox #werkzeuge", 0, 10000),
            ':col' => $color,
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Ergebnis wurde als Haftnotiz auf deiner Notizwand gespeichert!',
            'url'     => 'notes.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Generierte PNG-Grafik (QR-Code / Gäste-WLAN-Karte / GiroCode) im Cloud-Ordner „Werkzeuge“ speichern
    if ($ajaxAction === 'save_qr_image') {
        $dataUrl = (string)($_POST['image_data'] ?? '');
        $title   = trim((string)($_POST['title'] ?? 'QR_Code'));
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige PNG-Bilddaten.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rawBase64 = substr($dataUrl, strlen('data:image/png;base64,'));
        $binary    = base64_decode($rawBase64, true);
        if ($binary === false || strlen($binary) < 64) {
            echo json_encode(['ok' => false, 'error' => 'Bilddaten konnten nicht dekodiert werden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileSize = strlen($binary);
        $quota    = getUserQuotaInfo($pdo, $userId);
        if (($quota['used_bytes'] + $fileSize) > $quota['quota_bytes']) {
            echo json_encode(['ok' => false, 'error' => 'Speicherplatz-Kontingent erschöpft.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0750, true);
        }

        $safeTitle  = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $title) ?: 'QR_Code';
        $origName   = mb_substr($safeTitle, 0, 75) . '_' . date('Y-m-d_Hi') . '.png';
        $storedName = bin2hex(random_bytes(16)) . '.bin';
        file_put_contents(UPLOAD_DIR . $storedName, $binary);

        $folderName = 'Werkzeuge';
        $stmtChkF = $pdo->prepare('SELECT id FROM user_folders WHERE user_id = :uid AND folder_path = :fp AND group_folder_id IS NULL LIMIT 1');
        $stmtChkF->execute([':uid' => $userId, ':fp' => $folderName]);
        if (!$stmtChkF->fetchColumn()) {
            $pdo->prepare('INSERT INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                ->execute([':uid' => $userId, ':fp' => $folderName]);
        }

        $pdo->prepare(
            'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type)
             VALUES (:uid, :fp, :fn, :on, :fs, :mt)'
        )->execute([
            ':uid' => $userId,
            ':fp'  => $folderName,
            ':fn'  => $storedName,
            ':on'  => $origName,
            ':fs'  => $fileSize,
            ':mt'  => 'image/png',
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Grafik „' . $origName . '“ wurde im Cloud-Ordner „Werkzeuge“ gespeichert!',
            'url'     => 'index.php?folder=Werkzeuge',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Textdatei (.txt) im Cloud-Ordner „Werkzeuge“ speichern
    if ($ajaxAction === 'save_tool_file') {
        $title   = trim((string)($_POST['title'] ?? 'Toolbox_Export'));
        $content = (string)($_POST['content'] ?? '');
        if (trim($content) === '') {
            echo json_encode(['ok' => false, 'error' => 'Kein Inhalt zum Speichern vorhanden.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fileSize = strlen($content);
        $quota    = getUserQuotaInfo($pdo, $userId);
        if (($quota['used_bytes'] + $fileSize) > $quota['quota_bytes']) {
            echo json_encode(['ok' => false, 'error' => 'Speicherplatz-Kontingent erschöpft.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0750, true);
        }

        $safeTitle  = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $title) ?: 'Toolbox_Export';
        $origName   = mb_substr($safeTitle, 0, 75) . '_' . date('Y-m-d_Hi') . '.txt';
        $storedName = bin2hex(random_bytes(16)) . '.bin';
        file_put_contents(UPLOAD_DIR . $storedName, $content);

        $folderName = 'Werkzeuge';
        $stmtChkF = $pdo->prepare('SELECT id FROM user_folders WHERE user_id = :uid AND folder_path = :fp AND group_folder_id IS NULL LIMIT 1');
        $stmtChkF->execute([':uid' => $userId, ':fp' => $folderName]);
        if (!$stmtChkF->fetchColumn()) {
            $pdo->prepare('INSERT INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                ->execute([':uid' => $userId, ':fp' => $folderName]);
        }

        $pdo->prepare(
            'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type)
             VALUES (:uid, :fp, :fn, :on, :fs, :mt)'
        )->execute([
            ':uid' => $userId,
            ':fp'  => $folderName,
            ':fn'  => $storedName,
            ':on'  => $origName,
            ':fs'  => $fileSize,
            ':mt'  => 'text/plain',
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Datei „' . $origName . '“ wurde im Cloud-Ordner „Werkzeuge“ gespeichert!',
            'url'     => 'index.php?folder=Werkzeuge',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'Alltags-Werkzeuge & Toolbox';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .tb-tab-btn.active {
        background: linear-gradient(135deg, #2563eb, #4f46e5);
        color: #ffffff !important;
        border-color: transparent !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
    }
    .tb-sub-btn.active {
        background-color: #2563eb !important;
        color: #ffffff !important;
        border-color: #2563eb !important;
    }
    .diff-add {
        background-color: rgba(16, 185, 129, 0.22);
        color: #065f46;
        border-left: 3px solid #10b981;
    }
    html.dark .diff-add {
        background-color: rgba(16, 185, 129, 0.2);
        color: #6ee7b7;
    }
    .diff-del {
        background-color: rgba(239, 68, 68, 0.2);
        color: #991b1b;
        border-left: 3px solid #ef4444;
        text-decoration: line-through;
    }
    html.dark .diff-del {
        background-color: rgba(239, 68, 68, 0.2);
        color: #fca5a5;
    }
    @media print {
        header, footer, #tbMainTabs, .tb-no-print {
            display: none !important;
        }
        main {
            padding: 0 !important;
            max-width: 100% !important;
        }
        #tbQrPrintArea {
            box-shadow: none !important;
            border: 2px solid #0f172a !important;
            margin: 1.5cm auto !important;
        }
    }
</style>

<div class="space-y-6">
    <!-- Kopfbereich -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 sm:p-6 tb-no-print">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="space-y-1.5">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60">
                    <span>🧰 Schweizer Taschenmesser</span>
                    <span>·</span>
                    <span>6-in-1 Alltags-Studio · 100 % lokal &amp; werbefrei</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                    Alltags-Werkzeuge &amp; Toolbox
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-3xl">
                    Druckfertige <strong>Gäste-WLAN-Karten</strong> &amp; <strong>GiroCode-Überweisungs-QR-Codes</strong>, interaktives <strong>Glücksrad &amp; Team-Auslosung</strong>, <strong>Stoppuhr &amp; Weltzeituhr</strong>, <strong>Text-Diff-Checker</strong>, <strong>Farb- &amp; Kontrast-Studio</strong> sowie <strong>Passphrasen- &amp; Entwickler-Helfer</strong> – komplett ohne externe Webseiten.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="index.php?folder=Werkzeuge" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors">
                    <span>📁</span>
                    <span>Ordner „Werkzeuge“</span>
                </a>
                <a href="notes.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/60 transition-colors">
                    <span>📌</span>
                    <span>Notizwand</span>
                </a>
                <a href="calculator.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold bg-cyan-50 dark:bg-cyan-950/50 hover:bg-cyan-100 dark:hover:bg-cyan-900/60 text-cyan-800 dark:text-cyan-300 border border-cyan-200/80 dark:border-cyan-800/60 transition-colors">
                    <span>🧮</span>
                    <span>Zum Taschenrechner</span>
                </a>
            </div>
        </div>

        <!-- 6 Haupt-Tabs -->
        <div id="tbMainTabs" class="mt-5 pt-4 border-t border-slate-200/80 dark:border-slate-800 flex flex-wrap gap-2">
            <button type="button" onclick="switchToolboxTab('qr')" id="tbTabBtn-qr" class="tb-tab-btn active inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>📶</span>
                <span>QR, WLAN &amp; GiroCode</span>
            </button>
            <button type="button" onclick="switchToolboxTab('wheel')" id="tbTabBtn-wheel" class="tb-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>🎡</span>
                <span>Glücksrad, Teams &amp; Würfel</span>
            </button>
            <button type="button" onclick="switchToolboxTab('time')" id="tbTabBtn-time" class="tb-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>⏱️</span>
                <span>Stoppuhr, Timer &amp; Weltzeit</span>
            </button>
            <button type="button" onclick="switchToolboxTab('text')" id="tbTabBtn-text" class="tb-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>🔍</span>
                <span>Text-Diff &amp; Analyse</span>
            </button>
            <button type="button" onclick="switchToolboxTab('color')" id="tbTabBtn-color" class="tb-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>🎨</span>
                <span>Farb- &amp; Kontrast-Studio</span>
            </button>
            <button type="button" onclick="switchToolboxTab('gen')" id="tbTabBtn-gen" class="tb-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all cursor-pointer">
                <span>🔐</span>
                <span>Passphrasen, UUID &amp; Kodierung</span>
            </button>
        </div>
    </div>

    <!-- Toast-Benachrichtigungs-Banner -->
    <div id="tbToast" class="hidden fixed bottom-5 right-5 z-50 max-w-md px-4 py-3 rounded-2xl bg-slate-900 dark:bg-slate-800 text-white text-xs sm:text-sm font-semibold shadow-2xl border border-slate-700 flex items-center gap-3">
        <span id="tbToastIcon">✅</span>
        <span id="tbToastText">Gespeichert!</span>
        <a id="tbToastLink" href="#" class="hidden underline text-amber-300 hover:text-amber-200 ml-2 shrink-0">Öffnen →</a>
    </div>

    <!-- =====================================================================
         TAB 1: 📶 QR-CODE, GÄSTE-WLAN-KARTE & GIROCODE-STUDIO
         ===================================================================== -->
    <section id="tbPanel-qr" class="tb-panel grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-7 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-5 tb-no-print">
            <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-200/80 dark:border-slate-800">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">📶 QR-Code &amp; Karten-Konfigurator</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">100 % lokal im Browser berechnet – kein Passwort und keine IBAN verlässt dein Gerät.</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="setQrMode('wifi')" id="qrModeBtn-wifi" class="tb-sub-btn active px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 cursor-pointer">
                        🛜 Gäste-WLAN
                    </button>
                    <button type="button" onclick="setQrMode('giro')" id="qrModeBtn-giro" class="tb-sub-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 cursor-pointer">
                        💶 GiroCode (SEPA)
                    </button>
                    <button type="button" onclick="setQrMode('text')" id="qrModeBtn-text" class="tb-sub-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 cursor-pointer">
                        🔗 Link / Text
                    </button>
                    <button type="button" onclick="setQrMode('vcard')" id="qrModeBtn-vcard" class="tb-sub-btn px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 cursor-pointer">
                        📇 Visitenkarte
                    </button>
                </div>
            </div>

            <!-- Modus A: Gäste-WLAN -->
            <div id="qrForm-wifi" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Karten-Überschrift (für Ausdruck)</label>
                        <input type="text" id="qrWifiTitle" value="🛜 Gäste-WLAN" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">WLAN-Name (SSID) *</label>
                        <input type="text" id="qrWifiSsid" value="MiniCloud-Gast" placeholder="z. B. FritzBox-Gast" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">WLAN-Passwort</label>
                        <input type="text" id="qrWifiPass" value="Willkommen2026!" placeholder="WLAN-Schlüssel" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Verschlüsselung</label>
                        <select id="qrWifiEnc" onchange="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                            <option value="WPA" selected>WPA / WPA2 / WPA3</option>
                            <option value="WEP">WEP</option>
                            <option value="nopass">Offenes WLAN (ohne Passwort)</option>
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-5 pt-1">
                    <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" id="qrWifiShowPassOnCard" checked onchange="renderQrStudio()" class="rounded border-slate-300">
                        <span>Passwort im Klartext unter dem QR-Code auf die Karte drucken</span>
                    </label>
                    <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" id="qrWifiHidden" onchange="renderQrStudio()" class="rounded border-slate-300">
                        <span>Verstecktes Netzwerk (Hidden SSID)</span>
                    </label>
                </div>
            </div>

            <!-- Modus B: GiroCode / SEPA-Überweisungs-QR -->
            <div id="qrForm-giro" class="hidden space-y-4">
                <div class="p-3 rounded-xl bg-emerald-50/80 dark:bg-emerald-950/40 border border-emerald-200/70 dark:border-emerald-800/60 text-xs text-emerald-800 dark:text-emerald-300">
                    💡 <strong>EPC-Standard (GiroCode):</strong> Dieser QR-Code kann mit fast jeder deutschen &amp; europäischen Banking-App (Sparkasse, Volksbank, ING, DKB, N26 etc.) direkt gescannt werden – ideal für Vereinsbeiträge, Spendenkassen, Flohmarkt oder Auslagen im Freundeskreis!
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zahlungsempfänger (Name / Verein) *</label>
                        <input type="text" id="qrGiroName" value="Sportverein Musterstadt e.V." oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">IBAN *</label>
                        <input type="text" id="qrGiroIban" value="DE89 3704 0044 0532 0130 00" placeholder="DE00 0000 0000 0000 0000 00" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-mono uppercase">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Betrag in Euro (€)</label>
                        <input type="number" step="0.01" min="0" max="999999" id="qrGiroAmount" value="25.00" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Verwendungszweck (max. 140 Zeichen)</label>
                        <input type="text" maxlength="140" id="qrGiroPurpose" value="Jahresbeitrag 2026" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                </div>
            </div>

            <!-- Modus C: Freitext / Link -->
            <div id="qrForm-text" class="hidden space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Karten-Überschrift</label>
                    <input type="text" id="qrTextTitle" value="🔗 Schnell-Link scannen" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Web-Adresse (URL) oder beliebiger Text (max. 220 Byte)</label>
                    <textarea id="qrTextContent" rows="3" maxlength="220" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-mono">https://runready.cloud</textarea>
                </div>
            </div>

            <!-- Modus D: Visitenkarte (MECARD / vCard kompakt) -->
            <div id="qrForm-vcard" class="hidden space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Vor- &amp; Nachname *</label>
                        <input type="text" id="qrVcName" value="Max Mustermann" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Firma / Verein / Funktion</label>
                        <input type="text" id="qrVcOrg" value="MiniCloud Team" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Telefon / Mobil</label>
                        <input type="text" id="qrVcTel" value="+49 170 1234567" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">E-Mail-Adresse</label>
                        <input type="email" id="qrVcMail" value="info@runready.cloud" oninput="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm">
                    </div>
                </div>
            </div>

            <!-- Design-Optionen -->
            <div class="pt-3 border-t border-slate-200/80 dark:border-slate-800 grid grid-cols-2 sm:grid-cols-3 gap-3 items-center">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">QR-Farbe</label>
                    <input type="color" id="qrColorFg" value="#0f172a" oninput="renderQrStudio()" class="w-full h-9 rounded-xl border border-slate-300 dark:border-slate-700 cursor-pointer">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Hintergrund</label>
                    <input type="color" id="qrColorBg" value="#ffffff" oninput="renderQrStudio()" class="w-full h-9 rounded-xl border border-slate-300 dark:border-slate-700 cursor-pointer">
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Punkt-Stil</label>
                    <select id="qrDotStyle" onchange="renderQrStudio()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm">
                        <option value="square">Klassisch kantig</option>
                        <option value="rounded" selected>Sanft abgerundet</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Rechte Spalte: Druckfertige Live-Vorschau & Export -->
        <div class="lg:col-span-5 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 flex flex-col items-center justify-between space-y-4">
            <div id="tbQrPrintArea" class="w-full flex flex-col items-center justify-center p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700">
                <canvas id="qrCardCanvas" width="440" height="540" class="max-w-full h-auto rounded-xl shadow-md bg-white"></canvas>
            </div>
            <div id="qrStatusWarning" class="hidden w-full p-2.5 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 text-xs text-center font-medium"></div>

            <div class="w-full flex flex-wrap items-center justify-center gap-2 tb-no-print">
                <button type="button" onclick="downloadQrCardPng()" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition-colors cursor-pointer">
                    💾 Als PNG herunterladen
                </button>
                <button type="button" onclick="saveQrCardToCloud()" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition-colors cursor-pointer">
                    ☁️ In Cloud („Werkzeuge“) speichern
                </button>
                <button type="button" onclick="window.print()" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 transition-colors cursor-pointer">
                    🖨️ Karte drucken
                </button>
            </div>
        </div>
    </section>

    <!-- =====================================================================
         TAB 2: 🎡 GLÜCKSRAD, AUSLOSUNG, TEAM-GENERATOR & WÜRFEL
         ===================================================================== -->
    <section id="tbPanel-wheel" class="tb-panel hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Links: Glücksrad-Canvas -->
            <div class="lg:col-span-7 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 flex flex-col items-center space-y-4">
                <div class="w-full flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🎡 Interaktives Glücksrad &amp; Entscheider</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Drehe das Rad für Auslosungen, Aufgabenverteilung oder Vereins-Tombolas.</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" onclick="loadWheelPreset('chores')" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🧹 Haushalt</button>
                        <button type="button" onclick="loadWheelPreset('food')" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🍕 Was essen?</button>
                        <button type="button" onclick="loadWheelPreset('club')" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🏛️ Vereins-Dienst</button>
                    </div>
                </div>

                <div class="relative flex items-center justify-center my-2">
                    <canvas id="wheelCanvas" width="380" height="380" class="max-w-full h-auto cursor-pointer" onclick="spinWheel()"></canvas>
                </div>

                <div id="wheelWinnerBanner" class="hidden w-full p-3.5 rounded-2xl bg-gradient-to-r from-amber-500/20 via-emerald-500/20 to-indigo-500/20 border border-emerald-400/50 text-center">
                    <div class="text-xs uppercase tracking-wider font-bold text-emerald-700 dark:text-emerald-300">🎉 Ausgelost wurde:</div>
                    <div id="wheelWinnerText" class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white mt-0.5"></div>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <button type="button" id="spinWheelBtn" onclick="spinWheel()" class="px-6 py-2.5 rounded-xl text-sm font-extrabold bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-700 hover:to-indigo-700 text-white shadow-md cursor-pointer">
                        🎡 Jetzt drehen!
                    </button>
                    <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" id="wheelRemoveWinner" class="rounded border-slate-300">
                        <span>Gewinner nach Drehung automatisch entfernen</span>
                    </label>
                </div>
            </div>

            <!-- Rechts: Teilnehmerliste, Team-Generator & Würfel -->
            <div class="lg:col-span-5 space-y-6">
                <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 space-y-3.5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📋 Einträge / Namen (1 pro Zeile)</h3>
                        <span id="wheelCountBadge" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300">6 Einträge</span>
                    </div>
                    <textarea id="wheelItemsInput" rows="7" oninput="onWheelItemsChange()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm font-medium">Uwe
Anna
Lukas
Sarah
Markus
Elena</textarea>

                    <!-- Team- & Turnier-Generator -->
                    <div class="pt-2 border-t border-slate-200/80 dark:border-slate-800 space-y-2.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label class="text-xs font-bold text-slate-700 dark:text-slate-300">🤝 Team- &amp; Paarungs-Generator:</label>
                            <div class="flex items-center gap-1.5">
                                <select id="teamCountSelect" class="px-2.5 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold">
                                    <option value="2">2 Teams</option>
                                    <option value="3">3 Teams</option>
                                    <option value="4">4 Teams</option>
                                    <option value="pairs">1-gegen-1 Duelle</option>
                                </select>
                                <button type="button" onclick="generateRandomTeams()" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white cursor-pointer">
                                    🎲 Auslosen
                                </button>
                            </div>
                        </div>
                        <div id="teamGeneratorOutput" class="hidden p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs space-y-2"></div>
                        <button type="button" id="saveTeamsNoteBtn" onclick="saveTeamsToNote()" class="hidden w-full py-1.5 rounded-xl text-xs font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-200 cursor-pointer">
                            📌 Auslosung auf Notizwand speichern
                        </button>
                    </div>
                </div>

                <!-- Würfel, Münzwurf & Lottozahlen -->
                <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 space-y-3">
                    <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">🎲 Schnelle Zufalls-Helfer</h3>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="rollDice(6, 1)" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🎲 1× W6</button>
                        <button type="button" onclick="rollDice(6, 2)" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🎲 2× W6</button>
                        <button type="button" onclick="rollDice(20, 1)" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer">🐉 W20</button>
                        <button type="button" onclick="flipCoin()" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-200 cursor-pointer">🪙 Münzwurf</button>
                        <button type="button" onclick="drawLotto()" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 hover:bg-emerald-200 cursor-pointer">🍀 6 aus 49</button>
                    </div>
                    <div id="quickRandomOutput" class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-center text-sm font-bold text-slate-800 dark:text-slate-100">
                        Klicke auf einen Würfel oder Münzwurf!
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- =====================================================================
         TAB 3: ⏱️ STOPPUHR, COUNTDOWN-TIMER & WELTZEITUHR
         ===================================================================== -->
    <section id="tbPanel-time" class="tb-panel hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Stoppuhr mit Rundenzeiten -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">⏱️ Präzisions-Stoppuhr &amp; Runden</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Mit Zwischenzeiten (Split/Lap) &amp; Notizwand-Export.</p>
                    </div>
                    <button type="button" onclick="saveStopwatchLapsNote()" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-amber-50 dark:bg-amber-950/50 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 cursor-pointer">
                        📌 Runden als Notiz
                    </button>
                </div>

                <div id="swDisplay" class="py-5 rounded-2xl bg-slate-900 text-emerald-400 font-mono text-3xl sm:text-5xl font-extrabold text-center tracking-wider shadow-inner">
                    00:00:00.00
                </div>

                <div class="flex flex-wrap justify-center gap-2.5">
                    <button type="button" id="swStartBtn" onclick="toggleStopwatch()" class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer">
                        ▶️ Start
                    </button>
                    <button type="button" onclick="lapStopwatch()" class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                        🏁 Runde (Lap)
                    </button>
                    <button type="button" onclick="resetStopwatch()" class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer">
                        🔄 Reset
                    </button>
                </div>

                <div id="swLapsContainer" class="max-h-52 overflow-y-auto space-y-1.5 pt-2 border-t border-slate-200/80 dark:border-slate-800 text-xs font-mono">
                    <div class="text-center text-slate-400 py-3 font-sans">Noch keine Rundenzeiten gestoppt.</div>
                </div>
            </div>

            <!-- Countdown / Fokus- & Küchentimer -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">⏲️ Countdown-, Fokus- &amp; Küchentimer</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Akustisches Signal bei Ablauf – ideal für Pomodoro, Kochen, Sport oder Meetings.</p>
                </div>

                <div id="cdDisplay" class="py-5 rounded-2xl bg-slate-900 text-amber-400 font-mono text-3xl sm:text-5xl font-extrabold text-center tracking-wider shadow-inner">
                    05:00
                </div>

                <div class="flex flex-wrap items-center justify-center gap-1.5">
                    <button type="button" onclick="setCountdownPreset(180, '🍵 3 Min. Tee')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🍵 3 Min.</button>
                    <button type="button" onclick="setCountdownPreset(300, '🥚 5 Min. Frühstücksei')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🥚 5 Min.</button>
                    <button type="button" onclick="setCountdownPreset(600, '🍕 10 Min. Ofen')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🍕 10 Min.</button>
                    <button type="button" onclick="setCountdownPreset(900, '☕ 15 Min. Pause')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">☕ 15 Min.</button>
                    <button type="button" onclick="setCountdownPreset(1500, '🍅 25 Min. Pomodoro')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 cursor-pointer">🍅 25 Min.</button>
                    <button type="button" onclick="setCountdownPreset(2700, '💪 45 Min. Fokus')" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 cursor-pointer">💪 45 Min.</button>
                </div>

                <div class="grid grid-cols-3 gap-2 max-w-xs mx-auto">
                    <div>
                        <label class="block text-[11px] text-center text-slate-500">Stunden</label>
                        <input type="number" id="cdHours" min="0" max="99" value="0" onchange="syncCustomCountdown()" class="w-full px-2 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 text-center font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] text-center text-slate-500">Minuten</label>
                        <input type="number" id="cdMinutes" min="0" max="59" value="5" onchange="syncCustomCountdown()" class="w-full px-2 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 text-center font-mono text-sm">
                    </div>
                    <div>
                        <label class="block text-[11px] text-center text-slate-500">Sekunden</label>
                        <input type="number" id="cdSeconds" min="0" max="59" value="0" onchange="syncCustomCountdown()" class="w-full px-2 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 text-center font-mono text-sm">
                    </div>
                </div>

                <div class="flex flex-wrap justify-center gap-2.5">
                    <button type="button" id="cdStartBtn" onclick="toggleCountdown()" class="px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-amber-600 hover:bg-amber-700 text-white cursor-pointer">
                        ▶️ Timer starten
                    </button>
                    <button type="button" onclick="resetCountdown()" class="px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer">
                        🔄 Zurücksetzen
                    </button>
                </div>
            </div>
        </div>

        <!-- Weltzeituhr & Zeitzonen-Planer -->
        <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🌍 Weltzeituhr &amp; Zeitzonen-Konferenzplaner</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Verschiebe den Regler, um zu prüfen, wie spät es bei internationalen Telefonaten oder Reisen an anderen Orten ist.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span id="worldClockOffsetLabel" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-brand-50 dark:bg-brand-950 text-brand-700 dark:text-brand-300">Live-Uhrzeit (Jetzt)</span>
                    <button type="button" onclick="resetWorldClockOffset()" class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">Auf Jetzt</button>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs font-mono text-slate-400">-12h</span>
                <input type="range" id="worldClockSlider" min="-720" max="720" step="15" value="0" oninput="updateWorldClocks()" class="flex-1 accent-brand-600 cursor-pointer">
                <span class="text-xs font-mono text-slate-400">+12h</span>
            </div>

            <div id="worldClockGrid" class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3"></div>
        </div>
    </section>

    <!-- =====================================================================
         TAB 4: 🔍 TEXT-WERKSTATT & DOKUMENTEN-VERGLEICH (DIFF-CHECKER)
         ===================================================================== -->
    <section id="tbPanel-text" class="tb-panel hidden space-y-6">
        <!-- Text-Diff-Checker -->
        <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔍 Text- &amp; Dokumenten-Vergleich (Diff-Checker)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Vergleicht zwei Textfassungen (z. B. Satzungsänderungen, Verträge, Protokolle oder Code) Zeile für Zeile.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="runTextDiff()" class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                        🔍 Unterschiede markieren
                    </button>
                    <button type="button" onclick="swapDiffTexts()" class="px-3 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">
                        ⇄ Tauschen
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">📄 Original-Text (Fassung A)</label>
                    <textarea id="diffTextA" rows="6" oninput="runTextDiff()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm font-mono">§ 1 Mitgliedsbeitrag
Der Jahresbeitrag für Erwachsene beträgt 24,00 Euro.
Die Zahlung erfolgt jeweils zum 1. März.
Jugendliche zahlen den halben Beitrag.</textarea>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">📝 Geänderter Text (Fassung B)</label>
                    <textarea id="diffTextB" rows="6" oninput="runTextDiff()" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm font-mono">§ 1 Mitgliedsbeitrag
Der Jahresbeitrag für Erwachsene beträgt 30,00 Euro.
Die Zahlung erfolgt jeweils zum 15. Februar per SEPA.
Jugendliche zahlen den halben Beitrag.
Ehrenmitglieder sind beitragsfrei.</textarea>
                </div>
            </div>

            <div id="diffOutputBox" class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 font-mono text-xs sm:text-sm space-y-1 max-h-72 overflow-y-auto"></div>
        </div>

        <!-- Text-Analyse & 1-Klick-Umwandler -->
        <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">✍️ Text-Statistik, Reiniger &amp; Zeilen-Werkzeuge</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Wörter, Zeichen &amp; Lesezeit zählen, doppelte Zeilen löschen, sortieren oder formatieren.</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="saveCleanTextToCloud()" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer">☁️ Als .txt in Cloud</button>
                    <button type="button" onclick="saveCleanTextToNote()" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 cursor-pointer">📌 Als Notiz</button>
                </div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-6 gap-2.5 text-center">
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statWords" class="text-lg font-extrabold text-brand-600 dark:text-brand-400">0</div>
                    <div class="text-[11px] text-slate-500">Wörter</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statChars" class="text-lg font-extrabold text-slate-800 dark:text-slate-100">0</div>
                    <div class="text-[11px] text-slate-500">Zeichen (mit Leerz.)</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statCharsNoSpace" class="text-lg font-extrabold text-slate-800 dark:text-slate-100">0</div>
                    <div class="text-[11px] text-slate-500">Ohne Leerzeichen</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statLines" class="text-lg font-extrabold text-slate-800 dark:text-slate-100">0</div>
                    <div class="text-[11px] text-slate-500">Zeilen</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statReadTime" class="text-lg font-extrabold text-emerald-600 dark:text-emerald-400">0 Sek.</div>
                    <div class="text-[11px] text-slate-500">Lesezeit (~200 W/m)</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <div id="statSpeakTime" class="text-lg font-extrabold text-indigo-600 dark:text-indigo-400">0 Sek.</div>
                    <div class="text-[11px] text-slate-500">Redezeit (~130 W/m)</div>
                </div>
            </div>

            <textarea id="textWorkshopInput" rows="6" oninput="updateTextStats()" placeholder="Füge hier deinen Text, deine Namensliste oder dein Protokoll ein…" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm"></textarea>

            <div class="flex flex-wrap gap-1.5">
                <button type="button" onclick="transformWorkshopText('sort_asc')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🔤 Zeilen A → Z</button>
                <button type="button" onclick="transformWorkshopText('sort_desc')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🔡 Zeilen Z → A</button>
                <button type="button" onclick="transformWorkshopText('dedupe')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🧹 Doppelte Zeilen entfernen</button>
                <button type="button" onclick="transformWorkshopText('remove_empty')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">↕️ Leerzeilen entfernen</button>
                <button type="button" onclick="transformWorkshopText('number_lines')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🔢 Zeilen nummerieren</button>
                <button type="button" onclick="transformWorkshopText('upper')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">GROSSBUCHSTABEN</button>
                <button type="button" onclick="transformWorkshopText('lower')" class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">kleinbuchstaben</button>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('textWorkshopInput').value); showTbToast('Text in die Zwischenablage kopiert!');" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer">📋 Kopieren</button>
            </div>
        </div>
    </section>

    <!-- =====================================================================
         TAB 5: 🎨 FARB-STUDIO, HARMONIEN & WCAG-KONTRAST-PRÜFER
         ===================================================================== -->
    <section id="tbPanel-color" class="tb-panel hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Farbwähler, Umrechner & Harmonien -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🎨 Farbwähler, Umrechner &amp; Harmonien</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">HEX, RGB, HSL &amp; CMYK live umrechnen und passende Farbpaletten generieren.</p>
                </div>

                <div class="flex items-center gap-4">
                    <input type="color" id="colorPickerMain" value="#2563eb" oninput="onMainColorPick(this.value)" class="w-20 h-20 rounded-2xl border border-slate-300 dark:border-slate-700 cursor-pointer shrink-0">
                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500">HEX-Code</label>
                            <input type="text" id="colorHexInput" value="#2563EB" oninput="onHexTextInput(this.value)" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 font-mono text-xs sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500">RGB</label>
                            <input type="text" id="colorRgbOutput" readonly class="w-full px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs sm:text-sm cursor-pointer" onclick="navigator.clipboard.writeText(this.value); showTbToast('RGB kopiert!');">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500">HSL</label>
                            <input type="text" id="colorHslOutput" readonly class="w-full px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs sm:text-sm cursor-pointer" onclick="navigator.clipboard.writeText(this.value); showTbToast('HSL kopiert!');">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-500">CMYK (Druck)</label>
                            <input type="text" id="colorCmykOutput" readonly class="w-full px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs sm:text-sm cursor-pointer" onclick="navigator.clipboard.writeText(this.value); showTbToast('CMYK kopiert!');">
                        </div>
                    </div>
                </div>

                <div>
                    <div class="text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">✨ Automatische Farbharmonien (Klick kopiert HEX):</div>
                    <div id="colorHarmonySwatches" class="grid grid-cols-3 sm:grid-cols-6 gap-2"></div>
                </div>
            </div>

            <!-- WCAG Kontrast-Prüfer -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">👁️ Barrierefreiheits- &amp; Kontrast-Prüfer (WCAG)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Prüft die Lesbarkeit von Schrift- und Hintergrundfarben für Plakate, Präsentationen &amp; Webseiten.</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Schriftfarbe</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="wcagFgColor" value="#ffffff" oninput="updateWcagContrast()" class="w-10 h-9 rounded-lg cursor-pointer">
                            <input type="text" id="wcagFgHex" value="#FFFFFF" oninput="document.getElementById('wcagFgColor').value=this.value; updateWcagContrast();" class="flex-1 px-2.5 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 font-mono text-xs">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Hintergrundfarbe</label>
                        <div class="flex items-center gap-2">
                            <input type="color" id="wcagBgColor" value="#1e40af" oninput="updateWcagContrast()" class="w-10 h-9 rounded-lg cursor-pointer">
                            <input type="text" id="wcagBgHex" value="#1E40AF" oninput="document.getElementById('wcagBgColor').value=this.value; updateWcagContrast();" class="flex-1 px-2.5 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 font-mono text-xs">
                        </div>
                    </div>
                </div>

                <div id="wcagPreviewCard" class="p-4 rounded-2xl border border-slate-300 dark:border-slate-700 transition-colors" style="background-color:#1e40af; color:#ffffff;">
                    <div class="text-lg font-bold">Beispiel-Überschrift in großer Schrift (18 pt+)</div>
                    <p class="text-xs sm:text-sm mt-1">So wirkt normaler Fließtext auf diesem Hintergrund. Ein gutes Kontrastverhältnis (mindestens 4.5 : 1) sorgt für beste Lesbarkeit auf Handys und Drucksachen.</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div>
                        <span class="text-xs text-slate-500">Kontrastverhältnis:</span>
                        <strong id="wcagRatioText" class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white ml-1">8.59 : 1</strong>
                    </div>
                    <div class="flex gap-2">
                        <span id="wcagBadgeNormal" class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Normaltext: AAA ✓</span>
                        <span id="wcagBadgeLarge" class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Großtext: AAA ✓</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- =====================================================================
         TAB 6: 🔐 GENERATOR- & KODIERUNGS-WERKBANK
         ===================================================================== -->
    <section id="tbPanel-gen" class="tb-panel hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Passwort-, Passphrasen- & UUID-Generator -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔐 Passwort-, Passphrasen- &amp; PIN-Generator</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Kryptografisch sichere Zufallswerte über <code>window.crypto</code>.</p>
                    </div>
                    <a href="passwords.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Zum Schlüsselbund →</a>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="generateSecret('passphrase')" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">🗣️ Deutsche Wort-Passphrase</button>
                    <button type="button" onclick="generateSecret('password')" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white cursor-pointer">🔑 Starkes Passwort (20 Z.)</button>
                    <button type="button" onclick="generateSecret('pin')" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🔢 6-stellige PIN</button>
                    <button type="button" onclick="generateSecret('uuid')" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">🆔 UUID v4</button>
                </div>

                <div class="flex items-center gap-2">
                    <input type="text" id="secretGeneratorOutput" readonly class="flex-1 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-sm sm:text-base font-bold text-emerald-700 dark:text-emerald-300">
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('secretGeneratorOutput').value); showTbToast('In Zwischenablage kopiert!');" class="px-4 py-2.5 rounded-xl text-xs font-bold bg-slate-900 dark:bg-slate-700 text-white cursor-pointer shrink-0">
                        📋 Kopieren
                    </button>
                </div>
                <div id="secretEntropyInfo" class="text-xs text-slate-500 dark:text-slate-400"></div>
            </div>

            <!-- Base64, URL, JSON & SHA-256 -->
            <div class="lg:col-span-6 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-5 sm:p-6 space-y-3.5">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🛠️ Kodierungs-, JSON- &amp; Hash-Werkbank</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Base64, URL-Encoding, JSON-Pretty-Print und SHA-256 Prüfsumme.</p>
                </div>

                <textarea id="codecInput" rows="4" placeholder="Text, URL oder JSON hier eingeben…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 font-mono text-xs sm:text-sm"></textarea>

                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="runCodec('b64_enc')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">Base64 Kodieren</button>
                    <button type="button" onclick="runCodec('b64_dec')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">Base64 Dekodieren</button>
                    <button type="button" onclick="runCodec('url_enc')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">URL Encode</button>
                    <button type="button" onclick="runCodec('url_dec')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">URL Decode</button>
                    <button type="button" onclick="runCodec('json_fmt')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 cursor-pointer">{ } JSON Formatieren</button>
                    <button type="button" onclick="runCodec('sha256')" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 cursor-pointer"># SHA-256 Hash</button>
                </div>

                <textarea id="codecOutput" rows="3" readonly placeholder="Ergebnis erscheint hier…" class="w-full px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800/90 font-mono text-xs sm:text-sm"></textarea>
            </div>
        </div>
    </section>
</div>

<script>
const TB_CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;

function showTbToast(msg, linkUrl = '') {
    const box  = document.getElementById('tbToast');
    const txt  = document.getElementById('tbToastText');
    const link = document.getElementById('tbToastLink');
    if (!box || !txt) return;
    txt.textContent = msg;
    if (link) {
        if (linkUrl) {
            link.href = linkUrl;
            link.classList.remove('hidden');
        } else {
            link.classList.add('hidden');
        }
    }
    box.classList.remove('hidden');
    clearTimeout(window._tbToastTimer);
    window._tbToastTimer = setTimeout(() => box.classList.add('hidden'), 4200);
}

function switchToolboxTab(tab) {
    document.querySelectorAll('.tb-panel').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tb-tab-btn').forEach(btn => btn.classList.remove('active'));
    const panel = document.getElementById('tbPanel-' + tab);
    const btn   = document.getElementById('tbTabBtn-' + tab);
    if (panel) panel.classList.remove('hidden');
    if (btn) btn.classList.add('active');

    if (tab === 'qr') renderQrStudio();
    if (tab === 'wheel') drawWheel();
    if (tab === 'time') updateWorldClocks();
    if (tab === 'text') { runTextDiff(); updateTextStats(); }
    if (tab === 'color') { onMainColorPick(document.getElementById('colorPickerMain').value); updateWcagContrast(); }
}

// ============================================================================
// 1. ISO/IEC 18004 QR-CODE REED-SOLOMON ENGINE (Versionen 1–10, Level L)
// ============================================================================
let currentQrMode = 'wifi';

function setQrMode(mode) {
    currentQrMode = mode;
    ['wifi', 'giro', 'text', 'vcard'].forEach(m => {
        const form = document.getElementById('qrForm-' + m);
        const btn  = document.getElementById('qrModeBtn-' + m);
        if (form) form.classList.toggle('hidden', m !== mode);
        if (btn) btn.classList.toggle('active', m === mode);
    });
    renderQrStudio();
}

function computeQrMatrix(text) {
    const utf8 = new TextEncoder().encode(text);
    const versions = {
        1:  [26,   7,  1, 19,  0, 0,  []],
        2:  [44,   10, 1, 34,  0, 0,  [6, 18]],
        3:  [70,   15, 1, 55,  0, 0,  [6, 22]],
        4:  [100,  20, 1, 80,  0, 0,  [6, 26]],
        5:  [134,  26, 1, 108, 0, 0,  [6, 30]],
        6:  [172,  18, 2, 68,  0, 0,  [6, 34]],
        7:  [196,  20, 2, 78,  0, 0,  [6, 22, 38]],
        8:  [242,  24, 2, 97,  0, 0,  [6, 24, 42]],
        9:  [292,  30, 2, 116, 0, 0,  [6, 26, 46]],
        10: [346,  18, 2, 68,  2, 69, [6, 28, 50]]
    };

    const dataLen = utf8.length;
    let ver = 0;
    for (let v = 1; v <= 10; v++) {
        const cfg = versions[v];
        const totalDataCw = cfg[2] * cfg[3] + cfg[4] * cfg[5];
        const headerBits = v <= 9 ? 12 : 20;
        const maxBytes = Math.floor((totalDataCw * 8 - headerBits) / 8);
        if (dataLen <= maxBytes) {
            ver = v;
            break;
        }
    }
    if (ver === 0) return null;

    const [totalCw, ecCwPerBlock, b1, d1, b2, d2, align] = versions[ver];
    const totalDataCw = b1 * d1 + b2 * d2;

    let bits = '0100';
    const lenBits = ver <= 9 ? 8 : 16;
    bits += dataLen.toString(2).padStart(lenBits, '0');
    for (let i = 0; i < dataLen; i++) {
        bits += utf8[i].toString(2).padStart(8, '0');
    }

    const maxDataBits = totalDataCw * 8;
    const term = Math.min(4, maxDataBits - bits.length);
    if (term > 0) bits += '0'.repeat(term);
    while (bits.length % 8 !== 0) bits += '0';

    const padBytes = ['11101100', '00010001'];
    let pIdx = 0;
    while (bits.length < maxDataBits) {
        bits += padBytes[pIdx % 2];
        pIdx++;
    }

    const dataCw = [];
    for (let i = 0; i < totalDataCw; i++) {
        dataCw.push(parseInt(bits.slice(i * 8, i * 8 + 8), 2));
    }

    const gfExp = new Array(512).fill(0);
    const gfLog = new Array(256).fill(0);
    let x = 1;
    for (let i = 0; i < 255; i++) {
        gfExp[i] = x;
        gfLog[x] = i;
        x <<= 1;
        if (x & 0x100) x ^= 0x11D;
    }
    for (let i = 255; i < 512; i++) gfExp[i] = gfExp[i - 255];
    const gfMul = (a, b) => (a === 0 || b === 0) ? 0 : gfExp[gfLog[a] + gfLog[b]];

    let gen = [1];
    for (let i = 0; i < ecCwPerBlock; i++) {
        const next = new Array(gen.length + 1).fill(0);
        for (let j = 0; j < gen.length; j++) {
            next[j] ^= gen[j];
            next[j + 1] ^= gfMul(gen[j], gfExp[i]);
        }
        gen = next;
    }

    const blockLengths = [];
    for (let i = 0; i < b1; i++) blockLengths.push(d1);
    for (let i = 0; i < b2; i++) blockLengths.push(d2);

    const dataBlocks = [];
    const ecBlocks   = [];
    let offset = 0;
    for (const bLen of blockLengths) {
        const block = dataCw.slice(offset, offset + bLen);
        offset += bLen;
        dataBlocks.push(block);

        const msg = block.concat(new Array(ecCwPerBlock).fill(0));
        for (let i = 0; i < bLen; i++) {
            const coef = msg[i];
            if (coef !== 0) {
                for (let j = 0; j <= ecCwPerBlock; j++) {
                    msg[i + j] ^= gfMul(gen[j], coef);
                }
            }
        }
        ecBlocks.push(msg.slice(bLen));
    }

    const finalCw = [];
    const maxDataLen = Math.max(d1, d2);
    for (let i = 0; i < maxDataLen; i++) {
        for (const blk of dataBlocks) {
            if (i < blk.length) finalCw.push(blk[i]);
        }
    }
    for (let i = 0; i < ecCwPerBlock; i++) {
        for (const eblk of ecBlocks) finalCw.push(eblk[i]);
    }

    const dim = 17 + 4 * ver;
    const matrix   = Array.from({ length: dim }, () => new Array(dim).fill(0));
    const reserved = Array.from({ length: dim }, () => new Array(dim).fill(false));

    const placeFinder = (r0, c0) => {
        for (let r = -1; r <= 7; r++) {
            for (let c = -1; c <= 7; c++) {
                const rr = r0 + r, cc = c0 + c;
                if (rr < 0 || rr >= dim || cc < 0 || cc >= dim) continue;
                const inOuter  = (r >= 0 && r <= 6 && c >= 0 && c <= 6);
                const inBorder = (r === 0 || r === 6 || c === 0 || c === 6);
                const inCenter = (r >= 2 && r <= 4 && c >= 2 && c <= 4);
                matrix[rr][cc]   = (inOuter && (inBorder || inCenter)) ? 1 : 0;
                reserved[rr][cc] = true;
            }
        }
    };
    placeFinder(0, 0);
    placeFinder(0, dim - 7);
    placeFinder(dim - 7, 0);

    for (let i = 0; i < align.length; i++) {
        for (let j = 0; j < align.length; j++) {
            if ((i === 0 && j === 0) || (i === 0 && j === align.length - 1) || (i === align.length - 1 && j === 0)) continue;
            const ar = align[i], ac = align[j];
            for (let r = -2; r <= 2; r++) {
                for (let c = -2; c <= 2; c++) {
                    matrix[ar + r][ac + c]   = (Math.abs(r) === 2 || Math.abs(c) === 2 || (r === 0 && c === 0)) ? 1 : 0;
                    reserved[ar + r][ac + c] = true;
                }
            }
        }
    }

    for (let i = 8; i < dim - 8; i++) {
        if (!reserved[6][i]) { matrix[6][i] = (i % 2 === 0) ? 1 : 0; reserved[6][i] = true; }
        if (!reserved[i][6]) { matrix[i][6] = (i % 2 === 0) ? 1 : 0; reserved[i][6] = true; }
    }

    matrix[4 * ver + 9][8]   = 1;
    reserved[4 * ver + 9][8] = true;
    for (let i = 0; i < 9; i++) {
        reserved[8][i] = true;
        reserved[i][8] = true;
    }
    for (let i = 0; i < 8; i++) {
        reserved[8][dim - 1 - i] = true;
        reserved[dim - 1 - i][8] = true;
    }

    if (ver >= 7) {
        const verInfoTable = { 7: 0x07C94, 8: 0x085BC, 9: 0x09A99, 10: 0x0A4D3 };
        const vInfo = verInfoTable[ver] || 0;
        for (let i = 0; i < 18; i++) {
            const bit = (vInfo >> i) & 1;
            const a = Math.floor(i / 3);
            const b = (i % 3) + dim - 11;
            matrix[a][b] = bit; reserved[a][b] = true;
            matrix[b][a] = bit; reserved[b][a] = true;
        }
    }

    const allBits = [];
    for (const cw of finalCw) {
        for (let b = 7; b >= 0; b--) allBits.push((cw >> b) & 1);
    }

    let bitIdx = 0;
    let upward = true;
    for (let col = dim - 1; col > 0; col -= 2) {
        if (col === 6) col--;
        for (let step = 0; step < dim; step++) {
            const row = upward ? (dim - 1 - step) : step;
            for (let cOff = 0; cOff < 2; cOff++) {
                const c = col - cOff;
                if (!reserved[row][c]) {
                    const bit = bitIdx < allBits.length ? allBits[bitIdx++] : 0;
                    const masked = ((row + c) % 2 === 0) ? (bit ^ 1) : bit;
                    matrix[row][c] = masked;
                }
            }
        }
        upward = !upward;
    }

    // Format-Bits für Level L (01) und Mask 0 (000) => 0x77C4
    const fmtBits = 0x77C4;
    const fmtCoords1 = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
    const fmtCoords2 = [
        [dim - 1, 8],[dim - 2, 8],[dim - 3, 8],[dim - 4, 8],[dim - 5, 8],[dim - 6, 8],[dim - 7, 8],
        [8, dim - 8],[8, dim - 7],[8, dim - 6],[8, dim - 5],[8, dim - 4],[8, dim - 3],[8, dim - 2],[8, dim - 1]
    ];
    for (let i = 0; i < 15; i++) {
        const bit = (fmtBits >> i) & 1;
        matrix[fmtCoords1[i][0]][fmtCoords1[i][1]] = bit;
        matrix[fmtCoords2[i][0]][fmtCoords2[i][1]] = bit;
    }

    return matrix;
}

function escapeWifiField(str) {
    return String(str || '').replace(/([\\;,:])/g, '\\$1');
}

function renderQrStudio() {
    const canvas = document.getElementById('qrCardCanvas');
    const warnEl = document.getElementById('qrStatusWarning');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    let payload = '';
    let headerTitle = '';
    let subLine1 = '';
    let subLine2 = '';
    let badgeText = '';

    if (currentQrMode === 'wifi') {
        const title  = document.getElementById('qrWifiTitle').value.trim() || '🛜 Gäste-WLAN';
        const ssid   = document.getElementById('qrWifiSsid').value.trim();
        const pass   = document.getElementById('qrWifiPass').value;
        const enc    = document.getElementById('qrWifiEnc').value;
        const showPw = document.getElementById('qrWifiShowPassOnCard').checked;
        const hidden = document.getElementById('qrWifiHidden').checked;

        payload = `WIFI:T:${enc};S:${escapeWifiField(ssid)};P:${enc === 'nopass' ? '' : escapeWifiField(pass)};H:${hidden ? 'true' : 'false'};;`;
        headerTitle = title;
        subLine1    = `Netzwerk (SSID): ${ssid || '—'}`;
        subLine2    = enc === 'nopass' ? 'Offenes WLAN (kein Passwort)' : (showPw ? `Passwort: ${pass}` : 'Kamera auf QR-Code richten zum Verbinden');
        badgeText   = '📱 Kamera öffnen & QR-Code scannen';
    } else if (currentQrMode === 'giro') {
        const name    = document.getElementById('qrGiroName').value.trim().slice(0, 70);
        const ibanRaw = document.getElementById('qrGiroIban').value.replace(/\s+/g, '').toUpperCase();
        const amount  = parseFloat(document.getElementById('qrGiroAmount').value || '0');
        const purpose = document.getElementById('qrGiroPurpose').value.trim().slice(0, 140);
        const amtStr  = amount > 0 ? ('EUR' + amount.toFixed(2)) : '';

        payload = [
            'BCD',
            '002',
            '1',
            'SCT',
            '',
            name,
            ibanRaw,
            amtStr,
            '',
            '',
            purpose
        ].join('\n');

        headerTitle = '💶 GiroCode-Überweisung';
        subLine1    = `${name} · ${ amount > 0 ? amount.toFixed(2).replace('.', ',') + ' €' : 'Betrag frei' }`;
        subLine2    = `IBAN: ${ibanRaw.replace(/(.{4})/g, '$1 ').trim()}`;
        badgeText   = purpose ? `Verwendungszweck: ${purpose}` : 'In deiner Banking-App scannen';
    } else if (currentQrMode === 'text') {
        headerTitle = document.getElementById('qrTextTitle').value.trim() || '🔗 QR-Code';
        payload     = document.getElementById('qrTextContent').value.trim() || 'https://runready.cloud';
        subLine1    = payload.length > 44 ? payload.slice(0, 44) + '…' : payload;
        subLine2    = '';
        badgeText   = '📱 Mit Smartphone-Kamera scannen';
    } else if (currentQrMode === 'vcard') {
        const name = document.getElementById('qrVcName').value.trim();
        const org  = document.getElementById('qrVcOrg').value.trim();
        const tel  = document.getElementById('qrVcTel').value.trim();
        const mail = document.getElementById('qrVcMail').value.trim();
        payload = `MECARD:N:${name};ORG:${org};TEL:${tel};EMAIL:${mail};;`;
        headerTitle = name || '📇 Visitenkarte';
        subLine1    = org ? `${org} · ${tel}` : tel;
        subLine2    = mail;
        badgeText   = '📇 Scannen zum Speichern im Adressbuch';
    }

    const matrix = computeQrMatrix(payload);
    if (!matrix) {
        if (warnEl) {
            warnEl.textContent = '⚠️ Der eingegebene Text ist zu lang für einen lokalen Standard-QR-Code (max. ca. 220 Zeichen).';
            warnEl.classList.remove('hidden');
        }
        return;
    }
    if (warnEl) warnEl.classList.add('hidden');

    const fgColor  = document.getElementById('qrColorFg').value || '#0f172a';
    const bgColor  = document.getElementById('qrColorBg').value || '#ffffff';
    const dotStyle = document.getElementById('qrDotStyle').value || 'rounded';

    // Karte zeichnen (440 x 540)
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Dekorativer Kopfbalken
    ctx.fillStyle = fgColor;
    ctx.fillRect(0, 0, canvas.width, 76);

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 22px system-ui, -apple-system, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(headerTitle.slice(0, 32), canvas.width / 2, 46);

    // QR-Bereich
    const qrBoxSize = 310;
    const qrX = (canvas.width - qrBoxSize) / 2;
    const qrY = 96;

    ctx.fillStyle = bgColor;
    ctx.fillRect(qrX, qrY, qrBoxSize, qrBoxSize);

    const dim    = matrix.length;
    const quiet  = 3;
    const totalM = dim + quiet * 2;
    const cell   = qrBoxSize / totalM;

    ctx.fillStyle = fgColor;
    for (let r = 0; r < dim; r++) {
        for (let c = 0; c < dim; c++) {
            if (matrix[r][c]) {
                const px = qrX + (c + quiet) * cell;
                const py = qrY + (r + quiet) * cell;
                if (dotStyle === 'rounded') {
                    ctx.beginPath();
                    ctx.roundRect(px, py, cell + 0.35, cell + 0.35, cell * 0.28);
                    ctx.fill();
                } else {
                    ctx.fillRect(px, py, Math.ceil(cell), Math.ceil(cell));
                }
            }
        }
    }

    // Fußbereich mit Beschriftung
    ctx.fillStyle = '#0f172a';
    ctx.font = 'bold 16px system-ui, -apple-system, sans-serif';
    if (subLine1) {
        ctx.fillText(subLine1.slice(0, 44), canvas.width / 2, 435);
    }
    if (subLine2) {
        ctx.fillStyle = '#334155';
        ctx.font = '600 14px ui-monospace, SFMono-Regular, monospace';
        ctx.fillText(subLine2.slice(0, 46), canvas.width / 2, 465);
    }
    if (badgeText) {
        ctx.fillStyle = '#64748b';
        ctx.font = '500 13px system-ui, -apple-system, sans-serif';
        ctx.fillText(badgeText.slice(0, 52), canvas.width / 2, 505);
    }
}

function downloadQrCardPng() {
    const canvas = document.getElementById('qrCardCanvas');
    if (!canvas) return;
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = `MiniCloud_QR_${currentQrMode}_${new Date().toISOString().slice(0,10)}.png`;
    a.click();
}

async function saveQrCardToCloud() {
    const canvas = document.getElementById('qrCardCanvas');
    if (!canvas) return;
    const fd = new FormData();
    fd.append('csrf_token', TB_CSRF_TOKEN);
    fd.append('ajax_action', 'save_qr_image');
    fd.append('title', 'QR_Karte_' + currentQrMode);
    fd.append('image_data', canvas.toDataURL('image/png'));

    const res  = await fetch('toolbox.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
        showTbToast(data.message, data.url);
    } else {
        showTbToast('⚠️ ' + (data.error || 'Fehler beim Speichern'));
    }
}

// ============================================================================
// 2. 🎡 GLÜCKSRAD, TEAM-GENERATOR & WÜRFEL
// ============================================================================
let wheelAngle = 0;
let wheelSpinning = false;
const WHEEL_COLORS = ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

function getWheelItems() {
    const raw = document.getElementById('wheelItemsInput')?.value || '';
    return raw.split('\n').map(s => s.trim()).filter(Boolean);
}

function onWheelItemsChange() {
    const items = getWheelItems();
    const badge = document.getElementById('wheelCountBadge');
    if (badge) badge.textContent = `${items.length} Einträge`;
    drawWheel();
}

function loadWheelPreset(type) {
    const el = document.getElementById('wheelItemsInput');
    if (!el) return;
    if (type === 'chores') {
        el.value = "Spülmaschine ausräumen\nMüll rausbringen\nStaubsaugen\nKüche wischen\nWäsche aufhängen\nFreilos 🎉";
    } else if (type === 'food') {
        el.value = "Pizza backen 🍕\nPasta & Pesto 🍝\nPfannkuchen 🥞\nAsiatische Wok-Pfanne 🍜\nBurger & Pommes 🍔\nGroßer Salat & Baguette 🥗";
    } else if (type === 'club') {
        el.value = "Getränkedienst 🍺\nGrillmeister 🔥\nKasse & Einlass 🎟️\nAufbau-Team 🏗️\nKuchenbuffet 🍰\nProtokollführer 📝";
    }
    onWheelItemsChange();
}

function drawWheel() {
    const canvas = document.getElementById('wheelCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const items = getWheelItems();
    const n = Math.max(1, items.length);
    const cx = canvas.width / 2;
    const cy = canvas.height / 2;
    const r  = cx - 16;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const arc = (Math.PI * 2) / n;
    for (let i = 0; i < n; i++) {
        const start = wheelAngle + i * arc;
        const end   = start + arc;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, start, end);
        ctx.closePath();
        ctx.fillStyle = WHEEL_COLORS[i % WHEEL_COLORS.length];
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();

        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(start + arc / 2);
        ctx.textAlign = 'right';
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 14px system-ui, sans-serif';
        const label = (items[i] || '—').slice(0, 20);
        ctx.fillText(label, r - 18, 5);
        ctx.restore();
    }

    // Mittelknopf
    ctx.beginPath();
    ctx.arc(cx, cy, 24, 0, Math.PI * 2);
    ctx.fillStyle = '#0f172a';
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#ffffff';
    ctx.stroke();

    // Zeiger rechts (auf 0 Rad)
    ctx.beginPath();
    ctx.moveTo(canvas.width - 4, cy - 14);
    ctx.lineTo(canvas.width - 28, cy);
    ctx.lineTo(canvas.width - 4, cy + 14);
    ctx.closePath();
    ctx.fillStyle = '#f43f5e';
    ctx.fill();
    ctx.strokeStyle = '#ffffff';
    ctx.stroke();
}

function playTickSound(freq = 520, dur = 0.03) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!window._tbAudioCtx) window._tbAudioCtx = new AudioCtx();
        const ctx = window._tbAudioCtx;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = freq;
        gain.gain.value = 0.08;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + dur);
    } catch (e) {}
}

function spinWheel() {
    if (wheelSpinning) return;
    const items = getWheelItems();
    if (items.length === 0) return;

    wheelSpinning = true;
    const banner = document.getElementById('wheelWinnerBanner');
    if (banner) banner.classList.add('hidden');

    const extraSpins = (5 + Math.random() * 4) * Math.PI * 2;
    const startAngle = wheelAngle;
    const targetAngle = startAngle + extraSpins;
    const duration = 3400;
    const t0 = performance.now();
    let lastSector = -1;

    function step(now) {
        const elapsed = now - t0;
        const p = Math.min(1, elapsed / duration);
        const ease = 1 - Math.pow(1 - p, 3);
        wheelAngle = startAngle + (targetAngle - startAngle) * ease;
        drawWheel();

        const arc = (Math.PI * 2) / items.length;
        const norm = ((Math.PI * 2) - (wheelAngle % (Math.PI * 2))) % (Math.PI * 2);
        const sector = Math.floor(norm / arc) % items.length;
        if (sector !== lastSector) {
            lastSector = sector;
            playTickSound(440 + (sector % 5) * 60, 0.025);
        }

        if (p < 1) {
            requestAnimationFrame(step);
        } else {
            wheelSpinning = false;
            const winner = items[sector];
            const winTxt = document.getElementById('wheelWinnerText');
            if (winTxt && banner) {
                winTxt.textContent = winner;
                banner.classList.remove('hidden');
            }
            playTickSound(880, 0.18);

            if (document.getElementById('wheelRemoveWinner')?.checked && items.length > 1) {
                items.splice(sector, 1);
                document.getElementById('wheelItemsInput').value = items.join('\n');
                onWheelItemsChange();
            }
        }
    }
    requestAnimationFrame(step);
}

let lastGeneratedTeamsText = '';

function generateRandomTeams() {
    const items = getWheelItems().slice();
    if (items.length < 2) {
        showTbToast('Bitte mindestens 2 Namen eingeben!');
        return;
    }
    // Fisher-Yates Shuffle
    for (let i = items.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [items[i], items[j]] = [items[j], items[i]];
    }

    const mode = document.getElementById('teamCountSelect').value;
    const outBox = document.getElementById('teamGeneratorOutput');
    const saveBtn = document.getElementById('saveTeamsNoteBtn');
    if (!outBox) return;

    const lines = [];
    let html = '';

    if (mode === 'pairs') {
        html += '<div class="font-bold text-slate-800 dark:text-slate-100 mb-1">⚔️ Ausgeloste Paarungen / Duelle:</div>';
        for (let i = 0; i < items.length; i += 2) {
            const p1 = items[i];
            const p2 = items[i + 1] || 'Freilos';
            lines.push(`Duell ${Math.floor(i/2)+1}: ${p1} vs. ${p2}`);
            html += `<div class="py-1 border-b border-slate-200/60 dark:border-slate-700/60"><strong>Duell ${Math.floor(i/2)+1}:</strong> ${p1} <span class="text-brand-600 font-bold">vs.</span> ${p2}</div>`;
        }
    } else {
        const numTeams = parseInt(mode, 10) || 2;
        const teams = Array.from({ length: numTeams }, () => []);
        items.forEach((name, idx) => teams[idx % numTeams].push(name));
        teams.forEach((t, idx) => {
            lines.push(`Team ${idx + 1}: ${t.join(', ')}`);
            html += `<div><strong class="text-brand-600 dark:text-brand-400">Team ${idx + 1}:</strong> ${t.join(', ')}</div>`;
        });
    }

    lastGeneratedTeamsText = lines.join('\n');
    outBox.innerHTML = html;
    outBox.classList.remove('hidden');
    if (saveBtn) saveBtn.classList.remove('hidden');
}

async function saveTeamsToNote() {
    if (!lastGeneratedTeamsText) return;
    const fd = new FormData();
    fd.append('csrf_token', TB_CSRF_TOKEN);
    fd.append('ajax_action', 'save_tool_note');
    fd.append('title', 'Team-Auslosung vom ' + new Date().toLocaleDateString('de-DE'));
    fd.append('content', lastGeneratedTeamsText);
    fd.append('color', 'emerald');
    const r = await fetch('toolbox.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) showTbToast(d.message, d.url);
}

function rollDice(sides, count) {
    const rolls = [];
    for (let i = 0; i < count; i++) {
        rolls.push(1 + Math.floor(Math.random() * sides));
    }
    const sum = rolls.reduce((a, b) => a + b, 0);
    const box = document.getElementById('quickRandomOutput');
    if (box) {
        box.textContent = count > 1
            ? `🎲 ${count}× W${sides}: [ ${rolls.join(' + ')} ] = Summe ${sum}`
            : `🎲 W${sides} Ergebnis: ${sum}`;
    }
    playTickSound(600, 0.05);
}

function flipCoin() {
    const isHeads = Math.random() < 0.5;
    const box = document.getElementById('quickRandomOutput');
    if (box) box.textContent = isHeads ? '🪙 Münzwurf: KOPF (Zahlungsmünze)' : '🪙 Münzwurf: ZAHL (Adler/Wappen)';
    playTickSound(780, 0.06);
}

function drawLotto() {
    const pool = Array.from({ length: 49 }, (_, i) => i + 1);
    for (let i = pool.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    const six = pool.slice(0, 6).sort((a, b) => a - b);
    const superNum = Math.floor(Math.random() * 10);
    const box = document.getElementById('quickRandomOutput');
    if (box) box.textContent = `🍀 6 aus 49: ${six.join(' – ')}  (Superzahl: ${superNum})`;
}

// ============================================================================
// 3. ⏱️ STOPPUHR, COUNTDOWN & WELTZEITUHR
// ============================================================================
let swRunning = false;
let swStartPerf = 0;
let swElapsedMs = 0;
let swRafId = null;
let swLaps = [];

function formatSwTime(ms) {
    const totalCs = Math.floor(ms / 10);
    const cs  = totalCs % 100;
    const sec = Math.floor(totalCs / 100) % 60;
    const min = Math.floor(totalCs / 6000) % 60;
    const hr  = Math.floor(totalCs / 360000);
    return `${String(hr).padStart(2,'0')}:${String(min).padStart(2,'0')}:${String(sec).padStart(2,'0')}.${String(cs).padStart(2,'0')}`;
}

function toggleStopwatch() {
    const btn = document.getElementById('swStartBtn');
    if (!swRunning) {
        swRunning = true;
        swStartPerf = performance.now() - swElapsedMs;
        if (btn) { btn.textContent = '⏸️ Pause'; btn.className = 'px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-amber-600 hover:bg-amber-700 text-white cursor-pointer'; }
        const tick = () => {
            if (!swRunning) return;
            swElapsedMs = performance.now() - swStartPerf;
            document.getElementById('swDisplay').textContent = formatSwTime(swElapsedMs);
            swRafId = requestAnimationFrame(tick);
        };
        swRafId = requestAnimationFrame(tick);
    } else {
        swRunning = false;
        if (swRafId) cancelAnimationFrame(swRafId);
        if (btn) { btn.textContent = '▶️ Weiter'; btn.className = 'px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer'; }
    }
}

function lapStopwatch() {
    if (swElapsedMs <= 0) return;
    const prevTotal = swLaps.length > 0 ? swLaps[swLaps.length - 1].totalMs : 0;
    const splitMs   = swElapsedMs - prevTotal;
    swLaps.push({ nr: swLaps.length + 1, splitMs, totalMs: swElapsedMs });
    renderStopwatchLaps();
}

function resetStopwatch() {
    swRunning = false;
    if (swRafId) cancelAnimationFrame(swRafId);
    swElapsedMs = 0;
    swLaps = [];
    document.getElementById('swDisplay').textContent = '00:00:00.00';
    const btn = document.getElementById('swStartBtn');
    if (btn) { btn.textContent = '▶️ Start'; btn.className = 'px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer'; }
    renderStopwatchLaps();
}

function renderStopwatchLaps() {
    const box = document.getElementById('swLapsContainer');
    if (!box) return;
    if (swLaps.length === 0) {
        box.innerHTML = '<div class="text-center text-slate-400 py-3 font-sans">Noch keine Rundenzeiten gestoppt.</div>';
        return;
    }
    box.innerHTML = swLaps.slice().reverse().map(l => `
        <div class="flex items-center justify-between px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200/70 dark:border-slate-700">
            <span class="font-bold text-brand-600 dark:text-brand-400">Runde #${l.nr}</span>
            <span>+${formatSwTime(l.splitMs)}</span>
            <span class="text-slate-500">Gesamt: ${formatSwTime(l.totalMs)}</span>
        </div>
    `).join('');
}

async function saveStopwatchLapsNote() {
    if (swLaps.length === 0) {
        showTbToast('Stoppe zuerst mindestens eine Runde!');
        return;
    }
    const lines = swLaps.map(l => `Runde #${l.nr}: +${formatSwTime(l.splitMs)} (Gesamt: ${formatSwTime(l.totalMs)})`);
    const fd = new FormData();
    fd.append('csrf_token', TB_CSRF_TOKEN);
    fd.append('ajax_action', 'save_tool_note');
    fd.append('title', 'Stoppuhr-Rundenzeiten');
    fd.append('content', lines.join('\n'));
    fd.append('color', 'amber');
    const r = await fetch('toolbox.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) showTbToast(d.message, d.url);
}

// Countdown-Timer
let cdRemainingSec = 300;
let cdRunning = false;
let cdInterval = null;

function formatCdTime(sec) {
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return h > 0
        ? `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
        : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function setCountdownPreset(seconds, label) {
    if (cdInterval) clearInterval(cdInterval);
    cdRunning = false;
    cdRemainingSec = seconds;
    document.getElementById('cdHours').value   = Math.floor(seconds / 3600);
    document.getElementById('cdMinutes').value = Math.floor((seconds % 3600) / 60);
    document.getElementById('cdSeconds').value = seconds % 60;
    document.getElementById('cdDisplay').textContent = formatCdTime(cdRemainingSec);
    document.getElementById('cdStartBtn').textContent = '▶️ Timer starten';
    showTbToast(`Timer gesetzt: ${label}`);
}

function syncCustomCountdown() {
    if (cdRunning) return;
    const h = parseInt(document.getElementById('cdHours').value || '0', 10);
    const m = parseInt(document.getElementById('cdMinutes').value || '0', 10);
    const s = parseInt(document.getElementById('cdSeconds').value || '0', 10);
    cdRemainingSec = Math.max(1, h * 3600 + m * 60 + s);
    document.getElementById('cdDisplay').textContent = formatCdTime(cdRemainingSec);
}

function toggleCountdown() {
    const btn = document.getElementById('cdStartBtn');
    if (!cdRunning) {
        if (cdRemainingSec <= 0) syncCustomCountdown();
        cdRunning = true;
        if (btn) btn.textContent = '⏸️ Pause';
        cdInterval = setInterval(() => {
            cdRemainingSec--;
            if (cdRemainingSec <= 0) {
                cdRemainingSec = 0;
                clearInterval(cdInterval);
                cdRunning = false;
                if (btn) btn.textContent = '▶️ Timer starten';
                document.getElementById('cdDisplay').textContent = '00:00 🔔';
                playTickSound(880, 0.25);
                setTimeout(() => playTickSound(1046, 0.35), 280);
                showTbToast('🔔 Timer abgelaufen!');
            } else {
                document.getElementById('cdDisplay').textContent = formatCdTime(cdRemainingSec);
            }
        }, 1000);
    } else {
        cdRunning = false;
        clearInterval(cdInterval);
        if (btn) btn.textContent = '▶️ Weiter';
    }
}

function resetCountdown() {
    cdRunning = false;
    if (cdInterval) clearInterval(cdInterval);
    syncCustomCountdown();
    document.getElementById('cdStartBtn').textContent = '▶️ Timer starten';
}

// Weltzeituhr
const WORLD_ZONES = [
    { city: 'Berlin / Wien', tz: 'Europe/Berlin', flag: '🇩🇪' },
    { city: 'London',        tz: 'Europe/London', flag: '🇬🇧' },
    { city: 'UTC / GMT',     tz: 'UTC',           flag: '🌐' },
    { city: 'New York',      tz: 'America/New_York', flag: '🇺🇸' },
    { city: 'Los Angeles',   tz: 'America/Los_Angeles', flag: '🌴' },
    { city: 'São Paulo',     tz: 'America/Sao_Paulo', flag: '🇧🇷' },
    { city: 'Dubai',         tz: 'Asia/Dubai',    flag: '🇦🇪' },
    { city: 'Tokio',         tz: 'Asia/Tokyo',    flag: '🇯🇵' }
];

function resetWorldClockOffset() {
    const slider = document.getElementById('worldClockSlider');
    if (slider) slider.value = 0;
    updateWorldClocks();
}

function updateWorldClocks() {
    const grid = document.getElementById('worldClockGrid');
    if (!grid) return;
    const offsetMin = parseInt(document.getElementById('worldClockSlider')?.value || '0', 10);
    const lbl = document.getElementById('worldClockOffsetLabel');
    if (lbl) {
        if (offsetMin === 0) {
            lbl.textContent = 'Live-Uhrzeit (Jetzt)';
        } else {
            const sign = offsetMin > 0 ? '+' : '';
            const hrs  = (offsetMin / 60).toFixed(1).replace('.0', '');
            lbl.textContent = `Verschoben: ${sign}${hrs} Std.`;
        }
    }
    const refDate = new Date(Date.now() + offsetMin * 60000);
    grid.innerHTML = WORLD_ZONES.map(z => {
        const timeStr = new Intl.DateTimeFormat('de-DE', {
            timeZone: z.tz, hour: '2-digit', minute: '2-digit'
        }).format(refDate);
        const dateStr = new Intl.DateTimeFormat('de-DE', {
            timeZone: z.tz, weekday: 'short', day: '2-digit', month: '2-digit'
        }).format(refDate);
        return `
            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-center">
                <div class="text-xs font-bold text-slate-600 dark:text-slate-300 truncate">${z.flag} ${z.city}</div>
                <div class="text-lg sm:text-xl font-extrabold font-mono text-slate-900 dark:text-white mt-1">${timeStr}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">${dateStr}</div>
            </div>
        `;
    }).join('');
}

// ============================================================================
// 4. 🔍 TEXT-DIFF-CHECKER & TEXT-ANALYSE
// ============================================================================
function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function runTextDiff() {
    const aLines = (document.getElementById('diffTextA')?.value || '').split('\n');
    const bLines = (document.getElementById('diffTextB')?.value || '').split('\n');
    const outBox = document.getElementById('diffOutputBox');
    if (!outBox) return;

    // LCS Zeilen-Vergleich
    const m = aLines.length, n = bLines.length;
    const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
    for (let i = m - 1; i >= 0; i--) {
        for (let j = n - 1; j >= 0; j--) {
            dp[i][j] = (aLines[i] === bLines[j]) ? (1 + dp[i + 1][j + 1]) : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }

    const html = [];
    let i = 0, j = 0;
    while (i < m && j < n) {
        if (aLines[i] === bLines[j]) {
            html.push(`<div class="px-2.5 py-1 rounded text-slate-600 dark:text-slate-300">  ${escapeHtml(aLines[i])}</div>`);
            i++; j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            html.push(`<div class="px-2.5 py-1 rounded diff-del">- ${escapeHtml(aLines[i])}</div>`);
            i++;
        } else {
            html.push(`<div class="px-2.5 py-1 rounded diff-add">+ ${escapeHtml(bLines[j])}</div>`);
            j++;
        }
    }
    while (i < m) {
        html.push(`<div class="px-2.5 py-1 rounded diff-del">- ${escapeHtml(aLines[i++])}</div>`);
    }
    while (j < n) {
        html.push(`<div class="px-2.5 py-1 rounded diff-add">+ ${escapeHtml(bLines[j++])}</div>`);
    }

    outBox.innerHTML = html.join('');
}

function swapDiffTexts() {
    const a = document.getElementById('diffTextA');
    const b = document.getElementById('diffTextB');
    if (!a || !b) return;
    const tmp = a.value;
    a.value = b.value;
    b.value = tmp;
    runTextDiff();
}

function updateTextStats() {
    const txt = document.getElementById('textWorkshopInput')?.value || '';
    const trimmed = txt.trim();
    const words = trimmed === '' ? 0 : trimmed.split(/\s+/).length;
    const chars = txt.length;
    const charsNoSpace = txt.replace(/\s+/g, '').length;
    const lines = txt === '' ? 0 : txt.split('\n').length;
    const readSec  = Math.ceil((words / 200) * 60);
    const speakSec = Math.ceil((words / 130) * 60);

    document.getElementById('statWords').textContent = words;
    document.getElementById('statChars').textContent = chars;
    document.getElementById('statCharsNoSpace').textContent = charsNoSpace;
    document.getElementById('statLines').textContent = lines;
    document.getElementById('statReadTime').textContent = readSec >= 60 ? `${Math.ceil(readSec / 60)} Min.` : `${readSec} Sek.`;
    document.getElementById('statSpeakTime').textContent = speakSec >= 60 ? `${Math.ceil(speakSec / 60)} Min.` : `${speakSec} Sek.`;
}

function transformWorkshopText(action) {
    const el = document.getElementById('textWorkshopInput');
    if (!el) return;
    let lines = el.value.split('\n');
    if (action === 'sort_asc') lines.sort((a, b) => a.localeCompare(b, 'de'));
    if (action === 'sort_desc') lines.sort((a, b) => b.localeCompare(a, 'de'));
    if (action === 'dedupe') lines = [...new Set(lines)];
    if (action === 'remove_empty') lines = lines.filter(l => l.trim() !== '');
    if (action === 'number_lines') lines = lines.map((l, idx) => `${idx + 1}. ${l}`);
    if (action === 'upper') { el.value = el.value.toUpperCase(); updateTextStats(); return; }
    if (action === 'lower') { el.value = el.value.toLowerCase(); updateTextStats(); return; }
    el.value = lines.join('\n');
    updateTextStats();
}

async function saveCleanTextToCloud() {
    const content = document.getElementById('textWorkshopInput')?.value || '';
    const fd = new FormData();
    fd.append('csrf_token', TB_CSRF_TOKEN);
    fd.append('ajax_action', 'save_tool_file');
    fd.append('title', 'Text_Werkstatt');
    fd.append('content', content);
    const r = await fetch('toolbox.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) showTbToast(d.message, d.url);
    else showTbToast('⚠️ ' + (d.error || 'Fehler'));
}

async function saveCleanTextToNote() {
    const content = document.getElementById('textWorkshopInput')?.value || '';
    const fd = new FormData();
    fd.append('csrf_token', TB_CSRF_TOKEN);
    fd.append('ajax_action', 'save_tool_note');
    fd.append('title', 'Text-Werkstatt Notiz');
    fd.append('content', content);
    fd.append('color', 'sky');
    const r = await fetch('toolbox.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) showTbToast(d.message, d.url);
    else showTbToast('⚠️ ' + (d.error || 'Fehler'));
}

// ============================================================================
// 5. 🎨 FARB-STUDIO & WCAG-KONTRAST-PRÜFER
// ============================================================================
function hexToRgb(hex) {
    const clean = hex.replace('#', '').trim();
    if (!/^[0-9a-fA-F]{6}$/.test(clean)) return null;
    return {
        r: parseInt(clean.slice(0, 2), 16),
        g: parseInt(clean.slice(2, 4), 16),
        b: parseInt(clean.slice(4, 6), 16)
    };
}

function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    let h = 0, s = 0, l = (max + min) / 2;
    if (max !== min) {
        const d = max - min;
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break;
            case g: h = (b - r) / d + 2; break;
            case b: h = (r - g) / d + 4; break;
        }
        h /= 6;
    }
    return { h: Math.round(h * 360), s: Math.round(s * 100), l: Math.round(l * 100) };
}

function hslToHex(h, s, l) {
    h = ((h % 360) + 360) % 360;
    s /= 100; l /= 100;
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    let r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; }
    else if (h < 120) { r = x; g = c; }
    else if (h < 180) { g = c; b = x; }
    else if (h < 240) { g = x; b = c; }
    else if (h < 300) { r = x; b = c; }
    else { r = c; b = x; }
    const toHex = v => Math.round((v + m) * 255).toString(16).padStart(2, '0').toUpperCase();
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

function onMainColorPick(hex) {
    const rgb = hexToRgb(hex);
    if (!rgb) return;
    document.getElementById('colorHexInput').value = hex.toUpperCase();
    document.getElementById('colorRgbOutput').value = `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;

    const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
    document.getElementById('colorHslOutput').value = `hsl(${hsl.h}, ${hsl.s}%, ${hsl.l}%)`;

    const k = 1 - Math.max(rgb.r / 255, rgb.g / 255, rgb.b / 255);
    const c = k === 1 ? 0 : Math.round(((1 - rgb.r / 255 - k) / (1 - k)) * 100);
    const m = k === 1 ? 0 : Math.round(((1 - rgb.g / 255 - k) / (1 - k)) * 100);
    const y = k === 1 ? 0 : Math.round(((1 - rgb.b / 255 - k) / (1 - k)) * 100);
    document.getElementById('colorCmykOutput').value = `cmyk(${c}%, ${m}%, ${y}%, ${Math.round(k * 100)}%)`;

    const harmonies = [
        { name: 'Original',      hex: hex.toUpperCase() },
        { name: 'Komplementär',  hex: hslToHex(hsl.h + 180, hsl.s, hsl.l) },
        { name: 'Analog -30°',   hex: hslToHex(hsl.h - 30, hsl.s, hsl.l) },
        { name: 'Analog +30°',   hex: hslToHex(hsl.h + 30, hsl.s, hsl.l) },
        { name: 'Triade 1',      hex: hslToHex(hsl.h + 120, hsl.s, hsl.l) },
        { name: 'Triade 2',      hex: hslToHex(hsl.h + 240, hsl.s, hsl.l) }
    ];
    const box = document.getElementById('colorHarmonySwatches');
    if (box) {
        box.innerHTML = harmonies.map(h => `
            <button type="button" onclick="navigator.clipboard.writeText('${h.hex}'); showTbToast('${h.hex} kopiert!');"
                    class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 text-center cursor-pointer hover:scale-105 transition-transform">
                <div class="w-full h-10 rounded-lg mb-1 border border-black/10" style="background-color:${h.hex};"></div>
                <div class="text-[10px] font-bold text-slate-600 dark:text-slate-300 truncate">${h.name}</div>
                <div class="text-[10px] font-mono text-slate-400">${h.hex}</div>
            </button>
        `).join('');
    }
}

function onHexTextInput(val) {
    const clean = val.startsWith('#') ? val : ('#' + val);
    if (/^#[0-9a-fA-F]{6}$/.test(clean)) {
        document.getElementById('colorPickerMain').value = clean;
        onMainColorPick(clean);
    }
}

function luminance(rgb) {
    const a = [rgb.r, rgb.g, rgb.b].map(v => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;
}

function updateWcagContrast() {
    const fgHex = document.getElementById('wcagFgColor').value;
    const bgHex = document.getElementById('wcagBgColor').value;
    document.getElementById('wcagFgHex').value = fgHex.toUpperCase();
    document.getElementById('wcagBgHex').value = bgHex.toUpperCase();

    const card = document.getElementById('wcagPreviewCard');
    if (card) {
        card.style.color = fgHex;
        card.style.backgroundColor = bgHex;
    }

    const rgb1 = hexToRgb(fgHex), rgb2 = hexToRgb(bgHex);
    if (!rgb1 || !rgb2) return;
    const l1 = luminance(rgb1), l2 = luminance(rgb2);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    document.getElementById('wcagRatioText').textContent = `${ratio.toFixed(2)} : 1`;

    const bNorm  = document.getElementById('wcagBadgeNormal');
    const bLarge = document.getElementById('wcagBadgeLarge');
    if (bNorm) {
        const level = ratio >= 7 ? 'AAA ✓' : (ratio >= 4.5 ? 'AA ✓' : 'Zu gering ✗');
        bNorm.textContent = `Normaltext: ${level}`;
        bNorm.className = ratio >= 4.5
            ? 'px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800'
            : 'px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800';
    }
    if (bLarge) {
        const level = ratio >= 4.5 ? 'AAA ✓' : (ratio >= 3 ? 'AA ✓' : 'Zu gering ✗');
        bLarge.textContent = `Großtext: ${level}`;
        bLarge.className = ratio >= 3
            ? 'px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800'
            : 'px-2.5 py-1 rounded-full text-xs font-bold bg-rose-100 text-rose-800';
    }
}

// ============================================================================
// 6. 🔐 GENERATOR- & KODIERUNGS-WERKBANK
// ============================================================================
const DE_WORDS = [
    'Adler','Ahorn','Alpen','Anker','Apfel','Bernstein','Biene','Birke','Blitz','Blume',
    'Brise','Burg','Donner','Drache','Eiche','Falke','Feder','Felsen','Feuer','Flamme',
    'Fluss','Freude','Fuchs','Garten','Gipfel','Glanz','Glocke','Hafen','Heide','Herbst',
    'Horizont','Insel','Jade','Kaffee','Komet','Kompass','Koralle','Kristall','Krone','Küste',
    'Laterne','Lawine','Leuchtturm','Libelle','Linde','Löwe','Mond','Muschel','Nebel','Nordwind',
    'Ozean','Perle','Phönix','Planet','Quelle','Regenbogen','Ritter','Rubin','Saphir','Schloss',
    'Schnee','Segel','Silber','Sommer','Sonne','Stern','Sturm','Tanne','Tiger','Vulkan',
    'Wald','Wasser','Welle','Wiese','Wolke','Zauber','Zeder','Zeppelin','Zitrone','Zukunft'
];

function cryptoRandInt(max) {
    const arr = new Uint32Array(1);
    window.crypto.getRandomValues(arr);
    return arr[0] % max;
}

function generateSecret(mode) {
    const out = document.getElementById('secretGeneratorOutput');
    const info = document.getElementById('secretEntropyInfo');
    if (!out) return;

    if (mode === 'passphrase') {
        const words = [];
        for (let i = 0; i < 4; i++) words.push(DE_WORDS[cryptoRandInt(DE_WORDS.length)]);
        const num = 10 + cryptoRandInt(90);
        const sym = ['!', '?', '#', '+', '*', '@'][cryptoRandInt(6)];
        out.value = `${words.join('-')}-${num}${sym}`;
        if (info) info.textContent = '🛡️ Leicht merkbar & stark: 4 Zufallswörter + Zahl + Sonderzeichen';
    } else if (mode === 'password') {
        const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*+-=?';
        let pw = '';
        for (let i = 0; i < 20; i++) pw += chars[cryptoRandInt(chars.length)];
        out.value = pw;
        if (info) info.textContent = '🛡️ 20 Zeichen kryptografisches Zufallspasswort (~122 Bit Entropie)';
    } else if (mode === 'pin') {
        let pin = '';
        for (let i = 0; i < 6; i++) pin += String(cryptoRandInt(10));
        out.value = pin;
        if (info) info.textContent = '🔢 6-stellige Zufalls-PIN';
    } else if (mode === 'uuid') {
        out.value = window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = cryptoRandInt(16);
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
        if (info) info.textContent = '🆔 Standardisierte UUID v4 (RFC 4122)';
    }
}

async function runCodec(action) {
    const inp = document.getElementById('codecInput')?.value || '';
    const out = document.getElementById('codecOutput');
    if (!out) return;
    try {
        if (action === 'b64_enc') {
            out.value = btoa(unescape(encodeURIComponent(inp)));
        } else if (action === 'b64_dec') {
            out.value = decodeURIComponent(escape(atob(inp.trim())));
        } else if (action === 'url_enc') {
            out.value = encodeURIComponent(inp);
        } else if (action === 'url_dec') {
            out.value = decodeURIComponent(inp);
        } else if (action === 'json_fmt') {
            out.value = JSON.stringify(JSON.parse(inp), null, 2);
        } else if (action === 'sha256') {
            const buf = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(inp));
            out.value = Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
        }
    } catch (e) {
        out.value = '⚠️ Fehler bei der Umwandlung: ' + e.message;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderQrStudio();
    drawWheel();
    updateWorldClocks();
    runTextDiff();
    updateTextStats();
    onMainColorPick('#2563eb');
    updateWcagContrast();
    generateSecret('passphrase');
    setInterval(() => {
        const panelTime = document.getElementById('tbPanel-time');
        if (panelTime && !panelTime.classList.contains('hidden')) updateWorldClocks();
    }, 15000);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
