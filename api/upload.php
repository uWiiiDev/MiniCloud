<?php
declare(strict_types=1);

/**
 * MiniCloud REST API - Datei-Upload (inkl. Kamera-Synchronisation)
 */

require_once __DIR__ . '/common.php';

$pdo = getDB();
$user = requireApiAuth($pdo);
$userId = (int)$user['id'];

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJsonResponse(['ok' => false, 'error' => 'Upload erfordert HTTP POST.'], 405);
}

// Zielordner (z.B. "Kamera/2026/11" oder leer für Stammverzeichnis)
$folderInput = sanitizeFolderPathInput((string)($_POST['folder'] ?? ''));

// Upload-Prüfungen
$phpLimits = getPhpUploadLimits();
$storageStats = getUserStorageStats($pdo, $userId);
$remainingQuota = $storageStats['free'];

// Datei aus $_FILES holen ('file' oder 'files' oder erstes gefundene Element)
$fileItem = null;
if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
    $fileItem = $_FILES['file'];
} elseif (!empty($_FILES['files']) && is_array($_FILES['files'])) {
    $fileItem = $_FILES['files'];
} else {
    foreach ($_FILES as $f) {
        if (is_array($f) && isset($f['name'])) {
            $fileItem = $f;
            break;
        }
    }
}

if (!$fileItem || !isset($fileItem['name'])) {
    sendJsonResponse(['ok' => false, 'error' => 'Keine Datei übermittelt.'], 400);
}

// Falls mehrere Dateien als Array übergeben wurden, erste nehmen oder iterieren
$rawName = is_array($fileItem['name']) ? ($fileItem['name'][0] ?? '') : (string)$fileItem['name'];
$errorCode = is_array($fileItem['error']) ? ($fileItem['error'][0] ?? UPLOAD_ERR_NO_FILE) : (int)$fileItem['error'];
$tmpName = is_array($fileItem['tmp_name']) ? ($fileItem['tmp_name'][0] ?? '') : (string)$fileItem['tmp_name'];
$reportedSize = is_array($fileItem['size']) ? ($fileItem['size'][0] ?? 0) : (int)$fileItem['size'];

if ($errorCode !== UPLOAD_ERR_OK) {
    sendJsonResponse(['ok' => false, 'error' => getUploadErrorMessage($errorCode)], 400);
}

if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    sendJsonResponse(['ok' => false, 'error' => 'Ungültiger Upload-Stream.'], 400);
}

$actualSize = filesize($tmpName);
if ($actualSize === false) {
    $actualSize = $reportedSize;
}

if ($actualSize > $phpLimits['upload_max_filesize']) {
    sendJsonResponse([
        'ok'    => false,
        'error' => sprintf('Datei (%s) überschreitet das Server-Limit (%s).', formatBytes($actualSize), formatBytes($phpLimits['upload_max_filesize'])),
    ], 413);
}

if ($actualSize > $remainingQuota) {
    sendJsonResponse([
        'ok'    => false,
        'error' => sprintf('Nicht genügend Speicherplatz (%s benötigt, nur %s frei).', formatBytes($actualSize), formatBytes($remainingQuota)),
    ], 400);
}

$originalName = sanitizeOriginalFilename($rawName);
if ($originalName === '') {
    $originalName = 'upload_' . date('Ymd_His');
}

// Ordnerhierarchie sicherstellen
if ($folderInput !== '') {
    registerFolderHierarchy($pdo, $userId, $folderInput);
}

// Dateinamen auf Festplatte generieren
ensureUploadDirReady();
do {
    $storedFilename = generateSafeStoredFilename();
    $destinationPath = UPLOAD_DIR . $storedFilename;
} while (file_exists($destinationPath));

$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($tmpName);
$mimeType = is_string($detectedMime) && $detectedMime !== '' ? mb_substr($detectedMime, 0, 128) : 'application/octet-stream';

if (!move_uploaded_file($tmpName, $destinationPath)) {
    sendJsonResponse(['ok' => false, 'error' => 'Datei konnte nicht auf dem Server gespeichert werden.'], 500);
}

// SHA256-Hash ermitteln
$fileHash = @hash_file('sha256', $destinationPath) ?: null;

// Prüfen ob bereits eine Datei gleichen Namens im gleichen Ordner existiert
$stmtCheck = $pdo->prepare('SELECT id FROM files WHERE user_id = :uid AND folder_path = :folder AND original_name = :name AND deleted_at IS NULL LIMIT 1');
$stmtCheck->execute([
    ':uid'    => $userId,
    ':folder' => $folderInput,
    ':name'   => $originalName,
]);
$existing = $stmtCheck->fetch();

if ($existing) {
    // Versionsverwaltung oder Umbenennung
    $pathInfo = pathinfo($originalName);
    $baseName = $pathInfo['filename'] ?? 'file';
    $ext = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.' . $pathInfo['extension'] : '';
    $counter = 1;
    do {
        $candidateName = $baseName . ' (' . $counter . ')' . $ext;
        $stmtCheck->execute([
            ':uid'    => $userId,
            ':folder' => $folderInput,
            ':name'   => $candidateName,
        ]);
        $counter++;
    } while ($stmtCheck->fetch());
    $originalName = $candidateName;
}

$stmtInsert = $pdo->prepare(
    'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, file_hash, mime_type, is_encrypted, upload_date)
     VALUES (:user_id, NULL, :filename, :original_name, :folder_path, :file_size, :file_hash, :mime_type, 0, NOW())'
);

$stmtInsert->execute([
    ':user_id'       => $userId,
    ':filename'      => $storedFilename,
    ':original_name' => $originalName,
    ':folder_path'   => $folderInput,
    ':file_size'     => $actualSize,
    ':file_hash'     => $fileHash,
    ':mime_type'     => $mimeType,
]);

$newFileId = (int)$pdo->lastInsertId();

// Audit-Log
logAuditEvent($pdo, 'file_upload', sprintf('Datei "%s" (%s) in Ordner "%s" hochgeladen', $originalName, formatBytes($actualSize), $folderInput), $userId, $user['username'] ?? null);

$isImg = str_starts_with($mimeType, 'image/');
$isVid = str_starts_with($mimeType, 'video/');

sendJsonResponse([
    'ok'      => true,
    'message' => 'Datei erfolgreich hochgeladen.',
    'file'    => [
        'id'             => $newFileId,
        'name'           => $originalName,
        'folder_path'    => $folderInput,
        'size'           => $actualSize,
        'size_formatted' => formatBytes($actualSize),
        'mime_type'      => $mimeType,
        'is_image'       => $isImg,
        'is_video'       => $isVid,
        'thumbnail_url'  => ($isImg || $isVid) ? ('api/thumb.php?id=' . $newFileId) : null,
        'download_url'   => 'api/download.php?id=' . $newFileId,
    ],
], 201);
