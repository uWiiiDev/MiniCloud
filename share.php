<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$token = isset($_REQUEST['token']) && is_string($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
$pdo = getDB();

$share = null;
$isFolderShare = false;
$sharedFolderFiles = [];
$totalFolderSize = 0;
$errorState = null; // 'missing', 'expired', 'file_missing'

if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
    $errorState = 'missing';
} else {
    $stmt = $pdo->prepare(
        'SELECT s.id AS share_id, s.file_id, s.folder_path AS shared_folder_path, s.user_id,
                s.share_token, s.password_hash, s.expires_at, s.created_at AS share_date,
                f.filename, f.original_name, f.file_size, f.mime_type, f.is_encrypted, f.upload_date, f.deleted_at AS file_deleted_at,
                u.username AS owner_username
         FROM shares s
         LEFT JOIN files f ON f.id = s.file_id
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.share_token = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $share = $stmt->fetch();

    if (!$share) {
        $errorState = 'missing';
    } elseif ($share['expires_at'] !== null && strtotime((string)$share['expires_at']) < time()) {
        $errorState = 'expired';
    } elseif (!empty($share['shared_folder_path'])) {
        $isFolderShare = true;
        $folderPath = (string)$share['shared_folder_path'];
        $ownerId = (int)$share['user_id'];

        $stmtFiles = $pdo->prepare(
            'SELECT id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date
             FROM files
             WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL
               AND (folder_path = :fp_exact OR folder_path LIKE :fp_prefix)
             ORDER BY folder_path ASC, original_name ASC'
        );
        $stmtFiles->execute([
            ':uid'       => $ownerId,
            ':fp_exact'  => $folderPath,
            ':fp_prefix' => $folderPath . '/%',
        ]);
        $sharedFolderFiles = $stmtFiles->fetchAll();

        foreach ($sharedFolderFiles as $sfRow) {
            $totalFolderSize += (int)$sfRow['file_size'];
        }

        if (empty($sharedFolderFiles)) {
            $stmtExplicit = $pdo->prepare(
                'SELECT id FROM user_folders
                 WHERE user_id = :uid AND group_folder_id IS NULL
                   AND (folder_path = :fp_exact OR folder_path LIKE :fp_prefix)
                 LIMIT 1'
            );
            $stmtExplicit->execute([
                ':uid'       => $ownerId,
                ':fp_exact'  => $folderPath,
                ':fp_prefix' => $folderPath . '/%',
            ]);
            if (!$stmtExplicit->fetch()) {
                $errorState = 'missing';
            }
        }
    } else {
        // Einzeldatei-Freigabe
        if (empty($share['file_id']) || $share['file_deleted_at'] !== null || empty($share['filename'])) {
            $errorState = 'missing';
        } else {
            $physicalPath = UPLOAD_DIR . basename((string)$share['filename']);
            if (!is_file($physicalPath) || !is_readable($physicalPath)) {
                $errorState = 'file_missing';
            }
        }
    }
}

$requiresPassword = $share !== null && $errorState === null && !empty($share['password_hash']);
$isUnlocked = !$requiresPassword || !empty($_SESSION['unlocked_shares'][$token]);
$passwordError = '';

// Passwort-Prüfung bei POST-Anfrage (inkl. Brute-Force-Schutz)
if ($requiresPassword && !$isUnlocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $inputPassword = (string)($_POST['password'] ?? '');

    $shareAttemptKey = 'share_attempts_' . $token;
    $lockoutWindow   = 300;
    $maxAttempts     = 5;
    $failedAttempts  = isset($_SESSION[$shareAttemptKey]) && is_array($_SESSION[$shareAttemptKey])
        ? array_values(array_filter($_SESSION[$shareAttemptKey], static fn($ts): bool => is_int($ts) && $ts > (time() - $lockoutWindow)))
        : [];
    $_SESSION[$shareAttemptKey] = $failedAttempts;

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        $passwordError = 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.';
    } elseif (count($failedAttempts) >= $maxAttempts) {
        $waitSecs = max(1, ($failedAttempts[0] + $lockoutWindow) - time());
        $passwordError = sprintf('Zu viele Fehlversuche. Bitte warten Sie %d Sekunden.', $waitSecs);
    } elseif ($inputPassword === '') {
        $passwordError = 'Bitte geben Sie das Passwort für diese Freigabe ein.';
    } elseif (password_verify($inputPassword, (string)$share['password_hash'])) {
        unset($_SESSION[$shareAttemptKey]);
        $_SESSION['unlocked_shares'][$token] = true;
        $isUnlocked = true;
    } else {
        $_SESSION[$shareAttemptKey][] = time();
        $passwordError = 'Das eingegebene Passwort ist falsch.';
    }
}

$vaultShareError = '';
$vaultPromptFile = null; // Falls eine Tresor-Datei in einem freigegebenen Ordner heruntergeladen werden soll

// Download-Aktionen (nur wenn entsperrt und gültig)
if ($errorState === null && $share !== null && $isUnlocked) {
    // A) Einzelne Datei aus einem freigegebenen Ordner herunterladen (?download_file=ID)
    if ($isFolderShare && isset($_REQUEST['download_file'])) {
        $reqFileId = (int)$_REQUEST['download_file'];
        $targetFile = null;
        foreach ($sharedFolderFiles as $candidate) {
            if ((int)$candidate['id'] === $reqFileId) {
                $targetFile = $candidate;
                break;
            }
        }

        if ($targetFile !== null) {
            $storedFilename = basename((string)$targetFile['filename']);
            $filePath = UPLOAD_DIR . $storedFilename;
            if (is_file($filePath) && is_readable($filePath)) {
                $originalName = sanitizeOriginalFilename((string)$targetFile['original_name']);
                $fileSize = (int)filesize($filePath);

                if ((int)($targetFile['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($filePath)) {
                    $vaultPromptFile = $targetFile;
                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vault_password'])) {
                        $csrfToken = $_POST['csrf_token'] ?? null;
                        $vaultPw = (string)$_POST['vault_password'];
                        $vInfo = null;
                        if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
                            $vaultShareError = 'Ungültiges Sicherheits-Token (CSRF).';
                        } elseif ($vaultPw === '') {
                            $vaultShareError = 'Bitte geben Sie das Tresor-Passwort ein.';
                        } elseif (!verifyVaultFilePassword($filePath, $vaultPw, $vInfo) || !is_array($vInfo)) {
                            $vaultShareError = 'Das eingegebene Tresor-Passwort ist falsch.';
                        } else {
                            if (ob_get_level()) {
                                ob_end_clean();
                            }
                            $origPlainSize = (int)$vInfo['orig_size'];
                            header('Content-Description: File Transfer');
                            header('Content-Type: application/octet-stream');
                            header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
                            if ($origPlainSize > 0) {
                                header('Content-Length: ' . (string)$origPlainSize);
                            }
                            header('X-Content-Type-Options: nosniff');
                            header('Cache-Control: private, no-transform, no-store, must-revalidate');
                            header('Pragma: no-cache');
                            streamDecryptedVaultFile($filePath, $vInfo);
                            exit;
                        }
                    }
                } else {
                    if (ob_get_level()) {
                        ob_end_clean();
                    }

                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
                    header('Content-Length: ' . (string)$fileSize);
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, no-transform, no-store, must-revalidate');
                    header('Pragma: no-cache');

                    readfile($filePath);
                    exit;
                }
            }
        }
    }

    // B) Kompletten freigegebenen Ordner als ZIP herunterladen (?download=1)
    if ($isFolderShare && isset($_GET['download']) && $_GET['download'] === '1' && !empty($sharedFolderFiles) && class_exists('ZipArchive')) {
        $folderPath = (string)$share['shared_folder_path'];
        $tmpZip = tempnam(sys_get_temp_dir(), 'mc_share_zip_');
        if ($tmpZip !== false) {
            $zip = new ZipArchive();
            if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === true) {
                $addedFiles = 0;
                $skippedVaultFiles = [];
                foreach ($sharedFolderFiles as $f) {
                    $storedName = basename((string)$f['filename']);
                    $physicalPath = UPLOAD_DIR . $storedName;
                    if (!is_file($physicalPath) || !is_readable($physicalPath)) {
                        continue;
                    }
                    $relFolder = (string)$f['folder_path'];
                    $entryName = ($relFolder !== '' ? $relFolder . '/' : '') . sanitizeOriginalFilename((string)$f['original_name']);
                    if ((int)($f['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($physicalPath)) {
                        $skippedVaultFiles[] = $entryName;
                        continue;
                    }
                    $zip->addFile($physicalPath, $entryName);
                    $addedFiles++;
                }
                if (!empty($skippedVaultFiles)) {
                    $vaultNote = "HINWEIS ZU VERSCHLÜSSELTEN TRESOR-DATEIEN\r\n"
                        . "=========================================\r\n\r\n"
                        . "Die folgenden Dateien in diesem Ordner sind mit einem persönlichen Tresor-Passwort (AES-256) geschützt:\r\n\r\n"
                        . "- " . implode("\r\n- ", $skippedVaultFiles) . "\r\n\r\n"
                        . "Bitte laden Sie diese Dateien einzeln über die Freigabeseite mit dem Tresor-Passwort herunter.\r\n";
                    $zip->addFromString('TRESOR_HINWEIS.txt', $vaultNote);
                    $addedFiles++;
                }
                $zip->close();

                if ($addedFiles > 0 && is_file($tmpZip)) {
                    $zipDownloadName = (preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($folderPath)) ?: 'ordner') . '.zip';
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . addslashes($zipDownloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipDownloadName));
                    header('Content-Length: ' . (string)filesize($tmpZip));
                    header('Cache-Control: private, no-transform, no-store, must-revalidate');
                    header('Pragma: no-cache');

                    readfile($tmpZip);
                    @unlink($tmpZip);
                    exit;
                }
            }
            @unlink($tmpZip);
        }
    }

    // C) Einzeldatei-Freigabe über PHP-Header herunterladen (?download=1)
    if (!$isFolderShare && (isset($_GET['download']) && $_GET['download'] === '1' || isset($_POST['vault_password']))) {
        $storedFilename = basename((string)$share['filename']);
        $filePath = UPLOAD_DIR . $storedFilename;
        $originalName = sanitizeOriginalFilename((string)$share['original_name']);
        $fileSize = (int)filesize($filePath);

        if ((int)($share['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($filePath)) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vault_password'])) {
                $csrfToken = $_POST['csrf_token'] ?? null;
                $vaultPw = (string)$_POST['vault_password'];
                $vInfo = null;
                if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
                    $vaultShareError = 'Ungültiges Sicherheits-Token (CSRF).';
                } elseif ($vaultPw === '') {
                    $vaultShareError = 'Bitte geben Sie das Tresor-Passwort ein.';
                } elseif (!verifyVaultFilePassword($filePath, $vaultPw, $vInfo) || !is_array($vInfo)) {
                    $vaultShareError = 'Das eingegebene Tresor-Passwort ist falsch.';
                } else {
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    $origPlainSize = (int)$vInfo['orig_size'];
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
                    if ($origPlainSize > 0) {
                        header('Content-Length: ' . (string)$origPlainSize);
                    }
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: private, no-transform, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    streamDecryptedVaultFile($filePath, $vInfo);
                    exit;
                }
            }
        } else {
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
            header('Content-Length: ' . (string)$fileSize);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-transform, no-store, must-revalidate');
            header('Pragma: no-cache');

            readfile($filePath);
            exit;
        }
    }
}

if ($errorState !== null) {
    http_response_code(404);
}

$pageTitle = $isFolderShare ? 'Geteilter Ordner' : 'Geteilte Datei';
require_once __DIR__ . '/includes/header.php';
?>

<div class="<?= $isFolderShare && $isUnlocked && $errorState === null ? 'max-w-3xl' : 'max-w-lg' ?> mx-auto mt-6 sm:mt-12 px-1">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8">

        <?php if ($errorState !== null): ?>
            <!-- Fehlerzustand: Link ungültig oder abgelaufen -->
            <div class="text-center py-4">
                <div class="mx-auto w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-950/60 text-red-500 flex items-center justify-center mb-4">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-white mb-2">
                    <?php if ($errorState === 'expired'): ?>
                        Freigabelink abgelaufen
                    <?php else: ?>
                        Freigabe nicht gefunden
                    <?php endif; ?>
                </h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                    <?php if ($errorState === 'expired'): ?>
                        Dieser Freigabelink hat sein Ablaufdatum überschritten und ist nicht mehr gültig.
                    <?php else: ?>
                        Der aufgerufene Freigabelink existiert nicht mehr oder das Element wurde vom Besitzer gelöscht.
                    <?php endif; ?>
                </p>
                <a href="index.php" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-semibold transition-colors">
                    Zur Startseite
                </a>
            </div>

        <?php elseif ($requiresPassword && !$isUnlocked): ?>
            <!-- Passwortgeschützte Freigabe: Passworteingabe -->
            <div class="text-center mb-6">
                <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-500 flex items-center justify-center mb-4 border border-amber-200/60 dark:border-amber-800/50">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Passwortgeschützte Freigabe</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <?= $isFolderShare ? 'Dieser Ordner' : 'Diese Datei' ?> wurde von <strong><?= e((string)$share['owner_username']) ?></strong> mit einem Passwort geschützt.
                </p>
            </div>

            <?php if ($passwordError !== ''): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 text-sm flex items-center gap-2">
                    <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?= e($passwordError) ?></span>
                </div>
            <?php endif; ?>

            <form action="share.php?token=<?= rawurlencode($token) ?>" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div>
                    <label for="share_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Passwort eingeben
                    </label>
                    <input
                        type="password"
                        id="share_password"
                        name="password"
                        required
                        autofocus
                        placeholder="Passwort für den Zugriff..."
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-3 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                    </svg>
                    <span>Freigabe entsperren</span>
                </button>
            </form>

        <?php elseif ($isFolderShare): ?>
            <!-- Freigeschaltete Ordner-Ansicht mit ZIP-Download & Dateiliste -->
            <div class="text-center mb-6">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-amber-100 dark:bg-amber-950/70 text-amber-500 flex items-center justify-center mb-4 shadow-xs">
                    <svg class="w-9 h-9" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                    </svg>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 mb-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Geteilter Ordner
                </span>
                <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white break-all mt-1">
                    📁 <?= e((string)$share['shared_folder_path']) ?>
                </h1>
            </div>

            <div class="bg-slate-50 dark:bg-slate-800/70 rounded-xl border border-slate-200/80 dark:border-slate-700/80 p-4 mb-6 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Enthaltene Dateien:</span>
                    <span class="font-semibold text-slate-900 dark:text-white"><?= count($sharedFolderFiles) ?> <?= count($sharedFolderFiles) === 1 ? 'Datei' : 'Dateien' ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Gesamtgröße:</span>
                    <span class="font-semibold text-slate-900 dark:text-white"><?= e(formatBytes($totalFolderSize)) ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Freigegeben von:</span>
                    <span class="font-medium text-slate-800 dark:text-slate-200"><?= e((string)$share['owner_username']) ?></span>
                </div>
                <?php if ($share['expires_at'] !== null): ?>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Gültig bis:</span>
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">
                            <?= e(date('d.m.Y H:i', strtotime((string)$share['expires_at']))) ?> Uhr
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($vaultPromptFile !== null): ?>
                <div class="mb-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs sm:text-sm font-bold text-amber-900 dark:text-amber-200">
                            🔒 Tresor-Datei entschlüsseln: <?= e((string)$vaultPromptFile['original_name']) ?>
                        </p>
                        <a href="share.php?token=<?= rawurlencode($token) ?>" class="text-xs text-slate-500 hover:underline">Abbrechen</a>
                    </div>
                    <?php if ($vaultShareError !== ''): ?>
                        <p class="text-xs font-semibold text-red-600 dark:text-red-400"><?= e($vaultShareError) ?></p>
                    <?php endif; ?>
                    <form action="share.php?token=<?= rawurlencode($token) ?>&amp;download_file=<?= (int)$vaultPromptFile['id'] ?>" method="post" class="flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <input type="hidden" name="download_file" value="<?= (int)$vaultPromptFile['id'] ?>">
                        <input
                            type="password"
                            name="vault_password"
                            required
                            autofocus
                            placeholder="Persönliches Tresor-Passwort..."
                            class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-amber-300 dark:border-amber-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                        >
                        <button
                            type="submit"
                            class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs sm:text-sm cursor-pointer shrink-0"
                        >
                            🔓 Entschlüsseln &amp; Herunterladen
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($sharedFolderFiles)): ?>
                <a
                    href="share.php?token=<?= rawurlencode($token) ?>&amp;download=1"
                    class="w-full py-3.5 px-5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-base shadow-sm transition-all flex items-center justify-center gap-2.5 mb-6"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Kompletten Ordner als ZIP herunterladen</span>
                </a>

                <div class="border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                <th class="py-2.5 px-4">Dateiname</th>
                                <th class="py-2.5 px-4 hidden sm:table-cell">Größe</th>
                                <th class="py-2.5 px-4 text-right">Aktion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">
                            <?php foreach ($sharedFolderFiles as $sff): ?>
                                <?php
                                $sffBadge = getFileTypeBadge((string)$sff['original_name'], (string)$sff['mime_type']);
                                ?>
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                    <td class="py-2.5 px-4">
                                        <div class="flex items-center gap-2.5">
                                            <span class="inline-flex items-center justify-center w-9 h-7 rounded-md text-[10px] font-bold <?= e($sffBadge['bg']) ?> <?= e($sffBadge['text']) ?> shrink-0">
                                                <?= e($sffBadge['label']) ?>
                                            </span>
                                            <span class="font-medium text-slate-900 dark:text-white truncate max-w-xs">
                                                <?= e((string)$sff['original_name']) ?>
                                            </span>
                                            <?php if ((int)($sff['is_encrypted'] ?? 0) === 1): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300" title="Tresor-geschützt (AES-256)">
                                                    🔒 Tresor
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-4 text-slate-600 dark:text-slate-300 hidden sm:table-cell">
                                        <?= e(formatBytes((int)$sff['file_size'])) ?>
                                    </td>
                                    <td class="py-2.5 px-4 text-right">
                                        <a
                                            href="share.php?token=<?= rawurlencode($token) ?>&amp;download_file=<?= (int)$sff['id'] ?>"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-brand-700 dark:text-brand-300 text-xs font-semibold transition-colors"
                                        >
                                            <span>Download</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-6 text-center text-sm text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/40 rounded-xl">
                    Dieser freigegebene Ordner ist aktuell noch leer.
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Freigeschaltete Datei-Ansicht mit großem Download-Button -->
            <?php
            $badge = getFileTypeBadge((string)$share['original_name'], (string)$share['mime_type']);
            ?>
            <div class="text-center mb-6">
                <div class="mx-auto w-16 h-16 rounded-2xl <?= e($badge['bg']) ?> <?= e($badge['text']) ?> flex items-center justify-center text-base font-extrabold tracking-tight mb-4 shadow-xs">
                    <?= e($badge['label']) ?>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 mb-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    Bereit zum Herunterladen
                </span>
                <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white break-all mt-1">
                    <?= e((string)$share['original_name']) ?>
                </h1>
            </div>

            <div class="bg-slate-50 dark:bg-slate-800/70 rounded-xl border border-slate-200/80 dark:border-slate-700/80 p-4 mb-6 space-y-2.5 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Dateigröße:</span>
                    <span class="font-semibold text-slate-900 dark:text-white"><?= e(formatBytes((int)$share['file_size'])) ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Freigegeben von:</span>
                    <span class="font-medium text-slate-800 dark:text-slate-200"><?= e((string)$share['owner_username']) ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500 dark:text-slate-400">Dateityp:</span>
                    <span class="text-xs font-mono text-slate-600 dark:text-slate-300"><?= e((string)$share['mime_type']) ?></span>
                </div>
                <?php if ($share['expires_at'] !== null): ?>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500 dark:text-slate-400">Gültig bis:</span>
                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">
                            <?= e(date('d.m.Y H:i', strtotime((string)$share['expires_at']))) ?> Uhr
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ((int)($share['is_encrypted'] ?? 0) === 1): ?>
                <?php if ($vaultShareError !== ''): ?>
                    <div class="mb-4 p-3.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 text-xs sm:text-sm flex items-center gap-2">
                        <span><?= e($vaultShareError) ?></span>
                    </div>
                <?php endif; ?>
                <form action="share.php?token=<?= rawurlencode($token) ?>" method="post" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div>
                        <label for="share_vault_pw" class="block text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            🔒 Tresor-Passwort der Datei eingeben
                        </label>
                        <input
                            type="password"
                            id="share_vault_pw"
                            name="vault_password"
                            required
                            placeholder="Persönliches Tresor-Passwort..."
                            class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>
                    <button
                        type="submit"
                        class="w-full py-3.5 px-5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-bold text-base shadow-sm transition-all flex items-center justify-center gap-2.5 cursor-pointer"
                    >
                        <span>🔓 Entschlüsseln &amp; Herunterladen</span>
                    </button>
                </form>
            <?php else: ?>
                <a
                    href="share.php?token=<?= rawurlencode($token) ?>&amp;download=1"
                    class="w-full py-3.5 px-5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-base shadow-sm transition-all flex items-center justify-center gap-2.5"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Datei herunterladen</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
