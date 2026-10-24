<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Öffentliche Selbstregistrierung ist deaktiviert.
 * Benutzerkonten werden ausschließlich durch den Administrator in admin.php angelegt.
 */
if (isAdmin()) {
    header('Location: admin.php');
    exit;
}

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

setFlash('warning', 'Eine Selbstregistrierung ist nicht möglich. Benutzerkonten werden durch den Administrator angelegt.');
header('Location: login.php');
exit;
