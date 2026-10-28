<?php
/**
 * uWiiiMiniCloud – Notizwand / Pinboard (notes.php)
 * Digitale Haftnotizen mit Farbcodierung, Anpinnen, #Tags, Live-Suche,
 * Mikrofon-Sprachnotizen (Audio-Aufnahme + Sprache-zu-Text-Diktat),
 * Teilen mit Benutzern und 1-Klick-Export als Markdown-Datei in die Cloud.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo    = getDB();
$userId = currentUserId();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_notes'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Notizwand“ ist durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

// Selbstheilende Spalten-Migration für Sprachnotizen
try {
    $colsNotes = $pdo->query("SHOW COLUMNS FROM `notes`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('audio_file_id', $colsNotes, true)) {
        $pdo->exec("ALTER TABLE `notes` ADD COLUMN `audio_file_id` INT UNSIGNED DEFAULT NULL AFTER `is_pinned`");
        $pdo->exec("ALTER TABLE `notes` ADD COLUMN `audio_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `audio_file_id`");
    }
} catch (Throwable) {
    // Ignorieren falls bereits vorhanden
}

$visiblePeers = getVisibleUsersForUser($pdo, $userId);
$visiblePeerIds = array_map(static fn(array $u): int => (int)$u['id'], $visiblePeers);
$myGroups = getGroupsForUser($pdo, $userId);
$myGroupIds = array_map(static fn(array $g): int => (int)$g['id'], $myGroups);

$allowedColors = [
    'amber'   => ['label' => 'Gelb',    'bg' => 'rgba(245, 158, 11, 0.14)', 'border' => 'rgba(245, 158, 11, 0.45)', 'dot' => '#f59e0b'],
    'blue'    => ['label' => 'Blau',    'bg' => 'rgba(59, 130, 246, 0.14)', 'border' => 'rgba(59, 130, 246, 0.45)', 'dot' => '#3b82f6'],
    'emerald' => ['label' => 'Grün',    'bg' => 'rgba(16, 185, 129, 0.14)', 'border' => 'rgba(16, 185, 129, 0.45)', 'dot' => '#10b981'],
    'rose'    => ['label' => 'Rosa',    'bg' => 'rgba(244, 63, 94, 0.14)',  'border' => 'rgba(244, 63, 94, 0.45)',  'dot' => '#f43f5e'],
    'violet'  => ['label' => 'Violett', 'bg' => 'rgba(139, 92, 246, 0.14)', 'border' => 'rgba(139, 92, 246, 0.45)', 'dot' => '#8b5cf6'],
    'slate'   => ['label' => 'Neutral', 'bg' => 'rgba(100, 116, 139, 0.14)', 'border' => 'rgba(100, 116, 139, 0.40)', 'dot' => '#64748b'],
];

/**
 * Prüft, ob der aktuelle Benutzer Zugriff auf eine Notiz hat.
 */
function canAccessNote(array $note, int $userId, array $myGroupIds): bool
{
    if ((int)$note['user_id'] === $userId) {
        return true;
    }
    if (!empty($note['shared_user_id']) && (int)$note['shared_user_id'] === $userId) {
        return true;
    }
    if (!empty($note['group_id']) && in_array((int)$note['group_id'], $myGroupIds, true)) {
        return true;
    }
    return false;
}

/**
 * Formatiert Sekunden als MM:SS.
 */
function formatVoiceDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

// ============================================================================
// GET-Endpunkt: Audio-Stream einer Sprachnotiz (inkl. HTTP Range / 206 Support)
// ============================================================================
if (isset($_GET['stream_audio'])) {
    $noteId = (int)$_GET['stream_audio'];
    $stmtN = $pdo->prepare(
        "SELECT n.*, f.`filename` AS `stored_filename`, f.`original_name` AS `audio_orig_name`,
                f.`mime_type` AS `audio_mime`, f.`file_size` AS `audio_size`,
                f.`is_encrypted` AS `audio_enc`, f.`deleted_at` AS `audio_deleted`
         FROM `notes` n
         INNER JOIN `files` f ON f.`id` = n.`audio_file_id`
         WHERE n.`id` = :nid
         LIMIT 1"
    );
    $stmtN->execute([':nid' => $noteId]);
    $row = $stmtN->fetch();

    if (!$row || !canAccessNote($row, $userId, $myGroupIds) || !empty($row['audio_deleted']) || (int)($row['audio_enc'] ?? 0) === 1) {
        http_response_code(403);
        exit('Kein Zugriff auf diese Sprachnotiz.');
    }

    $fullPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$row['stored_filename']);
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        http_response_code(404);
        exit('Audiodatei nicht gefunden.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $size = (int)filesize($fullPath);
    $mime = trim((string)($row['audio_mime'] ?? 'audio/webm'));
    if ($mime === '' || $mime === 'application/octet-stream') {
        $ext = strtolower(pathinfo((string)$row['audio_orig_name'], PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3'  => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'ogg', 'opus' => 'audio/ogg',
            'wav'  => 'audio/wav',
            default => 'audio/webm',
        };
    }

    $start  = 0;
    $end    = $size > 0 ? $size - 1 : 0;
    $length = $size;

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
        $reqStart = $m[1] !== '' ? (int)$m[1] : null;
        $reqEnd   = $m[2] !== '' ? (int)$m[2] : null;

        if ($reqStart === null && $reqEnd !== null) {
            $start = max(0, $size - $reqEnd);
            $end   = $size - 1;
        } elseif ($reqStart !== null) {
            $start = $reqStart;
            $end   = ($reqEnd !== null && $reqEnd < $size) ? $reqEnd : ($size - 1);
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            exit;
        }

        $length = ($end - $start) + 1;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    } else {
        http_response_code(200);
    }

    header('Content-Length: ' . $length);

    $fp = fopen($fullPath, 'rb');
    if ($fp !== false) {
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: notes.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_note') {
        $noteId      = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
        $title       = trim((string)($_POST['title'] ?? ''));
        $content     = mb_substr(trim((string)($_POST['content'] ?? '')), 0, 50000);
        $color       = (string)($_POST['color'] ?? 'amber');
        $tagsRaw     = trim((string)($_POST['tags'] ?? ''));
        $pinned      = !empty($_POST['is_pinned']) ? 1 : 0;
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));
        $removeAudio = !empty($_POST['remove_audio']);
        $audioDurSec = max(0, min(86400, (int)($_POST['audio_duration_sec'] ?? 0)));

        $existing = null;
        if ($noteId > 0) {
            $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
            $stmtChk->execute([':id' => $noteId]);
            $existing = $stmtChk->fetch();
            if (!$existing || !canAccessNote($existing, $userId, $myGroupIds)) {
                setFlash('error', 'Notiz nicht gefunden oder keine Berechtigung.');
                header('Location: notes.php');
                exit;
            }
        }

        // Prüfen, ob eine neue Sprachaufnahme (oder Audiodatei) hochgeladen wurde
        $hasUploadedVoice = isset($_FILES['voice_file'])
            && is_array($_FILES['voice_file'])
            && (int)($_FILES['voice_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (int)($_FILES['voice_file']['size'] ?? 0) > 0;

        $currentAudioFileId = $existing && !empty($existing['audio_file_id']) ? (int)$existing['audio_file_id'] : null;
        $currentAudioDurSec = $existing ? (int)($existing['audio_duration_sec'] ?? 0) : 0;

        if ($removeAudio) {
            $currentAudioFileId = null;
            $currentAudioDurSec = 0;
        }

        if ($title === '' && $content === '' && !$hasUploadedVoice && $currentAudioFileId === null) {
            setFlash('error', 'Bitte gib einen Titel, Notiztext oder eine Sprachaufnahme ein.');
            header('Location: notes.php');
            exit;
        }

        if (!isset($allowedColors[$color])) {
            $color = 'amber';
        }

        // Neue Sprachaufnahme in der Cloud unter "Notizen/Sprachnotizen" speichern
        if ($hasUploadedVoice) {
            $tmpPath  = (string)$_FILES['voice_file']['tmp_name'];
            $origName = (string)($_FILES['voice_file']['name'] ?? 'Sprachnotiz.webm');
            $fileSize = (int)$_FILES['voice_file']['size'];

            if ($fileSize > 30 * 1024 * 1024) {
                setFlash('error', 'Die Sprachaufnahme darf maximal 30 MB groß sein.');
                header('Location: notes.php');
                exit;
            }

            $stats = getUserStorageStats($pdo, $userId);
            if (($stats['used'] + $fileSize) > $stats['quota']) {
                setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für die Sprachnotiz.');
                header('Location: notes.php');
                exit;
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['webm', 'ogg', 'mp4', 'm4a', 'mp3', 'wav', 'opus'], true)) {
                $ext = 'webm';
            }

            $mimeType = match ($ext) {
                'mp3'        => 'audio/mpeg',
                'm4a', 'mp4' => 'audio/mp4',
                'ogg', 'opus'=> 'audio/ogg',
                'wav'        => 'audio/wav',
                default      => 'audio/webm',
            };

            ensureUploadDirReady(UPLOAD_DIR);

            $baseLabel = $title !== '' ? preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_-]/u', '_', $title) : 'Sprachnotiz_' . date('Y-m-d_H-i-s');
            $baseLabel = trim((string)$baseLabel, '_');
            if ($baseLabel === '') {
                $baseLabel = 'Sprachnotiz_' . date('Y-m-d_H-i-s');
            }
            $cleanVoiceName = sanitizeFileName(mb_substr($baseLabel, 0, 80) . '.' . $ext);
            $storedName     = generateStoredFileName($ext);
            $destPath       = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $storedName;

            if (move_uploaded_file($tmpPath, $destPath)) {
                @chmod($destPath, 0640);
                $voiceFolder = 'Notizen/Sprachnotizen';
                registerFolderHierarchy($pdo, $userId, $voiceFolder);

                $stmtInsFile = $pdo->prepare(
                    "INSERT INTO `files` (`user_id`, `folder_path`, `original_name`, `filename`, `mime_type`, `file_size`, `is_encrypted`)
                     VALUES (:uid, :fp, :orig, :stored, :mime, :size, 0)"
                );
                $stmtInsFile->execute([
                    ':uid'    => $userId,
                    ':fp'     => $voiceFolder,
                    ':orig'   => $cleanVoiceName,
                    ':stored' => $storedName,
                    ':mime'   => $mimeType,
                    ':size'   => $fileSize,
                ]);
                $currentAudioFileId = (int)$pdo->lastInsertId();
                $currentAudioDurSec = $audioDurSec;

                // Falls weder Titel noch Text angegeben wurden, freundlichen Standardtitel setzen
                if ($title === '' && $content === '') {
                    $title = '🎙️ Sprachnotiz (' . date('d.m.Y H:i') . ')';
                }
                if ($tagsRaw === '') {
                    $tagsRaw = 'sprachnotiz';
                }
            } else {
                setFlash('error', 'Sprachaufnahme konnte nicht gespeichert werden.');
                header('Location: notes.php');
                exit;
            }
        }

        // Tags säubern (Komma/Leerzeichen getrennt, ohne führendes # speichern)
        $tagParts = preg_split('/[\s,]+/', $tagsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleanTags = [];
        foreach ($tagParts as $tp) {
            $tp = ltrim(trim($tp), '#');
            if ($tp !== '') {
                $cleanTags[] = mb_substr($tp, 0, 32);
            }
        }
        $tagsStr = implode(', ', array_unique($cleanTags));

        $sharedUserId = null;
        $groupId      = null;
        if (str_starts_with($shareTarget, 'user:')) {
            $candUser = (int)substr($shareTarget, 5);
            if (in_array($candUser, $visiblePeerIds, true)) {
                $sharedUserId = $candUser;
            }
        }

        if ($noteId > 0 && $existing) {
            if ((int)$existing['user_id'] !== $userId) {
                $sharedUserId = $existing['shared_user_id'] ? (int)$existing['shared_user_id'] : null;
                $groupId      = $existing['group_id'] ? (int)$existing['group_id'] : null;
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE `notes`
                 SET `title` = :t, `content` = :c, `color` = :col, `tags` = :tags,
                     `is_pinned` = :pin, `audio_file_id` = :afid, `audio_duration_sec` = :adur,
                     `shared_user_id` = :su, `group_id` = :gid
                 WHERE `id` = :id"
            );
            $stmtUpd->execute([
                ':t'    => mb_substr($title, 0, 190),
                ':c'    => $content,
                ':col'  => $color,
                ':tags' => mb_substr($tagsStr, 0, 255),
                ':pin'  => $pinned,
                ':afid' => $currentAudioFileId,
                ':adur' => $currentAudioDurSec,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
                ':id'   => $noteId,
            ]);
            setFlash('success', 'Notiz aktualisiert.');
        } else {
            $stmtIns = $pdo->prepare(
                "INSERT INTO `notes` (`user_id`, `title`, `content`, `color`, `tags`, `is_pinned`, `audio_file_id`, `audio_duration_sec`, `shared_user_id`, `group_id`)
                 VALUES (:uid, :t, :c, :col, :tags, :pin, :afid, :adur, :su, :gid)"
            );
            $stmtIns->execute([
                ':uid'  => $userId,
                ':t'    => mb_substr($title, 0, 190),
                ':c'    => $content,
                ':col'  => $color,
                ':tags' => mb_substr($tagsStr, 0, 255),
                ':pin'  => $pinned,
                ':afid' => $currentAudioFileId,
                ':adur' => $currentAudioDurSec,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
            ]);
            setFlash('success', $hasUploadedVoice ? '🎙️ Sprachnotiz an die Notizwand geheftet!' : 'Neue Haftnotiz angeheftet.');
        }

        header('Location: notes.php');
        exit;
    }

    if ($action === 'toggle_pin') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if ($note && canAccessNote($note, $userId, $myGroupIds)) {
            $newPin = (int)$note['is_pinned'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE `notes` SET `is_pinned` = :p WHERE `id` = :id")
                ->execute([':p' => $newPin, ':id' => $noteId]);
        }
        header('Location: notes.php');
        exit;
    }

    if ($action === 'delete_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if ($note && (int)$note['user_id'] === $userId) {
            $pdo->prepare("DELETE FROM `notes` WHERE `id` = :id")->execute([':id' => $noteId]);
            setFlash('success', 'Notiz gelöscht.');
        } else {
            setFlash('error', 'Nur der Ersteller kann diese Notiz löschen.');
        }
        header('Location: notes.php');
        exit;
    }

    if ($action === 'export_to_cloud') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if (!$note || !canAccessNote($note, $userId, $myGroupIds)) {
            setFlash('error', 'Notiz nicht gefunden.');
            header('Location: notes.php');
            exit;
        }

        $noteTitle = trim((string)$note['title']) !== '' ? trim((string)$note['title']) : 'Notiz_' . (int)$note['id'];
        $mdContent = '# ' . $noteTitle . "\n\n";
        if (trim((string)$note['tags']) !== '') {
            $mdContent .= '**Tags:** ' . trim((string)$note['tags']) . "\n\n";
        }
        $mdContent .= (string)$note['content'] . "\n";
        $bytes = strlen($mdContent);

        $stats = getUserStorageStats($pdo, $userId);
        if (($stats['used'] + $bytes) > $stats['quota']) {
            setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für den Export.');
            header('Location: notes.php');
            exit;
        }

        ensureUploadDirReady(UPLOAD_DIR);

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $noteTitle);
        $safeBase = trim((string)$safeBase, '_');
        if ($safeBase === '') {
            $safeBase = 'Notiz';
        }
        $originalName = sanitizeFileName(mb_substr($safeBase, 0, 80) . '.md');
        $storedName   = generateStoredFileName('md');
        $destPath     = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedName;

        if (file_put_contents($destPath, $mdContent) !== false) {
            @chmod($destPath, 0640);
            $folderPath = 'Notizen';
            registerFolderHierarchy($pdo, $userId, $folderPath);

            $stmtFile = $pdo->prepare(
                "INSERT INTO `files` (`user_id`, `folder_path`, `original_name`, `filename`, `mime_type`, `file_size`, `is_encrypted`)
                 VALUES (:uid, :fp, :orig, :stored, 'text/markdown', :size, 0)"
            );
            $stmtFile->execute([
                ':uid'    => $userId,
                ':fp'     => $folderPath,
                ':orig'   => $originalName,
                ':stored' => $storedName,
                ':size'   => $bytes,
            ]);
            setFlash('success', 'Notiz als „' . $originalName . '“ im Cloud-Ordner „Notizen“ gespeichert!');
        } else {
            setFlash('error', 'Fehler beim Schreiben der Markdown-Datei.');
        }

        header('Location: notes.php');
        exit;
    }
}

// Notizen abrufen (eigene + geteilte + verknüpfte Sprachaufnahme)
$sql = "SELECT n.*, u.`username` AS `owner_name`, su.`username` AS `shared_username`,
               af.`original_name` AS `audio_orig_name`, af.`file_size` AS `audio_file_size`
        FROM `notes` n
        INNER JOIN `users` u ON u.`id` = n.`user_id`
        LEFT JOIN `users` su ON su.`id` = n.`shared_user_id`
        LEFT JOIN `files` af ON af.`id` = n.`audio_file_id` AND af.`deleted_at` IS NULL
        WHERE n.`user_id` = :uid OR n.`shared_user_id` = :suid
        ORDER BY n.`is_pinned` DESC, n.`updated_at` DESC";

$stmtNotes = $pdo->prepare($sql);
$stmtNotes->execute([
    ':uid'  => $userId,
    ':suid' => $userId,
]);
$allNotes = $stmtNotes->fetchAll();

// Alle vorkommenden Tags sammeln + Anzahl Sprachnotizen zählen
$allTags = [];
$voiceNotesCount = 0;
foreach ($allNotes as $n) {
    if (!empty($n['audio_file_id']) && !empty($n['audio_orig_name'])) {
        $voiceNotesCount++;
    }
    if (trim((string)$n['tags']) !== '') {
        foreach (explode(',', (string)$n['tags']) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $allTags[$t] = ($allTags[$t] ?? 0) + 1;
            }
        }
    }
}
ksort($allTags);

$pageTitle = 'Notizwand';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.1rem;
    align-items: start;
}
.note-card {
    border-radius: var(--radius);
    padding: 1.15rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    position: relative;
}
.note-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}
.note-content {
    font-size: 0.9rem;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
    color: var(--text);
    max-height: 260px;
    overflow-y: auto;
}
.color-picker-row {
    display: flex;
    gap: 0.55rem;
    align-items: center;
    flex-wrap: wrap;
}
.color-swatch-label {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.6rem;
    border-radius: 999px;
    border: 1px solid var(--border);
    cursor: pointer;
    font-size: 0.8rem;
}
.voice-note-box {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    padding: 0.55rem 0.7rem;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.voice-note-box audio {
    width: 100%;
    height: 34px;
    border-radius: 0.5rem;
}
@keyframes pulseRec {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.35; transform: scale(1.18); }
}
.rec-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #ef4444;
    display: inline-block;
    animation: pulseRec 1.1s infinite ease-in-out;
}
</style>

<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">📌</span>
            <span>Notizwand &amp; Sprachnotizen</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">Schnelle Ideen, 🎙️ eingesprochene Sprachmemos, 🗣️ Diktierfunktion &amp; geteilte Pinnwand-Zettel</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" id="noteSearchInput" class="form-control" placeholder="🔍 Notizen oder #Tags durchsuchen..." style="max-width:250px;" oninput="filterNotesBoard()">
        <button type="button" class="btn btn-outline" onclick="openNoteModal(null, true)" title="Direkt eine neue Sprachnotiz per Mikrofon aufnehmen" style="border-color:rgba(244,63,94,0.45); color:#e11d48;">
            🎙️ Sprachnotiz
        </button>
        <button type="button" class="btn btn-primary" onclick="openNoteModal()">➕ Neue Notiz</button>
    </div>
</div>

<?php if (!empty($allTags) || $voiceNotesCount > 0): ?>
<div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem;">
    <span class="text-muted" style="font-size:0.8rem; font-weight:600;">🏷️ Filter:</span>
    <button type="button" class="btn btn-xs btn-outline note-tag-btn active" data-tag="" onclick="filterByTag('')">Alle (<?= count($allNotes) ?>)</button>
    <?php if ($voiceNotesCount > 0): ?>
        <button type="button" class="btn btn-xs btn-outline note-tag-btn" data-tag="__voice__" onclick="filterByTag('__voice__')">
            🎙️ Sprachnotizen <span class="text-muted">(<?= $voiceNotesCount ?>)</span>
        </button>
    <?php endif; ?>
    <?php foreach ($allTags as $tagName => $cnt): ?>
        <button type="button" class="btn btn-xs btn-outline note-tag-btn" data-tag="<?= e(mb_strtolower($tagName)) ?>" onclick="filterByTag('<?= e( addslashes(mb_strtolower($tagName)) ) ?>')">
            #<?= e($tagName) ?> <span class="text-muted">(<?= $cnt ?>)</span>
        </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($allNotes)): ?>
    <div class="card" style="text-align:center; padding:3rem 1.5rem;">
        <div style="font-size:3rem; margin-bottom:0.6rem;">📌</div>
        <h3 style="margin:0 0 0.4rem 0;">Deine Notizwand ist noch leer</h3>
        <p class="text-muted" style="margin:0 0 1.2rem 0;">Hefte deine erste Idee, Einkaufsliste oder eine eingesprochene 🎙️ Sprachnotiz an die Pinnwand.</p>
        <div style="display:inline-flex; gap:0.6rem; flex-wrap:wrap; justify-content:center;">
            <button type="button" class="btn btn-outline" onclick="openNoteModal(null, true)">🎙️ Sprachnotiz aufnehmen</button>
            <button type="button" class="btn btn-primary" onclick="openNoteModal()">➕ Erste Notiz erstellen</button>
        </div>
    </div>
<?php else: ?>
    <div class="notes-grid" id="notesGrid">
        <?php foreach ($allNotes as $note):
            $nid = (int)$note['id'];
            $colKey = isset($allowedColors[$note['color']]) ? $note['color'] : 'amber';
            $colDef = $allowedColors[$colKey];
            $isPinned = (int)$note['is_pinned'] === 1;
            $isOwner  = (int)$note['user_id'] === $userId;
            $hasAudio = !empty($note['audio_file_id']) && !empty($note['audio_orig_name']);
            $audioDur = (int)($note['audio_duration_sec'] ?? 0);
            $noteTags = array_filter(array_map('trim', explode(',', (string)$note['tags'])));
            $searchBlob = mb_strtolower(($note['title'] ?? '') . ' ' . ($note['content'] ?? '') . ' ' . ($note['tags'] ?? '') . ($hasAudio ? ' sprachnotiz audio' : ''));
            $tagsLower  = implode('|', array_map('mb_strtolower', $noteTags));
            $noteJson   = htmlspecialchars(json_encode([
                'id'            => $nid,
                'title'         => (string)$note['title'],
                'content'       => (string)$note['content'],
                'color'         => $colKey,
                'tags'          => (string)$note['tags'],
                'is_pinned'     => $isPinned ? 1 : 0,
                'is_owner'      => $isOwner,
                'has_audio'     => $hasAudio,
                'audio_name'    => (string)($note['audio_orig_name'] ?? ''),
                'audio_dur'     => $audioDur,
                'audio_url'     => $hasAudio ? ('notes.php?stream_audio=' . $nid) : '',
                'share_target'  => !empty($note['shared_user_id']) ? 'user:' . (int)$note['shared_user_id'] : '',
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
            <div class="note-card"
                 data-search="<?= e($searchBlob) ?>"
                 data-tags="<?= e($tagsLower) ?>"
                 data-has-voice="<?= $hasAudio ? '1' : '0' ?>"
                 style="background: <?= $colDef['bg'] ?>; border-color: <?= $colDef['border'] ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem;">
                    <div style="font-weight:700; font-size:1rem; line-height:1.35; word-break:break-word;">
                        <?php if ($isPinned): ?>
                            <span title="Angepinnt">📌</span>
                        <?php endif; ?>
                        <?php if ($hasAudio && !str_contains((string)$note['title'], '🎙️')): ?>
                            <span title="Enthält Sprachnotiz">🎙️</span>
                        <?php endif; ?>
                        <?= $note['title'] !== '' ? e($note['title']) : '<span class="text-muted">Ohne Titel</span>' ?>
                    </div>
                    <form method="post" action="notes.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_pin">
                        <input type="hidden" name="note_id" value="<?= $nid ?>">
                        <button type="submit" class="btn btn-xs btn-outline" title="<?= $isPinned ? 'Lösen' : 'Oben anpinnen' ?>" style="padding:0.15rem 0.4rem;">
                            <?= $isPinned ? '📍' : '📌' ?>
                        </button>
                    </form>
                </div>

                <?php if ($hasAudio): ?>
                    <div class="voice-note-box">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:0.4rem; font-size:0.75rem;">
                            <span style="font-weight:600; display:inline-flex; align-items:center; gap:0.3rem; truncate;">
                                <span>🎙️ Sprachaufnahme</span>
                                <?php if ($audioDur > 0): ?>
                                    <span class="text-muted">(<?= e(formatVoiceDuration($audioDur)) ?>)</span>
                                <?php endif; ?>
                            </span>
                            <div style="display:inline-flex; align-items:center; gap:0.25rem;">
                                <button type="button" class="btn btn-xs btn-outline" style="padding:0.05rem 0.35rem; font-size:0.7rem;" onclick="cycleVoiceNoteSpeed(this, 'voiceAudio_<?= $nid ?>')" title="Abspielgeschwindigkeit ändern">1×</button>
                                <?php if (($appSettings['enable_music'] ?? '1') === '1'): ?>
                                    <a href="music.php?play=<?= (int)$note['audio_file_id'] ?>" class="btn btn-xs btn-outline" style="padding:0.05rem 0.35rem; font-size:0.7rem;" title="Im Musik- & Hörbuch-Studio öffnen">🎵</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <audio id="voiceAudio_<?= $nid ?>" controls preload="metadata" src="notes.php?stream_audio=<?= $nid ?>"></audio>
                    </div>
                <?php endif; ?>

                <?php if (trim((string)$note['content']) !== ''): ?>
                    <div class="note-content"><?= e((string)$note['content']) ?></div>
                <?php endif; ?>

                <?php if (!empty($noteTags)): ?>
                    <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                        <?php foreach ($noteTags as $tg): ?>
                            <span class="badge" style="background:var(--bg-card); border:1px solid var(--border); font-size:0.72rem; cursor:pointer;"
                                  onclick="filterByTag('<?= e(addslashes(mb_strtolower($tg))) ?>')">#<?= e($tg) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem; margin-top:0.35rem; padding-top:0.55rem; border-top:1px dashed <?= $colDef['border'] ?>; font-size:0.75rem;">
                    <div class="text-muted">
                        🕓 <?= e(formatDate((string)$note['updated_at'])) ?>
                        <?php if (!$isOwner): ?>
                            · 👤 <?= e((string)$note['owner_name']) ?>
                        <?php elseif (!empty($note['shared_username'])): ?>
                            · 🤝 <?= e((string)$note['shared_username']) ?>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:0.3rem; align-items:center;">
                        <form method="post" action="notes.php" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="export_to_cloud">
                            <input type="hidden" name="note_id" value="<?= $nid ?>">
                            <button type="submit" class="btn btn-xs btn-outline" title="Als Markdown-Datei (.md) im Cloud-Ordner 'Notizen' speichern">☁️ .md</button>
                        </form>
                        <button type="button" class="btn btn-xs btn-outline" onclick='openNoteModal(<?= $noteJson ?>)' title="Bearbeiten">✏️</button>
                        <?php if ($isOwner): ?>
                            <form method="post" action="notes.php" style="margin:0;" onsubmit="return confirm('Notiz wirklich löschen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?= $nid ?>">
                                <button type="submit" class="btn btn-xs btn-danger" title="Löschen">🗑️</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal: Notiz erstellen / bearbeiten -->
<div class="modal-overlay" id="noteModal" style="display:none;" onclick="if(event.target===this) closeNoteModal();">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-header">
            <h3 id="noteModalTitle" style="margin:0;">📌 Neue Haftnotiz</h3>
            <button type="button" class="modal-close" onclick="closeNoteModal()">✕</button>
        </div>
        <form method="post" action="notes.php" enctype="multipart/form-data" id="noteForm" onsubmit="return beforeSubmitNoteForm()">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="note_id" id="noteFormId" value="0">
            <input type="hidden" name="remove_audio" id="noteRemoveAudio" value="0">
            <input type="hidden" name="audio_duration_sec" id="noteAudioDurationSec" value="0">
            <input type="file" name="voice_file" id="noteVoiceFileInput" accept="audio/*" style="display:none;" onchange="handleManualVoiceFileSelect(this)">

            <div class="form-group">
                <label class="form-label" for="noteFormTitle">Titel <span class="text-muted" style="font-weight:400; font-size:0.78rem;">(optional bei Sprachnotizen)</span></label>
                <input type="text" name="title" id="noteFormTitle" class="form-control" maxlength="190" placeholder="Kurze Überschrift...">
            </div>

            <!-- 🎙️ Sprachnotiz-Recorder-Box -->
            <div class="form-group" style="background:var(--bg-body); border:1px solid var(--border); border-radius:0.85rem; padding:0.85rem;">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.5rem;">
                    <div style="font-weight:600; font-size:0.85rem; display:flex; align-items:center; gap:0.4rem;">
                        <span>🎙️ Sprachnotiz (Audio-Aufnahme)</span>
                        <span id="voiceRecStatusBadge" style="display:none; align-items:center; gap:0.35rem; font-size:0.78rem; color:#ef4444; font-weight:700;">
                            <span class="rec-dot"></span>
                            <span id="voiceRecTimer">00:00</span>
                        </span>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                        <button type="button" id="btnStartVoiceRec" class="btn btn-xs btn-primary" style="background:#e11d48; border-color:#e11d48;" onclick="startNoteVoiceRecording()">
                            🎙️ Aufnahme starten
                        </button>
                        <button type="button" id="btnStopVoiceRec" class="btn btn-xs btn-danger" style="display:none;" onclick="stopNoteVoiceRecording()">
                            ⏹️ Aufnahme beenden
                        </button>
                        <button type="button" class="btn btn-xs btn-outline" onclick="document.getElementById('noteVoiceFileInput').click()" title="Vorhandene Audiodatei anhängen">
                            📁 Datei wählen
                        </button>
                    </div>
                </div>

                <!-- Live-Pegelanzeige während der Aufnahme -->
                <div id="voiceRecMeterWrap" style="display:none; height:8px; background:var(--border); border-radius:999px; overflow:hidden; margin-bottom:0.55rem;">
                    <div id="voiceRecMeterBar" style="height:100%; width:8%; background:linear-gradient(90deg, #10b981, #f59e0b, #ef4444); transition:width 0.08s ease;"></div>
                </div>

                <!-- Vorschau der aktuellen / neu aufgenommenen Sprachnotiz -->
                <div id="voicePreviewContainer" style="display:none; margin-top:0.4rem; padding-top:0.5rem; border-top:1px dashed var(--border);">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.35rem; font-size:0.78rem;">
                        <span id="voicePreviewLabel" class="text-muted">Bereit zum Speichern</span>
                        <button type="button" class="btn btn-xs btn-outline" style="color:#ef4444;" onclick="clearNoteVoiceAttachment()">
                            🗑️ Sprachaufnahme entfernen
                        </button>
                    </div>
                    <audio id="voicePreviewPlayer" controls style="width:100%; height:36px;"></audio>
                </div>
                <p class="text-muted" style="font-size:0.74rem; margin:0.35rem 0 0 0;">
                    💡 Tipp: Du kannst eine Notiz auch <strong>nur per Sprache</strong> aufnehmen, ohne Text zu tippen. Die Audiodatei wird im Ordner <code>Notizen/Sprachnotizen</code> abgelegt.
                </p>
            </div>

            <div class="form-group">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:0.5rem; margin-bottom:0.35rem;">
                    <label class="form-label" for="noteFormContent" style="margin:0;">Notiz-Text</label>
                    <button type="button" id="btnSpeechToText" class="btn btn-xs btn-outline" onclick="toggleNoteDictation()" title="Sprache live als geschriebenen Text in das Notizfeld diktieren">
                        🗣️ Diktieren (Sprache → Text)
                    </button>
                </div>
                <textarea name="content" id="noteFormContent" class="form-control" rows="5" placeholder="Schreibe deine Gedanken, Links oder Stichpunkte auf – oder nutze oben 🎙️ Sprachnotiz / 🗣️ Diktieren..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Zettel-Farbe</label>
                <div class="color-picker-row">
                    <?php foreach ($allowedColors as $cKey => $cDef): ?>
                        <label class="color-swatch-label" style="background: <?= $cDef['bg'] ?>; border-color: <?= $cDef['border'] ?>;">
                            <input type="radio" name="color" value="<?= e($cKey) ?>" id="noteColor_<?= e($cKey) ?>" <?= $cKey === 'amber' ? 'checked' : '' ?>>
                            <span style="width:10px; height:10px; border-radius:50%; background:<?= $cDef['dot'] ?>; display:inline-block;"></span>
                            <?= e($cDef['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="noteFormTags">🏷️ Schlagworte (Tags, kommagetrennt)</label>
                    <input type="text" name="tags" id="noteFormTags" class="form-control" placeholder="z. B. projekt, ideen, sprachnotiz">
                </div>
                <div class="form-group" id="noteShareWrapper">
                    <label class="form-label" for="noteFormShare">🤝 Teilen mit (optional)</label>
                    <select name="share_target" id="noteFormShare" class="form-control">
                        <option value="">🔒 Nur für mich privat</option>
                        <?php if (!empty($visiblePeers)): ?>
                            <optgroup label="👤 Benutzer">
                                <?php foreach ($visiblePeers as $peer): ?>
                                    <option value="user:<?= (int)$peer['id'] ?>">👤 <?= e($peer['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display:inline-flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" name="is_pinned" id="noteFormPinned" value="1">
                    📌 Oben an der Notizwand anpinnen
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeNoteModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="btnSaveNoteSubmit">💾 Notiz speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
let activeNoteTag = '';

function filterNotesBoard() {
    const q = (document.getElementById('noteSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.note-card').forEach(card => {
        const searchStr = card.getAttribute('data-search') || '';
        const tagsStr = (card.getAttribute('data-tags') || '').split('|').filter(Boolean);
        const hasVoice = card.getAttribute('data-has-voice') === '1';
        const matchesQuery = (q === '' || searchStr.indexOf(q) !== -1);
        let matchesTag = true;
        if (activeNoteTag === '__voice__') {
            matchesTag = hasVoice;
        } else if (activeNoteTag !== '') {
            matchesTag = tagsStr.includes(activeNoteTag);
        }
        card.style.display = (matchesQuery && matchesTag) ? '' : 'none';
    });
}

function filterByTag(tag) {
    activeNoteTag = tag;
    document.querySelectorAll('.note-tag-btn').forEach(btn => {
        btn.classList.toggle('active', (btn.getAttribute('data-tag') || '') === tag);
    });
    filterNotesBoard();
}

function cycleVoiceNoteSpeed(btn, audioId) {
    const audio = document.getElementById(audioId);
    if (!audio) return;
    const speeds = [1, 1.25, 1.5, 2];
    const current = audio.playbackRate || 1;
    const nextIdx = (speeds.indexOf(current) + 1) % speeds.length;
    audio.playbackRate = speeds[nextIdx];
    btn.textContent = speeds[nextIdx] + '×';
}

// ============================================================================
// 🎙️ Mikrofon-Sprachaufnahme (MediaRecorder API)
// ============================================================================
let noteMediaRecorder = null;
let noteAudioChunks = [];
let noteRecordStream = null;
let noteRecordStartTs = 0;
let noteRecordTimerInt = null;
let noteAudioCtx = null;
let noteAnalyser = null;
let noteMeterAnimId = null;

function getSupportedRecorderMime() {
    const candidates = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/mp4'
    ];
    if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
        return '';
    }
    for (const c of candidates) {
        if (MediaRecorder.isTypeSupported(c)) return c;
    }
    return '';
}

async function startNoteVoiceRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Dein Browser unterstützt leider keine direkte Mikrofon-Aufnahme (HTTPS oder localhost erforderlich).');
        return;
    }
    try {
        stopNoteVoiceRecording(true);
        noteRecordStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const mimeType = getSupportedRecorderMime();
        noteAudioChunks = [];
        noteMediaRecorder = mimeType
            ? new MediaRecorder(noteRecordStream, { mimeType })
            : new MediaRecorder(noteRecordStream);

        noteMediaRecorder.ondataavailable = (ev) => {
            if (ev.data && ev.data.size > 0) {
                noteAudioChunks.push(ev.data);
            }
        };

        noteMediaRecorder.onstop = () => {
            const elapsedSec = Math.max(1, Math.round((Date.now() - noteRecordStartTs) / 1000));
            document.getElementById('noteAudioDurationSec').value = String(elapsedSec);

            const usedMime = (noteMediaRecorder && noteMediaRecorder.mimeType) ? noteMediaRecorder.mimeType : 'audio/webm';
            let ext = 'webm';
            if (usedMime.includes('ogg')) ext = 'ogg';
            else if (usedMime.includes('mp4') || usedMime.includes('m4a')) ext = 'm4a';

            const blob = new Blob(noteAudioChunks, { type: usedMime });
            const fileName = 'Sprachnotiz_' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.' + ext;
            const fileObj = new File([blob], fileName, { type: usedMime });

            const dt = new DataTransfer();
            dt.items.add(fileObj);
            const fileInput = document.getElementById('noteVoiceFileInput');
            fileInput.files = dt.files;
            document.getElementById('noteRemoveAudio').value = '0';

            const previewUrl = URL.createObjectURL(blob);
            const player = document.getElementById('voicePreviewPlayer');
            player.src = previewUrl;
            document.getElementById('voicePreviewLabel').textContent = '✅ Neue Sprachaufnahme bereit (' + formatSecMMSS(elapsedSec) + ' · ' + Math.round(blob.size / 1024) + ' KB)';
            document.getElementById('voicePreviewContainer').style.display = 'block';
        };

        noteRecordStartTs = Date.now();
        noteMediaRecorder.start(250);

        document.getElementById('btnStartVoiceRec').style.display = 'none';
        document.getElementById('btnStopVoiceRec').style.display = 'inline-flex';
        document.getElementById('voiceRecStatusBadge').style.display = 'inline-flex';
        document.getElementById('voiceRecTimer').textContent = '00:00';

        noteRecordTimerInt = setInterval(() => {
            const sec = Math.max(0, Math.floor((Date.now() - noteRecordStartTs) / 1000));
            document.getElementById('voiceRecTimer').textContent = formatSecMMSS(sec);
        }, 500);

        // Live-Pegelanzeige starten
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) {
                noteAudioCtx = new AudioCtx();
                const src = noteAudioCtx.createMediaStreamSource(noteRecordStream);
                noteAnalyser = noteAudioCtx.createAnalyser();
                noteAnalyser.fftSize = 64;
                src.connect(noteAnalyser);
                document.getElementById('voiceRecMeterWrap').style.display = 'block';
                const dataArr = new Uint8Array(noteAnalyser.frequencyBinCount);
                const updateMeter = () => {
                    if (!noteAnalyser) return;
                    noteAnalyser.getByteFrequencyData(dataArr);
                    let sum = 0;
                    for (let i = 0; i < dataArr.length; i++) sum += dataArr[i];
                    const avg = sum / dataArr.length;
                    const pct = Math.min(100, Math.max(6, Math.round((avg / 140) * 100)));
                    document.getElementById('voiceRecMeterBar').style.width = pct + '%';
                    noteMeterAnimId = requestAnimationFrame(updateMeter);
                };
                updateMeter();
            }
        } catch (e) {}
    } catch (err) {
        alert('Mikrofon-Zugriff nicht möglich: ' + (err.message || err));
    }
}

function stopNoteVoiceRecording(silent = false) {
    if (noteRecordTimerInt) {
        clearInterval(noteRecordTimerInt);
        noteRecordTimerInt = null;
    }
    if (noteMeterAnimId) {
        cancelAnimationFrame(noteMeterAnimId);
        noteMeterAnimId = null;
    }
    if (noteAudioCtx) {
        try { noteAudioCtx.close(); } catch (e) {}
        noteAudioCtx = null;
        noteAnalyser = null;
    }
    if (!silent && noteMediaRecorder && noteMediaRecorder.state !== 'inactive') {
        try { noteMediaRecorder.stop(); } catch (e) {}
    }
    if (noteRecordStream) {
        noteRecordStream.getTracks().forEach(t => t.stop());
        noteRecordStream = null;
    }
    document.getElementById('btnStartVoiceRec').style.display = 'inline-flex';
    document.getElementById('btnStopVoiceRec').style.display = 'none';
    document.getElementById('voiceRecStatusBadge').style.display = 'none';
    document.getElementById('voiceRecMeterWrap').style.display = 'none';
}

function handleManualVoiceFileSelect(input) {
    if (!input.files || !input.files[0]) return;
    const f = input.files[0];
    document.getElementById('noteRemoveAudio').value = '0';
    const url = URL.createObjectURL(f);
    const player = document.getElementById('voicePreviewPlayer');
    player.src = url;
    player.onloadedmetadata = () => {
        if (player.duration && isFinite(player.duration)) {
            document.getElementById('noteAudioDurationSec').value = String(Math.round(player.duration));
        }
    };
    document.getElementById('voicePreviewLabel').textContent = '📎 Ausgewählte Audiodatei: ' + f.name;
    document.getElementById('voicePreviewContainer').style.display = 'block';
}

function clearNoteVoiceAttachment() {
    stopNoteVoiceRecording(true);
    const fileInput = document.getElementById('noteVoiceFileInput');
    fileInput.value = '';
    document.getElementById('noteRemoveAudio').value = '1';
    document.getElementById('noteAudioDurationSec').value = '0';
    const player = document.getElementById('voicePreviewPlayer');
    player.pause();
    player.removeAttribute('src');
    document.getElementById('voicePreviewContainer').style.display = 'none';
}

function formatSecMMSS(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

function beforeSubmitNoteForm() {
    // Falls die Aufnahme noch läuft, zuerst sauber stoppen
    if (noteMediaRecorder && noteMediaRecorder.state === 'recording') {
        stopNoteVoiceRecording(false);
        setTimeout(() => {
            document.getElementById('noteForm').submit();
        }, 250);
        return false;
    }
    stopNoteDictation();
    return true;
}

// ============================================================================
// 🗣️ Diktierfunktion (Sprache -> Text per Web Speech API)
// ============================================================================
let noteSpeechRec = null;
let isDictating = false;

function toggleNoteDictation() {
    if (isDictating) {
        stopNoteDictation();
        return;
    }
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        alert('Dein Browser unterstützt die direkte Sprache-zu-Text-Diktierfunktion leider nicht. Nutze stattdessen oben „🎙️ Aufnahme starten“ für eine echte Audio-Sprachnotiz!');
        return;
    }
    noteSpeechRec = new SpeechRecognition();
    noteSpeechRec.lang = document.documentElement.lang || 'de-DE';
    noteSpeechRec.continuous = true;
    noteSpeechRec.interimResults = false;

    const btn = document.getElementById('btnSpeechToText');
    const textarea = document.getElementById('noteFormContent');

    noteSpeechRec.onstart = () => {
        isDictating = true;
        btn.innerHTML = '🔴 Diktat läuft... (Stopp)';
        btn.style.borderColor = '#ef4444';
        btn.style.color = '#ef4444';
    };

    noteSpeechRec.onresult = (event) => {
        let added = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            if (event.results[i].isFinal) {
                added += event.results[i][0].transcript.trim() + ' ';
            }
        }
        if (added !== '') {
            const cur = textarea.value;
            textarea.value = (cur && !cur.endsWith(' ') && !cur.endsWith('\n') ? cur + ' ' : cur) + added.trim();
        }
    };

    noteSpeechRec.onerror = () => {
        stopNoteDictation();
    };

    noteSpeechRec.onend = () => {
        stopNoteDictation();
    };

    try {
        noteSpeechRec.start();
    } catch (e) {
        stopNoteDictation();
    }
}

function stopNoteDictation() {
    isDictating = false;
    if (noteSpeechRec) {
        try { noteSpeechRec.stop(); } catch (e) {}
        noteSpeechRec = null;
    }
    const btn = document.getElementById('btnSpeechToText');
    if (btn) {
        btn.innerHTML = '🗣️ Diktieren (Sprache → Text)';
        btn.style.borderColor = '';
        btn.style.color = '';
    }
}

function openNoteModal(note = null, autoStartVoice = false) {
    stopNoteVoiceRecording(true);
    stopNoteDictation();

    const modal = document.getElementById('noteModal');
    document.getElementById('noteModalTitle').textContent = note ? '✏️ Notiz bearbeiten' : (autoStartVoice ? '🎙️ Neue Sprachnotiz' : '📌 Neue Haftnotiz');
    document.getElementById('noteFormId').value = note ? note.id : 0;
    document.getElementById('noteFormTitle').value = note ? note.title : '';
    document.getElementById('noteFormContent').value = note ? note.content : '';
    document.getElementById('noteFormTags').value = note ? note.tags : '';
    document.getElementById('noteFormPinned').checked = note ? (Number(note.is_pinned) === 1) : false;
    document.getElementById('noteFormShare').value = note ? (note.share_target || '') : '';
    document.getElementById('noteShareWrapper').style.display = (!note || note.is_owner) ? '' : 'none';

    document.getElementById('noteVoiceFileInput').value = '';
    document.getElementById('noteRemoveAudio').value = '0';
    document.getElementById('noteAudioDurationSec').value = note && note.audio_dur ? String(note.audio_dur) : '0';

    const previewContainer = document.getElementById('voicePreviewContainer');
    const previewPlayer = document.getElementById('voicePreviewPlayer');
    if (note && note.has_audio && note.audio_url) {
        previewPlayer.src = note.audio_url;
        document.getElementById('voicePreviewLabel').textContent = '🎙️ Vorhandene Sprachaufnahme: ' + (note.audio_name || 'Sprachnotiz');
        previewContainer.style.display = 'block';
    } else {
        previewPlayer.pause();
        previewPlayer.removeAttribute('src');
        previewContainer.style.display = 'none';
    }

    const col = (note && note.color) ? note.color : (autoStartVoice ? 'rose' : 'amber');
    const radio = document.getElementById('noteColor_' + col);
    if (radio) radio.checked = true;

    modal.style.display = 'flex';

    if (autoStartVoice) {
        setTimeout(() => startNoteVoiceRecording(), 150);
    }
}

function closeNoteModal() {
    stopNoteVoiceRecording(true);
    stopNoteDictation();
    const previewPlayer = document.getElementById('voicePreviewPlayer');
    if (previewPlayer) previewPlayer.pause();
    document.getElementById('noteModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
