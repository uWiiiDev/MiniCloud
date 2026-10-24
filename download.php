<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

/**
 * Hilfsfunktion: Erstellt aus einer Dateiliste ein ZIP-Archiv und sendet es per Header an den Browser.
 *
 * @param array<int, array<string, mixed>> $folderFiles
 */
function streamZipArchive(array $folderFiles, string $archiveBaseName): never
{
    if (empty($folderFiles)) {
        setFlash('error', 'Der Ordner enthält noch keine Dateien zum Herunterladen.');
        header('Location: index.php');
        exit;
    }

    if (!class_exists('ZipArchive')) {
        setFlash('error', 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht aktiviert.');
        header('Location: index.php');
        exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'mc_zip_');
    if ($tmpZip === false) {
        setFlash('error', 'Temporäre ZIP-Datei konnte nicht erstellt werden.');
        header('Location: index.php');
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        setFlash('error', 'ZIP-Archiv konnte nicht initialisiert werden.');
        header('Location: index.php');
        exit;
    }

    $addedFiles = 0;
    foreach ($folderFiles as $f) {
        $storedName = basename((string)$f['filename']);
        $physicalPath = UPLOAD_DIR . $storedName;
        if (!is_file($physicalPath) || !is_readable($physicalPath)) {
            continue;
        }
        $relFolder = (string)$f['folder_path'];
        $entryName = ($relFolder !== '' ? $relFolder . '/' : '') . sanitizeOriginalFilename((string)$f['original_name']);
        $zip->addFile($physicalPath, $entryName);
        $addedFiles++;
    }
    $zip->close();

    if ($addedFiles === 0 || !is_file($tmpZip)) {
        @unlink($tmpZip);
        setFlash('error', 'Keine lesbaren Dateien für das ZIP-Archiv gefunden.');
        header('Location: index.php');
        exit;
    }

    $zipDownloadName = (preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($archiveBaseName)) ?: 'ordner') . '.zip';

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($zipDownloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipDownloadName));
    header('Content-Length: ' . (string)filesize($tmpZip));
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');

    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

// Option A: Kompletten persönlichen Ordner als ZIP-Archiv herunterladen (?folder=...)
if (isset($_GET['folder']) && is_string($_GET['folder']) && trim($_GET['folder']) !== '') {
    $folder = extractSanitizedFolderPath(trim($_GET['folder']) . '/placeholder');
    if ($folder === '') {
        setFlash('error', 'Ungültiger Ordnerpfad.');
        header('Location: index.php');
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE user_id = :user_id AND group_folder_id IS NULL AND deleted_at IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folder,
        ':folder_prefix' => $folder . '/%',
    ]);
    $folderFiles = $stmt->fetchAll();

    if (empty($folderFiles)) {
        setFlash('error', 'Ordner wurde nicht gefunden oder enthält keine Dateien.');
        header('Location: index.php');
        exit;
    }

    streamZipArchive($folderFiles, $folder);
}

// Option B: Von einem anderen Cloud-Benutzer freigegebenen Ordner als ZIP herunterladen (?user_share_id=...)
if (isset($_GET['user_share_id'])) {
    $userShareId = (int)$_GET['user_share_id'];
    $stmtShare = $pdo->prepare(
        'SELECT id, owner_id, folder_path
         FROM user_shares
         WHERE id = :id AND shared_with_user_id = :uid AND folder_path IS NOT NULL
         LIMIT 1'
    );
    $stmtShare->execute([
        ':id'  => $userShareId,
        ':uid' => $userId,
    ]);
    $sharedFolderRow = $stmtShare->fetch();

    if (!$sharedFolderRow || empty($sharedFolderRow['folder_path'])) {
        setFlash('error', 'Freigegebener Ordner nicht gefunden oder keine Berechtigung.');
        header('Location: index.php');
        exit;
    }

    $folder = (string)$sharedFolderRow['folder_path'];
    $ownerId = (int)$sharedFolderRow['owner_id'];

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE user_id = :owner_id AND group_folder_id IS NULL AND deleted_at IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([
        ':owner_id'      => $ownerId,
        ':folder_exact'  => $folder,
        ':folder_prefix' => $folder . '/%',
    ]);
    $folderFiles = $stmt->fetchAll();

    streamZipArchive($folderFiles, $folder);
}

// Option C: Kompletten Gruppenordner als ZIP herunterladen (?group_folder_zip=...)
if (isset($_GET['group_folder_zip'])) {
    $gfId = (int)$_GET['group_folder_zip'];
    if (!canUserAccessGroupFolder($pdo, $userId, $gfId, false, $isAdmin)) {
        setFlash('error', 'Kein Zugriff auf diesen Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    $stmtGf = $pdo->prepare('SELECT name FROM group_folders WHERE id = :id LIMIT 1');
    $stmtGf->execute([':id' => $gfId]);
    $gfRow = $stmtGf->fetch();

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE group_folder_id = :gf_id AND deleted_at IS NULL
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([':gf_id' => $gfId]);
    $folderFiles = $stmt->fetchAll();

    streamZipArchive($folderFiles, (string)($gfRow['name'] ?? 'gruppenordner'));
}

// Option D: Einzelne Datei herunterladen (?id=...) – prüft Eigentümer, Benutzer-Freigabe oder Gruppenordner-Recht
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) {
    setFlash('error', 'Ungültige Datei-ID.');
    header('Location: index.php');
    exit;
}

$file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);

if (!$file) {
    http_response_code(403);
    setFlash('error', 'Zugriff verweigert: Die angeforderte Datei existiert nicht oder Sie haben keine Berechtigung.');
    header('Location: index.php');
    exit;
}

$storedFilename = basename((string)$file['filename']);
$filePath = UPLOAD_DIR . $storedFilename;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    setFlash('error', 'Die physische Datei wurde auf dem Server nicht gefunden.');
    header('Location: index.php');
    exit;
}

$originalName = sanitizeOriginalFilename((string)$file['original_name']);
$fileSize = (int)filesize($filePath);
$isInline = isset($_GET['inline']) && $_GET['inline'] === '1';
$asText   = isset($_GET['as_text']) && $_GET['as_text'] === '1';

if (ob_get_level()) {
    ob_end_clean();
}

if ($isInline) {
    $rawMime = strtolower(trim((string)($file['mime_type'] ?? 'application/octet-stream')));
    $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($asText) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: private, no-store, must-revalidate');
        // Für die Textvorschau maximal die ersten 512 KB ausliefern, damit auch große Logdateien flüssig bleiben
        $maxTextBytes = 512 * 1024;
        $fh = fopen($filePath, 'rb');
        if ($fh !== false) {
            echo fread($fh, $maxTextBytes);
            if ($fileSize > $maxTextBytes) {
                echo "\n\n--- [Vorschau nach 512 KB gekürzt – bitte laden Sie die vollständige Datei herunter] ---";
            }
            fclose($fh);
        }
        exit;
    }

    // Sichere Inline-MIME-Types für Browser-Vorschau (Bilder, PDF, Audio, Video)
    $safeInlineMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/avif',
        'application/pdf',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
    ];

    $extMimeFallback = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
    ];

    $contentType = in_array($rawMime, $safeInlineMimes, true)
        ? $rawMime
        : ($extMimeFallback[$ext] ?? 'application/octet-stream');

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
    header('Content-Length: ' . (string)$fileSize);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; media-src \'self\'; object-src \'self\'; style-src \'unsafe-inline\'');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');

    readfile($filePath);
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('Content-Length: ' . (string)$fileSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-transform, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
