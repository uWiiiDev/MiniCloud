<?php
declare(strict_types=1);

/**
 * MiniCloud – 🎬 Video-Studio & Schnittplatz (video_studio.php)
 *
 * 100 % lokal im Browser (HTML5 Canvas + WebAudio API + MediaRecorder) & STRATO-kompatibel,
 * ergänzt um 2-MB-Chunk-Upload in die Cloud sowie optionalen Server-FFmpeg-Turbo:
 * 1. ✂️ Visuelle Timeline mit echtem Filmstreifen (Thumbnails) & Trimmen (Start [In] / Ende [Out])
 * 2. 📐 Drehen (90°/180°/270°), Spiegeln, Formatwechsel (Original, 16:9, 9:16 Reels, 1:1, 4:3) & Kino-Blur-Rand
 * 3. 🔤 Text-Einblendungen, Bauchbinden (Lower Thirds mit Zeitspanne) & Wasserzeichen
 * 4. 🎨 Live-Farbkorrektur (Helligkeit, Kontrast, Sättigung, Wärme, Vignette) & 8 Kino-Filter-Presets
 * 5. 🔊 Tonspur-Mixer (Originalton stummschalten/regeln, Cloud-MP3 als Hintergrundmusik mischen, Fade-In/Out, Tempo 0.5x–2.0x)
 * 6. 📸 1-Klick Standbild-Schnappschuss (.jpg/.png), 🎵 Audio-Extraktion (.wav) & 🎬 Video-Export direkt in die MiniCloud
 * 7. 📹 Integrierte Webcam- & 🖥️ Bildschirmaufnahme (Screencast) direkt zum Schneiden
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');
$isAdmin     = isAdmin();

if (($appSettings['enable_video_studio'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('warning', 'Das Modul „Video-Studio & Schnittplatz“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Prüft, ob ffmpeg auf dem Server verfügbar ist (optionaler Server-Turbo).
 */
function findVideoCliBinary(string $name = 'ffmpeg'): ?string
{
    if (!function_exists('proc_open')) {
        return null;
    }
    foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/bin/' . $name] as $path) {
        if (@is_file($path) && @is_executable($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Bereinigt einen vom Benutzer gewünschten Zielordnerpfad (z. B. "Videos" oder "Videos/Schnitt").
 */
function sanitizeStudioFolderPath(string $folderInput): string
{
    $trimmed = trim(str_replace('\\', '/', $folderInput), " \t\n\r\0\x0B/");
    if ($trimmed === '') {
        return '';
    }
    return extractSanitizedFolderPath($trimmed . '/placeholder');
}

/**
 * Prüft, ob der aktuelle Benutzer Leserechte auf eine Cloud-Datei hat (eigene Datei, Gruppenordner oder Direktfreigabe).
 *
 * @return array<string, mixed>|null
 */
function loadAccessibleMediaFile(PDO $pdo, int $fileId, int $userId, bool $isAdmin): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        return null;
    }

    $ownerId = (int)$file['user_id'];
    $gfId    = $file['group_folder_id'] !== null ? (int)$file['group_folder_id'] : null;

    $allowed = ($ownerId === $userId) || $isAdmin;
    if (!$allowed && $gfId !== null) {
        $allowed = canUserAccessGroupFolder($pdo, $userId, $gfId, false, $isAdmin);
    }
    if (!$allowed) {
        $stmtShare = $pdo->prepare(
            'SELECT id FROM user_shares
             WHERE shared_with_user_id = :uid
               AND (
                   file_id = :fid
                   OR (
                       owner_id = :owner_id
                       AND folder_path IS NOT NULL
                       AND (:fpath = folder_path OR :fpath_like LIKE CONCAT(folder_path, \'/% \'))
                   )
               )
             LIMIT 1'
        );
        $fPath = (string)($file['folder_path'] ?? '');
        $stmtShare->execute([
            ':uid'        => $userId,
            ':fid'        => $fileId,
            ':owner_id'   => $ownerId,
            ':fpath'      => $fPath,
            ':fpath_like' => $fPath,
        ]);
        if ($stmtShare->fetch()) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        return null;
    }

    $physPath = UPLOAD_DIR . basename((string)$file['filename']);
    if (!is_file($physPath) || !is_readable($physPath) || isVaultEncryptedFile($physPath)) {
        return null;
    }
    $file['_physical_path'] = $physPath;
    return $file;
}

$ffmpegBin = findVideoCliBinary('ffmpeg');

// ============================================================================
// AJAX-ENDPUNKTE: Chunked-Upload für geschnittene Videos, Standbilder & FFmpeg
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.']);
        exit;
    }

    $ajaxAction = (string)$_POST['ajax_action'];

    // 1. Chunked Upload initialisieren
    if ($ajaxAction === 'init_chunk_upload') {
        $desiredName  = sanitizeOriginalFilename((string)($_POST['filename'] ?? 'Video-Schnitt.webm'));
        $targetFolder = sanitizeStudioFolderPath((string)($_POST['folder'] ?? 'Videos'));
        $totalSize    = max(0, (int)($_POST['total_size'] ?? 0));
        $mimeType     = strtolower(trim((string)($_POST['mime_type'] ?? 'video/webm')));

        $allowedExts = ['webm', 'mp4', 'jpg', 'jpeg', 'png', 'wav', 'mp3'];
        $ext = strtolower(pathinfo($desiredName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $desiredName .= '.webm';
            $ext = 'webm';
        }

        if ($totalSize <= 0 || $totalSize > MAX_FILE_SIZE) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Dateigröße oder Maximalgröße überschritten.']);
            exit;
        }

        $storage = getUserStorageStats($pdo, $userId);
        if ($totalSize > $storage['free']) {
            echo json_encode([
                'ok'    => false,
                'error' => sprintf('Nicht genügend freier Cloud-Speicherplatz (benötigt: %s, frei: %s).', formatBytes($totalSize), formatBytes($storage['free'])),
            ]);
            exit;
        }

        ensureUploadDirReady();
        $uploadId = bin2hex(random_bytes(16));
        $tmpFile  = UPLOAD_DIR . 'mc_vidtmp_' . $userId . '_' . $uploadId . '.part';
        if (@file_put_contents($tmpFile, '') === false) {
            echo json_encode(['ok' => false, 'error' => 'Temporäre Upload-Datei konnte nicht angelegt werden.']);
            exit;
        }

        $_SESSION['mc_video_uploads'][$uploadId] = [
            'tmp_file'   => $tmpFile,
            'filename'   => $desiredName,
            'folder'     => $targetFolder,
            'total_size' => $totalSize,
            'received'   => 0,
            'mime_type'  => $mimeType,
            'created_at' => time(),
        ];

        echo json_encode(['ok' => true, 'upload_id' => $uploadId]);
        exit;
    }

    // 2. Einzelnen 2-MB-Chunk empfangen
    if ($ajaxAction === 'upload_chunk') {
        $uploadId = preg_replace('/[^a-f0-9]/', '', (string)($_POST['upload_id'] ?? ''));
        $meta     = $_SESSION['mc_video_uploads'][$uploadId] ?? null;
        if (!is_array($meta) || !is_file((string)$meta['tmp_file'])) {
            echo json_encode(['ok' => false, 'error' => 'Upload-Sitzung nicht gefunden oder abgelaufen.']);
            exit;
        }

        if (!isset($_FILES['chunk']) || (int)$_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Chunk-Übertragungsfehler.']);
            exit;
        }

        $chunkTmp = (string)$_FILES['chunk']['tmp_name'];
        $chunkBytes = @file_get_contents($chunkTmp);
        if ($chunkBytes === false) {
            echo json_encode(['ok' => false, 'error' => 'Chunk konnte nicht gelesen werden.']);
            exit;
        }

        $newReceived = (int)$meta['received'] + strlen($chunkBytes);
        if ($newReceived > ((int)$meta['total_size'] + 65536)) {
            @unlink((string)$meta['tmp_file']);
            unset($_SESSION['mc_video_uploads'][$uploadId]);
            echo json_encode(['ok' => false, 'error' => 'Empfangene Datenmenge überschreitet die angekündigte Dateigröße.']);
            exit;
        }

        if (@file_put_contents((string)$meta['tmp_file'], $chunkBytes, FILE_APPEND) === false) {
            echo json_encode(['ok' => false, 'error' => 'Chunk konnte nicht geschrieben werden.']);
            exit;
        }

        $_SESSION['mc_video_uploads'][$uploadId]['received'] = $newReceived;
        echo json_encode(['ok' => true, 'received' => $newReceived]);
        exit;
    }

    // 3. Chunked Upload abschließen & in `files` registrieren
    if ($ajaxAction === 'finalize_chunk_upload') {
        $uploadId = preg_replace('/[^a-f0-9]/', '', (string)($_POST['upload_id'] ?? ''));
        $meta     = $_SESSION['mc_video_uploads'][$uploadId] ?? null;
        if (!is_array($meta) || !is_file((string)$meta['tmp_file'])) {
            echo json_encode(['ok' => false, 'error' => 'Upload-Sitzung nicht gefunden.']);
            exit;
        }

        $tmpFile = (string)$meta['tmp_file'];
        clearstatcache(true, $tmpFile);
        $actualSize = (int)@filesize($tmpFile);
        if ($actualSize <= 0) {
            @unlink($tmpFile);
            unset($_SESSION['mc_video_uploads'][$uploadId]);
            echo json_encode(['ok' => false, 'error' => 'Die übertragene Datei ist leer.']);
            exit;
        }

        $storage = getUserStorageStats($pdo, $userId);
        if ($actualSize > $storage['free']) {
            @unlink($tmpFile);
            unset($_SESSION['mc_video_uploads'][$uploadId]);
            echo json_encode(['ok' => false, 'error' => 'Speicherkontingent überschritten.']);
            exit;
        }

        $storedName = sprintf('mc_%s_%s.bin', uniqid('', true), bin2hex(random_bytes(8)));
        $destPath   = UPLOAD_DIR . $storedName;
        if (!@rename($tmpFile, $destPath)) {
            @unlink($tmpFile);
            unset($_SESSION['mc_video_uploads'][$uploadId]);
            echo json_encode(['ok' => false, 'error' => 'Zieldatei konnte nicht verschoben werden.']);
            exit;
        }
        @chmod($destPath, 0640);
        unset($_SESSION['mc_video_uploads'][$uploadId]);

        $cleanName   = (string)$meta['filename'];
        $cleanFolder = (string)$meta['folder'];
        $ext         = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
        $mimeMap     = [
            'webm' => 'video/webm',
            'mp4'  => 'video/mp4',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'wav'  => 'audio/wav',
            'mp3'  => 'audio/mpeg',
        ];
        $finalMime = $mimeMap[$ext] ?? 'video/webm';

        if ($cleanFolder !== '') {
            registerFolderHierarchy($pdo, $userId, $cleanFolder);
        }

        $stmtIns = $pdo->prepare(
            'INSERT INTO files (user_id, filename, original_name, folder_path, file_size, mime_type)
             VALUES (:uid, :fn, :orig, :folder, :sz, :mime)'
        );
        $stmtIns->execute([
            ':uid'    => $userId,
            ':fn'     => $storedName,
            ':orig'   => $cleanName,
            ':folder' => $cleanFolder,
            ':sz'     => $actualSize,
            ':mime'   => $finalMime,
        ]);
        $newFileId = (int)$pdo->lastInsertId();

        recordModuleUsage($pdo, 'video_studio', true);
        logAuditEvent(
            $pdo,
            'video_studio_save',
            sprintf('Medien-Export aus Video-Studio gespeichert: %s (%s) in „/%s“', $cleanName, formatBytes($actualSize), $cleanFolder),
            $userId,
            $username
        );

        echo json_encode([
            'ok'           => true,
            'file_id'      => $newFileId,
            'filename'     => $cleanName,
            'folder'       => $cleanFolder,
            'size'         => $actualSize,
            'size_human'   => formatBytes($actualSize),
            'download_url' => 'download.php?id=' . $newFileId,
            'inline_url'   => 'download.php?id=' . $newFileId . '&inline=1',
        ]);
        exit;
    }

    // 4. Optionaler Server-FFmpeg-Turbo (falls ffmpeg auf dem Server installiert ist)
    if ($ajaxAction === 'server_ffmpeg_trim') {
        if ($ffmpegBin === null) {
            echo json_encode(['ok' => false, 'error' => 'Server-FFmpeg ist auf diesem System nicht installiert. Bitte nutzen Sie den integrierten Browser-Renderer.']);
            exit;
        }
        $sourceId    = (int)($_POST['source_file_id'] ?? 0);
        $startSec    = max(0.0, (float)($_POST['start_sec'] ?? 0.0));
        $endSec      = max($startSec + 0.2, (float)($_POST['end_sec'] ?? 0.0));
        $desiredName = sanitizeOriginalFilename((string)($_POST['filename'] ?? 'Schnell-Trim.mp4'));
        $folder      = sanitizeStudioFolderPath((string)($_POST['folder'] ?? 'Videos'));
        $mode        = (string)($_POST['ffmpeg_mode'] ?? 'copy_mp4'); // copy_mp4 | extract_mp3

        $srcFile = loadAccessibleMediaFile($pdo, $sourceId, $userId, $isAdmin);
        if (!$srcFile) {
            echo json_encode(['ok' => false, 'error' => 'Quell-Videodatei nicht gefunden oder keine Berechtigung.']);
            exit;
        }

        ensureUploadDirReady();
        $storedName = sprintf('mc_%s_%s.bin', uniqid('', true), bin2hex(random_bytes(8)));
        $destPath   = UPLOAD_DIR . $storedName;

        $duration = round($endSec - $startSec, 3);
        if ($mode === 'extract_mp3') {
            if (!str_ends_with(strtolower($desiredName), '.mp3')) {
                $desiredName = pathinfo($desiredName, PATHINFO_FILENAME) . '.mp3';
            }
            $cmd = [
                $ffmpegBin, '-y',
                '-ss', (string)$startSec,
                '-i', (string)$srcFile['_physical_path'],
                '-t', (string)$duration,
                '-vn', '-acodec', 'libmp3lame', '-q:a', '2',
                '-f', 'mp3', $destPath,
            ];
            $outMime = 'audio/mpeg';
        } else {
            if (!str_ends_with(strtolower($desiredName), '.mp4')) {
                $desiredName = pathinfo($desiredName, PATHINFO_FILENAME) . '.mp4';
            }
            $cmd = [
                $ffmpegBin, '-y',
                '-ss', (string)$startSec,
                '-i', (string)$srcFile['_physical_path'],
                '-t', (string)$duration,
                '-c', 'copy',
                '-movflags', '+faststart',
                '-f', 'mp4', $destPath,
            ];
            $outMime = 'video/mp4';
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, sys_get_temp_dir());
        if (!is_resource($proc)) {
            echo json_encode(['ok' => false, 'error' => 'FFmpeg-Prozess konnte nicht gestartet werden.']);
            exit;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $errOut = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || !is_file($destPath) || (int)@filesize($destPath) <= 0) {
            @unlink($destPath);
            echo json_encode(['ok' => false, 'error' => 'FFmpeg-Verarbeitung fehlgeschlagen: ' . mb_substr(trim((string)$errOut), 0, 200)]);
            exit;
        }

        $outSize = (int)filesize($destPath);
        $storage = getUserStorageStats($pdo, $userId);
        if ($outSize > $storage['free']) {
            @unlink($destPath);
            echo json_encode(['ok' => false, 'error' => 'Nicht genügend freier Cloud-Speicherplatz.']);
            exit;
        }
        @chmod($destPath, 0640);

        if ($folder !== '') {
            registerFolderHierarchy($pdo, $userId, $folder);
        }

        $stmtIns = $pdo->prepare(
            'INSERT INTO files (user_id, filename, original_name, folder_path, file_size, mime_type)
             VALUES (:uid, :fn, :orig, :folder, :sz, :mime)'
        );
        $stmtIns->execute([
            ':uid'    => $userId,
            ':fn'     => $storedName,
            ':orig'   => $desiredName,
            ':folder' => $folder,
            ':sz'     => $outSize,
            ':mime'   => $outMime,
        ]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'ok'           => true,
            'file_id'      => $newId,
            'filename'     => $desiredName,
            'folder'       => $folder,
            'size_human'   => formatBytes($outSize),
            'download_url' => 'download.php?id=' . $newId,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// ============================================================================
// CLOUD-VIDEOS & HINTERGRUNDMUSIK-DATEIEN DES BENUTZERS LADEN
// ============================================================================
$videoExtsSql = "('mp4','webm','mov','m4v','ogv','mkv')";
$stmtVideos = $pdo->prepare(
    "SELECT id, original_name, folder_path, file_size, upload_date, filename
     FROM files
     WHERE user_id = :uid
       AND deleted_at IS NULL
       AND (
           mime_type LIKE 'video/%'
           OR LOWER(SUBSTRING_INDEX(original_name, '.', -1)) IN $videoExtsSql
       )
     ORDER BY upload_date DESC
     LIMIT 150"
);
$stmtVideos->execute([':uid' => $userId]);
$cloudVideos = [];
foreach ($stmtVideos->fetchAll() as $row) {
    $phys = UPLOAD_DIR . basename((string)$row['filename']);
    if (!is_file($phys) || isVaultEncryptedFile($phys)) {
        continue;
    }
    $cloudVideos[] = [
        'id'         => (int)$row['id'],
        'name'       => (string)$row['original_name'],
        'folder'     => (string)($row['folder_path'] ?? ''),
        'size'       => (int)$row['file_size'],
        'size_human' => formatBytes((int)$row['file_size']),
        'date'       => date('d.m.Y H:i', strtotime((string)$row['upload_date'])),
        'inline_url' => 'download.php?id=' . (int)$row['id'] . '&inline=1',
    ];
}

// Falls ?id=... übergeben wurde (z. B. aus index.php), prüfen ob Zugriff besteht (auch Gruppenordner / geteilte Datei)
$preselectedVideo = null;
$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($requestedId > 0) {
    $accFile = loadAccessibleMediaFile($pdo, $requestedId, $userId, $isAdmin);
    if ($accFile) {
        $preselectedVideo = [
            'id'         => (int)$accFile['id'],
            'name'       => (string)$accFile['original_name'],
            'folder'     => (string)($accFile['folder_path'] ?? ''),
            'size_human' => formatBytes((int)$accFile['file_size']),
            'inline_url' => 'download.php?id=' . (int)$accFile['id'] . '&inline=1',
        ];
        // Falls noch nicht in $cloudVideos, oben anfügen
        $alreadyInList = false;
        foreach ($cloudVideos as $cv) {
            if ($cv['id'] === $preselectedVideo['id']) {
                $alreadyInList = true;
                break;
            }
        }
        if (!$alreadyInList) {
            array_unshift($cloudVideos, $preselectedVideo + ['size' => (int)$accFile['file_size'], 'date' => 'Freigabe']);
        }
    }
}

// Cloud-Audiodateien für Hintergrundmusik-Mischung laden
$audioExtsSql = "('mp3','m4a','wav','ogg','flac','aac','opus')";
$stmtAudios = $pdo->prepare(
    "SELECT id, original_name, folder_path, file_size, filename
     FROM files
     WHERE user_id = :uid
       AND deleted_at IS NULL
       AND (
           mime_type LIKE 'audio/%'
           OR LOWER(SUBSTRING_INDEX(original_name, '.', -1)) IN $audioExtsSql
       )
     ORDER BY original_name ASC
     LIMIT 120"
);
$stmtAudios->execute([':uid' => $userId]);
$cloudAudios = [];
foreach ($stmtAudios->fetchAll() as $row) {
    $phys = UPLOAD_DIR . basename((string)$row['filename']);
    if (!is_file($phys) || isVaultEncryptedFile($phys)) {
        continue;
    }
    $cloudAudios[] = [
        'id'         => (int)$row['id'],
        'name'       => (string)$row['original_name'],
        'folder'     => (string)($row['folder_path'] ?? ''),
        'size_human' => formatBytes((int)$row['file_size']),
        'inline_url' => 'download.php?id=' . (int)$row['id'] . '&inline=1',
    ];
}

// Vorhandene Benutzer-Ordner für die Zielordner-Vorschlagsliste
$stmtFolders = $pdo->prepare('SELECT folder_path FROM user_folders WHERE user_id = :uid ORDER BY folder_path ASC');
$stmtFolders->execute([':uid' => $userId]);
$userFolders = array_values(array_unique(array_filter(array_merge(
    ['Videos', 'Videos/Schnitt', 'Fotos'],
    array_map(static fn(array $r): string => (string)$r['folder_path'], $stmtFolders->fetchAll())
))));

$storageStats = getUserStorageStats($pdo, $userId);
$pageTitle    = '🎬 Video-Studio & Schnittplatz';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-5">
    <!-- Kopfbereich -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-violet-600 to-rose-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    🎬
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">
                            MiniCloud Video-Studio &amp; Schnittplatz
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800/60">
                            ⚡ 100 % Browser-Engine (STRATO-ready)
                        </span>
                        <?php if ($ffmpegBin !== null): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300 border border-violet-300/60 dark:border-violet-800/60">
                                🚀 Server-FFmpeg verfügbar
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Videos sekundengenau kürzen (Trim), drehen (`90°/180°`), auf `16:9` / `9:16` mit Kino-Unschärferand anpassen, Bauchbinden &amp; Farbfilter einblenden, Hintergrundmusik aus der Cloud darunterlegen oder Standbilder als Foto speichern.
                    </p>
                </div>
            </div>

            <!-- Schnell-Aktionen: Quelle wählen -->
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs transition-colors cursor-pointer">
                    <span>📂 Lokales Video öffnen</span>
                    <input type="file" id="vsLocalVideoInput" accept="video/*,.mp4,.webm,.mov,.m4v,.mkv" class="hidden" onchange="vsLoadLocalFile(this.files[0])">
                </label>
                <button
                    type="button"
                    onclick="vsStartCameraCapture('camera')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-rose-600 hover:text-white text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Direkt mit der Webcam oder Smartphone-Kamera einen Clip aufnehmen und schneiden"
                >
                    <span>📹 Kamera-Clip</span>
                </button>
                <button
                    type="button"
                    onclick="vsStartCameraCapture('screen')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Bildschirm / Fenster als Screencast-Video aufnehmen und sofort schneiden"
                >
                    <span>🖥️ Bildschirmaufnahme</span>
                </button>
                <a
                    href="index.php"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors"
                >
                    <span>📁 Meine Dateien</span>
                </a>
            </div>
        </div>

        <!-- Auswahl-Leiste für Cloud-Videos -->
        <div class="mt-4 pt-4 border-t border-slate-200/80 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center gap-2.5">
            <label for="vsCloudVideoSelect" class="text-xs font-bold text-slate-700 dark:text-slate-200 shrink-0">
                ☁️ Cloud-Video auswählen (<?= count($cloudVideos) ?>):
            </label>
            <div class="flex-1 flex flex-wrap sm:flex-nowrap items-center gap-2">
                <select
                    id="vsCloudVideoSelect"
                    onchange="if(this.value) vsLoadCloudVideoById(Number(this.value))"
                    class="flex-1 min-w-[220px] px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white font-medium focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                    <option value="">— Video aus deiner MiniCloud wählen oder lokale Datei öffnen —</option>
                    <?php foreach ($cloudVideos as $cv): ?>
                        <option
                            value="<?= (int)$cv['id'] ?>"
                            <?= ($preselectedVideo && $preselectedVideo['id'] === $cv['id']) ? 'selected' : '' ?>
                        >
                            🎬 <?= e($cv['name']) ?> (<?= e($cv['size_human']) ?><?= $cv['folder'] !== '' ? ' · 📁 ' . e($cv['folder']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button
                    type="button"
                    onclick="vsResetAllSettings()"
                    class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer shrink-0"
                    title="Alle Filter, Drehungen, Bauchbinden und Schnittmarken auf Standard zurücksetzen"
                >
                    🔄 Filter &amp; Schnitt zurücksetzen
                </button>
            </div>
        </div>
    </div>

    <!-- Live-Kamera / Screencast Aufnahme-Banner (nur sichtbar während einer Live-Aufnahme) -->
    <div id="vsLiveCaptureBar" class="hidden mc-card rounded-2xl bg-rose-50 dark:bg-rose-950/50 border-2 border-rose-500 p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="w-3.5 h-3.5 rounded-full bg-rose-600 animate-ping"></span>
            <div>
                <p id="vsLiveCaptureTitle" class="text-sm font-bold text-rose-900 dark:text-rose-200">
                    🔴 Live-Aufnahme läuft…
                </p>
                <p id="vsLiveCaptureSub" class="text-xs text-rose-700 dark:text-rose-300">
                    Sobald du auf „Aufnahme beenden &amp; im Studio öffnen“ klickst, landet der Clip direkt auf dem Schnittplatz.
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span id="vsLiveCaptureTimer" class="font-mono text-sm font-extrabold text-rose-800 dark:text-rose-200 px-3 py-1 rounded-lg bg-white/80 dark:bg-slate-900/80">00:00</span>
            <button
                type="button"
                onclick="vsStopCameraCapture()"
                class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs cursor-pointer"
            >
                ⏹ Aufnahme beenden &amp; schneiden
            </button>
        </div>
    </div>

    <!-- HAUPT-ARBEITSBEREICH: Links Vorschau-Monitor & Timeline | Rechts Werkzeug-Reiter -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">

        <!-- LINKE SPALTE (7/12): Live-Canvas-Monitor, Transport-Steuerung & Filmstreifen-Timeline -->
        <div class="xl:col-span-7 space-y-4">
            <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 sm:p-5 space-y-4">

                <!-- Statuszeile über dem Monitor -->
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex items-center gap-2 min-w-0">
                        <span id="vsLoadedBadge" class="px-2 py-0.5 rounded-md font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 truncate max-w-[260px] sm:max-w-[380px]">
                            Noch kein Video geladen
                        </span>
                        <span id="vsResolutionBadge" class="hidden px-2 py-0.5 rounded-md font-mono text-[11px] bg-indigo-50 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300">
                            1920×1080
                        </span>
                    </div>
                    <div class="flex items-center gap-2 font-mono text-xs font-bold text-slate-700 dark:text-slate-200">
                        <span id="vsCurrentTimeLabel" class="text-brand-600 dark:text-brand-400">00:00.0</span>
                        <span class="text-slate-400">/</span>
                        <span id="vsDurationLabel">00:00.0</span>
                        <span id="vsTrimDurationBadge" class="ml-1 px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 text-[11px]">
                            Schnittlänge: 0.0 s
                        </span>
                    </div>
                </div>

                <!-- Monitor-Bühne (Canvas + Drag & Drop Overlay) -->
                <div
                    id="vsStageContainer"
                    onwheel="vsHandleStageWheel(event)"
                    ondragover="event.preventDefault(); this.classList.add('ring-4','ring-brand-500');"
                    ondragleave="this.classList.remove('ring-4','ring-brand-500');"
                    ondrop="event.preventDefault(); this.classList.remove('ring-4','ring-brand-500'); if(event.dataTransfer.files[0]) vsLoadLocalFile(event.dataTransfer.files[0]);"
                    class="relative w-full aspect-video rounded-2xl bg-slate-950 border border-slate-800 overflow-hidden flex items-center justify-center shadow-inner select-none"
                >
                    <!-- Verstecktes Quell-Video & Hintergrund-Audio -->
                    <video id="vsSourceVideo" class="hidden" playsinline crossorigin="anonymous" preload="auto"></video>
                    <video id="vsLivePreviewVideo" class="hidden" autoplay muted playsinline></video>
                    <audio id="vsBgAudio" class="hidden" crossorigin="anonymous" preload="auto"></audio>

                    <!-- Echtzeit-Vorschau- & Render-Canvas -->
                    <canvas id="vsPreviewCanvas" width="1280" height="720" class="max-w-full max-h-full object-contain"></canvas>

                    <!-- Leerer Startzustand -->
                    <div id="vsEmptyOverlay" class="absolute inset-0 flex flex-col items-center justify-center text-center p-6 bg-slate-950/90 text-slate-300 space-y-3">
                        <div class="w-16 h-16 rounded-2xl bg-slate-800/90 border border-slate-700 flex items-center justify-center text-3xl shadow-md">
                            🎬
                        </div>
                        <div class="max-w-md space-y-1">
                            <p class="text-sm sm:text-base font-bold text-white">
                                Wähle oben ein Cloud-Video aus oder ziehe eine Videodatei hierher
                            </p>
                            <p class="text-xs text-slate-400">
                                Unterstützt `.mp4`, `.webm`, `.mov`, `.m4v` sowie direkte `📹 Kamera-` und `🖥️ Bildschirmaufnahmen`.
                            </p>
                        </div>
                        <div class="flex flex-wrap justify-center gap-2 pt-1">
                            <button
                                type="button"
                                onclick="document.getElementById('vsLocalVideoInput').click()"
                                class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs cursor-pointer"
                            >
                                📂 Videodatei vom Gerät wählen
                            </button>
                            <?php if (!empty($cloudVideos)): ?>
                                <button
                                    type="button"
                                    onclick="vsLoadCloudVideoById(<?= (int)$cloudVideos[0]['id'] ?>)"
                                    class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-xs font-semibold border border-slate-700 cursor-pointer"
                                >
                                    ☁️ Letztes Cloud-Video laden
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Render-/Export-Fortschritts-Overlay -->
                    <div id="vsRenderOverlay" class="hidden absolute inset-0 bg-slate-950/90 backdrop-blur-xs flex flex-col items-center justify-center p-6 text-center z-30 space-y-4">
                        <div class="w-14 h-14 rounded-2xl bg-violet-600/20 border border-violet-500/40 text-violet-300 flex items-center justify-center text-2xl animate-pulse">
                            ⚙️
                        </div>
                        <div class="space-y-1 max-w-sm">
                            <p id="vsRenderStatusTitle" class="text-sm sm:text-base font-bold text-white">
                                Video wird gerendert…
                            </p>
                            <p id="vsRenderStatusSub" class="text-xs text-slate-400">
                                Bitte dieses Browserfenster geöffnet lassen.
                            </p>
                        </div>
                        <div class="w-full max-w-xs h-3 bg-slate-800 rounded-full overflow-hidden border border-slate-700">
                            <div id="vsRenderProgressBar" class="h-full bg-gradient-to-r from-violet-500 to-rose-500 transition-all duration-150" style="width: 0%"></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span id="vsRenderPctLabel" class="font-mono text-xs font-bold text-violet-300">0 %</span>
                            <button
                                type="button"
                                onclick="vsCancelRender()"
                                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-red-600 text-slate-200 hover:text-white text-xs font-semibold border border-slate-700 transition-colors cursor-pointer"
                            >
                                ✖ Abbrechen
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Transport- & Einzelbild-Steuerung -->
                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            id="vsPlayPauseBtn"
                            onclick="vsTogglePlay()"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer"
                            title="Abspielen / Pause (Leertaste)"
                        >
                            <span id="vsPlayPauseIcon">▶️</span>
                            <span id="vsPlayPauseText">Abspielen</span>
                        </button>
                        <button
                            type="button"
                            onclick="vsSeekRelative(-1/30)"
                            class="px-2.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 cursor-pointer"
                            title="1 Frame zurück (Shift + Pfeil links)"
                        >
                            ⏮ -1F
                        </button>
                        <button
                            type="button"
                            onclick="vsSeekRelative(-1)"
                            class="px-2.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 cursor-pointer"
                            title="1 Sekunde zurück (Pfeil links)"
                        >
                            -1s
                        </button>
                        <button
                            type="button"
                            onclick="vsSeekRelative(1)"
                            class="px-2.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 cursor-pointer"
                            title="1 Sekunde vor (Pfeil rechts)"
                        >
                            +1s
                        </button>
                        <button
                            type="button"
                            onclick="vsSeekRelative(1/30)"
                            class="px-2.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 cursor-pointer"
                            title="1 Frame vor (Shift + Pfeil rechts)"
                        >
                            +1F ⏭
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onclick="vsSetTrimFromCurrent('in')"
                            class="px-2.5 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-800 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/80 text-xs font-bold cursor-pointer"
                            title="Aktuelle Position als Schnitt-Start [In] setzen (Taste I)"
                        >
                            [ Start hier
                        </button>
                        <button
                            type="button"
                            onclick="vsSetTrimFromCurrent('out')"
                            class="px-2.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/80 text-xs font-bold cursor-pointer"
                            title="Aktuelle Position als Schnitt-Ende [Out] setzen (Taste O)"
                        >
                            Ende hier ]
                        </button>
                        <button
                            type="button"
                            onclick="vsCaptureSnapshotToCloud()"
                            class="inline-flex items-center gap-1 px-3 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-600 hover:text-white text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800/80 text-xs font-bold transition-colors cursor-pointer"
                            title="Aktuelles Videobild inkl. Filter &amp; Bauchbinde als Foto in der MiniCloud speichern (Taste S)"
                        >
                            📸 Standbild in Cloud
                        </button>
                    </div>
                </div>

                <!-- VISUELLE FILMSTREIFEN-TIMELINE & TRIM-REGLER -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                    <div class="flex items-center justify-between gap-2 text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                        <span>🎞️ Filmstreifen-Zeitleiste &amp; Schnittbereich (In / Out)</span>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" id="vsLoopTrimCheck" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>Im Schnittbereich wiederholen (Loop)</span>
                        </label>
                    </div>

                    <!-- Filmstreifen + Abspielkopf + Ausgegraute Trim-Ränder -->
                    <div
                        id="vsTimelineTrack"
                        onclick="vsHandleTimelineClick(event)"
                        class="relative w-full h-16 rounded-xl bg-slate-900 border border-slate-700 overflow-hidden cursor-pointer select-none"
                    >
                        <div id="vsFilmstripRow" class="absolute inset-0 flex items-stretch opacity-85">
                            <div class="w-full flex items-center justify-center text-[11px] text-slate-400">
                                Filmstreifen-Vorschau erscheint automatisch nach dem Laden eines Videos…
                            </div>
                        </div>

                        <!-- Abgedunkelte Bereiche außerhalb von [In .. Out] -->
                        <div id="vsTrimShadeLeft" class="absolute inset-y-0 left-0 bg-slate-950/75 border-r-2 border-emerald-400 pointer-events-none" style="width: 0%"></div>
                        <div id="vsTrimShadeRight" class="absolute inset-y-0 right-0 bg-slate-950/75 border-l-2 border-amber-400 pointer-events-none" style="width: 0%"></div>

                        <!-- Roter Abspielkopf (Playhead) -->
                        <div id="vsPlayheadLine" class="absolute inset-y-0 w-0.5 bg-rose-500 shadow-[0_0_8px_#f43f5e] pointer-events-none" style="left: 0%">
                            <div class="w-3 h-3 -ml-[5px] rounded-full bg-rose-500 border-2 border-white"></div>
                        </div>
                    </div>

                    <!-- Präzisions-Regler für Abspielposition, Start (In) & Ende (Out) -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                        <div>
                            <div class="flex justify-between text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">
                                <span>🎯 Position (Scrub)</span>
                                <span id="vsScrubValLabel" class="font-mono">0.0s</span>
                            </div>
                            <input
                                type="range"
                                id="vsScrubSlider"
                                min="0"
                                max="100"
                                step="0.05"
                                value="0"
                                oninput="vsOnScrubSlider(this.value)"
                                class="w-full accent-rose-600 cursor-pointer"
                            >
                        </div>
                        <div>
                            <div class="flex justify-between text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 mb-1">
                                <span>[ Schnitt-Start (In)</span>
                                <input
                                    type="number"
                                    id="vsTrimInNum"
                                    min="0"
                                    step="0.1"
                                    value="0.0"
                                    onchange="vsOnTrimNumChange('in', this.value)"
                                    class="w-16 px-1.5 py-0.5 text-right font-mono text-[11px] rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700"
                                >
                            </div>
                            <input
                                type="range"
                                id="vsTrimInSlider"
                                min="0"
                                max="100"
                                step="0.05"
                                value="0"
                                oninput="vsOnTrimSlider('in', this.value)"
                                class="w-full accent-emerald-600 cursor-pointer"
                            >
                        </div>
                        <div>
                            <div class="flex justify-between text-[11px] font-semibold text-amber-700 dark:text-amber-400 mb-1">
                                <span>Schnitt-Ende (Out) ]</span>
                                <input
                                    type="number"
                                    id="vsTrimOutNum"
                                    min="0.1"
                                    step="0.1"
                                    value="0.0"
                                    onchange="vsOnTrimNumChange('out', this.value)"
                                    class="w-16 px-1.5 py-0.5 text-right font-mono text-[11px] rounded bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700"
                                >
                            </div>
                            <input
                                type="range"
                                id="vsTrimOutSlider"
                                min="0"
                                max="100"
                                step="0.05"
                                value="100"
                                oninput="vsOnTrimSlider('out', this.value)"
                                class="w-full accent-amber-500 cursor-pointer"
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECHTE SPALTE (5/12): 5 Studio-Werkzeug-Reiter + Export-Konsole -->
        <div class="xl:col-span-5 space-y-4">
            <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 sm:p-5 space-y-4">

                <!-- Reiter-Navigation -->
                <div class="grid grid-cols-5 gap-1 p-1 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 text-xs font-bold">
                    <button type="button" onclick="vsSwitchTab('geometry')" id="vsTabBtn-geometry" class="vs-tab-btn py-2 px-1.5 rounded-lg bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer text-center">
                        📐 Format
                    </button>
                    <button type="button" onclick="vsSwitchTab('text')" id="vsTabBtn-text" class="vs-tab-btn py-2 px-1.5 rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer text-center">
                        🔤 Titel
                    </button>
                    <button type="button" onclick="vsSwitchTab('color')" id="vsTabBtn-color" class="vs-tab-btn py-2 px-1.5 rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer text-center">
                        🎨 Filter
                    </button>
                    <button type="button" onclick="vsSwitchTab('audio')" id="vsTabBtn-audio" class="vs-tab-btn py-2 px-1.5 rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer text-center">
                        🔊 Ton/Musik
                    </button>
                    <button type="button" onclick="vsSwitchTab('export')" id="vsTabBtn-export" class="vs-tab-btn py-2 px-1.5 rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer text-center">
                        💾 Export
                    </button>
                </div>

                <!-- TAB 1: 📐 FORMAT, DREHEN, SPIEGELN & KINO-BLUR -->
                <div id="vsTabPanel-geometry" class="vs-tab-panel space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-2">
                            🔄 Video drehen &amp; spiegeln (z. B. bei Hochkant-Handyvideos)
                        </label>
                        <div class="grid grid-cols-4 gap-2">
                            <button type="button" onclick="vsRotateBy(-90)" class="py-2 px-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                                ↺ 90° links
                            </button>
                            <button type="button" onclick="vsRotateBy(90)" class="py-2 px-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                                ↻ 90° rechts
                            </button>
                            <button type="button" onclick="vsToggleFlip('h')" id="vsFlipHBtn" class="py-2 px-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                                ↔️ Spiegeln H
                            </button>
                            <button type="button" onclick="vsToggleFlip('v')" id="vsFlipVBtn" class="py-2 px-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                                ↕️ Spiegeln V
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-2">
                            📐 Ziel-Seitenverhältnis (Aspect Ratio)
                        </label>
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-1.5">
                            <?php
                            $ratios = [
                                'original' => 'Original',
                                '16:9'     => '16:9 TV',
                                '9:16'     => '9:16 Reels',
                                '1:1'      => '1:1 Quadrat',
                                '4:3'      => '4:3 Retro',
                            ];
                            foreach ($ratios as $rKey => $rLabel):
                            ?>
                                <button
                                    type="button"
                                    onclick="vsSetAspectRatio('<?= $rKey ?>')"
                                    id="vsRatioBtn-<?= $rKey ?>"
                                    class="vs-ratio-btn py-2 px-2 rounded-xl text-xs font-semibold border transition-colors cursor-pointer <?= $rKey === 'original' ? 'bg-brand-600 text-white border-brand-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700' ?>"
                                >
                                    <?= e($rLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="vsFitModeSelect" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                🖼️ Bildfüllung &amp; Ränder
                            </label>
                            <select
                                id="vsFitModeSelect"
                                onchange="vsState.fitMode = this.value; vsUpdateCanvasSize(); vsRenderSingleFrame();"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-white"
                            >
                                <option value="contain_blur">✨ Kino-Unschärfe-Rand (Blur)</option>
                                <option value="contain_black">⬛ Komplett einpassen (Schwarzer Rand)</option>
                                <option value="cover">🔍 Formatfüllend zuschneiden (Crop)</option>
                            </select>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                <span>🔍 Zusatz-Zoom</span>
                                <span id="vsZoomLabel" class="font-mono">100 %</span>
                            </div>
                            <input
                                type="range"
                                id="vsZoomSlider"
                                min="1.0"
                                max="2.5"
                                step="0.05"
                                value="1.0"
                                oninput="vsState.zoom = parseFloat(this.value); document.getElementById('vsZoomLabel').textContent = Math.round(vsState.zoom*100) + ' %'; vsRenderSingleFrame();"
                                class="w-full accent-brand-600 cursor-pointer mt-1.5"
                            >
                        </div>
                    </div>
                </div>

                <!-- TAB 2: 🔤 TEXT-BAUCHBINDE (LOWER THIRD) & WASSERZEICHEN -->
                <div id="vsTabPanel-text" class="vs-tab-panel hidden space-y-3.5">
                    <div>
                        <label for="vsTitleMain" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                            🔤 Haupttitel / Bauchbinde (Zeile 1)
                        </label>
                        <input
                            type="text"
                            id="vsTitleMain"
                            placeholder="z. B. Sommerfest 2026 – Vereinsmeisterschaft"
                            oninput="vsState.titleMain = this.value; vsRenderSingleFrame();"
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                        >
                    </div>

                    <div>
                        <label for="vsTitleSub" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                            💬 Untertitel / Zusatzzeile (Zeile 2, optional)
                        </label>
                        <input
                            type="text"
                            id="vsTitleSub"
                            placeholder="z. B. Finale auf Platz 1 · Aufgenommen mit MiniCloud"
                            oninput="vsState.titleSub = this.value; vsRenderSingleFrame();"
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                        >
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="vsTitleStyle" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                🎨 Banner-Stil
                            </label>
                            <select
                                id="vsTitleStyle"
                                onchange="vsState.titleStyle = this.value; vsRenderSingleFrame();"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                                <option value="glass">✨ Modernes Glas-Banner</option>
                                <option value="broadcast">📺 TV-Bauchbinde (Farbakzent)</option>
                                <option value="cinema">🎬 Kino-Untertitel (Kontur)</option>
                                <option value="pill">🏷️ Kompakte Kapsel</option>
                            </select>
                        </div>
                        <div>
                            <label for="vsTitlePos" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                📍 Position im Video
                            </label>
                            <select
                                id="vsTitlePos"
                                onchange="vsState.titlePos = this.value; vsRenderSingleFrame();"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                                <option value="bottom_left">↙️ Unten links (Bauchbinde)</option>
                                <option value="bottom_center">⬇️ Unten zentriert</option>
                                <option value="center">🎯 Bildmitte (Intro-Titel)</option>
                                <option value="top_left">↖️ Oben links</option>
                                <option value="top_center">⬆️ Oben zentriert</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 items-center">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Textfarbe</label>
                            <input type="color" id="vsTitleColor" value="#ffffff" oninput="vsState.titleColor = this.value; vsRenderSingleFrame();" class="w-full h-8 rounded-lg cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Akzentfarbe</label>
                            <input type="color" id="vsTitleAccent" value="#2563eb" oninput="vsState.titleAccent = this.value; vsRenderSingleFrame();" class="w-full h-8 rounded-lg cursor-pointer">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Textgröße</label>
                            <input type="range" id="vsTitleScale" min="0.7" max="1.8" step="0.05" value="1.0" oninput="vsState.titleScale = parseFloat(this.value); vsRenderSingleFrame();" class="w-full accent-brand-600 cursor-pointer">
                        </div>
                    </div>

                    <!-- Zeitspanne für die Text-Einblendung -->
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                        <label class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                id="vsTitleAllTimeCheck"
                                checked
                                onchange="vsState.titleAllTime = this.checked; document.getElementById('vsTitleTimeRangeBox').classList.toggle('hidden', this.checked); vsRenderSingleFrame();"
                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>⏱️ Während des gesamten Videos einblenden</span>
                        </label>
                        <div id="vsTitleTimeRangeBox" class="hidden grid grid-cols-2 gap-2 pt-1">
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-0.5">Einblenden ab (Sek.)</label>
                                <input type="number" id="vsTitleStartSec" min="0" step="0.5" value="0" oninput="vsState.titleStartSec = parseFloat(this.value)||0; vsRenderSingleFrame();" class="w-full px-2.5 py-1 rounded-lg text-xs font-mono">
                            </div>
                            <div>
                                <label class="block text-[11px] text-slate-500 mb-0.5">Ausblenden ab (Sek.)</label>
                                <input type="number" id="vsTitleEndSec" min="0.5" step="0.5" value="5" oninput="vsState.titleEndSec = parseFloat(this.value)||5; vsRenderSingleFrame();" class="w-full px-2.5 py-1 rounded-lg text-xs font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Wasserzeichen / Eck-Stempel -->
                    <div>
                        <label for="vsWatermarkText" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                            🏷️ Dezentes Wasserzeichen oben rechts (optional)
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                id="vsWatermarkText"
                                placeholder="z. B. © MiniCloud / Vereinsarchiv"
                                oninput="vsState.watermarkText = this.value; vsRenderSingleFrame();"
                                class="flex-1 px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                            >
                            <button
                                type="button"
                                onclick="const el=document.getElementById('vsWatermarkText'); el.value = '<?= e($appTitle) ?> · ' + new Date().toLocaleDateString('de-DE'); vsState.watermarkText = el.value; vsRenderSingleFrame();"
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 cursor-pointer shrink-0"
                            >
                                + Datum
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: 🎨 FARBKORREKTUR & 8 KINO-FILTER -->
                <div id="vsTabPanel-color" class="vs-tab-panel hidden space-y-3.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-2">
                            🎞️ 1-Klick Kino- &amp; Farb-Presets
                        </label>
                        <div class="grid grid-cols-4 gap-1.5">
                            <?php
                            $presets = [
                                'none'    => 'Original',
                                'cinema'  => '🎬 Kino',
                                'warm'    => '☀️ Sommer',
                                'cool'    => '❄️ Nordisch',
                                'vintage' => '🎞️ Vintage',
                                'bw'      => '⚫⚪ S/W',
                                'vivid'   => '🌈 Brillant',
                                'vhs'     => '📹 Retro',
                            ];
                            foreach ($presets as $pKey => $pLabel):
                            ?>
                                <button
                                    type="button"
                                    onclick="vsApplyColorPreset('<?= $pKey ?>')"
                                    id="vsPresetBtn-<?= $pKey ?>"
                                    class="vs-preset-btn py-1.5 px-2 rounded-xl text-[11px] font-semibold border transition-colors cursor-pointer <?= $pKey === 'none' ? 'bg-brand-600 text-white border-brand-600' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700' ?>"
                                >
                                    <?= e($pLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="space-y-2.5 pt-1">
                        <div>
                            <div class="flex justify-between text-xs font-semibold mb-0.5">
                                <span>☀️ Helligkeit</span>
                                <span id="vsBrightnessLbl" class="font-mono">100 %</span>
                            </div>
                            <input type="range" id="vsBrightness" min="50" max="160" value="100" oninput="vsState.brightness=Number(this.value); document.getElementById('vsBrightnessLbl').textContent=this.value+' %'; vsRenderSingleFrame();" class="w-full accent-brand-600 cursor-pointer">
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-semibold mb-0.5">
                                <span>◐ Kontrast</span>
                                <span id="vsContrastLbl" class="font-mono">100 %</span>
                            </div>
                            <input type="range" id="vsContrast" min="50" max="170" value="100" oninput="vsState.contrast=Number(this.value); document.getElementById('vsContrastLbl').textContent=this.value+' %'; vsRenderSingleFrame();" class="w-full accent-brand-600 cursor-pointer">
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-semibold mb-0.5">
                                <span>🌈 Farbsättigung</span>
                                <span id="vsSaturateLbl" class="font-mono">100 %</span>
                            </div>
                            <input type="range" id="vsSaturate" min="0" max="200" value="100" oninput="vsState.saturate=Number(this.value); document.getElementById('vsSaturateLbl').textContent=this.value+' %'; vsRenderSingleFrame();" class="w-full accent-brand-600 cursor-pointer">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="flex justify-between text-xs font-semibold mb-0.5">
                                    <span>🌡️ Sepia / Wärme</span>
                                    <span id="vsSepiaLbl" class="font-mono">0 %</span>
                                </div>
                                <input type="range" id="vsSepia" min="0" max="100" value="0" oninput="vsState.sepia=Number(this.value); document.getElementById('vsSepiaLbl').textContent=this.value+' %'; vsRenderSingleFrame();" class="w-full accent-amber-500 cursor-pointer">
                            </div>
                            <div>
                                <div class="flex justify-between text-xs font-semibold mb-0.5">
                                    <span>🌑 Kino-Vignette</span>
                                    <span id="vsVignetteLbl" class="font-mono">0 %</span>
                                </div>
                                <input type="range" id="vsVignette" min="0" max="100" value="0" oninput="vsState.vignette=Number(this.value); document.getElementById('vsVignetteLbl').textContent=this.value+' %'; vsRenderSingleFrame();" class="w-full accent-violet-600 cursor-pointer">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: 🔊 TONSPUR, CLOUD-HINTERGRUNDMUSIK & GESCHWINDIGKEIT -->
                <div id="vsTabPanel-audio" class="vs-tab-panel hidden space-y-4">
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer">
                                <input
                                    type="checkbox"
                                    id="vsMuteOrigCheck"
                                    onchange="vsToggleMuteOriginal(this.checked)"
                                    class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"
                                >
                                <span>🔇 Original-Videoton stummschalten (z. B. gegen Windgeräusche)</span>
                            </label>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
                                <span>🔊 Lautstärke Originalton</span>
                                <span id="vsOrigVolLbl" class="font-mono">100 %</span>
                            </div>
                            <input
                                type="range"
                                id="vsOrigVolSlider"
                                min="0"
                                max="150"
                                value="100"
                                oninput="vsSetOrigVolume(Number(this.value))"
                                class="w-full accent-brand-600 cursor-pointer"
                            >
                        </div>
                    </div>

                    <!-- Hintergrundmusik aus der MiniCloud darunterlegen -->
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                        <label for="vsCloudMusicSelect" class="block text-xs font-bold text-slate-800 dark:text-slate-100">
                            🎵 Hintergrundmusik dazumischen (aus deiner MiniCloud oder lokal)
                        </label>
                        <div class="flex gap-2">
                            <select
                                id="vsCloudMusicSelect"
                                onchange="vsSelectBgMusic(this.value)"
                                class="flex-1 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs"
                            >
                                <option value="">— Keine Hintergrundmusik —</option>
                                <?php foreach ($cloudAudios as $ca): ?>
                                    <option value="<?= e($ca['inline_url']) ?>">
                                        🎵 <?= e($ca['name']) ?> (<?= e($ca['size_human']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label class="px-2.5 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 text-xs font-semibold cursor-pointer shrink-0">
                                📂 MP3
                                <input type="file" accept="audio/*" class="hidden" onchange="if(this.files[0]) vsLoadLocalBgMusic(this.files[0])">
                            </label>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
                                <span>🎶 Lautstärke Hintergrundmusik</span>
                                <span id="vsBgVolLbl" class="font-mono">40 %</span>
                            </div>
                            <input
                                type="range"
                                id="vsBgVolSlider"
                                min="0"
                                max="100"
                                value="40"
                                oninput="vsSetBgVolume(Number(this.value))"
                                class="w-full accent-rose-600 cursor-pointer"
                            >
                        </div>
                    </div>

                    <!-- Ein-/Ausblenden & Tempo -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-200">🌗 Sanfte Blenden (Video &amp; Ton)</p>
                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input type="checkbox" id="vsFadeInCheck" onchange="vsState.fadeIn = this.checked;" class="rounded border-slate-300 text-brand-600">
                                <span>Einblenden (1,0 Sek. Schwarzblende)</span>
                            </label>
                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input type="checkbox" id="vsFadeOutCheck" onchange="vsState.fadeOut = this.checked;" class="rounded border-slate-300 text-brand-600">
                                <span>Ausblenden am Ende (1,0 Sek.)</span>
                            </label>
                        </div>

                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                            <label for="vsSpeedSelect" class="block text-xs font-bold text-slate-700 dark:text-slate-200">
                                ⏩ Geschwindigkeit (Zeitlupe / Raffer)
                            </label>
                            <select
                                id="vsSpeedSelect"
                                onchange="vsSetPlaybackSpeed(parseFloat(this.value))"
                                class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                                <option value="0.5">🐢 0.5× (Zeitlupe)</option>
                                <option value="0.75">0.75× (Langsam)</option>
                                <option value="1" selected>▶️ 1.0× (Originaltempo)</option>
                                <option value="1.25">1.25× (Flott)</option>
                                <option value="1.5">🐇 1.5× (Schnell)</option>
                                <option value="2">⚡ 2.0× (Zeitraffer)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: 💾 EXPORT & CLOUD-SPEICHERUNG -->
                <div id="vsTabPanel-export" class="vs-tab-panel hidden space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="vsExportName" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                📝 Zieldateiname
                            </label>
                            <input
                                type="text"
                                id="vsExportName"
                                value="Video-Schnitt"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                        </div>
                        <div>
                            <label for="vsExportFolder" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                📁 Zielordner in deiner MiniCloud
                            </label>
                            <input
                                type="text"
                                id="vsExportFolder"
                                list="vsFolderPresets"
                                value="Videos"
                                placeholder="z. B. Videos"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                            <datalist id="vsFolderPresets">
                                <?php foreach ($userFolders as $uf): ?>
                                    <option value="<?= e($uf) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="vsExportQuality" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                🎚️ Auflösung &amp; Bitrate
                            </label>
                            <select
                                id="vsExportQuality"
                                onchange="vsUpdateCanvasSize(); vsRenderSingleFrame();"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                                <option value="720p">Kompakt HD (720p · 3,5 Mbit/s)</option>
                                <option value="1080p" selected>Full-HD (1080p · 6 Mbit/s)</option>
                                <option value="max">Maximum (Original · 10 Mbit/s)</option>
                            </select>
                        </div>
                        <div>
                            <label for="vsExportFps" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">
                                🎞️ Bildrate (FPS)
                            </label>
                            <select
                                id="vsExportFps"
                                class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold"
                            >
                                <option value="24">24 FPS (Kino-Look)</option>
                                <option value="30" selected>30 FPS (Standard flüssig)</option>
                                <option value="60">60 FPS (Sport / Action)</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl bg-violet-50/70 dark:bg-violet-950/40 border border-violet-200/80 dark:border-violet-800/70 text-[11px] text-slate-600 dark:text-slate-300 space-y-1">
                        <p class="font-bold text-violet-900 dark:text-violet-200">
                            💡 Frei verfügbarer Cloud-Speicher: <strong><?= e(formatBytes($storageStats['free'])) ?></strong>
                        </p>
                        <p>
                            Beim Rendern werden sämtliche Drehungen, Seitenverhältnisse, Kino-Unschärferänder, Farbfilter, Bauchbinden sowie die gemischte Tonspur direkt eingerechnet und per 2-MB-Chunk-Transfer ausfallsicher in deiner Cloud gespeichert.
                        </p>
                    </div>
                </div>

                <!-- IMMER SICHTBARE HAUPT-EXPORT-BUTTONS UNTEN RECHTS -->
                <div class="pt-3 border-t border-slate-200/80 dark:border-slate-800 space-y-2.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <button
                            type="button"
                            onclick="vsRenderAndExport('cloud')"
                            class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-violet-600 to-brand-600 hover:from-violet-700 hover:to-brand-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-all cursor-pointer flex items-center justify-center gap-2"
                        >
                            <span>☁️ Video rendern &amp; in Cloud speichern</span>
                        </button>
                        <button
                            type="button"
                            onclick="vsRenderAndExport('download')"
                            class="w-full py-3 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 font-bold text-xs sm:text-sm border border-slate-300 dark:border-slate-700 transition-colors cursor-pointer flex items-center justify-center gap-2"
                        >
                            <span>⬇️ Video rendern &amp; herunterladen</span>
                        </button>
                    </div>

                    <?php if ($ffmpegBin !== null): ?>
                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <button
                                type="button"
                                onclick="vsRunServerFfmpeg('copy_mp4')"
                                class="py-2 px-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-600 hover:text-white text-emerald-800 dark:text-emerald-300 border border-emerald-300/70 dark:border-emerald-800/70 text-xs font-bold transition-colors cursor-pointer"
                                title="Verlustfreies Sofort-Trimmen ohne Neukodierung direkt über Server-FFmpeg"
                            >
                                ⚡ Verlustfreier Sofort-Trim (.mp4)
                            </button>
                            <button
                                type="button"
                                onclick="vsRunServerFfmpeg('extract_mp3')"
                                class="py-2 px-3 rounded-xl bg-rose-50 dark:bg-rose-950/60 hover:bg-rose-600 hover:text-white text-rose-800 dark:text-rose-300 border border-rose-300/70 dark:border-rose-800/70 text-xs font-bold transition-colors cursor-pointer"
                            >
                                🎵 Tonspur als .mp3 in Cloud
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Ergebnis-Meldung nach erfolgreichem Cloud-Speichern -->
                    <div id="vsExportResultBox" class="hidden p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/70 border border-emerald-300 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200 text-xs space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <p id="vsExportResultText" class="font-bold"></p>
                            <button type="button" onclick="this.closest('#vsExportResultBox').classList.add('hidden')" class="text-emerald-600 hover:text-emerald-800 font-bold cursor-pointer">✕</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a id="vsExportResultDl" href="#" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs">
                                ⬇️ Datei herunterladen
                            </a>
                            <a href="index.php" class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 font-semibold text-xs">
                                📁 In „Meine Dateien“ anzeigen
                            </a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Tastaturkürzel-Spickzettel -->
            <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-3.5 text-[11px] text-slate-500 dark:text-slate-400 flex flex-wrap items-center justify-between gap-2">
                <span>⌨️ <strong>Studio-Shortcuts:</strong></span>
                <span><kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">Leertaste</kbd> Play/Pause</span>
                <span><kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">I</kbd> Start [</span>
                <span><kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">O</kbd> Ende ]</span>
                <span><kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">S</kbd> Standbild</span>
                <span><kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">←/→</kbd> ±1s</span>
            </div>
        </div>
    </div>
</div>

<script>
const VS_CSRF_TOKEN   = <?= json_encode(getCsrfToken()) ?>;
const VS_CLOUD_VIDEOS = <?= json_encode($cloudVideos, JSON_UNESCAPED_UNICODE) ?>;
const VS_PRESELECTED  = <?= json_encode($preselectedVideo, JSON_UNESCAPED_UNICODE) ?>;

const vsVideo   = document.getElementById('vsSourceVideo');
const vsBgAudio = document.getElementById('vsBgAudio');
const vsCanvas  = document.getElementById('vsPreviewCanvas');
const vsCtx     = vsCanvas.getContext('2d');

let vsRafId            = null;
let vsIsRendering      = false;
let vsCancelRequested  = false;
let vsActiveRecorder   = null;
let vsLiveCaptureStream= null;
let vsLiveCaptureRec   = null;
let vsLiveCaptureChunks= [];
let vsLiveCaptureTimerId = null;
let vsLiveCaptureSec   = 0;

// WebAudio-Graph für Live-Vorschau & Export-Mischung (Originalton + Hintergrundmusik)
let vsAudioCtx         = null;
let vsVideoSourceNode  = null;
let vsBgSourceNode     = null;
let vsVideoGainNode    = null;
let vsBgGainNode       = null;
let vsStreamDestNode   = null;

const vsState = {
    sourceCloudId: 0,
    sourceName: '',
    duration: 0,
    trimIn: 0,
    trimOut: 0,
    rotation: 0,        // 0, 90, 180, 270
    flipH: false,
    flipV: false,
    aspectRatio: 'original', // original, 16:9, 9:16, 1:1, 4:3
    fitMode: 'contain_blur', // contain_blur, contain_black, cover
    zoom: 1.0,
    brightness: 100,
    contrast: 100,
    saturate: 100,
    sepia: 0,
    vignette: 0,
    preset: 'none',
    titleMain: '',
    titleSub: '',
    titleStyle: 'glass',
    titlePos: 'bottom_left',
    titleColor: '#ffffff',
    titleAccent: '#2563eb',
    titleScale: 1.0,
    titleAllTime: true,
    titleStartSec: 0,
    titleEndSec: 5,
    watermarkText: '',
    muteOrig: false,
    origVolume: 1.0,
    bgMusicUrl: '',
    bgVolume: 0.4,
    fadeIn: false,
    fadeOut: false,
    speed: 1.0,
};

function vsFormatTime(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    const m = Math.floor(sec / 60);
    const s = (sec % 60).toFixed(1).padStart(4, '0');
    return String(m).padStart(2, '0') + ':' + s;
}

function vsSwitchTab(tabKey) {
    document.querySelectorAll('.vs-tab-panel').forEach(el => el.classList.add('hidden'));
    const target = document.getElementById('vsTabPanel-' + tabKey);
    if (target) target.classList.remove('hidden');

    document.querySelectorAll('.vs-tab-btn').forEach(btn => {
        btn.className = 'vs-tab-btn py-2 px-1.5 rounded-lg text-slate-700 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-700/60 transition-colors cursor-pointer text-center';
    });
    const activeBtn = document.getElementById('vsTabBtn-' + tabKey);
    if (activeBtn) {
        activeBtn.className = 'vs-tab-btn py-2 px-1.5 rounded-lg bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer text-center';
    }
}

function vsEnsureAudioGraph() {
    if (vsAudioCtx) {
        if (vsAudioCtx.state === 'suspended') {
            vsAudioCtx.resume().catch(() => {});
        }
        return;
    }
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;

    vsAudioCtx        = new AudioContextClass();
    vsVideoSourceNode = vsAudioCtx.createMediaElementSource(vsVideo);
    vsBgSourceNode    = vsAudioCtx.createMediaElementSource(vsBgAudio);
    vsVideoGainNode   = vsAudioCtx.createGain();
    vsBgGainNode      = vsAudioCtx.createGain();
    vsStreamDestNode  = vsAudioCtx.createMediaStreamDestination();

    vsVideoGainNode.gain.value = vsState.muteOrig ? 0 : vsState.origVolume;
    vsBgGainNode.gain.value    = vsState.bgVolume;

    vsVideoSourceNode.connect(vsVideoGainNode);
    vsVideoGainNode.connect(vsAudioCtx.destination);
    vsVideoGainNode.connect(vsStreamDestNode);

    vsBgSourceNode.connect(vsBgGainNode);
    vsBgGainNode.connect(vsAudioCtx.destination);
    vsBgGainNode.connect(vsStreamDestNode);
}

function vsLoadCloudVideoById(fileId) {
    const item = VS_CLOUD_VIDEOS.find(v => Number(v.id) === Number(fileId));
    if (!item) return;
    vsLoadVideoSource(item.inline_url, item.name, Number(item.id), item.folder || 'Videos');
}

function vsLoadLocalFile(file) {
    if (!file) return;
    const objUrl = URL.createObjectURL(file);
    vsLoadVideoSource(objUrl, file.name, 0, 'Videos');
}

function vsLoadVideoSource(url, name, cloudId = 0, defaultFolder = 'Videos') {
    vsVideo.pause();
    vsBgAudio.pause();
    vsState.sourceCloudId = cloudId;
    vsState.sourceName    = name || 'Video.mp4';

    const baseName = vsState.sourceName.replace(/\.[^/.]+$/, '');
    const expNameEl = document.getElementById('vsExportName');
    if (expNameEl) expNameEl.value = baseName + '-Schnitt';
    const expFolderEl = document.getElementById('vsExportFolder');
    if (expFolderEl && defaultFolder) expFolderEl.value = defaultFolder;

    document.getElementById('vsEmptyOverlay').classList.add('hidden');
    const badge = document.getElementById('vsLoadedBadge');
    if (badge) badge.textContent = '🎬 ' + vsState.sourceName;

    vsVideo.src = url;
    vsVideo.load();
}

vsVideo.addEventListener('loadedmetadata', () => {
    const dur = isFinite(vsVideo.duration) && vsVideo.duration > 0 ? vsVideo.duration : 10;
    vsState.duration = dur;
    vsState.trimIn   = 0;
    vsState.trimOut  = dur;

    const scrub = document.getElementById('vsScrubSlider');
    const inSl  = document.getElementById('vsTrimInSlider');
    const outSl = document.getElementById('vsTrimOutSlider');
    [scrub, inSl, outSl].forEach(sl => {
        if (sl) sl.max = String(dur.toFixed(2));
    });
    if (scrub) scrub.value = '0';
    if (inSl)  inSl.value  = '0';
    if (outSl) outSl.value = String(dur.toFixed(2));

    document.getElementById('vsTrimInNum').value  = '0.0';
    document.getElementById('vsTrimOutNum').value = dur.toFixed(1);
    document.getElementById('vsDurationLabel').textContent = vsFormatTime(dur);

    vsUpdateCanvasSize();
    vsSyncTimelineUi();
    vsVideo.currentTime = 0;
    setTimeout(() => {
        vsRenderSingleFrame();
        vsGenerateFilmstripThumbnails();
    }, 120);
});

vsVideo.addEventListener('seeked', () => {
    if (!vsIsRendering && vsVideo.paused) {
        vsRenderSingleFrame();
    }
});

function vsUpdateCanvasSize() {
    const vw = vsVideo.videoWidth  || 1280;
    const vh = vsVideo.videoHeight || 720;
    const rotated90 = (Math.abs(vsState.rotation) % 180) === 90;
    const natW = rotated90 ? vh : vw;
    const natH = rotated90 ? vw : vh;

    const qMode = document.getElementById('vsExportQuality')?.value || '1080p';
    const maxDim = qMode === '720p' ? 1280 : (qMode === '1080p' ? 1920 : Math.max(1920, Math.max(natW, natH)));

    let targetRatio = natW / Math.max(1, natH);
    if (vsState.aspectRatio === '16:9') targetRatio = 16 / 9;
    else if (vsState.aspectRatio === '9:16') targetRatio = 9 / 16;
    else if (vsState.aspectRatio === '1:1') targetRatio = 1;
    else if (vsState.aspectRatio === '4:3') targetRatio = 4 / 3;

    let cw, ch;
    if (targetRatio >= 1) {
        cw = Math.min(maxDim, Math.max(640, natW));
        ch = Math.round(cw / targetRatio);
    } else {
        ch = Math.min(maxDim, Math.max(640, natH));
        cw = Math.round(ch * targetRatio);
    }
    // Gerade Pixelzahlen für Video-Encoder sicherstellen
    cw = Math.max(320, cw - (cw % 2));
    ch = Math.max(320, ch - (ch % 2));

    vsCanvas.width  = cw;
    vsCanvas.height = ch;

    const resBadge = document.getElementById('vsResolutionBadge');
    if (resBadge) {
        resBadge.textContent = cw + '×' + ch + ' px';
        resBadge.classList.remove('hidden');
    }
}

function vsBuildCssFilter() {
    return `brightness(${vsState.brightness}%) contrast(${vsState.contrast}%) saturate(${vsState.saturate}%) sepia(${vsState.sepia}%)`;
}

function vsRenderSingleFrame() {
    const cw = vsCanvas.width;
    const ch = vsCanvas.height;
    vsCtx.save();
    vsCtx.clearRect(0, 0, cw, ch);

    // 1. Hintergrund
    vsCtx.fillStyle = '#020617';
    vsCtx.fillRect(0, 0, cw, ch);

    if (vsVideo.readyState >= 2) {
        const vw = vsVideo.videoWidth  || 1280;
        const vh = vsVideo.videoHeight || 720;
        const rotRad = (vsState.rotation * Math.PI) / 180;
        const rotated90 = (Math.abs(vsState.rotation) % 180) === 90;
        const effW = rotated90 ? vh : vw;
        const effH = rotated90 ? vw : vh;

        // Optionaler Kino-Unschärfe-Hintergrund (wenn Seitenverhältnis abweicht)
        if (vsState.fitMode === 'contain_blur') {
            vsCtx.save();
            vsCtx.filter = `blur(24px) brightness(${Math.max(40, vsState.brightness - 30)}%) saturate(${vsState.saturate}%)`;
            const coverScale = Math.max(cw / effW, ch / effH) * 1.08;
            vsCtx.translate(cw / 2, ch / 2);
            vsCtx.rotate(rotRad);
            vsCtx.scale(vsState.flipH ? -coverScale : coverScale, vsState.flipV ? -coverScale : coverScale);
            vsCtx.drawImage(vsVideo, -vw / 2, -vh / 2, vw, vh);
            vsCtx.restore();
        }

        // Haupt-Videobild zeichnen
        vsCtx.save();
        vsCtx.filter = vsBuildCssFilter();
        const baseScale = (vsState.fitMode === 'cover')
            ? Math.max(cw / effW, ch / effH)
            : Math.min(cw / effW, ch / effH);
        const finalScale = baseScale * vsState.zoom;

        vsCtx.translate(cw / 2, ch / 2);
        vsCtx.rotate(rotRad);
        vsCtx.scale(vsState.flipH ? -finalScale : finalScale, vsState.flipV ? -finalScale : finalScale);
        vsCtx.drawImage(vsVideo, -vw / 2, -vh / 2, vw, vh);
        vsCtx.restore();

        // Retro-VHS-Scanlines falls Preset 'vhs' aktiv
        if (vsState.preset === 'vhs') {
            vsCtx.save();
            vsCtx.fillStyle = 'rgba(0, 0, 0, 0.16)';
            for (let y = 0; y < ch; y += 4) {
                vsCtx.fillRect(0, y, cw, 1.5);
            }
            vsCtx.restore();
        }
    }

    // 2. Kino-Vignette
    if (vsState.vignette > 0) {
        const rad = Math.max(cw, ch) * 0.72;
        const grad = vsCtx.createRadialGradient(cw / 2, ch / 2, rad * 0.35, cw / 2, ch / 2, rad);
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, `rgba(0,0,0,${(vsState.vignette / 100) * 0.82})`);
        vsCtx.fillStyle = grad;
        vsCtx.fillRect(0, 0, cw, ch);
    }

    // 3. Text-Bauchbinde (Lower Third) & Untertitel
    const curT = vsVideo.currentTime || 0;
    const relT = Math.max(0, curT - vsState.trimIn);
    const trimDur = Math.max(0.1, vsState.trimOut - vsState.trimIn);

    const showTitle = (vsState.titleMain.trim() !== '' || vsState.titleSub.trim() !== '')
        && (vsState.titleAllTime || (curT >= vsState.titleStartSec && curT <= vsState.titleEndSec));

    if (showTitle) {
        vsDrawTitleOverlay(vsCtx, cw, ch);
    }

    // 4. Wasserzeichen oben rechts
    if (vsState.watermarkText.trim() !== '') {
        vsCtx.save();
        const wmFont = Math.max(13, Math.round(ch * 0.024));
        vsCtx.font = `600 ${wmFont}px system-ui, -apple-system, sans-serif`;
        const txt = vsState.watermarkText.trim();
        const tw = vsCtx.measureText(txt).width;
        const padX = 12, padY = 7;
        const bx = cw - tw - padX * 2 - 20;
        const by = 18;
        vsCtx.fillStyle = 'rgba(15, 23, 42, 0.55)';
        vsCtx.beginPath();
        vsCtx.roundRect(bx, by, tw + padX * 2, wmFont + padY * 2, 8);
        vsCtx.fill();
        vsCtx.fillStyle = 'rgba(255, 255, 255, 0.92)';
        vsCtx.fillText(txt, bx + padX, by + padY + wmFont * 0.82);
        vsCtx.restore();
    }

    // 5. Schwarzblende am Anfang / Ende (Fade-In / Fade-Out)
    let fadeAlpha = 0;
    if (vsState.fadeIn && relT < 1.0) {
        fadeAlpha = Math.max(fadeAlpha, 1.0 - relT);
    }
    if (vsState.fadeOut && (trimDur - relT) < 1.0) {
        fadeAlpha = Math.max(fadeAlpha, 1.0 - Math.max(0, trimDur - relT));
    }
    if (fadeAlpha > 0.01) {
        vsCtx.fillStyle = `rgba(0, 0, 0, ${Math.min(1, fadeAlpha)})`;
        vsCtx.fillRect(0, 0, cw, ch);
    }

    vsCtx.restore();
}

function vsDrawTitleOverlay(ctx, cw, ch) {
    ctx.save();
    const scale = vsState.titleScale || 1.0;
    const mainFontPx = Math.max(18, Math.round(ch * 0.045 * scale));
    const subFontPx  = Math.max(13, Math.round(ch * 0.028 * scale));

    const mainTxt = vsState.titleMain.trim();
    const subTxt  = vsState.titleSub.trim();

    ctx.font = `800 ${mainFontPx}px system-ui, -apple-system, sans-serif`;
    const mainW = mainTxt ? ctx.measureText(mainTxt).width : 0;
    ctx.font = `600 ${subFontPx}px system-ui, -apple-system, sans-serif`;
    const subW  = subTxt ? ctx.measureText(subTxt).width : 0;

    const contentW = Math.max(mainW, subW);
    const padX = Math.round(mainFontPx * 0.75);
    const padY = Math.round(mainFontPx * 0.45);
    const boxW = contentW + padX * 2 + 12;
    const boxH = (mainTxt ? mainFontPx * 1.2 : 0) + (subTxt ? subFontPx * 1.35 : 0) + padY * 1.6;

    let bx = Math.round(cw * 0.045);
    let by = Math.round(ch - boxH - ch * 0.06);

    if (vsState.titlePos === 'bottom_center') {
        bx = Math.round((cw - boxW) / 2);
    } else if (vsState.titlePos === 'center') {
        bx = Math.round((cw - boxW) / 2);
        by = Math.round((ch - boxH) / 2);
    } else if (vsState.titlePos === 'top_left') {
        by = Math.round(ch * 0.06);
    } else if (vsState.titlePos === 'top_center') {
        bx = Math.round((cw - boxW) / 2);
        by = Math.round(ch * 0.06);
    }

    if (vsState.titleStyle === 'glass') {
        ctx.fillStyle = 'rgba(15, 23, 42, 0.78)';
        ctx.beginPath();
        ctx.roundRect(bx, by, boxW, boxH, 14);
        ctx.fill();
        ctx.fillStyle = vsState.titleAccent;
        ctx.beginPath();
        ctx.roundRect(bx, by, 7, boxH, [14, 0, 0, 14]);
        ctx.fill();
    } else if (vsState.titleStyle === 'broadcast') {
        ctx.fillStyle = vsState.titleAccent;
        ctx.beginPath();
        ctx.roundRect(bx, by, boxW, boxH, 10);
        ctx.fill();
    } else if (vsState.titleStyle === 'pill') {
        ctx.fillStyle = 'rgba(2, 6, 23, 0.85)';
        ctx.beginPath();
        ctx.roundRect(bx, by, boxW, boxH, boxH / 2);
        ctx.fill();
    }

    let textY = by + padY + (mainTxt ? mainFontPx * 0.88 : subFontPx * 0.88);
    const textX = bx + padX + 6;

    if (mainTxt) {
        ctx.font = `800 ${mainFontPx}px system-ui, -apple-system, sans-serif`;
        if (vsState.titleStyle === 'cinema') {
            ctx.strokeStyle = 'rgba(0,0,0,0.9)';
            ctx.lineWidth = Math.max(4, Math.round(mainFontPx * 0.16));
            ctx.strokeText(mainTxt, textX, textY);
        }
        ctx.fillStyle = vsState.titleColor;
        ctx.fillText(mainTxt, textX, textY);
        textY += subFontPx * 1.35;
    }

    if (subTxt) {
        ctx.font = `600 ${subFontPx}px system-ui, -apple-system, sans-serif`;
        if (vsState.titleStyle === 'cinema') {
            ctx.strokeStyle = 'rgba(0,0,0,0.9)';
            ctx.lineWidth = Math.max(3, Math.round(subFontPx * 0.15));
            ctx.strokeText(subTxt, textX, textY);
        }
        ctx.fillStyle = 'rgba(241, 245, 249, 0.92)';
        ctx.fillText(subTxt, textX, textY);
    }

    ctx.restore();
}

function vsPreviewLoop() {
    if (vsVideo.paused || vsVideo.ended) {
        vsUpdatePlayButtonUi();
        return;
    }

    const cur = vsVideo.currentTime;
    if (cur >= vsState.trimOut) {
        const loopOn = document.getElementById('vsLoopTrimCheck')?.checked;
        if (loopOn && !vsIsRendering) {
            vsVideo.currentTime = vsState.trimIn;
            if (vsState.bgMusicUrl) {
                vsBgAudio.currentTime = 0;
                vsBgAudio.play().catch(() => {});
            }
        } else {
            vsVideo.pause();
            vsBgAudio.pause();
            vsUpdatePlayButtonUi();
            return;
        }
    }

    // Audio-Fade-Gain live nachführen
    if (vsAudioCtx && vsVideoGainNode) {
        const relT = Math.max(0, cur - vsState.trimIn);
        const trimDur = Math.max(0.1, vsState.trimOut - vsState.trimIn);
        let gainMult = 1.0;
        if (vsState.fadeIn && relT < 1.0) gainMult = Math.min(gainMult, relT);
        if (vsState.fadeOut && (trimDur - relT) < 1.0) gainMult = Math.min(gainMult, Math.max(0, trimDur - relT));
        vsVideoGainNode.gain.value = (vsState.muteOrig ? 0 : vsState.origVolume) * gainMult;
        if (vsBgGainNode) vsBgGainNode.gain.value = vsState.bgVolume * gainMult;
    }

    vsRenderSingleFrame();
    vsSyncTimelineUi();
    vsRafId = requestAnimationFrame(vsPreviewLoop);
}

function vsTogglePlay() {
    if (!vsVideo.src) return;
    vsEnsureAudioGraph();

    if (vsVideo.paused) {
        if (vsVideo.currentTime < vsState.trimIn || vsVideo.currentTime >= vsState.trimOut - 0.05) {
            vsVideo.currentTime = vsState.trimIn;
        }
        vsVideo.playbackRate = vsState.speed;
        vsVideo.play().then(() => {
            if (vsState.bgMusicUrl) {
                vsBgAudio.currentTime = Math.max(0, vsVideo.currentTime - vsState.trimIn) % Math.max(1, vsBgAudio.duration || 60);
                vsBgAudio.play().catch(() => {});
            }
            vsUpdatePlayButtonUi();
            if (vsRafId) cancelAnimationFrame(vsRafId);
            vsRafId = requestAnimationFrame(vsPreviewLoop);
        }).catch(() => {});
    } else {
        vsVideo.pause();
        vsBgAudio.pause();
        vsUpdatePlayButtonUi();
    }
}

function vsUpdatePlayButtonUi() {
    const icon = document.getElementById('vsPlayPauseIcon');
    const txt  = document.getElementById('vsPlayPauseText');
    if (vsVideo.paused) {
        if (icon) icon.textContent = '▶️';
        if (txt)  txt.textContent  = 'Abspielen';
    } else {
        if (icon) icon.textContent = '⏸';
        if (txt)  txt.textContent  = 'Pause';
    }
}

function vsSeekRelative(deltaSec) {
    if (!vsVideo.src) return;
    const next = Math.max(0, Math.min(vsState.duration, (vsVideo.currentTime || 0) + deltaSec));
    vsVideo.currentTime = next;
    vsSyncTimelineUi();
}

function vsOnScrubSlider(val) {
    if (!vsVideo.src) return;
    vsVideo.currentTime = parseFloat(val) || 0;
    vsSyncTimelineUi();
}

function vsOnTrimSlider(which, val) {
    const v = parseFloat(val) || 0;
    if (which === 'in') {
        vsState.trimIn = Math.max(0, Math.min(v, vsState.trimOut - 0.2));
        document.getElementById('vsTrimInSlider').value = String(vsState.trimIn);
        document.getElementById('vsTrimInNum').value    = vsState.trimIn.toFixed(1);
        vsVideo.currentTime = vsState.trimIn;
    } else {
        vsState.trimOut = Math.min(vsState.duration, Math.max(v, vsState.trimIn + 0.2));
        document.getElementById('vsTrimOutSlider').value = String(vsState.trimOut);
        document.getElementById('vsTrimOutNum').value    = vsState.trimOut.toFixed(1);
        vsVideo.currentTime = vsState.trimOut;
    }
    vsSyncTimelineUi();
}

function vsOnTrimNumChange(which, val) {
    vsOnTrimSlider(which, val);
}

function vsSetTrimFromCurrent(which) {
    if (!vsVideo.src) return;
    vsOnTrimSlider(which, vsVideo.currentTime || 0);
}

function vsHandleTimelineClick(ev) {
    if (!vsVideo.src || vsState.duration <= 0) return;
    const track = document.getElementById('vsTimelineTrack');
    const rect  = track.getBoundingClientRect();
    const pct   = Math.max(0, Math.min(1, (ev.clientX - rect.left) / rect.width));
    vsVideo.currentTime = pct * vsState.duration;
    vsSyncTimelineUi();
}

function vsSyncTimelineUi() {
    const dur = Math.max(0.1, vsState.duration);
    const cur = vsVideo.currentTime || 0;

    document.getElementById('vsCurrentTimeLabel').textContent = vsFormatTime(cur);
    document.getElementById('vsScrubValLabel').textContent    = cur.toFixed(1) + 's';
    const scrub = document.getElementById('vsScrubSlider');
    if (scrub && document.activeElement !== scrub) {
        scrub.value = String(cur);
    }

    const trimLen = Math.max(0, vsState.trimOut - vsState.trimIn);
    document.getElementById('vsTrimDurationBadge').textContent = 'Schnittlänge: ' + trimLen.toFixed(1) + ' s';

    const leftPct  = (vsState.trimIn / dur) * 100;
    const rightPct = ((dur - vsState.trimOut) / dur) * 100;
    const headPct  = (cur / dur) * 100;

    document.getElementById('vsTrimShadeLeft').style.width  = leftPct + '%';
    document.getElementById('vsTrimShadeRight').style.width = Math.max(0, rightPct) + '%';
    document.getElementById('vsPlayheadLine').style.left    = Math.min(99.5, Math.max(0, headPct)) + '%';
}

// Erzeugt 10 Vorschaubilder (Filmstreifen) über ein separates Offscreen-Videoelement
async function vsGenerateFilmstripThumbnails() {
    const strip = document.getElementById('vsFilmstripRow');
    if (!strip || !vsVideo.src || vsState.duration <= 0) return;

    const thumbVideo = document.createElement('video');
    thumbVideo.src = vsVideo.src;
    thumbVideo.muted = true;
    thumbVideo.playsInline = true;
    thumbVideo.crossOrigin = 'anonymous';

    const count = 10;
    strip.innerHTML = '';

    try {
        await new Promise((res, rej) => {
            thumbVideo.onloadedmetadata = res;
            thumbVideo.onerror = rej;
        });

        for (let i = 0; i < count; i++) {
            const t = ((i + 0.5) / count) * vsState.duration;
            thumbVideo.currentTime = t;
            await new Promise(r => { thumbVideo.onseeked = r; setTimeout(r, 400); });

            const c = document.createElement('canvas');
            c.width  = 96;
            c.height = 54;
            const cx = c.getContext('2d');
            cx.drawImage(thumbVideo, 0, 0, c.width, c.height);
            c.className = 'flex-1 h-full object-cover border-r border-slate-800/70';
            strip.appendChild(c);
        }
    } catch (e) {
        // Fallback falls Browser paralleles Seeking blockiert
    }
}

function vsRotateBy(deg) {
    vsState.rotation = (vsState.rotation + deg + 360) % 360;
    vsUpdateCanvasSize();
    vsRenderSingleFrame();
}

function vsToggleFlip(axis) {
    if (axis === 'h') {
        vsState.flipH = !vsState.flipH;
        document.getElementById('vsFlipHBtn').classList.toggle('bg-brand-600', vsState.flipH);
        document.getElementById('vsFlipHBtn').classList.toggle('text-white', vsState.flipH);
    } else {
        vsState.flipV = !vsState.flipV;
        document.getElementById('vsFlipVBtn').classList.toggle('bg-brand-600', vsState.flipV);
        document.getElementById('vsFlipVBtn').classList.toggle('text-white', vsState.flipV);
    }
    vsRenderSingleFrame();
}

function vsSetAspectRatio(ratioKey) {
    vsState.aspectRatio = ratioKey;
    document.querySelectorAll('.vs-ratio-btn').forEach(btn => {
        btn.className = 'vs-ratio-btn py-2 px-2 rounded-xl text-xs font-semibold border transition-colors cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700';
    });
    const active = document.getElementById('vsRatioBtn-' + ratioKey);
    if (active) {
        active.className = 'vs-ratio-btn py-2 px-2 rounded-xl text-xs font-semibold border transition-colors cursor-pointer bg-brand-600 text-white border-brand-600';
    }
    vsUpdateCanvasSize();
    vsRenderSingleFrame();
}

function vsHandleStageWheel(ev) {
    if (!vsVideo.src) return;
    ev.preventDefault();
    const step = ev.deltaY < 0 ? 0.05 : -0.05;
    vsState.zoom = Math.max(1.0, Math.min(2.5, Number((vsState.zoom + step).toFixed(2))));
    document.getElementById('vsZoomSlider').value = String(vsState.zoom);
    document.getElementById('vsZoomLabel').textContent = Math.round(vsState.zoom * 100) + ' %';
    vsRenderSingleFrame();
}

function vsApplyColorPreset(presetKey) {
    vsState.preset = presetKey;
    const map = {
        none:    { b: 100, c: 100, s: 100, sep: 0,  vig: 0  },
        cinema:  { b: 98,  c: 122, s: 112, sep: 8,  vig: 38 },
        warm:    { b: 104, c: 108, s: 125, sep: 22, vig: 15 },
        cool:    { b: 102, c: 112, s: 88,  sep: 0,  vig: 20 },
        vintage: { b: 96,  c: 92,  s: 75,  sep: 48, vig: 45 },
        bw:      { b: 104, c: 135, s: 0,   sep: 0,  vig: 30 },
        vivid:   { b: 105, c: 118, s: 155, sep: 0,  vig: 10 },
        vhs:     { b: 102, c: 120, s: 130, sep: 18, vig: 50 },
    };
    const cfg = map[presetKey] || map.none;
    vsState.brightness = cfg.b;
    vsState.contrast   = cfg.c;
    vsState.saturate   = cfg.s;
    vsState.sepia      = cfg.sep;
    vsState.vignette   = cfg.vig;

    document.getElementById('vsBrightness').value = cfg.b;
    document.getElementById('vsBrightnessLbl').textContent = cfg.b + ' %';
    document.getElementById('vsContrast').value = cfg.c;
    document.getElementById('vsContrastLbl').textContent = cfg.c + ' %';
    document.getElementById('vsSaturate').value = cfg.s;
    document.getElementById('vsSaturateLbl').textContent = cfg.s + ' %';
    document.getElementById('vsSepia').value = cfg.sep;
    document.getElementById('vsSepiaLbl').textContent = cfg.sep + ' %';
    document.getElementById('vsVignette').value = cfg.vig;
    document.getElementById('vsVignetteLbl').textContent = cfg.vig + ' %';

    document.querySelectorAll('.vs-preset-btn').forEach(btn => {
        btn.className = 'vs-preset-btn py-1.5 px-2 rounded-xl text-[11px] font-semibold border transition-colors cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700';
    });
    const act = document.getElementById('vsPresetBtn-' + presetKey);
    if (act) {
        act.className = 'vs-preset-btn py-1.5 px-2 rounded-xl text-[11px] font-semibold border transition-colors cursor-pointer bg-brand-600 text-white border-brand-600';
    }
    vsRenderSingleFrame();
}

function vsToggleMuteOriginal(muted) {
    vsState.muteOrig = muted;
    if (vsVideoGainNode) {
        vsVideoGainNode.gain.value = muted ? 0 : vsState.origVolume;
    } else {
        vsVideo.muted = muted;
    }
}

function vsSetOrigVolume(pct) {
    vsState.origVolume = pct / 100;
    document.getElementById('vsOrigVolLbl').textContent = pct + ' %';
    if (vsVideoGainNode && !vsState.muteOrig) {
        vsVideoGainNode.gain.value = vsState.origVolume;
    }
}

function vsSelectBgMusic(url) {
    vsState.bgMusicUrl = url || '';
    if (url) {
        vsBgAudio.src = url;
        vsBgAudio.load();
    } else {
        vsBgAudio.pause();
        vsBgAudio.removeAttribute('src');
    }
}

function vsLoadLocalBgMusic(file) {
    const url = URL.createObjectURL(file);
    vsSelectBgMusic(url);
}

function vsSetBgVolume(pct) {
    vsState.bgVolume = pct / 100;
    document.getElementById('vsBgVolLbl').textContent = pct + ' %';
    if (vsBgGainNode) {
        vsBgGainNode.gain.value = vsState.bgVolume;
    }
}

function vsSetPlaybackSpeed(rate) {
    vsState.speed = rate || 1.0;
    vsVideo.playbackRate = vsState.speed;
}

function vsResetAllSettings() {
    vsState.rotation = 0;
    vsState.flipH = false;
    vsState.flipV = false;
    vsState.zoom = 1.0;
    document.getElementById('vsZoomSlider').value = '1.0';
    document.getElementById('vsZoomLabel').textContent = '100 %';
    vsSetAspectRatio('original');
    vsApplyColorPreset('none');
    vsState.titleMain = '';
    vsState.titleSub  = '';
    vsState.watermarkText = '';
    document.getElementById('vsTitleMain').value = '';
    document.getElementById('vsTitleSub').value  = '';
    document.getElementById('vsWatermarkText').value = '';
    if (vsState.duration > 0) {
        vsOnTrimSlider('in', 0);
        vsOnTrimSlider('out', vsState.duration);
    }
    vsRenderSingleFrame();
}

// ============================================================================
// CHUNKED CLOUD-UPLOAD FÜR STANDBILDER & FERTIG GESCHNITTENE VIDEOS
// ============================================================================
async function vsUploadBlobToCloud(blob, filename, folder, onProgress) {
    const chunkSize = 2 * 1024 * 1024; // 2 MB Chunks (100 % STRATO Shared-Hosting kompatibel)
    const initFd = new FormData();
    initFd.append('ajax_action', 'init_chunk_upload');
    initFd.append('csrf_token', VS_CSRF_TOKEN);
    initFd.append('filename', filename);
    initFd.append('folder', folder);
    initFd.append('total_size', String(blob.size));
    initFd.append('mime_type', blob.type || 'video/webm');

    const initRes  = await fetch('video_studio.php', { method: 'POST', body: initFd });
    const initJson = await initRes.json();
    if (!initJson.ok) {
        throw new Error(initJson.error || 'Upload-Initialisierung fehlgeschlagen.');
    }

    const uploadId = initJson.upload_id;
    let offset = 0;
    while (offset < blob.size) {
        const slice = blob.slice(offset, offset + chunkSize);
        const cFd   = new FormData();
        cFd.append('ajax_action', 'upload_chunk');
        cFd.append('csrf_token', VS_CSRF_TOKEN);
        cFd.append('upload_id', uploadId);
        cFd.append('chunk', slice, 'chunk.bin');

        const cRes  = await fetch('video_studio.php', { method: 'POST', body: cFd });
        const cJson = await cRes.json();
        if (!cJson.ok) {
            throw new Error(cJson.error || 'Fehler beim Chunk-Upload.');
        }
        offset += slice.size;
        if (onProgress) onProgress(Math.min(100, Math.round((offset / blob.size) * 100)));
    }

    const finFd = new FormData();
    finFd.append('ajax_action', 'finalize_chunk_upload');
    finFd.append('csrf_token', VS_CSRF_TOKEN);
    finFd.append('upload_id', uploadId);

    const finRes  = await fetch('video_studio.php', { method: 'POST', body: finFd });
    const finJson = await finRes.json();
    if (!finJson.ok) {
        throw new Error(finJson.error || 'Fehler beim Abschließen des Uploads.');
    }
    return finJson;
}

// 📸 1-Klick Standbild-Schnappschuss in voller Auflösung in der Cloud speichern
async function vsCaptureSnapshotToCloud() {
    if (!vsVideo.src) return;
    vsRenderSingleFrame();

    const baseName = (document.getElementById('vsExportName')?.value || 'Standbild').trim();
    const folder   = (document.getElementById('vsExportFolder')?.value || 'Fotos').trim();
    const snapName = baseName + '-Frame-' + Math.round((vsVideo.currentTime || 0) * 10) / 10 + 's.jpg';

    vsCanvas.toBlob(async (blob) => {
        if (!blob) return;
        try {
            const res = await vsUploadBlobToCloud(blob, snapName, folder);
            vsShowExportResult(`📸 Standbild „${res.filename}“ (${res.size_human}) wurde in „/${res.folder || ''}“ gespeichert!`, res.download_url);
        } catch (err) {
            alert(err.message || 'Standbild konnte nicht gespeichert werden.');
        }
    }, 'image/jpeg', 0.92);
}

// 🎬 Video im Schnittbereich [In .. Out] samt allen Effekten & Audio rendern
async function vsRenderAndExport(targetMode = 'cloud') {
    if (!vsVideo.src || vsIsRendering) return;

    vsEnsureAudioGraph();
    vsVideo.pause();
    vsBgAudio.pause();
    vsUpdateCanvasSize();

    const fps   = parseInt(document.getElementById('vsExportFps')?.value || '30', 10);
    const qMode = document.getElementById('vsExportQuality')?.value || '1080p';
    const bps   = qMode === '720p' ? 3500000 : (qMode === '1080p' ? 6000000 : 10000000);

    const canvasStream = vsCanvas.captureStream(fps);
    const combinedTracks = [...canvasStream.getVideoTracks()];
    if (vsStreamDestNode && vsStreamDestNode.stream.getAudioTracks().length > 0) {
        combinedTracks.push(...vsStreamDestNode.stream.getAudioTracks());
    }
    const finalStream = new MediaStream(combinedTracks);

    const mimeCandidates = [
        'video/mp4;codecs=avc1',
        'video/mp4',
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm',
    ];
    let chosenMime = 'video/webm';
    for (const m of mimeCandidates) {
        if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) {
            chosenMime = m;
            break;
        }
    }
    const ext = chosenMime.includes('mp4') ? '.mp4' : '.webm';

    vsIsRendering     = true;
    vsCancelRequested = false;
    const overlay     = document.getElementById('vsRenderOverlay');
    const titleEl     = document.getElementById('vsRenderStatusTitle');
    const subEl       = document.getElementById('vsRenderStatusSub');
    const barEl       = document.getElementById('vsRenderProgressBar');
    const pctEl       = document.getElementById('vsRenderPctLabel');

    overlay.classList.remove('hidden');
    titleEl.textContent = '🎬 Video wird mit allen Effekten gerendert…';
    subEl.textContent   = 'Schnittbereich ' + vsFormatTime(vsState.trimIn) + ' bis ' + vsFormatTime(vsState.trimOut);
    barEl.style.width   = '0%';
    pctEl.textContent   = '0 %';

    const recordedChunks = [];
    vsActiveRecorder = new MediaRecorder(finalStream, { mimeType: chosenMime, videoBitsPerSecond: bps });
    vsActiveRecorder.ondataavailable = (ev) => {
        if (ev.data && ev.data.size > 0) recordedChunks.push(ev.data);
    };

    const renderPromise = new Promise((resolve, reject) => {
        vsActiveRecorder.onstop = () => resolve();
        vsActiveRecorder.onerror = (e) => reject(e);
    });

    vsVideo.currentTime  = vsState.trimIn;
    vsVideo.playbackRate = vsState.speed;
    await new Promise(r => { vsVideo.onseeked = r; setTimeout(r, 250); });

    if (vsState.bgMusicUrl) {
        vsBgAudio.currentTime = 0;
        vsBgAudio.play().catch(() => {});
    }

    vsActiveRecorder.start(250);
    await vsVideo.play();

    const totalTrimDur = Math.max(0.2, vsState.trimOut - vsState.trimIn);
    const monitorStep = () => {
        if (!vsIsRendering || vsCancelRequested) return;
        const cur = vsVideo.currentTime || vsState.trimIn;
        const pct = Math.min(99, Math.max(0, Math.round(((cur - vsState.trimIn) / totalTrimDur) * 100)));
        barEl.style.width = pct + '%';
        pctEl.textContent = pct + ' %';

        vsRenderSingleFrame();

        if (cur >= vsState.trimOut || vsVideo.ended) {
            vsVideo.pause();
            vsBgAudio.pause();
            if (vsActiveRecorder && vsActiveRecorder.state !== 'inactive') {
                vsActiveRecorder.stop();
            }
            return;
        }
        requestAnimationFrame(monitorStep);
    };
    requestAnimationFrame(monitorStep);

    try {
        await renderPromise;
        if (vsCancelRequested) {
            overlay.classList.add('hidden');
            vsIsRendering = false;
            return;
        }

        const outBlob  = new Blob(recordedChunks, { type: chosenMime });
        let baseName   = (document.getElementById('vsExportName')?.value || 'Video-Schnitt').trim();
        baseName       = baseName.replace(/\.(mp4|webm|mov|mkv)$/i, '') + ext;
        const folder   = (document.getElementById('vsExportFolder')?.value || 'Videos').trim();

        if (targetMode === 'download') {
            const dlUrl = URL.createObjectURL(outBlob);
            const a = document.createElement('a');
            a.href = dlUrl;
            a.download = baseName;
            document.body.appendChild(a);
            a.click();
            a.remove();
            overlay.classList.add('hidden');
            vsIsRendering = false;
            vsShowExportResult(`✅ Video „${baseName}“ wurde erfolgreich auf dein Gerät heruntergeladen!`, dlUrl);
        } else {
            titleEl.textContent = '☁️ Geschnittenes Video wird in deine MiniCloud gespeichert…';
            subEl.textContent   = '2-MB-Chunk-Transfer läuft…';
            const res = await vsUploadBlobToCloud(outBlob, baseName, folder, (uPct) => {
                barEl.style.width = uPct + '%';
                pctEl.textContent = uPct + ' %';
            });
            overlay.classList.add('hidden');
            vsIsRendering = false;
            vsShowExportResult(`🎉 Video „${res.filename}“ (${res.size_human}) wurde im Cloud-Ordner „/${res.folder || ''}“ gespeichert!`, res.download_url);
        }
    } catch (err) {
        overlay.classList.add('hidden');
        vsIsRendering = false;
        alert(err.message || 'Fehler beim Rendern des Videos.');
    }
}

function vsCancelRender() {
    vsCancelRequested = true;
    vsIsRendering = false;
    vsVideo.pause();
    vsBgAudio.pause();
    if (vsActiveRecorder && vsActiveRecorder.state !== 'inactive') {
        try { vsActiveRecorder.stop(); } catch (e) {}
    }
    document.getElementById('vsRenderOverlay').classList.add('hidden');
}

function vsShowExportResult(msg, dlUrl) {
    const box = document.getElementById('vsExportResultBox');
    const txt = document.getElementById('vsExportResultText');
    const dl  = document.getElementById('vsExportResultDl');
    if (box && txt && dl) {
        txt.textContent = msg;
        dl.href = dlUrl || '#';
        box.classList.remove('hidden');
    }
}

// Optionaler Server-FFmpeg Schnell-Trim / MP3-Extraktor
async function vsRunServerFfmpeg(mode) {
    if (!vsState.sourceCloudId) {
        alert('Bitte wähle dafür zuerst ein Video aus deiner MiniCloud aus.');
        return;
    }
    const fd = new FormData();
    fd.append('ajax_action', 'server_ffmpeg_trim');
    fd.append('csrf_token', VS_CSRF_TOKEN);
    fd.append('source_file_id', String(vsState.sourceCloudId));
    fd.append('start_sec', String(vsState.trimIn));
    fd.append('end_sec', String(vsState.trimOut));
    fd.append('filename', document.getElementById('vsExportName')?.value || 'Schnell-Trim');
    fd.append('folder', document.getElementById('vsExportFolder')?.value || 'Videos');
    fd.append('ffmpeg_mode', mode);

    const res  = await fetch('video_studio.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.ok) {
        vsShowExportResult(`⚡ Server-FFmpeg fertig: „${json.filename}“ (${json.size_human}) in „/${json.folder || ''}“ gespeichert!`, json.download_url);
    } else {
        alert(json.error || 'Server-FFmpeg-Verarbeitung fehlgeschlagen.');
    }
}

// ============================================================================
// 📹 LIVE-KAMERA & 🖥️ BILDSCHIRMAUFNAHME DIREKT IM STUDIO
// ============================================================================
async function vsStartCameraCapture(kind = 'camera') {
    try {
        if (kind === 'screen') {
            vsLiveCaptureStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: true });
        } else {
            vsLiveCaptureStream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720 }, audio: true });
        }

        const liveVid = document.getElementById('vsLivePreviewVideo');
        liveVid.srcObject = vsLiveCaptureStream;
        liveVid.classList.remove('hidden');
        vsCanvas.classList.add('hidden');
        document.getElementById('vsEmptyOverlay').classList.add('hidden');

        document.getElementById('vsLiveCaptureBar').classList.remove('hidden');
        document.getElementById('vsLiveCaptureTitle').textContent = kind === 'screen' ? '🖥️ Bildschirmaufnahme läuft…' : '📹 Kamera-Aufnahme läuft…';

        vsLiveCaptureChunks = [];
        vsLiveCaptureRec = new MediaRecorder(vsLiveCaptureStream);
        vsLiveCaptureRec.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) vsLiveCaptureChunks.push(e.data);
        };
        vsLiveCaptureRec.onstop = () => {
            const blob = new Blob(vsLiveCaptureChunks, { type: 'video/webm' });
            const url  = URL.createObjectURL(blob);
            liveVid.classList.add('hidden');
            vsCanvas.classList.remove('hidden');
            document.getElementById('vsLiveCaptureBar').classList.add('hidden');
            if (vsLiveCaptureTimerId) clearInterval(vsLiveCaptureTimerId);
            vsLoadVideoSource(url, (kind === 'screen' ? 'Screencast-' : 'Kamera-Clip-') + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.webm', 0, 'Videos');
        };

        vsLiveCaptureSec = 0;
        document.getElementById('vsLiveCaptureTimer').textContent = '00:00';
        vsLiveCaptureTimerId = setInterval(() => {
            vsLiveCaptureSec++;
            const m = String(Math.floor(vsLiveCaptureSec / 60)).padStart(2, '0');
            const s = String(vsLiveCaptureSec % 60).padStart(2, '0');
            document.getElementById('vsLiveCaptureTimer').textContent = m + ':' + s;
        }, 1000);

        vsLiveCaptureRec.start(250);
    } catch (err) {
        alert('Kamera- oder Bildschirmfreigabe wurde abgebrochen oder vom Browser blockiert.');
    }
}

function vsStopCameraCapture() {
    if (vsLiveCaptureRec && vsLiveCaptureRec.state !== 'inactive') {
        vsLiveCaptureRec.stop();
    }
    if (vsLiveCaptureStream) {
        vsLiveCaptureStream.getTracks().forEach(t => t.stop());
    }
}

// Tastatur-Shortcuts im Studio
window.addEventListener('keydown', (ev) => {
    const tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

    if (ev.code === 'Space') {
        ev.preventDefault();
        vsTogglePlay();
    } else if (ev.key === 'i' || ev.key === 'I') {
        ev.preventDefault();
        vsSetTrimFromCurrent('in');
    } else if (ev.key === 'o' || ev.key === 'O') {
        ev.preventDefault();
        vsSetTrimFromCurrent('out');
    } else if (ev.key === 's' || ev.key === 'S') {
        ev.preventDefault();
        vsCaptureSnapshotToCloud();
    } else if (ev.key === 'ArrowLeft') {
        ev.preventDefault();
        vsSeekRelative(ev.shiftKey ? -1/30 : -1);
    } else if (ev.key === 'ArrowRight') {
        ev.preventDefault();
        vsSeekRelative(ev.shiftKey ? 1/30 : 1);
    }
});

// Falls ?id=... beim Seitenaufruf übergeben wurde, sofort laden!
if (VS_PRESELECTED && VS_PRESELECTED.inline_url) {
    vsLoadVideoSource(VS_PRESELECTED.inline_url, VS_PRESELECTED.name, Number(VS_PRESELECTED.id), VS_PRESELECTED.folder || 'Videos');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
