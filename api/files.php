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
}

// GET: Ordnerinhalte auflisten oder Suche ausführen
$search = trim((string)($_GET['q'] ?? ''));
$folderInput = sanitizeFolderPathInput((string)($_GET['folder'] ?? ''));

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
    $fPath = (string)$f['folder_path'];
    if ($folderInput === '') {
        // Wurzelverzeichnis: Erster Pfadabschnitt
        if (!str_contains($fPath, '/')) {
            $directSubfolders[] = ['name' => $fPath, 'path' => $fPath];
        } else {
            $rootSeg = explode('/', $fPath, 2)[0];
            if (!isset($seenSubfolders[$rootSeg])) {
                $seenSubfolders[$rootSeg] = true;
                $directSubfolders[] = ['name' => $rootSeg, 'path' => $rootSeg];
            }
        }
    } else {
        // In Unterordner: Pfad muss mit "$folderInput/" beginnen
        $prefix = $folderInput . '/';
        if (str_starts_with($fPath, $prefix)) {
            $rest = substr($fPath, strlen($prefix));
            $subSeg = explode('/', $rest, 2)[0];
            if (!isset($seenSubfolders[$subSeg])) {
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
        'upload_date'    => (string)$rf['upload_date'],
        'thumbnail_url'  => ($isImg || $isVid) ? ('api/thumb.php?id=' . $rf['id']) : null,
        'download_url'   => 'api/download.php?id=' . $rf['id'],
    ];
}

$quota = getUserStorageStats($pdo, $userId);

sendJsonResponse([
    'ok'             => true,
    'current_folder' => $folderInput,
    'breadcrumbs'    => $breadcrumbs,
    'folders'        => $directSubfolders,
    'files'          => $files,
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
