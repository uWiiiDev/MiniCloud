<?php
declare(strict_types=1);

/**
 * MiniCloud REST API - Datei-Download & Medienstreaming (mit HTTP-Range-Support)
 */

require_once __DIR__ . '/common.php';

$pdo = getDB();
$user = requireApiAuth($pdo);
$userId = (int)$user['id'];

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) {
    http_response_code(400);
    exit('Ungültige Datei-ID.');
}

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

// Zugriff prüfen
$hasAccess = false;
if ((int)$file['user_id'] === $userId && $file['group_folder_id'] === null) {
    $hasAccess = true;
} elseif ($file['group_folder_id'] !== null && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], false, (bool)$user['is_admin'])) {
    $hasAccess = true;
} else {
    // Einzel- oder Ordner-Freigabe prüfen
    $stmtUs = $pdo->prepare('SELECT id FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :uid LIMIT 1');
    $stmtUs->execute([':fid' => $fileId, ':uid' => $userId]);
    if ($stmtUs->fetch()) {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$physicalPath = UPLOAD_DIR . basename((string)$file['filename']);
if (!is_file($physicalPath) || !is_readable($physicalPath)) {
    http_response_code(404);
    exit('Physische Datei nicht gefunden.');
}

$fileSize = (int)(filesize($physicalPath) ?: 0);
$mimeType = (string)($file['mime_type'] ?: 'application/octet-stream');
$originalName = (string)$file['original_name'];
$safeFilename = str_replace(['"', "\r", "\n"], '_', $originalName);

// HTTP Range-Support für Streaming & Resuming
$range = $_SERVER['HTTP_RANGE'] ?? '';
$start = 0;
$end = $fileSize - 1;
$isRange = false;

if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
    $isRange = true;
    if ($matches[1] !== '') {
        $start = (int)$matches[1];
    }
    if ($matches[2] !== '') {
        $end = (int)$matches[2];
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }
}

if (ob_get_level()) {
    ob_end_clean();
}

if ($isRange) {
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
    $length = $end - $start + 1;
} else {
    http_response_code(200);
    $length = $fileSize;
}

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)$length);
$inline = str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/') || $mimeType === 'application/pdf';
$disposition = $inline ? 'inline' : 'attachment';
header(sprintf('Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $safeFilename, rawurlencode($originalName)));
header('Cache-Control: private, max-age=86400');

$fp = @fopen($physicalPath, 'rb');
if (!$fp) {
    http_response_code(500);
    exit('Fehler beim Öffnen der Datei.');
}

if ($start > 0) {
    fseek($fp, $start);
}

$bytesRemaining = $length;
$chunkSize = 64 * 1024; // 64 KB
while (!feof($fp) && $bytesRemaining > 0 && connection_status() === CONNECTION_NORMAL) {
    $readLen = min($chunkSize, $bytesRemaining);
    $data = fread($fp, $readLen);
    if ($data === false) {
        break;
    }
    echo $data;
    flush();
    $bytesRemaining -= strlen($data);
}

fclose($fp);
exit;
