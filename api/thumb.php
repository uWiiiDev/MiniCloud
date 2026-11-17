<?php
declare(strict_types=1);

/**
 * MiniCloud REST API - Thumbnail Generator & Cache für Android-App
 */

require_once __DIR__ . '/common.php';

$pdo = getDB();
$user = requireApiAuth($pdo);
$userId = (int)$user['id'];

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$maxDim = isset($_GET['size']) ? max(60, min(1080, (int)$_GET['size'])) : 360;

if ($fileId <= 0) {
    http_response_code(400);
    exit('Ungültige Datei-ID.');
}

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

if (!$file || (int)($file['is_encrypted'] ?? 0) === 1) {
    http_response_code(404);
    exit;
}

// Zugriff prüfen
$hasAccess = false;
if ((int)$file['user_id'] === $userId && $file['group_folder_id'] === null) {
    $hasAccess = true;
} elseif ($file['group_folder_id'] !== null && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], false, (bool)$user['is_admin'])) {
    $hasAccess = true;
} else {
    $stmtUs = $pdo->prepare('SELECT id FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :uid LIMIT 1');
    $stmtUs->execute([':fid' => $fileId, ':uid' => $userId]);
    if ($stmtUs->fetch()) {
        $hasAccess = true;
    }
}

if (!$hasAccess) {
    http_response_code(403);
    exit;
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

// Aus Cache ausliefern
if (is_file($thumbPath) && is_readable($thumbPath)) {
    header('Content-Type: ' . $thumbMime);
    header('Content-Length: ' . (string)filesize($thumbPath));
    readfile($thumbPath);
    exit;
}

$fileSize = (int)filesize($physicalPath);
$canUseGd = extension_loaded('gd') && function_exists('imagecreatetruecolor') && $fileSize > 0 && $fileSize <= 35 * 1048576;

if ($canUseGd) {
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
                // EXIF-Drehung bei Smartphone-JPEGs berücksichtigen
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
                    imagealphablending($dstImg, false);
                    imagesavealpha($dstImg, true);
                    $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
                    imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, (int)$transparent);

                    if (imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
                        $saved = false;
                        if ($supportsWebp) {
                            $saved = @imagewebp($dstImg, $thumbPath, 82);
                        } else {
                            $jpgBg = imagecreatetruecolor($dstW, $dstH);
                            if ($jpgBg !== false) {
                                $white = imagecolorallocate($jpgBg, 255, 255, 255);
                                imagefilledrectangle($jpgBg, 0, 0, $dstW, $dstH, (int)$white);
                                imagecopy($jpgBg, $dstImg, 0, 0, 0, 0, $dstW, $dstH);
                                $saved = @imagejpeg($jpgBg, $thumbPath, 84);
                                imagedestroy($jpgBg);
                            }
                        }

                        if ($saved && is_file($thumbPath)) {
                            imagedestroy($dstImg);
                            imagedestroy($srcImg);
                            header('Content-Type: ' . $thumbMime);
                            header('Content-Length: ' . (string)filesize($thumbPath));
                            readfile($thumbPath);
                            exit;
                        }
                    }
                    imagedestroy($dstImg);
                }
                imagedestroy($srcImg);
            }
        }
    }
}

// Fallback: Original ausliefern
header('Content-Type: ' . ($file['mime_type'] ?: 'image/jpeg'));
header('Content-Length: ' . (string)$fileSize);
readfile($physicalPath);
exit;
