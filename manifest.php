<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$settings = getAppSettings();
$appTitle = trim((string)($settings['app_title'] ?? APP_NAME)) ?: APP_NAME;
$appSubtitle = trim((string)($settings['app_subtitle'] ?? 'Private Cloud')) ?: 'Private Cloud';
$logoHash = substr(hash('sha256', (string)($settings['logo_data_uri'] ?? '')), 0, 10);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$manifest = [
    'id'               => './index.php',
    'name'             => $appTitle . ' – ' . $appSubtitle,
    'short_name'       => mb_substr($appTitle, 0, 20),
    'description'      => $appTitle . ' – Sicherer privater Cloud-Speicher',
    'lang'             => 'de-DE',
    'dir'              => 'ltr',
    'start_url'        => './index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#0f172a',
    'theme_color'      => '#2563eb',
    'categories'       => ['productivity', 'utilities'],
    'icons'            => [
        [
            'src'     => 'app-icon.php?size=192&v=' . $logoHash,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'app-icon.php?size=512&v=' . $logoHash,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'app-icon.php?size=192&maskable=1&v=' . $logoHash,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src'     => 'app-icon.php?size=512&maskable=1&v=' . $logoHash,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
