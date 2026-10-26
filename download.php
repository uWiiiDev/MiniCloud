<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

/**
 * Hilfsfunktion: Erstellt aus einer Dateiliste ein ZIP-Archiv und sendet es per Header an den Browser.
 *
 * @param array<int, array<string, mixed>> $folderFiles
 */
function streamZipArchive(array $folderFiles, string $archiveBaseName): never
{
    if (empty($folderFiles)) {
        setFlash('error', 'Der Ordner enthält noch keine Dateien zum Herunterladen.');
        header('Location: index.php');
        exit;
    }

    if (!class_exists('ZipArchive')) {
        setFlash('error', 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht aktiviert.');
        header('Location: index.php');
        exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'mc_zip_');
    if ($tmpZip === false) {
        setFlash('error', 'Temporäre ZIP-Datei konnte nicht erstellt werden.');
        header('Location: index.php');
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        setFlash('error', 'ZIP-Archiv konnte nicht initialisiert werden.');
        header('Location: index.php');
        exit;
    }

    $addedFiles = 0;
    $skippedVaultFiles = [];
    foreach ($folderFiles as $f) {
        $storedName = basename((string)$f['filename']);
        $physicalPath = UPLOAD_DIR . $storedName;
        if (!is_file($physicalPath) || !is_readable($physicalPath)) {
            continue;
        }
        $relFolder = (string)$f['folder_path'];
        $entryName = ($relFolder !== '' ? $relFolder . '/' : '') . sanitizeOriginalFilename((string)$f['original_name']);
        if (isVaultEncryptedFile($physicalPath)) {
            $skippedVaultFiles[] = $entryName;
            continue;
        }
        $zip->addFile($physicalPath, $entryName);
        $addedFiles++;
    }
    if (!empty($skippedVaultFiles)) {
        $vaultNote = "HINWEIS ZU VERSCHLÜSSELTEN TRESOR-DATEIEN\r\n"
            . "=========================================\r\n\r\n"
            . "Die folgenden Dateien in diesem Ordner sind mit einem persönlichen Tresor-Passwort (AES-256) geschützt\r\n"
            . "und können daher nicht unverschlüsselt in ein Sammel-ZIP-Archiv gepackt werden:\r\n\r\n"
            . "- " . implode("\r\n- ", $skippedVaultFiles) . "\r\n\r\n"
            . "Bitte laden Sie diese Dateien einzeln über die Web-Oberfläche mit Ihrem Tresor-Passwort herunter.\r\n";
        $zip->addFromString('TRESOR_HINWEIS.txt', $vaultNote);
        $addedFiles++;
    }
    $zip->close();

    if ($addedFiles === 0 || !is_file($tmpZip)) {
        @unlink($tmpZip);
        setFlash('error', 'Keine lesbaren Dateien für das ZIP-Archiv gefunden.');
        header('Location: index.php');
        exit;
    }

    $zipDownloadName = (preg_replace('/[^a-zA-Z0-9_.-]/', '_', basename($archiveBaseName)) ?: 'ordner') . '.zip';

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

// Option A: Kompletten persönlichen Ordner als ZIP-Archiv herunterladen (?folder=...)
if (isset($_GET['folder']) && is_string($_GET['folder']) && trim($_GET['folder']) !== '') {
    $folder = extractSanitizedFolderPath(trim($_GET['folder']) . '/placeholder');
    if ($folder === '') {
        setFlash('error', 'Ungültiger Ordnerpfad.');
        header('Location: index.php');
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE user_id = :user_id AND group_folder_id IS NULL AND deleted_at IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folder,
        ':folder_prefix' => $folder . '/%',
    ]);
    $folderFiles = $stmt->fetchAll();

    if (empty($folderFiles)) {
        setFlash('error', 'Ordner wurde nicht gefunden oder enthält keine Dateien.');
        header('Location: index.php');
        exit;
    }

    streamZipArchive($folderFiles, $folder);
}

// Option B: Von einem anderen Cloud-Benutzer freigegebenen Ordner als ZIP herunterladen (?user_share_id=...)
if (isset($_GET['user_share_id'])) {
    $userShareId = (int)$_GET['user_share_id'];
    $stmtShare = $pdo->prepare(
        'SELECT id, owner_id, folder_path
         FROM user_shares
         WHERE id = :id AND shared_with_user_id = :uid AND folder_path IS NOT NULL
         LIMIT 1'
    );
    $stmtShare->execute([
        ':id'  => $userShareId,
        ':uid' => $userId,
    ]);
    $sharedFolderRow = $stmtShare->fetch();

    if (!$sharedFolderRow || empty($sharedFolderRow['folder_path'])) {
        setFlash('error', 'Freigegebener Ordner nicht gefunden oder keine Berechtigung.');
        header('Location: index.php');
        exit;
    }

    $folder = (string)$sharedFolderRow['folder_path'];
    $ownerId = (int)$sharedFolderRow['owner_id'];

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE user_id = :owner_id AND group_folder_id IS NULL AND deleted_at IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([
        ':owner_id'      => $ownerId,
        ':folder_exact'  => $folder,
        ':folder_prefix' => $folder . '/%',
    ]);
    $folderFiles = $stmt->fetchAll();

    streamZipArchive($folderFiles, $folder);
}

// Option C: Kompletten Gruppenordner als ZIP herunterladen (?group_folder_zip=...)
if (isset($_GET['group_folder_zip'])) {
    $gfId = (int)$_GET['group_folder_zip'];
    if (!canUserAccessGroupFolder($pdo, $userId, $gfId, false, $isAdmin)) {
        setFlash('error', 'Kein Zugriff auf diesen Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    $stmtGf = $pdo->prepare('SELECT name FROM group_folders WHERE id = :id LIMIT 1');
    $stmtGf->execute([':id' => $gfId]);
    $gfRow = $stmtGf->fetch();

    $stmt = $pdo->prepare(
        'SELECT id, filename, original_name, folder_path, file_size
         FROM files
         WHERE group_folder_id = :gf_id AND deleted_at IS NULL
         ORDER BY folder_path ASC, original_name ASC'
    );
    $stmt->execute([':gf_id' => $gfId]);
    $folderFiles = $stmt->fetchAll();

    streamZipArchive($folderFiles, (string)($gfRow['name'] ?? 'gruppenordner'));
}

// Option C2: Mehrfachauswahl (Batch-Download ausgewählter Dateien als ZIP-Archiv, ?batch_ids=1,2,3)
if (isset($_GET['batch_ids']) && is_string($_GET['batch_ids']) && trim($_GET['batch_ids']) !== '') {
    $rawIds = explode(',', $_GET['batch_ids']);
    $batchFiles = [];
    foreach ($rawIds as $rawId) {
        $fid = (int)trim($rawId);
        if ($fid <= 0) {
            continue;
        }
        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fid, $isAdmin);
        if ($authFile) {
            // Für ein flaches/sauberes Auswahl-ZIP setzen wir folder_path leer
            $authFile['folder_path'] = '';
            $batchFiles[] = $authFile;
        }
    }

    if (empty($batchFiles)) {
        setFlash('error', 'Keine berechtigten Dateien für den Sammel-Download ausgewählt.');
        header('Location: index.php');
        exit;
    }

    streamZipArchive($batchFiles, 'MiniCloud_Auswahl_' . date('Y-m-d_His'));
}

// Option C3: Frühere Dateiversion herunterladen (?version_id=...)
if (isset($_GET['version_id']) && (int)$_GET['version_id'] > 0) {
    $verId = (int)$_GET['version_id'];
    $stmtVer = $pdo->prepare('SELECT * FROM file_versions WHERE id = :vid LIMIT 1');
    $stmtVer->execute([':vid' => $verId]);
    $verRow = $stmtVer->fetch();

    if (!$verRow) {
        setFlash('error', 'Die angeforderte Dateiversion existiert nicht.');
        header('Location: index.php');
        exit;
    }

    $parentFile = getAuthorizedFileForDownload($pdo, $userId, (int)$verRow['file_id'], $isAdmin);
    if (!$parentFile) {
        http_response_code(403);
        setFlash('error', 'Zugriff verweigert: Keine Berechtigung für diese Dateiversion.');
        header('Location: index.php');
        exit;
    }

    $verPath = UPLOAD_DIR . basename((string)$verRow['stored_filename']);
    if (!is_file($verPath) || !is_readable($verPath)) {
        http_response_code(404);
        setFlash('error', 'Die physische Versionsdatei wurde nicht gefunden.');
        header('Location: index.php');
        exit;
    }

    $origName = sanitizeOriginalFilename((string)$parentFile['original_name']);
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $base = pathinfo($origName, PATHINFO_FILENAME);
    $tsStr = date('Y-m-d_Hi', strtotime((string)$verRow['created_at']) ?: time());
    $dlName = $base . '_v' . $verId . '_' . $tsStr . ($ext !== '' ? '.' . $ext : '');

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($dlName) . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
    header('Content-Length: ' . (string)filesize($verPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($verPath);
    exit;
}

// Option D: Einzelne Datei herunterladen (?id=...) – prüft Eigentümer, Benutzer-Freigabe oder Gruppenordner-Recht
$fileId = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
if ($fileId <= 0) {
    setFlash('error', 'Ungültige Datei-ID.');
    header('Location: index.php');
    exit;
}

$file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);

if (!$file) {
    http_response_code(403);
    setFlash('error', 'Zugriff verweigert: Die angeforderte Datei existiert nicht oder Sie haben keine Berechtigung.');
    header('Location: index.php');
    exit;
}

$storedFilename = basename((string)$file['filename']);
$filePath = UPLOAD_DIR . $storedFilename;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    setFlash('error', 'Die physische Datei wurde auf dem Server nicht gefunden.');
    header('Location: index.php');
    exit;
}

$originalName = sanitizeOriginalFilename((string)$file['original_name']);
$fileSize = (int)filesize($filePath);
$isInline = isset($_REQUEST['inline']) && $_REQUEST['inline'] === '1';
$asText   = isset($_REQUEST['as_text']) && $_REQUEST['as_text'] === '1';

// Prüfen, ob die Datei im Tresor-Modus (AES-256-GCM) verschlüsselt ist
if ((int)($file['is_encrypted'] ?? 0) === 1 || isVaultEncryptedFile($filePath)) {
    $vaultError = '';
    $attemptKey = 'vault_dl_attempts_' . $fileId;
    $lockoutWindow = 300;
    $maxAttempts = 5;
    $failedAttempts = isset($_SESSION[$attemptKey]) && is_array($_SESSION[$attemptKey])
        ? array_values(array_filter($_SESSION[$attemptKey], static fn($ts): bool => is_int($ts) && $ts > (time() - $lockoutWindow)))
        : [];
    $_SESSION[$attemptKey] = $failedAttempts;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vault_password'])) {
        $csrfToken = $_POST['csrf_token'] ?? null;
        $vaultPassword = (string)$_POST['vault_password'];
        $vaultHeaderInfo = null;

        if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
            $vaultError = 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.';
        } elseif (count($failedAttempts) >= $maxAttempts) {
            $waitSecs = max(1, ($failedAttempts[0] + $lockoutWindow) - time());
            $vaultError = sprintf('Zu viele Fehlversuche für diese Tresor-Datei. Bitte warten Sie %d Sekunden.', $waitSecs);
        } elseif ($vaultPassword === '') {
            $vaultError = 'Bitte geben Sie das Tresor-Passwort für diese Datei ein.';
        } elseif (!verifyVaultFilePassword($filePath, $vaultPassword, $vaultHeaderInfo) || !is_array($vaultHeaderInfo)) {
            $_SESSION[$attemptKey][] = time();
            $vaultError = 'Das eingegebene Tresor-Passwort ist falsch.';
        } else {
            unset($_SESSION[$attemptKey]);
            if (ob_get_level()) {
                ob_end_clean();
            }

            $origPlainSize = (int)$vaultHeaderInfo['orig_size'];
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
            if ($origPlainSize > 0) {
                header('Content-Length: ' . (string)$origPlainSize);
            }
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-transform, no-store, must-revalidate');
            header('Pragma: no-cache');

            streamDecryptedVaultFile($filePath, $vaultHeaderInfo);
            exit;
        }
    }

    // Tresor-Passwort-Eingabeseite anzeigen
    $pageTitle = 'Tresor-Datei entschlüsseln';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-md mx-auto mt-8 sm:mt-14 px-1">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8">
            <div class="text-center mb-6">
                <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center mb-4 border border-amber-200/70 dark:border-amber-800/60 text-2xl">
                    🔒
                </div>
                <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Tresor-geschützte Datei</h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1 break-all">
                    <strong><?= e($originalName) ?></strong> ist mit <strong>AES-256</strong> verschlüsselt.
                </p>
            </div>

            <?php if ($vaultError !== ''): ?>
                <div class="mb-5 p-3.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 text-xs sm:text-sm flex items-center gap-2">
                    <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?= e($vaultError) ?></span>
                </div>
            <?php endif; ?>

            <form action="download.php?id=<?= (int)$fileId ?>" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int)$fileId ?>">

                <div>
                    <label for="vault_dl_password" class="block text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Tresor-Passwort eingeben
                    </label>
                    <input
                        type="password"
                        id="vault_dl_password"
                        name="vault_password"
                        required
                        autofocus
                        placeholder="Persönliches Tresor-Passwort..."
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-3 px-4 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                >
                    <span>🔓 Entschlüsseln &amp; Herunterladen</span>
                </button>
            </form>

            <div class="mt-5 pt-4 border-t border-slate-200 dark:border-slate-800 text-center">
                <a href="index.php" class="text-xs font-semibold text-slate-500 hover:text-brand-600 dark:text-slate-400 dark:hover:text-brand-400">
                    &larr; Zurück zu meinen Dateien
                </a>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

if (ob_get_level()) {
    ob_end_clean();
}

if ($isInline) {
    $rawMime = strtolower(trim((string)($file['mime_type'] ?? 'application/octet-stream')));
    $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($asText) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Cache-Control: private, no-store, must-revalidate');
        // Für die Textvorschau maximal die ersten 512 KB ausliefern, damit auch große Logdateien flüssig bleiben
        $maxTextBytes = 512 * 1024;
        $fh = fopen($filePath, 'rb');
        if ($fh !== false) {
            echo fread($fh, $maxTextBytes);
            if ($fileSize > $maxTextBytes) {
                echo "\n\n--- [Vorschau nach 512 KB gekürzt – bitte laden Sie die vollständige Datei herunter] ---";
            }
            fclose($fh);
        }
        exit;
    }

    // Sichere Inline-MIME-Types für Browser-Vorschau (Bilder, PDF, Audio, Video)
    $safeInlineMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/avif',
        'application/pdf',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
    ];

    $extMimeFallback = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
    ];

    $contentType = in_array($rawMime, $safeInlineMimes, true)
        ? $rawMime
        : ($extMimeFallback[$ext] ?? 'application/octet-stream');

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . addslashes($originalName) . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
    header('Content-Length: ' . (string)$fileSize);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; media-src \'self\'; object-src \'self\'; style-src \'unsafe-inline\'');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');

    readfile($filePath);
    exit;
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
