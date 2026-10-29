<?php
declare(strict_types=1);

/**
 * MiniCloud – Kollaboratives Whiteboard & Skizzenbuch (whiteboard.php)
 * 100 % lokal, werbefrei & DSGVO-konform (HTML5 Canvas / Vektor-Elemente / PHP / MySQL).
 *
 * Funktionen:
 * - Freihand-Stift, Textmarker, Linien, Pfeile, Rechtecke, Kreise, Rauten, Haftnotizen (Sticky Notes) & Textfelder
 * - Auswahl- & Verschiebe-Werkzeug, Radierer, Rückgängig (Strg+Z) & Wiederholen (Strg+Y)
 * - Unendliches Verschieben & Zoomen (Pan & Zoom), 3 Hintergründe (Punktraster, Kariert, Blanko)
 * - 1-Klick-Vorlagen (Brainstorming, Flussdiagramm, Projekt-Board)
 * - Live-Team-Modus: Gemeinsam mit anderen Cloud-Benutzern in Echtzeit auf demselben Board zeichnen (inkl. Live-Mauszeiger!)
 * - Export als PNG-Download ODER 1-Klick-Speicherung direkt in „Meine Dateien“ (index.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_whiteboard'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Whiteboard-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];

ensureWhiteboardSchema($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['ajax'])) {
    recordModuleUsage($pdo, 'whiteboard');
}

function ensureWhiteboardSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked || (!empty($_SESSION['_mc_whiteboard_schema_v2']) && $_SESSION['_mc_whiteboard_schema_v2'] === 1)) {
        $checked = true;
        return;
    }
    $checked = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `whiteboards` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(160) NOT NULL DEFAULT 'Neues Whiteboard',
                `is_shared` TINYINT(1) NOT NULL DEFAULT 0,
                `bg_style` VARCHAR(24) NOT NULL DEFAULT 'dots',
                `elements_json` MEDIUMTEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_wb_user` (`user_id`),
                INDEX `idx_wb_shared` (`is_shared`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `whiteboard_shares` (
                `board_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`board_id`, `user_id`),
                INDEX `idx_wbs_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Einmalige Bereinigung: Boards ohne explizite Benutzerfreigabe in whiteboard_shares sind privat (is_shared = 0)
        $pdo->exec(
            "UPDATE `whiteboards` w
             SET w.`is_shared` = 0
             WHERE w.`is_shared` = 1
               AND NOT EXISTS (SELECT 1 FROM `whiteboard_shares` ws WHERE ws.`board_id` = w.`id`)"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `whiteboard_peers` (
                `board_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `username` VARCHAR(100) NOT NULL,
                `cursor_x` FLOAT NOT NULL DEFAULT 0,
                `cursor_y` FLOAT NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`board_id`, `user_id`),
                INDEX `idx_wb_peers_time` (`board_id`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $_SESSION['_mc_whiteboard_schema_v2'] = 1;
    } catch (Throwable) {
    }
}

/**
 * Prüft, ob ein Benutzer Eigentümer des Whiteboards ist oder explizit in `whiteboard_shares` freigegeben wurde.
 */
function getAccessibleWhiteboard(PDO $pdo, int $boardId, int $userId): ?array
{
    if ($boardId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT w.*
         FROM `whiteboards` w
         WHERE w.`id` = :id
           AND (
               w.`user_id` = :uid
               OR EXISTS (
                   SELECT 1 FROM `whiteboard_shares` ws
                   WHERE ws.`board_id` = w.`id` AND ws.`user_id` = :uid2
               )
           )
         LIMIT 1"
    );
    $stmt->execute([':id' => $boardId, ':uid' => $userId, ':uid2' => $userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

// =============================================================================
// AJAX-ENDPUNKTE FÜR WHITEBOARD-SPEICHERUNG, FREIGABE, LIVE-SYNC & CLOUD-EXPORT
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Neues Whiteboard anlegen (standardmäßig privat)
    if ($action === 'create_board') {
        $title    = trim((string)($_POST['title'] ?? 'Neues Whiteboard'));
        $title    = $title !== '' ? mb_substr($title, 0, 150) : 'Neues Whiteboard';
        $elements = (string)($_POST['elements_json'] ?? '[]');
        if (json_decode($elements, true) === null) {
            $elements = '[]';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO `whiteboards` (`user_id`, `title`, `is_shared`, `bg_style`, `elements_json`)
             VALUES (:uid, :t, 0, 'dots', :el)"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':t'   => $title,
            ':el'  => $elements,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'board_id' => $newId]);
        exit;
    }

    // 1b. Gezielte Benutzer-Freigabe speichern (nur durch Eigentümer)
    if ($action === 'save_shares') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT `id`, `user_id` FROM `whiteboards` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $boardId]);
        $board = $stmtChk->fetch();

        if (!$board || (int)$board['user_id'] !== $userId) {
            echo json_encode(['ok' => false, 'error' => 'Nur der Eigentümer kann die Freigabe dieses Whiteboards ändern.']);
            exit;
        }

        $rawIds = json_decode((string)($_POST['user_ids_json'] ?? '[]'), true);
        $cleanIds = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $val) {
                $uidCandidate = (int)$val;
                if ($uidCandidate > 0 && $uidCandidate !== $userId) {
                    $cleanIds[$uidCandidate] = $uidCandidate;
                }
            }
        }

        $pdo->prepare("DELETE FROM `whiteboard_shares` WHERE `board_id` = :bid")->execute([':bid' => $boardId]);

        $validSharedIds = [];
        if (!empty($cleanIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmtValidUsers = $pdo->prepare("SELECT `id` FROM `users` WHERE `id` IN ($inPlaceholders)");
            $stmtValidUsers->execute(array_values($cleanIds));
            $validSharedIds = array_map('intval', $stmtValidUsers->fetchAll(PDO::FETCH_COLUMN));

            $stmtInsShare = $pdo->prepare("INSERT IGNORE INTO `whiteboard_shares` (`board_id`, `user_id`) VALUES (:bid, :uid)");
            foreach ($validSharedIds as $targetUid) {
                $stmtInsShare->execute([':bid' => $boardId, ':uid' => $targetUid]);
            }
        }

        $isNowShared = !empty($validSharedIds) ? 1 : 0;
        $pdo->prepare("UPDATE `whiteboards` SET `is_shared` = :sh WHERE `id` = :id")
            ->execute([':sh' => $isNowShared, ':id' => $boardId]);

        echo json_encode([
            'ok'           => true,
            'is_shared'    => $isNowShared,
            'shared_count' => count($validSharedIds),
            'shared_ids'   => $validSharedIds,
        ]);
        exit;
    }

    // 2. Whiteboard speichern (Elemente, Titel, Hintergrund)
    if ($action === 'save_board') {
        session_write_close();
        $boardId  = (int)($_POST['board_id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? 'Whiteboard'));
        $title    = $title !== '' ? mb_substr($title, 0, 150) : 'Whiteboard';
        $bgStyle  = in_array($_POST['bg_style'] ?? '', ['dots', 'grid', 'blank'], true) ? (string)$_POST['bg_style'] : 'dots';
        $elements = (string)($_POST['elements_json'] ?? '[]');

        $board = getAccessibleWhiteboard($pdo, $boardId, $userId);
        if (!$board) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für dieses Whiteboard.']);
            exit;
        }

        $stmtUp = $pdo->prepare(
            "UPDATE `whiteboards`
             SET `title` = :t, `bg_style` = :bg, `elements_json` = :el
             WHERE `id` = :id"
        );
        $stmtUp->execute([
            ':t'  => $title,
            ':bg' => $bgStyle,
            ':el' => $elements,
            ':id' => $boardId,
        ]);

        echo json_encode(['ok' => true, 'updated_at' => date('H:i:s')]);
        exit;
    }

    // 3. Live-Sync: Eigene Mausposition senden & Änderungen/Mitzeichner abrufen
    if ($action === 'poll_board') {
        session_write_close();
        $boardId = (int)($_POST['board_id'] ?? 0);
        $cx      = (float)($_POST['cursor_x'] ?? 0);
        $cy      = (float)($_POST['cursor_y'] ?? 0);

        $bRow = getAccessibleWhiteboard($pdo, $boardId, $userId);
        if (!$bRow) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für dieses Whiteboard.']);
            exit;
        }

        if ((int)$bRow['is_shared'] !== 1) {
            $peers = [];
        } else {
            try {
                $stmtPeer = $pdo->prepare(
                    "INSERT INTO `whiteboard_peers` (`board_id`, `user_id`, `username`, `cursor_x`, `cursor_y`)
                     VALUES (:bid, :uid, :uname, :cx, :cy)
                     ON DUPLICATE KEY UPDATE `username` = VALUES(`username`), `cursor_x` = VALUES(`cursor_x`), `cursor_y` = VALUES(`cursor_y`), `updated_at` = NOW()"
                );
                $stmtPeer->execute([
                    ':bid'   => $boardId,
                    ':uid'   => $userId,
                    ':uname' => $username,
                    ':cx'    => $cx,
                    ':cy'    => $cy,
                ]);
            } catch (Throwable) {
            }

            $stmtP = $pdo->prepare(
                "SELECT `user_id`, `username`, `cursor_x`, `cursor_y`
                 FROM `whiteboard_peers`
                 WHERE `board_id` = :bid
                   AND `user_id` != :uid
                   AND `updated_at` >= DATE_SUB(NOW(), INTERVAL 12 SECOND)"
            );
            $stmtP->execute([':bid' => $boardId, ':uid' => $userId]);
            $peers = $stmtP->fetchAll();
        }

        echo json_encode([
            'ok'            => true,
            'title'         => $bRow['title'] ?? '',
            'bg_style'      => $bRow['bg_style'] ?? 'dots',
            'elements_json' => $bRow['elements_json'] ?? '[]',
            'updated_at'    => $bRow['updated_at'] ?? '',
            'peers'         => $peers,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 4. Whiteboard löschen
    if ($action === 'delete_board') {
        $boardId = (int)($_POST['board_id'] ?? 0);
        $stmtDel = $pdo->prepare("DELETE FROM `whiteboards` WHERE `id` = :id AND `user_id` = :uid");
        $stmtDel->execute([':id' => $boardId, ':uid' => $userId]);
        $pdo->prepare("DELETE FROM `whiteboard_shares` WHERE `board_id` = :id")->execute([':id' => $boardId]);
        $pdo->prepare("DELETE FROM `whiteboard_peers` WHERE `board_id` = :id")->execute([':id' => $boardId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 5. Whiteboard als PNG-Bild direkt in „Meine Dateien“ (Cloud) speichern
    if ($action === 'export_to_cloud') {
        $dataUrl = (string)($_POST['image_data_url'] ?? '');
        $title   = trim((string)($_POST['title'] ?? 'Whiteboard'));
        if (!preg_match('/^data:image\/png;base64,(.+)$/', $dataUrl, $m)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges PNG-Bildformat.']);
            exit;
        }
        $pngBin = base64_decode($m[1], true);
        if ($pngBin === false || strlen($pngBin) < 64 || substr($pngBin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            echo json_encode(['ok' => false, 'error' => 'Bilddaten konnten nicht als gültiges PNG dekodiert werden.']);
            exit;
        }

        $size  = strlen($pngBin);
        $stats = getUserStorageStats($pdo, $userId);
        if ($size > $stats['free']) {
            echo json_encode(['ok' => false, 'error' => 'Nicht genügend freier persönlicher Cloud-Speicherplatz verfügbar.']);
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0750, true);
        }
        $safeTitle = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_-]+/u', '_', $title) ?: 'Whiteboard';
        $origName  = $safeTitle . '_' . date('Y-m-d_His') . '.png';
        $stored    = generateSafeStoredFilename('png');
        $destPath  = UPLOAD_DIR . $stored;

        if (@file_put_contents($destPath, $pngBin) === false) {
            echo json_encode(['ok' => false, 'error' => 'Datei konnte nicht im Cloud-Verzeichnis gespeichert werden.']);
            exit;
        }
        @chmod($destPath, 0640);

        $stmtInsF = $pdo->prepare(
            "INSERT INTO `files` (`user_id`, `group_folder_id`, `filename`, `original_name`, `folder_path`, `file_size`, `mime_type`, `is_encrypted`)
             VALUES (:uid, NULL, :stored, :orig, '', :sz, 'image/png', 0)"
        );
        $stmtInsF->execute([
            ':uid'    => $userId,
            ':stored' => $stored,
            ':orig'   => $origName,
            ':sz'     => $size,
        ]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Als „' . $origName . '“ in deinen Cloud-Dateien gespeichert!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// =============================================================================
// ALLE VERFÜGBAREN WHITEBOARDS LADEN (EIGENE + FÜR MICH FREIGEGEBENE)
// =============================================================================
$stmtList = $pdo->prepare(
    "SELECT w.`id`, w.`user_id`, w.`title`, w.`is_shared`, w.`bg_style`, w.`updated_at`, u.`username` AS `owner_name`,
            (SELECT COUNT(*) FROM `whiteboard_shares` ws_cnt WHERE ws_cnt.`board_id` = w.`id`) AS `shared_count`
     FROM `whiteboards` w
     INNER JOIN `users` u ON u.`id` = w.`user_id`
     WHERE w.`user_id` = :uid
        OR EXISTS (
            SELECT 1 FROM `whiteboard_shares` ws
            WHERE ws.`board_id` = w.`id` AND ws.`user_id` = :uid2
        )
     ORDER BY (w.`user_id` = :uid3) DESC, w.`updated_at` DESC"
);
$stmtList->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
$boardsList = $stmtList->fetchAll();

if (empty($boardsList)) {
    // Starter-Whiteboard (standardmäßig privat) mit Begrüßungs-Elementen anlegen
    $starterElements = [
        [
            'id'    => 'el_welcome_1',
            'type'  => 'sticky',
            'x'     => 90,
            'y'     => 80,
            'w'     => 220,
            'h'     => 145,
            'color' => '#fef08a',
            'text'  => "🎨 Willkommen auf dem\nMiniCloud Whiteboard!\n\nDoppelklicke auf Notizen oder Texte zum Bearbeiten.",
        ],
        [
            'id'    => 'el_welcome_2',
            'type'  => 'sticky',
            'x'     => 410,
            'y'     => 85,
            'w'     => 220,
            'h'     => 145,
            'color' => '#bbf7d0',
            'text'  => "👥 Gezielte Team-Freigabe:\nÜber „👥 Freigabe“ wählst du genau die Cloud-Mitglieder aus, die mitzeichnen dürfen!",
        ],
        [
            'id'    => 'el_welcome_arrow',
            'type'  => 'arrow',
            'x1'    => 315,
            'y1'    => 150,
            'x2'    => 402,
            'y2'    => 150,
            'color' => '#2563eb',
            'size'  => 3,
        ],
    ];
    $stmtSeed = $pdo->prepare(
        "INSERT INTO `whiteboards` (`user_id`, `title`, `is_shared`, `bg_style`, `elements_json`)
         VALUES (:uid, 'Mein Ideen-Whiteboard', 0, 'dots', :el)"
    );
    $stmtSeed->execute([
        ':uid' => $userId,
        ':el'  => json_encode($starterElements, JSON_UNESCAPED_UNICODE),
    ]);
    $stmtList->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
    $boardsList = $stmtList->fetchAll();
}

$requestedId = (int)($_GET['id'] ?? 0);
$activeBoard = null;
foreach ($boardsList as $b) {
    if ((int)$b['id'] === $requestedId) {
        $activeBoard = $b;
        break;
    }
}
if ($activeBoard === null) {
    $activeBoard = $boardsList[0];
}

$stmtFull = $pdo->prepare("SELECT * FROM `whiteboards` WHERE `id` = :id LIMIT 1");
$stmtFull->execute([':id' => (int)$activeBoard['id']]);
$activeBoardFull = $stmtFull->fetch() ?: $activeBoard;
$isBoardOwner    = ((int)$activeBoardFull['user_id'] === $userId);

// Alle anderen Cloud-Benutzer & aktuell freigegebene Benutzer für dieses Board laden
$stmtUsers = $pdo->prepare("SELECT `id`, `username` FROM `users` WHERE `id` != :uid ORDER BY `username` ASC");
$stmtUsers->execute([':uid' => $userId]);
$allOtherUsers = $stmtUsers->fetchAll();

$stmtSharedIds = $pdo->prepare("SELECT `user_id` FROM `whiteboard_shares` WHERE `board_id` = :bid");
$stmtSharedIds->execute([':bid' => (int)$activeBoardFull['id']]);
$currentSharedUserIds = array_map('intval', $stmtSharedIds->fetchAll(PDO::FETCH_COLUMN));

$pageTitle = '🎨 Whiteboard & Skizzenbuch';
require_once __DIR__ . '/includes/header.php';
$csrfToken = getCsrfToken();
?>

<div class="space-y-4">
    <!-- KOPFBEREICH & BOARD-AUSWAHL -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5 shadow-xs flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-xl shadow-xs shrink-0">
                🎨
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        type="text"
                        id="wbTitleInput"
                        value="<?= e((string)$activeBoardFull['title']) ?>"
                        onchange="wbScheduleSave()"
                        class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white bg-transparent border-b border-transparent hover:border-slate-300 dark:hover:border-slate-700 focus:border-brand-500 focus:outline-none px-1 py-0.5 rounded"
                        title="Klicken zum Umbenennen des Whiteboards"
                    >
                    <span id="wbSaveStatusBadge" class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        ✓ Gespeichert
                    </span>
                    <div id="wbLivePeersBox" class="flex items-center gap-1"></div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Freihand-Skizzen, Flussdiagramme, Klebezettel &amp; gezielte Team-Kollaboration im Browser
                </p>
            </div>
        </div>

        <!-- Board-Wechsler, Vorlagen, gezielte Freigabe & Export -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <select
                id="wbBoardSwitcher"
                onchange="window.location.href='whiteboard.php?id=' + this.value"
                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-800 dark:text-slate-100 cursor-pointer"
            >
                <?php foreach ($boardsList as $bItem): ?>
                    <?php $bSharedCnt = (int)($bItem['shared_count'] ?? 0); ?>
                    <option value="<?= (int)$bItem['id'] ?>" <?= (int)$bItem['id'] === (int)$activeBoardFull['id'] ? 'selected' : '' ?>>
                        <?= $bSharedCnt > 0 ? '👥' : '🔒' ?> <?= e((string)$bItem['title']) ?> (<?= (int)$bItem['user_id'] === $userId ? ($bSharedCnt > 0 ? $bSharedCnt . ' Mitzeichner' : 'Privat') : 'von ' . e((string)$bItem['owner_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" onclick="wbCreateNewBoard()"
                    class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold shadow-2xs cursor-pointer">
                + Neues Board
            </button>

            <select id="wbTemplateSelect" onchange="if(this.value){wbApplyTemplate(this.value);this.value='';}"
                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold cursor-pointer">
                <option value="">✨ Vorlage einfügen…</option>
                <option value="brainstorm">💡 Brainstorming-Cluster</option>
                <option value="flowchart">🔄 Flussdiagramm / Prozess</option>
                <option value="kanban">📋 3-Spalten Projekt-Board</option>
            </select>

            <?php if ($isBoardOwner): ?>
                <button
                    type="button"
                    id="wbOpenShareModalBtn"
                    onclick="wbOpenShareModal()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-bold border transition-colors cursor-pointer <?= count($currentSharedUserIds) > 0 ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                    title="Auswählen, welche Cloud-Benutzer dieses Whiteboard sehen und mitzeichnen dürfen"
                >
                    <span id="wbShareBtnIcon"><?= count($currentSharedUserIds) > 0 ? '👥' : '🔒' ?></span>
                    <span id="wbShareBtnLabel"><?= count($currentSharedUserIds) > 0 ? 'Freigegeben (' . count($currentSharedUserIds) . ')' : 'Privat · Freigeben…' ?></span>
                </button>
            <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 font-semibold">
                    👥 Freigegeben von <?= e((string)($activeBoard['owner_name'] ?? 'Team')) ?>
                </span>
            <?php endif; ?>

            <button type="button" onclick="wbExportPngDownload()"
                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-semibold cursor-pointer"
                    title="Als PNG-Bilddatei herunterladen">
                📥 PNG
            </button>

            <button type="button" onclick="wbExportToCloudFiles()"
                    class="px-2.5 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer"
                    title="Direkt als PNG-Bild in „Meine Dateien“ speichern">
                ☁️ In Dateien speichern
            </button>

            <?php if ($isBoardOwner && count($boardsList) > 1): ?>
                <button type="button" onclick="wbDeleteCurrentBoard()"
                        class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 hover:bg-rose-100 font-semibold cursor-pointer"
                        title="Dieses Whiteboard löschen">
                    🗑️
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isBoardOwner): ?>
    <!-- MODAL: GEZIELTE BENUTZER-FREIGABE FÜR DIESES WHITEBOARD -->
    <div id="wbShareModal" class="hidden fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4" onclick="if(event.target===this)wbCloseShareModal()">
        <div class="mc-card w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl p-5 sm:p-6 space-y-4">
            <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                <div>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">👥 Whiteboard gezielt freigeben</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Wähle aus, welche Benutzer dieses Whiteboard sehen und live mitzeichnen dürfen. Ohne Auswahl ist das Board <strong>🔒 komplett privat</strong>.
                    </p>
                </div>
                <button type="button" onclick="wbCloseShareModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-white text-lg px-2 cursor-pointer">✕</button>
            </div>

            <?php if (empty($allOtherUsers)): ?>
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800 text-xs text-slate-500 text-center">
                    Es sind aktuell keine weiteren Benutzerkonten in der Cloud angelegt.
                </div>
            <?php else: ?>
                <div class="flex items-center justify-between gap-2">
                    <input
                        type="search"
                        id="wbShareUserFilter"
                        oninput="wbFilterShareUsers(this.value)"
                        placeholder="Benutzer suchen…"
                        class="flex-1 px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs"
                    >
                    <button type="button" onclick="wbToggleAllShareUsers(true)" class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">Alle</button>
                    <button type="button" onclick="wbToggleAllShareUsers(false)" class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 cursor-pointer">Keine (Privat)</button>
                </div>

                <div id="wbShareUserList" class="max-h-60 overflow-y-auto divide-y divide-slate-200/70 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl">
                    <?php foreach ($allOtherUsers as $uRow): ?>
                        <?php $uidItem = (int)$uRow['id']; $isChecked = in_array($uidItem, $currentSharedUserIds, true); ?>
                        <label
                            data-wb-user-name="<?= e(mb_strtolower((string)$uRow['username'])) ?>"
                            class="wb-share-user-row flex items-center justify-between gap-3 px-3.5 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-800/70 cursor-pointer text-xs sm:text-sm"
                        >
                            <span class="flex items-center gap-2.5 font-medium text-slate-800 dark:text-slate-100">
                                <span class="w-6 h-6 rounded-full bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300 font-bold text-xs flex items-center justify-center">
                                    <?= e(mb_strtoupper(mb_substr((string)$uRow['username'], 0, 1))) ?>
                                </span>
                                <span><?= e((string)$uRow['username']) ?></span>
                            </span>
                            <input
                                type="checkbox"
                                value="<?= $uidItem ?>"
                                <?= $isChecked ? 'checked' : '' ?>
                                class="wb-share-user-cb w-4 h-4 rounded text-brand-600 focus:ring-brand-500"
                            >
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="wbCloseShareModal()" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 cursor-pointer">
                    Abbrechen
                </button>
                <button type="button" onclick="wbSaveSharedUsers()" class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-xs cursor-pointer">
                    💾 Freigabe speichern
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- WERKZEUGLEISTE (TOOLBAR) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-2.5 sm:p-3 shadow-xs flex flex-wrap items-center justify-between gap-2">
        <!-- Zeichenwerkzeuge -->
        <div class="flex flex-wrap items-center gap-1">
            <?php
            $tools = [
                ['id' => 'select',      'icon' => '🖐️', 'label' => 'Auswahl / Bewegen (V)'],
                ['id' => 'pen',         'icon' => '✏️', 'label' => 'Freihand-Stift (P)'],
                ['id' => 'highlighter', 'icon' => '🖍️', 'label' => 'Textmarker (H)'],
                ['id' => 'line',        'icon' => '📏', 'label' => 'Gerade Linie (L)'],
                ['id' => 'arrow',       'icon' => '↗️', 'label' => 'Pfeil / Verbindung (A)'],
                ['id' => 'rect',        'icon' => '▭',  'label' => 'Rechteck / Box (R)'],
                ['id' => 'ellipse',     'icon' => '◯',  'label' => 'Kreis / Ellipse (C)'],
                ['id' => 'diamond',     'icon' => '◇',  'label' => 'Raute / Entscheidung (D)'],
                ['id' => 'sticky',      'icon' => '🗒️', 'label' => 'Klebezettel / Haftnotiz (S)'],
                ['id' => 'text',        'icon' => '🔤', 'label' => 'Textfeld (T)'],
                ['id' => 'eraser',      'icon' => '🧽', 'label' => 'Element-Radierer (E)'],
            ];
            foreach ($tools as $idx => $t):
                $active = $t['id'] === 'pen';
            ?>
                <button
                    type="button"
                    id="wbToolBtn-<?= e($t['id']) ?>"
                    onclick="wbSelectTool('<?= e($t['id']) ?>')"
                    title="<?= e($t['label']) ?>"
                    class="wb-tool-btn px-2.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1 <?= $active ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span class="text-sm"><?= $t['icon'] ?></span>
                    <span class="hidden xl:inline"><?= e(explode(' ', $t['label'])[0]) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Farben, Strichstärke, Raster & Undo/Redo -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <!-- Schnellfarben -->
            <div class="flex items-center gap-1 px-2 py-1 rounded-xl bg-slate-100 dark:bg-slate-800">
                <?php
                $palette = ['#1e293b', '#2563eb', '#dc2626', '#16a34a', '#d97706', '#9333ea', '#0891b2', '#fef08a'];
                foreach ($palette as $hex):
                ?>
                    <button
                        type="button"
                        onclick="wbSetColor('<?= $hex ?>')"
                        class="w-5 h-5 rounded-full border-2 border-white dark:border-slate-700 shadow-2xs cursor-pointer transition-transform hover:scale-110"
                        style="background-color: <?= $hex ?>;"
                        title="Farbe <?= $hex ?>"
                    ></button>
                <?php endforeach; ?>
                <input type="color" id="wbColorPicker" value="#2563eb" onchange="wbSetColor(this.value)"
                       class="w-6 h-6 rounded cursor-pointer border-0 bg-transparent p-0" title="Freie Farbwahl">
            </div>

            <!-- Strichstärke -->
            <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-slate-100 dark:bg-slate-800">
                <span class="text-slate-500 dark:text-slate-400">Stärke:</span>
                <input type="range" id="wbStrokeSize" min="1" max="14" value="3" class="w-16 accent-brand-600 cursor-pointer">
            </div>

            <!-- Hintergrund-Stil -->
            <select id="wbBgStyleSelect" onchange="wbChangeBgStyle(this.value)"
                    class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold cursor-pointer">
                <option value="dots" <?= ($activeBoardFull['bg_style'] ?? 'dots') === 'dots' ? 'selected' : '' ?>>⠿ Punktraster</option>
                <option value="grid" <?= ($activeBoardFull['bg_style'] ?? 'dots') === 'grid' ? 'selected' : '' ?>># Kariert</option>
                <option value="blank" <?= ($activeBoardFull['bg_style'] ?? 'dots') === 'blank' ? 'selected' : '' ?>>⬜ Blanko</option>
            </select>

            <!-- Undo / Redo / Zoom / Leeren -->
            <div class="flex items-center gap-1">
                <button type="button" onclick="wbUndo()" title="Rückgängig (Strg+Z)"
                        class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold cursor-pointer">↩️</button>
                <button type="button" onclick="wbRedo()" title="Wiederholen (Strg+Y)"
                        class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold cursor-pointer">↪️</button>
                <button type="button" onclick="wbResetZoom()" id="wbZoomBadge" title="Zoom auf 100 % zurücksetzen"
                        class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-mono font-bold cursor-pointer">100%</button>
                <button type="button" onclick="wbClearAll()" title="Gesamte Zeichenfläche leeren"
                        class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 hover:bg-rose-100 font-bold cursor-pointer">🧹 Leeren</button>
            </div>
        </div>
    </div>

    <!-- ZEICHENFLÄCHE (CANVAS) -->
    <div class="relative rounded-2xl overflow-hidden border-2 border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 shadow-lg select-none">
        <canvas id="wbCanvas" width="1280" height="720" class="w-full h-[540px] sm:h-[640px] block touch-none cursor-crosshair"></canvas>

        <!-- Unten links: Kurzhilfe -->
        <div class="absolute bottom-3 left-3 pointer-events-none px-3 py-1.5 rounded-xl bg-slate-900/75 text-white text-[11px] backdrop-blur-xs">
            💡 <strong>Tipp:</strong> Doppelklick auf Klebezettel/Text zum Bearbeiten · Mausrad = Zoom · Mittlere Maustaste (oder Leertaste + Ziehen) = Ansicht verschieben
        </div>
    </div>
</div>

<script>
const WB_CSRF     = <?= json_encode($csrfToken) ?>;
const WB_BOARD_ID = <?= (int)$activeBoardFull['id'] ?>;
const WB_USER_ID  = <?= (int)$userId ?>;
const WB_USERNAME = <?= json_encode($username) ?>;

let wbElements    = [];
try {
    const parsed = JSON.parse(<?= json_encode((string)$activeBoardFull['elements_json']) ?>);
    if (Array.isArray(parsed)) wbElements = parsed;
} catch (e) {
    wbElements = [];
}

let wbTool         = 'pen';
let wbColor        = '#2563eb';
let wbBgStyle      = <?= json_encode((string)($activeBoardFull['bg_style'] ?? 'dots')) ?>;
let wbUndoStack    = [];
let wbRedoStack    = [];
let wbSelectedId   = null;
let wbIsDrawing    = false;
let wbIsPanning    = false;
let wbSpaceDown    = false;
let wbCurrentShape = null;
let wbDragOffset   = { x: 0, y: 0 };
let wbPanStart     = { x: 0, y: 0 };
let wbCam          = { x: 0, y: 0, zoom: 1 };
let wbMyCursor     = { x: 0, y: 0 };
let wbPeers        = [];
let wbSaveTimer    = null;
let wbDirty        = false;

const wbCanvas = document.getElementById('wbCanvas');
const wbCtx    = wbCanvas.getContext('2d');

function wbResizeCanvas() {
    const rect = wbCanvas.getBoundingClientRect();
    if (rect.width > 0 && rect.height > 0) {
        wbCanvas.width  = Math.round(rect.width);
        wbCanvas.height = Math.round(rect.height);
        wbRender();
    }
}
window.addEventListener('resize', wbResizeCanvas);

function wbPushUndo() {
    wbUndoStack.push(JSON.stringify(wbElements));
    if (wbUndoStack.length > 40) wbUndoStack.shift();
    wbRedoStack = [];
}

function wbUndo() {
    if (!wbUndoStack.length) return;
    wbRedoStack.push(JSON.stringify(wbElements));
    wbElements = JSON.parse(wbUndoStack.pop());
    wbSelectedId = null;
    wbRender();
    wbScheduleSave();
}

function wbRedo() {
    if (!wbRedoStack.length) return;
    wbUndoStack.push(JSON.stringify(wbElements));
    wbElements = JSON.parse(wbRedoStack.pop());
    wbSelectedId = null;
    wbRender();
    wbScheduleSave();
}

function wbSelectTool(toolId) {
    wbTool = toolId;
    document.querySelectorAll('.wb-tool-btn').forEach((b) => {
        b.className = 'wb-tool-btn px-2.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700';
    });
    const act = document.getElementById('wbToolBtn-' + toolId);
    if (act) {
        act.className = 'wb-tool-btn px-2.5 py-1.5 rounded-xl text-xs font-bold transition-all cursor-pointer flex items-center gap-1 bg-brand-600 text-white shadow-xs';
    }
    wbCanvas.style.cursor = toolId === 'select' ? 'default' : 'crosshair';
    if (toolId === 'sticky' && wbColor === '#2563eb') {
        wbSetColor('#fef08a');
    }
    wbRender();
}

function wbSetColor(hex) {
    wbColor = hex;
    const picker = document.getElementById('wbColorPicker');
    if (picker) picker.value = hex;
    if (wbSelectedId) {
        const el = wbElements.find((e) => e.id === wbSelectedId);
        if (el) {
            wbPushUndo();
            el.color = hex;
            wbRender();
            wbScheduleSave();
        }
    }
}

function wbChangeBgStyle(style) {
    wbBgStyle = style;
    wbRender();
    wbScheduleSave();
}

function wbResetZoom() {
    wbCam = { x: 0, y: 0, zoom: 1 };
    const b = document.getElementById('wbZoomBadge');
    if (b) b.textContent = '100%';
    wbRender();
}

function wbClearAll() {
    if (!wbElements.length) return;
    if (!confirm('Möchtest du wirklich alle Elemente auf diesem Whiteboard löschen?')) return;
    wbPushUndo();
    wbElements = [];
    wbSelectedId = null;
    wbRender();
    wbScheduleSave();
}

function wbScreenToWorld(sx, sy) {
    return {
        x: (sx - wbCam.x) / wbCam.zoom,
        y: (sy - wbCam.y) / wbCam.zoom,
    };
}

function wbGetEventPos(e) {
    const r = wbCanvas.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return {
        sx: clientX - r.left,
        sy: clientY - r.top,
        ...wbScreenToWorld(clientX - r.left, clientY - r.top),
    };
}

function wbHitTest(wx, wy) {
    for (let i = wbElements.length - 1; i >= 0; i--) {
        const el = wbElements[i];
        if (el.type === 'sticky' || el.type === 'rect' || el.type === 'ellipse' || el.type === 'diamond') {
            const xMin = Math.min(el.x, el.x + (el.w || 0));
            const xMax = Math.max(el.x, el.x + (el.w || 0));
            const yMin = Math.min(el.y, el.y + (el.h || 0));
            const yMax = Math.max(el.y, el.y + (el.h || 0));
            if (wx >= xMin - 6 && wx <= xMax + 6 && wy >= yMin - 6 && wy <= yMax + 6) {
                return el;
            }
        } else if (el.type === 'text') {
            const w = Math.max(60, (el.text || '').length * 10);
            if (wx >= el.x - 6 && wx <= el.x + w && wy >= el.y - 24 && wy <= el.y + 10) {
                return el;
            }
        } else if (el.type === 'line' || el.type === 'arrow') {
            const d = wbPointToSegDist(wx, wy, el.x1, el.y1, el.x2, el.y2);
            if (d <= 10) return el;
        } else if ((el.type === 'pen' || el.type === 'highlighter') && Array.isArray(el.pts)) {
            for (const p of el.pts) {
                if (Math.hypot(wx - p[0], wy - p[1]) <= 12) return el;
            }
        }
    }
    return null;
}

function wbPointToSegDist(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len2 = dx * dx + dy * dy;
    if (len2 === 0) return Math.hypot(px - x1, py - y1);
    const t = Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / len2));
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
}

wbCanvas.addEventListener('pointerdown', (e) => {
    const pos = wbGetEventPos(e);
    wbMyCursor = { x: Math.round(pos.x), y: Math.round(pos.y) };

    if (e.button === 1 || wbSpaceDown) {
        wbIsPanning = true;
        wbPanStart  = { x: pos.sx - wbCam.x, y: pos.sy - wbCam.y };
        wbCanvas.style.cursor = 'grabbing';
        return;
    }

    const strokeSize = Number(document.getElementById('wbStrokeSize')?.value || 3);
    const newId = 'el_' + Date.now() + '_' + Math.floor(Math.random() * 9999);

    if (wbTool === 'select') {
        const hit = wbHitTest(pos.x, pos.y);
        wbSelectedId = hit ? hit.id : null;
        if (hit) {
            wbPushUndo();
            wbIsDrawing = true;
            if (hit.x !== undefined) {
                wbDragOffset = { x: pos.x - hit.x, y: pos.y - hit.y };
            } else if (hit.x1 !== undefined) {
                wbDragOffset = { x: pos.x, y: pos.y };
            } else if (Array.isArray(hit.pts)) {
                wbDragOffset = { x: pos.x, y: pos.y };
            }
        }
        wbRender();
        return;
    }

    if (wbTool === 'eraser') {
        const hit = wbHitTest(pos.x, pos.y);
        if (hit) {
            wbPushUndo();
            wbElements = wbElements.filter((item) => item.id !== hit.id);
            wbRender();
            wbScheduleSave();
        }
        wbIsDrawing = true;
        return;
    }

    if (wbTool === 'sticky') {
        const text = prompt('Text für die Haftnotiz (Klebezettel) eingeben:', 'Neue Idee…');
        if (text === null) return;
        wbPushUndo();
        const stickyCol = ['#fef08a', '#bbf7d0', '#bfdbfe', '#fbcfe8', '#fed7aa'].includes(wbColor) ? wbColor : '#fef08a';
        const item = {
            id: newId,
            type: 'sticky',
            x: Math.round(pos.x - 95),
            y: Math.round(pos.y - 65),
            w: 195,
            h: 135,
            color: stickyCol,
            text: text.trim() || 'Notiz',
        };
        wbElements.push(item);
        wbSelectedId = item.id;
        wbSelectTool('select');
        wbRender();
        wbScheduleSave();
        return;
    }

    if (wbTool === 'text') {
        const text = prompt('Text für die Beschriftung eingeben:', '');
        if (!text || !text.trim()) return;
        wbPushUndo();
        const item = {
            id: newId,
            type: 'text',
            x: Math.round(pos.x),
            y: Math.round(pos.y),
            color: wbColor,
            size: Math.max(14, strokeSize * 5),
            text: text.trim(),
        };
        wbElements.push(item);
        wbSelectedId = item.id;
        wbSelectTool('select');
        wbRender();
        wbScheduleSave();
        return;
    }

    wbPushUndo();
    wbIsDrawing = true;

    if (wbTool === 'pen' || wbTool === 'highlighter') {
        wbCurrentShape = {
            id: newId,
            type: wbTool,
            color: wbColor,
            size: wbTool === 'highlighter' ? Math.max(14, strokeSize * 4) : strokeSize,
            pts: [[Math.round(pos.x), Math.round(pos.y)]],
        };
        wbElements.push(wbCurrentShape);
    } else if (wbTool === 'line' || wbTool === 'arrow') {
        wbCurrentShape = {
            id: newId,
            type: wbTool,
            x1: Math.round(pos.x),
            y1: Math.round(pos.y),
            x2: Math.round(pos.x),
            y2: Math.round(pos.y),
            color: wbColor,
            size: strokeSize,
        };
        wbElements.push(wbCurrentShape);
    } else if (wbTool === 'rect' || wbTool === 'ellipse' || wbTool === 'diamond') {
        wbCurrentShape = {
            id: newId,
            type: wbTool,
            x: Math.round(pos.x),
            y: Math.round(pos.y),
            w: 1,
            h: 1,
            color: wbColor,
            size: strokeSize,
        };
        wbElements.push(wbCurrentShape);
    }
    wbRender();
});

wbCanvas.addEventListener('pointermove', (e) => {
    const pos = wbGetEventPos(e);
    wbMyCursor = { x: Math.round(pos.x), y: Math.round(pos.y) };

    if (wbIsPanning) {
        wbCam.x = pos.sx - wbPanStart.x;
        wbCam.y = pos.sy - wbPanStart.y;
        wbRender();
        return;
    }

    if (!wbIsDrawing) return;

    if (wbTool === 'select' && wbSelectedId) {
        const el = wbElements.find((it) => it.id === wbSelectedId);
        if (!el) return;
        if (el.x !== undefined) {
            el.x = Math.round(pos.x - wbDragOffset.x);
            el.y = Math.round(pos.y - wbDragOffset.y);
        } else if (el.x1 !== undefined) {
            const dx = Math.round(pos.x - wbDragOffset.x);
            const dy = Math.round(pos.y - wbDragOffset.y);
            el.x1 += dx; el.y1 += dy;
            el.x2 += dx; el.y2 += dy;
            wbDragOffset = { x: pos.x, y: pos.y };
        } else if (Array.isArray(el.pts)) {
            const dx = Math.round(pos.x - wbDragOffset.x);
            const dy = Math.round(pos.y - wbDragOffset.y);
            el.pts = el.pts.map((p) => [p[0] + dx, p[1] + dy]);
            wbDragOffset = { x: pos.x, y: pos.y };
        }
        wbDirty = true;
        wbRender();
        return;
    }

    if (wbTool === 'eraser') {
        const hit = wbHitTest(pos.x, pos.y);
        if (hit) {
            wbElements = wbElements.filter((it) => it.id !== hit.id);
            wbDirty = true;
            wbRender();
        }
        return;
    }

    if (!wbCurrentShape) return;

    if (wbCurrentShape.type === 'pen' || wbCurrentShape.type === 'highlighter') {
        const pts = wbCurrentShape.pts;
        const last = pts[pts.length - 1];
        if (!last || Math.hypot(pos.x - last[0], pos.y - last[1]) >= 2.5) {
            pts.push([Math.round(pos.x), Math.round(pos.y)]);
        }
    } else if (wbCurrentShape.type === 'line' || wbCurrentShape.type === 'arrow') {
        wbCurrentShape.x2 = Math.round(pos.x);
        wbCurrentShape.y2 = Math.round(pos.y);
    } else if (wbCurrentShape.type === 'rect' || wbCurrentShape.type === 'ellipse' || wbCurrentShape.type === 'diamond') {
        wbCurrentShape.w = Math.round(pos.x - wbCurrentShape.x);
        wbCurrentShape.h = Math.round(pos.y - wbCurrentShape.y);
    }

    wbDirty = true;
    wbRender();
});

window.addEventListener('pointerup', () => {
    if (wbIsPanning) {
        wbIsPanning = false;
        wbCanvas.style.cursor = wbTool === 'select' ? 'default' : 'crosshair';
    }
    if (wbIsDrawing) {
        wbIsDrawing = false;
        wbCurrentShape = null;
        if (wbDirty) {
            wbDirty = false;
            wbScheduleSave();
        }
    }
});

// Doppelklick zum Bearbeiten von Klebezetteln, Formen oder Texten
wbCanvas.addEventListener('dblclick', (e) => {
    const pos = wbGetEventPos(e);
    const hit = wbHitTest(pos.x, pos.y);
    if (!hit) return;
    if (hit.type === 'sticky' || hit.type === 'text' || hit.type === 'rect' || hit.type === 'diamond') {
        const updated = prompt('Text bearbeiten:', hit.text || '');
        if (updated !== null) {
            wbPushUndo();
            hit.text = updated;
            wbRender();
            wbScheduleSave();
        }
    }
});

// Mausrad-Zoom auf der Zeichenfläche
wbCanvas.addEventListener('wheel', (e) => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.1 : 0.9;
    const newZoom = Math.min(2.8, Math.max(0.35, wbCam.zoom * factor));
    const r = wbCanvas.getBoundingClientRect();
    const mx = e.clientX - r.left;
    const my = e.clientY - r.top;

    wbCam.x = mx - (mx - wbCam.x) * (newZoom / wbCam.zoom);
    wbCam.y = my - (my - wbCam.y) * (newZoom / wbCam.zoom);
    wbCam.zoom = newZoom;

    const badge = document.getElementById('wbZoomBadge');
    if (badge) badge.textContent = Math.round(wbCam.zoom * 100) + '%';
    wbRender();
}, { passive: false });

// Tastenkürzel (Strg+Z, Strg+Y, Entf, Leertaste)
window.addEventListener('keydown', (e) => {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) return;
    if (e.code === 'Space') {
        wbSpaceDown = true;
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        wbUndo();
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        wbRedo();
    } else if ((e.key === 'Delete' || e.key === 'Backspace') && wbSelectedId) {
        e.preventDefault();
        wbPushUndo();
        wbElements = wbElements.filter((it) => it.id !== wbSelectedId);
        wbSelectedId = null;
        wbRender();
        wbScheduleSave();
    }
});
window.addEventListener('keyup', (e) => {
    if (e.code === 'Space') wbSpaceDown = false;
});

function wbDrawWrappedText(ctx, text, x, y, maxWidth, lineHeight) {
    const paragraphs = String(text || '').split('\n');
    let curY = y;
    for (const para of paragraphs) {
        const words = para.split(' ');
        let line = '';
        for (let n = 0; n < words.length; n++) {
            const testLine = line + words[n] + ' ';
            if (ctx.measureText(testLine).width > maxWidth && n > 0) {
                ctx.fillText(line.trim(), x, curY);
                line = words[n] + ' ';
                curY += lineHeight;
            } else {
                line = testLine;
            }
        }
        ctx.fillText(line.trim(), x, curY);
        curY += lineHeight;
    }
}

function wbRender(exportClean = false) {
    const W = wbCanvas.width;
    const H = wbCanvas.height;
    const isDark = document.documentElement.classList.contains('dark') && !exportClean;

    // 1. Hintergrund
    wbCtx.fillStyle = isDark ? '#0f172a' : '#f8fafc';
    wbCtx.fillRect(0, 0, W, H);

    wbCtx.save();
    wbCtx.translate(wbCam.x, wbCam.y);
    wbCtx.scale(wbCam.zoom, wbCam.zoom);

    // 2. Punktraster oder Karo-Gitter
    const step = 28;
    const startX = Math.floor((-wbCam.x / wbCam.zoom) / step) * step;
    const endX   = startX + (W / wbCam.zoom) + step * 2;
    const startY = Math.floor((-wbCam.y / wbCam.zoom) / step) * step;
    const endY   = startY + (H / wbCam.zoom) + step * 2;

    if (wbBgStyle === 'dots') {
        wbCtx.fillStyle = isDark ? 'rgba(148, 163, 184, 0.22)' : 'rgba(100, 116, 139, 0.28)';
        for (let gx = startX; gx < endX; gx += step) {
            for (let gy = startY; gy < endY; gy += step) {
                wbCtx.fillRect(gx, gy, 1.8, 1.8);
            }
        }
    } else if (wbBgStyle === 'grid') {
        wbCtx.strokeStyle = isDark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(100, 116, 139, 0.15)';
        wbCtx.lineWidth = 1;
        wbCtx.beginPath();
        for (let gx = startX; gx < endX; gx += step) {
            wbCtx.moveTo(gx, startY);
            wbCtx.lineTo(gx, endY);
        }
        for (let gy = startY; gy < endY; gy += step) {
            wbCtx.moveTo(startX, gy);
            wbCtx.lineTo(endX, gy);
        }
        wbCtx.stroke();
    }

    // 3. Alle Whiteboard-Elemente zeichnen
    for (const el of wbElements) {
        wbCtx.save();
        const isSel = !exportClean && el.id === wbSelectedId;

        if (el.type === 'pen' || el.type === 'highlighter') {
            if (Array.isArray(el.pts) && el.pts.length > 0) {
                wbCtx.strokeStyle = el.color || '#2563eb';
                wbCtx.lineWidth   = el.size || 3;
                wbCtx.lineCap     = 'round';
                wbCtx.lineJoin    = 'round';
                if (el.type === 'highlighter') {
                    wbCtx.globalAlpha = 0.36;
                }
                wbCtx.beginPath();
                wbCtx.moveTo(el.pts[0][0], el.pts[0][1]);
                for (let i = 1; i < el.pts.length; i++) {
                    wbCtx.lineTo(el.pts[i][0], el.pts[i][1]);
                }
                wbCtx.stroke();
            }
        } else if (el.type === 'line' || el.type === 'arrow') {
            wbCtx.strokeStyle = el.color || '#2563eb';
            wbCtx.fillStyle   = el.color || '#2563eb';
            wbCtx.lineWidth   = el.size || 3;
            wbCtx.lineCap     = 'round';
            wbCtx.beginPath();
            wbCtx.moveTo(el.x1, el.y1);
            wbCtx.lineTo(el.x2, el.y2);
            wbCtx.stroke();

            if (el.type === 'arrow') {
                const ang = Math.atan2(el.y2 - el.y1, el.x2 - el.x1);
                const headLen = 11 + (el.size || 3) * 1.4;
                wbCtx.beginPath();
                wbCtx.moveTo(el.x2, el.y2);
                wbCtx.lineTo(el.x2 - headLen * Math.cos(ang - 0.45), el.y2 - headLen * Math.sin(ang - 0.45));
                wbCtx.lineTo(el.x2 - headLen * Math.cos(ang + 0.45), el.y2 - headLen * Math.sin(ang + 0.45));
                wbCtx.closePath();
                wbCtx.fill();
            }
        } else if (el.type === 'rect') {
            wbCtx.fillStyle   = (el.color || '#2563eb') + '18';
            wbCtx.strokeStyle = el.color || '#2563eb';
            wbCtx.lineWidth   = el.size || 2.5;
            wbCtx.beginPath();
            wbCtx.roundRect(el.x, el.y, el.w, el.h, 10);
            wbCtx.fill();
            wbCtx.stroke();
            if (el.text) {
                wbCtx.fillStyle = isDark ? '#f8fafc' : '#0f172a';
                wbCtx.font = 'bold 13px sans-serif';
                wbCtx.textAlign = 'center';
                wbCtx.fillText(el.text, el.x + el.w / 2, el.y + el.h / 2 + 4);
            }
        } else if (el.type === 'ellipse') {
            wbCtx.fillStyle   = (el.color || '#2563eb') + '18';
            wbCtx.strokeStyle = el.color || '#2563eb';
            wbCtx.lineWidth   = el.size || 2.5;
            wbCtx.beginPath();
            wbCtx.ellipse(el.x + el.w / 2, el.y + el.h / 2, Math.abs(el.w / 2), Math.abs(el.h / 2), 0, 0, Math.PI * 2);
            wbCtx.fill();
            wbCtx.stroke();
        } else if (el.type === 'diamond') {
            const cx = el.x + el.w / 2;
            const cy = el.y + el.h / 2;
            wbCtx.fillStyle   = (el.color || '#d97706') + '20';
            wbCtx.strokeStyle = el.color || '#d97706';
            wbCtx.lineWidth   = el.size || 2.5;
            wbCtx.beginPath();
            wbCtx.moveTo(cx, el.y);
            wbCtx.lineTo(el.x + el.w, cy);
            wbCtx.lineTo(cx, el.y + el.h);
            wbCtx.lineTo(el.x, cy);
            wbCtx.closePath();
            wbCtx.fill();
            wbCtx.stroke();
            if (el.text) {
                wbCtx.fillStyle = isDark ? '#f8fafc' : '#0f172a';
                wbCtx.font = 'bold 12px sans-serif';
                wbCtx.textAlign = 'center';
                wbCtx.fillText(el.text, cx, cy + 4);
            }
        } else if (el.type === 'sticky') {
            wbCtx.shadowColor   = 'rgba(15, 23, 42, 0.18)';
            wbCtx.shadowBlur    = 12;
            wbCtx.shadowOffsetY = 5;
            wbCtx.fillStyle     = el.color || '#fef08a';
            wbCtx.beginPath();
            wbCtx.roundRect(el.x, el.y, el.w || 195, el.h || 135, 10);
            wbCtx.fill();

            wbCtx.shadowBlur    = 0;
            wbCtx.shadowOffsetY = 0;
            wbCtx.strokeStyle   = 'rgba(15, 23, 42, 0.14)';
            wbCtx.lineWidth     = 1;
            wbCtx.stroke();

            wbCtx.fillStyle = '#0f172a';
            wbCtx.font = '600 13px sans-serif';
            wbCtx.textAlign = 'left';
            wbDrawWrappedText(wbCtx, el.text || '', el.x + 14, el.y + 26, (el.w || 195) - 28, 18);
        } else if (el.type === 'text') {
            wbCtx.fillStyle = el.color || (isDark ? '#f8fafc' : '#0f172a');
            wbCtx.font = `bold ${el.size || 18}px sans-serif`;
            wbCtx.textAlign = 'left';
            wbCtx.fillText(el.text || '', el.x, el.y);
        }

        // Auswahl-Rahmen zeichnen, falls ausgewählt
        if (isSel && el.x !== undefined) {
            wbCtx.strokeStyle = '#3b82f6';
            wbCtx.lineWidth = 1.5;
            wbCtx.setLineDash([5, 4]);
            wbCtx.strokeRect(el.x - 5, el.y - 5, (el.w || 120) + 10, (el.h || 30) + 10);
            wbCtx.setLineDash([]);
        }
        wbCtx.restore();
    }

    // 4. Live-Mauszeiger anderer Cloud-Benutzer auf diesem Board zeichnen
    if (!exportClean) {
        for (const p of wbPeers) {
            wbCtx.save();
            wbCtx.fillStyle = '#ec4899';
            wbCtx.beginPath();
            wbCtx.arc(p.cursor_x, p.cursor_y, 5, 0, Math.PI * 2);
            wbCtx.fill();

            wbCtx.font = 'bold 11px sans-serif';
            const tw = wbCtx.measureText(p.username).width + 12;
            wbCtx.fillStyle = '#ec4899';
            wbCtx.beginPath();
            wbCtx.roundRect(p.cursor_x + 8, p.cursor_y - 10, tw, 18, 6);
            wbCtx.fill();
            wbCtx.fillStyle = '#ffffff';
            wbCtx.fillText(p.username, p.cursor_x + 14, p.cursor_y + 3);
            wbCtx.restore();
        }
    }

    wbCtx.restore();
}

function wbScheduleSave() {
    const badge = document.getElementById('wbSaveStatusBadge');
    if (badge) {
        badge.textContent = '⏳ Speichert…';
        badge.className = 'px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 dark:bg-amber-950/70 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800';
    }
    if (wbSaveTimer) clearTimeout(wbSaveTimer);
    wbSaveTimer = setTimeout(wbSaveNow, 450);
}

async function wbSaveNow() {
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'save_board');
    fd.append('board_id', String(WB_BOARD_ID));
    fd.append('title', document.getElementById('wbTitleInput')?.value || 'Whiteboard');
    fd.append('bg_style', wbBgStyle);
    fd.append('elements_json', JSON.stringify(wbElements));

    try {
        const res = await fetch('whiteboard.php', { method: 'POST', body: fd });
        const d   = await res.json();
        const badge = document.getElementById('wbSaveStatusBadge');
        if (d && d.ok && badge) {
            badge.textContent = '✓ Gespeichert (' + d.updated_at + ')';
            badge.className = 'px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800';
        }
    } catch (e) {}
}

function wbOpenShareModal() {
    const modal = document.getElementById('wbShareModal');
    if (modal) modal.classList.remove('hidden');
}

function wbCloseShareModal() {
    const modal = document.getElementById('wbShareModal');
    if (modal) modal.classList.add('hidden');
}

function wbFilterShareUsers(q) {
    const term = String(q || '').trim().toLowerCase();
    document.querySelectorAll('.wb-share-user-row').forEach(row => {
        const uname = row.getAttribute('data-wb-user-name') || '';
        row.style.display = (term === '' || uname.includes(term)) ? '' : 'none';
    });
}

function wbToggleAllShareUsers(checked) {
    document.querySelectorAll('.wb-share-user-cb').forEach(cb => {
        cb.checked = !!checked;
    });
}

async function wbSaveSharedUsers() {
    const selectedIds = Array.from(document.querySelectorAll('.wb-share-user-cb:checked')).map(cb => parseInt(cb.value, 10)).filter(Boolean);
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'save_shares');
    fd.append('board_id', String(WB_BOARD_ID));
    fd.append('user_ids_json', JSON.stringify(selectedIds));

    try {
        const r = await fetch('whiteboard.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d && d.ok) {
            const cnt = d.shared_count || 0;
            const iconEl = document.getElementById('wbShareBtnIcon');
            const lblEl  = document.getElementById('wbShareBtnLabel');
            const btnEl  = document.getElementById('wbOpenShareModalBtn');
            if (iconEl) iconEl.textContent = cnt > 0 ? '👥' : '🔒';
            if (lblEl) lblEl.textContent   = cnt > 0 ? `Freigegeben (${cnt})` : 'Privat · Freigeben…';
            if (btnEl) {
                btnEl.className = cnt > 0
                    ? 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-bold border transition-colors cursor-pointer bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800'
                    : 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl font-bold border transition-colors cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-300 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700';
            }
            wbCloseShareModal();
        } else {
            alert(d?.error || 'Fehler beim Speichern der Freigabe.');
        }
    } catch (e) {}
}

async function wbPollLiveState() {
    if (wbIsDrawing) return;
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'poll_board');
    fd.append('board_id', String(WB_BOARD_ID));
    fd.append('cursor_x', String(wbMyCursor.x));
    fd.append('cursor_y', String(wbMyCursor.y));

    try {
        const r = await fetch('whiteboard.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d || !d.ok) return;

        wbPeers = Array.isArray(d.peers) ? d.peers : [];
        const peerBox = document.getElementById('wbLivePeersBox');
        if (peerBox) {
            peerBox.innerHTML = wbPeers.map((p) =>
                `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-pink-100 dark:bg-pink-950 text-pink-700 dark:text-pink-300">🖊️ ${p.username}</span>`
            ).join('');
        }

        if (!wbIsDrawing && !wbSaveTimer && d.elements_json) {
            const remoteEls = JSON.parse(d.elements_json);
            if (Array.isArray(remoteEls) && JSON.stringify(remoteEls) !== JSON.stringify(wbElements)) {
                wbElements = remoteEls;
            }
        }
        wbRender();
    } catch (e) {}
}

async function wbCreateNewBoard() {
    const title = prompt('Name für das neue Whiteboard:', 'Projekt-Skizze');
    if (!title || !title.trim()) return;
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'create_board');
    fd.append('title', title.trim());
    fd.append('is_shared', '1');
    fd.append('elements_json', '[]');

    const r = await fetch('whiteboard.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d && d.ok && d.board_id) {
        window.location.href = 'whiteboard.php?id=' + d.board_id;
    }
}

async function wbDeleteCurrentBoard() {
    if (!confirm('Möchtest du dieses Whiteboard wirklich unwiderruflich löschen?')) return;
    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'delete_board');
    fd.append('board_id', String(WB_BOARD_ID));
    const r = await fetch('whiteboard.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d && d.ok) {
        window.location.href = 'whiteboard.php';
    }
}

function wbApplyTemplate(tpl) {
    wbPushUndo();
    const nowId = Date.now();
    if (tpl === 'brainstorm') {
        wbElements.push(
            { id: 't1_' + nowId, type: 'rect',   x: 340, y: 210, w: 210, h: 75, color: '#2563eb', size: 3, text: '🎯 Hauptthema' },
            { id: 't2_' + nowId, type: 'sticky', x: 75,  y: 65,  w: 185, h: 115, color: '#fef08a', text: '💡 Idee 1:\nWas wollen wir erreichen?' },
            { id: 't3_' + nowId, type: 'sticky', x: 630, y: 65,  w: 185, h: 115, color: '#bbf7d0', text: '🚀 Idee 2:\nNächste Schritte' },
            { id: 't4_' + nowId, type: 'sticky', x: 75,  y: 335, w: 185, h: 115, color: '#bfdbfe', text: '🛠️ Idee 3:\nWerkzeuge & Technik' },
            { id: 't5_' + nowId, type: 'sticky', x: 630, y: 335, w: 185, h: 115, color: '#fbcfe8', text: '✅ Idee 4:\nOffene Fragen' },
            { id: 'a1_' + nowId, type: 'arrow',  x1: 340, y1: 230, x2: 265, y2: 140, color: '#64748b', size: 2.5 },
            { id: 'a2_' + nowId, type: 'arrow',  x1: 550, y1: 230, x2: 625, y2: 140, color: '#64748b', size: 2.5 },
            { id: 'a3_' + nowId, type: 'arrow',  x1: 340, y1: 270, x2: 265, y2: 365, color: '#64748b', size: 2.5 },
            { id: 'a4_' + nowId, type: 'arrow',  x1: 550, y1: 270, x2: 625, y2: 365, color: '#64748b', size: 2.5 }
        );
    } else if (tpl === 'flowchart') {
        wbElements.push(
            { id: 'f1_' + nowId, type: 'rect',    x: 70,  y: 200, w: 150, h: 65, color: '#16a34a', size: 3, text: '🟢 Start / Eingang' },
            { id: 'f2_' + nowId, type: 'arrow',   x1: 220, y1: 232, x2: 295, y2: 232, color: '#475569', size: 3 },
            { id: 'f3_' + nowId, type: 'rect',    x: 300, y: 200, w: 165, h: 65, color: '#2563eb', size: 3, text: '⚙️ Prüfung & Bearbeitung' },
            { id: 'f4_' + nowId, type: 'arrow',   x1: 465, y1: 232, x2: 535, y2: 232, color: '#475569', size: 3 },
            { id: 'f5_' + nowId, type: 'diamond', x: 540, y: 182, w: 150, h: 100, color: '#d97706', size: 3, text: 'Freigabe OK?' },
            { id: 'f6_' + nowId, type: 'arrow',   x1: 690, y1: 232, x2: 765, y2: 232, color: '#16a34a', size: 3 },
            { id: 'f7_' + nowId, type: 'rect',    x: 770, y: 200, w: 150, h: 65, color: '#9333ea', size: 3, text: '🏁 Abschluss' }
        );
    } else if (tpl === 'kanban') {
        wbElements.push(
            { id: 'k1_' + nowId, type: 'rect',   x: 60,  y: 50, w: 250, h: 420, color: '#64748b', size: 2, text: '' },
            { id: 'k2_' + nowId, type: 'rect',   x: 340, y: 50, w: 250, h: 420, color: '#2563eb', size: 2, text: '' },
            { id: 'k3_' + nowId, type: 'rect',   x: 620, y: 50, w: 250, h: 420, color: '#16a34a', size: 2, text: '' },
            { id: 'kt1_' + nowId, type: 'text',  x: 115, y: 82, color: '#475569', size: 18, text: '📥 Ideen / Offen' },
            { id: 'kt2_' + nowId, type: 'text',  x: 410, y: 82, color: '#2563eb', size: 18, text: '🔨 In Arbeit' },
            { id: 'kt3_' + nowId, type: 'text',  x: 700, y: 82, color: '#16a34a', size: 18, text: '✅ Erledigt' },
            { id: 'ks1_' + nowId, type: 'sticky', x: 85,  y: 110, w: 200, h: 115, color: '#fef08a', text: 'Aufgabe A:\nKonzept ausarbeiten' },
            { id: 'ks2_' + nowId, type: 'sticky', x: 365, y: 110, w: 200, h: 115, color: '#bfdbfe', text: 'Aufgabe B:\nPrototyp testen' },
            { id: 'ks3_' + nowId, type: 'sticky', x: 645, y: 110, w: 200, h: 115, color: '#bbf7d0', text: 'Aufgabe C:\nKick-off abgeschlossen' }
        );
    }
    wbRender();
    wbScheduleSave();
}

function wbExportPngDownload() {
    wbRender(true);
    const url = wbCanvas.toDataURL('image/png');
    wbRender(false);
    const a = document.createElement('a');
    a.href = url;
    a.download = (document.getElementById('wbTitleInput')?.value || 'Whiteboard') + '.png';
    a.click();
}

async function wbExportToCloudFiles() {
    wbRender(true);
    const dataUrl = wbCanvas.toDataURL('image/png');
    wbRender(false);

    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('csrf_token', WB_CSRF);
    fd.append('action', 'export_to_cloud');
    fd.append('title', document.getElementById('wbTitleInput')?.value || 'Whiteboard');
    fd.append('image_data_url', dataUrl);

    try {
        const r = await fetch('whiteboard.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d && d.ok) {
            alert('☁️ ' + d.message);
        } else {
            alert(d.error || 'Fehler beim Speichern in der Cloud.');
        }
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
    wbResizeCanvas();
    setInterval(wbPollLiveState, 3500);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
