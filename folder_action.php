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

header('Location: index.php');
exit;
