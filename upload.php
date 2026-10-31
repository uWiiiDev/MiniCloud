<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

/**
 * Hilfsfunktion für konsistente Antworten (Redirect oder JSON bei AJAX-Upload).
 */
function respondUpload(bool $success, string $message, bool $isAjax, string $redirectFolder = '', int $groupFolderId = 0, int $httpCode = 200, int $sharedFolderId = 0): never
{
    $type = $success ? 'success' : 'error';
    setFlash($type, $message);

    $queryParams = [];
    if ($sharedFolderId > 0) {
        $queryParams['shared_folder'] = (string)$sharedFolderId;
    } elseif ($groupFolderId > 0) {
        $queryParams['group_folder'] = (string)$groupFolderId;
    } elseif ($redirectFolder !== '') {
        $queryParams['folder'] = $redirectFolder;
    }
    $location = 'index.php' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : '');

    if ($isAjax) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'redirect' => $location,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . $location);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondUpload(false, 'Ungültige Anfrage-Methode.', $isAjax, '', 0, 405);
}

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
$phpLimits = getPhpUploadLimits();
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    $limitFormatted = formatBytes($phpLimits['post_max_size']);
    respondUpload(
        false,
        "Das gesamte Upload-Volumen überschreitet das Server-Limit (post_max_size: {$limitFormatted}).",
        $isAjax,
        '',
        0,
        413
    );
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    respondUpload(false, 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.', $isAjax, '', 0, 403);
}

$userId = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo = getDB();

// Optionaler freigegebener Ordner eines anderen Benutzers mit Schreibrecht (can_write = 1)
$sharedFolderId = isset($_POST['shared_folder_id']) ? (int)$_POST['shared_folder_id'] : 0;
$effectiveOwnerId = $userId;
$sharedBaseFolder = '';
if ($sharedFolderId > 0) {
    $stmtSf = $pdo->prepare(
        'SELECT id, owner_id, folder_path, can_write
         FROM user_shares
         WHERE id = :id AND shared_with_user_id = :uid AND folder_path IS NOT NULL
         LIMIT 1'
    );
    $stmtSf->execute([':id' => $sharedFolderId, ':uid' => $userId]);
    $sfRow = $stmtSf->fetch();
    if (!$sfRow || (int)($sfRow['can_write'] ?? 0) !== 1) {
        respondUpload(false, 'Sie besitzen keine Schreibberechtigung für diesen freigegebenen Ordner.', $isAjax, '', 0, 403, $sharedFolderId);
    }
    $effectiveOwnerId = (int)$sfRow['owner_id'];
    $sharedBaseFolder = (string)$sfRow['folder_path'];
}

$storageStats = getUserStorageStats($pdo, $effectiveOwnerId);
$remainingQuota = $storageStats['free'];

// Optionaler Gruppenordner
$groupFolderId = isset($_POST['group_folder_id']) ? (int)$_POST['group_folder_id'] : 0;
if ($groupFolderId > 0 && $sharedFolderId === 0) {
    if (!canUserAccessGroupFolder($pdo, $userId, $groupFolderId, true, $isAdmin)) {
        respondUpload(false, 'Sie besitzen keine Schreibberechtigung für diesen Gruppenordner.', $isAjax, '', 0, 403);
    }
}

// Optionaler Zielordner aus dem Formular
$targetFolderInput = isset($_POST['target_folder']) ? trim((string)$_POST['target_folder']) : '';
$baseTargetFolder = $sharedFolderId > 0
    ? $sharedBaseFolder
    : ($targetFolderInput !== '' ? extractSanitizedFolderPath($targetFolderInput . '/placeholder') : '');

// Optionales Tresor-Passwort direkt beim Upload
$uploadVaultPassword = isset($_POST['vault_password']) ? (string)$_POST['vault_password'] : '';
if ($uploadVaultPassword !== '' && mb_strlen($uploadVaultPassword, 'UTF-8') < 4) {
    respondUpload(false, 'Das optionale Tresor-Passwort muss mindestens 4 Zeichen lang sein.', $isAjax, $baseTargetFolder, $groupFolderId, 200, $sharedFolderId);
}

if (!isset($_FILES['files'])) {
    respondUpload(false, 'Keine Dateien zum Hochladen empfangen.', $isAjax, $baseTargetFolder, $groupFolderId, 200, $sharedFolderId);
}

if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
    respondUpload(false, 'Serverfehler: Upload-Verzeichnis konnte nicht erstellt werden.', $isAjax, $baseTargetFolder, $groupFolderId, 500, $sharedFolderId);
}

$rawFiles = $_FILES['files'];
$relativePathsPost = isset($_POST['relative_paths']) && is_array($_POST['relative_paths']) ? $_POST['relative_paths'] : [];

$normalizedFiles = [];
if (is_array($rawFiles['name'])) {
    $count = count($rawFiles['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalizedFiles[] = [
            'name'          => (string)($rawFiles['name'][$i] ?? ''),
            'full_path'     => (string)($rawFiles['full_path'][$i] ?? ''),
            'relative_post' => (string)($relativePathsPost[$i] ?? ''),
            'type'          => (string)($rawFiles['type'][$i] ?? ''),
            'tmp_name'      => (string)($rawFiles['tmp_name'][$i] ?? ''),
            'error'         => (int)($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'          => (int)($rawFiles['size'][$i] ?? 0),
        ];
    }
} else {
    $normalizedFiles[] = [
        'name'          => (string)($rawFiles['name'] ?? ''),
        'full_path'     => (string)($rawFiles['full_path'] ?? ''),
        'relative_post' => (string)($relativePathsPost[0] ?? ''),
        'type'          => (string)($rawFiles['type'] ?? ''),
        'tmp_name'      => (string)($rawFiles['tmp_name'] ?? ''),
        'error'         => (int)($rawFiles['error'] ?? UPLOAD_ERR_NO_FILE),
        'size'          => (int)($rawFiles['size'] ?? 0),
    ];
}

$validCandidates = array_filter($normalizedFiles, static function (array $f): bool {
    return $f['error'] !== UPLOAD_ERR_NO_FILE || $f['name'] !== '';
});

if (empty($validCandidates)) {
    respondUpload(false, 'Bitte wählen Sie mindestens eine Datei oder einen Ordner zum Hochladen aus.', $isAjax, $baseTargetFolder, $groupFolderId);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$uploadedCount = 0;
$totalUploadedBytes = 0;
$errorMessages = [];

$stmtInsert = $pdo->prepare(
    'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, file_hash, mime_type, is_encrypted)
     VALUES (:user_id, :group_folder_id, :filename, :original_name, :folder_path, :file_size, :file_hash, :mime_type, :is_encrypted)'
);

foreach ($validCandidates as $fileItem) {
    $rawName = $fileItem['name'];
    $errorCode = $fileItem['error'];

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errorMessages[] = sprintf('"%s": %s', sanitizeOriginalFilename($rawName), getUploadErrorMessage($errorCode));
        continue;
    }

    $tmpName = $fileItem['tmp_name'];
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errorMessages[] = sprintf('"%s": Ungültiger Upload-Stream.', sanitizeOriginalFilename($rawName));
        continue;
    }

    $actualSize = filesize($tmpName);
    if ($actualSize === false) {
        $actualSize = $fileItem['size'];
    }

    if ($actualSize > $phpLimits['upload_max_filesize']) {
        $errorMessages[] = sprintf(
            '"%s" (%s) überschreitet das Server-Dateilimit von %s.',
            sanitizeOriginalFilename($rawName),
            formatBytes($actualSize),
            formatBytes($phpLimits['upload_max_filesize'])
        );
        continue;
    }

    if ($actualSize > $remainingQuota) {
        $errorMessages[] = sprintf(
            '"%s" (%s) überschreitet Ihren verbleibenden freien Speicherplatz (%s frei von %s).',
            sanitizeOriginalFilename($rawName),
            formatBytes($actualSize),
            formatBytes($remainingQuota),
            formatBytes($storageStats['quota'])
        );
        continue;
    }

    $originalName = sanitizeOriginalFilename($rawName);
    $rawRelativePath = $fileItem['relative_post'] !== '' ? $fileItem['relative_post'] : $fileItem['full_path'];
    $subFolderPath = extractSanitizedFolderPath($rawRelativePath);

    if ($baseTargetFolder !== '' && $subFolderPath !== '') {
        $finalFolderPath = mb_substr($baseTargetFolder . '/' . $subFolderPath, 0, 500, 'UTF-8');
    } elseif ($baseTargetFolder !== '') {
        $finalFolderPath = $baseTargetFolder;
    } else {
        $finalFolderPath = $subFolderPath;
    }

    do {
        $storedFilename = generateSafeStoredFilename();
        $destinationPath = UPLOAD_DIR . $storedFilename;
    } while (file_exists($destinationPath));

    $detectedMime = $finfo->file($tmpName);
    $mimeType = is_string($detectedMime) && $detectedMime !== '' ? mb_substr($detectedMime, 0, 128) : 'application/octet-stream';

    if (!move_uploaded_file($tmpName, $destinationPath)) {
        $errorMessages[] = sprintf('"%s": Datei konnte nicht im Upload-Verzeichnis gespeichert werden.', $originalName);
        continue;
    }

    @chmod($destinationPath, 0640);

    $isEncryptedFlag = 0;
    $fileHash = null;
    if ($uploadVaultPassword !== '') {
        if (!encryptFileInPlaceWithVault($destinationPath, $uploadVaultPassword)) {
            @unlink($destinationPath);
            $errorMessages[] = sprintf('"%s": Tresor-Verschlüsselung beim Upload fehlgeschlagen.', $originalName);
            continue;
        }
        clearstatcache(true, $destinationPath);
        $actualSize = (int)(@filesize($destinationPath) ?: $actualSize);
        $isEncryptedFlag = 1;
    } else {
        $computedHash = @hash_file('sha256', $destinationPath);
        if (is_string($computedHash) && strlen($computedHash) === 64) {
            $fileHash = $computedHash;
        }
    }

    try {
        $existingFile = null;
        if ($isEncryptedFlag === 0) {
            if ($groupFolderId > 0) {
                $stmtEx = $pdo->prepare(
                    'SELECT * FROM files
                     WHERE group_folder_id = :gfid AND folder_path = :fp AND original_name = :orig AND deleted_at IS NULL AND is_encrypted = 0
                     ORDER BY id DESC LIMIT 1'
                );
                $stmtEx->execute([
                    ':gfid' => $groupFolderId,
                    ':fp'   => $finalFolderPath,
                    ':orig' => $originalName,
                ]);
            } else {
                $stmtEx = $pdo->prepare(
                    'SELECT * FROM files
                     WHERE user_id = :uid AND group_folder_id IS NULL AND folder_path = :fp AND original_name = :orig AND deleted_at IS NULL AND is_encrypted = 0
                     ORDER BY id DESC LIMIT 1'
                );
                $stmtEx->execute([
                    ':uid'  => $effectiveOwnerId,
                    ':fp'   => $finalFolderPath,
                    ':orig' => $originalName,
                ]);
            }
            $existingFile = $stmtEx->fetch() ?: null;
        }

        if ($existingFile) {
            createFileVersionSnapshot($pdo, $existingFile, 'Vor neuem Upload (' . (string)($_SESSION['username'] ?? 'Upload') . ')');
            $oldPath = UPLOAD_DIR . basename((string)$existingFile['filename']);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $stmtUpdExisting = $pdo->prepare(
                'UPDATE files
                 SET filename = :filename, file_size = :file_size, file_hash = :file_hash, mime_type = :mime_type, upload_date = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmtUpdExisting->execute([
                ':filename'  => $storedFilename,
                ':file_size' => $actualSize,
                ':file_hash' => $fileHash,
                ':mime_type' => $mimeType,
                ':id'        => (int)$existingFile['id'],
            ]);
            indexFileForFulltextSearch($pdo, (int)$existingFile['id']);
        } else {
            $stmtInsert->execute([
                ':user_id'         => $effectiveOwnerId,
                ':group_folder_id' => $groupFolderId > 0 ? $groupFolderId : null,
                ':filename'        => $storedFilename,
                ':original_name'   => $originalName,
                ':folder_path'     => $finalFolderPath,
                ':file_size'       => $actualSize,
                ':file_hash'       => $fileHash,
                ':mime_type'       => $mimeType,
                ':is_encrypted'    => $isEncryptedFlag,
            ]);
            if ($isEncryptedFlag === 0) {
                indexFileForFulltextSearch($pdo, (int)$pdo->lastInsertId());
            }
        }

        if ($finalFolderPath !== '') {
            registerFolderHierarchy($pdo, $effectiveOwnerId, $finalFolderPath, $groupFolderId > 0 ? $groupFolderId : null);
        }

        $remainingQuota -= $actualSize;
        $totalUploadedBytes += $actualSize;
        $uploadedCount++;
    } catch (Throwable $e) {
        @unlink($destinationPath);
        error_log('Fehler beim Speichern des Datei-Datensatzes: ' . $e->getMessage());
        $errorMessages[] = sprintf('"%s": Datenbankfehler beim Speichern.', $originalName);
    }
}

if ($uploadedCount > 0 && empty($errorMessages)) {
    $msg = $uploadedCount === 1
        ? sprintf('1 Datei (%s) wurde erfolgreich hochgeladen.', formatBytes($totalUploadedBytes))
        : sprintf('%d Dateien (insgesamt %s) wurden erfolgreich hochgeladen.', $uploadedCount, formatBytes($totalUploadedBytes));
    respondUpload(true, $msg, $isAjax, $baseTargetFolder, $groupFolderId, 200, $sharedFolderId);
}

if ($uploadedCount > 0 && !empty($errorMessages)) {
    $msg = sprintf(
        '%d Datei(en) hochgeladen, aber %d Fehler aufgetreten: %s',
        $uploadedCount,
        count($errorMessages),
        implode(' | ', array_slice($errorMessages, 0, 3))
    );
    respondUpload(true, $msg, $isAjax, $baseTargetFolder, $groupFolderId, 200, $sharedFolderId);
}

$fullErrorMsg = 'Upload fehlgeschlagen: ' . implode(' | ', array_slice($errorMessages, 0, 3));
respondUpload(false, $fullErrorMsg, $isAjax, $baseTargetFolder, $groupFolderId, 400, $sharedFolderId);
