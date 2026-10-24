<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$allowed = array_keys(getAllowedLanguages());
$lang = isset($_POST['lang']) && is_string($_POST['lang'])
    ? strtolower(trim($_POST['lang']))
    : (isset($_GET['lang']) && is_string($_GET['lang']) ? strtolower(trim($_GET['lang'])) : 'de');

if (!in_array($lang, $allowed, true)) {
    $lang = 'de';
}

setcookie('mc_lang', $lang, [
    'expires'  => time() + 86400 * 365,
    'path'     => '/',
    'samesite' => 'Lax',
]);

$_SESSION['lang'] = $lang;

$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$safeRedirect = 'index.php';
if ($referer !== '') {
    $parsed = parse_url($referer);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if (is_array($parsed) && (!isset($parsed['host']) || strcasecmp((string)$parsed['host'], $currentHost) === 0)) {
        $path = basename((string)($parsed['path'] ?? 'index.php'));
        if (preg_match('/^[a-zA-Z0-9_-]+\.php$/', $path)) {
            $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
            $safeRedirect = $path . $query;
        }
    }
}

header('Location: ' . $safeRedirect);
exit;
