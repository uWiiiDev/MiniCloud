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

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.');
    header('Location: index.php');
    exit;
}

$userId        = (int)$_SESSION['user_id'];
$isAdmin       = isAdmin();
$pdo           = getDB();
$action        = (string)($_POST['action'] ?? '');
$groupFolderId = isset($_POST['group_folder_id']) && (int)$_POST['group_folder_id'] > 0
    ? (int)$_POST['group_folder_id']
    : null;

/**
 * Parst eine kombinierte Ziel-Spezifikation (z.B. "move|personal:Projekte", "copy|gf:2:Team", "__root__", "Ordner").
 *
 * @return array{mode: string, gf_id: ?int, folder: string}
 */
function parseTransferTargetSpec(string $rawSpec, ?int $defaultGfId): array
{
    $rawSpec = trim($rawSpec);
    $mode    = 'move';

    if (str_starts_with($rawSpec, 'move|')) {
        $mode    = 'move';
        $rawSpec = substr($rawSpec, 5);
    } elseif (str_starts_with($rawSpec, 'copy|')) {
        $mode    = 'copy';
        $rawSpec = substr($rawSpec, 5);
    }

    if (str_starts_with($rawSpec, 'personal:')) {
        $sub = substr($rawSpec, 9);
        return [
            'mode'   => $mode,
            'gf_id'  => null,
            'folder' => ($sub === '' || $sub === '__root__') ? '' : sanitizeFolderPathInput($sub),
        ];
    }

    if (preg_match('/^gf:(\d+):(.*)$/', $rawSpec, $m)) {
        $gfId = (int)$m[1];
        $sub  = $m[2];
        return [
            'mode'   => $mode,
            'gf_id'  => $gfId > 0 ? $gfId : null,
            'folder' => ($sub === '' || $sub === '__root__') ? '' : sanitizeFolderPathInput($sub),
        ];
    }

    if ($rawSpec === '__root__' || $rawSpec === '') {
        return [
            'mode'   => $mode,
            'gf_id'  => $defaultGfId,
            'folder' => '',
        ];
    }

    return [
        'mode'   => $mode,
        'gf_id'  => $defaultGfId,
        'folder' => sanitizeFolderPathInput($rawSpec),
    ];
}

/**
 * Liefert eine lesbare Bezeichnung für einen Zielort (Persönliche Cloud oder Gruppenordner).
 */
function formatTransferTargetLabel(PDO $pdo, ?int $targetGfId, string $targetFolder): string
{
    if ($targetGfId !== null) {
        $stmt = $pdo->prepare('SELECT name FROM group_folders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $targetGfId]);
        $gfName = (string)($stmt->fetchColumn() ?: ('Gruppenordner #' . $targetGfId));
        return $targetFolder !== ''
            ? sprintf('Gruppenordner "%s / %s"', $gfName, $targetFolder)
            : sprintf('Gruppenordner "%s" (Hauptverzeichnis)', $gfName);
    }

    return $targetFolder !== ''
        ? sprintf('meinen Ordner "%s"', $targetFolder)
        : 'mein persönliches Hauptverzeichnis';
}

// 1. Neuen Ordner / Unterordner anlegen
if ($action === 'create_folder') {
    if ($groupFolderId !== null && !canUserAccessGroupFolder($pdo, $userId, $groupFolderId, true, $isAdmin)) {
        setFlash('error', 'Sie besitzen keine Schreibberechtigung für diesen Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    $rawFolderName = isset($_POST['folder_name']) ? trim((string)$_POST['folder_name']) : '';
    $parentFolder  = isset($_POST['parent_folder']) ? sanitizeFolderPathInput((string)$_POST['parent_folder']) : '';

    $cleanSubName = sanitizeFolderPathInput($rawFolderName);
    if ($cleanSubName === '') {
        setFlash('error', 'Bitte geben Sie einen gültigen Ordnernamen ein.');
        header('Location: index.php');
        exit;
    }

    $fullNewPath = $parentFolder !== ''
        ? sanitizeFolderPathInput($parentFolder . '/' . $cleanSubName)
        : $cleanSubName;

    registerFolderHierarchy($pdo, $userId, $fullNewPath, $groupFolderId);

    setFlash('success', sprintf('Ordner "%s" wurde erfolgreich angelegt.', $fullNewPath));

    $queryParams = [];
    if ($groupFolderId !== null) {
        $queryParams['group_folder'] = (string)$groupFolderId;
    }
    $queryParams['folder'] = $fullNewPath;
    header('Location: index.php?' . http_build_query($queryParams));
    exit;
}

// 2. Datei verschieben ODER kopieren (zwischen persönlichen Ordnern und Gruppenordnern in beide Richtungen)
if ($action === 'move_file') {
    $fileId       = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $rawTarget    = isset($_POST['target_folder']) ? (string)$_POST['target_folder'] : '';
    $returnFolder = isset($_POST['return_folder']) ? sanitizeFolderPathInput((string)$_POST['return_folder']) : '';

    $stmtFile = $pdo->prepare(
        'SELECT id, user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted
         FROM files
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmtFile->execute([':id' => $fileId]);
    $file = $stmtFile->fetch();

    if (!$file) {
        setFlash('error', 'Die ausgewählte Datei wurde nicht gefunden.');
        header('Location: index.php');
        exit;
    }

    $sourceGfId   = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
    $sourceFolder = (string)$file['folder_path'];
    $targetSpec   = parseTransferTargetSpec($rawTarget, $sourceGfId);
    $mode         = $targetSpec['mode'];
    $targetGfId   = $targetSpec['gf_id'];
    $targetFolder = $targetSpec['folder'];

    // Quell-Berechtigung prüfen
    if ($mode === 'move') {
        $canWriteSource = ((int)$file['user_id'] === $userId)
            || ($sourceGfId !== null && canUserAccessGroupFolder($pdo, $userId, $sourceGfId, true, $isAdmin));
        if (!$canWriteSource) {
            setFlash('error', 'Zugriff verweigert: Sie haben keine Berechtigung, diese Datei zu verschieben.');
            header('Location: index.php');
            exit;
        }
    } else {
        $authorizedFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authorizedFile) {
            setFlash('error', 'Zugriff verweigert: Sie haben keine Leseberechtigung für diese Datei.');
            header('Location: index.php');
            exit;
        }
    }

    // Ziel-Berechtigung prüfen
    if ($targetGfId !== null && !canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin)) {
        setFlash('error', 'Zugriff verweigert: Sie besitzen keine Schreibberechtigung für den gewählten Ziel-Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    $destLabel = formatTransferTargetLabel($pdo, $targetGfId, $targetFolder);

    if ($mode === 'move') {
        if ($sourceGfId === $targetGfId && $sourceFolder === $targetFolder) {
            setFlash('warning', 'Die Datei befindet sich bereits am gewählten Zielort.');
        } else {
            if ($targetFolder !== '') {
                registerFolderHierarchy($pdo, $userId, $targetFolder, $targetGfId);
            }

            // Wenn Datei in persönliche Cloud verschoben wird, gehört sie dem verschiebenden Benutzer
            $newOwnerId = $targetGfId === null ? $userId : (int)$file['user_id'];

            $stmtMove = $pdo->prepare(
                'UPDATE files SET user_id = :uid, group_folder_id = :gf_id, folder_path = :fp WHERE id = :id'
            );
            $stmtMove->execute([
                ':uid'   => $newOwnerId,
                ':gf_id' => $targetGfId,
                ':fp'    => $targetFolder,
                ':id'    => $fileId,
            ]);

            setFlash('success', sprintf('Datei "%s" wurde in %s verschoben.', (string)$file['original_name'], $destLabel));
        }
    } else {
        // KOPIEREN: Physische Datei im Upload-Verzeichnis duplizieren und neuen DB-Eintrag anlegen
        $srcStored = basename((string)$file['filename']);
        $srcPath   = UPLOAD_DIR . $srcStored;
        if (!is_file($srcPath) || !is_readable($srcPath)) {
            setFlash('error', 'Die physische Quelldatei konnte zum Kopieren nicht gelesen werden.');
            header('Location: index.php');
            exit;
        }

        $fileSize = (int)$file['file_size'];
        if ($targetGfId === null) {
            $stats = getUserStorageStats($pdo, $userId);
            if ($fileSize > $stats['free']) {
                setFlash('error', 'Nicht genügend freier persönlicher Speicherplatz zum Kopieren dieser Datei.');
                header('Location: index.php');
                exit;
            }
        }

        $newStored = generateSafeStoredFilename();
        $dstPath   = UPLOAD_DIR . $newStored;
        if (!@copy($srcPath, $dstPath)) {
            setFlash('error', 'Fehler beim Duplizieren der Datei auf dem Server.');
            header('Location: index.php');
            exit;
        }
        @chmod($dstPath, 0640);

        if ($targetFolder !== '') {
            registerFolderHierarchy($pdo, $userId, $targetFolder, $targetGfId);
        }

        $copyName = (string)$file['original_name'];
        if ($sourceGfId === $targetGfId && $sourceFolder === $targetFolder) {
            $dotPos = strrpos($copyName, '.');
            $copyName = $dotPos !== false
                ? substr($copyName, 0, $dotPos) . ' (Kopie)' . substr($copyName, $dotPos)
                : $copyName . ' (Kopie)';
        }

        $stmtCopy = $pdo->prepare(
            'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
             VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, :enc)'
        );
        $stmtCopy->execute([
            ':uid'   => $userId,
            ':gf_id' => $targetGfId,
            ':fn'    => $newStored,
            ':orig'  => $copyName,
            ':fp'    => $targetFolder,
            ':sz'    => $fileSize,
            ':mime'  => (string)$file['mime_type'],
            ':enc'   => (int)($file['is_encrypted'] ?? 0),
        ]);

        setFlash('success', sprintf('Datei "%s" wurde in %s kopiert.', (string)$file['original_name'], $destLabel));
    }

    $queryParams = [];
    if ($sourceGfId !== null) {
        $queryParams['group_folder'] = (string)$sourceGfId;
    }
    if ($returnFolder !== '') {
        $queryParams['folder'] = $returnFolder;
    }
    header('Location: index.php' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : ''));
    exit;
}

// 3. Ordner umbenennen
if ($action === 'rename_folder') {
    if ($groupFolderId !== null && !canUserAccessGroupFolder($pdo, $userId, $groupFolderId, true, $isAdmin)) {
        setFlash('error', 'Sie besitzen keine Schreibberechtigung für diesen Gruppenordner.');
        header('Location: index.php');
        exit;
    }
    $oldFolder = isset($_POST['old_folder']) ? sanitizeFolderPathInput((string)$_POST['old_folder']) : '';
    $newFolder = isset($_POST['new_folder']) ? sanitizeFolderPathInput((string)$_POST['new_folder']) : '';

    if ($oldFolder === '' || $newFolder === '' || $oldFolder === $newFolder) {
        setFlash('error', 'Bitte geben Sie einen gültigen neuen Ordnernamen an.');
        header('Location: index.php');
        exit;
    }

    registerFolderHierarchy($pdo, $userId, $newFolder, $groupFolderId);

    if ($groupFolderId !== null) {
        // Dateien im Gruppenordner aktualisieren
        $stmtFiles = $pdo->prepare(
            'SELECT id, folder_path FROM files
             WHERE group_folder_id = :gf_id AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtFiles->execute([
            ':gf_id'  => $groupFolderId,
            ':exact'  => $oldFolder,
            ':prefix' => $oldFolder . '/%',
        ]);
        foreach ($stmtFiles->fetchAll() as $fRow) {
            $updatedPath = $newFolder . substr((string)$fRow['folder_path'], strlen($oldFolder));
            $pdo->prepare('UPDATE files SET folder_path = :fp WHERE id = :id')
                ->execute([':fp' => $updatedPath, ':id' => (int)$fRow['id']]);
        }

        // Einträge in user_folders aktualisieren
        $stmtUf = $pdo->prepare(
            'SELECT id, folder_path FROM user_folders
             WHERE group_folder_id = :gf_id AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtUf->execute([
            ':gf_id'  => $groupFolderId,
            ':exact'  => $oldFolder,
            ':prefix' => $oldFolder . '/%',
        ]);
        foreach ($stmtUf->fetchAll() as $ufRow) {
            $updatedPath = $newFolder . substr((string)$ufRow['folder_path'], strlen($oldFolder));
            $pdo->prepare('UPDATE user_folders SET folder_path = :fp WHERE id = :id')
                ->execute([':fp' => $updatedPath, ':id' => (int)$ufRow['id']]);
        }
    } else {
        $stmtFiles = $pdo->prepare(
            'SELECT id, folder_path FROM files
             WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtFiles->execute([
            ':uid'    => $userId,
            ':exact'  => $oldFolder,
            ':prefix' => $oldFolder . '/%',
        ]);
        foreach ($stmtFiles->fetchAll() as $fRow) {
            $updatedPath = $newFolder . substr((string)$fRow['folder_path'], strlen($oldFolder));
            $pdo->prepare('UPDATE files SET folder_path = :fp WHERE id = :id AND user_id = :uid')
                ->execute([':fp' => $updatedPath, ':id' => (int)$fRow['id'], ':uid' => $userId]);
        }

        $stmtUf = $pdo->prepare(
            'SELECT id, folder_path FROM user_folders
             WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtUf->execute([
            ':uid'    => $userId,
            ':exact'  => $oldFolder,
            ':prefix' => $oldFolder . '/%',
        ]);
        foreach ($stmtUf->fetchAll() as $ufRow) {
            $updatedPath = $newFolder . substr((string)$ufRow['folder_path'], strlen($oldFolder));
            $pdo->prepare('UPDATE user_folders SET folder_path = :fp WHERE id = :id AND user_id = :uid')
                ->execute([':fp' => $updatedPath, ':id' => (int)$ufRow['id'], ':uid' => $userId]);
        }
    }

    setFlash('success', sprintf('Ordner "%s" wurde in "%s" umbenannt.', $oldFolder, $newFolder));
    $queryParams = [];
    if ($groupFolderId !== null) {
        $queryParams['group_folder'] = (string)$groupFolderId;
    }
    $queryParams['folder'] = $newFolder;
    header('Location: index.php?' . http_build_query($queryParams));
    exit;
}

// 4. Kompletten Ordner (inklusive aller Unterordner und Dateien) verschieben ODER kopieren (auch zwischen persönlichen Ordnern und Gruppenordnern)
if ($action === 'move_folder') {
    $sourceFolder = isset($_POST['source_folder']) ? sanitizeFolderPathInput((string)$_POST['source_folder']) : '';
    $rawTarget    = isset($_POST['target_parent']) ? trim((string)$_POST['target_parent']) : '';

    if ($sourceFolder === '' || $rawTarget === '') {
        setFlash('error', 'Bitte wählen Sie einen gültigen Zielordner aus.');
        header('Location: index.php');
        exit;
    }

    $targetSpec   = parseTransferTargetSpec($rawTarget, $groupFolderId);
    $mode         = $targetSpec['mode'];
    $targetGfId   = $targetSpec['gf_id'];
    $targetParent = $targetSpec['folder'];

    // Quell-Berechtigung prüfen
    if ($groupFolderId !== null) {
        $requireWriteSource = ($mode === 'move');
        if (!canUserAccessGroupFolder($pdo, $userId, $groupFolderId, $requireWriteSource, $isAdmin)) {
            setFlash('error', 'Sie besitzen nicht die erforderliche Berechtigung für diesen Quell-Gruppenordner.');
            header('Location: index.php');
            exit;
        }
    }

    // Ziel-Berechtigung prüfen
    if ($targetGfId !== null && !canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin)) {
        setFlash('error', 'Sie besitzen keine Schreibberechtigung für den gewählten Ziel-Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    $folderBaseName = basename($sourceFolder);
    $newFolderPath  = $targetParent !== ''
        ? sanitizeFolderPathInput($targetParent . '/' . $folderBaseName)
        : sanitizeFolderPathInput($folderBaseName);

    // Prüfen auf Selbst-Verschiebung / Schleife im selben Speicherbereich
    if ($groupFolderId === $targetGfId) {
        if ($targetParent === $sourceFolder || str_starts_with($targetParent, $sourceFolder . '/')) {
            setFlash('error', 'Ein Ordner kann nicht in sich selbst oder einen eigenen Unterordner verschoben/kopiert werden.');
            header('Location: index.php?folder=' . rawurlencode($sourceFolder));
            exit;
        }
        if ($newFolderPath === $sourceFolder) {
            if ($mode === 'move') {
                setFlash('warning', 'Der Ordner befindet sich bereits am gewählten Zielort.');
                header('Location: index.php?folder=' . rawurlencode($sourceFolder));
                exit;
            }
            $newFolderPath = sanitizeFolderPathInput($newFolderPath . ' (Kopie)');
        }
    }

    // Quelldateien und Quell-Ordnerstruktur laden
    if ($groupFolderId !== null) {
        $stmtFiles = $pdo->prepare(
            'SELECT id, user_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted
             FROM files
             WHERE group_folder_id = :gf_id AND deleted_at IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtFiles->execute([
            ':gf_id'  => $groupFolderId,
            ':exact'  => $sourceFolder,
            ':prefix' => $sourceFolder . '/%',
        ]);
        $sourceFiles = $stmtFiles->fetchAll();

        $stmtUf = $pdo->prepare(
            'SELECT id, folder_path FROM user_folders
             WHERE group_folder_id = :gf_id AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtUf->execute([
            ':gf_id'  => $groupFolderId,
            ':exact'  => $sourceFolder,
            ':prefix' => $sourceFolder . '/%',
        ]);
        $sourceUfRows = $stmtUf->fetchAll();
    } else {
        $stmtFiles = $pdo->prepare(
            'SELECT id, user_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted
             FROM files
             WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtFiles->execute([
            ':uid'    => $userId,
            ':exact'  => $sourceFolder,
            ':prefix' => $sourceFolder . '/%',
        ]);
        $sourceFiles = $stmtFiles->fetchAll();

        $stmtUf = $pdo->prepare(
            'SELECT id, folder_path FROM user_folders
             WHERE user_id = :uid AND group_folder_id IS NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
        );
        $stmtUf->execute([
            ':uid'    => $userId,
            ':exact'  => $sourceFolder,
            ':prefix' => $sourceFolder . '/%',
        ]);
        $sourceUfRows = $stmtUf->fetchAll();
    }

    $destLabel = formatTransferTargetLabel($pdo, $targetGfId, $newFolderPath);

    if ($mode === 'move') {
        registerFolderHierarchy($pdo, $userId, $newFolderPath, $targetGfId);

        foreach ($sourceFiles as $fRow) {
            $updatedPath = $newFolderPath . substr((string)$fRow['folder_path'], strlen($sourceFolder));
            $newOwnerId  = $targetGfId === null ? $userId : (int)$fRow['user_id'];
            $pdo->prepare('UPDATE files SET user_id = :uid, group_folder_id = :gf_id, folder_path = :fp WHERE id = :id')
                ->execute([
                    ':uid'   => $newOwnerId,
                    ':gf_id' => $targetGfId,
                    ':fp'    => $updatedPath,
                    ':id'    => (int)$fRow['id'],
                ]);
        }

        foreach ($sourceUfRows as $ufRow) {
            $updatedPath = $newFolderPath . substr((string)$ufRow['folder_path'], strlen($sourceFolder));
            registerFolderHierarchy($pdo, $userId, $updatedPath, $targetGfId);
            $pdo->prepare('DELETE FROM user_folders WHERE id = :id')
                ->execute([':id' => (int)$ufRow['id']]);
        }

        if ($groupFolderId === null) {
            if ($targetGfId === null) {
                // Innerhalb der persönlichen Cloud verschoben -> Freigaben mitziehen
                $stmtShares = $pdo->prepare(
                    'SELECT id, folder_path FROM shares
                     WHERE user_id = :uid AND folder_path IS NOT NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
                );
                $stmtShares->execute([
                    ':uid'    => $userId,
                    ':exact'  => $sourceFolder,
                    ':prefix' => $sourceFolder . '/%',
                ]);
                foreach ($stmtShares->fetchAll() as $sRow) {
                    $updatedPath = $newFolderPath . substr((string)$sRow['folder_path'], strlen($sourceFolder));
                    $pdo->prepare('UPDATE shares SET folder_path = :fp WHERE id = :id')
                        ->execute([':fp' => $updatedPath, ':id' => (int)$sRow['id']]);
                }

                $stmtUserShares = $pdo->prepare(
                    'SELECT id, folder_path FROM user_shares
                     WHERE owner_id = :uid AND folder_path IS NOT NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
                );
                $stmtUserShares->execute([
                    ':uid'    => $userId,
                    ':exact'  => $sourceFolder,
                    ':prefix' => $sourceFolder . '/%',
                ]);
                foreach ($stmtUserShares->fetchAll() as $usRow) {
                    $updatedPath = $newFolderPath . substr((string)$usRow['folder_path'], strlen($sourceFolder));
                    $pdo->prepare('UPDATE user_shares SET folder_path = :fp WHERE id = :id')
                        ->execute([':fp' => $updatedPath, ':id' => (int)$usRow['id']]);
                }
            } else {
                // Aus persönlicher Cloud in Gruppenordner verschoben -> persönliche Ordnerfreigaben aufräumen
                $pdo->prepare(
                    'DELETE FROM shares WHERE user_id = :uid AND folder_path IS NOT NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
                )->execute([':uid' => $userId, ':exact' => $sourceFolder, ':prefix' => $sourceFolder . '/%']);

                $pdo->prepare(
                    'DELETE FROM user_shares WHERE owner_id = :uid AND folder_path IS NOT NULL AND (folder_path = :exact OR folder_path LIKE :prefix)'
                )->execute([':uid' => $userId, ':exact' => $sourceFolder, ':prefix' => $sourceFolder . '/%']);
            }
        }

        setFlash('success', sprintf('Ordner "%s" wurde samt Inhalt in %s verschoben.', $sourceFolder, $destLabel));
    } else {
        // KOPIEREN eines gesamten Ordners samt Unterordnern und Dateien
        if ($targetGfId === null && !empty($sourceFiles)) {
            $totalCopyBytes = 0;
            foreach ($sourceFiles as $fRow) {
                $totalCopyBytes += (int)$fRow['file_size'];
            }
            $stats = getUserStorageStats($pdo, $userId);
            if ($totalCopyBytes > $stats['free']) {
                setFlash('error', 'Nicht genügend freier persönlicher Speicherplatz zum Kopieren dieses Ordners.');
                header('Location: index.php');
                exit;
            }
        }

        registerFolderHierarchy($pdo, $userId, $newFolderPath, $targetGfId);

        foreach ($sourceUfRows as $ufRow) {
            $updatedPath = $newFolderPath . substr((string)$ufRow['folder_path'], strlen($sourceFolder));
            registerFolderHierarchy($pdo, $userId, $updatedPath, $targetGfId);
        }

        $copiedCount = 0;
        $stmtInsertCopy = $pdo->prepare(
            'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
             VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, :enc)'
        );

        foreach ($sourceFiles as $fRow) {
            $srcStored = basename((string)$fRow['filename']);
            $srcPath   = UPLOAD_DIR . $srcStored;
            if (!is_file($srcPath) || !is_readable($srcPath)) {
                continue;
            }

            $newStored = generateSafeStoredFilename();
            $dstPath   = UPLOAD_DIR . $newStored;
            if (!@copy($srcPath, $dstPath)) {
                continue;
            }
            @chmod($dstPath, 0640);

            $updatedPath = $newFolderPath . substr((string)$fRow['folder_path'], strlen($sourceFolder));
            registerFolderHierarchy($pdo, $userId, $updatedPath, $targetGfId);

            $stmtInsertCopy->execute([
                ':uid'   => $userId,
                ':gf_id' => $targetGfId,
                ':fn'    => $newStored,
                ':orig'  => (string)$fRow['original_name'],
                ':fp'    => $updatedPath,
                ':sz'    => (int)$fRow['file_size'],
                ':mime'  => (string)$fRow['mime_type'],
                ':enc'   => (int)($fRow['is_encrypted'] ?? 0),
            ]);
            $copiedCount++;
        }

        setFlash(
            'success',
            sprintf('Ordner "%s" (%d Datei(en)) wurde in %s kopiert.', $sourceFolder, $copiedCount, $destLabel)
        );
    }

    $queryParams = [];
    if ($targetGfId !== null) {
        $queryParams['group_folder'] = (string)$targetGfId;
    }
    $queryParams['folder'] = $newFolderPath;
    header('Location: index.php?' . http_build_query($queryParams));
    exit;
}

// 5. Favoriten-Status (⭐) für eine einzelne Datei umschalten (per AJAX oder Formular)
if ($action === 'toggle_favorite') {
    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $stmtF = $pdo->prepare('SELECT id, user_id, group_folder_id, is_favorite, original_name FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmtF->execute([':id' => $fileId]);
    $fRow = $stmtF->fetch();

    $canFav = false;
    if ($fRow) {
        if ((int)$fRow['user_id'] === $userId) {
            $canFav = true;
        } elseif (!empty($fRow['group_folder_id']) && canUserAccessGroupFolder($pdo, $userId, (int)$fRow['group_folder_id'], false, $isAdmin)) {
            $canFav = true;
        }
    }

    if (!$fRow || !$canFav) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
            exit;
        }
        setFlash('error', 'Datei nicht gefunden oder keine Berechtigung.');
        header('Location: index.php');
        exit;
    }

    $newFav = ((int)($fRow['is_favorite'] ?? 0) === 1) ? 0 : 1;
    $pdo->prepare('UPDATE files SET is_favorite = :fav WHERE id = :id')->execute([
        ':fav' => $newFav,
        ':id'  => $fileId,
    ]);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => true,
            'file_id'     => $fileId,
            'is_favorite' => ($newFav === 1),
        ]);
        exit;
    }

    setFlash('success', $newFav === 1 ? 'Als Favorit markiert.' : 'Aus Favoriten entfernt.');
    header('Location: index.php');
    exit;
}

// 6. Mehrfachauswahl (Batch): Ausgewählte Dateien gemeinsam verschieben oder kopieren
if ($action === 'batch_transfer_files') {
    $rawIds       = isset($_POST['batch_file_ids']) ? explode(',', (string)$_POST['batch_file_ids']) : [];
    $rawTarget    = isset($_POST['target_folder']) ? (string)$_POST['target_folder'] : '';
    $returnFolder = isset($_POST['return_folder']) ? sanitizeFolderPathInput((string)$_POST['return_folder']) : '';

    $targetSpec   = parseTransferTargetSpec($rawTarget, $groupFolderId);
    $mode         = $targetSpec['mode'];
    $targetGfId   = $targetSpec['gf_id'];
    $targetFolder = $targetSpec['folder'];

    if ($targetGfId !== null && !canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin)) {
        setFlash('error', 'Sie besitzen keine Schreibberechtigung für den gewählten Ziel-Gruppenordner.');
        header('Location: index.php');
        exit;
    }

    if ($targetFolder !== '') {
        registerFolderHierarchy($pdo, $userId, $targetFolder, $targetGfId);
    }

    $destLabel = formatTransferTargetLabel($pdo, $targetGfId, $targetFolder);
    $processedCount = 0;

    $stmtSel = $pdo->prepare(
        'SELECT id, user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted
         FROM files
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmtMove = $pdo->prepare(
        'UPDATE files SET user_id = :uid, group_folder_id = :gf_id, folder_path = :fp WHERE id = :id'
    );
    $stmtCopy = $pdo->prepare(
        'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
         VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, :enc)'
    );

    foreach ($rawIds as $rawId) {
        $fid = (int)trim($rawId);
        if ($fid <= 0) {
            continue;
        }
        $stmtSel->execute([':id' => $fid]);
        $file = $stmtSel->fetch();
        if (!$file) {
            continue;
        }

        $sourceGfId   = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
        $sourceFolder = (string)$file['folder_path'];

        if ($mode === 'move') {
            $canWriteSource = ((int)$file['user_id'] === $userId)
                || ($sourceGfId !== null && canUserAccessGroupFolder($pdo, $userId, $sourceGfId, true, $isAdmin))
                || canUserWriteSharedFileOrFolder($pdo, $userId, (int)$file['user_id'], $fid, $sourceFolder);
            if (!$canWriteSource) {
                continue;
            }
            if ($sourceGfId === $targetGfId && $sourceFolder === $targetFolder) {
                continue;
            }
            $newOwnerId = $targetGfId === null ? $userId : (int)$file['user_id'];
            $stmtMove->execute([
                ':uid'   => $newOwnerId,
                ':gf_id' => $targetGfId,
                ':fp'    => $targetFolder,
                ':id'    => $fid,
            ]);
            $processedCount++;
        } else {
            $authFile = getAuthorizedFileForDownload($pdo, $userId, $fid, $isAdmin);
            if (!$authFile) {
                continue;
            }
            $srcStored = basename((string)$file['filename']);
            $srcPath   = UPLOAD_DIR . $srcStored;
            if (!is_file($srcPath) || !is_readable($srcPath)) {
                continue;
            }
            $fileSize = (int)$file['file_size'];
            if ($targetGfId === null) {
                $stats = getUserStorageStats($pdo, $userId);
                if ($fileSize > $stats['free']) {
                    continue;
                }
            }
            $newStored = generateSafeStoredFilename();
            $dstPath   = UPLOAD_DIR . $newStored;
            if (!@copy($srcPath, $dstPath)) {
                continue;
            }
            @chmod($dstPath, 0640);

            $copyName = (string)$file['original_name'];
            if ($sourceGfId === $targetGfId && $sourceFolder === $targetFolder) {
                $dotPos = strrpos($copyName, '.');
                $copyName = $dotPos !== false
                    ? substr($copyName, 0, $dotPos) . ' (Kopie)' . substr($copyName, $dotPos)
                    : $copyName . ' (Kopie)';
            }

            $stmtCopy->execute([
                ':uid'   => $userId,
                ':gf_id' => $targetGfId,
                ':fn'    => $newStored,
                ':orig'  => $copyName,
                ':fp'    => $targetFolder,
                ':sz'    => $fileSize,
                ':mime'  => (string)$file['mime_type'],
                ':enc'   => (int)($file['is_encrypted'] ?? 0),
            ]);
            $processedCount++;
        }
    }

    if ($processedCount > 0) {
        setFlash(
            'success',
            sprintf(
                '%d %s in %s %s.',
                $processedCount,
                $processedCount === 1 ? 'Datei wurde' : 'Dateien wurden',
                $destLabel,
                $mode === 'move' ? 'verschoben' : 'kopiert'
            )
        );
    } else {
        setFlash('warning', 'Es wurden keine Dateien übertragen (bereits am Zielort oder fehlende Berechtigung).');
    }

    $queryParams = [];
    if ($groupFolderId !== null) {
        $queryParams['group_folder'] = (string)$groupFolderId;
    }
    if ($returnFolder !== '') {
        $queryParams['folder'] = $returnFolder;
    }
    header('Location: index.php' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : ''));
    exit;
}

// 7. ZIP-Archiv im Browser inspizieren (JSON-Dateiliste für das Vorschau-Modal)
if ($action === 'inspect_zip') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;

    if (!class_exists('ZipArchive')) {
        echo json_encode(['success' => false, 'message' => 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht aktiviert.']);
        exit;
    }

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Leseberechtigung für dieses ZIP-Archiv.']);
        exit;
    }

    $physicalPath = UPLOAD_DIR . basename((string)$file['filename']);
    if (!is_file($physicalPath) || !is_readable($physicalPath)) {
        echo json_encode(['success' => false, 'message' => 'Die physische ZIP-Datei wurde nicht gefunden.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($physicalPath) !== true) {
        echo json_encode(['success' => false, 'message' => 'Das ZIP-Archiv konnte nicht geöffnet werden (beschädigt oder verschlüsselt).']);
        exit;
    }

    $entries = [];
    $totalUncompressedBytes = 0;
    $fileCount = 0;
    $maxPreviewEntries = 300;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || !isset($stat['name'])) {
            continue;
        }
        $rawName = str_replace('\\', '/', (string)$stat['name']);
        $isDir = str_ends_with($rawName, '/');
        $uSize = max(0, (int)($stat['size'] ?? 0));
        if (!$isDir) {
            $fileCount++;
            $totalUncompressedBytes += $uSize;
        }
        if (count($entries) < $maxPreviewEntries) {
            $entries[] = [
                'name'   => $rawName,
                'size'   => formatBytes($uSize),
                'is_dir' => $isDir,
            ];
        }
    }
    $zip->close();

    echo json_encode([
        'success'          => true,
        'file_count'       => $fileCount,
        'total_size_human' => formatBytes($totalUncompressedBytes),
        'entries'          => $entries,
        'truncated'        => ($zip->numFiles > $maxPreviewEntries),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 8. ZIP-Archiv direkt auf dem Server in die Cloud entpacken
if ($action === 'extract_zip') {
    $fileId       = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $intoSub      = isset($_POST['into_subfolder']) && (int)$_POST['into_subfolder'] === 1;
    $returnFolder = isset($_POST['return_folder']) ? sanitizeFolderPathInput((string)$_POST['return_folder']) : '';

    if (!class_exists('ZipArchive')) {
        setFlash('error', 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht aktiviert.');
        header('Location: index.php');
        exit;
    }

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        setFlash('error', 'Zugriff verweigert: ZIP-Archiv nicht gefunden.');
        header('Location: index.php');
        exit;
    }

    $targetGfId   = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
    $baseFolder   = sanitizeFolderPathInput((string)$file['folder_path']);
    $canWriteHere = ((int)$file['user_id'] === $userId)
        || ($targetGfId !== null && canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin))
        || canUserWriteSharedFileOrFolder($pdo, $userId, (int)$file['user_id'], $fileId, $baseFolder);

    if (!$canWriteHere) {
        setFlash('error', 'Sie haben keine Schreibberechtigung, um dieses ZIP-Archiv hier zu entpacken.');
        header('Location: index.php');
        exit;
    }

    if ($intoSub) {
        $zipBaseName = pathinfo((string)$file['original_name'], PATHINFO_FILENAME);
        $cleanZipDir = sanitizeFolderPathInput($zipBaseName) ?: 'Entpackt';
        $baseFolder  = $baseFolder !== ''
            ? sanitizeFolderPathInput($baseFolder . '/' . $cleanZipDir)
            : $cleanZipDir;
    }

    $physicalPath = UPLOAD_DIR . basename((string)$file['filename']);
    $zip = new ZipArchive();
    if (!is_file($physicalPath) || $zip->open($physicalPath) !== true) {
        setFlash('error', 'Das ZIP-Archiv konnte zum Entpacken nicht geöffnet werden.');
        header('Location: index.php');
        exit;
    }

    // Gesamte entpackte Größe & Datei-Anzahl prüfen (Schutz vor ZIP-Bomben & Kontingent-Überschreitung)
    $maxExtractedFiles = 500;
    $totalExtractBytes = 0;
    $validEntryIndices = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || empty($stat['name'])) {
            continue;
        }
        $rawName = str_replace('\\', '/', (string)$stat['name']);
        if (str_ends_with($rawName, '/') || str_starts_with(basename($rawName), '.__') || str_starts_with($rawName, '__MACOSX/')) {
            continue;
        }
        $uSize = max(0, (int)($stat['size'] ?? 0));
        $totalExtractBytes += $uSize;
        $validEntryIndices[] = $i;
        if (count($validEntryIndices) > $maxExtractedFiles) {
            $zip->close();
            setFlash('error', sprintf('Das ZIP-Archiv enthält mehr als %d Dateien und kann aus Sicherheitsgründen nicht auf einmal entpackt werden.', $maxExtractedFiles));
            header('Location: index.php');
            exit;
        }
    }

    $targetOwnerId = $targetGfId === null ? (int)$file['user_id'] : $userId;
    if ($targetGfId === null) {
        $stats = getUserStorageStats($pdo, $targetOwnerId);
        if ($totalExtractBytes > (int)$stats['free']) {
            $zip->close();
            setFlash('error', sprintf('Nicht genügend freier Speicherplatz zum Entpacken (%s benötigt, %s frei).', formatBytes($totalExtractBytes), formatBytes((int)$stats['free'])));
            header('Location: index.php');
            exit;
        }
    }

    ensureUploadDirReady();
    if ($baseFolder !== '') {
        registerFolderHierarchy($pdo, $targetOwnerId, $baseFolder, $targetGfId);
    }

    $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $stmtIns = $pdo->prepare(
        'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
         VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, 0)'
    );

    $extractedCount = 0;
    foreach ($validEntryIndices as $idx) {
        $stat = $zip->statIndex($idx);
        $rawName = str_replace('\\', '/', (string)$stat['name']);
        $dirPart = dirname($rawName);
        $cleanSubDir = ($dirPart !== '.' && $dirPart !== '/') ? sanitizeFolderPathInput($dirPart) : '';
        $cleanFileName = sanitizeOriginalFilename(basename($rawName));

        $entryFolder = $baseFolder;
        if ($cleanSubDir !== '') {
            $entryFolder = $entryFolder !== ''
                ? sanitizeFolderPathInput($entryFolder . '/' . $cleanSubDir)
                : $cleanSubDir;
        }

        $stream = $zip->getStream($stat['name']);
        if (!is_resource($stream)) {
            continue;
        }

        $storedName = generateSafeStoredFilename();
        $destPath   = UPLOAD_DIR . $storedName;
        $outFh      = @fopen($destPath, 'wb');
        if ($outFh === false) {
            fclose($stream);
            continue;
        }

        stream_copy_to_stream($stream, $outFh);
        fclose($stream);
        fclose($outFh);
        @chmod($destPath, 0640);

        if ($entryFolder !== '') {
            registerFolderHierarchy($pdo, $targetOwnerId, $entryFolder, $targetGfId);
        }

        $actualSize = max(0, (int)@filesize($destPath));
        $detectedMime = ($finfo !== null) ? (string)($finfo->file($destPath) ?: 'application/octet-stream') : 'application/octet-stream';

        $stmtIns->execute([
            ':uid'   => $targetOwnerId,
            ':gf_id' => $targetGfId,
            ':fn'    => $storedName,
            ':orig'  => $cleanFileName,
            ':fp'    => $entryFolder,
            ':sz'    => $actualSize,
            ':mime'  => mb_substr($detectedMime, 0, 128),
        ]);
        $extractedCount++;
    }

    $zip->close();

    logAuditEvent(
        $pdo,
        'zip_extract',
        sprintf('ZIP-Archiv "%s" entpackt (%d Dateien in %s)', (string)$file['original_name'], $extractedCount, $baseFolder !== '' ? ('"' . $baseFolder . '"') : 'Hauptverzeichnis')
    );

    setFlash('success', sprintf('ZIP-Archiv "%s" wurde erfolgreich entpackt (%d %s).', (string)$file['original_name'], $extractedCount, $extractedCount === 1 ? 'Datei' : 'Dateien'));

    $queryParams = [];
    if ($targetGfId !== null) {
        $queryParams['group_folder'] = (string)$targetGfId;
    }
    $redirectFolder = $intoSub ? $baseFolder : $returnFolder;
    if ($redirectFolder !== '') {
        $queryParams['folder'] = $redirectFolder;
    }
    header('Location: index.php' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : ''));
    exit;
}

// 9. Dateiversionen & Kommentare einer Datei laden (AJAX für #fileMetaModal)
if ($action === 'file_meta') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für diese Datei.']);
        exit;
    }

    $targetGfId = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
    $baseFolder = sanitizeFolderPathInput((string)$file['folder_path']);
    $isOwner    = ((int)$file['user_id'] === $userId) || $isAdmin;
    $canWrite   = $isOwner
        || ($targetGfId !== null && canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin))
        || canUserWriteSharedFileOrFolder($pdo, $userId, (int)$file['user_id'], $fileId, $baseFolder);

    $stmtVers = $pdo->prepare(
        'SELECT id, file_size, version_note, created_at
         FROM file_versions
         WHERE file_id = :fid
         ORDER BY id DESC'
    );
    $stmtVers->execute([':fid' => $fileId]);
    $versions = [];
    foreach ($stmtVers->fetchAll() as $v) {
        $ts = strtotime((string)$v['created_at']) ?: time();
        $versions[] = [
            'id'         => (int)$v['id'],
            'size_human' => formatBytes((int)$v['file_size']),
            'note'       => (string)$v['version_note'],
            'date_fmt'   => date('d.m.Y H:i', $ts) . ' Uhr',
        ];
    }

    $stmtCom = $pdo->prepare(
        'SELECT fc.id, fc.user_id, fc.comment_text, fc.created_at, u.username
         FROM file_comments fc
         INNER JOIN users u ON u.id = fc.user_id
         WHERE fc.file_id = :fid
         ORDER BY fc.id ASC'
    );
    $stmtCom->execute([':fid' => $fileId]);
    $comments = [];
    foreach ($stmtCom->fetchAll() as $c) {
        $ts = strtotime((string)$c['created_at']) ?: time();
        $comments[] = [
            'id'         => (int)$c['id'],
            'username'   => (string)$c['username'],
            'text'       => (string)$c['comment_text'],
            'date_fmt'   => date('d.m.Y H:i', $ts) . ' Uhr',
            'can_delete' => ($isOwner || (int)$c['user_id'] === $userId),
        ];
    }

    echo json_encode([
        'success'   => true,
        'file_id'   => $fileId,
        'file_name' => (string)$file['original_name'],
        'can_write' => $canWrite,
        'is_owner'  => $isOwner,
        'versions'  => $versions,
        'comments'  => $comments,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 10. Frühere Dateiversion wiederherstellen (AJAX)
if ($action === 'restore_version') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId    = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $versionId = isset($_POST['version_id']) ? (int)$_POST['version_id'] : 0;

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden oder keine Berechtigung.']);
        exit;
    }

    $targetGfId = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
    $baseFolder = sanitizeFolderPathInput((string)$file['folder_path']);
    $canWrite   = ((int)$file['user_id'] === $userId)
        || $isAdmin
        || ($targetGfId !== null && canUserAccessGroupFolder($pdo, $userId, $targetGfId, true, $isAdmin))
        || canUserWriteSharedFileOrFolder($pdo, $userId, (int)$file['user_id'], $fileId, $baseFolder);

    if (!$canWrite) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Schreibberechtigung zum Wiederherstellen einer Version.']);
        exit;
    }

    $stmtVer = $pdo->prepare('SELECT * FROM file_versions WHERE id = :vid AND file_id = :fid LIMIT 1');
    $stmtVer->execute([':vid' => $versionId, ':fid' => $fileId]);
    $verRow = $stmtVer->fetch();

    if (!$verRow) {
        echo json_encode(['success' => false, 'message' => 'Die gewählte Dateiversion wurde nicht gefunden.']);
        exit;
    }

    $verPath = UPLOAD_DIR . basename((string)$verRow['stored_filename']);
    if (!is_file($verPath) || !is_readable($verPath)) {
        echo json_encode(['success' => false, 'message' => 'Die physische Versionsdatei ist nicht mehr vorhanden.']);
        exit;
    }

    // Aktuellen Stand vor dem Zurückrollen ebenfalls sichern
    createFileVersionSnapshot($pdo, $file, 'Stand vor Wiederherstellung von #' . $versionId);

    $currentPath = UPLOAD_DIR . basename((string)$file['filename']);
    if (!@copy($verPath, $currentPath)) {
        echo json_encode(['success' => false, 'message' => 'Fehler beim Wiederherstellen der Dateiversion.']);
        exit;
    }
    @chmod($currentPath, 0640);
    $restoredSize = (int)(@filesize($currentPath) ?: (int)$verRow['file_size']);

    $pdo->prepare(
        'UPDATE files SET file_size = :sz, mime_type = :mime, upload_date = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([
        ':sz'   => $restoredSize,
        ':mime' => (string)$verRow['mime_type'],
        ':id'   => $fileId,
    ]);

    logAuditEvent($pdo, 'version_restore', sprintf('Dateiversion #%d für „%s“ wiederhergestellt', $versionId, (string)$file['original_name']));

    echo json_encode([
        'success' => true,
        'message' => 'Die ausgewählte Vorgängerversion wurde erfolgreich wiederhergestellt.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 11. Einzelne Dateiversion löschen (AJAX)
if ($action === 'delete_version') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId    = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $versionId = isset($_POST['version_id']) ? (int)$_POST['version_id'] : 0;

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file || (((int)$file['user_id'] !== $userId) && !$isAdmin)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Nur der Eigentümer darf alte Dateiversionen löschen.']);
        exit;
    }

    $stmtVer = $pdo->prepare('SELECT * FROM file_versions WHERE id = :vid AND file_id = :fid LIMIT 1');
    $stmtVer->execute([':vid' => $versionId, ':fid' => $fileId]);
    $verRow = $stmtVer->fetch();

    if ($verRow) {
        $verPath = UPLOAD_DIR . basename((string)$verRow['stored_filename']);
        if (is_file($verPath)) {
            @unlink($verPath);
        }
        $pdo->prepare('DELETE FROM file_versions WHERE id = :id')->execute([':id' => $versionId]);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// 12. Datei-Kommentar hinzufügen (AJAX)
if ($action === 'add_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId      = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $commentText = mb_substr(trim((string)($_POST['comment_text'] ?? '')), 0, 1000);

    if ($commentText === '') {
        echo json_encode(['success' => false, 'message' => 'Bitte geben Sie einen Kommentartext ein.']);
        exit;
    }

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für diese Datei.']);
        exit;
    }

    $stmtIns = $pdo->prepare(
        'INSERT INTO file_comments (file_id, user_id, comment_text) VALUES (:fid, :uid, :txt)'
    );
    $stmtIns->execute([
        ':fid' => $fileId,
        ':uid' => $userId,
        ':txt' => $commentText,
    ]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// 13. Datei-Kommentar löschen (AJAX)
if ($action === 'delete_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $fileId    = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $commentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;

    $file = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
        exit;
    }

    $stmtCom = $pdo->prepare('SELECT * FROM file_comments WHERE id = :cid AND file_id = :fid LIMIT 1');
    $stmtCom->execute([':cid' => $commentId, ':fid' => $fileId]);
    $cRow = $stmtCom->fetch();

    if ($cRow && ((int)$cRow['user_id'] === $userId || (int)$file['user_id'] === $userId || $isAdmin)) {
        $pdo->prepare('DELETE FROM file_comments WHERE id = :id')->execute([':id' => $commentId]);
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// 14a. Live-Fortschrittsabfrage für große Server-zu-Server Cloud-Importe (verhindert 504 Timeouts)
if ($action === 'import_cloud_status') {
    session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    $jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['job_id'] ?? ''));
    if ($jobId === '') {
        echo json_encode(['ok' => false, 'phase' => 'unknown'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $statusFile = UPLOAD_DIR . 'tmp_import_status_' . $userId . '_' . $jobId . '.json';
    if (!is_file($statusFile)) {
        echo json_encode(['ok' => true, 'phase' => 'starting', 'downloaded_bytes' => 0, 'total_bytes' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rawJson = @file_get_contents($statusFile);
    $data    = is_string($rawJson) ? json_decode($rawJson, true) : null;
    if (!is_array($data)) {
        echo json_encode(['ok' => true, 'phase' => 'running'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (in_array((string)($data['phase'] ?? ''), ['done', 'error'], true)) {
        @unlink($statusFile);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 14. Direkt-Import per Dropbox-Freigabelink / Cloud-URL (Server-zu-Server Migration inkl. Auto-Entpacken & Hintergrund-Fortschritt)
if ($action === 'import_cloud_url') {
    $rawUrl         = trim((string)($_POST['import_url'] ?? ''));
    $importPassword = (string)($_POST['import_password'] ?? '');
    $parentFolder   = isset($_POST['parent_folder']) ? sanitizeFolderPathInput((string)$_POST['parent_folder']) : '';
    $customSub      = isset($_POST['target_subfolder']) ? sanitizeFolderPathInput((string)$_POST['target_subfolder']) : '';
    $groupFolderId  = isset($_POST['group_folder_id']) && (int)$_POST['group_folder_id'] > 0
        ? (int)$_POST['group_folder_id']
        : null;
    $autoUnpack     = isset($_POST['auto_unpack']) && (string)$_POST['auto_unpack'] === '1';
    $isAjax         = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
    $jobId          = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['job_id'] ?? ''));

    // Session-Sperre sofort freigeben & Abbruch bei Browser-/Proxy-Timeout (504) verhindern!
    session_write_close();
    @ignore_user_abort(true);
    @set_time_limit(1800);

    $returnQuery = [];
    if ($groupFolderId !== null) {
        $returnQuery['group_folder'] = (string)$groupFolderId;
    }
    if ($parentFolder !== '') {
        $returnQuery['folder'] = $parentFolder;
    }
    $redirectUrl = 'index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : '');

    ensureUploadDirReady();
    $statusFile = ($jobId !== '') ? (UPLOAD_DIR . 'tmp_import_status_' . $userId . '_' . $jobId . '.json') : '';

    $writeProgress = static function (array $payload) use ($statusFile): void {
        if ($statusFile === '') {
            return;
        }
        $payload['ok'] = ($payload['phase'] ?? '') !== 'error';
        $payload['updated_at'] = time();
        @file_put_contents($statusFile, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    };

    $finishImport = static function (bool $success, string $message, string $targetRedirect) use ($isAjax, $writeProgress): never {
        $writeProgress([
            'phase'        => $success ? 'done' : 'error',
            'message'      => $message,
            'redirect_url' => $targetRedirect,
        ]);
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok'           => $success,
                'phase'        => $success ? 'done' : 'error',
                'message'      => $message,
                'redirect_url' => $targetRedirect,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        @session_start();
        setFlash($success ? 'success' : 'error', $message);
        session_write_close();
        header('Location: ' . $targetRedirect);
        exit;
    };

    // Alte verwaiste Status-/Temp-Dateien (> 2 Stunden) aufräumen
    foreach (glob(UPLOAD_DIR . 'tmp_import_*') ?: [] as $oldTmp) {
        if (is_file($oldTmp) && (time() - (int)@filemtime($oldTmp)) > 7200) {
            @unlink($oldTmp);
        }
    }

    if ($rawUrl === '' || !preg_match('#^https://#i', $rawUrl)) {
        $finishImport(false, 'Bitte gib einen gültigen HTTPS-Freigabelink (z. B. von Dropbox oder Nextcloud) ein.', $redirectUrl);
    }

    if ($groupFolderId !== null && !canUserAccessGroupFolder($pdo, $userId, $groupFolderId, true, $isAdmin)) {
        $finishImport(false, 'Keine Schreibberechtigung für diesen Gruppen-Ordner.', 'index.php');
    }

    // 1. Dropbox- / Google-Drive- / Nextcloud-Freigabelinks automatisch in Direkt-Download-URLs umwandeln
    $downloadUrl      = $rawUrl;
    $parsedInit       = parse_url($downloadUrl);
    $initHost         = strtolower((string)($parsedInit['host'] ?? ''));
    $ncShareToken     = '';
    $ncBaseUrl        = '';

    if ($initHost === 'www.dropbox.com' || $initHost === 'dropbox.com' || str_ends_with($initHost, '.dropbox.com')) {
        // Dropbox: dl=0 durch dl=1 ersetzen oder ?dl=1 / &dl=1 anhängen (liefert Dateien direkt bzw. Ordner als .zip!)
        if (preg_match('/([?&])dl=0\b/i', $downloadUrl)) {
            $downloadUrl = (string)preg_replace('/([?&])dl=0\b/i', '$1dl=1', $downloadUrl);
        } elseif (!preg_match('/([?&])dl=1\b/i', $downloadUrl)) {
            $downloadUrl .= (str_contains($downloadUrl, '?') ? '&' : '?') . 'dl=1';
        }
    } elseif (($initHost === 'drive.google.com' || $initHost === 'docs.google.com') && preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $downloadUrl, $mGDrive)) {
        $downloadUrl = 'https://drive.google.com/uc?export=download&id=' . $mGDrive[1];
    } elseif (preg_match('#^(https://[^/]+(?:/[^/]+)*?)/(?:index\.php/)?s/([A-Za-z0-9]+)(?:/download)?/?$#i', $downloadUrl, $mNc)) {
        $ncBaseUrl    = rtrim($mNc[1], '/');
        $ncShareToken = $mNc[2];
        // Bei passwortgeschützten Nextcloud-Freigaben nutzt Nextcloud die öffentliche WebDAV-Schnittstelle mit Token als Benutzername
        if ($importPassword !== '') {
            $downloadUrl = $ncBaseUrl . '/public.php/webdav';
        } else {
            $downloadUrl = $ncBaseUrl . '/s/' . $ncShareToken . '/download';
        }
    }

    // 2. SSRF-Schutz: Nur öffentliche IPv4-Ziele auf HTTPS (Port 443) zulassen
    $validatePublicHttpsUrl = static function (string $checkUrl): bool {
        $parts = parse_url($checkUrl);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host   = strtolower((string)($parts['host'] ?? ''));
        $port   = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($scheme !== 'https' || $host === '' || $port !== 443) {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }
        $ips = @gethostbynamel($host);
        if (!is_array($ips) || empty($ips)) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    };

    if (!$validatePublicHttpsUrl($downloadUrl)) {
        $finishImport(false, 'Der angegebene Link verweist auf kein gültiges öffentliches HTTPS-Ziel.', $redirectUrl);
    }

    $stats = getUserStorageStats($pdo, $userId);
    $freeBytes = (int)$stats['free'];
    if ($groupFolderId === null && $freeBytes < 65536) {
        $finishImport(false, 'Dein Cloud-Speicherplatz ist erschöpft.', $redirectUrl);
    }

    $tmpFile = UPLOAD_DIR . 'tmp_import_' . bin2hex(random_bytes(12)) . '.part';
    $fhTmp   = @fopen($tmpFile, 'wb');
    if ($fhTmp === false) {
        $finishImport(false, 'Temporäre Datei für den Cloud-Import konnte nicht angelegt werden.', $redirectUrl);
    }

    $downloadedBytes   = 0;
    $expectedTotal     = 0;
    $quotaExceeded     = false;
    $headerFilename    = '';
    $responseMime      = '';
    $lastProgressWrite = 0.0;
    $maxAllowedBytes   = $groupFolderId === null ? $freeBytes : (2048 * 1024 * 1024);

    $writeProgress([
        'phase'            => 'downloading',
        'downloaded_bytes' => 0,
        'total_bytes'      => 0,
        'filename'         => '',
    ]);

    // 3. Server-zu-Server-Download per cURL (bis zu 30 Minuten Timeout für große Archive)
    $ch = curl_init($downloadUrl);
    $curlOpts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MiniCloud-Migrator/1.0)',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headerFilename, &$responseMime, &$expectedTotal): int {
            $trimLine = trim($headerLine);
            if (stripos($trimLine, 'Content-Disposition:') === 0) {
                if (preg_match("/filename\\*=UTF-8''([^;\\r\\n]+)/i", $trimLine, $mUtf8)) {
                    $headerFilename = rawurldecode(trim($mUtf8[1], " \"'"));
                } elseif (preg_match('/filename="([^"]+)"/i', $trimLine, $mQuoted)) {
                    $headerFilename = trim($mQuoted[1]);
                } elseif (preg_match('/filename=([^;\r\n]+)/i', $trimLine, $mPlain)) {
                    $headerFilename = trim($mPlain[1], " \"'");
                }
            } elseif (stripos($trimLine, 'Content-Type:') === 0) {
                $parts = explode(';', substr($trimLine, 13), 2);
                $responseMime = strtolower(trim($parts[0]));
            } elseif (stripos($trimLine, 'Content-Length:') === 0) {
                $cl = (int)trim(substr($trimLine, 15));
                if ($cl > 0) {
                    $expectedTotal = $cl;
                }
            }
            return strlen($headerLine);
        },
        CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use ($fhTmp, &$downloadedBytes, &$expectedTotal, &$headerFilename, &$quotaExceeded, &$lastProgressWrite, $maxAllowedBytes, $writeProgress): int {
            $len = strlen($chunk);
            if (($downloadedBytes + $len) > $maxAllowedBytes) {
                $quotaExceeded = true;
                return 0; // bricht cURL sofort ab
            }
            $written = fwrite($fhTmp, $chunk);
            if ($written === false) {
                return 0;
            }
            $downloadedBytes += $written;
            $now = microtime(true);
            if (($now - $lastProgressWrite) >= 0.45) {
                $lastProgressWrite = $now;
                $writeProgress([
                    'phase'            => 'downloading',
                    'downloaded_bytes' => $downloadedBytes,
                    'total_bytes'      => $expectedTotal,
                    'filename'         => $headerFilename,
                ]);
            }
            return $written;
        },
    ];

    if ($importPassword !== '') {
        $curlOpts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $curlOpts[CURLOPT_USERPWD]  = ($ncShareToken !== '' ? $ncShareToken : 'anonymous') . ':' . $importPassword;
    }

    curl_setopt_array($ch, $curlOpts);

    $curlOk   = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    fclose($fhTmp);

    if ($quotaExceeded) {
        @unlink($tmpFile);
        $finishImport(false, sprintf('Der Import übersteigt deinen freien Cloud-Speicherplatz (%s frei).', formatBytes($freeBytes)), $redirectUrl);
    }

    if ($curlOk === false || $httpCode < 200 || $httpCode >= 400 || $downloadedBytes <= 0) {
        @unlink($tmpFile);
        if ($httpCode === 401 || $httpCode === 403) {
            $finishImport(false, 'Zugriff verweigert (HTTP ' . $httpCode . '). Bitte prüfe das Freigabe-Passwort bzw. die Berechtigung des Links.', $redirectUrl);
        }
        $finishImport(false, 'Download vom Quell-Link fehlgeschlagen (HTTP ' . $httpCode . ($curlErr !== '' ? ': ' . $curlErr : '') . '). Prüfe bitte, ob der Freigabelink gültig ist.', $redirectUrl);
    }

    // Prüfen, ob versehentlich eine HTML-Anmeldeseite statt der Datei geladen wurde
    $firstBytes = (string)@file_get_contents($tmpFile, false, null, 0, 512);
    $isZipMagic = str_starts_with($firstBytes, "PK\x03\x04") || str_starts_with($firstBytes, "PK\x05\x06");
    if (!$isZipMagic && str_contains($responseMime, 'text/html') && preg_match('/<!DOCTYPE\s+html|<html/i', $firstBytes)) {
        @unlink($tmpFile);
        $finishImport(false, 'Der Link lieferte eine HTML-Webseite (z. B. Passwortabfrage) statt einer Datei zurück. Bitte gib ggf. das Passwort an oder stelle sicher, dass der Link für „Jeder mit diesem Link“ freigegeben ist.', $redirectUrl);
    }

    // Dateinamen ermitteln
    if ($headerFilename === '') {
        $urlPath = (string)(parse_url($rawUrl, PHP_URL_PATH) ?? '');
        $urlBase = rawurldecode(basename($urlPath));
        if ($urlBase !== '' && $urlBase !== '/' && !in_array(strtolower($urlBase), ['s', 'fo', 'fi', 'view', 'download', 'uc', 'webdav'], true)) {
            $headerFilename = $urlBase;
        } else {
            $headerFilename = $isZipMagic ? ('Cloud_Import_' . date('Y-m-d_Hi') . '.zip') : ('Import_' . date('Y-m-d_Hi'));
        }
    }
    $cleanOrigName = sanitizeOriginalFilename($headerFilename);
    if ($isZipMagic && strtolower(pathinfo($cleanOrigName, PATHINFO_EXTENSION)) !== 'zip') {
        $cleanOrigName .= '.zip';
    }

    // Zielordner bestimmen
    $targetBaseFolder = $parentFolder;
    if ($customSub !== '') {
        $targetBaseFolder = $targetBaseFolder !== ''
            ? sanitizeFolderPathInput($targetBaseFolder . '/' . $customSub)
            : $customSub;
    }

    // 4. Wenn es ein ZIP-Archiv ist (z. B. ein kompletter Dropbox-/Nextcloud-Ordner) und Auto-Entpacken aktiv ist -> direkt entpacken!
    if ($isZipMagic && $autoUnpack && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) === true) {
            if ($importPassword !== '' && method_exists($zip, 'setPassword')) {
                @$zip->setPassword($importPassword);
            }

            // Falls kein eigener Unterordner angegeben wurde und das ZIP-Archiv einen sprechenden Namen hat:
            if ($customSub === '') {
                $zipTitle = sanitizeFolderPathInput(pathinfo($cleanOrigName, PATHINFO_FILENAME));
                if ($zipTitle !== '' && !str_starts_with($zipTitle, 'Cloud_Import_') && !str_starts_with($zipTitle, 'Dropbox_Import_')) {
                    $targetBaseFolder = $targetBaseFolder !== ''
                        ? sanitizeFolderPathInput($targetBaseFolder . '/' . $zipTitle)
                        : $zipTitle;
                }
            }

            $maxExtractedFiles = 5000;
            $totalExtractBytes = 0;
            $validEntryIndices = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || empty($stat['name'])) {
                    continue;
                }
                $rawEntryName = str_replace('\\', '/', (string)$stat['name']);
                $baseEntry    = basename($rawEntryName);
                if (str_ends_with($rawEntryName, '/') || str_starts_with($baseEntry, '.__') || $baseEntry === '.DS_Store' || str_starts_with($rawEntryName, '__MACOSX/')) {
                    continue;
                }
                $uSize = max(0, (int)($stat['size'] ?? 0));
                $totalExtractBytes += $uSize;
                $validEntryIndices[] = $i;
                if (count($validEntryIndices) >= $maxExtractedFiles) {
                    break;
                }
            }

            if ($groupFolderId === null && $totalExtractBytes > $freeBytes) {
                $zip->close();
                @unlink($tmpFile);
                $finishImport(false, sprintf('Der entpackte Ordner (%s) ist größer als dein freier Speicherplatz (%s).', formatBytes($totalExtractBytes), formatBytes($freeBytes)), $redirectUrl);
            }

            if ($targetBaseFolder !== '') {
                registerFolderHierarchy($pdo, $userId, $targetBaseFolder, $groupFolderId);
            }

            $totalValidFiles = count($validEntryIndices);
            $writeProgress([
                'phase'            => 'unpacking',
                'downloaded_bytes' => $downloadedBytes,
                'total_bytes'      => $downloadedBytes,
                'extracted_files'  => 0,
                'total_files'      => $totalValidFiles,
                'filename'         => $cleanOrigName,
            ]);

            $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
            $stmtIns = $pdo->prepare(
                'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
                 VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, 0)'
            );

            $extractedCount = 0;
            $registeredDirs = [];
            foreach ($validEntryIndices as $idx) {
                $stat    = $zip->statIndex($idx);
                $rawName = str_replace('\\', '/', (string)$stat['name']);
                $dirPart = dirname($rawName);
                $cleanSubDir   = ($dirPart !== '.' && $dirPart !== '/') ? sanitizeFolderPathInput($dirPart) : '';
                $cleanFileName = sanitizeOriginalFilename(basename($rawName));

                $entryFolder = $targetBaseFolder;
                if ($cleanSubDir !== '') {
                    $entryFolder = $entryFolder !== ''
                        ? sanitizeFolderPathInput($entryFolder . '/' . $cleanSubDir)
                        : $cleanSubDir;
                }

                $stream = $zip->getStream($stat['name']);
                if (!is_resource($stream)) {
                    continue;
                }

                $storedName = generateSafeStoredFilename();
                $destPath   = UPLOAD_DIR . $storedName;
                $outFh      = @fopen($destPath, 'wb');
                if ($outFh === false) {
                    fclose($stream);
                    continue;
                }

                stream_copy_to_stream($stream, $outFh);
                fclose($stream);
                fclose($outFh);
                @chmod($destPath, 0640);

                if ($entryFolder !== '' && !isset($registeredDirs[$entryFolder])) {
                    registerFolderHierarchy($pdo, $userId, $entryFolder, $groupFolderId);
                    $registeredDirs[$entryFolder] = true;
                }

                $actualSize   = max(0, (int)@filesize($destPath));
                $detectedMime = ($finfo !== null) ? (string)($finfo->file($destPath) ?: 'application/octet-stream') : 'application/octet-stream';

                $stmtIns->execute([
                    ':uid'   => $userId,
                    ':gf_id' => $groupFolderId,
                    ':fn'    => $storedName,
                    ':orig'  => $cleanFileName,
                    ':fp'    => $entryFolder,
                    ':sz'    => $actualSize,
                    ':mime'  => mb_substr($detectedMime, 0, 128),
                ]);
                $extractedCount++;

                $now = microtime(true);
                if (($now - $lastProgressWrite) >= 0.45 || $extractedCount === $totalValidFiles) {
                    $lastProgressWrite = $now;
                    $writeProgress([
                        'phase'            => 'unpacking',
                        'downloaded_bytes' => $downloadedBytes,
                        'total_bytes'      => $downloadedBytes,
                        'extracted_files'  => $extractedCount,
                        'total_files'      => $totalValidFiles,
                        'filename'         => $cleanOrigName,
                    ]);
                }
            }

            $zip->close();
            @unlink($tmpFile);

            logAuditEvent(
                $pdo,
                'cloud_import',
                sprintf('Dropbox-/Cloud-Ordner "%s" importiert & entpackt (%d Dateien nach "%s")', $cleanOrigName, $extractedCount, $targetBaseFolder !== '' ? $targetBaseFolder : 'Hauptverzeichnis')
            );

            if ($targetBaseFolder !== '') {
                $returnQuery['folder'] = $targetBaseFolder;
            }
            $finalRedirect = 'index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : '');
            $msg = sprintf(
                '☁️✅ Cloud-Import abgeschlossen: %d %s (%s) wurden direkt samt Ordnerstruktur nach „%s“ übertragen!',
                $extractedCount,
                $extractedCount === 1 ? 'Datei' : 'Dateien',
                formatBytes($totalExtractBytes),
                $targetBaseFolder !== '' ? $targetBaseFolder : 'Meine Dateien'
            );
            @session_start();
            setFlash('success', $msg);
            session_write_close();
            $finishImport(true, $msg, $finalRedirect);
        }
    }

    // 5. Einzelne Datei (oder unentpacktes ZIP) direkt speichern
    $storedName = generateSafeStoredFilename();
    $destPath   = UPLOAD_DIR . $storedName;
    if (!@rename($tmpFile, $destPath)) {
        if (!@copy($tmpFile, $destPath)) {
            @unlink($tmpFile);
            $finishImport(false, 'Importierte Datei konnte nicht gespeichert werden.', $redirectUrl);
        }
        @unlink($tmpFile);
    }
    @chmod($destPath, 0640);

    if ($targetBaseFolder !== '') {
        registerFolderHierarchy($pdo, $userId, $targetBaseFolder, $groupFolderId);
    }

    $finfo        = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $actualSize   = max(0, (int)@filesize($destPath));
    $detectedMime = ($finfo !== null) ? (string)($finfo->file($destPath) ?: 'application/octet-stream') : 'application/octet-stream';

    $stmtIns = $pdo->prepare(
        'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
         VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, 0)'
    );
    $stmtIns->execute([
        ':uid'   => $userId,
        ':gf_id' => $groupFolderId,
        ':fn'    => $storedName,
        ':orig'  => $cleanOrigName,
        ':fp'    => $targetBaseFolder,
        ':sz'    => $actualSize,
        ':mime'  => mb_substr($detectedMime, 0, 128),
    ]);

    logAuditEvent(
        $pdo,
        'cloud_import',
        sprintf('Datei "%s" (%s) per Cloud-Link importiert', $cleanOrigName, formatBytes($actualSize))
    );

    if ($targetBaseFolder !== '') {
        $returnQuery['folder'] = $targetBaseFolder;
    }
    $finalRedirect = 'index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : '');
    $msg = sprintf('☁️✅ Datei „%s“ (%s) wurde direkt in deine MiniCloud importiert!', $cleanOrigName, formatBytes($actualSize));
    @session_start();
    setFlash('success', $msg);
    session_write_close();
    $finishImport(true, $msg, $finalRedirect);
}

header('Location: index.php');
exit;
