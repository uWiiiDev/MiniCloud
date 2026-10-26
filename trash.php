<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Strikter Session-Schutz
requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

// Abgelaufene Dateien (> 30 Tage) automatisch bereinigen
purgeExpiredTrashFiles($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: trash.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Einzelne Datei wiederherstellen
    if ($action === 'restore') {
        $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
        $stmt = $pdo->prepare('SELECT id, user_id, group_folder_id, original_name, folder_path FROM files WHERE id = :id AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $fileId]);
        $file = $stmt->fetch();

        if ($file && (int)$file['user_id'] === $userId) {
            $pdo->prepare('UPDATE files SET deleted_at = NULL WHERE id = :id')->execute([':id' => $fileId]);
            $fp = (string)$file['folder_path'];
            $gfId = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
            if ($fp !== '') {
                registerFolderHierarchy($pdo, $userId, $fp, $gfId);
            }
            setFlash('success', sprintf('Datei "%s" wurde erfolgreich wiederhergestellt.', (string)$file['original_name']));
        } else {
            setFlash('error', 'Datei nicht gefunden oder keine Berechtigung.');
        }
        header('Location: trash.php');
        exit;
    }

    // 2. Einzelne Datei endgültig löschen
    if ($action === 'delete_permanently') {
        $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
        $stmt = $pdo->prepare('SELECT id, user_id, filename, original_name FROM files WHERE id = :id AND deleted_at IS NOT NULL LIMIT 1');
        $stmt->execute([':id' => $fileId]);
        $file = $stmt->fetch();

        if ($file && (int)$file['user_id'] === $userId) {
            deletePhysicalFileVersions($pdo, (int)$file['id']);
            $physicalPath = UPLOAD_DIR . basename((string)$file['filename']);
            if (is_file($physicalPath)) {
                @unlink($physicalPath);
            }
            $pdo->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $fileId]);
            setFlash('success', sprintf('Datei "%s" wurde endgültig gelöscht.', (string)$file['original_name']));
        } else {
            setFlash('error', 'Datei nicht gefunden oder keine Berechtigung.');
        }
        header('Location: trash.php');
        exit;
    }

    // 3. Alle Dateien im Papierkorb wiederherstellen
    if ($action === 'restore_all') {
        $stmtAll = $pdo->prepare('SELECT id, group_folder_id, folder_path FROM files WHERE user_id = :uid AND deleted_at IS NOT NULL');
        $stmtAll->execute([':uid' => $userId]);
        $rows = $stmtAll->fetchAll();

        foreach ($rows as $r) {
            $fp = (string)$r['folder_path'];
            $gfId = !empty($r['group_folder_id']) ? (int)$r['group_folder_id'] : null;
            if ($fp !== '') {
                registerFolderHierarchy($pdo, $userId, $fp, $gfId);
            }
        }

        $stmtRestore = $pdo->prepare('UPDATE files SET deleted_at = NULL WHERE user_id = :uid AND deleted_at IS NOT NULL');
        $stmtRestore->execute([':uid' => $userId]);

        setFlash('success', sprintf('%d Datei(en) wurden aus dem Papierkorb wiederhergestellt.', count($rows)));
        header('Location: trash.php');
        exit;
    }

    // 4. Papierkorb komplett leeren (endgültig löschen)
    if ($action === 'empty_trash') {
        $stmtAll = $pdo->prepare('SELECT id, filename FROM files WHERE user_id = :uid AND deleted_at IS NOT NULL');
        $stmtAll->execute([':uid' => $userId]);
        $rows = $stmtAll->fetchAll();

        foreach ($rows as $r) {
            deletePhysicalFileVersions($pdo, (int)$r['id']);
            $physicalPath = UPLOAD_DIR . basename((string)$r['filename']);
            if (is_file($physicalPath)) {
                @unlink($physicalPath);
            }
        }

        $pdo->prepare('DELETE FROM files WHERE user_id = :uid AND deleted_at IS NOT NULL')->execute([':uid' => $userId]);
        setFlash('success', sprintf('Papierkorb geleert (%d Datei(en) endgültig gelöscht).', count($rows)));
        header('Location: trash.php');
        exit;
    }
}

// Alle im Papierkorb befindlichen Dateien des aktuellen Benutzers laden
$stmtTrash = $pdo->prepare(
    'SELECT id, filename, original_name, folder_path, file_size, mime_type, upload_date, deleted_at,
            GREATEST(0, :retention_days - DATEDIFF(NOW(), deleted_at)) AS days_left
     FROM files
     WHERE user_id = :user_id AND deleted_at IS NOT NULL
     ORDER BY deleted_at DESC, id DESC'
);
$stmtTrash->execute([
    ':retention_days' => TRASH_RETENTION_DAYS,
    ':user_id'        => $userId,
]);
$trashFiles = $stmtTrash->fetchAll();

$pageTitle = 'Papierkorb';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <svg class="w-6 h-6 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            <span>Papierkorb (<?= count($trashFiles) ?>)</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            Gelöschte Dateien werden hier noch <strong><?= (int)TRASH_RETENTION_DAYS ?> Tage</strong> aufbewahrt, bevor sie automatisch endgültig entfernt werden.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <a
            href="index.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors"
        >
            &larr; Zurück zu meinen Dateien
        </a>

        <?php if (!empty($trashFiles)): ?>
            <form action="trash.php" method="post" class="inline">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="restore_all">
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-semibold shadow-2xs transition-colors cursor-pointer"
                >
                    <span>Alle wiederherstellen</span>
                </button>
            </form>

            <form action="trash.php" method="post" class="inline" onsubmit="return confirm('Möchten Sie den gesamten Papierkorb jetzt unwiderruflich leeren?');">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="empty_trash">
                <button
                    type="submit"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs sm:text-sm font-semibold shadow-2xs transition-colors cursor-pointer"
                >
                    <span>Papierkorb leeren</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
    <?php if (empty($trashFiles)): ?>
        <div class="p-12 text-center">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Ihr Papierkorb ist leer</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                Wenn Sie Dateien löschen, werden sie hier für <?= (int)TRASH_RETENTION_DAYS ?> Tage zur Wiederherstellung aufbewahrt.
            </p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3.5 px-3 sm:px-6">Dateiname</th>
                        <th class="py-3.5 px-4 hidden md:table-cell">Ursprünglicher Ordner</th>
                        <th class="py-3.5 px-4 hidden sm:table-cell">Größe</th>
                        <th class="py-3.5 px-4 hidden sm:table-cell">Aufbewahrung</th>
                        <th class="py-3.5 px-3 sm:px-6 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">
                    <?php foreach ($trashFiles as $tf): ?>
                        <?php
                        $tfId = (int)$tf['id'];
                        $badge = getFileTypeBadge((string)$tf['original_name'], (string)$tf['mime_type']);
                        $delDate = date('d.m.Y H:i', strtotime((string)$tf['deleted_at']));
                        $daysLeft = (int)$tf['days_left'];
                        ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                            <td class="py-3.5 px-3 sm:px-6">
                                <div class="flex items-center gap-2.5 sm:gap-3">
                                    <span class="inline-flex items-center justify-center w-9 sm:w-10 h-8 rounded-lg text-[10px] font-bold <?= e($badge['bg']) ?> <?= e($badge['text']) ?> shrink-0">
                                        <?= e($badge['label']) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-900 dark:text-white truncate max-w-[165px] sm:max-w-md">
                                            <?= e((string)$tf['original_name']) ?>
                                        </p>
                                        <p class="text-[11px] text-slate-400">
                                            Gelöscht am <?= e($delDate) ?> Uhr
                                        </p>
                                        <p class="sm:hidden text-[11px] font-medium mt-0.5 <?= $daysLeft <= 5 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' ?>">
                                            <?= e(formatBytes((int)$tf['file_size'])) ?> • Noch <?= $daysLeft ?> <?= $daysLeft === 1 ? 'Tag' : 'Tage' ?>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3.5 px-4 hidden md:table-cell text-xs text-slate-500 dark:text-slate-400">
                                <?= (string)$tf['folder_path'] !== '' ? '📁 ' . e((string)$tf['folder_path']) : '🏠 Hauptverzeichnis' ?>
                            </td>

                            <td class="py-3.5 px-4 hidden sm:table-cell text-slate-600 dark:text-slate-300 font-medium whitespace-nowrap">
                                <?= e(formatBytes((int)$tf['file_size'])) ?>
                            </td>

                            <td class="py-3.5 px-4 hidden sm:table-cell whitespace-nowrap">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $daysLeft <= 5 ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300' ?>">
                                    Noch <?= $daysLeft ?> <?= $daysLeft === 1 ? 'Tag' : 'Tage' ?>
                                </span>
                            </td>

                            <td class="py-3.5 px-3 sm:px-6 whitespace-nowrap text-right">
                                <div class="inline-flex items-center justify-end gap-1.5 sm:gap-2">
                                    <!-- Wiederherstellen -->
                                    <form action="trash.php" method="post" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="file_id" value="<?= $tfId ?>">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-700 dark:text-emerald-300 font-medium text-xs transition-colors cursor-pointer"
                                            title="Datei an ihren ursprünglichen Ort wiederherstellen"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                            </svg>
                                            <span class="hidden sm:inline">Wiederherstellen</span>
                                        </button>
                                    </form>

                                    <!-- Endgültig löschen -->
                                    <form
                                        action="trash.php"
                                        method="post"
                                        class="inline"
                                        onsubmit="return confirm('Möchten Sie die Datei &quot;<?= e(addslashes((string)$tf['original_name'])) ?>&quot; jetzt endgültig und unwiderruflich löschen?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_permanently">
                                        <input type="hidden" name="file_id" value="<?= $tfId ?>">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center justify-center p-2 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                            title="Endgültig löschen"
                                            aria-label="Endgültig löschen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
