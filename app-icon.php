<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$size = isset($_GET['size']) ? (int)$_GET['size'] : 192;
if (!in_array($size, [180, 192, 512], true)) {
    $size = max(64, min(512, $size));
}
$maskable = isset($_GET['maskable']) && (string)$_GET['maskable'] === '1';

$settings = getAppSettings();
$logoDataUri = trim((string)($settings['logo_data_uri'] ?? ''));

$etag = '"' . substr(hash('sha256', $logoDataUri . '|' . $size . '|' . ($maskable ? '1' : '0')), 0, 16) . '"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

/**
 * Dekodiert ein Data-URI-Logo in Binärdaten und MIME-Typ.
 *
 * @return array{mime: string, data: string}|null
 */
function decodeLogoDataUri(string $dataUri): ?array
{
    if ($dataUri === '' || !str_starts_with($dataUri, 'data:image/')) {
        return null;
    }
    $parts = explode(',', $dataUri, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $meta = $parts[0];
    $payload = $parts[1];

    if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+)(;base64)?$#i', $meta, $m)) {
        return null;
    }
    $mime = strtolower($m[1]);
    $isBase64 = isset($m[2]) && strtolower($m[2]) === ';base64';

    $raw = $isBase64 ? base64_decode($payload, true) : rawurldecode($payload);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    return ['mime' => $mime, 'data' => $raw];
}

$decoded = decodeLogoDataUri($logoDataUri);

// Ziel-Canvas mit Alpha-Kanal erstellen
$canvas = imagecreatetruecolor($size, $size);
imagealphablending($canvas, false);
imagesavealpha($canvas, true);

if ($maskable || $size === 180) {
    // Für Maskable-Icons (Android) und Apple Touch Icons (iOS mag keine Transparenz) dunkler edler Hintergrund
    $bg = imagecolorallocatealpha($canvas, 15, 23, 42, 0); // #0f172a (Slate-900)
} else {
    // Transparenter Hintergrund für Standard-Icons
    $bg = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
}
imagefill($canvas, 0, 0, $bg);
imagealphablending($canvas, true);

$renderedCustom = false;

if ($decoded !== null) {
    $srcImg = false;

    if ($decoded['mime'] === 'image/svg+xml' && class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->readImageBlob($decoded['data']);
            $im->setImageFormat('png32');
            $pngBlob = $im->getImageBlob();
            $im->clear();
            $srcImg = @imagecreatefromstring($pngBlob);
        } catch (Throwable) {
            $srcImg = false;
        }
    } else {
        $srcImg = @imagecreatefromstring($decoded['data']);
    }

    if ($srcImg !== false) {
        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);

        if ($srcW > 0 && $srcH > 0) {
            $padRatio = $maskable ? 0.14 : ($size === 180 ? 0.08 : 0.04);
            $pad = (int)round($size * $padRatio);
            $avail = max(1, $size - ($pad * 2));

            $scale = min($avail / $srcW, $avail / $srcH);
            $dstW = max(1, (int)round($srcW * $scale));
            $dstH = max(1, (int)round($srcH * $scale));
            $dstX = (int)floor(($size - $dstW) / 2);
            $dstY = (int)floor(($size - $dstH) / 2);

            imagecopyresampled($canvas, $srcImg, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
            $renderedCustom = true;
        }
        imagedestroy($srcImg);
    }
}

if (!$renderedCustom) {
    // Fallback: Blaues Cloud-App-Icon zeichnen
    $blue = imagecolorallocate($canvas, 37, 99, 235); // #2563eb
    $white = imagecolorallocate($canvas, 255, 255, 255);

    $margin = $maskable ? 0 : (int)round($size * 0.06);
    imagefilledrectangle($canvas, $margin, $margin, $size - 1 - $margin, $size - 1 - $margin, $blue);

    // Stilisierte weiße Wolke in der Mitte
    $cx = (int)round($size * 0.50);
    $cy = (int)round($size * 0.54);
    $r1 = (int)round($size * 0.22);
    $r2 = (int)round($size * 0.28);
    $r3 = (int)round($size * 0.20);

    imagefilledellipse($canvas, (int)round($cx - $size * 0.14), $cy, $r1, $r1, $white);
    imagefilledellipse($canvas, $cx, (int)round($cy - $size * 0.07), $r2, $r2, $white);
    imagefilledellipse($canvas, (int)round($cx + $size * 0.15), (int)round($cy + $size * 0.01), $r3, $r3, $white);
    imagefilledrectangle(
        $canvas,
        (int)round($cx - $size * 0.14),
        (int)round($cy - $size * 0.02),
        (int)round($cx + $size * 0.15),
        (int)round($cy + $size * 0.11),
        $white
    );
}

imagepng($canvas, null, 6);
imagedestroy($canvas);
