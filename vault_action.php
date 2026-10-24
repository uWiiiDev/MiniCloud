<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$userId            = (int)$_SESSION['user_id'];
$isAdmin           = isAdmin();
$pdo               = getDB();
$action            = trim((string)($_POST['action'] ?? ''));
$fileId            = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
$returnFolder      = isset($_POST['return_folder']) ? sanitizeFolderPathInput((string)$_POST['return_folder']) : '';
$returnGroupFolder = isset($_POST['return_group_folder']) ? (int)$_POST['return_group_folder'] : 0;

$redirectParams = [];
if ($returnGroupFolder > 0) {
    $redirectParams['group_folder'] = (string)$returnGroupFolder;
}
if ($returnFolder !== '') {
    $redirectParams['folder'] = $returnFolder;
}
$redirectUrl = 'index.php' . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : '');

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($fileId <= 0) {
    setFlash('error', 'Ungültige Datei-ID.');
    header('Location: ' . $redirectUrl);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

if (!$file) {
    setFlash('error', 'Die ausgewählte Datei wurde nicht gefunden.');
    header('Location: ' . $redirectUrl);
    exit;
}

// Berechtigung prüfen: Eigentümer der Datei oder Schreibrecht im Gruppenordner
$gfId = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : 0;
$canManage = ((int)$file['user_id'] === $userId)
    || ($gfId > 0 && canUserAccessGroupFolder($pdo, $userId, $gfId, true, $isAdmin));

if (!$canManage) {
    setFlash('error', 'Zugriff verweigert: Sie haben keine Berechtigung, den Tresor-Schutz dieser Datei zu ändern.');
    header('Location: ' . $redirectUrl);
    exit;
}

$storedFilename = basename((string)$file['filename']);
$filePath = UPLOAD_DIR . $storedFilename;

if (!is_file($filePath) || !is_readable($filePath)) {
    setFlash('error', 'Die physische Datei wurde auf dem Server nicht gefunden.');
    header('Location: ' . $redirectUrl);
    exit;
}

// 1. DATEI IM TRESOR VERSCHLÜSSELN (AES-256-GCM)
if ($action === 'encrypt_file') {
    if ((int)($file['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($filePath)) {
        setFlash('warning', 'Diese Datei ist bereits im Tresor verschlüsselt.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    $password        = (string)($_POST['vault_password'] ?? '');
    $passwordConfirm = (string)($_POST['vault_password_confirm'] ?? '');

    if (mb_strlen($password, 'UTF-8') < 4) {
        setFlash('error', 'Das Tresor-Passwort muss mindestens 4 Zeichen lang sein.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($password !== $passwordConfirm) {
        setFlash('error', 'Die eingegebenen Tresor-Passwörter stimmen nicht überein.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!encryptFileInPlaceWithVault($filePath, $password)) {
        setFlash('error', 'Fehler beim Verschlüsseln der Datei.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    clearstatcache(true, $filePath);
    $newSize = (int)(@filesize($filePath) ?: $file['file_size']);

    $stmtUpd = $pdo->prepare('UPDATE files SET is_encrypted = 1, file_size = :sz WHERE id = :id');
    $stmtUpd->execute([
        ':sz' => $newSize,
        ':id' => $fileId,
    ]);

    setFlash('success', sprintf('Datei "%s" wurde erfolgreich mit AES-256 im Tresor verschlüsselt.', (string)$file['original_name']));
    header('Location: ' . $redirectUrl);
    exit;
}

// 2. TRESOR-SCHUTZ DAUERHAFT AUFHEBEN (ENTSCHLÜSSELN)
if ($action === 'decrypt_file') {
    if ((int)($file['is_encrypted'] ?? 0) === 0 && !isVaultEncryptedFile($filePath)) {
        setFlash('warning', 'Diese Datei ist nicht verschlüsselt.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Brute-Force-Schutz (max. 5 Fehlversuche in 5 Minuten)
    $attemptKey    = 'vault_attempts_' . $fileId;
    $lockoutWindow = 300;
    $maxAttempts   = 5;
    $failedAttempts = isset($_SESSION[$attemptKey]) && is_array($_SESSION[$attemptKey])
        ? array_values(array_filter($_SESSION[$attemptKey], static fn($ts): bool => is_int($ts) && $ts > (time() - $lockoutWindow)))
        : [];
    $_SESSION[$attemptKey] = $failedAttempts;

    if (count($failedAttempts) >= $maxAttempts) {
        $waitSecs = max(1, ($failedAttempts[0] + $lockoutWindow) - time());
        setFlash('error', sprintf('Zu viele Fehlversuche für diese Tresor-Datei. Bitte warten Sie %d Sekunden.', $waitSecs));
        header('Location: ' . $redirectUrl);
        exit;
    }

    $password = (string)($_POST['vault_password'] ?? '');
    if ($password === '') {
        setFlash('error', 'Bitte geben Sie das Tresor-Passwort ein.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!decryptFileInPlaceFromVault($filePath, $password)) {
        $_SESSION[$attemptKey][] = time();
        setFlash('error', 'Das eingegebene Tresor-Passwort ist falsch.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    unset($_SESSION[$attemptKey]);
    clearstatcache(true, $filePath);
    $newSize = (int)(@filesize($filePath) ?: $file['file_size']);

    $stmtUpd = $pdo->prepare('UPDATE files SET is_encrypted = 0, file_size = :sz WHERE id = :id');
    $stmtUpd->execute([
        ':sz' => $newSize,
        ':id' => $fileId,
    ]);

    setFlash('success', sprintf('Tresor-Schutz für "%s" wurde aufgehoben. Die Datei liegt wieder unverschlüsselt vor.', (string)$file['original_name']));
    header('Location: ' . $redirectUrl);
    exit;
}

header('Location: ' . $redirectUrl);
exit;
