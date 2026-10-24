<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Ungültige Anfrage-Methode.');
    header('Location: index.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
    header('Location: index.php');
    exit;
}

$userId       = (int)$_SESSION['user_id'];
$isAdmin      = isAdmin();
$pdo          = getDB();
$returnFolder = isset($_POST['return_folder']) ? extractSanitizedFolderPath(trim((string)$_POST['return_folder']) . '/placeholder') : '';
$returnGfId   = isset($_POST['return_group_folder']) ? (int)$_POST['return_group_folder'] : 0;

// Option A: Kompletten eigenen Ordner löschen (Dateien wandern für 30 Tage in den Papierkorb)
if (isset($_POST['delete_folder']) && is_string($_POST['delete_folder']) && trim($_POST['delete_folder']) !== '') {
    $folderToDelete = extractSanitizedFolderPath(trim($_POST['delete_folder']) . '/placeholder');
    if ($folderToDelete === '') {
        setFlash('error', 'Ungültiger Ordnername.');
        header('Location: index.php');
        exit;
    }

    // Dateien im Ordner in den Papierkorb verschieben (deleted_at = NOW())
    $stmtTrashFiles = $pdo->prepare(
        'UPDATE files
         SET deleted_at = NOW()
         WHERE user_id = :user_id AND group_folder_id IS NULL AND deleted_at IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)'
    );
    $stmtTrashFiles->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folderToDelete,
        ':folder_prefix' => $folderToDelete . '/%',
    ]);
    $trashedCount = $stmtTrashFiles->rowCount();

    // Ordner-Einträge aus user_folders sowie Ordner-Freigaben entfernen
    $stmtDeleteUf = $pdo->prepare(
        'DELETE FROM user_folders
         WHERE user_id = :user_id AND group_folder_id IS NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)'
    );
    $stmtDeleteUf->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folderToDelete,
        ':folder_prefix' => $folderToDelete . '/%',
    ]);
    $deletedUfCount = $stmtDeleteUf->rowCount();

    $pdo->prepare(
        'DELETE FROM shares
         WHERE user_id = :user_id AND folder_path IS NOT NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)'
    )->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folderToDelete,
        ':folder_prefix' => $folderToDelete . '/%',
    ]);

    $pdo->prepare(
        'DELETE FROM user_shares
         WHERE owner_id = :user_id AND folder_path IS NOT NULL
           AND (folder_path = :folder_exact OR folder_path LIKE :folder_prefix)'
    )->execute([
        ':user_id'       => $userId,
        ':folder_exact'  => $folderToDelete,
        ':folder_prefix' => $folderToDelete . '/%',
    ]);

    $returnFolder = sanitizeFolderPathInput((string)($_POST['return_folder'] ?? ''));
    $redirectUrl = $returnFolder !== '' && $returnFolder !== $folderToDelete && !str_starts_with($returnFolder, $folderToDelete . '/')
        ? 'index.php?folder=' . rawurlencode($returnFolder)
        : 'index.php';

    if ($trashedCount === 0 && $deletedUfCount === 0) {
        setFlash('error', 'Ordner wurde nicht gefunden oder Zugriff verweigert.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($trashedCount > 0) {
        setFlash(
            'success',
            sprintf(
                'Ordner "%s" wurde aufgelöst und %d enthaltene Datei(en) wurden in den Papierkorb verschoben (%d Tage Aufbewahrung).',
                $folderToDelete,
                $trashedCount,
                TRASH_RETENTION_DAYS
            )
        );
    } else {
        setFlash('success', sprintf('Leerer Ordner "%s" wurde gelöscht.', $folderToDelete));
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// Option B: Einzelne Datei in den Papierkorb verschieben (Soft-Delete mit 30 Tagen Aufbewahrung)
$fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
if ($fileId <= 0) {
    setFlash('error', 'Ungültige Datei-ID.');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, user_id, group_folder_id, filename, original_name FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

$canDelete = false;
if ($file) {
    if ((int)$file['user_id'] === $userId) {
        $canDelete = true;
    } elseif (!empty($file['group_folder_id']) && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], true, $isAdmin)) {
        $canDelete = true;
    }
}

if (!$file || !$canDelete) {
    http_response_code(403);
    setFlash('error', 'Zugriff verweigert: Datei nicht gefunden oder keine Berechtigung zum Löschen.');
    header('Location: index.php');
    exit;
}

$stmtTrash = $pdo->prepare('UPDATE files SET deleted_at = NOW() WHERE id = :id');
$stmtTrash->execute([':id' => $fileId]);

setFlash(
    'success',
    sprintf(
        'Die Datei "%s" wurde in den Papierkorb verschoben und wird dort noch %d Tage aufbewahrt.',
        (string)$file['original_name'],
        TRASH_RETENTION_DAYS
    )
);

$queryParams = [];
if ($returnGfId > 0) {
    $queryParams['group_folder'] = (string)$returnGfId;
}
if ($returnFolder !== '') {
    $queryParams['folder'] = $returnFolder;
}
$redirectUrl = 'index.php' . (!empty($queryParams) ? '?' . http_build_query($queryParams) : '');
header('Location: ' . $redirectUrl);
exit;
