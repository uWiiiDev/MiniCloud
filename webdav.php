<?php
/**
 * uWiiiMiniCloud – Native WebDAV-Schnittstelle (webdav.php)
 * Ermöglicht das Einbinden des persönlichen Cloud-Speichers als Netzlaufwerk
 * unter Windows Explorer, macOS Finder, Linux (GNOME/KDE/davfs2) und Smartphones.
 *
 * Unterstützte Methoden: OPTIONS, PROPFIND, GET, HEAD, PUT, MKCOL, DELETE, MOVE, COPY, LOCK, UNLOCK
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_webdav'] ?? '1') !== '1') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Die WebDAV-Schnittstelle ist durch den Administrator deaktiviert.';
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Standard-WebDAV-Header
header('DAV: 1, 2');
header('MS-Author-Via: DAV');
header('Accept-Ranges: bytes');

if ($method === 'OPTIONS') {
    header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, MKCOL, PROPFIND, MOVE, COPY, LOCK, UNLOCK');
    http_response_code(200);
    exit;
}

// HTTP Basic Auth auslesen (inkl. FastCGI/FPM Fallback)
$authUser = $_SERVER['PHP_AUTH_USER'] ?? null;
$authPass = $_SERVER['PHP_AUTH_PW'] ?? null;

if ($authUser === null) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$authUser, $authPass] = explode(':', $decoded, 2);
        }
    }
}

// Wenn ein Benutzer die URL direkt im Browser öffnet (GET ohne WebDAV-Client), hilfreiche Info-Seite zeigen
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/webdav.php';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/webdav.php', PHP_URL_PATH) ?: '/webdav.php';
$rawRelPath = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $rawRelPath = (string)$_SERVER['PATH_INFO'];
} elseif (str_starts_with($requestUri, $scriptName)) {
    $rawRelPath = substr($requestUri, strlen($scriptName));
}
$rawRelPath = trim(rawurldecode($rawRelPath), '/');

if ($method === 'GET' && $rawRelPath === '' && $authUser === null && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
    $webdavUrl = getBaseUrl() . '/webdav.php/';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>WebDAV-Endpunkt – ' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</title>';
    echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}.box{max-width:560px;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.75rem}code{background:#0f172a;padding:.25rem .5rem;border-radius:6px;color:#38bdf8}a{color:#38bdf8}</style></head><body>';
    echo '<div class="box"><h2 style="margin-top:0">📡 WebDAV-Schnittstelle aktiv</h2>';
    echo '<p>Dies ist der WebDAV-Server-Endpunkt von <strong>' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</strong>.</p>';
    echo '<p>Verwende folgende Adresse in deinem Dateimanager (Windows Explorer, macOS Finder, Linux oder Smartphone-App) mit deinem normalen Benutzernamen und Passwort:</p>';
    echo '<p><code>' . e($webdavUrl) . '</code></p>';
    echo '<p style="margin-bottom:0"><a href="settings.php">← Zurück zu den Kontoeinstellungen</a></p></div></body></html>';
    exit;
}

if ($authUser === null || $authPass === null || trim($authUser) === '') {
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud WebDAV"');
    http_response_code(401);
    echo 'Authentifizierung erforderlich.';
    exit;
}

$clientIp = getClientIp();
if (isLoginRateLimited($pdo, $clientIp)) {
    http_response_code(429);
    echo 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.';
    exit;
}

$stmtUser = $pdo->prepare("SELECT `id`, `username`, `password_hash` FROM `users` WHERE `username` = :u LIMIT 1");
$stmtUser->execute([':u' => trim($authUser)]);
$userRow = $stmtUser->fetch();

if (!$userRow || !password_verify((string)$authPass, (string)$userRow['password_hash'])) {
    recordLoginFailure($pdo, $clientIp, trim($authUser));
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud WebDAV"');
    http_response_code(401);
    echo 'Ungültige Zugangsdaten.';
    exit;
}

$userId = (int)$userRow['id'];

/**
 * Normalisiert einen relativen WebDAV-Pfad in (folder_path, basename).
 */
function splitWebdavPath(string $relPath): array
{
    $clean = sanitizeFolderPath($relPath);
    if ($clean === '') {
        return ['', ''];
    }
    $pos = strrpos($clean, '/');
    if ($pos === false) {
        return ['', sanitizeFileName($clean)];
    }
    $folder = sanitizeFolderPath(substr($clean, 0, $pos));
    $name   = sanitizeFileName(substr($clean, $pos + 1));
    return [$folder, $name];
}

/**
 * Prüft, ob ein Ordner für den Benutzer existiert.
 */
function webdavFolderExists(PDO $pdo, int $userId, string $folderPath): bool
{
    if ($folderPath === '') {
        return true;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM `user_folders` WHERE `user_id` = :uid AND `folder_path` = :fp LIMIT 1");
    $stmt->execute([':uid' => $userId, ':fp' => $folderPath]);
    if ($stmt->fetchColumn()) {
        return true;
    }
    $stmt2 = $pdo->prepare("SELECT 1 FROM `files` WHERE `user_id` = :uid AND `deleted_at` IS NULL AND (`folder_path` = :fp OR `folder_path` LIKE :fplike) LIMIT 1");
    $stmt2->execute([':uid' => $userId, ':fp' => $folderPath, ':fplike' => $folderPath . '/%']);
    return (bool)$stmt2->fetchColumn();
}

/**
 * Sucht eine unverschlüsselte, aktive Datei anhand von Ordnerpfad und Dateiname.
 */
function webdavFindFile(PDO $pdo, int $userId, string $folderPath, string $fileName): ?array
{
    if ($fileName === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM `files`
         WHERE `user_id` = :uid
           AND `folder_path` = :fp
           AND `original_name` = :name
           AND `deleted_at` IS NULL
           AND `is_encrypted` = 0
         ORDER BY `id` DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':uid'  => $userId,
        ':fp'   => $folderPath,
        ':name' => $fileName,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Baut ein einzelnes <D:response>-Element für PROPFIND.
 */
function buildDavResponseXml(string $href, string $displayName, bool $isDir, int $size = 0, int $mtime = 0, string $mime = 'application/octet-stream'): string
{
    $mtimeFormatted = gmdate('D, d M Y H:i:s', $mtime > 0 ? $mtime : time()) . ' GMT';
    $etag = '"' . md5($href . ':' . $size . ':' . $mtime) . '"';

    $xml  = "  <D:response>\n";
    $xml .= "    <D:href>" . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n";
    $xml .= "    <D:propstat>\n";
    $xml .= "      <D:prop>\n";
    $xml .= "        <D:displayname>" . htmlspecialchars($displayName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:displayname>\n";
    $xml .= "        <D:getlastmodified>{$mtimeFormatted}</D:getlastmodified>\n";
    $xml .= "        <D:getetag>" . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getetag>\n";
    if ($isDir) {
        $xml .= "        <D:resourcetype><D:collection/></D:resourcetype>\n";
    } else {
        $xml .= "        <D:resourcetype/>\n";
        $xml .= "        <D:getcontentlength>{$size}</D:getcontentlength>\n";
        $xml .= "        <D:getcontenttype>" . htmlspecialchars($mime, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getcontenttype>\n";
    }
    $xml .= "      </D:prop>\n";
    $xml .= "      <D:status>HTTP/1.1 200 OK</D:status>\n";
    $xml .= "    </D:propstat>\n";
    $xml .= "  </D:response>\n";
    return $xml;
}

/**
 * Kodiere Pfadsegmente für WebDAV-HREFs.
 */
function encodeWebdavHref(string $baseScript, string $relPath, bool $isDir): string
{
    $base = rtrim($baseScript, '/');
    if ($relPath === '') {
        return $base . '/';
    }
    $parts = explode('/', trim($relPath, '/'));
    $encoded = array_map('rawurlencode', $parts);
    return $base . '/' . implode('/', $encoded) . ($isDir ? '/' : '');
}

$cleanRelPath = sanitizeFolderPath($rawRelPath);
[$parentFolder, $itemName] = splitWebdavPath($rawRelPath);

// 1. LOCK / UNLOCK (Synthetischer Lock-Support für Windows Explorer & macOS Finder)
if ($method === 'LOCK') {
    $lockToken = 'opaquelocktoken:' . bin2hex(random_bytes(16));
    header('Content-Type: application/xml; charset=utf-8');
    header('Lock-Token: <' . $lockToken . '>');
    http_response_code(200);
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
       . '<D:prop xmlns:D="DAV:"><D:lockdiscovery><D:activelock>'
       . '<D:locktype><D:write/></D:locktype>'
       . '<D:lockscope><D:exclusive/></D:lockscope>'
       . '<D:depth>infinity</D:depth>'
       . '<D:timeout>Second-3600</D:timeout>'
       . '<D:locktoken><D:href>' . $lockToken . '</D:href></D:locktoken>'
       . '</D:activelock></D:lockdiscovery></D:prop>';
    exit;
}

if ($method === 'UNLOCK') {
    http_response_code(204);
    exit;
}

// 2. PROPFIND
if ($method === 'PROPFIND') {
    $depthHeader = strtolower(trim($_SERVER['HTTP_DEPTH'] ?? '1'));
    $depth = ($depthHeader === '0') ? 0 : 1;

    // Prüfen, ob es sich um eine Datei oder ein Verzeichnis handelt
    $fileMatch = webdavFindFile($pdo, $userId, $parentFolder, $itemName);
    if ($fileMatch !== null) {
        $href = encodeWebdavHref($scriptName, ($parentFolder !== '' ? $parentFolder . '/' : '') . $fileMatch['original_name'], false);
        $mtime = strtotime((string)$fileMatch['upload_date']) ?: time();
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
           . '<D:multistatus xmlns:D="DAV:">' . "\n"
           . buildDavResponseXml($href, (string)$fileMatch['original_name'], false, (int)$fileMatch['file_size'], $mtime, (string)$fileMatch['mime_type'])
           . '</D:multistatus>';
        exit;
    }

    if (!webdavFolderExists($pdo, $userId, $cleanRelPath)) {
        http_response_code(404);
        exit;
    }

    $responses = '';
    $selfHref  = encodeWebdavHref($scriptName, $cleanRelPath, true);
    $selfLabel = $cleanRelPath === '' ? 'Root' : basename($cleanRelPath);
    $responses .= buildDavResponseXml($selfHref, $selfLabel, true, 0, time());

    if ($depth >= 1) {
        // Unterordner ermitteln
        $subFolders = [];
        $stmtUf = $pdo->prepare("SELECT `folder_path`, `created_at` FROM `user_folders` WHERE `user_id` = :uid");
        $stmtUf->execute([':uid' => $userId]);
        $prefix = $cleanRelPath === '' ? '' : $cleanRelPath . '/';
        $prefixLen = strlen($prefix);

        foreach ($stmtUf->fetchAll() as $uf) {
            $fp = (string)$uf['folder_path'];
            if ($prefix === '' || str_starts_with($fp, $prefix)) {
                $rest = $prefix === '' ? $fp : substr($fp, $prefixLen);
                if ($rest !== '') {
                    $firstSeg = explode('/', $rest)[0];
                    $fullSub  = $prefix . $firstSeg;
                    $subFolders[$fullSub] = strtotime((string)$uf['created_at']) ?: time();
                }
            }
        }

        // Implizite Unterordner aus Dateien ergänzen
        $stmtImplicit = $pdo->prepare("SELECT DISTINCT `folder_path` FROM `files` WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `folder_path` <> ''");
        $stmtImplicit->execute([':uid' => $userId]);
        foreach ($stmtImplicit->fetchAll(PDO::FETCH_COLUMN) as $fpRaw) {
            $fp = (string)$fpRaw;
            if ($prefix === '' || str_starts_with($fp, $prefix)) {
                $rest = $prefix === '' ? $fp : substr($fp, $prefixLen);
                if ($rest !== '') {
                    $firstSeg = explode('/', $rest)[0];
                    $fullSub  = $prefix . $firstSeg;
                    if (!isset($subFolders[$fullSub])) {
                        $subFolders[$fullSub] = time();
                    }
                }
            }
        }

        ksort($subFolders);
        foreach ($subFolders as $subPath => $subMtime) {
            $subHref = encodeWebdavHref($scriptName, $subPath, true);
            $responses .= buildDavResponseXml($subHref, basename($subPath), true, 0, $subMtime);
        }

        // Dateien im aktuellen Ordner
        $stmtFiles = $pdo->prepare(
            "SELECT * FROM `files`
             WHERE `user_id` = :uid
               AND `folder_path` = :fp
               AND `deleted_at` IS NULL
               AND `is_encrypted` = 0
             ORDER BY `original_name` ASC"
        );
        $stmtFiles->execute([':uid' => $userId, ':fp' => $cleanRelPath]);
        foreach ($stmtFiles->fetchAll() as $f) {
            $fRel = ($cleanRelPath !== '' ? $cleanRelPath . '/' : '') . $f['original_name'];
            $fHref = encodeWebdavHref($scriptName, $fRel, false);
            $fMtime = strtotime((string)$f['upload_date']) ?: time();
            $responses .= buildDavResponseXml($fHref, (string)$f['original_name'], false, (int)$f['file_size'], $fMtime, (string)$f['mime_type']);
        }
    }

    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
       . '<D:multistatus xmlns:D="DAV:">' . "\n"
       . $responses
       . '</D:multistatus>';
    exit;
}

// 3. GET / HEAD
if ($method === 'GET' || $method === 'HEAD') {
    $fileMatch = webdavFindFile($pdo, $userId, $parentFolder, $itemName);
    if ($fileMatch === null) {
        http_response_code(404);
        exit;
    }

    $physPath = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string)$fileMatch['filename']);
    if (!is_file($physPath) || !is_readable($physPath)) {
        http_response_code(404);
        exit;
    }

    $size  = (int)filesize($physPath);
    $mtime = strtotime((string)$fileMatch['upload_date']) ?: (int)filemtime($physPath);
    header('Content-Type: ' . ((string)$fileMatch['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . $size);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: "' . md5($fileMatch['id'] . ':' . $size . ':' . $mtime) . '"');

    if ($method === 'HEAD') {
        http_response_code(200);
        exit;
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    readfile($physPath);
    exit;
}

// 4. PUT (Datei hochladen oder bestehende Datei überschreiben)
if ($method === 'PUT') {
    if ($itemName === '') {
        http_response_code(400);
        exit;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0750, true);
    }

    $ext = strtolower(pathinfo($itemName, PATHINFO_EXTENSION));
    $newStored = generateStoredFileName($ext);
    $destPath  = UPLOAD_DIR . DIRECTORY_SEPARATOR . $newStored;

    $inStream  = fopen('php://input', 'rb');
    $outStream = fopen($destPath, 'wb');
    if ($inStream === false || $outStream === false) {
        http_response_code(500);
        exit;
    }

    $writtenBytes = stream_copy_to_stream($inStream, $outStream);
    fclose($inStream);
    fclose($outStream);

    if ($writtenBytes === false) {
        @unlink($destPath);
        http_response_code(500);
        exit;
    }

    @chmod($destPath, 0640);
    $stats = getUserStorageStats($pdo, $userId);
    if (($stats['used'] + $writtenBytes) > $stats['quota']) {
        @unlink($destPath);
        http_response_code(507); // Insufficient Storage
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($destPath) ?: 'application/octet-stream';

    if ($parentFolder !== '') {
        registerFolderHierarchy($pdo, $userId, $parentFolder);
    }

    $existing = webdavFindFile($pdo, $userId, $parentFolder, $itemName);
    if ($existing !== null) {
        createFileVersionSnapshot($pdo, $existing, 'Vor WebDAV-Speicherung');
        $oldPhys = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string)$existing['filename']);
        if (is_file($oldPhys)) {
            @unlink($oldPhys);
        }

        $stmtUpd = $pdo->prepare(
            "UPDATE `files`
             SET `filename` = :stored, `mime_type` = :mime, `file_size` = :size, `upload_date` = NOW()
             WHERE `id` = :id"
        );
        $stmtUpd->execute([
            ':stored' => $newStored,
            ':mime'   => $detectedMime,
            ':size'   => $writtenBytes,
            ':id'     => (int)$existing['id'],
        ]);
        http_response_code(204);
        exit;
    }

    $stmtIns = $pdo->prepare(
        "INSERT INTO `files` (`user_id`, `folder_path`, `original_name`, `filename`, `mime_type`, `file_size`, `is_encrypted`)
         VALUES (:uid, :fp, :orig, :stored, :mime, :size, 0)"
    );
    $stmtIns->execute([
        ':uid'    => $userId,
        ':fp'     => $parentFolder,
        ':orig'   => $itemName,
        ':stored' => $newStored,
        ':mime'   => $detectedMime,
        ':size'   => $writtenBytes,
    ]);

    http_response_code(201);
    exit;
}

// 5. MKCOL (Neuen Ordner erstellen)
if ($method === 'MKCOL') {
    if ($cleanRelPath === '') {
        http_response_code(405);
        exit;
    }
    if (webdavFolderExists($pdo, $userId, $cleanRelPath)) {
        http_response_code(405);
        exit;
    }
    registerFolderHierarchy($pdo, $userId, $cleanRelPath);
    http_response_code(201);
    exit;
}

// 6. DELETE (Datei in den Papierkorb verschieben oder Ordner löschen)
if ($method === 'DELETE') {
    if ($cleanRelPath === '') {
        http_response_code(403);
        exit;
    }

    $fileMatch = webdavFindFile($pdo, $userId, $parentFolder, $itemName);
    if ($fileMatch !== null) {
        $pdo->prepare("UPDATE `files` SET `deleted_at` = NOW() WHERE `id` = :id")
            ->execute([':id' => (int)$fileMatch['id']]);
        http_response_code(204);
        exit;
    }

    if (webdavFolderExists($pdo, $userId, $cleanRelPath)) {
        $pdo->prepare(
            "UPDATE `files` SET `deleted_at` = NOW()
             WHERE `user_id` = :uid AND `deleted_at` IS NULL
               AND (`folder_path` = :fp OR `folder_path` LIKE :fplike)"
        )->execute([
            ':uid'    => $userId,
            ':fp'     => $cleanRelPath,
            ':fplike' => $cleanRelPath . '/%',
        ]);

        $pdo->prepare(
            "DELETE FROM `user_folders`
             WHERE `user_id` = :uid AND (`folder_path` = :fp OR `folder_path` LIKE :fplike)"
        )->execute([
            ':uid'    => $userId,
            ':fp'     => $cleanRelPath,
            ':fplike' => $cleanRelPath . '/%',
        ]);

        http_response_code(204);
        exit;
    }

    http_response_code(404);
    exit;
}

// 7. MOVE / COPY
if ($method === 'MOVE' || $method === 'COPY') {
    $destHeader = trim($_SERVER['HTTP_DESTINATION'] ?? '');
    if ($destHeader === '') {
        http_response_code(400);
        exit;
    }

    $destUriPath = parse_url($destHeader, PHP_URL_PATH) ?: '';
    $destRelRaw  = '';
    if (str_starts_with($destUriPath, $scriptName)) {
        $destRelRaw = substr($destUriPath, strlen($scriptName));
    } else {
        $pos = strpos($destUriPath, '/webdav.php/');
        if ($pos !== false) {
            $destRelRaw = substr($destUriPath, $pos + strlen('/webdav.php/'));
        }
    }
    $destRelRaw = trim(rawurldecode($destRelRaw), '/');
    $destClean  = sanitizeFolderPath($destRelRaw);
    [$destFolder, $destName] = splitWebdavPath($destRelRaw);

    if ($destName === '') {
        http_response_code(400);
        exit;
    }

    $fileMatch = webdavFindFile($pdo, $userId, $parentFolder, $itemName);
    if ($fileMatch !== null) {
        if ($destFolder !== '') {
            registerFolderHierarchy($pdo, $userId, $destFolder);
        }

        // Falls Zieldatei bereits existiert, als Version sichern und ersetzen
        $targetExisting = webdavFindFile($pdo, $userId, $destFolder, $destName);
        if ($targetExisting !== null && (int)$targetExisting['id'] !== (int)$fileMatch['id']) {
            createFileVersionSnapshot($pdo, $targetExisting, 'Vor WebDAV-' . $method);
            $pdo->prepare("UPDATE `files` SET `deleted_at` = NOW() WHERE `id` = :id")
                ->execute([':id' => (int)$targetExisting['id']]);
        }

        if ($method === 'MOVE') {
            $pdo->prepare("UPDATE `files` SET `folder_path` = :fp, `original_name` = :name WHERE `id` = :id")
                ->execute([
                    ':fp'   => $destFolder,
                    ':name' => $destName,
                    ':id'   => (int)$fileMatch['id'],
                ]);
            http_response_code($targetExisting ? 204 : 201);
            exit;
        }

        // COPY
        $srcPhys = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string)$fileMatch['filename']);
        if (!is_file($srcPhys)) {
            http_response_code(404);
            exit;
        }
        $ext = strtolower(pathinfo($destName, PATHINFO_EXTENSION));
        $copyStored = generateStoredFileName($ext);
        $copyPhys   = UPLOAD_DIR . DIRECTORY_SEPARATOR . $copyStored;
        if (!@copy($srcPhys, $copyPhys)) {
            http_response_code(500);
            exit;
        }
        @chmod($copyPhys, 0640);

        $pdo->prepare(
            "INSERT INTO `files` (`user_id`, `folder_path`, `original_name`, `filename`, `mime_type`, `file_size`, `is_encrypted`)
             VALUES (:uid, :fp, :orig, :stored, :mime, :size, 0)"
        )->execute([
            ':uid'    => $userId,
            ':fp'     => $destFolder,
            ':orig'   => $destName,
            ':stored' => $copyStored,
            ':mime'   => (string)$fileMatch['mime_type'],
            ':size'   => (int)$fileMatch['file_size'],
        ]);
        http_response_code($targetExisting ? 204 : 201);
        exit;
    }

    // Ordner verschieben/umbenennen
    if ($method === 'MOVE' && webdavFolderExists($pdo, $userId, $cleanRelPath) && $destClean !== '') {
        registerFolderHierarchy($pdo, $userId, $destClean);

        $stmtMoveFiles = $pdo->prepare(
            "SELECT `id`, `folder_path` FROM `files`
             WHERE `user_id` = :uid AND (`folder_path` = :fp OR `folder_path` LIKE :fplike)"
        );
        $stmtMoveFiles->execute([
            ':uid'    => $userId,
            ':fp'     => $cleanRelPath,
            ':fplike' => $cleanRelPath . '/%',
        ]);
        foreach ($stmtMoveFiles->fetchAll() as $fRow) {
            $oldFp = (string)$fRow['folder_path'];
            $newFp = $destClean . substr($oldFp, strlen($cleanRelPath));
            $pdo->prepare("UPDATE `files` SET `folder_path` = :nfp WHERE `id` = :id")
                ->execute([':nfp' => $newFp, ':id' => (int)$fRow['id']]);
        }

        $stmtMoveUf = $pdo->prepare(
            "SELECT `id`, `folder_path` FROM `user_folders`
             WHERE `user_id` = :uid AND (`folder_path` = :fp OR `folder_path` LIKE :fplike)"
        );
        $stmtMoveUf->execute([
            ':uid'    => $userId,
            ':fp'     => $cleanRelPath,
            ':fplike' => $cleanRelPath . '/%',
        ]);
        foreach ($stmtMoveUf->fetchAll() as $ufRow) {
            $oldFp = (string)$ufRow['folder_path'];
            $newFp = $destClean . substr($oldFp, strlen($cleanRelPath));
            registerFolderHierarchy($pdo, $userId, $newFp);
            $pdo->prepare("DELETE FROM `user_folders` WHERE `id` = :id")
                ->execute([':id' => (int)$ufRow['id']]);
        }
        registerFolderHierarchy($pdo, $userId, $destClean);
        http_response_code(201);
        exit;
    }

    http_response_code(404);
    exit;
}

http_response_code(405);
header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, MKCOL, PROPFIND, MOVE, COPY, LOCK, UNLOCK');
