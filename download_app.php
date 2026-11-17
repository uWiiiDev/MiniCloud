<?php
declare(strict_types=1);

/**
 * MiniCloud - Direkter APK-Download für die native Android-App
 */

$apkPath = __DIR__ . '/minicloud-files.apk';
if (!is_file($apkPath) || !is_readable($apkPath)) {
    http_response_code(404);
    exit('Die Android-App (APK) wurde auf dem Server noch nicht kompiliert oder bereitgestellt.');
}

$fileSize = (int)filesize($apkPath);
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="MiniCloud-Files.apk"');
header('Content-Length: ' . (string)$fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($apkPath);
exit;
