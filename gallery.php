<?php
/**
 * MiniCloud – Foto-Alben & Medien-Galerie (gallery.php)
 * - Chronologische Fotowand aller eigenen & geteilten Bilder der Cloud
 * - Virtuelle Foto-Alben (ohne doppelten Speicherplatzverbrauch)
 * - Direkt-Upload mehrerer Fotos vom Smartphone/PC in ein Album
 * - Vollbild-Lightbox mit automatischer Diashow (3s/5s/8s), Tastatursteuerung & Zoom
 * - Lokaler EXIF-Metadaten-Inspektor (Aufnahmedatum, Kamera, Blende, ISO, Auflösung)
 * - Öffentliche Album-Freigabe per Link & lokalem SVG-QR-Code (optional mit Passwort
 *   und optionalem „Party-/Vereins-Sammelalbum“ Gast-Foto-Upload)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_gallery'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('warning', 'Das Modul „Foto-Alben & Medien-Galerie“ ist durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Tabellen bei Bedarf selbstheilend anlegen
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `gallery_albums` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(180) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `cover_file_id` INT UNSIGNED DEFAULT NULL,
        `event_date` DATE DEFAULT NULL,
        `shared_user_id` INT UNSIGNED DEFAULT NULL,
        `group_id` INT UNSIGNED DEFAULT NULL,
        `share_token` VARCHAR(64) DEFAULT NULL,
        `password_hash` VARCHAR(255) DEFAULT NULL,
        `allow_guest_upload` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_ga_token` (`share_token`),
        KEY `idx_ga_user` (`user_id`),
        KEY `idx_ga_group` (`group_id`),
        KEY `idx_ga_shared` (`shared_user_id`),
        CONSTRAINT `fk_ga_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `gallery_album_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `album_id` INT UNSIGNED NOT NULL,
        `file_id` INT UNSIGNED NOT NULL,
        `caption` VARCHAR(255) NOT NULL DEFAULT '',
        `added_by_name` VARCHAR(100) NOT NULL DEFAULT '',
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_gai_unique` (`album_id`, `file_id`),
        KEY `idx_gai_file` (`file_id`),
        CONSTRAINT `fk_gai_album` FOREIGN KEY (`album_id`) REFERENCES `gallery_albums` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_gai_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$albumToken  = isset($_GET['album_token']) && is_string($_GET['album_token']) ? trim($_GET['album_token']) : '';
$isGuestMode = ($albumToken !== '' && !isLoggedIn());

if ($albumToken === '' && !isLoggedIn()) {
    requireLogin();
}

$userId   = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
$username = isLoggedIn() ? (string)($_SESSION['username'] ?? '') : '';

/**
 * Prüft, ob ein Benutzer oder Gast Zugriff auf ein Album hat.
 */
function canAccessGalleryAlbum(PDO $pdo, array $album, int $userId, string $token): bool
{
    if ($token !== '' && !empty($album['share_token']) && hash_equals((string)$album['share_token'], $token)) {
        return true;
    }
    if ($userId <= 0) {
        return false;
    }
    if ((int)$album['user_id'] === $userId || (int)($album['shared_user_id'] ?? 0) === $userId) {
        return true;
    }
    if (!empty($album['group_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => (int)$album['group_id'], ':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

/**
 * Prüft, ob ein Album bearbeiten/befüllen werden darf.
 */
function canEditGalleryAlbum(PDO $pdo, array $album, int $userId, string $token): bool
{
    if ($userId > 0 && ((int)$album['user_id'] === $userId || (int)($album['shared_user_id'] ?? 0) === $userId)) {
        return true;
    }
    if ($userId > 0 && !empty($album['group_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => (int)$album['group_id'], ':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    if ($token !== '' && !empty($album['share_token']) && hash_equals((string)$album['share_token'], $token)) {
        return (int)($album['allow_guest_upload'] ?? 0) === 1;
    }
    return false;
}

/**
 * Prüft, ob eine Bilddatei durch den aktuellen Benutzer oder über das aktuelle Album-Token betrachtet werden darf.
 */
function canViewGalleryImage(PDO $pdo, array $fileRow, int $userId, string $albumToken): bool
{
    if (!empty($fileRow['deleted_at']) || (int)($fileRow['is_encrypted'] ?? 0) === 1) {
        return false;
    }
    $fid = (int)$fileRow['id'];

    if ($albumToken !== '') {
        $stmt = $pdo->prepare(
            'SELECT ga.id, ga.password_hash
             FROM gallery_albums ga
             INNER JOIN gallery_album_items gai ON gai.album_id = ga.id
             WHERE ga.share_token = :tok AND gai.file_id = :fid
             LIMIT 1'
        );
        $stmt->execute([':tok' => $albumToken, ':fid' => $fid]);
        $alb = $stmt->fetch();
        if ($alb) {
            if (!empty($alb['password_hash'])) {
                $unlocked = $_SESSION['unlocked_gallery_albums'] ?? [];
                return !empty($unlocked[(int)$alb['id']]);
            }
            return true;
        }
    }

    if ($userId <= 0) {
        return false;
    }

    if ((int)$fileRow['user_id'] === $userId) {
        return true;
    }

    // Gruppenordner-Prüfung
    if (!empty($fileRow['group_folder_id'])) {
        if (canUserAccessGroupFolder($pdo, $userId, (int)$fileRow['group_folder_id'], false, isAdmin())) {
            return true;
        }
    }

    // Direktfreigabe oder Album-Freigabe prüfen
    $stmtShare = $pdo->prepare('SELECT 1 FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :uid LIMIT 1');
    $stmtShare->execute([':fid' => $fid, ':uid' => $userId]);
    if ($stmtShare->fetchColumn()) {
        return true;
    }

    // Über ein geteiltes Foto-Album sichtbar?
    $stmtAlb = $pdo->prepare(
        'SELECT ga.* FROM gallery_albums ga
         INNER JOIN gallery_album_items gai ON gai.album_id = ga.id
         WHERE gai.file_id = :fid'
    );
    $stmtAlb->execute([':fid' => $fid]);
    foreach ($stmtAlb->fetchAll() as $albRow) {
        if (canAccessGalleryAlbum($pdo, $albRow, $userId, '')) {
            return true;
        }
    }

    return false;
}

// 1. Bild-Streaming-Endpunkt (für schnelle Vorschau, Lightbox & Gast-Alben)
if (isset($_GET['stream_file'])) {
    $streamId = (int)$_GET['stream_file'];
    $stmtFile = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmtFile->execute([':id' => $streamId]);
    $fileRow = $stmtFile->fetch();

    if (!$fileRow || (int)($fileRow['is_encrypted'] ?? 0) === 1 || !canViewGalleryImage($pdo, $fileRow, $userId, $albumToken)) {
        http_response_code(403);
        exit('Kein Zugriff auf dieses Bild.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $physicalPath = UPLOAD_DIR . basename((string)$fileRow['filename']);
    if (!is_file($physicalPath)) {
        http_response_code(404);
        exit('Bilddatei nicht gefunden.');
    }

    // Falls als Kachel-Thumbnail angefordert (?thumb=1), komprimiertes WebP/JPEG aus uploads/.thumbs/ ausliefern
    if (isset($_GET['thumb']) && $_GET['thumb'] === '1' && !isset($_GET['download'])) {
        $mtime = (int)(@filemtime($physicalPath) ?: 0);
        $maxDim = 360;
        $etag = sprintf('"mc-thumb-%d-%d-%d"', $streamId, $mtime, $maxDim);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=2592000, immutable');
        header('X-Content-Type-Options: nosniff');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }
        $thumbDir = UPLOAD_DIR . '.thumbs/';
        if (!is_dir($thumbDir)) {
            @mkdir($thumbDir, 0750, true);
            @file_put_contents($thumbDir . '.htaccess', "Order deny,allow\nDeny from all\n");
            @file_put_contents($thumbDir . 'index.php', "<?php http_response_code(403); exit;\n");
        }
        $supportsWebp = function_exists('imagewebp');
        $thumbExt  = $supportsWebp ? 'webp' : 'jpg';
        $thumbMime = $supportsWebp ? 'image/webp' : 'image/jpeg';
        $thumbPath = sprintf('%sthumb_%d_%d_%d.%s', $thumbDir, $streamId, $mtime, $maxDim, $thumbExt);
        if (is_file($thumbPath) && is_readable($thumbPath)) {
            header('Content-Type: ' . $thumbMime);
            header('Content-Length: ' . (string)filesize($thumbPath));
            readfile($thumbPath);
            exit;
        }
        $fileSize = (int)filesize($physicalPath);
        if (extension_loaded('gd') && function_exists('imagecreatetruecolor') && $fileSize > 0 && $fileSize <= 35 * 1048576) {
            $imgInfo = @getimagesize($physicalPath);
            if (is_array($imgInfo) && isset($imgInfo[0], $imgInfo[1], $imgInfo[2])) {
                $srcW = (int)$imgInfo[0];
                $srcH = (int)$imgInfo[1];
                $type = (int)$imgInfo[2];
                if ($srcW > 0 && $srcH > 0 && ($srcW * $srcH) <= 36000000) {
                    $srcImg = match ($type) {
                        IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($physicalPath) : false,
                        IMAGETYPE_PNG  => function_exists('imagecreatefrompng')  ? @imagecreatefrompng($physicalPath)  : false,
                        IMAGETYPE_GIF  => function_exists('imagecreatefromgif')  ? @imagecreatefromgif($physicalPath)  : false,
                        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($physicalPath) : false,
                        IMAGETYPE_BMP  => function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($physicalPath)  : false,
                        default        => false,
                    };
                    if ($srcImg !== false) {
                        $scale = min(1.0, $maxDim / max($srcW, $srcH));
                        $dstW  = max(1, (int)round($srcW * $scale));
                        $dstH  = max(1, (int)round($srcH * $scale));
                        $dstImg = imagecreatetruecolor($dstW, $dstH);
                        if ($dstImg !== false) {
                            imagealphablending($dstImg, false);
                            imagesavealpha($dstImg, true);
                            $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
                            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, (int)$transparent);
                            if (imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
                                $saved = $supportsWebp ? @imagewebp($dstImg, $thumbPath, 82) : @imagejpeg($dstImg, $thumbPath, 84);
                                imagedestroy($dstImg);
                                imagedestroy($srcImg);
                                if ($saved && is_file($thumbPath)) {
                                    @chmod($thumbPath, 0640);
                                    header('Content-Type: ' . $thumbMime);
                                    header('Content-Length: ' . (string)filesize($thumbPath));
                                    readfile($thumbPath);
                                    exit;
                                }
                            } else {
                                imagedestroy($dstImg);
                                imagedestroy($srcImg);
                            }
                        } else {
                            imagedestroy($srcImg);
                        }
                    }
                }
            }
        }
    }

    $ext = strtolower(pathinfo((string)$fileRow['original_name'], PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'avif' => 'image/avif',
    ];
    $contentType = $mimeMap[$ext] ?? 'image/jpeg';

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)filesize($physicalPath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['download']) && $_GET['download'] === '1') {
        $safeDownloadName = str_replace(['"', "\r", "\n"], '', (string)$fileRow['original_name']);
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
    } else {
        header('Content-Disposition: inline');
    }
    readfile($physicalPath);
    exit;
}

// 2. Lokaler EXIF- & Bildmetadaten-Endpunkt (JSON)
if (isset($_GET['exif_file'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $exifId = (int)$_GET['exif_file'];
    $stmtFile = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
    $stmtFile->execute([':id' => $exifId]);
    $fileRow = $stmtFile->fetch();

    if (!$fileRow || !canViewGalleryImage($pdo, $fileRow, $userId, $albumToken)) {
        echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }

    $physicalPath = UPLOAD_DIR . basename((string)$fileRow['filename']);
    if (!is_file($physicalPath)) {
        echo json_encode(['ok' => false, 'error' => 'Datei nicht gefunden']);
        exit;
    }

    $meta = [
        'ok'         => true,
        'name'       => (string)$fileRow['original_name'],
        'size'       => formatBytes((int)($fileRow['file_size'] ?? 0)),
        'uploaded'   => date('d.m.Y H:i', strtotime((string)$fileRow['upload_date'])),
        'folder'     => (string)($fileRow['folder_path'] ?: '/'),
        'dimensions' => '–',
        'camera'     => '–',
        'taken_at'   => '–',
        'aperture'   => '–',
        'exposure'   => '–',
        'iso'        => '–',
        'focal'      => '–',
    ];

    $imgSize = @getimagesize($physicalPath);
    if (is_array($imgSize) && isset($imgSize[0], $imgSize[1])) {
        $mp = round(($imgSize[0] * $imgSize[1]) / 1000000, 1);
        $meta['dimensions'] = $imgSize[0] . ' × ' . $imgSize[1] . ' px' . ($mp >= 0.1 ? ' (' . $mp . ' MP)' : '');
    }

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($physicalPath, 'ANY_TAG', true);
        if (is_array($exif)) {
            $make  = trim((string)($exif['IFD0']['Make'] ?? ''));
            $model = trim((string)($exif['IFD0']['Model'] ?? ''));
            if ($make !== '' || $model !== '') {
                $meta['camera'] = trim($make . ' ' . (str_starts_with($model, $make) ? substr($model, strlen($make)) : $model));
            }
            $dateOrig = (string)($exif['EXIF']['DateTimeOriginal'] ?? ($exif['IFD0']['DateTime'] ?? ''));
            if ($dateOrig !== '') {
                $ts = strtotime(str_replace(':', '-', substr($dateOrig, 0, 10)) . substr($dateOrig, 10));
                $meta['taken_at'] = $ts ? date('d.m.Y H:i', $ts) : $dateOrig;
            }
            if (!empty($exif['COMPUTED']['ApertureFNumber'])) {
                $meta['aperture'] = (string)$exif['COMPUTED']['ApertureFNumber'];
            }
            if (!empty($exif['EXIF']['ExposureTime'])) {
                $meta['exposure'] = (string)$exif['EXIF']['ExposureTime'] . ' s';
            }
            if (!empty($exif['EXIF']['ISOSpeedRatings'])) {
                $isoVal = $exif['EXIF']['ISOSpeedRatings'];
                $meta['iso'] = 'ISO ' . (is_array($isoVal) ? implode('/', $isoVal) : (string)$isoVal);
            }
            if (!empty($exif['EXIF']['FocalLength'])) {
                $fl = (string)$exif['EXIF']['FocalLength'];
                if (str_contains($fl, '/')) {
                    [$num, $den] = array_map('floatval', explode('/', $fl, 2));
                    if ($den > 0) {
                        $fl = round($num / $den, 1) . ' mm';
                    }
                } else {
                    $fl .= ' mm';
                }
                $meta['focal'] = $fl;
            }
        }
    }

    echo json_encode($meta);
    exit;
}

// POST-Aktionen verarbeiten
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: gallery.php' . ($albumToken !== '' ? '?album_token=' . urlencode($albumToken) : ''));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // Passwort-Entsperrung für passwortgeschützte Gast-Alben
    if ($action === 'unlock_album') {
        $albId = (int)($_POST['album_id'] ?? 0);
        $pass  = (string)($_POST['album_password'] ?? '');
        $stmtA = $pdo->prepare('SELECT id, password_hash, share_token FROM gallery_albums WHERE id = :id LIMIT 1');
        $stmtA->execute([':id' => $albId]);
        $albRow = $stmtA->fetch();

        if ($albRow && !empty($albRow['password_hash']) && password_verify($pass, (string)$albRow['password_hash'])) {
            $_SESSION['unlocked_gallery_albums'][(int)$albRow['id']] = true;
            setFlash('success', 'Album erfolgreich entsperrt.');
        } else {
            setFlash('error', 'Das eingegebene Passwort ist leider falsch.');
        }
        header('Location: gallery.php?album_token=' . urlencode((string)($albRow['share_token'] ?? $albumToken)));
        exit;
    }

    // Direkt-Upload von Fotos in ein Album (für eingeloggte Mitglieder UND für Gäste bei allow_guest_upload = 1)
    if ($action === 'upload_album_photos') {
        $albId = (int)($_POST['album_id'] ?? 0);
        $tok   = trim((string)($_POST['album_token'] ?? $albumToken));
        $guestName = trim((string)($_POST['uploader_name'] ?? ($username ?: 'Gast')));
        $defaultCaption = trim((string)($_POST['default_caption'] ?? ''));

        $stmtA = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = :id LIMIT 1');
        $stmtA->execute([':id' => $albId]);
        $album = $stmtA->fetch();

        if (!$album || !canEditGalleryAlbum($pdo, $album, $userId, $tok)) {
            setFlash('error', 'Keine Berechtigung zum Hochladen von Fotos in dieses Album.');
            header('Location: gallery.php' . ($tok !== '' ? '?album_token=' . urlencode($tok) : ''));
            exit;
        }

        // Ziel-Benutzerkonto für den Speicherplatz: Album-Eigentümer bei Gast-Uploads, sonst eigener Account
        $ownerId = $userId > 0 ? $userId : (int)$album['user_id'];
        $safeFolder = 'Fotos/' . preg_replace('/[^a-zA-Z0-9äöüÄÖÜß _\-]/u', '', (string)$album['title']);
        $safeFolder = trim($safeFolder, '/') ?: 'Fotos/Album';

        // Ordner in user_folders registrieren
        $stmtChkFolder = $pdo->prepare('SELECT id FROM user_folders WHERE user_id = :uid AND folder_path = :fp AND group_folder_id IS NULL LIMIT 1');
        foreach (['Fotos', $safeFolder] as $fpCreate) {
            $stmtChkFolder->execute([':uid' => $ownerId, ':fp' => $fpCreate]);
            if (!$stmtChkFolder->fetchColumn()) {
                $pdo->prepare('INSERT INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                    ->execute([':uid' => $ownerId, ':fp' => $fpCreate]);
            }
        }

        $uploadedCount = 0;
        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            $fileCount = count($_FILES['photos']['name']);
            $allowedImgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmpPath  = (string)$_FILES['photos']['tmp_name'][$i];
                $origName = basename((string)$_FILES['photos']['name'][$i]);
                $fileSize = (int)$_FILES['photos']['size'][$i];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedImgExts, true) || $fileSize <= 0 || $fileSize > MAX_FILE_SIZE) {
                    continue;
                }

                $imgInfo = @getimagesize($tmpPath);
                if ($imgInfo === false) {
                    continue;
                }

                if (!hasAvailableQuota($pdo, $ownerId, $fileSize)) {
                    setFlash('warning', 'Das Speicherkontingent des Album-Besitzers ist ausgeschöpft.');
                    break;
                }

                $storedName = bin2hex(random_bytes(16)) . '.bin';
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0750, true);
                }
                $destPath = UPLOAD_DIR . $storedName;

                if (move_uploaded_file($tmpPath, $destPath)) {
                    $mimeType = (string)($imgInfo['mime'] ?? 'image/jpeg');
                    $stmtInsFile = $pdo->prepare(
                        'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type)
                         VALUES (:uid, :fp, :fn, :on, :fs, :mt)'
                    );
                    $stmtInsFile->execute([
                        ':uid' => $ownerId,
                        ':fp'  => $safeFolder,
                        ':fn'  => $storedName,
                        ':on'  => $origName,
                        ':fs'  => $fileSize,
                        ':mt'  => $mimeType,
                    ]);
                    $newFileId = (int)$pdo->lastInsertId();

                    $pdo->prepare(
                        'INSERT IGNORE INTO gallery_album_items (album_id, file_id, caption, added_by_name)
                         VALUES (:aid, :fid, :cap, :by)'
                    )->execute([
                        ':aid' => $albId,
                        ':fid' => $newFileId,
                        ':cap' => mb_substr($defaultCaption, 0, 250),
                        ':by'  => mb_substr($guestName !== '' ? $guestName : 'Gast', 0, 95),
                    ]);

                    // Falls Album noch kein Cover hat, erstes Bild als Cover setzen
                    if (empty($album['cover_file_id'])) {
                        $pdo->prepare('UPDATE gallery_albums SET cover_file_id = :fid WHERE id = :aid AND cover_file_id IS NULL')
                            ->execute([':fid' => $newFileId, ':aid' => $albId]);
                        $album['cover_file_id'] = $newFileId;
                    }
                    $uploadedCount++;
                }
            }
        }

        if ($uploadedCount > 0) {
            setFlash('success', $uploadedCount . ' Foto(s) erfolgreich in das Album „' . $album['title'] . '“ hochgeladen!');
        } else {
            setFlash('warning', 'Es wurden keine gültigen Bilddateien (JPG, PNG, WebP, GIF) zum Hochladen ausgewählt.');
        }

        if ($tok !== '' && $isGuestMode) {
            header('Location: gallery.php?album_token=' . urlencode($tok));
        } else {
            header('Location: gallery.php?album=' . $albId);
        }
        exit;
    }

    // Alle folgenden Aktionen erfordern ein eingeloggtes Mitglied
    requireLogin();

    // Neues Foto-Album erstellen oder bearbeiten
    if ($action === 'save_album') {
        $albumId          = (int)($_POST['album_id'] ?? 0);
        $title            = trim((string)($_POST['title'] ?? ''));
        $description      = trim((string)($_POST['description'] ?? ''));
        $eventDate        = trim((string)($_POST['event_date'] ?? ''));
        $sharedUserId     = (int)($_POST['shared_user_id'] ?? 0) ?: null;
        $groupId          = (int)($_POST['group_id'] ?? 0) ?: null;
        $enablePublicLink = !empty($_POST['enable_public_link']);
        $allowGuestUpload = !empty($_POST['allow_guest_upload']) ? 1 : 0;
        $newPassword      = trim((string)($_POST['album_password'] ?? ''));
        $clearPassword    = !empty($_POST['clear_password']);

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Titel für das Foto-Album ein.');
            header('Location: gallery.php');
            exit;
        }

        $cleanDate = ($eventDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) ? $eventDate : null;

        if ($albumId > 0) {
            $stmtChk = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtChk->execute([':id' => $albumId, ':uid' => $userId]);
            $existing = $stmtChk->fetch();
            if (!$existing) {
                setFlash('error', 'Album nicht gefunden oder keine Berechtigung.');
                header('Location: gallery.php');
                exit;
            }

            $shareToken = $existing['share_token'];
            if ($enablePublicLink && empty($shareToken)) {
                $shareToken = bin2hex(random_bytes(20));
            } elseif (!$enablePublicLink) {
                $shareToken = null;
                $allowGuestUpload = 0;
            }

            $passwordHash = $existing['password_hash'];
            if ($clearPassword) {
                $passwordHash = null;
            } elseif ($newPassword !== '') {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $stmtUpd = $pdo->prepare(
                'UPDATE gallery_albums
                 SET title = :t, description = :d, event_date = :ed, shared_user_id = :su,
                     group_id = :gid, share_token = :tok, password_hash = :pw, allow_guest_upload = :agu
                 WHERE id = :id AND user_id = :uid'
            );
            $stmtUpd->execute([
                ':t'   => mb_substr($title, 0, 180),
                ':d'   => $description !== '' ? $description : null,
                ':ed'  => $cleanDate,
                ':su'  => $sharedUserId,
                ':gid' => $groupId,
                ':tok' => $shareToken,
                ':pw'  => $passwordHash,
                ':agu' => $allowGuestUpload,
                ':id'  => $albumId,
                ':uid' => $userId,
            ]);
            setFlash('success', 'Album-Einstellungen wurden aktualisiert.');
            header('Location: gallery.php?album=' . $albumId);
            exit;
        } else {
            $shareToken   = $enablePublicLink ? bin2hex(random_bytes(20)) : null;
            $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : null;

            $stmtIns = $pdo->prepare(
                'INSERT INTO gallery_albums
                 (user_id, title, description, event_date, shared_user_id, group_id, share_token, password_hash, allow_guest_upload)
                 VALUES (:uid, :t, :d, :ed, :su, :gid, :tok, :pw, :agu)'
            );
            $stmtIns->execute([
                ':uid' => $userId,
                ':t'   => mb_substr($title, 0, 180),
                ':d'   => $description !== '' ? $description : null,
                ':ed'  => $cleanDate,
                ':su'  => $sharedUserId,
                ':gid' => $groupId,
                ':tok' => $shareToken,
                ':pw'  => $passwordHash,
                ':agu' => $enablePublicLink ? $allowGuestUpload : 0,
            ]);
            $newAlbumId = (int)$pdo->lastInsertId();
            setFlash('success', 'Neues Foto-Album „' . $title . '“ wurde angelegt.');
            header('Location: gallery.php?album=' . $newAlbumId);
            exit;
        }
    }

    // Vorhandene Cloud-Bilder einem Album zuordnen
    if ($action === 'add_existing_to_album') {
        $albumId = (int)($_POST['album_id'] ?? 0);
        $fileIds = isset($_POST['file_ids']) && is_array($_POST['file_ids']) ? array_map('intval', $_POST['file_ids']) : [];

        $stmtAlb = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = :id LIMIT 1');
        $stmtAlb->execute([':id' => $albumId]);
        $album = $stmtAlb->fetch();

        if (!$album || !canEditGalleryAlbum($pdo, $album, $userId, '')) {
            setFlash('error', 'Keine Berechtigung für dieses Album.');
            header('Location: gallery.php');
            exit;
        }

        $added = 0;
        $stmtIns = $pdo->prepare(
            'INSERT IGNORE INTO gallery_album_items (album_id, file_id, added_by_name)
             VALUES (:aid, :fid, :by)'
        );
        foreach ($fileIds as $fid) {
            if ($fid <= 0) {
                continue;
            }
            $stmtF = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
            $stmtF->execute([':id' => $fid]);
            $fRow = $stmtF->fetch();
            if ($fRow && canViewGalleryImage($pdo, $fRow, $userId, '')) {
                $stmtIns->execute([
                    ':aid' => $albumId,
                    ':fid' => $fid,
                    ':by'  => $username,
                ]);
                if ($stmtIns->rowCount() > 0) {
                    $added++;
                }
                if (empty($album['cover_file_id'])) {
                    $pdo->prepare('UPDATE gallery_albums SET cover_file_id = :fid WHERE id = :aid AND cover_file_id IS NULL')
                        ->execute([':fid' => $fid, ':aid' => $albumId]);
                    $album['cover_file_id'] = $fid;
                }
            }
        }

        setFlash('success', $added . ' Bild(er) wurden dem Album „' . $album['title'] . '“ hinzugefügt.');
        header('Location: gallery.php?album=' . $albumId);
        exit;
    }

    // Bildbeschreibung oder Album-Titelbild setzen / aus Album entfernen
    if ($action === 'update_album_item') {
        $albumId   = (int)($_POST['album_id'] ?? 0);
        $fileId    = (int)($_POST['file_id'] ?? 0);
        $subAction = (string)($_POST['sub_action'] ?? 'caption');
        $caption   = trim((string)($_POST['caption'] ?? ''));

        $stmtAlb = $pdo->prepare('SELECT * FROM gallery_albums WHERE id = :id LIMIT 1');
        $stmtAlb->execute([':id' => $albumId]);
        $album = $stmtAlb->fetch();

        if ($album && canEditGalleryAlbum($pdo, $album, $userId, '')) {
            if ($subAction === 'set_cover') {
                $pdo->prepare('UPDATE gallery_albums SET cover_file_id = :fid WHERE id = :aid')
                    ->execute([':fid' => $fileId, ':aid' => $albumId]);
                setFlash('success', 'Album-Titelbild wurde aktualisiert.');
            } elseif ($subAction === 'remove_item') {
                $pdo->prepare('DELETE FROM gallery_album_items WHERE album_id = :aid AND file_id = :fid')
                    ->execute([':aid' => $albumId, ':fid' => $fileId]);
                setFlash('success', 'Bild wurde aus dem Album entfernt (Originaldatei bleibt in der Cloud erhalten).');
            } else {
                $pdo->prepare('UPDATE gallery_album_items SET caption = :cap WHERE album_id = :aid AND file_id = :fid')
                    ->execute([':cap' => mb_substr($caption, 0, 250), ':aid' => $albumId, ':fid' => $fileId]);
                setFlash('success', 'Bildunterschrift gespeichert.');
            }
        }
        header('Location: gallery.php?album=' . $albumId);
        exit;
    }

    // Album löschen (nur Zuordnung, keine physischen Cloud-Dateien!)
    if ($action === 'delete_album') {
        $albumId = (int)($_POST['album_id'] ?? 0);
        $pdo->prepare('DELETE FROM gallery_albums WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $albumId, ':uid' => $userId]);
        setFlash('success', 'Das Foto-Album wurde gelöscht (alle Originalfotos bleiben in deiner Cloud erhalten).');
        header('Location: gallery.php');
        exit;
    }
}

// Daten für Seitenansicht laden
$activeAlbumId = isset($_GET['album']) ? (int)$_GET['album'] : 0;
$viewMode      = isset($_GET['view']) && in_array($_GET['view'], ['all', 'albums'], true) ? $_GET['view'] : ($activeAlbumId > 0 || $albumToken !== '' ? 'album' : 'all');
$searchQuery   = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$folderFilter  = isset($_GET['folder']) && is_string($_GET['folder']) ? trim($_GET['folder']) : '';

$albums      = [];
$activeAlbum = null;

if ($albumToken !== '') {
    $stmtAlb = $pdo->prepare(
        'SELECT ga.*, u.username AS owner_name, g.name AS group_name,
                (SELECT COUNT(*) FROM gallery_album_items gai WHERE gai.album_id = ga.id) AS photo_count
         FROM gallery_albums ga
         INNER JOIN users u ON u.id = ga.user_id
         LEFT JOIN `groups` g ON g.id = ga.group_id
         WHERE ga.share_token = :tok
         LIMIT 1'
    );
    $stmtAlb->execute([':tok' => $albumToken]);
    $activeAlbum = $stmtAlb->fetch();
    if (!$activeAlbum) {
        http_response_code(404);
        exit('Dieses Foto-Album existiert nicht oder die Freigabe wurde beendet.');
    }
    $activeAlbumId = (int)$activeAlbum['id'];
} else {
    $userGroups = getGroupsForUser($pdo, $userId);
    $groupIds   = array_map(static fn($g) => (int)$g['id'], $userGroups);

    $sqlAlb = 'SELECT ga.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username,
                      (SELECT COUNT(*) FROM gallery_album_items gai WHERE gai.album_id = ga.id) AS photo_count,
                      (SELECT gai2.file_id FROM gallery_album_items gai2 WHERE gai2.album_id = ga.id ORDER BY gai2.id ASC LIMIT 1) AS first_file_id
               FROM gallery_albums ga
               INNER JOIN users u ON u.id = ga.user_id
               LEFT JOIN `groups` g ON g.id = ga.group_id
               LEFT JOIN users su ON su.id = ga.shared_user_id
               WHERE ga.user_id = :uid1 OR ga.shared_user_id = :uid2';
    $paramsAlb = [':uid1' => $userId, ':uid2' => $userId];
    if (!empty($groupIds)) {
        $phs = [];
        foreach ($groupIds as $idx => $gid) {
            $k = ':gid' . $idx;
            $phs[] = $k;
            $paramsAlb[$k] = $gid;
        }
        $sqlAlb .= ' OR ga.group_id IN (' . implode(',', $phs) . ')';
    }
    $sqlAlb .= ' ORDER BY COALESCE(ga.event_date, DATE(ga.created_at)) DESC, ga.id DESC';
    $stmtAlb = $pdo->prepare($sqlAlb);
    $stmtAlb->execute($paramsAlb);
    $albums = $stmtAlb->fetchAll();

    if ($activeAlbumId > 0) {
        foreach ($albums as $alb) {
            if ((int)$alb['id'] === $activeAlbumId) {
                $activeAlbum = $alb;
                break;
            }
        }
    }
}

// Alle eigenen persönlichen Bilder des angemeldeten Benutzers laden
$allCloudPhotos = [];
$photoFolders   = [];
if ($userId > 0) {
    $sqlImg = "SELECT f.*, u.username AS owner_name, '' AS group_folder_name
               FROM files f
               INNER JOIN users u ON u.id = f.user_id
               WHERE f.deleted_at IS NULL
                 AND f.is_encrypted = 0
                 AND f.group_folder_id IS NULL
                 AND f.user_id = :uid
                 AND (
                     LOWER(f.original_name) REGEXP '\\\\.(jpg|jpeg|png|gif|webp|bmp)$'
                     OR f.mime_type LIKE 'image/%'
                 )
               ORDER BY f.upload_date DESC, f.id DESC";
    $stmtImg = $pdo->prepare($sqlImg);
    $stmtImg->execute([':uid' => $userId]);
    $allCloudPhotos = $stmtImg->fetchAll();

    foreach ($allCloudPhotos as $p) {
        $fp = trim((string)($p['folder_path'] ?? ''), '/');
        $folderLabel = $fp !== '' ? $fp : 'Hauptverzeichnis (/)';
        $photoFolders[$folderLabel] = ($photoFolders[$folderLabel] ?? 0) + 1;
    }
}

// Welche Fotos sollen aktuell im Raster angezeigt werden?
$displayedPhotos = [];
$isAlbumLocked   = false;

if ($activeAlbum) {
    if (!empty($activeAlbum['password_hash']) && $userId !== (int)$activeAlbum['user_id']) {
        $unlocked = $_SESSION['unlocked_gallery_albums'] ?? [];
        if (empty($unlocked[(int)$activeAlbum['id']])) {
            $isAlbumLocked = true;
        }
    }

    if (!$isAlbumLocked) {
        $stmtItems = $pdo->prepare(
            'SELECT f.*, gai.caption, gai.added_by_name, gai.created_at AS added_at, u.username AS owner_name
             FROM gallery_album_items gai
             INNER JOIN files f ON f.id = gai.file_id
             INNER JOIN users u ON u.id = f.user_id
             WHERE gai.album_id = :aid AND f.deleted_at IS NULL AND f.is_encrypted = 0
             ORDER BY gai.sort_order ASC, gai.id DESC'
        );
        $stmtItems->execute([':aid' => (int)$activeAlbum['id']]);
        $displayedPhotos = $stmtItems->fetchAll();
    }
} else {
    foreach ($allCloudPhotos as $photo) {
        $fp = trim((string)($photo['folder_path'] ?? ''), '/');
        $folderLabel = $fp !== '' ? $fp : 'Hauptverzeichnis (/)';
        if ($folderFilter !== '' && $folderLabel !== $folderFilter) {
            continue;
        }
        if ($searchQuery !== '' && mb_stripos((string)$photo['original_name'], $searchQuery) === false && mb_stripos($folderLabel, $searchQuery) === false) {
            continue;
        }
        $photo['caption'] = '';
        $photo['added_by_name'] = (string)$photo['owner_name'];
        $displayedPhotos[] = $photo;
    }
}

// Benutzer & Gruppen für Album-Freigabe
$shareableUsers = $userId > 0 ? getVisibleUsersForUser($pdo, $userId) : [];
$userGroupsList = $userId > 0 ? getGroupsForUser($pdo, $userId) : [];

$pageTitle = $activeAlbum ? ('Album: ' . $activeAlbum['title']) : 'Foto-Alben & Medien-Galerie';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- Modul-Kopfzeile im einheitlichen .mc-card Design -->
    <div class="mc-card p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0 shadow-xs border border-brand-100 dark:border-brand-800/60">
                🖼️
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                        <?= e($activeAlbum ? $activeAlbum['title'] : 'Foto-Alben & Medien-Galerie') ?>
                    </h1>
                    <?php if ($activeAlbum): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-100 dark:bg-brand-900/50 text-brand-700 dark:text-brand-300">
                            <?= count($displayedPhotos) ?> Fotos
                        </span>
                        <?php if (!empty($activeAlbum['event_date'])): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                📅 <?= e(date('d.m.Y', strtotime((string)$activeAlbum['event_date']))) ?>
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                            <?= count($allCloudPhotos) ?> Bilder · <?= count($albums) ?> Alben
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <?php if ($activeAlbum && !empty($activeAlbum['description'])): ?>
                        <?= e((string)$activeAlbum['description']) ?>
                    <?php else: ?>
                        Chronologische Fotowand, virtuelle Alben ohne doppelten Speicherbedarf, Vollbild-Diashow, EXIF-Inspektor & Gäste-Sammelalben.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <?php if (!$isGuestMode): ?>
                <a href="gallery.php?view=all"
                   class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition <?= ($viewMode === 'all' && !$activeAlbum) ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                    🖼️ Alle Fotos (<?= count($allCloudPhotos) ?>)
                </a>
                <a href="gallery.php?view=albums"
                   class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition <?= ($viewMode === 'albums' && !$activeAlbum) ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                    📚 Alben (<?= count($albums) ?>)
                </a>
            <?php endif; ?>

            <?php if (!empty($displayedPhotos)): ?>
                <button type="button" onclick="openLightbox(0, true)"
                        class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition flex items-center gap-1.5">
                    ▶️ Diashow starten
                </button>
            <?php endif; ?>

            <?php if (!$isGuestMode): ?>
                <button type="button" onclick="document.getElementById('modalCreateAlbum').classList.remove('hidden')"
                        class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition flex items-center gap-1.5">
                    ➕ Neues Album
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAlbumLocked && $activeAlbum): ?>
        <!-- Passwortschutz-Abfrage für Gäste -->
        <div class="mc-card p-6 sm:p-8 max-w-md mx-auto text-center space-y-4">
            <div class="w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-3xl mx-auto">
                🔒
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Passwortgeschütztes Foto-Album</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Bitte gib das Album-Passwort ein, um die Fotos von <strong><?= e($activeAlbum['title']) ?></strong> anzusehen.
            </p>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e( getCsrfToken() ) ?>">
                <input type="hidden" name="action" value="unlock_album">
                <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">
                <input type="password" name="album_password" required autofocus placeholder="Album-Passwort eingeben…"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm transition">
                    🔓 Album entsperren
                </button>
            </form>
        </div>
    <?php else: ?>

        <?php if ($activeAlbum): ?>
            <!-- Aktives Album: Aktionsleiste, Direkt-Upload & QR-Code-Freigabe -->
            <div class="mc-card p-4 sm:p-5 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm text-slate-600 dark:text-slate-300">
                    <?php if (!$isGuestMode): ?>
                        <a href="gallery.php?view=albums" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-semibold text-slate-700 dark:text-slate-200 transition">
                            ← Alle Alben
                        </a>
                    <?php endif; ?>
                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800">
                        👤 Erstellt von <strong><?= e((string)$activeAlbum['owner_name']) ?></strong>
                    </span>
                    <?php if (!empty($activeAlbum['group_name'])): ?>
                        <span class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 font-medium">
                            👥 Gruppe: <?= e((string)$activeAlbum['group_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($activeAlbum['allow_guest_upload'])): ?>
                        <span class="px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 font-medium">
                            📸 Gäste-Foto-Upload aktiv („Sammelalbum“)
                        </span>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <?php if (canEditGalleryAlbum($pdo, $activeAlbum, $userId, $albumToken)): ?>
                        <button type="button" onclick="document.getElementById('modalUploadToAlbum').classList.remove('hidden')"
                                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition">
                            📤 Fotos hochladen
                        </button>
                    <?php endif; ?>

                    <?php if (!$isGuestMode && canEditGalleryAlbum($pdo, $activeAlbum, $userId, '')): ?>
                        <button type="button" onclick="document.getElementById('modalPickCloudPhotos').classList.remove('hidden')"
                                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition">
                            🗂️ Aus Cloud hinzufügen
                        </button>
                    <?php endif; ?>

                    <?php if (!empty($activeAlbum['share_token'])): ?>
                        <button type="button" onclick="document.getElementById('modalAlbumQr').classList.remove('hidden')"
                                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-xs transition">
                            📱 Teilen & QR-Code
                        </button>
                    <?php endif; ?>

                    <?php if (!$isGuestMode && (int)$activeAlbum['user_id'] === $userId): ?>
                        <button type="button" onclick="document.getElementById('modalEditAlbum').classList.remove('hidden')"
                                class="px-3 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition">
                            ⚙️ Einstellungen
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$activeAlbum && !$isGuestMode && (!empty($albums) || $viewMode === 'albums')): ?>
            <!-- Alben-Kartenübersicht -->
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                        📚 Foto-Alben & Sammelalben
                    </h2>
                    <?php if ($viewMode !== 'albums' && count($albums) > 4): ?>
                        <a href="gallery.php?view=albums" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                            Alle <?= count($albums) ?> Alben anzeigen →
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($albums)): ?>
                    <div class="mc-card p-8 text-center space-y-3">
                        <div class="text-4xl">📚</div>
                        <h3 class="text-base font-bold text-slate-800 dark:text-slate-100">Noch keine Foto-Alben angelegt</h3>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                            Fasse Fotos von Vereinsfesten, Urlauben oder Projekten in virtuellen Alben zusammen – ohne dass die Dateien doppelt Speicherplatz belegen.
                        </p>
                        <button type="button" onclick="document.getElementById('modalCreateAlbum').classList.remove('hidden')"
                                class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold transition">
                            ➕ Erstes Foto-Album erstellen
                        </button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <?php
                        $albumsToShow = ($viewMode === 'albums') ? $albums : array_slice($albums, 0, 4);
                        foreach ($albumsToShow as $alb):
                            $coverId = (int)($alb['cover_file_id'] ?: ($alb['first_file_id'] ?? 0));
                        ?>
                            <a href="gallery.php?album=<?= (int)$alb['id'] ?>"
                               class="mc-card group overflow-hidden flex flex-col hover:border-brand-400 dark:hover:border-brand-500 transition">
                                <div class="relative aspect-16/10 bg-slate-100 dark:bg-slate-800 overflow-hidden flex items-center justify-center">
                                    <?php if ($coverId > 0): ?>
                                        <img src="gallery.php?stream_file=<?= $coverId ?>&amp;thumb=1"
                                             alt="<?= e($alb['title']) ?>"
                                             loading="lazy"
                                             class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                    <?php else: ?>
                                        <span class="text-4xl opacity-40">🖼️</span>
                                    <?php endif; ?>
                                    <div class="absolute top-2.5 right-2.5 flex items-center gap-1">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-900/75 text-white backdrop-blur-xs">
                                            <?= (int)$alb['photo_count'] ?> Fotos
                                        </span>
                                        <?php if (!empty($alb['share_token'])): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-600/90 text-white" title="Öffentlicher Link / QR-Code aktiv">
                                                🔗
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="p-4 flex-1 flex flex-col justify-between">
                                    <div>
                                        <h3 class="font-bold text-sm text-slate-900 dark:text-white group-hover:text-brand-600 dark:group-hover:text-brand-400 truncate">
                                            <?= e($alb['title']) ?>
                                        </h3>
                                        <?php if (!empty($alb['description'])): ?>
                                            <p class="text-xs text-slate-500 dark:text-slate-400 line-clamp-1 mt-0.5">
                                                <?= e((string)$alb['description']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center justify-between text-[11px] text-slate-400 dark:text-slate-500 mt-3 pt-2 border-t border-slate-100 dark:border-slate-800">
                                        <span><?= !empty($alb['event_date']) ? ('📅 ' . date('d.m.Y', strtotime((string)$alb['event_date']))) : ('👤 ' . e((string)$alb['owner_name'])) ?></span>
                                        <?php if (!empty($alb['allow_guest_upload'])): ?>
                                            <span class="text-emerald-600 dark:text-emerald-400 font-semibold">📸 Sammelalbum</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($viewMode !== 'albums' || $activeAlbum): ?>
            <!-- Filter- & Suchleiste für die Fotowand -->
            <?php if (!$activeAlbum && !$isGuestMode): ?>
                <div class="mc-card p-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                    <form method="get" action="gallery.php" class="flex flex-1 flex-wrap items-center gap-2">
                        <input type="hidden" name="view" value="all">
                        <div class="relative flex-1 min-w-[200px]">
                            <input type="text" name="q" value="<?= e($searchQuery) ?>"
                                   placeholder="🔍 Bildname oder Ordner durchsuchen…"
                                   class="w-full pl-3.5 pr-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 text-xs sm:text-sm text-slate-800 dark:text-slate-100">
                        </div>
                        <select name="folder" onchange="this.form.submit()"
                                class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80 text-xs sm:text-sm text-slate-800 dark:text-slate-100">
                            <option value="">📁 Alle Ordner (<?= count($allCloudPhotos) ?>)</option>
                            <?php foreach ($photoFolders as $fName => $cnt): ?>
                                <option value="<?= e($fName) ?>" <?= $folderFilter === $fName ? 'selected' : '' ?>>
                                    <?= e($fName) ?> (<?= $cnt ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold transition">
                            Filtern
                        </button>
                        <?php if ($searchQuery !== '' || $folderFilter !== ''): ?>
                            <a href="gallery.php?view=all" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-600 dark:text-slate-300">
                                ✕ Zurücksetzen
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Fotowand-Raster -->
            <?php if (empty($displayedPhotos)): ?>
                <div class="mc-card p-10 text-center space-y-3">
                    <div class="text-5xl">📷</div>
                    <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100">
                        <?= $activeAlbum ? 'Dieses Album enthält noch keine Fotos' : 'Keine Bilder gefunden' ?>
                    </h3>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                        <?php if ($activeAlbum): ?>
                            Lade direkt neue Fotos vom Smartphone oder PC hoch oder wähle bestehende Bilder aus deiner Cloud aus.
                        <?php else: ?>
                            Lade Bilder über die Dateiverwaltung hoch oder lege direkt ein neues Foto-Album an.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4" id="galleryPhotoGrid">
                    <?php foreach ($displayedPhotos as $idx => $photo):
                        $fid = (int)$photo['id'];
                        $caption = trim((string)($photo['caption'] ?? ''));
                        $displayTitle = $caption !== '' ? $caption : (string)$photo['original_name'];
                        $streamUrl = 'gallery.php?stream_file=' . $fid . '&thumb=1' . ($albumToken !== '' ? '&album_token=' . urlencode($albumToken) : '');
                    ?>
                        <div class="mc-card group relative overflow-hidden flex flex-col">
                            <button type="button"
                                    onclick="openLightbox(<?= (int)$idx ?>, false)"
                                    class="relative aspect-square w-full bg-slate-100 dark:bg-slate-800 overflow-hidden focus:outline-none">
                                <img src="<?= e($streamUrl) ?>"
                                     alt="<?= e($displayTitle) ?>"
                                     loading="lazy"
                                     class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/65 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition flex items-end p-2.5">
                                    <span class="text-[11px] text-white font-medium truncate w-full text-left">
                                        🔍 Vollbild & EXIF
                                    </span>
                                </div>
                            </button>

                            <div class="p-2.5 flex items-center justify-between gap-1.5 text-xs">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-slate-800 dark:text-slate-100 truncate" title="<?= e($displayTitle) ?>">
                                        <?= e($displayTitle) ?>
                                    </p>
                                    <p class="text-[11px] text-slate-400 dark:text-slate-500 truncate">
                                        <?= e(date('d.m.Y', strtotime((string)$photo['upload_date']))) ?>
                                        <?php if (!empty($photo['added_by_name'])): ?>
                                            · <?= e((string)$photo['added_by_name']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    <?php if (!$isGuestMode): ?>
                                        <a href="office.php?id=<?= $fid ?>&amp;return_gallery=1<?= $activeAlbum ? '&amp;return_album=' . (int)$activeAlbum['id'] : '' ?>"
                                           title="Im Foto- &amp; Bild-Studio bearbeiten (Zuschneiden, Größe, Filter, DSGVO-Pixel)"
                                           class="p-1.5 rounded-lg hover:bg-pink-50 dark:hover:bg-pink-950/50 text-slate-400 hover:text-pink-600 transition">
                                            🎨
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($activeAlbum && !$isGuestMode && canEditGalleryAlbum($pdo, $activeAlbum, $userId, '')): ?>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_album_item">
                                            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">
                                            <input type="hidden" name="file_id" value="<?= $fid ?>">
                                            <input type="hidden" name="sub_action" value="set_cover">
                                            <button type="submit" title="Als Album-Titelbild setzen"
                                                    class="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-amber-500">
                                                ⭐
                                            </button>
                                        </form>
                                        <form method="post" class="inline" onsubmit="return confirm('Bild aus dem Album entfernen? (Originaldatei bleibt erhalten)');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_album_item">
                                            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">
                                            <input type="hidden" name="file_id" value="<?= $fid ?>">
                                            <input type="hidden" name="sub_action" value="remove_item">
                                            <button type="submit" title="Aus Album entfernen"
                                                    class="p-1 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-400 hover:text-red-500">
                                                ✕
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>
</main>

<!-- Vollbild-Lightbox mit Diashow & EXIF-Inspektor -->
<div id="galleryLightbox" class="hidden fixed inset-0 z-50 bg-slate-950/95 backdrop-blur-md flex flex-col justify-between select-none">
    <!-- Obere Steuerleiste -->
    <div class="flex items-center justify-between px-4 sm:px-6 py-3.5 bg-black/40 border-b border-white/10 text-white">
        <div class="min-w-0">
            <h3 id="lbTitle" class="font-bold text-sm sm:text-base truncate">Foto</h3>
            <p id="lbSubtitle" class="text-xs text-slate-400 truncate"></p>
        </div>
        <div class="flex items-center gap-2">
            <select id="lbSlideshowInterval" onchange="restartSlideshowIfActive()"
                    class="px-2.5 py-1.5 rounded-xl bg-white/10 border border-white/15 text-xs text-white">
                <option value="3000" class="text-slate-900">⏱️ 3 Sek.</option>
                <option value="5000" selected class="text-slate-900">⏱️ 5 Sek.</option>
                <option value="8000" class="text-slate-900">⏱️ 8 Sek.</option>
            </select>
            <button type="button" id="lbBtnPlay" onclick="toggleSlideshow()"
                    class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-xs font-semibold transition">
                ▶️ Diashow
            </button>
            <button type="button" onclick="toggleExifDrawer()"
                    class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold transition">
                ℹ️ EXIF-Info
            </button>
            <?php if (!$isGuestMode): ?>
            <a id="lbBtnEdit" href="#"
               class="px-3 py-1.5 rounded-xl bg-pink-600 hover:bg-pink-500 text-xs font-bold transition">
                🎨 Bearbeiten
            </a>
            <?php endif; ?>
            <a id="lbBtnDownload" href="#" download
               class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold transition">
                ⬇️ Download
            </a>
            <button type="button" onclick="closeLightbox()"
                    class="px-3 py-1.5 rounded-xl bg-red-600/80 hover:bg-red-600 text-xs font-bold transition">
                ✕ Schließen
            </button>
        </div>
    </div>

    <!-- Mittlerer Bildbereich + Vor/Zurück + EXIF-Seitenleiste -->
    <div class="relative flex-1 flex items-center justify-center overflow-hidden p-4">
        <button type="button" onclick="stepLightbox(-1)"
                class="absolute left-3 sm:left-6 z-10 w-11 h-11 rounded-full bg-black/60 hover:bg-black/80 text-white text-xl flex items-center justify-center border border-white/15 transition">
            ‹
        </button>

        <img id="lbImage" src="" alt=""
             onclick="toggleLightboxZoom()"
             class="max-h-[78vh] max-w-[90vw] object-contain rounded-lg shadow-2xl transition-transform duration-300 cursor-zoom-in">

        <button type="button" onclick="stepLightbox(1)"
                class="absolute right-3 sm:right-6 z-10 w-11 h-11 rounded-full bg-black/60 hover:bg-black/80 text-white text-xl flex items-center justify-center border border-white/15 transition">
            ›
        </button>

        <!-- EXIF-Metadaten-Drawer -->
        <aside id="lbExifDrawer" class="hidden absolute right-4 top-4 bottom-4 w-72 sm:w-80 rounded-2xl bg-slate-900/95 border border-white/15 p-4 text-white overflow-y-auto shadow-2xl space-y-3 z-20">
            <div class="flex items-center justify-between border-b border-white/10 pb-2">
                <h4 class="font-bold text-sm flex items-center gap-1.5">📷 Bild- & EXIF-Details</h4>
                <button type="button" onclick="toggleExifDrawer()" class="text-slate-400 hover:text-white text-sm">✕</button>
            </div>
            <dl class="space-y-2 text-xs">
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Dateiname:</dt><dd id="exifName" class="font-semibold text-right truncate">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Auflösung:</dt><dd id="exifDim" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Dateigröße:</dt><dd id="exifSize" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Aufnahmedatum:</dt><dd id="exifTaken" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Kamera / Gerät:</dt><dd id="exifCam" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Blende:</dt><dd id="exifAperture" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Belichtungszeit:</dt><dd id="exifExposure" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">ISO-Empfindlichkeit:</dt><dd id="exifIso" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Brennweite:</dt><dd id="exifFocal" class="font-semibold text-right">–</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-400">Cloud-Ordner:</dt><dd id="exifFolder" class="font-semibold text-right truncate">–</dd></div>
            </dl>
        </aside>
    </div>

    <!-- Untere Statuszeile -->
    <div class="px-4 sm:px-6 py-2.5 bg-black/40 border-t border-white/10 text-xs text-slate-300 flex items-center justify-between">
        <span id="lbCounter">1 / 1</span>
        <span class="hidden sm:inline text-slate-400">Tastatur: ← / → Blättern · Leertaste Diashow · Esc Schließen</span>
    </div>
</div>

<?php if (!$isGuestMode): ?>
<!-- Modal: Neues Foto-Album anlegen -->
<div id="modalCreateAlbum" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">➕ Neues Foto-Album erstellen</h3>
            <button type="button" onclick="document.getElementById('modalCreateAlbum').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_album">
            <input type="hidden" name="album_id" value="0">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Album-Titel *</label>
                <input type="text" name="title" required placeholder="z. B. Sommerfest 2026, Vereinsausflug, Familienurlaub…"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Datum / Ereignis</label>
                <input type="date" name="event_date" value="<?= date('Y-m-d') ?>"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                <input type="hidden" name="group_id" value="0">
            </div>

            <?php if (!empty($shareableUsers)): ?>
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Direkt mit einzelnem Mitglied teilen</label>
                <select name="shared_user_id" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                    <option value="0">— Keine Einzelperson —</option>
                    <?php foreach ($shareableUsers as $su): ?>
                        <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung (optional)</label>
                <textarea name="description" rows="2" placeholder="Kurze Beschreibung zum Anlass…"
                          class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"></textarea>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                <label class="flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                    <input type="checkbox" name="enable_public_link" value="1" checked class="rounded text-brand-600">
                    🔗 Öffentlichen Gäste-Link & QR-Code aktivieren
                </label>
                <label class="flex items-center gap-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-300 cursor-pointer">
                    <input type="checkbox" name="allow_guest_upload" value="1" class="rounded text-emerald-600">
                    📸 Gäste dürfen eigene Fotos vom Handy in dieses Sammelalbum hochladen
                </label>
                <div>
                    <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Optionales Passwort für Gäste:</label>
                    <input type="password" name="album_password" placeholder="Leer lassen für direkten Zugriff per QR-Code"
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalCreateAlbum').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                    💾 Album anlegen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activeAlbum && canEditGalleryAlbum($pdo, $activeAlbum, $userId, $albumToken)): ?>
<!-- Modal: Fotos direkt vom Handy/PC in das Album hochladen -->
<div id="modalUploadToAlbum" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">📤 Fotos in „<?= e($activeAlbum['title']) ?>“ hochladen</h3>
            <button type="button" onclick="document.getElementById('modalUploadToAlbum').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload_album_photos">
            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">
            <input type="hidden" name="album_token" value="<?= e($albumToken) ?>">

            <?php if ($isGuestMode): ?>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dein Name (wird unter dem Foto angezeigt) *</label>
                    <input type="text" name="uploader_name" required placeholder="z. B. Max / Familie Weber"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Bilddateien auswählen (Mehrfachauswahl möglich) *</label>
                <input type="file" name="photos[]" multiple required accept="image/jpeg,image/png,image/webp,image/gif"
                       class="w-full text-xs sm:text-sm file:mr-3 file:py-2 file:px-3.5 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Gemeinsame Bildunterschrift (optional)</label>
                <input type="text" name="default_caption" placeholder="z. B. Gruppenfoto am Grillstand"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalUploadToAlbum').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                    📤 Jetzt hochladen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activeAlbum && !$isGuestMode && canEditGalleryAlbum($pdo, $activeAlbum, $userId, '')): ?>
<!-- Modal: Vorhandene Cloud-Bilder dem Album zuordnen -->
<div id="modalPickCloudPhotos" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-2xl p-6 space-y-4 max-h-[88vh] flex flex-col">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">🗂️ Vorhandene Cloud-Bilder hinzufügen</h3>
            <button type="button" onclick="document.getElementById('modalPickCloudPhotos').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Wähle Bilder aus deiner Cloud aus. Sie werden dem Album virtuell zugeordnet, ohne zusätzlichen Speicherplatz zu verbrauchen.
        </p>
        <form method="post" class="flex-1 flex flex-col min-h-0 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_existing_to_album">
            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">

            <div class="flex-1 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 gap-2.5 pr-1">
                <?php foreach ($allCloudPhotos as $cp): ?>
                    <label class="flex items-center gap-2 p-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60 cursor-pointer text-xs">
                        <input type="checkbox" name="file_ids[]" value="<?= (int)$cp['id'] ?>" class="rounded text-brand-600 shrink-0">
                        <img src="gallery.php?stream_file=<?= (int)$cp['id'] ?>" alt="" loading="lazy" class="w-10 h-10 rounded-lg object-cover shrink-0">
                        <span class="truncate font-medium text-slate-800 dark:text-slate-200"><?= e((string)$cp['original_name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('modalPickCloudPhotos').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                    ➕ Ausgewählte hinzufügen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activeAlbum && !empty($activeAlbum['share_token'])):
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $publicAlbumUrl = $scheme . '://' . $host . ($base !== '' ? $base : '') . '/gallery.php?album_token=' . urlencode((string)$activeAlbum['share_token']);
?>
<!-- Modal: Öffentlicher Album-Link & lokaler SVG-QR-Code -->
<div id="modalAlbumQr" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 text-center space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">📱 Album teilen & QR-Code</h3>
            <button type="button" onclick="document.getElementById('modalAlbumQr').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
            Scanne den QR-Code mit dem Smartphone, um das Album <strong><?= e($activeAlbum['title']) ?></strong> direkt zu öffnen<?= !empty($activeAlbum['allow_guest_upload']) ? ' und eigene Party-/Vereinsfotos beizusteuern' : '' ?>.
        </p>
        <div class="flex justify-center py-2">
            <?= renderQrCodeSvg($publicAlbumUrl, 200) ?>
        </div>
        <div class="flex items-center gap-2">
            <input type="text" id="publicAlbumUrlInput" readonly value="<?= e($publicAlbumUrl) ?>"
                   class="flex-1 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs text-slate-700 dark:text-slate-200">
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('publicAlbumUrlInput').value); this.textContent='✓ Kopiert';"
                    class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shrink-0">
                📋 Kopieren
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeAlbum && !$isGuestMode && (int)$activeAlbum['user_id'] === $userId): ?>
<!-- Modal: Album-Einstellungen bearbeiten / löschen -->
<div id="modalEditAlbum" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">⚙️ Album-Einstellungen</h3>
            <button type="button" onclick="document.getElementById('modalEditAlbum').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_album">
            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Album-Titel *</label>
                <input type="text" name="title" required value="<?= e((string)$activeAlbum['title']) ?>"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Datum</label>
                <input type="date" name="event_date" value="<?= e((string)($activeAlbum['event_date'] ?? '')) ?>"
                       class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                <input type="hidden" name="group_id" value="0">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung</label>
                <textarea name="description" rows="2"
                          class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"><?= e((string)($activeAlbum['description'] ?? '')) ?></textarea>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                <label class="flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                    <input type="checkbox" name="enable_public_link" value="1" <?= !empty($activeAlbum['share_token']) ? 'checked' : '' ?> class="rounded text-brand-600">
                    🔗 Öffentlichen Gäste-Link & QR-Code aktivieren
                </label>
                <label class="flex items-center gap-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-300 cursor-pointer">
                    <input type="checkbox" name="allow_guest_upload" value="1" <?= !empty($activeAlbum['allow_guest_upload']) ? 'checked' : '' ?> class="rounded text-emerald-600">
                    📸 Gäste-Foto-Upload („Party-/Vereins-Sammelalbum“) erlauben
                </label>
                <div>
                    <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Neues Passwort setzen (optional):</label>
                    <input type="password" name="album_password" placeholder="<?= !empty($activeAlbum['password_hash']) ? 'Passwort ist aktiv (leer lassen zum Behalten)' : 'Kein Passwort gesetzt' ?>"
                           class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                </div>
                <?php if (!empty($activeAlbum['password_hash'])): ?>
                    <label class="flex items-center gap-2 text-xs text-red-600 dark:text-red-400 cursor-pointer">
                        <input type="checkbox" name="clear_password" value="1"> Passwortschutz entfernen
                    </label>
                <?php endif; ?>
            </div>

            <div class="flex items-center justify-between pt-2">
                <button type="submit" form="formDeleteAlbum"
                        class="px-3.5 py-2 rounded-xl bg-red-50 dark:bg-red-950/50 text-red-600 dark:text-red-400 text-xs font-semibold hover:bg-red-100">
                    🗑️ Album löschen
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('modalEditAlbum').classList.add('hidden')"
                            class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold">
                        Abbrechen
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                        💾 Speichern
                    </button>
                </div>
            </div>
        </form>
        <form id="formDeleteAlbum" method="post" onsubmit="return confirm('Foto-Album wirklich löschen? Alle Originalbilder bleiben in deiner Cloud erhalten.');">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="delete_album">
            <input type="hidden" name="album_id" value="<?= (int)$activeAlbum['id'] ?>">
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const GALLERY_ITEMS = <?= json_encode(array_map(static function (array $p) use ($albumToken, $activeAlbum): array {
    $fid = (int)$p['id'];
    $tokParam = $albumToken !== '' ? '&album_token=' . urlencode($albumToken) : '';
    $retAlbum = $activeAlbum ? '&return_album=' . (int)$activeAlbum['id'] : '';
    return [
        'id'       => $fid,
        'title'    => (trim((string)($p['caption'] ?? '')) !== '') ? (string)$p['caption'] : (string)$p['original_name'],
        'origName' => (string)$p['original_name'],
        'date'     => date('d.m.Y H:i', strtotime((string)$p['upload_date'])),
        'by'       => (string)($p['added_by_name'] ?? ($p['owner_name'] ?? '')),
        'url'      => 'gallery.php?stream_file=' . $fid . $tokParam,
        'dlUrl'    => 'gallery.php?stream_file=' . $fid . '&download=1' . $tokParam,
        'exifUrl'  => 'gallery.php?exif_file=' . $fid . $tokParam,
        'editUrl'  => 'office.php?id=' . $fid . '&return_gallery=1' . $retAlbum,
    ];
}, $displayedPhotos), JSON_UNESCAPED_UNICODE) ?>;

let lbIndex = 0;
let lbTimer = null;
let lbZoomed = false;

function openLightbox(index, startSlideshow = false) {
    if (!GALLERY_ITEMS.length) return;
    lbIndex = index;
    document.getElementById('galleryLightbox').classList.remove('hidden');
    renderLightboxSlide();
    if (startSlideshow) {
        startLightboxSlideshow();
    }
}

function closeLightbox() {
    stopLightboxSlideshow();
    document.getElementById('galleryLightbox').classList.add('hidden');
}

function stepLightbox(delta) {
    if (!GALLERY_ITEMS.length) return;
    lbIndex = (lbIndex + delta + GALLERY_ITEMS.length) % GALLERY_ITEMS.length;
    renderLightboxSlide();
}

function renderLightboxSlide() {
    const item = GALLERY_ITEMS[lbIndex];
    if (!item) return;
    lbZoomed = false;
    const imgEl = document.getElementById('lbImage');
    imgEl.style.transform = 'scale(1)';
    imgEl.src = item.url;
    document.getElementById('lbTitle').textContent = item.title;
    document.getElementById('lbSubtitle').textContent = item.date + (item.by ? ' · ' + item.by : '');
    document.getElementById('lbCounter').textContent = (lbIndex + 1) + ' / ' + GALLERY_ITEMS.length;
    document.getElementById('lbBtnDownload').href = item.dlUrl;
    const editBtn = document.getElementById('lbBtnEdit');
    if (editBtn && item.editUrl) {
        editBtn.href = item.editUrl;
    }

    if (!document.getElementById('lbExifDrawer').classList.contains('hidden')) {
        loadExifForCurrent();
    }
}

function toggleLightboxZoom() {
    lbZoomed = !lbZoomed;
    const imgEl = document.getElementById('lbImage');
    imgEl.style.transform = lbZoomed ? 'scale(1.65)' : 'scale(1)';
}

function toggleSlideshow() {
    if (lbTimer) {
        stopLightboxSlideshow();
    } else {
        startLightboxSlideshow();
    }
}

function startLightboxSlideshow() {
    stopLightboxSlideshow();
    const interval = parseInt(document.getElementById('lbSlideshowInterval').value || '5000', 10);
    lbTimer = setInterval(() => stepLightbox(1), interval);
    const btn = document.getElementById('lbBtnPlay');
    btn.textContent = '⏸️ Pause';
    btn.classList.replace('bg-emerald-600', 'bg-amber-600');
}

function stopLightboxSlideshow() {
    if (lbTimer) {
        clearInterval(lbTimer);
        lbTimer = null;
    }
    const btn = document.getElementById('lbBtnPlay');
    if (btn) {
        btn.textContent = '▶️ Diashow';
        btn.classList.replace('bg-amber-600', 'bg-emerald-600');
    }
}

function restartSlideshowIfActive() {
    if (lbTimer) {
        startLightboxSlideshow();
    }
}

function toggleExifDrawer() {
    const drawer = document.getElementById('lbExifDrawer');
    drawer.classList.toggle('hidden');
    if (!drawer.classList.contains('hidden')) {
        loadExifForCurrent();
    }
}

async function loadExifForCurrent() {
    const item = GALLERY_ITEMS[lbIndex];
    if (!item) return;
    try {
        const res = await fetch(item.exifUrl);
        const data = await res.json();
        if (!data.ok) return;
        document.getElementById('exifName').textContent = data.name || '–';
        document.getElementById('exifDim').textContent = data.dimensions || '–';
        document.getElementById('exifSize').textContent = data.size || '–';
        document.getElementById('exifTaken').textContent = data.taken_at || '–';
        document.getElementById('exifCam').textContent = data.camera || '–';
        document.getElementById('exifAperture').textContent = data.aperture || '–';
        document.getElementById('exifExposure').textContent = data.exposure || '–';
        document.getElementById('exifIso').textContent = data.iso || '–';
        document.getElementById('exifFocal').textContent = data.focal || '–';
        document.getElementById('exifFolder').textContent = data.folder || '/';
    } catch (e) {}
}

document.addEventListener('keydown', (e) => {
    const lb = document.getElementById('galleryLightbox');
    if (!lb || lb.classList.contains('hidden')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowRight') stepLightbox(1);
    if (e.key === 'ArrowLeft') stepLightbox(-1);
    if (e.key === ' ') {
        e.preventDefault();
        toggleSlideshow();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
