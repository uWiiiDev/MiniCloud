<?php
declare(strict_types=1);

/**
 * MiniCloud REST API - Dateiverwaltung & Datei-Explorer für die Android-App
 */

require_once __DIR__ . '/common.php';

$pdo = getDB();
$user = requireApiAuth($pdo);
$userId = (int)$user['id'];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// JSON Input parsen falls vorhanden
$jsonInput = [];
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    }
}

// POST Aktionen (mkdir, rename, delete, search)
if ($method === 'POST') {
    $action = $_POST['action'] ?? $jsonInput['action'] ?? '';

    // Ordner erstellen
    if ($action === 'mkdir') {
        $parentFolder = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));
        $name = trim((string)($_POST['name'] ?? $jsonInput['name'] ?? ''));

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
            sendJsonResponse(['ok' => false, 'error' => 'Ungültiger Ordnername.'], 400);
        }

        $newFolder = $parentFolder === '' ? $name : $parentFolder . '/' . $name;
        registerFolderHierarchy($pdo, $userId, $newFolder);

        sendJsonResponse([
            'ok'      => true,
            'message' => 'Ordner erfolgreich erstellt.',
            'folder'  => [
                'name' => $name,
                'path' => $newFolder,
            ],
        ]);
    }

    // Datei umbenennen
    if ($action === 'rename') {
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);
        $newName = sanitizeOriginalFilename((string)($_POST['new_name'] ?? $jsonInput['new_name'] ?? ''));

        if ($fileId <= 0 || $newName === '') {
            sendJsonResponse(['ok' => false, 'error' => 'Ungültige Datei-ID oder Dateiname.'], 400);
        }

        // Sicherstellen, dass die Datei dem Benutzer gehört
        $stmt = $pdo->prepare('SELECT id, folder_path, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $fileId, ':uid' => $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            sendJsonResponse(['ok' => false, 'error' => 'Datei nicht gefunden oder keine Berechtigung.'], 404);
        }

        $stmtUpdate = $pdo->prepare('UPDATE files SET original_name = :name WHERE id = :id AND user_id = :uid');
        $stmtUpdate->execute([':name' => $newName, ':id' => $fileId, ':uid' => $userId]);

        sendJsonResponse([
            'ok'       => true,
            'message'  => 'Datei erfolgreich umbenannt.',
            'id'       => $fileId,
            'new_name' => $newName,
        ]);
    }

    // Datei löschen (Papierkorb)
    if ($action === 'delete') {
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);

        if ($fileId <= 0) {
            sendJsonResponse(['ok' => false, 'error' => 'Ungültige Datei-ID.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $fileId, ':uid' => $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            sendJsonResponse(['ok' => false, 'error' => 'Datei nicht gefunden oder bereits gelöscht.'], 404);
        }

        $stmtDel = $pdo->prepare('UPDATE files SET deleted_at = NOW() WHERE id = :id AND user_id = :uid');
        $stmtDel->execute([':id' => $fileId, ':uid' => $userId]);

        sendJsonResponse([
            'ok'      => true,
            'message' => 'Datei in den Papierkorb verschoben.',
            'id'      => $fileId,
        ]);
    }

    // Ordner löschen
    if ($action === 'delete_folder') {
        $folderPath = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));
        if ($folderPath === '') {
            sendJsonResponse(['ok' => false, 'error' => 'Hauptverzeichnis kann nicht gelöscht werden.'], 400);
        }

        // Dateien im Ordner in den Papierkorb verschieben
        $prefix = $folderPath . '/%';
        $stmtDelFiles = $pdo->prepare('UPDATE files SET deleted_at = NOW() WHERE user_id = :uid AND (folder_path = :path OR folder_path LIKE :prefix) AND deleted_at IS NULL');
        $stmtDelFiles->execute([':uid' => $userId, ':path' => $folderPath, ':prefix' => $prefix]);

        // Aus user_folders entfernen
        $stmtDelFolders = $pdo->prepare('DELETE FROM user_folders WHERE user_id = :uid AND (folder_path = :path OR folder_path LIKE :prefix)');
        $stmtDelFolders->execute([':uid' => $userId, ':path' => $folderPath, ':prefix' => $prefix]);

        sendJsonResponse([
            'ok'      => true,
            'message' => 'Ordner gelöscht.',
            'folder'  => $folderPath,
        ]);
    }

    // Datei verschieben
    if ($action === 'move') {
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);
        $targetFolder = sanitizeFolderPathInput((string)($_POST['target_folder'] ?? $jsonInput['target_folder'] ?? ''));

        if ($fileId <= 0) {
            sendJsonResponse(['ok' => false, 'error' => 'Ungültige Datei-ID.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, folder_path, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $fileId, ':uid' => $userId]);
        $file = $stmt->fetch();

        if (!$file) {
            sendJsonResponse(['ok' => false, 'error' => 'Datei nicht gefunden oder keine Berechtigung.'], 404);
        }

        if ($targetFolder !== '') {
            registerFolderHierarchy($pdo, $userId, $targetFolder);
        }

        $stmtUpdate = $pdo->prepare('UPDATE files SET folder_path = :target WHERE id = :id AND user_id = :uid');
        $stmtUpdate->execute([':target' => $targetFolder, ':id' => $fileId, ':uid' => $userId]);

        sendJsonResponse([
            'ok'            => true,
            'message'       => 'Datei erfolgreich verschoben.',
            'id'            => $fileId,
            'target_folder' => $targetFolder,
        ]);
    }

    // Ordner umbenennen
    if ($action === 'rename_folder') {
        $oldFolder = sanitizeFolderPathInput((string)($_POST['old_folder'] ?? $jsonInput['old_folder'] ?? ''));
        $newName = trim((string)($_POST['new_name'] ?? $jsonInput['new_name'] ?? ''));

        if ($oldFolder === '') {
            sendJsonResponse(['ok' => false, 'error' => 'Hauptverzeichnis kann nicht umbenannt werden.'], 400);
        }
        if ($newName === '' || str_contains($newName, '/') || str_contains($newName, '\\') || $newName === '.' || $newName === '..') {
            sendJsonResponse(['ok' => false, 'error' => 'Ungültiger Ordnername.'], 400);
        }

        $parentPath = str_contains($oldFolder, '/') ? substr($oldFolder, 0, (int)strrpos($oldFolder, '/')) : '';
        $newFolder = $parentPath === '' ? $newName : $parentPath . '/' . $newName;

        if ($newFolder !== $oldFolder) {
            registerFolderHierarchy($pdo, $userId, $newFolder);

            // 1. Files aktualisieren
            $stmtFiles = $pdo->prepare('SELECT id, folder_path FROM files WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)');
            $stmtFiles->execute([':uid' => $userId, ':exact' => $oldFolder, ':prefix' => $oldFolder . '/%']);
            foreach ($stmtFiles->fetchAll() as $fRow) {
                $updatedPath = $newFolder . substr((string)$fRow['folder_path'], strlen($oldFolder));
                $pdo->prepare('UPDATE files SET folder_path = :fp WHERE id = :id AND user_id = :uid')
                    ->execute([':fp' => $updatedPath, ':id' => (int)$fRow['id'], ':uid' => $userId]);
            }

            // 2. user_folders aktualisieren
            $stmtUf = $pdo->prepare('SELECT id, folder_path FROM user_folders WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)');
            $stmtUf->execute([':uid' => $userId, ':exact' => $oldFolder, ':prefix' => $oldFolder . '/%']);
            foreach ($stmtUf->fetchAll() as $ufRow) {
                $updatedPath = $newFolder . substr((string)$ufRow['folder_path'], strlen($oldFolder));
                registerFolderHierarchy($pdo, $userId, $updatedPath);
                $pdo->prepare('DELETE FROM user_folders WHERE id = :id')
                    ->execute([':id' => (int)$ufRow['id']]);
            }
        }

        sendJsonResponse([
            'ok'         => true,
            'message'    => 'Ordner erfolgreich umbenannt.',
            'old_folder' => $oldFolder,
            'new_folder' => $newFolder,
        ]);
    }

    // Ordner verschieben
    if ($action === 'move_folder') {
        $folder = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));
        $targetParent = sanitizeFolderPathInput((string)($_POST['target_parent'] ?? $jsonInput['target_parent'] ?? ''));

        if ($folder === '') {
            sendJsonResponse(['ok' => false, 'error' => 'Hauptverzeichnis kann nicht verschoben werden.'], 400);
        }

        // Kann nicht in sich selbst oder in einen Unterordner von sich selbst verschoben werden
        if ($targetParent === $folder || str_starts_with($targetParent, $folder . '/')) {
            sendJsonResponse(['ok' => false, 'error' => 'Ein Ordner kann nicht in sich selbst oder einen eigenen Unterordner verschoben werden.'], 400);
        }

        $folderBase = basename($folder);
        $newFolder = $targetParent === '' ? $folderBase : $targetParent . '/' . $folderBase;

        if ($newFolder !== $folder) {
            registerFolderHierarchy($pdo, $userId, $newFolder);

            // 1. Files aktualisieren
            $stmtFiles = $pdo->prepare('SELECT id, folder_path FROM files WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)');
            $stmtFiles->execute([':uid' => $userId, ':exact' => $folder, ':prefix' => $folder . '/%']);
            foreach ($stmtFiles->fetchAll() as $fRow) {
                $updatedPath = $newFolder . substr((string)$fRow['folder_path'], strlen($folder));
                $pdo->prepare('UPDATE files SET folder_path = :fp WHERE id = :id AND user_id = :uid')
                    ->execute([':fp' => $updatedPath, ':id' => (int)$fRow['id'], ':uid' => $userId]);
            }

            // 2. user_folders aktualisieren
            $stmtUf = $pdo->prepare('SELECT id, folder_path FROM user_folders WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)');
            $stmtUf->execute([':uid' => $userId, ':exact' => $folder, ':prefix' => $folder . '/%']);
            foreach ($stmtUf->fetchAll() as $ufRow) {
                $updatedPath = $newFolder . substr((string)$ufRow['folder_path'], strlen($folder));
                registerFolderHierarchy($pdo, $userId, $updatedPath);
                $pdo->prepare('DELETE FROM user_folders WHERE id = :id')
                    ->execute([':id' => (int)$ufRow['id']]);
            }
        }

        sendJsonResponse([
            'ok'         => true,
            'message'    => 'Ordner erfolgreich verschoben.',
            'old_folder' => $folder,
            'new_folder' => $newFolder,
        ]);
    }

    // Freigabelink erstellen (Datei oder Ordner)
    if ($action === 'create_share') {
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);
        $folderPath = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));
        $password = trim((string)($_POST['password'] ?? $jsonInput['password'] ?? ''));
        $expiresDays = (int)($_POST['expires_days'] ?? $jsonInput['expires_days'] ?? 0);
        $shareMode = trim((string)($_POST['share_mode'] ?? $jsonInput['share_mode'] ?? 'read'));
        if (!in_array($shareMode, ['read', 'upload'], true)) {
            $shareMode = 'read';
        }

        if ($fileId <= 0 && $folderPath === '') {
            sendJsonResponse(['ok' => false, 'error' => 'Keine Datei oder kein Ordner angegeben.'], 400);
        }

        $targetName = '';
        if ($fileId > 0) {
            $stmt = $pdo->prepare('SELECT id, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmt->execute([':id' => $fileId, ':uid' => $userId]);
            $file = $stmt->fetch();
            if (!$file) {
                sendJsonResponse(['ok' => false, 'error' => 'Datei nicht gefunden oder keine Berechtigung.'], 404);
            }
            $targetName = (string)$file['original_name'];
            $folderPath = null;
        } else {
            $targetName = basename($folderPath);
            $fileId = null;
        }

        // Frühere Freigaben für diese Datei / diesen Ordner des Nutzers bereinigen
        if ($fileId !== null) {
            $stmtDelOld = $pdo->prepare('DELETE FROM shares WHERE user_id = :uid AND file_id = :fid');
            $stmtDelOld->execute([':uid' => $userId, ':fid' => $fileId]);
        } else {
            $stmtDelOld = $pdo->prepare('DELETE FROM shares WHERE user_id = :uid AND folder_path = :fp');
            $stmtDelOld->execute([':uid' => $userId, ':fp' => $folderPath]);
        }

        // Eindeutigen 32-Zeichen Token generieren
        do {
            $token = bin2hex(random_bytes(16));
            $stmtCheck = $pdo->prepare('SELECT id FROM shares WHERE share_token = :token LIMIT 1');
            $stmtCheck->execute([':token' => $token]);
        } while ($stmtCheck->fetch());

        $passwordHash = null;
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $expiresAt = null;
        if ($expiresDays > 0 && $expiresDays <= 3650) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($expiresDays * 86400));
        }

        $stmtInsert = $pdo->prepare(
            'INSERT INTO shares (file_id, folder_path, user_id, share_token, share_mode, password_hash, expires_at, max_downloads, download_count)
             VALUES (:file_id, :folder_path, :user_id, :share_token, :share_mode, :password_hash, :expires_at, NULL, 0)'
        );
        $stmtInsert->execute([
            ':file_id'       => $fileId,
            ':folder_path'   => $folderPath,
            ':user_id'       => $userId,
            ':share_token'   => $token,
            ':share_mode'    => $shareMode,
            ':password_hash' => $passwordHash,
            ':expires_at'    => $expiresAt,
        ]);
        $shareId = (int)$pdo->lastInsertId();

        $shareUrl = getBaseUrl() . '/share.php?token=' . rawurlencode($token);

        if (function_exists('logAuditEvent')) {
            logAuditEvent(
                $pdo,
                'share_create',
                sprintf('App: Freigabelink für %s "%s" erstellt', $folderPath !== null ? 'Ordner' : 'Datei', $targetName),
                $userId
            );
        }

        sendJsonResponse([
            'ok'    => true,
            'share' => [
                'id'             => $shareId,
                'share_token'    => $token,
                'share_url'      => $shareUrl,
                'share_mode'     => $shareMode,
                'has_password'   => ($passwordHash !== null),
                'expires_at'     => $expiresAt,
                'download_count' => 0,
            ],
        ]);
    }

    // Aktive Freigabe abfragen
    if ($action === 'get_share') {
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);
        $folderPath = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));

        if ($fileId > 0) {
            $stmt = $pdo->prepare('SELECT id, share_token, share_mode, password_hash, expires_at, download_count, created_at
                                   FROM shares
                                   WHERE user_id = :uid AND file_id = :fid
                                   ORDER BY id DESC LIMIT 1');
            $stmt->execute([':uid' => $userId, ':fid' => $fileId]);
        } elseif ($folderPath !== '') {
            $stmt = $pdo->prepare('SELECT id, share_token, share_mode, password_hash, expires_at, download_count, created_at
                                   FROM shares
                                   WHERE user_id = :uid AND folder_path = :fp
                                   ORDER BY id DESC LIMIT 1');
            $stmt->execute([':uid' => $userId, ':fp' => $folderPath]);
        } else {
            sendJsonResponse(['ok' => false, 'error' => 'Keine Datei oder kein Ordner angegeben.'], 400);
        }

        $row = $stmt->fetch();
        if (!$row) {
            sendJsonResponse(['ok' => true, 'share' => null]);
        }

        $shareUrl = getBaseUrl() . '/share.php?token=' . rawurlencode((string)$row['share_token']);
        sendJsonResponse([
            'ok'    => true,
            'share' => [
                'id'             => (int)$row['id'],
                'share_token'    => (string)$row['share_token'],
                'share_url'      => $shareUrl,
                'share_mode'     => (string)($row['share_mode'] ?? 'read'),
                'has_password'   => !empty($row['password_hash']),
                'expires_at'     => $row['expires_at'] ? (string)$row['expires_at'] : null,
                'download_count' => (int)($row['download_count'] ?? 0),
                'created_at'     => (string)$row['created_at'],
            ],
        ]);
    }

    // Freigabe löschen
    if ($action === 'delete_share') {
        $shareId = (int)($_POST['share_id'] ?? $jsonInput['share_id'] ?? 0);
        $fileId = (int)($_POST['id'] ?? $jsonInput['id'] ?? 0);
        $folderPath = sanitizeFolderPathInput((string)($_POST['folder'] ?? $jsonInput['folder'] ?? ''));

        if ($shareId > 0) {
            $stmt = $pdo->prepare('DELETE FROM shares WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $shareId, ':uid' => $userId]);
        } elseif ($fileId > 0) {
            $stmt = $pdo->prepare('DELETE FROM shares WHERE file_id = :fid AND user_id = :uid');
            $stmt->execute([':fid' => $fileId, ':uid' => $userId]);
        } elseif ($folderPath !== '') {
            $stmt = $pdo->prepare('DELETE FROM shares WHERE folder_path = :fp AND user_id = :uid');
            $stmt->execute([':fp' => $folderPath, ':uid' => $userId]);
        } else {
            sendJsonResponse(['ok' => false, 'error' => 'Keine Freigabe-ID oder Ziel angegeben.'], 400);
        }

        if (function_exists('logAuditEvent')) {
            logAuditEvent($pdo, 'share_delete', 'App: Freigabelink gelöscht', $userId);
        }

        sendJsonResponse(['ok' => true, 'message' => 'Freigabelink erfolgreich gelöscht.']);
    }
}

// GET: Ordnerinhalte auflisten oder Suche ausführen
$search = trim((string)($_GET['q'] ?? ''));
$folderInput = sanitizeFolderPathInput((string)($_GET['folder'] ?? ''));

// Aktive Freigaben des Benutzers laden für Schnellanzeige
$stmtUserShares = $pdo->prepare('SELECT id, file_id, folder_path, share_token FROM shares WHERE user_id = :uid AND (expires_at IS NULL OR expires_at > NOW())');
$stmtUserShares->execute([':uid' => $userId]);
$fileShares = [];
$folderShares = [];
$baseUrl = getBaseUrl();
foreach ($stmtUserShares->fetchAll() as $sRow) {
    $sUrl = $baseUrl . '/share.php?token=' . rawurlencode((string)$sRow['share_token']);
    if ($sRow['file_id'] !== null) {
        $fileShares[(int)$sRow['file_id']] = $sUrl;
    }
    if (!empty($sRow['folder_path'])) {
        $folderShares[(string)$sRow['folder_path']] = $sUrl;
    }
}

// Falls Suche
if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $stmtSearch = $pdo->prepare('SELECT id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, is_favorite, upload_date
        FROM files
        WHERE user_id = :uid AND deleted_at IS NULL AND original_name LIKE :q
        ORDER BY original_name ASC
        LIMIT 100');
    $stmtSearch->execute([':uid' => $userId, ':q' => $searchLike]);
    $rawFiles = $stmtSearch->fetchAll();

    $files = [];
    foreach ($rawFiles as $rf) {
        $isImg = str_starts_with((string)$rf['mime_type'], 'image/');
        $isVid = str_starts_with((string)$rf['mime_type'], 'video/');
        $files[] = [
            'id'             => (int)$rf['id'],
            'name'           => (string)$rf['original_name'],
            'folder_path'    => (string)$rf['folder_path'],
            'size'           => (int)$rf['file_size'],
            'size_formatted' => formatBytes((int)$rf['file_size']),
            'mime_type'      => (string)$rf['mime_type'],
            'is_image'       => $isImg,
            'is_video'       => $isVid,
            'is_favorite'    => (int)$rf['is_favorite'] === 1,
            'is_shared'      => isset($fileShares[(int)$rf['id']]),
            'share_url'      => $fileShares[(int)$rf['id']] ?? null,
            'upload_date'    => (string)$rf['upload_date'],
            'thumbnail_url'  => ($isImg || $isVid) ? ('api/thumb.php?id=' . $rf['id']) : null,
            'download_url'   => 'api/download.php?id=' . $rf['id'],
        ];
    }

    sendJsonResponse([
        'ok'     => true,
        'search' => $search,
        'files'  => $files,
    ]);
}

// Normaler Ordnerinhalt
// 1. Breadcrumbs aufbauen
$breadcrumbs = [
    ['name' => 'MiniCloud', 'path' => ''],
];
if ($folderInput !== '') {
    $parts = explode('/', $folderInput);
    $currAcc = '';
    foreach ($parts as $p) {
        $currAcc = ($currAcc === '') ? $p : $currAcc . '/' . $p;
        $breadcrumbs[] = [
            'name' => $p,
            'path' => $currAcc,
        ];
    }
}

// 2. Unterordner ermitteln
$allFolders = getMergedFoldersList($pdo, $userId);
$directSubfolders = [];
$seenSubfolders = [];

foreach ($allFolders as $f) {
    $fPath = trim((string)$f['folder_path'], '/');
    if ($fPath === '') {
        continue;
    }

    if ($folderInput === '') {
        // Wurzelverzeichnis: Erster Pfadabschnitt
        $rootSeg = explode('/', $fPath, 2)[0];
        if (!isset($seenSubfolders[$rootSeg])) {
            $seenSubfolders[$rootSeg] = true;
            $directSubfolders[] = ['name' => $rootSeg, 'path' => $rootSeg];
        }
    } else {
        // In Unterordner: Pfad muss mit "$folderInput/" beginnen
        $prefix = $folderInput . '/';
        if (str_starts_with($fPath, $prefix)) {
            $rest = substr($fPath, strlen($prefix));
            $subSeg = explode('/', $rest, 2)[0];
            if ($subSeg !== '' && !isset($seenSubfolders[$subSeg])) {
                $seenSubfolders[$subSeg] = true;
                $directSubfolders[] = [
                    'name' => $subSeg,
                    'path' => $folderInput . '/' . $subSeg,
                ];
            }
        }
    }
}

// Alphabetisch sortieren
usort($directSubfolders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

foreach ($directSubfolders as &$dsf) {
    $dsf['is_shared'] = isset($folderShares[$dsf['path']]);
    $dsf['share_url'] = $folderShares[$dsf['path']] ?? null;
}
unset($dsf);

// 3. Dateien im aktuellen Verzeichnis ermitteln
$stmtFiles = $pdo->prepare('SELECT id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, is_favorite, upload_date
    FROM files
    WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path = :folder
    ORDER BY is_favorite DESC, original_name ASC');
$stmtFiles->execute([':uid' => $userId, ':folder' => $folderInput]);
$rawFiles = $stmtFiles->fetchAll();

$files = [];
foreach ($rawFiles as $rf) {
    $isImg = str_starts_with((string)$rf['mime_type'], 'image/');
    $isVid = str_starts_with((string)$rf['mime_type'], 'video/');
    $files[] = [
        'id'             => (int)$rf['id'],
        'name'           => (string)$rf['original_name'],
        'folder_path'    => (string)$rf['folder_path'],
        'size'           => (int)$rf['file_size'],
        'size_formatted' => formatBytes((int)$rf['file_size']),
        'mime_type'      => (string)$rf['mime_type'],
        'is_image'       => $isImg,
        'is_video'       => $isVid,
        'is_favorite'    => (int)$rf['is_favorite'] === 1,
        'is_shared'      => isset($fileShares[(int)$rf['id']]),
        'share_url'      => $fileShares[(int)$rf['id']] ?? null,
        'upload_date'    => (string)$rf['upload_date'],
        'thumbnail_url'  => ($isImg || $isVid) ? ('api/thumb.php?id=' . $rf['id']) : null,
        'download_url'   => 'api/download.php?id=' . $rf['id'],
    ];
}

$quota = getUserStorageStats($pdo, $userId);

$allFolderPaths = [];
foreach ($allFolders as $af) {
    $afp = trim((string)$af['folder_path'], '/');
    if ($afp !== '') {
        $allFolderPaths[] = $afp;
    }
}
$allFolderPaths = array_values(array_unique($allFolderPaths));
sort($allFolderPaths, SORT_NATURAL | SORT_FLAG_CASE);

sendJsonResponse([
    'ok'             => true,
    'current_folder' => $folderInput,
    'breadcrumbs'    => $breadcrumbs,
    'folders'        => $directSubfolders,
    'files'          => $files,
    'all_folders'    => $allFolderPaths,
    'quota'          => [
        'used'            => $quota['used'],
        'quota'           => $quota['quota'],
        'free'            => $quota['free'],
        'percent'         => $quota['percent'],
        'used_formatted'  => formatBytes($quota['used']),
        'quota_formatted' => formatBytes($quota['quota']),
        'free_formatted'  => formatBytes($quota['free']),
    ],
]);
