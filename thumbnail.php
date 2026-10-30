<?php
declare(strict_types=1);

/**
 * MiniCloud – Serverseitiger Thumbnail-Generator & Cache (thumbnail.php)
 * Erzeugt komprimierte Vorschaubilder (WebP / JPEG, Standard: max. 360px) für Fotos in der Kachelansicht,
 * im Vorschaumodal und in öffentlichen Ordner-Freigaben, um hunderte Megabyte Originalbilder-Traffic zu sparen.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$fileId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$maxDim     = isset($_GET['size']) ? max(120, min(800, (int)$_GET['size'])) : 360;
$shareToken = isset($_GET['share_token']) && is_string($_GET['share_token']) ? trim($_GET['share_token']) : '';

if ($fileId <= 0) {
    http_response_code(400);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

if (!$file || (int)($file['is_encrypted'] ?? 0) === 1) {
    http_response_code(404);
    exit;
}

$hasAccess = false;

// A) Zugriff über öffentlichen Freigabelink (share.php)
if ($shareToken !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $shareToken)) {
    $stmtShare = $pdo->prepare(
        'SELECT id, file_id, folder_path, user_id, share_mode, password_hash, expires_at, max_downloads, download_count
         FROM shares
         WHERE share_token = :token
         LIMIT 1'
    );
    $stmtShare->execute([':token' => $shareToken]);
    $shareRow = $stmtShare->fetch();

    if ($shareRow) {
        $notExpired = ($shareRow['expires_at'] === null || strtotime((string)$shareRow['expires_at']) >= time());
        $notMaxed   = ($shareRow['max_downloads'] === null || (int)$shareRow['download_count'] < (int)$shareRow['max_downloads']);
        $unlocked   = empty($shareRow['password_hash']) || !empty($_SESSION['unlocked_shares'][$shareToken]);
        $canRead    = ((string)($shareRow['share_mode'] ?? 'read') !== 'upload_only');

        if ($notExpired && $notMaxed && $unlocked && $canRead) {
            if (!empty($shareRow['file_id']) && (int)$shareRow['file_id'] === $fileId) {
                $hasAccess = true;
            } elseif (!empty($shareRow['folder_path'])) {
                $sfp = (string)$shareRow['folder_path'];
                $ffp = (string)$file['folder_path'];
                if ((int)$file['user_id'] === (int)$shareRow['user_id']
                    && $file['group_folder_id'] === null
                    && ($ffp === $sfp || str_starts_with($ffp, $sfp . '/'))
                ) {
                    $hasAccess = true;
                }
            }
        }
    }
}

// B) Zugriff als eingeloggter Benutzer
if (!$hasAccess) {
    if (!isLoggedIn()) {
        http_response_code(403);
        exit;
    }
    $userId  = (int)$_SESSION['user_id'];
    $isAdmin = isAdmin();

    if ((int)$file['user_id'] === $userId && $file['group_folder_id'] === null) {
        $hasAccess = true;
    } elseif ($file['group_folder_id'] !== null && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], false, $isAdmin)) {
        $hasAccess = true;
    } else {
        // Direkte Einzeldatei-Freigabe prüfen
        $stmtUs = $pdo->prepare('SELECT id FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :uid LIMIT 1');
        $stmtUs->execute([':fid' => $fileId, ':uid' => $userId]);
        if ($stmtUs->fetch()) {
            $hasAccess = true;
        } elseif ($file['group_folder_id'] === null && (string)$file['folder_path'] !== '') {
            // Ordner-Freigabe prüfen
            $stmtUf = $pdo->prepare(
                'SELECT folder_path FROM user_shares
                 WHERE owner_id = :owner_id AND shared_with_user_id = :uid AND folder_path IS NOT NULL'
            );
            $stmtUf->execute([':owner_id' => (int)$file['user_id'], ':uid' => $userId]);
            $ffp = (string)$file['folder_path'];
            foreach ($stmtUf->fetchAll(PDO::FETCH_COLUMN) as $sharedFp) {
                $sfp = (string)$sharedFp;
                if ($ffp === $sfp || str_starts_with($ffp, $sfp . '/')) {
                    $hasAccess = true;
                    break;
                }
            }
        }
    }
}

if (!$hasAccess) {
    http_response_code(403);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$physicalPath = UPLOAD_DIR . basename((string)$file['filename']);
if (!is_file($physicalPath) || !is_readable($physicalPath)) {
    http_response_code(404);
    exit;
}

$mtime = (int)(@filemtime($physicalPath) ?: 0);
$etag  = sprintf('"mc-thumb-%d-%d-%d"', $fileId, $mtime, $maxDim);

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
}

$supportsWebp = function_exists('imagewebp');
$thumbExt     = $supportsWebp ? 'webp' : 'jpg';
$thumbMime    = $supportsWebp ? 'image/webp' : 'image/jpeg';
$thumbPath    = sprintf('%sthumb_%d_%d_%d.%s', $thumbDir, $fileId, $mtime, $maxDim, $thumbExt);

// Falls Thumbnail bereits im Cache liegt -> sofort ausliefern
if (is_file($thumbPath) && is_readable($thumbPath)) {
    header('Content-Type: ' . $thumbMime);
    header('Content-Length: ' . (string)filesize($thumbPath));
    readfile($thumbPath);
    exit;
}

$ext  = strtolower((string)pathinfo((string)$file['original_name'], PATHINFO_EXTENSION));
$mime = strtolower((string)$file['mime_type']);

// Bei sehr großen Bilddateien (> 35 MB) oder fehlender GD-Extension direkt Original ausliefern, um RAM-Spitzen zu vermeiden
$fileSize = (int)filesize($physicalPath);
$canUseGd = extension_loaded('gd') && function_exists('imagecreatetruecolor') && $fileSize > 0 && $fileSize <= 35 * 1048576;

if ($canUseGd) {
    $imgInfo = @getimagesize($physicalPath);
    if (is_array($imgInfo) && isset($imgInfo[0], $imgInfo[1], $imgInfo[2])) {
        $srcW = (int)$imgInfo[0];
        $srcH = (int)$imgInfo[1];
        $type = (int)$imgInfo[2];

        // Nur skalieren, wenn das Bild nicht ohnehin schon klein ist und die Pixelanzahl im sicheren Rahmen liegt (< 36 MP)
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
                // EXIF-Drehung bei Smartphone-JPEGs automatisch korrigieren
                if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                    $exif = @exif_read_data($physicalPath);
                    $orientation = (int)($exif['Orientation'] ?? 1);
                    if ($orientation === 3) {
                        $rotated = @imagerotate($srcImg, 180, 0);
                        if ($rotated !== false) {
                            imagedestroy($srcImg);
                            $srcImg = $rotated;
                        }
                    } elseif ($orientation === 6) {
                        $rotated = @imagerotate($srcImg, -90, 0);
                        if ($rotated !== false) {
                            imagedestroy($srcImg);
                            $srcImg = $rotated;
                            [$srcW, $srcH] = [$srcH, $srcW];
                        }
                    } elseif ($orientation === 8) {
                        $rotated = @imagerotate($srcImg, 90, 0);
                        if ($rotated !== false) {
                            imagedestroy($srcImg);
                            $srcImg = $rotated;
                            [$srcW, $srcH] = [$srcH, $srcW];
                        }
                    }
                }

                $scale = min(1.0, $maxDim / max($srcW, $srcH));
                $dstW  = max(1, (int)round($srcW * $scale));
                $dstH  = max(1, (int)round($srcH * $scale));

                $dstImg = imagecreatetruecolor($dstW, $dstH);
                if ($dstImg !== false) {
                    // Transparenz für PNG/WebP/GIF erhalten
                    imagealphablending($dstImg, false);
                    imagesavealpha($dstImg, true);
                    $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
                    imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, (int)$transparent);

                    if (imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
                        $saved = false;
                        if ($supportsWebp) {
                            $saved = @imagewebp($dstImg, $thumbPath, 82);
                        } else {
                            // Für JPEG weißen Hintergrund unterlegen
                            $jpgBg = imagecreatetruecolor($dstW, $dstH);
                            if ($jpgBg !== false) {
                                $white = imagecolorallocate($jpgBg, 255, 255, 255);
                                imagefilledrectangle($jpgBg, 0, 0, $dstW, $dstH, (int)$white);
                                imagecopy($jpgBg, $dstImg, 0, 0, 0, 0, $dstW, $dstH);
                                $saved = @imagejpeg($jpgBg, $thumbPath, 84);
                                imagedestroy($jpgBg);
                            }
                        }
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

// Fallback: Originalbild sicher ausliefern (z.B. SVG oder wenn GD das Format nicht unterstützt)
$safeMime = str_starts_with($mime, 'image/') ? $mime : 'application/octet-stream';
if ($ext === 'svg' || $safeMime === 'image/svg+xml') {
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    $safeMime = 'image/svg+xml';
}
header('Content-Type: ' . $safeMime);
header('Content-Length: ' . (string)$fileSize);
readfile($physicalPath);
exit;
