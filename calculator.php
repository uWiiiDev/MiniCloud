<?php
/**
 * uWiiiMiniCloud – Taschenrechner & Rechen-Studio (calculator.php)
 *
 * Funktionen:
 *  1. Standard- & Wissenschaftlicher Taschenrechner (inkl. Tastatur-/Numpad-Steuerung & Speicher M+/M-/MR/MC)
 *  2. Interaktiver Rechenstreifen (Bon-/Tischrechner) mit Beschriftung je Zeile, Gesamtsumme (Σ) & 1-Klick Cloud-Export
 *     als .txt/.csv in den Ordner „Rechnungen/“ oder direkt als Haftnotiz auf die Notizwand (notes.php)
 *  3. Prozent-, MwSt.- (19 % / 7 % / frei), Rabatt- & Trinkgeld-/Gruppen-Rechner
 *  4. Einheiten-Umrechner (Länge, Gewicht, Fläche, Volumen, Temperatur, Datenspeicher, Geschwindigkeit)
 *     sowie Zinseszins- & Sparplan-Rechner
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo    = getDB();
$userId = currentUserId();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_calculator'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Taschenrechner“ ist durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

recordModuleUsage($pdo, 'calculator');

// ============================================================================
// AJAX-Endpunkte: Rechenstreifen in die Cloud (Rechnungen/) oder Notizwand speichern
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
    $title      = trim((string)($_POST['title'] ?? 'Rechenstreifen vom ' . date('d.m.Y H:i')));
    if ($title === '') {
        $title = 'Rechenstreifen vom ' . date('d.m.Y H:i');
    }
    $itemsRaw   = (string)($_POST['items_json'] ?? '[]');
    $items      = json_decode($itemsRaw, true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['ok' => false, 'error' => 'Der Rechenstreifen ist noch leer.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. Als Haftnotiz auf der Notizwand (notes.php) speichern
    if ($ajaxAction === 'save_tape_note') {
        $lines = [];
        $sum   = 0.0;
        foreach ($items as $idx => $it) {
            $expr    = trim((string)($it['expr'] ?? ''));
            $res     = (float)($it['result'] ?? 0);
            $resFmt  = trim((string)($it['resultFormatted'] ?? number_format($res, 2, ',', '.')));
            $label   = trim((string)($it['label'] ?? ''));
            $include = !isset($it['included']) || (bool)$it['included'];
            if ($include) {
                $sum += $res;
            }
            $prefix = ($idx + 1) . '. ';
            if ($label !== '') {
                $lines[] = $prefix . $label . ': ' . $expr . ' = ' . $resFmt . ($include ? '' : ' (nicht in Σ)');
            } else {
                $lines[] = $prefix . $expr . ' = ' . $resFmt . ($include ? '' : ' (nicht in Σ)');
            }
        }
        $lines[] = '────────────────────────────';
        $lines[] = 'Gesamtsumme (Σ): ' . number_format($sum, 2, ',', '.');
        $lines[] = '#rechenstreifen #taschenrechner';

        $content = implode("\n", $lines);

        $stmtNote = $pdo->prepare(
            'INSERT INTO notes (user_id, title, content, color, is_pinned)
             VALUES (:uid, :t, :c, :col, 0)'
        );
        $stmtNote->execute([
            ':uid' => $userId,
            ':t'   => mb_substr('🧮 ' . $title, 0, 140),
            ':c'   => mb_substr($content, 0, 10000),
            ':col' => 'amber',
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Rechenstreifen wurde als Haftnotiz auf deiner Notizwand gespeichert!',
            'url'     => 'notes.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Als .txt oder .csv Datei im Cloud-Ordner „Rechnungen“ speichern
    if ($ajaxAction === 'save_tape_file') {
        $format = strtolower((string)($_POST['format'] ?? 'txt')) === 'csv' ? 'csv' : 'txt';
        $safeTitle = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $title) ?: 'Rechenstreifen';
        $origName  = mb_substr($safeTitle, 0, 80) . '_' . date('Y-m-d_Hi') . '.' . $format;

        if ($format === 'csv') {
            $mem = fopen('php://temp', 'r+b');
            fwrite($mem, "\xEF\xBB\xBF");
            fputcsv($mem, ['Pos.', 'Bezeichnung / Notiz', 'Rechenweg', 'Ergebnis', 'In Summe enthalten'], ';');
            $sum = 0.0;
            foreach ($items as $idx => $it) {
                $expr    = trim((string)($it['expr'] ?? ''));
                $res     = (float)($it['result'] ?? 0);
                $label   = trim((string)($it['label'] ?? ''));
                $include = !isset($it['included']) || (bool)$it['included'];
                if ($include) {
                    $sum += $res;
                }
                fputcsv($mem, [
                    $idx + 1,
                    $label !== '' ? $label : ('Position ' . ($idx + 1)),
                    $expr,
                    number_format($res, 2, ',', ''),
                    $include ? 'Ja' : 'Nein',
                ], ';');
            }
            fputcsv($mem, ['', 'GESAMTSUMME (Σ)', '', number_format($sum, 2, ',', ''), ''], ';');
            rewind($mem);
            $fileContent = (string)stream_get_contents($mem);
            fclose($mem);
            $mimeType = 'text/csv';
        } else {
            $out   = [];
            $out[] = '============================================================';
            $out[] = '🧮 ' . $title;
            $out[] = 'Erstellt am: ' . date('d.m.Y H:i:s');
            $out[] = '============================================================';
            $out[] = '';
            $sum = 0.0;
            foreach ($items as $idx => $it) {
                $expr    = trim((string)($it['expr'] ?? ''));
                $res     = (float)($it['result'] ?? 0);
                $resFmt  = trim((string)($it['resultFormatted'] ?? number_format($res, 2, ',', '.')));
                $label   = trim((string)($it['label'] ?? ''));
                $include = !isset($it['included']) || (bool)$it['included'];
                if ($include) {
                    $sum += $res;
                }
                $lineLabel = $label !== '' ? $label : ('Position ' . ($idx + 1));
                $out[] = sprintf(
                    "%2d. %-26s | %18s = %14s%s",
                    $idx + 1,
                    mb_substr($lineLabel, 0, 26),
                    $expr,
                    $resFmt,
                    $include ? '' : ' (nicht in Σ)'
                );
            }
            $out[] = '------------------------------------------------------------';
            $out[] = sprintf("GESAMTSUMME (Σ): %47s", number_format($sum, 2, ',', '.'));
            $out[] = '============================================================';
            $fileContent = implode("\r\n", $out) . "\r\n";
            $mimeType    = 'text/plain';
        }

        $fileSize = strlen($fileContent);
        $quota    = getUserQuotaInfo($pdo, $userId);
        if (($quota['used_bytes'] + $fileSize) > $quota['quota_bytes']) {
            echo json_encode(['ok' => false, 'error' => 'Speicherplatz-Kontingent erschöpft.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0750, true);
        }
        $storedName = bin2hex(random_bytes(16)) . '.bin';
        file_put_contents(UPLOAD_DIR . $storedName, $fileContent);

        $folderName = 'Rechnungen';
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
            ':mt'  => $mimeType,
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Datei „' . $origName . '“ wurde im Cloud-Ordner „Rechnungen“ gespeichert!',
            'url'     => 'index.php?folder=Rechnungen',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'Taschenrechner & Rechen-Studio';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .calc-btn {
        user-select: none;
        transition: transform 0.06s ease, background-color 0.12s ease, box-shadow 0.12s ease;
    }
    .calc-btn:active, .calc-btn.key-pressed {
        transform: scale(0.96);
    }
    .calc-tape-scroll::-webkit-scrollbar {
        width: 6px;
    }
    .calc-tape-scroll::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.45);
        border-radius: 9999px;
    }
</style>

<!-- Kopfbereich -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-2xl bg-brand-600 text-white text-xl shadow-xs">
                🧮
            </span>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                    Taschenrechner &amp; Rechen-Studio
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                    Standard- &amp; Wissenschaftsrechner mit beschreibbarem Rechenstreifen (Bon-Summe &Sigma;), MwSt.-/Prozent-Rechner, Einheiten-Umrechner &amp; Zinsrechner
                </p>
            </div>
        </div>
    </div>

    <!-- Modus-Tabs -->
    <div class="flex flex-wrap items-center gap-1.5 bg-white dark:bg-slate-900 p-1.5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
        <button type="button" onclick="switchCalcTab('main')" id="calc-tab-btn-main"
            class="calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer bg-brand-600 text-white shadow-xs">
            🔢 Rechner &amp; Streifen
        </button>
        <button type="button" onclick="switchCalcTab('percent')" id="calc-tab-btn-percent"
            class="calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
            💶 MwSt., % &amp; Rabatt
        </button>
        <button type="button" onclick="switchCalcTab('units')" id="calc-tab-btn-units"
            class="calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
            📐 Einheiten-Umrechner
        </button>
        <button type="button" onclick="switchCalcTab('finance')" id="calc-tab-btn-finance"
            class="calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
            📈 Zins &amp; Kredit
        </button>
    </div>
</div>

<!-- Status-Toast für Cloud-Export -->
<div id="calc-toast" class="hidden mb-5 p-3.5 rounded-xl border border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/60 text-emerald-900 dark:text-emerald-200 text-xs sm:text-sm flex items-center justify-between gap-3 shadow-xs">
    <div class="flex items-center gap-2">
        <span>✅</span>
        <span id="calc-toast-msg" class="font-semibold">Gespeichert!</span>
    </div>
    <a id="calc-toast-link" href="#" class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-colors shrink-0">
        Öffnen &rarr;
    </a>
</div>

<!-- ======================================================================= -->
<!-- TAB 1: STANDARD- & WISSENSCHAFTLICHER RECHNER + RECHENSTREIFEN          -->
<!-- ======================================================================= -->
<div id="calc-panel-main" class="calc-tab-panel grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- LINKE SPALTE: Taschenrechner (7 Spalten) -->
    <div class="lg:col-span-7 flex flex-col gap-4">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 shadow-xs">

            <!-- Obere Steuerleiste: Standard / Wissenschaftlich, DEG/RAD, Speicher-Badge -->
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div class="inline-flex items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl">
                    <button type="button" id="btn-mode-std" onclick="setSciMode(false)"
                        class="px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs">
                        Standard
                    </button>
                    <button type="button" id="btn-mode-sci" onclick="setSciMode(true)"
                        class="px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-400">
                        🔬 Wissenschaftlich
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Winkelmaß DEG / RAD -->
                    <button type="button" id="btn-angle-unit" onclick="toggleAngleUnit()"
                        class="hidden px-2.5 py-1 rounded-lg text-[11px] font-bold bg-indigo-50 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 cursor-pointer"
                        title="Winkelmaß zwischen Grad (DEG) und Bogenmaß (RAD) umschalten">
                        DEG (°)
                    </button>

                    <!-- Speicher-Anzeige -->
                    <span id="memory-badge" class="hidden px-2.5 py-1 rounded-lg text-[11px] font-mono font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800">
                        M = 0
                    </span>

                    <!-- Ergebnis kopieren -->
                    <button type="button" onclick="copyCalcResult()"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-colors cursor-pointer"
                        title="Aktuelles Ergebnis in die Zwischenablage kopieren">
                        📋 Kopieren
                    </button>
                </div>
            </div>

            <!-- Display / Anzeige -->
            <div class="rounded-2xl bg-slate-900 dark:bg-slate-950 text-white p-4 sm:p-5 mb-4 border border-slate-800 shadow-inner">
                <!-- Rechenweg / Formelzeile -->
                <div class="flex items-center justify-between gap-2 min-h-[1.5rem]">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-slate-400">Rechenweg</span>
                    <div id="calc-expr-display" class="text-xs sm:text-sm font-mono text-slate-300 text-right truncate max-w-[80%]">
                        &nbsp;
                    </div>
                </div>

                <!-- Haupt-Eingabe / Ergebnis -->
                <div class="mt-1 flex items-baseline justify-between gap-3">
                    <span id="calc-preview-hint" class="text-xs font-mono text-emerald-400 truncate"></span>
                    <input
                        type="text"
                        id="calc-input"
                        value="0"
                        autocomplete="off"
                        spellcheck="false"
                        aria-label="Rechenausdruck"
                        class="w-full bg-transparent text-right text-2xl sm:text-4xl font-extrabold font-mono tracking-tight text-white focus:outline-none"
                    >
                </div>

                <!-- Direkt-Beschriftung für den Rechenstreifen -->
                <div class="mt-3 pt-2.5 border-t border-slate-800 flex items-center gap-2">
                    <span class="text-xs text-slate-400 shrink-0">🏷️ Positions-Notiz:</span>
                    <input
                        type="text"
                        id="calc-line-label"
                        placeholder="Optional: z. B. Getränke, Miete, Material (vor = eingeben)..."
                        class="w-full bg-slate-800/80 text-slate-200 placeholder-slate-500 text-xs px-2.5 py-1.5 rounded-lg border border-slate-700 focus:outline-none focus:border-brand-500"
                    >
                </div>
            </div>

            <!-- Speicher-Tasten (MC, MR, M+, M-, Ans) -->
            <div class="grid grid-cols-5 gap-2 mb-3">
                <button type="button" onclick="calcMemory('MC')" class="calc-btn py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 cursor-pointer" title="Speicher löschen (Memory Clear)">MC</button>
                <button type="button" onclick="calcMemory('MR')" class="calc-btn py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 cursor-pointer" title="Speicherwert abrufen (Memory Recall)">MR</button>
                <button type="button" onclick="calcMemory('M+')" class="calc-btn py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 cursor-pointer" title="Zum Speicher addieren">M+</button>
                <button type="button" onclick="calcMemory('M-')" class="calc-btn py-2 rounded-xl text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 cursor-pointer" title="Vom Speicher abziehen">M&minus;</button>
                <button type="button" onclick="insertToken('Ans')" class="calc-btn py-2 rounded-xl text-xs font-bold bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 cursor-pointer" title="Letztes Ergebnis einsetzen">Ans</button>
            </div>

            <!-- Wissenschaftliche Zusatz-Tasten (ein-/ausblendbar) -->
            <div id="sci-keypad" class="hidden grid grid-cols-5 gap-2 mb-3 p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/70">
                <button type="button" onclick="insertFunc('sin(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">sin</button>
                <button type="button" onclick="insertFunc('cos(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">cos</button>
                <button type="button" onclick="insertFunc('tan(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">tan</button>
                <button type="button" onclick="insertFunc('sqrt(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">&radic;x</button>
                <button type="button" onclick="insertFunc('cbrt(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">&#8731;x</button>

                <button type="button" onclick="insertToken('^2')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">x&sup2;</button>
                <button type="button" onclick="insertToken('^3')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">x&sup3;</button>
                <button type="button" onclick="insertToken('^')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">x<sup>y</sup></button>
                <button type="button" onclick="insertFunc('log(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">log<sub>10</sub></button>
                <button type="button" onclick="insertFunc('ln(')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">ln</button>

                <button type="button" onclick="insertToken('π')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">&pi;</button>
                <button type="button" onclick="insertToken('e')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">e</button>
                <button type="button" onclick="applyUnary('inv')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">1/x</button>
                <button type="button" onclick="applyUnary('fact')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">n!</button>
                <button type="button" onclick="applyUnary('neg')" class="calc-btn py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer">&plusmn;</button>
            </div>

            <!-- Haupt-Tastenfeld (4 x 5 Grid) -->
            <div class="grid grid-cols-4 gap-2.5">
                <!-- Zeile 1 -->
                <button type="button" onclick="clearCalc()" data-key="Escape" class="calc-btn py-3.5 rounded-xl text-sm sm:text-base font-extrabold bg-rose-100 hover:bg-rose-200 dark:bg-rose-950/70 dark:hover:bg-rose-900 text-rose-700 dark:text-rose-300 cursor-pointer">AC</button>
                <button type="button" onclick="insertParens()" class="calc-btn py-3.5 rounded-xl text-sm sm:text-base font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer">( )</button>
                <button type="button" onclick="insertToken('%')" data-key="%" class="calc-btn py-3.5 rounded-xl text-sm sm:text-base font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer">%</button>
                <button type="button" onclick="backspaceCalc()" data-key="Backspace" class="calc-btn py-3.5 rounded-xl text-sm sm:text-base font-bold bg-amber-100 hover:bg-amber-200 dark:bg-amber-950/70 dark:hover:bg-amber-900 text-amber-800 dark:text-amber-300 cursor-pointer" title="Letztes Zeichen löschen (Backspace)">⌫</button>

                <!-- Zeile 2 -->
                <button type="button" onclick="insertToken('7')" data-key="7" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">7</button>
                <button type="button" onclick="insertToken('8')" data-key="8" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">8</button>
                <button type="button" onclick="insertToken('9')" data-key="9" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">9</button>
                <button type="button" onclick="insertOperator('÷')" data-key="/" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-extrabold bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/70 dark:hover:bg-brand-900 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800 cursor-pointer">&divide;</button>

                <!-- Zeile 3 -->
                <button type="button" onclick="insertToken('4')" data-key="4" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">4</button>
                <button type="button" onclick="insertToken('5')" data-key="5" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">5</button>
                <button type="button" onclick="insertToken('6')" data-key="6" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">6</button>
                <button type="button" onclick="insertOperator('×')" data-key="*" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-extrabold bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/70 dark:hover:bg-brand-900 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800 cursor-pointer">&times;</button>

                <!-- Zeile 4 -->
                <button type="button" onclick="insertToken('1')" data-key="1" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">1</button>
                <button type="button" onclick="insertToken('2')" data-key="2" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">2</button>
                <button type="button" onclick="insertToken('3')" data-key="3" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">3</button>
                <button type="button" onclick="insertOperator('-')" data-key="-" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-extrabold bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/70 dark:hover:bg-brand-900 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800 cursor-pointer">&minus;</button>

                <!-- Zeile 5 -->
                <button type="button" onclick="insertToken('0')" data-key="0" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">0</button>
                <button type="button" onclick="insertToken(',')" data-key="," class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-bold bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/90 dark:hover:bg-slate-700 text-slate-900 dark:text-white border border-slate-200/80 dark:border-slate-700 cursor-pointer">,</button>
                <button type="button" onclick="evaluateCalculation()" data-key="Enter" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-extrabold bg-brand-600 hover:bg-brand-700 text-white shadow-xs cursor-pointer">=</button>
                <button type="button" onclick="insertOperator('+')" data-key="+" class="calc-btn py-3.5 rounded-xl text-base sm:text-lg font-extrabold bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/70 dark:hover:bg-brand-900 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800 cursor-pointer">+</button>
            </div>

            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500 text-center">
                ⌨️ Volle Tastatur- &amp; Numpad-Unterstützung: Zahlen <code class="font-mono">0–9</code>, <code class="font-mono">+ - * / ( ) %</code>, <code class="font-mono">Enter</code> (=), <code class="font-mono">Backspace</code> (⌫) &amp; <code class="font-mono">Esc</code> (AC)
            </p>
        </div>
    </div>

    <!-- RECHTE SPALTE: Interaktiver Rechenstreifen / Bon-Rechner (5 Spalten) -->
    <div class="lg:col-span-5 flex flex-col">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 shadow-xs flex flex-col h-full justify-between">
            <div>
                <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-slate-200 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🧾</span>
                        <div>
                            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                                Rechenstreifen (Bon-Summe &Sigma;)
                            </h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                Klicke auf ein Ergebnis zum Weiterrechnen oder beschrifte einzelne Posten
                            </p>
                        </div>
                    </div>
                    <button type="button" onclick="clearTape()"
                        class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/60 transition-colors cursor-pointer"
                        title="Gesamten Rechenstreifen leeren">
                        🗑️ Leeren
                    </button>
                </div>

                <!-- Titel für den Export -->
                <div class="mb-3">
                    <input
                        type="text"
                        id="tape-title-input"
                        placeholder="Titel des Rechenstreifens (z. B. Abrechnung Vereinsfest)..."
                        class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <!-- Liste der Rechenschritte -->
                <div id="tape-list" class="calc-tape-scroll max-h-[320px] overflow-y-auto space-y-2 pr-1">
                    <!-- Wird per JS befüllt -->
                </div>
            </div>

            <!-- Summenzeile & Cloud-Export-Aktionen -->
            <div class="mt-4 pt-3 border-t-2 border-dashed border-slate-200 dark:border-slate-800 space-y-3">
                <div class="p-3 rounded-xl bg-amber-50/80 dark:bg-amber-950/40 border border-amber-200/80 dark:border-amber-900/60 flex items-center justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300 block">
                            Gesamtsumme (&Sigma;) aller aktiven Posten
                        </span>
                        <span id="tape-count-info" class="text-[11px] text-amber-700/80 dark:text-amber-400/80">
                            0 Positionen
                        </span>
                    </div>
                    <button type="button" onclick="useTapeSumInCalc()"
                        class="text-right group cursor-pointer"
                        title="Klicken, um die Gesamtsumme in den Rechner zu übernehmen">
                        <span id="tape-sum-display" class="text-lg sm:text-xl font-extrabold font-mono text-amber-900 dark:text-amber-200 group-hover:underline">
                            0,00
                        </span>
                    </button>
                </div>

                <!-- Cloud-Speicher-Buttons -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <button type="button" onclick="saveTapeToCloud('note')"
                        class="px-3 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-1.5">
                        📌 Als Haftnotiz
                    </button>
                    <button type="button" onclick="saveTapeToCloud('txt')"
                        class="px-3 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-1.5">
                        📄 Cloud (.txt)
                    </button>
                    <button type="button" onclick="saveTapeToCloud('csv')"
                        class="px-3 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-1.5">
                        📊 Tabelle (.csv)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- TAB 2: PROZENT-, MWST.-, RABATT- & GRUPPEN-/TRINKGELD-RECHNER           -->
<!-- ======================================================================= -->
<div id="calc-panel-percent" class="calc-tab-panel hidden grid grid-cols-1 md:grid-cols-3 gap-6">

    <!-- Karte 1: Mehrwertsteuer-Rechner (Netto <-> Brutto) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-200 dark:border-slate-800">
                <span class="text-xl">🧾</span>
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Mehrwertsteuer-Rechner</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Netto &harr; Brutto mit 19 %, 7 % oder freiem Satz</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Betrag (€)</label>
                <input type="number" step="0.01" id="vat-amount" value="100" oninput="calcVat()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Rechenrichtung</label>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="vat-dir-netto" onclick="setVatMode('netto_to_brutto')"
                        class="px-3 py-2 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer">
                        Netto &rarr; Brutto (+MwSt.)
                    </button>
                    <button type="button" id="vat-dir-brutto" onclick="setVatMode('brutto_to_netto')"
                        class="px-3 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">
                        Brutto &rarr; Netto (&minus;MwSt.)
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Steuersatz (%)</label>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="setVatRate(19)" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 cursor-pointer">19 %</button>
                    <button type="button" onclick="setVatRate(7)" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 cursor-pointer">7 %</button>
                    <input type="number" step="0.1" id="vat-rate" value="19" oninput="calcVat()"
                        class="w-full px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-900 dark:text-white">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700 space-y-2 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Nettobetrag:</span>
                <span id="vat-res-netto" class="font-mono font-bold text-slate-900 dark:text-white">100,00 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Mehrwertsteuer:</span>
                <span id="vat-res-tax" class="font-mono font-bold text-amber-600 dark:text-amber-400">19,00 €</span>
            </div>
            <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between text-sm">
                <span class="font-bold text-slate-800 dark:text-slate-200">Bruttobetrag:</span>
                <span id="vat-res-brutto" class="font-mono font-extrabold text-emerald-600 dark:text-emerald-400">119,00 €</span>
            </div>
            <button type="button" onclick="sendVatToTape()"
                class="mt-2 w-full py-1.5 rounded-lg bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 font-bold text-xs cursor-pointer">
                + Auf den Rechenstreifen übernehmen
            </button>
        </div>
    </div>

    <!-- Karte 2: Rabatt- & Prozent-Rechner -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-200 dark:border-slate-800">
                <span class="text-xl">🏷️</span>
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Rabatt- &amp; Prozent-Rechner</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Preisersparnis, Prozentanteil &amp; Aufschlag</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Grundwert / Ausgangspreis (€)</label>
                <input type="number" step="0.01" id="disc-price" value="250" oninput="calcDiscount()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Prozentsatz (%)</label>
                <div class="flex items-center gap-1.5 mb-2">
                    <button type="button" onclick="setDiscountPct(5)" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">5 %</button>
                    <button type="button" onclick="setDiscountPct(10)" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">10 %</button>
                    <button type="button" onclick="setDiscountPct(20)" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">20 %</button>
                    <button type="button" onclick="setDiscountPct(30)" class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">30 %</button>
                </div>
                <input type="number" step="0.1" id="disc-pct" value="15" oninput="calcDiscount()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
            </div>
        </div>

        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700 space-y-2 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Prozentwert / Ersparnis:</span>
                <span id="disc-res-part" class="font-mono font-bold text-amber-600 dark:text-amber-400">37,50 €</span>
            </div>
            <div class="flex justify-between text-sm pt-2 border-t border-slate-200 dark:border-slate-700">
                <span class="font-bold text-slate-800 dark:text-slate-200">Endpreis nach Rabatt (&minus;):</span>
                <span id="disc-res-minus" class="font-mono font-extrabold text-emerald-600 dark:text-emerald-400">212,50 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Wert inkl. Aufschlag (+):</span>
                <span id="disc-res-plus" class="font-mono font-bold text-slate-700 dark:text-slate-300">287,50 €</span>
            </div>
            <button type="button" onclick="sendDiscountToTape()"
                class="mt-2 w-full py-1.5 rounded-lg bg-brand-50 hover:bg-brand-100 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 font-bold text-xs cursor-pointer">
                + Auf den Rechenstreifen übernehmen
            </button>
        </div>
    </div>

    <!-- Karte 3: Trinkgeld- & Gruppen-Rechner (Rechnung teilen) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-200 dark:border-slate-800">
                <span class="text-xl">🍽️</span>
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Trinkgeld &amp; Rechnung teilen</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Perfekt für Restaurant, Ausflüge &amp; Verein</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Rechnungsbetrag (€)</label>
                <input type="number" step="0.01" id="split-bill" value="84.50" oninput="calcSplit()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Trinkgeld (%)</label>
                    <input type="number" step="1" min="0" id="split-tip" value="10" oninput="calcSplit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Personen</label>
                    <input type="number" step="1" min="1" id="split-people" value="4" oninput="calcSplit()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700 space-y-2 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Trinkgeld gesamt:</span>
                <span id="split-res-tip" class="font-mono font-bold text-amber-600 dark:text-amber-400">8,45 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Gesamtsumme inkl. Trinkgeld:</span>
                <span id="split-res-total" class="font-mono font-bold text-slate-900 dark:text-white">92,95 €</span>
            </div>
            <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between text-sm">
                <span class="font-bold text-slate-800 dark:text-slate-200">Pro Person:</span>
                <span id="split-res-person" class="font-mono font-extrabold text-emerald-600 dark:text-emerald-400">23,24 €</span>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- TAB 3: EINHEITEN-UMRECHNER                                              -->
<!-- ======================================================================= -->
<div id="calc-panel-units" class="calc-tab-panel hidden">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs max-w-3xl mx-auto space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="text-2xl">📐</span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Universal-Einheiten-Umrechner</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Länge, Gewicht, Fläche, Volumen, Temperatur, Datenspeicher &amp; Geschwindigkeit</p>
                </div>
            </div>

            <select id="unit-category" onchange="populateUnitSelects()"
                class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100 cursor-pointer">
                <option value="length">📏 Länge &amp; Entfernung</option>
                <option value="weight">⚖️ Gewicht &amp; Masse</option>
                <option value="area">🟩 Fläche (m², Hektar)</option>
                <option value="volume">🧪 Volumen &amp; Flüssigkeit</option>
                <option value="temp">🌡️ Temperatur (°C, °F, K)</option>
                <option value="data">💾 Datenspeicher (MB, GB, TB)</option>
                <option value="speed">🚀 Geschwindigkeit (km/h, m/s, kn)</option>
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-11 gap-4 items-center">
            <div class="sm:col-span-5 space-y-2">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Ausgangswert</label>
                <input type="number" step="any" id="unit-input" value="1" oninput="convertUnits()"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-lg font-mono font-bold text-slate-900 dark:text-white">
                <select id="unit-from" onchange="convertUnits()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200"></select>
            </div>

            <div class="sm:col-span-1 flex justify-center">
                <button type="button" onclick="swapUnits()"
                    class="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold cursor-pointer"
                    title="Einheiten tauschen">
                    ⇄
                </button>
            </div>

            <div class="sm:col-span-5 space-y-2">
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Umgerechnetes Ergebnis</label>
                <input type="text" id="unit-output" readonly
                    class="w-full px-3.5 py-2.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800 text-lg font-mono font-extrabold text-emerald-800 dark:text-emerald-300">
                <select id="unit-to" onchange="convertUnits()"
                    class="w-full px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200"></select>
            </div>
        </div>

        <!-- Übersicht aller Einheiten dieser Kategorie -->
        <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2.5">Alle Umrechnungen auf einen Blick</h3>
            <div id="unit-all-grid" class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 text-xs"></div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- TAB 4: ZINSESZINS-, SPARPLAN- & KREDIT-RECHNER                          -->
<!-- ======================================================================= -->
<div id="calc-panel-finance" class="calc-tab-panel hidden grid grid-cols-1 md:grid-cols-2 gap-6">

    <!-- Karte 1: Zinseszins & Sparplan -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-200 dark:border-slate-800">
                <span class="text-xl">📈</span>
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Zinseszins- &amp; Sparplan-Rechner</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Berechnet Endkapital, Einzahlungen und Zinsertrag</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Startkapital (€)</label>
                    <input type="number" step="100" id="sav-initial" value="5000" oninput="calcSavings()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Monatliche Sparrate (€)</label>
                    <input type="number" step="25" id="sav-monthly" value="150" oninput="calcSavings()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Zinssatz (% p. a.)</label>
                    <input type="number" step="0.1" id="sav-rate" value="3.5" oninput="calcSavings()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Anlagedauer (Jahre)</label>
                    <input type="number" step="1" min="1" max="60" id="sav-years" value="10" oninput="calcSavings()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700 space-y-2 text-xs">
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Eigene Einzahlungen gesamt:</span>
                <span id="sav-res-paid" class="font-mono font-bold text-slate-900 dark:text-white">23.000,00 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Erwirtschaftete Zinsen (+):</span>
                <span id="sav-res-interest" class="font-mono font-bold text-emerald-600 dark:text-emerald-400">5.676,34 €</span>
            </div>
            <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between text-sm">
                <span class="font-bold text-slate-800 dark:text-slate-200">Endkapital nach Laufzeit:</span>
                <span id="sav-res-total" class="font-mono font-extrabold text-brand-600 dark:text-brand-400">28.676,34 €</span>
            </div>
        </div>
    </div>

    <!-- Karte 2: Kredit- & Annuitäten-Rechner -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-200 dark:border-slate-800">
                <span class="text-xl">🏦</span>
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Kredit- &amp; Darlehens-Rechner</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Berechnet die monatliche Rate und Gesamtzinskosten</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Kreditsumme (€)</label>
                    <input type="number" step="500" id="loan-amount" value="25000" oninput="calcLoan()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Effektivzins (% p.a.)</label>
                    <input type="number" step="0.1" id="loan-rate" value="4.2" oninput="calcLoan()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Laufzeit (Jahre)</label>
                    <input type="number" step="1" min="1" max="40" id="loan-years" value="5" oninput="calcLoan()"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-sm font-mono font-bold text-slate-900 dark:text-white">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700 space-y-2 text-xs">
            <div class="flex justify-between text-sm pb-2 border-b border-slate-200 dark:border-slate-700">
                <span class="font-bold text-slate-800 dark:text-slate-200">Monatliche Rate:</span>
                <span id="loan-res-monthly" class="font-mono font-extrabold text-brand-600 dark:text-brand-400">462,68 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Gesamte Zinskosten:</span>
                <span id="loan-res-interest" class="font-mono font-bold text-rose-600 dark:text-rose-400">2.760,64 €</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500 dark:text-slate-400">Gesamtrückzahlung:</span>
                <span id="loan-res-total" class="font-mono font-bold text-slate-900 dark:text-white">27.760,64 €</span>
            </div>
        </div>
    </div>
</div>

<script>
const CALC_CSRF = <?= json_encode(getCsrfToken()) ?>;
const TAPE_STORAGE_KEY = 'mc_calc_tape_v1_' + <?= (int)$userId ?>;

let sciMode = false;
let angleUnit = 'DEG'; // 'DEG' oder 'RAD'
let memoryVal = 0;
let lastAns = 0;
let justEvaluated = false;
let tapeItems = [];
let vatDirection = 'netto_to_brutto';

const calcInput = document.getElementById('calc-input');
const calcExprDisplay = document.getElementById('calc-expr-display');
const calcPreviewHint = document.getElementById('calc-preview-hint');
const calcLineLabel = document.getElementById('calc-line-label');

// ============================================================================
// 1. TAB-UMSCHALTUNG
// ============================================================================
function switchCalcTab(tab) {
    document.querySelectorAll('.calc-tab-panel').forEach(el => el.classList.add('hidden'));
    const panel = document.getElementById('calc-panel-' + tab);
    if (panel) panel.classList.remove('hidden');

    document.querySelectorAll('.calc-tab-btn').forEach(btn => {
        btn.className = 'calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800';
    });
    const activeBtn = document.getElementById('calc-tab-btn-' + tab);
    if (activeBtn) {
        activeBtn.className = 'calc-tab-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-colors cursor-pointer bg-brand-600 text-white shadow-xs';
    }
}

function setSciMode(enable) {
    sciMode = !!enable;
    const sciPad = document.getElementById('sci-keypad');
    const angleBtn = document.getElementById('btn-angle-unit');
    const btnStd = document.getElementById('btn-mode-std');
    const btnSci = document.getElementById('btn-mode-sci');

    if (sciMode) {
        sciPad.classList.remove('hidden');
        angleBtn.classList.remove('hidden');
        btnSci.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs';
        btnStd.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-400';
    } else {
        sciPad.classList.add('hidden');
        angleBtn.classList.add('hidden');
        btnStd.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-xs';
        btnSci.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer text-slate-600 dark:text-slate-400';
    }
}

function toggleAngleUnit() {
    angleUnit = angleUnit === 'DEG' ? 'RAD' : 'DEG';
    document.getElementById('btn-angle-unit').textContent = angleUnit === 'DEG' ? 'DEG (°)' : 'RAD (π)';
    updateLivePreview();
}

// ============================================================================
// 2. SICHERER MATHEMATISCHER PARSER & AUSWERTER
// ============================================================================
function formatDeNumber(num, maxDecimals = 8) {
    if (!Number.isFinite(num)) return 'Fehler';
    if (Math.abs(num) > 0 && (Math.abs(num) >= 1e12 || Math.abs(num) < 1e-7)) {
        return num.toExponential(6).replace('.', ',');
    }
    const rounded = Number(Math.round(Number(num + 'e' + maxDecimals)) + 'e-' + maxDecimals);
    return rounded.toLocaleString('de-DE', { maximumFractionDigits: maxDecimals });
}

function factorial(n) {
    if (n < 0 || !Number.isInteger(n)) return NaN;
    if (n > 170) return Infinity;
    let r = 1;
    for (let i = 2; i <= n; i++) r *= i;
    return r;
}

function safeEvalExpression(rawExpr) {
    if (!rawExpr || typeof rawExpr !== 'string') return null;
    let s = rawExpr.trim();
    if (s === '') return null;

    // Deutsche Kommas in Dezimalpunkte umwandeln, Tausenderpunkte bei Bedarf bereinigen
    s = s.replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-');
    s = s.replace(/,/g, '.');
    s = s.replace(/\bAns\b/gi, '(' + String(lastAns) + ')');
    s = s.replace(/π/g, '(' + String(Math.PI) + ')');
    s = s.replace(/\bpi\b/gi, '(' + String(Math.PI) + ')');
    s = s.replace(/(?<![a-zA-Z])e(?![a-zA-Z0-9_])/g, '(' + String(Math.E) + ')');

    // Fakultät n!
    s = s.replace(/(\d+(?:\.\d+)?)\s*!/g, '__fact($1)');

    // Prozent-Behandlung: z. B. "100 + 19%" -> "100 + (100 * 19 / 100)" oder "50%" -> "(50/100)"
    s = s.replace(/(\d+(?:\.\d+)?)\s*([+\-])\s*(\d+(?:\.\d+)?)\s*%/g, '($1 $2 ($1 * $3 / 100))');
    s = s.replace(/(\d+(?:\.\d+)?)\s*%/g, '($1 / 100)');

    // Implizite Multiplikation: 2(3+4) -> 2*(3+4)
    s = s.replace(/(\d|\))\s*(?=\()/g, '$1*');

    // Potenz ^ -> **
    s = s.replace(/\^/g, '**');

    // Wissenschaftliche Funktionen ersetzen
    const trigFactor = angleUnit === 'DEG' ? (Math.PI / 180) : 1;
    const __sin = (x) => Math.sin(x * trigFactor);
    const __cos = (x) => Math.cos(x * trigFactor);
    const __tan = (x) => Math.tan(x * trigFactor);
    const __sqrt = (x) => Math.sqrt(x);
    const __cbrt = (x) => Math.cbrt(x);
    const __log = (x) => Math.log10(x);
    const __ln = (x) => Math.log(x);
    const __fact = (x) => factorial(x);

    s = s.replace(/\bsin\s*\(/gi, '__sin(')
         .replace(/\bcos\s*\(/gi, '__cos(')
         .replace(/\btan\s*\(/gi, '__tan(')
         .replace(/\bsqrt\s*\(/gi, '__sqrt(')
         .replace(/\bcbrt\s*\(/gi, '__cbrt(')
         .replace(/\blog\s*\(/gi, '__log(')
         .replace(/\bln\s*\(/gi, '__ln(');

    // Offene Klammern am Ende automatisch schließen
    const openCount = (s.match(/\(/g) || []).length;
    const closeCount = (s.match(/\)/g) || []).length;
    if (openCount > closeCount) {
        s += ')'.repeat(openCount - closeCount);
    }

    // Whitelist-Prüfung
    const cleanedCheck = s.replace(/__(sin|cos|tan|sqrt|cbrt|log|ln|fact)/g, '');
    if (/[^0-9+\-*/().\s]/.test(cleanedCheck)) {
        return null;
    }

    try {
        const fn = new Function('__sin', '__cos', '__tan', '__sqrt', '__cbrt', '__log', '__ln', '__fact', '"use strict"; return (' + s + ');');
        const val = fn(__sin, __cos, __tan, __sqrt, __cbrt, __log, __ln, __fact);
        if (typeof val === 'number' && Number.isFinite(val)) {
            return val;
        }
    } catch (e) {
        return null;
    }
    return null;
}

// ============================================================================
// 3. TASTEN-EINGABE & AUSWERTUNG
// ============================================================================
function insertToken(tok) {
    let cur = calcInput.value;
    if (justEvaluated) {
        if (/^[0-9,πe]$/.test(tok) || tok === 'Ans') {
            cur = '';
        }
        justEvaluated = false;
    }
    if (cur === '0' && /^[0-9πe]$/.test(tok)) {
        cur = '';
    }
    calcInput.value = cur + tok;
    updateLivePreview();
}

function insertOperator(op) {
    let cur = calcInput.value.trim();
    justEvaluated = false;
    if (cur === '' && op === '-') {
        calcInput.value = '-';
        updateLivePreview();
        return;
    }
    if (/[+\-×÷*/^]$/.test(cur)) {
        cur = cur.slice(0, -1);
    }
    calcInput.value = cur + op;
    updateLivePreview();
}

function insertFunc(fnToken) {
    let cur = calcInput.value;
    if (justEvaluated || cur === '0') {
        cur = '';
        justEvaluated = false;
    }
    calcInput.value = cur + fnToken;
    updateLivePreview();
}

function insertParens() {
    let cur = calcInput.value;
    if (justEvaluated || cur === '0') {
        calcInput.value = '(';
        justEvaluated = false;
        updateLivePreview();
        return;
    }
    const openCount = (cur.match(/\(/g) || []).length;
    const closeCount = (cur.match(/\)/g) || []).length;
    if (openCount > closeCount && /[0-9πeAns)]$/.test(cur)) {
        calcInput.value = cur + ')';
    } else {
        calcInput.value = cur + '(';
    }
    updateLivePreview();
}

function applyUnary(type) {
    const val = safeEvalExpression(calcInput.value);
    if (val === null) return;
    let res = val;
    let exprLabel = calcInput.value;
    if (type === 'inv') {
        res = 1 / val;
        exprLabel = '1/(' + exprLabel + ')';
    } else if (type === 'fact') {
        res = factorial(Math.round(val));
        exprLabel = Math.round(val) + '!';
    } else if (type === 'neg') {
        res = -val;
        exprLabel = '-(' + exprLabel + ')';
    }
    if (Number.isFinite(res)) {
        lastAns = res;
        calcExprDisplay.textContent = exprLabel + ' =';
        calcInput.value = formatDeNumber(res);
        justEvaluated = true;
        calcPreviewHint.textContent = '';
    }
}

function clearCalc() {
    calcInput.value = '0';
    calcExprDisplay.innerHTML = '&nbsp;';
    calcPreviewHint.textContent = '';
    justEvaluated = false;
}

function backspaceCalc() {
    if (justEvaluated) {
        clearCalc();
        return;
    }
    let cur = calcInput.value;
    if (cur.length <= 1) {
        calcInput.value = '0';
    } else {
        calcInput.value = cur.slice(0, -1);
    }
    updateLivePreview();
}

function updateLivePreview() {
    const raw = calcInput.value.trim();
    if (!raw || /^[0-9.,\-]+$/.test(raw)) {
        calcPreviewHint.textContent = '';
        return;
    }
    const val = safeEvalExpression(raw);
    if (val !== null) {
        calcPreviewHint.textContent = '= ' + formatDeNumber(val);
    } else {
        calcPreviewHint.textContent = '';
    }
}

function evaluateCalculation() {
    const raw = calcInput.value.trim();
    if (!raw) return;
    const val = safeEvalExpression(raw);
    if (val === null) {
        calcPreviewHint.textContent = 'Ungültiger Ausdruck';
        return;
    }
    const formatted = formatDeNumber(val);
    calcExprDisplay.textContent = raw + ' =';
    calcInput.value = formatted;
    calcPreviewHint.textContent = '';
    lastAns = val;
    justEvaluated = true;

    // Auf den Rechenstreifen übernehmen (sofern eine echte Rechnung oder ein Label vorliegt)
    const label = calcLineLabel.value.trim();
    if (/[+\-×÷*/^%!()]|sin|cos|tan|sqrt|cbrt|log|ln/i.test(raw) || label !== '') {
        addTapeItem(raw, val, formatted, label);
        calcLineLabel.value = '';
    }
}

function copyCalcResult() {
    const txt = calcInput.value.trim();
    if (!txt) return;
    navigator.clipboard.writeText(txt).then(() => {
        calcPreviewHint.textContent = '✓ Kopiert!';
        setTimeout(() => { calcPreviewHint.textContent = ''; }, 1500);
    });
}

// ============================================================================
// 4. SPEICHER-FUNKTIONEN (MC, MR, M+, M-)
// ============================================================================
function calcMemory(op) {
    const badge = document.getElementById('memory-badge');
    const curVal = safeEvalExpression(calcInput.value) ?? 0;

    if (op === 'MC') {
        memoryVal = 0;
        badge.classList.add('hidden');
    } else if (op === 'MR') {
        calcInput.value = formatDeNumber(memoryVal);
        justEvaluated = true;
        updateLivePreview();
    } else if (op === 'M+') {
        memoryVal += curVal;
        badge.textContent = 'M = ' + formatDeNumber(memoryVal);
        badge.classList.remove('hidden');
    } else if (op === 'M-') {
        memoryVal -= curVal;
        badge.textContent = 'M = ' + formatDeNumber(memoryVal);
        badge.classList.remove('hidden');
    }
}

// ============================================================================
// 5. RECHENSTREIFEN (PAPER-TAPE) & CLOUD-SPEICHERUNG
// ============================================================================
function loadTape() {
    try {
        const raw = localStorage.getItem(TAPE_STORAGE_KEY);
        if (raw) {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) tapeItems = parsed;
        }
    } catch (e) {
        tapeItems = [];
    }
    renderTape();
}

function persistTape() {
    try {
        localStorage.setItem(TAPE_STORAGE_KEY, JSON.stringify(tapeItems));
    } catch (e) {}
}

function addTapeItem(expr, result, resultFormatted, label = '') {
    tapeItems.push({
        id: Date.now() + Math.random(),
        expr: expr,
        result: Number(result),
        resultFormatted: resultFormatted || formatDeNumber(Number(result)),
        label: label,
        included: true
    });
    persistTape();
    renderTape();
}

function renderTape() {
    const container = document.getElementById('tape-list');
    const sumEl = document.getElementById('tape-sum-display');
    const countEl = document.getElementById('tape-count-info');

    if (tapeItems.length === 0) {
        container.innerHTML = `
            <div class="p-6 text-center rounded-xl border border-dashed border-slate-200 dark:border-slate-800 text-xs text-slate-400">
                Noch keine Einträge auf dem Rechenstreifen.<br>
                Gib links eine Rechnung ein und drücke <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono">=</kbd> oder <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono">Enter</kbd>.
            </div>
        `;
        sumEl.textContent = '0,00';
        countEl.textContent = '0 Positionen';
        return;
    }

    let sum = 0;
    let activeCount = 0;

    container.innerHTML = tapeItems.map((it, idx) => {
        const inc = it.included !== false;
        if (inc && Number.isFinite(it.result)) {
            sum += it.result;
            activeCount++;
        }
        return `
            <div class="p-2.5 rounded-xl border ${inc ? 'bg-slate-50 dark:bg-slate-800/60 border-slate-200/80 dark:border-slate-700/80' : 'bg-slate-100/50 dark:bg-slate-900/40 border-slate-200/40 dark:border-slate-800 opacity-60'} flex flex-col gap-1.5">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5 min-w-0 flex-1">
                        <input type="checkbox" ${inc ? 'checked' : ''} onchange="toggleTapeItem(${idx})"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer"
                            title="In Gesamtsumme (Σ) einbeziehen">
                        <span class="text-[11px] font-mono text-slate-400">#${idx + 1}</span>
                        <input type="text" value="${escapeHtml(it.label || '')}"
                            placeholder="Bezeichnung hinzufügen..."
                            onchange="updateTapeLabel(${idx}, this.value)"
                            class="w-full bg-transparent text-xs font-semibold text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:underline">
                    </div>
                    <button type="button" onclick="removeTapeItem(${idx})"
                        class="text-slate-400 hover:text-rose-500 text-xs cursor-pointer" title="Zeile entfernen">&times;</button>
                </div>
                <div class="flex items-baseline justify-between gap-2 pl-5">
                    <button type="button" onclick="loadTapeExpr(${idx})"
                        class="text-xs font-mono text-slate-500 dark:text-slate-400 hover:text-brand-600 truncate cursor-pointer"
                        title="Rechenweg in den Rechner laden">
                        ${escapeHtml(it.expr)} =
                    </button>
                    <button type="button" onclick="loadTapeResult(${idx})"
                        class="text-sm font-mono font-extrabold text-slate-900 dark:text-white hover:text-brand-600 cursor-pointer"
                        title="Ergebnis in den Rechner übernehmen">
                        ${escapeHtml(it.resultFormatted)}
                    </button>
                </div>
            </div>
        `;
    }).join('');

    sumEl.textContent = formatDeNumber(sum, 2);
    countEl.textContent = `${activeCount} von ${tapeItems.length} ${tapeItems.length === 1 ? 'Position' : 'Positionen'} summiert`;
    container.scrollTop = container.scrollHeight;
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

function toggleTapeItem(idx) {
    if (!tapeItems[idx]) return;
    tapeItems[idx].included = !tapeItems[idx].included;
    persistTape();
    renderTape();
}

function updateTapeLabel(idx, val) {
    if (!tapeItems[idx]) return;
    tapeItems[idx].label = val.trim();
    persistTape();
}

function removeTapeItem(idx) {
    tapeItems.splice(idx, 1);
    persistTape();
    renderTape();
}

function clearTape() {
    if (tapeItems.length === 0) return;
    tapeItems = [];
    persistTape();
    renderTape();
}

function loadTapeExpr(idx) {
    if (!tapeItems[idx]) return;
    calcInput.value = tapeItems[idx].expr;
    justEvaluated = false;
    updateLivePreview();
}

function loadTapeResult(idx) {
    if (!tapeItems[idx]) return;
    calcInput.value = tapeItems[idx].resultFormatted;
    justEvaluated = true;
    updateLivePreview();
}

function useTapeSumInCalc() {
    let sum = 0;
    tapeItems.forEach(it => {
        if (it.included !== false && Number.isFinite(it.result)) sum += it.result;
    });
    calcInput.value = formatDeNumber(sum, 2);
    justEvaluated = true;
    updateLivePreview();
}

async function saveTapeToCloud(target) {
    if (tapeItems.length === 0) {
        alert('Der Rechenstreifen ist noch leer. Führe zuerst mindestens eine Berechnung durch.');
        return;
    }
    const title = document.getElementById('tape-title-input').value.trim() || ('Rechenstreifen vom ' + new Date().toLocaleDateString('de-DE'));
    const fd = new FormData();
    fd.append('csrf_token', CALC_CSRF);
    fd.append('title', title);
    fd.append('items_json', JSON.stringify(tapeItems));

    if (target === 'note') {
        fd.append('ajax_action', 'save_tape_note');
    } else {
        fd.append('ajax_action', 'save_tape_file');
        fd.append('format', target);
    }

    try {
        const res = await fetch('calculator.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            const toast = document.getElementById('calc-toast');
            document.getElementById('calc-toast-msg').textContent = data.message;
            document.getElementById('calc-toast-link').href = data.url || 'index.php';
            toast.classList.remove('hidden');
        } else {
            alert(data.error || 'Fehler beim Speichern.');
        }
    } catch (e) {
        alert('Netzwerkfehler beim Speichern.');
    }
}

// ============================================================================
// 6. MWST.-, RABATT- & TRINKGELD-RECHNER
// ============================================================================
function setVatMode(mode) {
    vatDirection = mode;
    const btnN = document.getElementById('vat-dir-netto');
    const btnB = document.getElementById('vat-dir-brutto');
    if (mode === 'netto_to_brutto') {
        btnN.className = 'px-3 py-2 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer';
        btnB.className = 'px-3 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer';
    } else {
        btnB.className = 'px-3 py-2 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer';
        btnN.className = 'px-3 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer';
    }
    calcVat();
}

function setVatRate(rate) {
    document.getElementById('vat-rate').value = rate;
    calcVat();
}

function calcVat() {
    const amount = parseFloat(document.getElementById('vat-amount').value) || 0;
    const rate = parseFloat(document.getElementById('vat-rate').value) || 0;
    let netto = 0, tax = 0, brutto = 0;

    if (vatDirection === 'netto_to_brutto') {
        netto = amount;
        tax = netto * (rate / 100);
        brutto = netto + tax;
    } else {
        brutto = amount;
        netto = brutto / (1 + rate / 100);
        tax = brutto - netto;
    }

    document.getElementById('vat-res-netto').textContent = formatDeNumber(netto, 2) + ' €';
    document.getElementById('vat-res-tax').textContent = formatDeNumber(tax, 2) + ' €';
    document.getElementById('vat-res-brutto').textContent = formatDeNumber(brutto, 2) + ' €';
}

function sendVatToTape() {
    const amount = parseFloat(document.getElementById('vat-amount').value) || 0;
    const rate = parseFloat(document.getElementById('vat-rate').value) || 0;
    if (vatDirection === 'netto_to_brutto') {
        const brutto = amount * (1 + rate / 100);
        addTapeItem(`${formatDeNumber(amount, 2)} + ${rate}% MwSt.`, brutto, formatDeNumber(brutto, 2), 'Brutto inkl. MwSt.');
    } else {
        const netto = amount / (1 + rate / 100);
        addTapeItem(`${formatDeNumber(amount, 2)} / 1,${rate}`, netto, formatDeNumber(netto, 2), 'Netto ohne MwSt.');
    }
    switchCalcTab('main');
}

function setDiscountPct(p) {
    document.getElementById('disc-pct').value = p;
    calcDiscount();
}

function calcDiscount() {
    const price = parseFloat(document.getElementById('disc-price').value) || 0;
    const pct = parseFloat(document.getElementById('disc-pct').value) || 0;
    const part = price * (pct / 100);
    document.getElementById('disc-res-part').textContent = formatDeNumber(part, 2) + ' €';
    document.getElementById('disc-res-minus').textContent = formatDeNumber(price - part, 2) + ' €';
    document.getElementById('disc-res-plus').textContent = formatDeNumber(price + part, 2) + ' €';
}

function sendDiscountToTape() {
    const price = parseFloat(document.getElementById('disc-price').value) || 0;
    const pct = parseFloat(document.getElementById('disc-pct').value) || 0;
    const finalPrice = price * (1 - pct / 100);
    addTapeItem(`${formatDeNumber(price, 2)} - ${pct}%`, finalPrice, formatDeNumber(finalPrice, 2), `Rabatt (${pct}%)`);
    switchCalcTab('main');
}

function calcSplit() {
    const bill = parseFloat(document.getElementById('split-bill').value) || 0;
    const tipPct = parseFloat(document.getElementById('split-tip').value) || 0;
    const people = Math.max(1, parseInt(document.getElementById('split-people').value, 10) || 1);
    const tip = bill * (tipPct / 100);
    const total = bill + tip;
    const perPerson = total / people;

    document.getElementById('split-res-tip').textContent = formatDeNumber(tip, 2) + ' €';
    document.getElementById('split-res-total').textContent = formatDeNumber(total, 2) + ' €';
    document.getElementById('split-res-person').textContent = formatDeNumber(perPerson, 2) + ' €';
}

// ============================================================================
// 7. EINHEITEN-UMRECHNER
// ============================================================================
const UNIT_DEFS = {
    length: {
        defaultFrom: 'm', defaultTo: 'km',
        units: {
            mm: { label: 'Millimeter (mm)', factor: 0.001 },
            cm: { label: 'Zentimeter (cm)', factor: 0.01 },
            m:  { label: 'Meter (m)', factor: 1 },
            km: { label: 'Kilometer (km)', factor: 1000 },
            inch: { label: 'Zoll / Inch (in)', factor: 0.0254 },
            ft: { label: 'Fuß / Feet (ft)', factor: 0.3048 },
            yd: { label: 'Yard (yd)', factor: 0.9144 },
            mi: { label: 'Meilen (mi)', factor: 1609.344 },
            nmi: { label: 'Seemeilen (nmi)', factor: 1852 }
        }
    },
    weight: {
        defaultFrom: 'kg', defaultTo: 'g',
        units: {
            mg: { label: 'Milligramm (mg)', factor: 0.000001 },
            g:  { label: 'Gramm (g)', factor: 0.001 },
            kg: { label: 'Kilogramm (kg)', factor: 1 },
            t:  { label: 'Tonne (t)', factor: 1000 },
            oz: { label: 'Unze / Ounce (oz)', factor: 0.0283495231 },
            lb: { label: 'Pfund / Pound (lb)', factor: 0.45359237 }
        }
    },
    area: {
        defaultFrom: 'm2', defaultTo: 'ha',
        units: {
            cm2: { label: 'Quadratzentimeter (cm²)', factor: 0.0001 },
            m2:  { label: 'Quadratmeter (m²)', factor: 1 },
            ar:  { label: 'Ar (a)', factor: 100 },
            ha:  { label: 'Hektar (ha)', factor: 10000 },
            km2: { label: 'Quadratkilometer (km²)', factor: 1000000 },
            sqft: { label: 'Quadratfuß (sq ft)', factor: 0.09290304 }
        }
    },
    volume: {
        defaultFrom: 'l', defaultTo: 'ml',
        units: {
            ml: { label: 'Milliliter (ml)', factor: 0.001 },
            cl: { label: 'Zentiliter (cl)', factor: 0.01 },
            l:  { label: 'Liter (l)', factor: 1 },
            m3: { label: 'Kubikmeter (m³)', factor: 1000 },
            gal: { label: 'US-Gallone (gal)', factor: 3.78541178 }
        }
    },
    temp: {
        defaultFrom: 'C', defaultTo: 'F',
        units: {
            C: { label: 'Grad Celsius (°C)' },
            F: { label: 'Grad Fahrenheit (°F)' },
            K: { label: 'Kelvin (K)' }
        }
    },
    data: {
        defaultFrom: 'GB', defaultTo: 'MB',
        units: {
            B:  { label: 'Byte (B)', factor: 1 },
            KB: { label: 'Kilobyte (KB)', factor: 1024 },
            MB: { label: 'Megabyte (MB)', factor: 1048576 },
            GB: { label: 'Gigabyte (GB)', factor: 1073741824 },
            TB: { label: 'Terabyte (TB)', factor: 1099511627776 }
        }
    },
    speed: {
        defaultFrom: 'kmh', defaultTo: 'ms',
        units: {
            ms:  { label: 'Meter pro Sekunde (m/s)', factor: 1 },
            kmh: { label: 'Kilometer pro Stunde (km/h)', factor: 1 / 3.6 },
            mph: { label: 'Meilen pro Stunde (mph)', factor: 0.44704 },
            kn:  { label: 'Knoten (kn)', factor: 0.514444 }
        }
    }
};

function populateUnitSelects() {
    const cat = document.getElementById('unit-category').value;
    const def = UNIT_DEFS[cat];
    const selFrom = document.getElementById('unit-from');
    const selTo = document.getElementById('unit-to');

    selFrom.innerHTML = '';
    selTo.innerHTML = '';

    Object.entries(def.units).forEach(([key, meta]) => {
        selFrom.add(new Option(meta.label, key, key === def.defaultFrom, key === def.defaultFrom));
        selTo.add(new Option(meta.label, key, key === def.defaultTo, key === def.defaultTo));
    });
    convertUnits();
}

function swapUnits() {
    const selFrom = document.getElementById('unit-from');
    const selTo = document.getElementById('unit-to');
    const tmp = selFrom.value;
    selFrom.value = selTo.value;
    selTo.value = tmp;
    convertUnits();
}

function convertSingleUnit(val, fromKey, toKey, cat) {
    if (cat === 'temp') {
        let celsius = val;
        if (fromKey === 'F') celsius = (val - 32) * (5 / 9);
        if (fromKey === 'K') celsius = val - 273.15;
        if (toKey === 'C') return celsius;
        if (toKey === 'F') return celsius * (9 / 5) + 32;
        if (toKey === 'K') return celsius + 273.15;
        return celsius;
    }
    const units = UNIT_DEFS[cat].units;
    const baseVal = val * units[fromKey].factor;
    return baseVal / units[toKey].factor;
}

function convertUnits() {
    const cat = document.getElementById('unit-category').value;
    const val = parseFloat(document.getElementById('unit-input').value) || 0;
    const fromKey = document.getElementById('unit-from').value;
    const toKey = document.getElementById('unit-to').value;

    const res = convertSingleUnit(val, fromKey, toKey, cat);
    document.getElementById('unit-output').value = formatDeNumber(res, 6);

    const grid = document.getElementById('unit-all-grid');
    grid.innerHTML = Object.entries(UNIT_DEFS[cat].units).map(([k, meta]) => {
        const r = convertSingleUnit(val, fromKey, k, cat);
        return `
            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 flex justify-between items-center gap-2">
                <span class="text-slate-500 dark:text-slate-400 truncate">${escapeHtml(meta.label)}</span>
                <span class="font-mono font-bold text-slate-900 dark:text-white shrink-0">${formatDeNumber(r, 4)}</span>
            </div>
        `;
    }).join('');
}

// ============================================================================
// 8. ZINSESZINS- & KREDIT-RECHNER
// ============================================================================
function calcSavings() {
    const initial = parseFloat(document.getElementById('sav-initial').value) || 0;
    const monthly = parseFloat(document.getElementById('sav-monthly').value) || 0;
    const rate = parseFloat(document.getElementById('sav-rate').value) || 0;
    const years = Math.max(1, parseInt(document.getElementById('sav-years').value, 10) || 1);

    const months = years * 12;
    const monthlyRate = (rate / 100) / 12;
    let total = initial;
    for (let m = 0; m < months; m++) {
        total = (total + monthly) * (1 + monthlyRate);
    }
    const paid = initial + monthly * months;
    const interest = total - paid;

    document.getElementById('sav-res-paid').textContent = formatDeNumber(paid, 2) + ' €';
    document.getElementById('sav-res-interest').textContent = formatDeNumber(interest, 2) + ' €';
    document.getElementById('sav-res-total').textContent = formatDeNumber(total, 2) + ' €';
}

function calcLoan() {
    const amount = parseFloat(document.getElementById('loan-amount').value) || 0;
    const rate = parseFloat(document.getElementById('loan-rate').value) || 0;
    const years = Math.max(1, parseInt(document.getElementById('loan-years').value, 10) || 1);

    const months = years * 12;
    const r = (rate / 100) / 12;
    let monthlyPayment = 0;
    if (r === 0) {
        monthlyPayment = amount / months;
    } else {
        monthlyPayment = amount * (r * Math.pow(1 + r, months)) / (Math.pow(1 + r, months) - 1);
    }
    const total = monthlyPayment * months;
    const interest = total - amount;

    document.getElementById('loan-res-monthly').textContent = formatDeNumber(monthlyPayment, 2) + ' €';
    document.getElementById('loan-res-interest').textContent = formatDeNumber(interest, 2) + ' €';
    document.getElementById('loan-res-total').textContent = formatDeNumber(total, 2) + ' €';
}

// ============================================================================
// 9. TASTATUR- & NUMPAD-STEUERUNG
// ============================================================================
calcInput.addEventListener('input', () => {
    justEvaluated = false;
    updateLivePreview();
});

document.addEventListener('keydown', (ev) => {
    const panelMain = document.getElementById('calc-panel-main');
    if (panelMain.classList.contains('hidden')) return;

    const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
    const activeId = document.activeElement ? document.activeElement.id : '';

    // Wenn der Benutzer gerade im Notiz-Feld oder Titel-Feld tippt: nur Enter abfangen
    if ((activeTag === 'input' || activeTag === 'textarea') && activeId !== 'calc-input') {
        if (activeId === 'calc-line-label' && ev.key === 'Enter') {
            ev.preventDefault();
            evaluateCalculation();
        }
        return;
    }

    if (activeId === 'calc-input') {
        if (ev.key === 'Enter' || ev.key === '=') {
            ev.preventDefault();
            evaluateCalculation();
        } else if (ev.key === 'Escape') {
            ev.preventDefault();
            clearCalc();
        }
        return;
    }

    if (/^[0-9]$/.test(ev.key)) {
        ev.preventDefault();
        insertToken(ev.key);
    } else if (ev.key === ',' || ev.key === '.') {
        ev.preventDefault();
        insertToken(',');
    } else if (ev.key === '+') {
        ev.preventDefault();
        insertOperator('+');
    } else if (ev.key === '-') {
        ev.preventDefault();
        insertOperator('-');
    } else if (ev.key === '*') {
        ev.preventDefault();
        insertOperator('×');
    } else if (ev.key === '/') {
        ev.preventDefault();
        insertOperator('÷');
    } else if (ev.key === '%') {
        ev.preventDefault();
        insertToken('%');
    } else if (ev.key === '(' || ev.key === ')') {
        ev.preventDefault();
        insertToken(ev.key);
    } else if (ev.key === 'Enter' || ev.key === '=') {
        ev.preventDefault();
        evaluateCalculation();
    } else if (ev.key === 'Backspace') {
        ev.preventDefault();
        backspaceCalc();
    } else if (ev.key === 'Escape' || ev.key === 'Delete') {
        ev.preventDefault();
        clearCalc();
    }
});

// Initialisierung beim Laden
loadTape();
calcVat();
calcDiscount();
calcSplit();
populateUnitSelects();
calcSavings();
calcLoan();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
