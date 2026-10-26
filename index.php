<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';

// Strikter Session-Schutz: Nicht eingeloggte Nutzer werden sofort auf login.php umgeleitet
requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

$storageStats = getUserStorageStats($pdo, $userId);
$phpLimits    = getPhpUploadLimits();

// 1. Gruppenordner prüfen
$activeGroupFolderId = isset($_GET['group_folder']) ? (int)$_GET['group_folder'] : 0;
$activeGroupFolder   = null;
$canWriteActiveGf    = false;

$accessibleGroupFolders = getAccessibleGroupFolders($pdo, $userId, $isAdmin);
foreach ($accessibleGroupFolders as $gfItem) {
    if ((int)$gfItem['id'] === $activeGroupFolderId) {
        $activeGroupFolder = $gfItem;
        $canWriteActiveGf  = ((int)$gfItem['can_write'] === 1);
        break;
    }
}
if ($activeGroupFolderId > 0 && !$activeGroupFolder) {
    setFlash('error', 'Sie haben keinen Zugriff auf den angeforderten Gruppenordner.');
    header('Location: index.php');
    exit;
}

// 2. Von anderem Benutzer freigegebenen Ordner prüfen (?shared_folder=ID)
$activeSharedFolderId  = isset($_GET['shared_folder']) ? (int)$_GET['shared_folder'] : 0;
$activeSharedFolder    = null;
$canWriteSharedFolder  = false;
$canDeleteSharedFolder = false;
if ($activeSharedFolderId > 0 && $activeGroupFolder === null) {
    $stmtSf = $pdo->prepare(
        'SELECT us.id, us.owner_id, us.folder_path, us.can_write, us.can_delete, us.created_at, u.username AS owner_username
         FROM user_shares us
         INNER JOIN users u ON u.id = us.owner_id
         WHERE us.id = :id AND us.shared_with_user_id = :uid AND us.folder_path IS NOT NULL
         LIMIT 1'
    );
    $stmtSf->execute([
        ':id'  => $activeSharedFolderId,
        ':uid' => $userId,
    ]);
    $activeSharedFolder = $stmtSf->fetch() ?: null;
    if (!$activeSharedFolder) {
        setFlash('error', 'Der angeforderte freigegebene Ordner wurde nicht gefunden.');
        header('Location: index.php');
        exit;
    }
    $canWriteSharedFolder  = ((int)($activeSharedFolder['can_write'] ?? 0) === 1);
    $canDeleteSharedFolder = ((int)($activeSharedFolder['can_delete'] ?? 0) === 1);
}

$rawFolderParam = isset($_GET['folder']) && is_string($_GET['folder']) ? trim($_GET['folder']) : '';
$showAllFolders = ($rawFolderParam === '__all__');
$currentFolder  = (!$showAllFolders && $rawFolderParam !== '')
    ? extractSanitizedFolderPath($rawFolderParam . '/placeholder')
    : '';
$searchQuery   = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$onlyFavorites = isset($_GET['fav']) && $_GET['fav'] === '1';

// Sortier-Parameter für die Haupttabelle (Standard: Datum absteigend)
$allowedSortCols = [
    'name'   => 'f.original_name',
    'folder' => 'f.folder_path',
    'size'   => 'f.file_size',
    'date'   => 'f.upload_date',
];
$rawSort = isset($_GET['sort']) && is_string($_GET['sort']) ? strtolower(trim($_GET['sort'])) : 'date';
$sortBy  = array_key_exists($rawSort, $allowedSortCols) ? $rawSort : 'date';
$rawDir  = isset($_GET['dir']) && is_string($_GET['dir']) ? strtolower(trim($_GET['dir'])) : ($sortBy === 'date' ? 'desc' : 'asc');
$sortDir = ($rawDir === 'asc' || $rawDir === 'desc') ? $rawDir : ($sortBy === 'date' ? 'desc' : 'asc');
$sqlSortDir = strtoupper($sortDir);

// Alle Ordner (sowohl explizit angelegte leere Ordner als auch Ordner mit Dateien) ermitteln
$userFolders = getMergedFoldersList(
    $pdo,
    $userId,
    $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : null
);

// Persönliche Ordner & beschreibbare Gruppenordner (samt Unterordnern) für Verschieben/Kopieren bereitstellen
$personalFoldersList = $activeGroupFolder === null
    ? $userFolders
    : getMergedFoldersList($pdo, $userId, null);

$writableGroupFolders      = [];
$groupFoldersSubfoldersMap = [];
foreach ($accessibleGroupFolders as $gfEntry) {
    if ((int)$gfEntry['can_write'] === 1) {
        $gfEntryId = (int)$gfEntry['id'];
        $writableGroupFolders[] = $gfEntry;
        $groupFoldersSubfoldersMap[$gfEntryId] = ($activeGroupFolder !== null && (int)$activeGroupFolder['id'] === $gfEntryId)
            ? $userFolders
            : getMergedFoldersList($pdo, $userId, $gfEntryId);
    }
}

$renderFolderTransferOptions = static function (string $srcFolder, ?int $srcGfId, bool $canMoveSource) use (
    $personalFoldersList,
    $writableGroupFolders,
    $groupFoldersSubfoldersMap
): string {
    $srcParent = str_contains($srcFolder, '/') ? dirname($srcFolder) : '';
    ob_start();
    ?>
    <option value="">⇄ Verschieben / 📋 Kopieren...</option>
    <?php if ($canMoveSource): ?>
        <optgroup label="⇄ Verschieben – Meine Dateien">
            <?php if ($srcGfId !== null || $srcParent !== ''): ?>
                <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
            <?php endif; ?>
            <?php foreach ($personalFoldersList as $pfRow): ?>
                <?php
                $pfPath = (string)$pfRow['folder_path'];
                if ($srcGfId === null && ($pfPath === $srcFolder || $pfPath === $srcParent || str_starts_with($pfPath, $srcFolder . '/'))) {
                    continue;
                }
                ?>
                <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
            <?php endforeach; ?>
        </optgroup>

        <?php if (!empty($writableGroupFolders)): ?>
            <optgroup label="⇄ Verschieben – Gruppenordner">
                <?php foreach ($writableGroupFolders as $wgf): ?>
                    <?php
                    $wgfId   = (int)$wgf['id'];
                    $wgfName = (string)$wgf['name'];
                    $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                    ?>
                    <?php if ($srcGfId !== $wgfId || $srcParent !== ''): ?>
                        <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                    <?php endif; ?>
                    <?php foreach ($wgfSubs as $gfSubRow): ?>
                        <?php
                        $gfSubPath = (string)$gfSubRow['folder_path'];
                        if ($srcGfId === $wgfId && ($gfSubPath === $srcFolder || $gfSubPath === $srcParent || str_starts_with($gfSubPath, $srcFolder . '/'))) {
                            continue;
                        }
                        ?>
                        <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    <?php endif; ?>

    <optgroup label="📋 Kopieren – Meine Dateien">
        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
        <?php foreach ($personalFoldersList as $pfRow): ?>
            <?php
            $pfPath = (string)$pfRow['folder_path'];
            if ($srcGfId === null && ($pfPath === $srcFolder || str_starts_with($pfPath, $srcFolder . '/'))) {
                continue;
            }
            ?>
            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
        <?php endforeach; ?>
    </optgroup>

    <?php if (!empty($writableGroupFolders)): ?>
        <optgroup label="📋 Kopieren – Gruppenordner">
            <?php foreach ($writableGroupFolders as $wgf): ?>
                <?php
                $wgfId   = (int)$wgf['id'];
                $wgfName = (string)$wgf['name'];
                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                ?>
                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                <?php foreach ($wgfSubs as $gfSubRow): ?>
                    <?php
                    $gfSubPath = (string)$gfSubRow['folder_path'];
                    if ($srcGfId === $wgfId && ($gfSubPath === $srcFolder || str_starts_with($gfSubPath, $srcFolder . '/'))) {
                        continue;
                    }
                    ?>
                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
};

$renderFileTransferOptions = static function (string $fileFolderPath, ?int $fileGfId, bool $canMoveFile, string $currentLocLabel) use (
    $personalFoldersList,
    $writableGroupFolders,
    $groupFoldersSubfoldersMap
): string {
    ob_start();
    ?>
    <option value=""><?= e($currentLocLabel) ?></option>
    <?php if ($canMoveFile): ?>
        <optgroup label="⇄ Verschieben – Meine Dateien">
            <?php if ($fileGfId !== null || $fileFolderPath !== ''): ?>
                <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
            <?php endif; ?>
            <?php foreach ($personalFoldersList as $pfRow): ?>
                <?php
                $pfPath = (string)$pfRow['folder_path'];
                if ($fileGfId === null && $pfPath === $fileFolderPath) {
                    continue;
                }
                ?>
                <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
            <?php endforeach; ?>
        </optgroup>

        <?php if (!empty($writableGroupFolders)): ?>
            <optgroup label="⇄ Verschieben – Gruppenordner">
                <?php foreach ($writableGroupFolders as $wgf): ?>
                    <?php
                    $wgfId   = (int)$wgf['id'];
                    $wgfName = (string)$wgf['name'];
                    $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                    ?>
                    <?php if ($fileGfId !== $wgfId || $fileFolderPath !== ''): ?>
                        <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                    <?php endif; ?>
                    <?php foreach ($wgfSubs as $gfSubRow): ?>
                        <?php
                        $gfSubPath = (string)$gfSubRow['folder_path'];
                        if ($fileGfId === $wgfId && $gfSubPath === $fileFolderPath) {
                            continue;
                        }
                        ?>
                        <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    <?php endif; ?>

    <optgroup label="📋 Kopieren – Meine Dateien">
        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
        <?php foreach ($personalFoldersList as $pfRow): ?>
            <?php $pfPath = (string)$pfRow['folder_path']; ?>
            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
        <?php endforeach; ?>
    </optgroup>

    <?php if (!empty($writableGroupFolders)): ?>
        <optgroup label="📋 Kopieren – Gruppenordner">
            <?php foreach ($writableGroupFolders as $wgf): ?>
                <?php
                $wgfId   = (int)$wgf['id'];
                $wgfName = (string)$wgf['name'];
                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                ?>
                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                <?php foreach ($wgfSubs as $gfSubRow): ?>
                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
};

// Anzahl & Größe der Dateien direkt im Hauptverzeichnis (folder_path = '') ermitteln
if ($activeGroupFolder !== null) {
    $stmtRootStats = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
         FROM files
         WHERE group_folder_id = :gf_id AND deleted_at IS NULL AND folder_path = ''"
    );
    $stmtRootStats->execute([':gf_id' => (int)$activeGroupFolder['id']]);
} else {
    $stmtRootStats = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
         FROM files
         WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path = ''"
    );
    $stmtRootStats->execute([':uid' => $userId]);
}
$rootStatsRow   = $stmtRootStats->fetch();
$rootFileCount  = (int)($rootStatsRow['cnt'] ?? 0);
$rootFolderSize = (int)($rootStatsRow['sz'] ?? 0);

// Share-Zähler für persönliche Ordner ermitteln (damit man sieht, welche Ordner geteilt sind)
$folderShareCounts = [];
if ($activeGroupFolder === null && !empty($userFolders)) {
    $stmtFolderLinks = $pdo->prepare(
        'SELECT folder_path, COUNT(*) AS cnt FROM shares WHERE user_id = :uid AND folder_path IS NOT NULL GROUP BY folder_path'
    );
    $stmtFolderLinks->execute([':uid' => $userId]);
    foreach ($stmtFolderLinks->fetchAll() as $r) {
        $fp = (string)$r['folder_path'];
        $folderShareCounts[$fp]['links'] = (int)$r['cnt'];
    }

    $stmtFolderUsers = $pdo->prepare(
        'SELECT folder_path, COUNT(*) AS cnt FROM user_shares WHERE owner_id = :uid AND folder_path IS NOT NULL GROUP BY folder_path'
    );
    $stmtFolderUsers->execute([':uid' => $userId]);
    foreach ($stmtFolderUsers->fetchAll() as $r) {
        $fp = (string)$r['folder_path'];
        $folderShareCounts[$fp]['users'] = (int)$r['cnt'];
    }
}

// Dateien für die Haupttabelle abrufen (entweder aktiver Gruppenordner, aktiver freigegebener Ordner ODER eigene Dateien)
$params = [];
if ($activeGroupFolder !== null) {
    $sqlFiles = 'SELECT f.*, u.username AS uploader_name,
                        (SELECT COUNT(*) FROM shares s WHERE s.file_id = f.id) AS link_share_count,
                        (SELECT COUNT(*) FROM user_shares us WHERE us.file_id = f.id) AS user_share_count
                 FROM files f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.group_folder_id = :gf_id AND f.deleted_at IS NULL';
    $params[':gf_id'] = (int)$activeGroupFolder['id'];
} elseif ($activeSharedFolder !== null) {
    $sharedFp = (string)$activeSharedFolder['folder_path'];
    $sqlFiles = 'SELECT f.*, u.username AS uploader_name, 0 AS link_share_count, 0 AS user_share_count
                 FROM files f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.user_id = :owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
                   AND (f.folder_path = :sf_exact OR f.folder_path LIKE :sf_prefix)';
    $params[':owner_id']  = (int)$activeSharedFolder['owner_id'];
    $params[':sf_exact']  = $sharedFp;
    $params[':sf_prefix'] = $sharedFp . '/%';
} else {
    $sqlFiles = 'SELECT f.*, u.username AS uploader_name,
                        (SELECT COUNT(*) FROM shares s WHERE s.file_id = f.id) AS link_share_count,
                        (SELECT COUNT(*) FROM user_shares us WHERE us.file_id = f.id) AS user_share_count
                 FROM files f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.user_id = :user_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL';
    $params[':user_id'] = $userId;
}

if ($activeSharedFolder === null && $searchQuery === '' && !$showAllFolders && !$onlyFavorites) {
    if ($currentFolder !== '') {
        // Nur Dateien im ausgewählten Ordner anzeigen
        $sqlFiles .= ' AND f.folder_path = :folder_exact';
        $params[':folder_exact'] = $currentFolder;
    } else {
        // Nur Dateien direkt im Hauptverzeichnis anzeigen (nicht aus Unterordnern)
        $sqlFiles .= " AND f.folder_path = ''";
    }
}

if ($onlyFavorites) {
    $sqlFiles .= ' AND f.is_favorite = 1';
}

if ($searchQuery !== '') {
    $sqlFiles .= ' AND (f.original_name LIKE :search_name OR f.folder_path LIKE :search_folder)';
    $params[':search_name']   = '%' . $searchQuery . '%';
    $params[':search_folder'] = '%' . $searchQuery . '%';
}

$orderByColumn = $allowedSortCols[$sortBy] ?? 'f.upload_date';
$sqlFiles .= sprintf(' ORDER BY f.is_favorite DESC, %s %s, f.id DESC', $orderByColumn, $sqlSortDir);

$stmtFiles = $pdo->prepare($sqlFiles);
$stmtFiles->execute($params);
$files = $stmtFiles->fetchAll();

// Direkte Unterordner des aktuell geöffneten Ordners ermitteln (oder bei globaler Suche alle passenden Ordner)
$directSubfolders = [];
if ($activeSharedFolder === null && !$showAllFolders && !$onlyFavorites) {
    foreach ($userFolders as $ufItem) {
        $fPath = (string)$ufItem['folder_path'];
        $parentOfF = str_contains($fPath, '/') ? dirname($fPath) : '';
        if ($searchQuery !== '') {
            if (stripos($fPath, $searchQuery) !== false) {
                $directSubfolders[] = $ufItem;
            }
        } elseif ($parentOfF === $currentFolder) {
            $directSubfolders[] = $ufItem;
        }
    }

    if (count($directSubfolders) > 1) {
        usort($directSubfolders, static function (array $a, array $b) use ($sortBy, $sortDir): int {
            if ($sortBy === 'size') {
                $cmp = ((int)$a['folder_size']) <=> ((int)$b['folder_size']);
                if ($cmp === 0) {
                    $cmp = strnatcasecmp(basename((string)$a['folder_path']), basename((string)$b['folder_path']));
                }
            } else {
                $cmp = strnatcasecmp(basename((string)$a['folder_path']), basename((string)$b['folder_path']));
            }
            if ($sortBy === 'name' || $sortBy === 'folder' || $sortBy === 'size') {
                return $sortDir === 'desc' ? -$cmp : $cmp;
            }
            return $cmp;
        });
    }
}

// Für mich freigegebene Ordner (von anderen Cloud-Benutzern via user_shares)
$stmtSharedFoldersWithMe = $pdo->prepare(
    'SELECT us.id AS user_share_id, us.owner_id, us.folder_path, us.can_write, us.can_delete, us.created_at AS shared_at,
            u.username AS owner_username,
            (SELECT COUNT(*) FROM files f
             WHERE f.user_id = us.owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
               AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, \'/%\'))) AS file_count,
            (SELECT COALESCE(SUM(f.file_size), 0) FROM files f
             WHERE f.user_id = us.owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
               AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, \'/%\'))) AS folder_size
     FROM user_shares us
     INNER JOIN users u ON u.id = us.owner_id
     WHERE us.shared_with_user_id = :uid AND us.folder_path IS NOT NULL
     ORDER BY us.created_at DESC'
);
$stmtSharedFoldersWithMe->execute([':uid' => $userId]);
$sharedWithMeFolders = $stmtSharedFoldersWithMe->fetchAll();

// Für mich freigegebene Einzeldateien (von anderen Cloud-Benutzern via user_shares, nur aktive Dateien)
$stmtSharedWithMe = $pdo->prepare(
    'SELECT f.id, f.original_name, f.file_size, f.mime_type, f.is_encrypted, f.upload_date,
            us.can_write, us.can_delete, us.created_at AS shared_at, u.username AS owner_username
     FROM user_shares us
     INNER JOIN files f ON f.id = us.file_id
     INNER JOIN users u ON u.id = us.owner_id
     WHERE us.shared_with_user_id = :uid AND us.file_id IS NOT NULL AND f.deleted_at IS NULL
     ORDER BY us.created_at DESC'
);
$stmtSharedWithMe->execute([':uid' => $userId]);
$sharedWithMeFiles = $stmtSharedWithMe->fetchAll();

$totalSharedWithMeCount = count($sharedWithMeFolders) + count($sharedWithMeFiles);

// Liste anderer Cloud-Benutzer für das Teilen-Modal (nur Benutzer, die ihre Sichtbarkeit für Direktfreigaben aktiviert haben)
$stmtOtherUsers = $pdo->prepare('SELECT id, username FROM users WHERE id <> :uid AND allow_user_sharing = 1 ORDER BY username ASC');
$stmtOtherUsers->execute([':uid' => $userId]);
$otherCloudUsers = $stmtOtherUsers->fetchAll();

$canUploadHere = $activeSharedFolder !== null
    ? $canWriteSharedFolder
    : (($activeGroupFolder === null) || $canWriteActiveGf);

$pageTitle = $activeGroupFolder
    ? 'Gruppenordner: ' . $activeGroupFolder['name']
    : ($activeSharedFolder ? 'Geteilter Ordner: ' . $activeSharedFolder['folder_path'] : 'Dashboard');
require_once __DIR__ . '/includes/header.php';
?>

<!-- ORDNER-VERWALTUNG: Neuen Ordner anlegen & Ordner-Übersicht (bei eigenen Dateien oder im Gruppenordner) -->
<?php if ($activeSharedFolder === null): ?>
<?php
$activeFolderMeta = null;
if ($currentFolder !== '') {
    foreach ($userFolders as $ufItem) {
        if ((string)$ufItem['folder_path'] === $currentFolder) {
            $activeFolderMeta = $ufItem;
            break;
        }
    }
}
$baseGfQuery = $activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] : '';
?>
<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-5">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <!-- Links: Ordner-Dropdown + Aktionen für den aktuell gewählten Ordner -->
        <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
            <label for="folderNavSelect" class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200 shrink-0">
                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                </svg>
                <span>Ordner (<?= count($userFolders) ?>):</span>
            </label>

            <select
                id="folderNavSelect"
                onchange="window.location.href = this.value"
                class="w-full sm:w-auto sm:min-w-[260px] max-w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
            >
                <option value="index.php<?= $baseGfQuery !== '' ? '?' . $baseGfQuery : '' ?>" <?= ($currentFolder === '' && !$showAllFolders) ? 'selected' : '' ?>>
                    🏠 <?= ($activeGroupFolder !== null ? e((string)$activeGroupFolder['name']) . ' – Hauptverzeichnis' : 'Hauptverzeichnis') . ' (' . $rootFileCount . ' • ' . e(formatBytes($rootFolderSize)) . ')' ?>
                </option>
                <?php foreach ($userFolders as $folderRow): ?>
                    <?php
                    $fPath = (string)$folderRow['folder_path'];
                    $depth = substr_count($fPath, '/');
                    $indent = $depth > 0 ? str_repeat('— ', $depth) : '';
                    $folderUrl = 'index.php?' . ($baseGfQuery !== '' ? $baseGfQuery . '&' : '') . 'folder=' . rawurlencode($fPath);
                    $fLinkShares = (int)($folderShareCounts[$fPath]['links'] ?? 0);
                    $fUserShares = (int)($folderShareCounts[$fPath]['users'] ?? 0);
                    $shareIndicator = '';
                    if ($fLinkShares > 0) {
                        $shareIndicator .= ' 🔗' . $fLinkShares;
                    }
                    if ($fUserShares > 0) {
                        $shareIndicator .= ' 👤' . $fUserShares;
                    }
                    ?>
                    <option value="<?= e($folderUrl) ?>" <?= $currentFolder === $fPath ? 'selected' : '' ?>>
                        <?= e($indent . '📁 ' . $fPath . ' (' . (int)$folderRow['file_count'] . ' • ' . formatBytes((int)$folderRow['folder_size']) . ')' . $shareIndicator) ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!empty($userFolders)): ?>
                    <option value="index.php?<?= $baseGfQuery !== '' ? $baseGfQuery . '&amp;' : '' ?>folder=__all__" <?= $showAllFolders ? 'selected' : '' ?>>
                        📂 Alle Dateien anzeigen (Ordnerübergreifend)
                    </option>
                <?php endif; ?>
            </select>

            <?php if ($currentFolder !== ''): ?>
                <!-- Aktions-Buttons & Verschieben/Kopieren-Auswahl für den aktuell gewählten Ordner -->
                <?php
                $activeGfIdOrNull = $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : null;
                ?>
                <div class="inline-flex flex-wrap items-center gap-1.5">
                    <?php if ($activeGroupFolder === null && $activeFolderMeta !== null && (int)$activeFolderMeta['file_count'] > 0): ?>
                        <a
                            href="download.php?folder=<?= rawurlencode($currentFolder) ?>"
                            title="Ordner als ZIP herunterladen"
                            aria-label="Ordner als ZIP herunterladen"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 border border-sky-200/70 dark:border-sky-800/60 transition-colors"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                            </svg>
                        </a>
                    <?php endif; ?>

                    <button
                        type="button"
                        onclick="openTransferModal('folder', 0, <?= e((string)json_encode($currentFolder)) ?>, <?= e((string)json_encode($currentFolder)) ?>, <?= $canUploadHere ? 'true' : 'false' ?>)"
                        title="Ordner verschieben oder kopieren"
                        aria-label="Ordner verschieben oder kopieren"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                    </button>

                    <?php if ($activeGroupFolder === null): ?>
                        <button
                            type="button"
                            onclick="openShareModal(0, <?= e((string)json_encode($currentFolder)) ?>, <?= e((string)json_encode($currentFolder)) ?>)"
                            title="Ordner teilen"
                            aria-label="Ordner teilen"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                            </svg>
                        </button>
                    <?php endif; ?>

                    <?php if ($canUploadHere && $activeGroupFolder === null): ?>
                        <form action="delete.php" method="post" class="inline" onsubmit="return confirm('Möchten Sie den Ordner &quot;<?= e(addslashes($currentFolder)) ?>&quot; <?= ($activeFolderMeta && (int)$activeFolderMeta['file_count'] > 0) ? 'inklusive aller darin enthaltenen Dateien ' : '' ?>in den Papierkorb verschieben?');">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="delete_folder" value="<?= e($currentFolder) ?>">
                            <button
                                type="submit"
                                title="Ordner löschen"
                                aria-label="Ordner löschen"
                                class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 border border-red-200/70 dark:border-red-800/60 transition-colors cursor-pointer"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canUploadHere): ?>
            <!-- Rechts: Formular zum Anlegen eines neuen Ordners / Unterordners -->
            <form action="folder_action.php" method="post" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 shrink-0">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_folder">
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="parent_folder" value="<?= e($currentFolder) ?>">

                <input
                    type="text"
                    name="folder_name"
                    required
                    placeholder="<?= $currentFolder !== '' ? 'Neuer Unterordner in ' . e($currentFolder) . '...' : 'Neuer Ordnername (z.B. Projekte)...' ?>"
                    class="w-full sm:w-56 px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <button
                    type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs sm:text-sm shadow-2xs transition-colors cursor-pointer shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>Ordner anlegen</span>
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Haupt-Dateitabelle (Eigene Dateien, aktiver Gruppenordner oder geöffneter freigegebener Ordner) -->
<section id="mainFilesSection" class="mc-card relative bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8 transition-all">
    <div class="p-4 sm:p-6 border-b border-slate-200 dark:border-slate-800 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                    <?php if ($onlyFavorites): ?>
                        ⭐ Meine Favoriten
                    <?php elseif ($searchQuery !== ''): ?>
                        🔎 Globale Suche: <span class="text-brand-600 dark:text-brand-400">„<?= e($searchQuery) ?>“</span>
                    <?php elseif ($activeGroupFolder !== null): ?>
                        Gruppenordner: <span class="text-indigo-600 dark:text-indigo-400"><?= e((string)$activeGroupFolder['name']) ?></span>
                    <?php elseif ($activeSharedFolder !== null): ?>
                        Freigegebener Ordner: <span class="text-blue-600 dark:text-blue-400">📁 <?= e((string)$activeSharedFolder['folder_path']) ?></span>
                        <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(von <?= e((string)$activeSharedFolder['owner_username']) ?>)</span>
                    <?php elseif ($currentFolder !== ''): ?>
                        Ordner: <span class="text-brand-600 dark:text-brand-400"><?= e($currentFolder) ?></span>
                    <?php else: ?>
                        Meine hochgeladenen Dateien
                    <?php endif; ?>
                </h2>
                <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                    <?= count($directSubfolders) > 0 ? count($directSubfolders) . ' Ordner &bull; ' : '' ?><?= count($files) ?> <?= count($files) === 1 ? 'Datei' : 'Dateien' ?>
                </span>
            </div>
            <?php if ($currentFolder !== '' || $searchQuery !== '' || $activeGroupFolder !== null || $activeSharedFolder !== null || $showAllFolders || $onlyFavorites): ?>
                <div class="mt-1 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span><?= $searchQuery !== '' ? 'Ordnerübergreifende Suche aktiv' : 'Gefilterte Ansicht aktiv' ?></span>
                    <span>&bull;</span>
                    <a
                        href="index.php<?= $activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] : '' ?>"
                        data-drop-folder=""
                        data-drop-folder-label="Hauptverzeichnis"
                        class="text-brand-600 dark:text-brand-400 hover:underline font-semibold rounded px-1 -mx-1 transition-all"
                    >&larr; Zurück zum Hauptverzeichnis</a>
                </div>
            <?php else: ?>
                <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                    💡 Tipp: Dateien oder Ordner einfach per Drag &amp; Drop direkt auf einen Ordner (oder in diese Liste) ziehen.
                </p>
            <?php endif; ?>
        </div>

        <!-- Rechts: Kompakte Upload-Buttons, Favoriten-Filter, Ansicht-Umschalter (Liste / Kacheln) & Globale Suche -->
        <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
            <?php if ($canUploadHere): ?>
                <form id="uploadForm" action="upload.php" method="post" enctype="multipart/form-data" class="inline-flex items-center gap-1.5">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" id="target_folder" name="target_folder" value="<?= e($currentFolder) ?>">
                    <?php if ($activeSharedFolder !== null): ?>
                        <input type="hidden" name="shared_folder_id" value="<?= (int)$activeSharedFolder['id'] ?>">
                    <?php elseif ($activeGroupFolder !== null): ?>
                        <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
                    <?php endif; ?>
                    <input type="file" id="fileInput" name="files[]" multiple class="sr-only">
                    <input type="file" id="folderInput" webkitdirectory directory multiple class="sr-only">

                    <button
                        type="button"
                        id="btnSelectFiles"
                        title="Dateien in <?= e($currentFolder !== '' ? '„' . $currentFolder . '“' : 'das aktuelle Verzeichnis') ?> hochladen (Frei: <?= e(formatBytes($storageStats['free'])) ?>)"
                        class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M12 12V4m0 0L8.5 7.5M12 4l3.5 3.5"/>
                        </svg>
                        <span>+ Dateien</span>
                    </button>

                    <button
                        type="button"
                        id="btnSelectFolder"
                        title="Kompletten Ordner samt Unterordnern hochladen"
                        class="inline-flex items-center justify-center gap-1 px-2.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                    >
                        <span>📁+</span>
                        <span class="hidden sm:inline">Ordner</span>
                    </button>

                    <button
                        type="button"
                        onclick="openCreateOfficeModal()"
                        title="Neues Word-Dokument (.docx), Excel-Tabelle (.xlsx) oder Textnotiz direkt in der Cloud erstellen"
                        aria-label="Office Light – Neues Dokument erstellen"
                        class="inline-flex items-center justify-center gap-1 px-2.5 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-100 dark:hover:bg-indigo-900/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                    >
                        <span>📄+</span>
                        <span class="hidden xl:inline">Office</span>
                    </button>
                </form>
            <?php endif; ?>

            <?php
            $favFilterParams = [];
            if ($activeGroupFolder !== null) {
                $favFilterParams['group_folder'] = (int)$activeGroupFolder['id'];
            }
            if (!$onlyFavorites) {
                $favFilterParams['fav'] = '1';
            } elseif ($currentFolder !== '') {
                $favFilterParams['folder'] = $currentFolder;
            }
            $favFilterUrl = 'index.php' . (!empty($favFilterParams) ? '?' . http_build_query($favFilterParams) : '');
            ?>
            <a
                href="<?= e($favFilterUrl) ?>"
                title="<?= $onlyFavorites ? 'Alle Dateien anzeigen' : 'Nur Favoriten (⭐) anzeigen' ?>"
                class="inline-flex items-center gap-1 px-2.5 py-2 rounded-xl border text-xs font-semibold transition-colors <?= $onlyFavorites ? 'bg-amber-100 dark:bg-amber-950/80 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-300' : 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-amber-50 dark:hover:bg-amber-950/40' ?>"
            >
                <span><?= $onlyFavorites ? '⭐' : '☆' ?></span>
                <span class="hidden md:inline">Favoriten</span>
            </a>

            <!-- Umschalter: Listenansicht <-> Kachel-/Galerieansicht -->
            <div class="inline-flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 p-0.5 border border-slate-200 dark:border-slate-700">
                <button
                    type="button"
                    id="btnViewList"
                    onclick="setFileViewMode('list')"
                    title="Tabellen-/Listenansicht"
                    aria-label="Listenansicht"
                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs"
                >
                    📋 <span class="hidden lg:inline">Liste</span>
                </button>
                <button
                    type="button"
                    id="btnViewGrid"
                    onclick="setFileViewMode('grid')"
                    title="Kachel- & Foto-Galerieansicht"
                    aria-label="Kachelansicht"
                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white"
                >
                    🖼️ <span class="hidden lg:inline">Kacheln</span>
                </button>
            </div>

            <!-- Globale Suche über alle Ordner -->
            <form action="index.php" method="get" class="flex items-center gap-1.5 flex-1 sm:flex-initial">
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>
                <?php if ($activeSharedFolder !== null): ?>
                    <input type="hidden" name="shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
                <?php endif; ?>
                <?php if ($sortBy !== 'date' || $sortDir !== 'desc'): ?>
                    <input type="hidden" name="sort" value="<?= e($sortBy) ?>">
                    <input type="hidden" name="dir" value="<?= e($sortDir) ?>">
                <?php endif; ?>
                <div class="relative flex-1 sm:flex-initial">
                    <input
                        type="search"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        placeholder="Alle Ordner durchsuchen..."
                        class="w-full sm:w-48 pl-8 pr-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <button type="submit" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-medium transition-colors cursor-pointer">
                    Suchen
                </button>
            </form>
        </div>
    </div>

    <?php if ($canUploadHere): ?>
        <!-- Schlanker Drag-&-Drop-Hinweis & Fortschrittsbalken (erscheint nur bei aktivem Drag & Drop oder Upload) -->
        <div id="globalDropHint" class="hidden px-4 sm:px-6 py-2.5 bg-brand-50/90 dark:bg-brand-950/60 border-b border-brand-200 dark:border-brand-800 text-xs sm:text-sm font-semibold text-brand-700 dark:text-brand-300 items-center justify-between gap-2">
            <span id="globalDropHintText">📥 Dateien oder Ordner hier ablegen, um sie direkt in <?= e($currentFolder !== '' ? '„' . $currentFolder . '“' : 'das aktuelle Verzeichnis') ?> hochzuladen</span>
            <span class="text-[11px] font-normal text-brand-600 dark:text-brand-400">Frei: <?= e(formatBytes($storageStats['free'])) ?></span>
        </div>
        <div id="uploadProgressWrapper" class="hidden px-4 sm:px-6 py-3 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 space-y-1.5">
            <div class="flex justify-between text-xs font-semibold text-slate-700 dark:text-slate-200">
                <span id="uploadProgressText">Dateien werden hochgeladen...</span>
                <span id="uploadProgressPercent">0%</span>
            </div>
            <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div id="uploadProgressBar" class="h-full bg-brand-600 transition-all duration-150" style="width: 0%"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($files) && empty($directSubfolders) && $currentFolder === ''): ?>
        <div
            class="p-10 sm:p-12 text-center <?= $canUploadHere ? 'cursor-pointer hover:bg-brand-50/30 dark:hover:bg-brand-950/20 transition-colors' : '' ?>"
            <?php if ($canUploadHere): ?>onclick="document.getElementById('fileInput')?.click()"<?php endif; ?>
        >
            <div class="mx-auto w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">Keine Dateien oder Ordner vorhanden</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                <?= $canUploadHere ? 'Ziehen Sie Dateien oder Ordner einfach per Drag & Drop hierher oder klicken Sie auf „+ Dateien“.' : 'In diesem Ordner befinden sich aktuell keine Dateien.' ?>
            </p>
        </div>
    <?php else: ?>
        <?php
        $buildSortHeaderUrl = static function (string $col) use (
            $activeGroupFolder,
            $activeSharedFolder,
            $showAllFolders,
            $currentFolder,
            $searchQuery,
            $sortBy,
            $sortDir
        ): string {
            $nextDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
            if ($col === 'date' && $sortBy !== 'date') {
                $nextDir = 'desc';
            }
            $qParams = [];
            if ($activeGroupFolder !== null) {
                $qParams['group_folder'] = (int)$activeGroupFolder['id'];
            }
            if ($activeSharedFolder !== null) {
                $qParams['shared_folder'] = (int)$activeSharedFolder['id'];
            }
            if ($showAllFolders) {
                $qParams['folder'] = '__all__';
            } elseif ($currentFolder !== '') {
                $qParams['folder'] = $currentFolder;
            }
            if ($searchQuery !== '') {
                $qParams['q'] = $searchQuery;
            }
            if ($onlyFavorites) {
                $qParams['fav'] = 1;
            }
            $qParams['sort'] = $col;
            $qParams['dir']  = $nextDir;
            return 'index.php?' . http_build_query($qParams);
        };
        ?>
        <div id="fileListView" class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 select-none">
                        <th class="py-3.5 pl-4 pr-2 sm:pl-6 w-9">
                            <?php if (!empty($files)): ?>
                                <input
                                    type="checkbox"
                                    id="selectAllFilesCheckbox"
                                    onchange="toggleSelectAllFiles(this)"
                                    class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500 cursor-pointer align-middle"
                                    title="Alle Dateien auf dieser Seite auswählen"
                                    aria-label="Alle Dateien auswählen"
                                >
                            <?php endif; ?>
                        </th>
                        <th class="py-3.5 px-2 sm:px-4">
                            <a
                                href="<?= e($buildSortHeaderUrl('name')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'name' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Name sortieren (<?= ($sortBy === 'name' && $sortDir === 'asc') ? 'Z–A' : 'A–Z' ?>)"
                            >
                                <span>Name</span>
                                <span class="text-[11px] <?= $sortBy === 'name' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'name' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden md:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('folder')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'folder' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Ordner sortieren"
                            >
                                <span>Ordner</span>
                                <span class="text-[11px] <?= $sortBy === 'folder' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'folder' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden sm:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('size')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'size' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Größe sortieren"
                            >
                                <span>Größe</span>
                                <span class="text-[11px] <?= $sortBy === 'size' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'size' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden lg:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('date')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'date' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Datum sortieren"
                            >
                                <span>Typ / Datum</span>
                                <span class="text-[11px] <?= $sortBy === 'date' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'date' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 sm:px-6 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-sm">
                    <?php if ($currentFolder !== '' && $activeSharedFolder === null): ?>
                        <?php
                        $upParent = str_contains($currentFolder, '/') ? dirname($currentFolder) : '';
                        $upUrl = 'index.php'
                            . ($activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] . ($upParent !== '' ? '&amp;folder=' . rawurlencode($upParent) : '') : ($upParent !== '' ? '?folder=' . rawurlencode($upParent) : ''));
                        $upLabel = $upParent !== '' ? $upParent : 'Hauptverzeichnis';
                        ?>
                        <tr
                            data-drop-folder="<?= e($upParent) ?>"
                            data-drop-folder-label="<?= e($upLabel) ?>"
                            class="bg-slate-50/40 dark:bg-slate-800/20 hover:bg-slate-100/70 dark:hover:bg-slate-800/50 transition-all"
                        >
                            <td class="py-2.5 px-4 sm:px-6" colspan="6">
                                <div class="flex items-center justify-between gap-2">
                                    <a href="<?= $upUrl ?>" class="inline-flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400">
                                        <span class="inline-flex items-center justify-center w-10 h-7 rounded-lg bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold">
                                            &crarr; ..
                                        </span>
                                        <span>Übergeordneter Ordner (<?= e($upLabel) ?>)</span>
                                    </a>
                                    <span class="hidden sm:inline text-[11px] text-slate-400 dark:text-slate-500">
                                        📥 Dateien hierher ziehen zum Ablegen in <?= e($upLabel) ?>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <!-- 1. Direkte Unterordner in diesem Verzeichnis -->
                    <?php foreach ($directSubfolders as $subFolder): ?>
                        <?php
                        $subPath = (string)$subFolder['folder_path'];
                        $subDisplayName = basename($subPath);
                        $subFileCount = (int)$subFolder['file_count'];
                        $subSize = (int)$subFolder['folder_size'];
                        $subUrl = 'index.php?' . ($activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] . '&amp;' : '') . 'folder=' . rawurlencode($subPath);
                        $subLinkShares = (int)($folderShareCounts[$subPath]['links'] ?? 0);
                        $subUserShares = (int)($folderShareCounts[$subPath]['users'] ?? 0);
                        $subParent = str_contains($subPath, '/') ? dirname($subPath) : '';

                        $subMoveTargets = [];
                        foreach ($userFolders as $ufCand) {
                            $candP = (string)$ufCand['folder_path'];
                            if ($candP !== $subPath && $candP !== $subParent && !str_starts_with($candP, $subPath . '/')) {
                                $subMoveTargets[] = $candP;
                            }
                        }
                        $subGfIdOrNull = $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : null;
                        ?>
                        <tr
                            data-drop-folder="<?= e($subPath) ?>"
                            data-drop-folder-label="<?= e($subDisplayName) ?>"
                            class="bg-amber-50/20 dark:bg-amber-950/10 hover:bg-amber-50/60 dark:hover:bg-amber-950/25 transition-all"
                        >
                            <td class="py-3 pl-4 pr-2 sm:pl-6 w-9 text-center text-amber-500">
                                📁
                            </td>
                            <td class="py-3 px-2 sm:px-4">
                                <div class="flex items-start sm:items-center gap-2.5 sm:gap-3">
                                    <a href="<?= $subUrl ?>" class="inline-flex items-center justify-center w-9 h-8 sm:w-10 sm:h-8 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5 sm:mt-0" title="Ordner öffnen (oder Dateien per Drag & Drop hier ablegen)">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                        </svg>
                                    </a>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <a href="<?= $subUrl ?>" class="font-bold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-[145px] sm:max-w-xs md:max-w-md" title="Ordner <?= e($subPath) ?> öffnen (oder Dateien direkt hierher ziehen)">
                                                <?= e($searchQuery !== '' ? $subPath : $subDisplayName) ?>
                                            </a>
                                            <?php if ($subLinkShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300" title="<?= $subLinkShares ?> öffentliche(r) Freigabelink(s)">
                                                    🔗 <?= $subLinkShares ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($subUserShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300" title="Mit <?= $subUserShares ?> Cloud-Benutzer(n) geteilt">
                                                    👤 <?= $subUserShares ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                            <?= $subFileCount ?> <?= $subFileCount === 1 ? 'Datei' : 'Dateien' ?>
                                            <span class="sm:hidden"> &bull; <?= e(formatBytes($subSize)) ?></span>
                                            <span class="hidden md:inline text-amber-700/70 dark:text-amber-400/70"> &bull; 📥 Drop-Zone</span>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap hidden md:table-cell text-xs text-amber-700 dark:text-amber-400 font-medium">
                                📁 <?= e($subParent !== '' ? $subParent : 'Hauptverzeichnis') ?>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                <?= e(formatBytes($subSize)) ?>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap text-xs text-amber-700 dark:text-amber-400 font-medium hidden lg:table-cell">
                                📁 Unterordner
                            </td>

                            <td class="py-3 px-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                <!-- Mobil: Ein einzelner kompakter ⋯ Aktionen-Button -->
                                <div class="sm:hidden inline-flex items-center justify-end">
                                    <button
                                        type="button"
                                        onclick="openMobileFolderMenu(<?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>, <?= $subFileCount ?>, <?= ($activeGroupFolder === null && $subFileCount > 0) ? 'true' : 'false' ?>, <?= $activeGroupFolder === null ? 'true' : 'false' ?>, <?= ($canUploadHere && $activeGroupFolder === null) ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                                        title="Ordner-Aktionen"
                                        aria-label="Ordner-Aktionen"
                                    >
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Desktop (ab sm): Alle Aktions-Icons direkt nebeneinander -->
                                <div class="hidden sm:inline-flex items-center justify-end gap-1.5">
                                    <?php if ($activeGroupFolder === null && $subFileCount > 0): ?>
                                        <a
                                            href="download.php?folder=<?= rawurlencode($subPath) ?>"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                            title="Ordner als ZIP herunterladen"
                                            aria-label="Ordner als ZIP herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <!-- VERSCHIEBEN / KOPIEREN BUTTON (Nur Icon) -->
                                    <button
                                        type="button"
                                        onclick="openTransferModal('folder', 0, <?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>, <?= $canUploadHere ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 transition-colors cursor-pointer"
                                        title="Ordner verschieben oder kopieren"
                                        aria-label="Ordner verschieben oder kopieren"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                        </svg>
                                    </button>

                                    <?php if ($activeGroupFolder === null): ?>
                                        <button
                                            type="button"
                                            onclick="openShareModal(0, <?= e((string)json_encode($subPath)) ?>, <?= e((string)json_encode($subPath)) ?>)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 transition-colors cursor-pointer"
                                            title="Ordner teilen"
                                            aria-label="Ordner teilen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canUploadHere && $activeGroupFolder === null): ?>
                                        <form
                                            action="delete.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return confirm('Möchten Sie den Ordner &quot;<?= e(addslashes($subPath)) ?>&quot; <?= $subFileCount > 0 ? 'inklusive aller darin enthaltenen Dateien ' : '' ?>in den Papierkorb verschieben?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="delete_folder" value="<?= e($subPath) ?>">
                                            <?php if ($currentFolder !== ''): ?>
                                                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                                            <?php endif; ?>
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                                title="Ordner löschen"
                                                aria-label="Ordner löschen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 2. Dateien in diesem Verzeichnis -->
                    <?php
                    $previewItemsList = [];
                    foreach ($files as $fileIdx => $file):
                        $fId = (int)$file['id'];
                        $origName = (string)$file['original_name'];
                        $mimeType = (string)$file['mime_type'];
                        $badge = getFileTypeBadge($origName, $mimeType);
                        $folderPath = (string)$file['folder_path'];
                        $uploadTimestamp = strtotime((string)$file['upload_date']);
                        $formattedDate = $uploadTimestamp ? date('d.m.Y H:i', $uploadTimestamp) : (string)$file['upload_date'];
                        $formattedSize = formatBytes((int)$file['file_size']);
                        $linkShares = (int)($file['link_share_count'] ?? 0);
                        $userShares = (int)($file['user_share_count'] ?? 0);
                        $isEncrypted = ((int)($file['is_encrypted'] ?? 0) === 1);
                        $isFavorite  = ((int)($file['is_favorite'] ?? 0) === 1);
                        $canManageThisFile = $activeSharedFolder !== null
                            ? $canWriteSharedFolder
                            : (((int)$file['user_id'] === $userId) || $canWriteActiveGf);
                        $canDeleteThisFile = $activeSharedFolder !== null
                            ? $canDeleteSharedFolder
                            : $canManageThisFile;

                        $officeEditorType = getOfficeEditorType($origName);
                        $officeEditParams = ['id' => $fId];
                        if ($activeSharedFolder !== null) {
                            $officeEditParams['return_sf'] = (int)$activeSharedFolder['id'];
                        } elseif ($activeGroupFolder !== null) {
                            $officeEditParams['return_gf'] = (int)$activeGroupFolder['id'];
                        }
                        if ($currentFolder !== '') {
                            $officeEditParams['return_folder'] = $currentFolder;
                        }
                        $officeEditUrl = ($officeEditorType !== null && !$isEncrypted)
                            ? ('office.php?' . http_build_query($officeEditParams))
                            : '';

                        $previewItemsList[] = [
                            'id'          => $fId,
                            'name'        => $origName,
                            'mime'        => strtolower(trim($mimeType)),
                            'ext'         => strtolower(pathinfo($origName, PATHINFO_EXTENSION)),
                            'size'        => $formattedSize,
                            'date'        => $formattedDate . ' Uhr',
                            'folder'      => $folderPath !== '' ? $folderPath : 'Hauptverzeichnis',
                            'rawFolder'   => $folderPath,
                            'badgeLabel'  => $badge['label'],
                            'badgeBg'     => $badge['bg'],
                            'badgeText'   => $badge['text'],
                            'isEncrypted' => $isEncrypted,
                            'isFavorite'  => $isFavorite,
                            'canManage'   => $canManageThisFile,
                            'officeUrl'   => $officeEditUrl,
                            'downloadUrl' => 'download.php?id=' . $fId,
                            'inlineUrl'   => 'download.php?id=' . $fId . '&inline=1',
                            'textUrl'     => 'download.php?id=' . $fId . '&inline=1&as_text=1',
                        ];
                        $fileGfIdOrNull = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
                        if ($fileGfIdOrNull !== null && $activeGroupFolder !== null) {
                            $currentLocLabel = $folderPath !== ''
                                ? '👥 ' . (string)$activeGroupFolder['name'] . ' / 📁 ' . $folderPath
                                : '👥 ' . (string)$activeGroupFolder['name'] . ' (Hauptverz.)';
                            $folderJumpUrl = 'index.php?group_folder=' . (int)$activeGroupFolder['id'] . ($folderPath !== '' ? '&folder=' . rawurlencode($folderPath) : '');
                        } else {
                            $currentLocLabel = $folderPath !== '' ? '📁 ' . $folderPath : '🏠 Hauptverzeichnis';
                            $folderJumpUrl = $folderPath !== '' ? ('index.php?folder=' . rawurlencode($folderPath)) : 'index.php';
                        }
                    ?>
                        <tr
                            class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors <?= $canManageThisFile ? 'cursor-grab active:cursor-grabbing' : '' ?>"
                            data-file-row-id="<?= $fId ?>"
                            <?php if ($canManageThisFile): ?>
                                draggable="true"
                                data-drag-file-id="<?= $fId ?>"
                                data-drag-file-name="<?= e($origName) ?>"
                            <?php endif; ?>
                        >
                            <td class="py-3.5 pl-4 pr-2 sm:pl-6 w-9 align-middle">
                                <input
                                    type="checkbox"
                                    value="<?= $fId ?>"
                                    data-file-id="<?= $fId ?>"
                                    data-can-manage="<?= $canManageThisFile ? '1' : '0' ?>"
                                    data-can-delete="<?= $canDeleteThisFile ? '1' : '0' ?>"
                                    onchange="updateBatchSelection()"
                                    class="file-select-checkbox w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500 cursor-pointer align-middle"
                                    aria-label="Datei <?= e($origName) ?> auswählen"
                                >
                            </td>
                            <td class="py-3.5 px-2 sm:px-4">
                                <div class="flex items-start sm:items-center gap-2.5 sm:gap-3">
                                    <?php if ($canManageThisFile): ?>
                                        <button
                                            type="button"
                                            onclick="toggleFavorite(<?= $fId ?>, this)"
                                            data-fav-btn-id="<?= $fId ?>"
                                            data-fav="<?= $isFavorite ? '1' : '0' ?>"
                                            class="text-base leading-none shrink-0 cursor-pointer transition-transform hover:scale-110 mt-1 sm:mt-0 <?= $isFavorite ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600 hover:text-amber-400' ?>"
                                            title="<?= $isFavorite ? 'Aus Favoriten entfernen' : 'Als Favorit markieren' ?>"
                                            aria-label="Favorit umschalten"
                                        >
                                            <?= $isFavorite ? '⭐' : '☆' ?>
                                        </button>
                                    <?php endif; ?>
                                    <button
                                        type="button"
                                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                        title="Vorschau öffnen"
                                        class="inline-flex items-center justify-center w-9 h-8 sm:w-10 sm:h-8 rounded-lg text-[10px] font-bold tracking-tight <?= e($badge['bg']) ?> <?= e($badge['text']) ?> shrink-0 cursor-pointer hover:opacity-80 transition-opacity mt-0.5 sm:mt-0"
                                    >
                                        <?= e($badge['label']) ?>
                                    </button>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <button
                                                type="button"
                                                onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                                class="font-medium text-left text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-[200px] sm:max-w-xs md:max-w-md cursor-pointer transition-colors"
                                                title="<?= e($origName) ?> (Klicken für Vorschau)"
                                            >
                                                <?= e($origName) ?>
                                            </button>
                                            <?php if ($isEncrypted): ?>
                                                <button
                                                    type="button"
                                                    onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, true, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800/70 hover:bg-amber-200/70 cursor-pointer transition-colors"
                                                    title="Tresor-geschützte Datei (AES-256 Zero-Knowledge)"
                                                >
                                                    🔒 Tresor
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($linkShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300" title="<?= $linkShares ?> öffentliche(r) Freigabelink(s)">
                                                    🔗 <?= $linkShares ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($userShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300" title="Mit <?= $userShares ?> Cloud-Benutzer(n) geteilt">
                                                    👤 <?= $userShares ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-400 flex flex-wrap items-center gap-1.5 mt-0.5">
                                            <span class="sm:hidden"><?= e($formattedSize) ?> &bull; <?= e($formattedDate) ?></span>
                                            <span class="hidden sm:inline truncate max-w-[220px]"><?= e($mimeType) ?></span>
                                            <?php if ($searchQuery !== '' || $onlyFavorites): ?>
                                                <a href="<?= e($folderJumpUrl) ?>" class="md:hidden inline-flex items-center gap-1 text-brand-600 dark:text-brand-400 hover:underline font-medium">
                                                    &bull; <?= e($currentLocLabel) ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($activeGroupFolder !== null || $activeSharedFolder !== null): ?>
                                                <span>&bull; von <?= e((string)$file['uploader_name']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap hidden md:table-cell text-xs text-slate-600 dark:text-slate-300 font-medium">
                                <a href="<?= e($folderJumpUrl) ?>" class="truncate block max-w-[180px] hover:text-brand-600 dark:hover:text-brand-400 transition-colors" title="Zum Ordner wechseln: <?= e($currentLocLabel) ?>">
                                    <?= e($currentLocLabel) ?>
                                </a>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                <?= e($formattedSize) ?>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap text-slate-500 dark:text-slate-400 text-xs hidden lg:table-cell">
                                <?= e($formattedDate) ?> Uhr
                            </td>

                            <td class="py-3.5 px-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                <!-- Mobil (< sm): Ein einzelner kompakter ⋯ Aktionen-Button -->
                                <div class="sm:hidden inline-flex items-center justify-end">
                                    <button
                                        type="button"
                                        onclick="openMobileFileMenu(<?= (int)$fileIdx ?>, <?= $canDeleteThisFile ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                                        title="Datei-Aktionen"
                                        aria-label="Datei-Aktionen"
                                    >
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/>
                                        </svg>
                                    </button>
                                </div>

                                <!-- Desktop (ab sm): Alle Aktions-Icons direkt nebeneinander -->
                                <div class="hidden sm:inline-flex items-center justify-end gap-1.5">
                                    <!-- VORSCHAU-BUTTON (Nur Icon, Beschriftung als Hover-Tooltip) -->
                                    <button
                                        type="button"
                                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-violet-50 dark:bg-violet-950/60 hover:bg-violet-100 dark:hover:bg-violet-900/70 text-violet-700 dark:text-violet-300 transition-colors cursor-pointer"
                                        title="Vorschau"
                                        aria-label="Vorschau"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>

                                    <?php if ($officeEditUrl !== ''): ?>
                                        <!-- OFFICE LIGHT BEARBEITEN BUTTON (auf Desktop direkt sichtbar) -->
                                        <a
                                            href="<?= e($officeEditUrl) ?>"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 transition-colors"
                                            title="<?= $canManageThisFile ? 'In Office Light bearbeiten' : 'In Office Light öffnen (Schreibgeschützt)' ?>"
                                            aria-label="<?= $canManageThisFile ? 'In Office Light bearbeiten' : 'In Office Light öffnen' ?>"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <!-- HERUNTERLADEN-BUTTON (Nur Icon, Beschriftung als Hover-Tooltip) -->
                                    <?php if ($isEncrypted): ?>
                                        <button
                                            type="button"
                                            onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, true, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors cursor-pointer"
                                            title="Entschlüsseln &amp; Herunterladen"
                                            aria-label="Entschlüsseln &amp; Herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </button>
                                    <?php else: ?>
                                        <a
                                            href="download.php?id=<?= $fId ?>"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                            title="Herunterladen"
                                            aria-label="Herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canManageThisFile || $isEncrypted): ?>
                                        <!-- TRESOR-BUTTON (Einzeldatei-Verschlüsselung AES-256) -->
                                        <button
                                            type="button"
                                            onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, <?= $isEncrypted ? 'true' : 'false' ?>, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg <?= $isEncrypted ? 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 hover:bg-amber-200/80 border border-amber-300/70 dark:border-amber-800/70' : 'bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300' ?> transition-colors cursor-pointer"
                                            title="<?= $isEncrypted ? 'Tresor-Schutz verwalten / entschlüsseln' : 'Im Tresor verschlüsseln (AES-256)' ?>"
                                            aria-label="<?= $isEncrypted ? 'Tresor-Schutz verwalten / entschlüsseln' : 'Im Tresor verschlüsseln (AES-256)' ?>"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- VERSCHIEBEN / KOPIEREN BUTTON (Nur Icon, Beschriftung als Hover-Tooltip) -->
                                    <button
                                        type="button"
                                        onclick="openTransferModal('file', <?= $fId ?>, <?= e((string)json_encode($origName)) ?>, <?= e((string)json_encode($folderPath)) ?>, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 transition-colors cursor-pointer"
                                        title="Datei verschieben oder kopieren"
                                        aria-label="Datei verschieben oder kopieren"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                        </svg>
                                    </button>

                                    <?php if ($canManageThisFile): ?>
                                        <!-- TEILEN-BUTTON (Nur Icon, Beschriftung als Hover-Tooltip) -->
                                        <button
                                            type="button"
                                            onclick="openShareModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, '')"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 transition-colors cursor-pointer"
                                            title="Teilen"
                                            aria-label="Teilen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canDeleteThisFile): ?>
                                        <!-- LÖSCHEN-BUTTON (Nur Icon, Beschriftung als Hover-Tooltip) -->
                                        <form
                                            action="delete.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return confirm('Möchten Sie die Datei &quot;<?= e(addslashes((string)$file['original_name'])) ?>&quot; in den Papierkorb verschieben?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="file_id" value="<?= $fId ?>">
                                            <?php if ($currentFolder !== ''): ?>
                                                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                                            <?php endif; ?>
                                            <?php if ($activeSharedFolder !== null): ?>
                                                <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
                                            <?php elseif ($activeGroupFolder !== null): ?>
                                                <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                                            <?php endif; ?>
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                                title="Löschen"
                                                aria-label="Löschen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- FOTO-GALERIE / KACHEL-ANSICHT (#fileGridView) -->
        <div id="fileGridView" class="hidden p-4 sm:p-6 grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3.5">
            <?php if ($currentFolder !== '' && $activeSharedFolder === null): ?>
                <?php
                $upParentGrid = str_contains($currentFolder, '/') ? dirname($currentFolder) : '';
                $upUrlGrid = 'index.php'
                    . ($activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] . ($upParentGrid !== '' ? '&amp;folder=' . rawurlencode($upParentGrid) : '') : ($upParentGrid !== '' ? '?folder=' . rawurlencode($upParentGrid) : ''));
                $upLabelGrid = $upParentGrid !== '' ? $upParentGrid : 'Hauptverzeichnis';
                ?>
                <a
                    href="<?= $upUrlGrid ?>"
                    data-drop-folder="<?= e($upParentGrid) ?>"
                    data-drop-folder-label="<?= e($upLabelGrid) ?>"
                    class="group flex flex-col justify-between p-3.5 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-dashed border-slate-300 dark:border-slate-700 hover:border-brand-400 dark:hover:border-brand-500 transition-all shadow-2xs"
                    title="Übergeordneter Ordner (oder Dateien hierher ziehen)"
                >
                    <div class="flex items-center justify-center h-28 rounded-xl bg-slate-200/60 dark:bg-slate-800 text-slate-600 dark:text-slate-300 mb-2.5 group-hover:scale-[1.02] transition-transform text-xl font-bold">
                        &crarr; ..
                    </div>
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200 truncate">
                            Übergeordneter Ordner
                        </p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                            <?= e($upLabelGrid) ?> &bull; 📥 Drop-Zone
                        </p>
                    </div>
                </a>
            <?php endif; ?>

            <?php foreach ($directSubfolders as $subFolder): ?>
                <?php
                $subPath = (string)$subFolder['folder_path'];
                $subDisplayName = basename($subPath);
                $subFileCount = (int)$subFolder['file_count'];
                $subSize = (int)$subFolder['folder_size'];
                $subUrl = 'index.php?' . ($activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] . '&amp;' : '') . 'folder=' . rawurlencode($subPath);
                ?>
                <a
                    href="<?= $subUrl ?>"
                    data-drop-folder="<?= e($subPath) ?>"
                    data-drop-folder-label="<?= e($subDisplayName) ?>"
                    class="group flex flex-col justify-between p-3.5 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-800/60 hover:border-amber-400 dark:hover:border-amber-600 transition-all shadow-2xs"
                    title="Ordner <?= e($subPath) ?> öffnen (oder Dateien per Drag & Drop direkt hierher ziehen)"
                >
                    <div class="flex items-center justify-center h-28 rounded-xl bg-amber-100/70 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 mb-2.5 group-hover:scale-[1.02] transition-transform">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate" title="<?= e($subPath) ?>">
                            📁 <?= e($searchQuery !== '' ? $subPath : $subDisplayName) ?>
                        </p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            <?= $subFileCount ?> <?= $subFileCount === 1 ? 'Datei' : 'Dateien' ?> &bull; <?= e(formatBytes($subSize)) ?> &bull; 📥
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php foreach ($files as $fileIdx => $file): ?>
                <?php
                $fId = (int)$file['id'];
                $origName = (string)$file['original_name'];
                $mimeType = strtolower(trim((string)$file['mime_type']));
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $badge = getFileTypeBadge($origName, (string)$file['mime_type']);
                $isEncrypted = ((int)($file['is_encrypted'] ?? 0) === 1);
                $isFavorite  = ((int)($file['is_favorite'] ?? 0) === 1);
                $isImageThumb = !$isEncrypted && (str_starts_with($mimeType, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true));
                $canManageThisFile = $activeSharedFolder !== null
                    ? $canWriteSharedFolder
                    : (((int)$file['user_id'] === $userId) || $canWriteActiveGf);
                $canDeleteThisFile = $activeSharedFolder !== null
                    ? $canDeleteSharedFolder
                    : $canManageThisFile;
                ?>
                <div
                    class="group relative flex flex-col justify-between p-2.5 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 hover:border-brand-400 dark:hover:border-brand-500 transition-all shadow-2xs <?= $canManageThisFile ? 'cursor-grab active:cursor-grabbing' : '' ?>"
                    <?php if ($canManageThisFile): ?>
                        draggable="true"
                        data-drag-file-id="<?= $fId ?>"
                        data-drag-file-name="<?= e($origName) ?>"
                    <?php endif; ?>
                >
                    <!-- Top Overlay: Favorit + Aktionen -->
                    <div class="absolute top-4 left-4 right-4 z-10 flex items-center justify-between pointer-events-none">
                        <?php if ($canManageThisFile): ?>
                            <button
                                type="button"
                                onclick="toggleFavorite(<?= $fId ?>, this)"
                                data-fav-btn-id="<?= $fId ?>"
                                data-fav="<?= $isFavorite ? '1' : '0' ?>"
                                class="pointer-events-auto w-7 h-7 rounded-lg bg-white/90 dark:bg-slate-900/90 shadow-xs flex items-center justify-center text-sm cursor-pointer hover:scale-110 transition-transform"
                                title="Favorit umschalten"
                            >
                                <?= $isFavorite ? '⭐' : '☆' ?>
                            </button>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="openMobileFileMenu(<?= (int)$fileIdx ?>, <?= $canDeleteThisFile ? 'true' : 'false' ?>)"
                            class="pointer-events-auto w-7 h-7 rounded-lg bg-white/90 dark:bg-slate-900/90 shadow-xs flex items-center justify-center text-slate-700 dark:text-slate-200 cursor-pointer hover:bg-brand-50"
                            title="Datei-Aktionen"
                        >
                            ⋯
                        </button>
                    </div>

                    <!-- Thumbnail / Icon -->
                    <button
                        type="button"
                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                        class="w-full h-32 rounded-xl overflow-hidden bg-slate-100 dark:bg-slate-900 flex items-center justify-center mb-2.5 cursor-pointer"
                    >
                        <?php if ($isImageThumb): ?>
                            <img
                                src="download.php?id=<?= $fId ?>&amp;inline=1"
                                alt="<?= e($origName) ?>"
                                loading="lazy"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                            >
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center px-3 py-1.5 rounded-xl text-xs font-extrabold <?= e($badge['bg']) ?> <?= e($badge['text']) ?>">
                                <?= $isEncrypted ? '🔒 ' : '' ?><?= e($badge['label']) ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <div class="min-w-0 px-1">
                        <button
                            type="button"
                            onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                            class="w-full text-left text-xs font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate cursor-pointer"
                            title="<?= e($origName) ?>"
                        >
                            <?= e($origName) ?>
                        </button>
                        <p class="text-[11px] text-slate-400 truncate">
                            <?= e(formatBytes((int)$file['file_size'])) ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- SCHWEBENDE SAMMEL-AKTIONSLEISTE (Batch-Aktionen bei Mehrfachauswahl) -->
<div
    id="batchActionBar"
    class="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 hidden items-center gap-2 sm:gap-3 px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-800/95 text-white shadow-2xl border border-slate-700 backdrop-blur-md max-w-[95vw]"
>
    <span class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-brand-300 whitespace-nowrap">
        ☑️ <span id="batchSelectedCount">0</span> ausgewählt
    </span>
    <div class="h-4 w-px bg-slate-700"></div>
    <button
        type="button"
        onclick="downloadBatchZip()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Dateien als ZIP herunterladen"
    >
        <span>⬇️ ZIP</span>
    </button>
    <button
        type="button"
        id="btnBatchTransfer"
        onclick="openBatchTransferModal()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Dateien verschieben oder kopieren"
    >
        <span>⇄ Verschieben / Kopieren</span>
    </button>
    <button
        type="button"
        id="btnBatchDelete"
        onclick="deleteBatchFiles()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Dateien in den Papierkorb verschieben"
    >
        <span>🗑️ Löschen</span>
    </button>
    <button
        type="button"
        onclick="clearBatchSelection()"
        class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors cursor-pointer"
        title="Auswahl aufheben"
        aria-label="Auswahl aufheben"
    >
        ✕
    </button>
</div>

<form id="batchDeleteForm" action="delete.php" method="post" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
    <input type="hidden" name="batch_file_ids" id="batchDeleteIdsInput" value="">
    <?php if ($currentFolder !== ''): ?>
        <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
    <?php endif; ?>
    <?php if ($activeSharedFolder !== null): ?>
        <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
    <?php elseif ($activeGroupFolder !== null): ?>
        <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
    <?php endif; ?>
</form>

<!-- FÜR MICH FREIGEGEBEN (Ordner & Dateien, die von anderen Cloud-Benutzern geteilt wurden) -->
<section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
    <div class="p-4 sm:p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Für mich freigegeben (Von Cloud-Benutzern)</h2>
            <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                <?= $totalSharedWithMeCount ?>
            </span>
        </div>
        <?php if ($activeSharedFolder !== null): ?>
            <a href="index.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                &larr; Zurück zu meinen Dateien
            </a>
        <?php endif; ?>
    </div>

    <?php if ($totalSharedWithMeCount === 0): ?>
        <div class="p-8 text-center text-xs sm:text-sm text-slate-500 dark:text-slate-400">
            Aktuell wurden noch keine Ordner oder Dateien von anderen Cloud-Benutzern direkt mit Ihnen geteilt.
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4 sm:px-6">Name</th>
                        <th class="py-3 px-4">Geteilt von</th>
                        <th class="py-3 px-4">Berechtigung</th>
                        <th class="py-3 px-4 hidden sm:table-cell">Größe / Inhalt</th>
                        <th class="py-3 px-4 hidden md:table-cell">Freigegeben am</th>
                        <th class="py-3 px-4 sm:px-6 text-right">Aktion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">
                    <!-- 1. Freigegebene Ordner -->
                    <?php foreach ($sharedWithMeFolders as $sFolder): ?>
                        <?php
                        $usId = (int)$sFolder['user_share_id'];
                        $isSelectedSf = ($activeSharedFolderId === $usId);
                        $sfDate = date('d.m.Y H:i', strtotime((string)$sFolder['shared_at']));
                        $sfCount = (int)$sFolder['file_count'];
                        $sfSize  = (int)$sFolder['folder_size'];
                        $sfCanWrite  = ((int)($sFolder['can_write'] ?? 0) === 1);
                        $sfCanDelete = ((int)($sFolder['can_delete'] ?? 0) === 1);
                        $sfPermLabel = $sfCanDelete ? 'Lesen, Schreiben & Löschen' : ($sfCanWrite ? 'Lesen & Schreiben' : 'Nur Lesen');
                        $sfPermIcon  = $sfCanDelete ? '✏️🗑️' : ($sfCanWrite ? '✏️' : '👁️');
                        $sfPermBg    = $sfCanDelete
                            ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300'
                            : ($sfCanWrite ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300');
                        ?>
                        <tr class="<?= $isSelectedSf ? 'bg-blue-50/60 dark:bg-blue-950/30' : 'hover:bg-slate-50/80 dark:hover:bg-slate-800/40' ?> transition-colors">
                            <td class="py-3 px-4 sm:px-6">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold bg-amber-100 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300 shrink-0">
                                        ORDNER
                                    </span>
                                    <a href="<?= $isSelectedSf ? 'index.php' : 'index.php?shared_folder=' . $usId ?>" class="font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-xs">
                                        📁 <?= e((string)$sFolder['folder_path']) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 text-xs font-semibold">
                                    👤 <?= e((string)$sFolder['owner_username']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span
                                    class="inline-flex items-center justify-center px-2 h-8 rounded-lg <?= $sfPermBg ?> text-xs font-semibold"
                                    title="<?= e($sfPermLabel) ?>"
                                    aria-label="<?= e($sfPermLabel) ?>"
                                >
                                    <?= $sfPermIcon ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-slate-600 dark:text-slate-300 hidden sm:table-cell">
                                <?= $sfCount ?> <?= $sfCount === 1 ? 'Datei' : 'Dateien' ?> &bull; <?= e(formatBytes($sfSize)) ?>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-500 dark:text-slate-400 hidden md:table-cell">
                                <?= e($sfDate) ?> Uhr
                            </td>
                            <td class="py-3 px-4 sm:px-6 text-right">
                                <div class="inline-flex items-center justify-end gap-2">
                                    <a
                                        href="<?= $isSelectedSf ? 'index.php' : 'index.php?shared_folder=' . $usId ?>"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg <?= $isSelectedSf ? 'bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-100' : 'bg-indigo-600 hover:bg-indigo-700 text-white' ?> font-semibold text-xs transition-colors"
                                    >
                                        <span><?= $isSelectedSf ? 'Schließen' : 'Öffnen' ?></span>
                                    </a>
                                    <?php if ($sfCount > 0): ?>
                                        <a
                                            href="download.php?user_share_id=<?= $usId ?>"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                            title="Als ZIP herunterladen"
                                            aria-label="Als ZIP herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 2. Freigegebene Einzeldateien -->
                    <?php foreach ($sharedWithMeFiles as $sf): ?>
                        <?php
                        $sfBadge = getFileTypeBadge((string)$sf['original_name'], (string)$sf['mime_type']);
                        $sfDate  = date('d.m.Y H:i', strtotime((string)$sf['shared_at']));
                        $sfFileCanWrite  = ((int)($sf['can_write'] ?? 0) === 1);
                        $sfFileCanDelete = ((int)($sf['can_delete'] ?? 0) === 1);
                        $sfFilePermLabel = $sfFileCanDelete ? 'Lesen, Schreiben & Löschen' : ($sfFileCanWrite ? 'Lesen & Schreiben' : 'Nur Lesen');
                        $sfFilePermIcon  = $sfFileCanDelete ? '✏️🗑️' : ($sfFileCanWrite ? '✏️' : '👁️');
                        $sfFilePermBg    = $sfFileCanDelete
                            ? 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300'
                            : ($sfFileCanWrite ? 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300');
                        ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                            <td class="py-3 px-4 sm:px-6">
                                <?php
                                $sfOfficeType = getOfficeEditorType((string)$sf['original_name']);
                                $sfIsEnc = ((int)($sf['is_encrypted'] ?? 0) === 1);
                                ?>
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold <?= e($sfBadge['bg']) ?> <?= e($sfBadge['text']) ?> shrink-0">
                                        <?= e($sfBadge['label']) ?>
                                    </span>
                                    <?php if ($sfOfficeType !== null && !$sfIsEnc): ?>
                                        <a
                                            href="office.php?id=<?= (int)$sf['id'] ?>"
                                            class="font-medium text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-xs transition-colors"
                                            title="In Office Light öffnen"
                                        >
                                            <?= e((string)$sf['original_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="font-medium text-slate-900 dark:text-white truncate max-w-xs">
                                            <?= e((string)$sf['original_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($sfIsEnc): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300" title="Tresor-geschützt (AES-256)">
                                            🔒 Tresor
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 text-xs font-semibold">
                                    👤 <?= e((string)$sf['owner_username']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <span
                                    class="inline-flex items-center justify-center px-2 h-8 rounded-lg <?= $sfFilePermBg ?> text-xs font-semibold"
                                    title="<?= e($sfFilePermLabel) ?>"
                                    aria-label="<?= e($sfFilePermLabel) ?>"
                                >
                                    <?= $sfFilePermIcon ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-slate-600 dark:text-slate-300 hidden sm:table-cell">
                                <?= e(formatBytes((int)$sf['file_size'])) ?>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-500 dark:text-slate-400 hidden md:table-cell">
                                <?= e($sfDate) ?> Uhr
                            </td>
                            <td class="py-3 px-4 sm:px-6 text-right">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    <a
                                        href="download.php?id=<?= (int)$sf['id'] ?>"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                        title="Herunterladen"
                                        aria-label="Herunterladen"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                        </svg>
                                    </a>
                                    <?php if ($sfFileCanDelete): ?>
                                        <form
                                            action="delete.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return confirm('Möchten Sie die freigegebene Datei &quot;<?= e(addslashes((string)$sf['original_name'])) ?>&quot; wirklich in den Papierkorb verschieben?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="file_id" value="<?= (int)$sf['id'] ?>">
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                                title="Löschen"
                                                aria-label="Löschen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- TEILEN-MODAL (Popup für öffentliche Links & Teilen mit Cloud-Benutzern für Dateien UND Ordner) -->
<div
    id="shareModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="shareModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="min-w-0">
                <h3 id="shareModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                    Element teilen
                </h3>
                <p id="shareModalFilename" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
            </div>
            <button
                type="button"
                onclick="closeShareModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto space-y-6">
            <input type="hidden" id="shareModalFileId" value="">
            <input type="hidden" id="shareModalFolderPath" value="">

            <!-- 1. Öffentlichen Freigabelink generieren -->
            <div class="space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    1. Öffentlicher Freigabelink (für Freunde)
                </h4>

                <form id="createShareLinkForm" class="space-y-3 bg-slate-50 dark:bg-slate-800/50 p-3.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <div>
                            <label for="shareLinkPassword" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Passwort (optional)
                            </label>
                            <input
                                type="password"
                                id="shareLinkPassword"
                                name="password"
                                placeholder="Kein Passwort"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                        </div>
                        <div>
                            <label for="shareLinkExpires" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Ablaufdatum
                            </label>
                            <select
                                id="shareLinkExpires"
                                name="expires_days"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                                <option value="0">Unbegrenzt gültig</option>
                                <option value="1">1 Tag gültig</option>
                                <option value="7">7 Tage gültig</option>
                                <option value="30">30 Tage gültig</option>
                            </select>
                        </div>
                        <div>
                            <label for="shareLinkMaxDownloads" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1" title="Selbstzerstörender Link (Burn after reading)">
                                Max. Downloads 🔥
                            </label>
                            <select
                                id="shareLinkMaxDownloads"
                                name="max_downloads"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                                <option value="0">Unbegrenzt</option>
                                <option value="1">🔥 1× (Selbstzerstörend)</option>
                                <option value="3">🔥 3× Downloads</option>
                                <option value="5">5× Downloads</option>
                                <option value="10">10× Downloads</option>
                                <option value="25">25× Downloads</option>
                            </select>
                        </div>
                    </div>

                    <!-- Freigabe-Modus für Ordner-Links (Idee 1: Digitaler Briefkasten / File-Drop) -->
                    <div id="shareLinkModeWrapper" class="hidden">
                        <label for="shareLinkMode" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Ordner-Modus für Besucher dieses Links
                        </label>
                        <select
                            id="shareLinkMode"
                            name="share_mode"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="read">👁️ Nur Download (Besucher können Dateien ansehen &amp; herunterladen)</option>
                            <option value="upload_only">📤 Digitaler Briefkasten / Nur Hochladen (Besucher sehen keine fremden Dateien)</option>
                            <option value="read_write">🔄 Download &amp; Upload (Gemeinsamer Austausch-Ordner)</option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        id="btnGenerateLink"
                        class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        <span>Link &amp; QR-Code generieren</span>
                    </button>
                </form>

                <!-- Frisch generierter Link mit Kopieren-Button & lokalem QR-Code -->
                <div id="generatedLinkBox" class="hidden p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 space-y-3">
                    <div class="flex items-center justify-between text-xs font-semibold text-emerald-800 dark:text-emerald-300">
                        <span>Fertiger Freigabelink &amp; QR-Code:</span>
                        <span id="copyFeedback" class="hidden text-emerald-600 dark:text-emerald-400">✓ Kopiert!</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            id="generatedLinkInput"
                            readonly
                            class="flex-1 px-3 py-2 rounded-lg bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 text-xs font-mono text-slate-800 dark:text-slate-100 focus:outline-none"
                        >
                        <button
                            type="button"
                            onclick="copyGeneratedLink()"
                            class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shrink-0 cursor-pointer"
                        >
                            Kopieren
                        </button>
                    </div>
                    <div id="generatedLinkQrContainer" class="hidden pt-1 flex flex-col sm:flex-row items-center gap-3">
                        <div id="generatedLinkQrSvg" class="shrink-0"></div>
                        <div class="text-xs text-emerald-900 dark:text-emerald-200 space-y-1.5 text-center sm:text-left">
                            <p class="font-semibold">📱 Direkt per Smartphone scannen</p>
                            <p class="text-[11px] text-emerald-700 dark:text-emerald-300/80">
                                100 % lokal auf Ihrem Server erzeugt (ohne externe Dienste). Ideal zum schnellen Teilen oder Ausdrucken für den Digitalen Briefkasten.
                            </p>
                            <button
                                type="button"
                                onclick="downloadQrCodeSvg('generatedLinkQrSvg')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-800 border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 text-[11px] font-semibold hover:bg-emerald-100 cursor-pointer"
                            >
                                ⬇️ QR-Code als SVG speichern
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bestehende Freigabelinks -->
                <div id="existingLinksList" class="space-y-2"></div>
            </div>

            <!-- 2. Mit Cloud-Benutzer teilen (intern) -->
            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    2. Direkt mit Cloud-Benutzer teilen
                </h4>

                <?php if (empty($otherCloudUsers)): ?>
                    <p class="text-xs text-slate-500 dark:text-slate-400 italic">
                        Es hat noch kein anderer Cloud-Benutzer die Sichtbarkeit für Direktfreigaben in seinen Einstellungen aktiviert.
                    </p>
                <?php else: ?>
                    <form id="shareWithUserForm" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <select
                            id="shareTargetUserId"
                            required
                            class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="">Benutzer auswählen...</option>
                            <?php foreach ($otherCloudUsers as $ou): ?>
                                <option value="<?= (int)$ou['id'] ?>"><?= e((string)$ou['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select
                            id="shareTargetCanWrite"
                            class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="0">👁️ Nur Lesen</option>
                            <option value="1">✏️ Lesen &amp; Schreiben</option>
                            <option value="2">🗑️ Lesen, Schreiben &amp; Löschen</option>
                        </select>
                        <button
                            type="submit"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm cursor-pointer shrink-0"
                        >
                            Freigeben
                        </button>
                    </form>
                <?php endif; ?>

                <div id="existingUserSharesList" class="space-y-1.5"></div>
            </div>
        </div>
    </div>
</div>

<!-- TRESOR-MODAL (Einzeldatei-Verschlüsselung AES-256 Zero-Knowledge) -->
<div
    id="vaultModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="vaultModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 text-base shrink-0">
                    🔒
                </span>
                <div class="min-w-0">
                    <h3 id="vaultModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                        Tresor-Verschlüsselung
                    </h3>
                    <p id="vaultModalFilename" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeVaultModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto space-y-5">
            <!-- Wichtiger Zero-Knowledge-Warnhinweis -->
            <div class="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200/80 dark:border-amber-800/70 text-xs text-amber-900 dark:text-amber-200 leading-relaxed">
                <strong>⚠️ Wichtiger Hinweis (Zero-Knowledge):</strong> Ihr Tresor-Passwort wird <strong>nirgendwo gespeichert</strong>. Wenn Sie dieses Passwort vergessen, kann die Datei <strong>auch vom Administrator nicht wiederhergestellt werden!</strong>
            </div>

            <!-- A) ANSICHT: Datei verschlüsseln -->
            <form id="vaultEncryptForm" action="vault_action.php" method="post" class="space-y-3.5 hidden">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="encrypt_file">
                <input type="hidden" name="file_id" id="vaultEncryptFileId" value="">
                <?php if ($currentFolder !== ''): ?>
                    <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                <?php endif; ?>
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>

                <div>
                    <label for="vaultEncPw" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Tresor-Passwort festlegen (mind. 4 Zeichen)
                    </label>
                    <input
                        type="password"
                        id="vaultEncPw"
                        name="vault_password"
                        required
                        minlength="4"
                        autocomplete="new-password"
                        placeholder="Geheimes Tresor-Passwort..."
                        class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                </div>

                <div>
                    <label for="vaultEncPwConfirm" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Tresor-Passwort wiederholen
                    </label>
                    <input
                        type="password"
                        id="vaultEncPwConfirm"
                        name="vault_password_confirm"
                        required
                        minlength="4"
                        autocomplete="new-password"
                        placeholder="Passwort zur Sicherheit bestätigen..."
                        class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                >
                    <span>🔒 Jetzt mit AES-256 verschlüsseln</span>
                </button>
            </form>

            <!-- B) ANSICHT: Bereits verschlüsselte Tresor-Datei herunterladen oder dauerhaft entschlüsseln -->
            <div id="vaultProtectedSection" class="space-y-5 hidden">
                <!-- 1. Entschlüsselt herunterladen (Datei bleibt in der Cloud geschützt) -->
                <form id="vaultDownloadForm" action="download.php" method="post" class="space-y-3 bg-slate-50 dark:bg-slate-800/50 p-3.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="id" id="vaultDownloadFileId" value="">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                        1. Entschlüsselt herunterladen
                    </h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Lädt die Datei entschlüsselt auf Ihr Gerät herunter. In der Cloud bleibt der Tresor-Schutz weiter bestehen.
                    </p>
                    <input
                        type="password"
                        id="vaultDlPw"
                        name="vault_password"
                        required
                        placeholder="Tresor-Passwort eingeben..."
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <button
                        type="submit"
                        class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <span>📥 Entschlüsseln &amp; Herunterladen</span>
                    </button>
                </form>

                <!-- 2. Tresor-Schutz dauerhaft in der Cloud aufheben -->
                <form id="vaultDecryptForm" action="vault_action.php" method="post" class="space-y-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="decrypt_file">
                    <input type="hidden" name="file_id" id="vaultDecryptFileId" value="">
                    <?php if ($currentFolder !== ''): ?>
                        <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                    <?php endif; ?>
                    <?php if ($activeGroupFolder !== null): ?>
                        <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                    <?php endif; ?>

                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                        2. Tresor-Schutz dauerhaft aufheben
                    </h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Entschlüsselt die Datei dauerhaft auf dem Server, sodass sie wieder als normale Datei vorliegt.
                    </p>
                    <input
                        type="password"
                        id="vaultDecPw"
                        name="vault_password"
                        required
                        placeholder="Tresor-Passwort eingeben..."
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                    <button
                        type="submit"
                        class="w-full py-2 px-4 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-semibold text-xs sm:text-sm transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <span>🔓 Tresor-Schutz dauerhaft entfernen</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- VERSCHIEBEN / KOPIEREN MODAL (Für Dateien und Ordner über das ⇄ Icon) -->
<div
    id="transferModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="transferModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold text-base shrink-0">
                    ⇄
                </span>
                <div class="min-w-0">
                    <h3 id="transferModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                        Verschieben oder Kopieren
                    </h3>
                    <p id="transferModalItemName" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeTransferModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="transferModalForm" action="folder_action.php" method="post" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" id="transferModalAction" value="move_file">
            <input type="hidden" name="file_id" id="transferModalFileId" value="">
            <input type="hidden" name="batch_file_ids" id="transferModalBatchIds" value="">
            <input type="hidden" name="source_folder" id="transferModalSourceFolder" value="">
            <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
            <?php if ($activeGroupFolder !== null): ?>
                <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>

            <div>
                <label for="transferTargetSelect" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Aktion &amp; Zielordner auswählen
                </label>
                <select
                    id="transferTargetSelect"
                    name="target_folder"
                    required
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                >
                    <option value="">⇄ Verschieben / 📋 Kopieren...</option>
                    <optgroup id="optGroupMovePersonal" label="⇄ Verschieben – Meine Dateien">
                        <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
                        <?php foreach ($personalFoldersList as $pfRow): ?>
                            <?php $pfPath = (string)$pfRow['folder_path']; ?>
                            <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if (!empty($writableGroupFolders)): ?>
                        <optgroup id="optGroupMoveGroup" label="⇄ Verschieben – Gruppenordner">
                            <?php foreach ($writableGroupFolders as $wgf): ?>
                                <?php
                                $wgfId   = (int)$wgf['id'];
                                $wgfName = (string)$wgf['name'];
                                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                                ?>
                                <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                                <?php foreach ($wgfSubs as $gfSubRow): ?>
                                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                                    <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <optgroup label="📋 Kopieren – Meine Dateien">
                        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
                        <?php foreach ($personalFoldersList as $pfRow): ?>
                            <?php $pfPath = (string)$pfRow['folder_path']; ?>
                            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if (!empty($writableGroupFolders)): ?>
                        <optgroup label="📋 Kopieren – Gruppenordner">
                            <?php foreach ($writableGroupFolders as $wgf): ?>
                                <?php
                                $wgfId   = (int)$wgf['id'];
                                $wgfName = (string)$wgf['name'];
                                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                                ?>
                                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                                <?php foreach ($wgfSubs as $gfSubRow): ?>
                                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeTransferModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    Ausführen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- OFFICE LIGHT: NEUES DOKUMENT / NEUE TABELLE ANLEGEN MODAL -->
<div
    id="createOfficeModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="createOfficeModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-base shrink-0">
                    📄
                </span>
                <div>
                    <h3 id="createOfficeModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        MiniCloud Office Light
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Neues Dokument oder neue Tabelle direkt in der Cloud erstellen
                    </p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeCreateOfficeModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form action="office.php" method="post" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_document">
            <input type="hidden" name="target_folder" id="officeCreateTargetFolder" value="<?= e($currentFolder) ?>">
            <?php if ($activeSharedFolder !== null): ?>
                <input type="hidden" name="shared_folder_id" value="<?= (int)$activeSharedFolder['id'] ?>">
            <?php elseif ($activeGroupFolder !== null): ?>
                <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>

            <div>
                <label for="officeCreateType" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Dokumenttyp wählen
                </label>
                <select
                    id="officeCreateType"
                    name="doc_type"
                    onchange="updateOfficeDefaultFilename(this.value)"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                >
                    <option value="docx">📄 Word-Dokument (.docx)</option>
                    <option value="xlsx">📊 Excel-Tabelle (.xlsx)</option>
                    <option value="csv">📋 CSV-Tabelle (.csv)</option>
                    <option value="txt">📝 Textnotiz (.txt)</option>
                    <option value="md">📑 Markdown-Protokoll (.md)</option>
                </select>
            </div>

            <div>
                <label for="officeCreateName" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Dateiname
                </label>
                <input
                    type="text"
                    id="officeCreateName"
                    name="doc_name"
                    required
                    value="Neues_Dokument_<?= date('Y-m-d') ?>"
                    placeholder="z.B. Projektplan_2026"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <p class="text-[11px] text-slate-400 mt-1">
                    Die passende Dateiendung (<code>.docx</code>, <code>.xlsx</code> usw.) wird automatisch ergänzt.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeCreateOfficeModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    Erstellen &amp; Öffnen &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MOBILES AKTIONSMENÜ (Auf Smartphones über den kompakten ⋯ Button pro Zeile) -->
<div
    id="mobileActionModal"
    class="fixed inset-0 z-50 hidden items-end sm:items-center justify-center p-3 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="mobileActionTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-sm rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-4 py-3.5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
            <div class="min-w-0">
                <h3 id="mobileActionTitle" class="text-sm font-bold text-slate-900 dark:text-white truncate"></h3>
                <p id="mobileActionSubtitle" class="text-[11px] text-slate-500 dark:text-slate-400 truncate"></p>
            </div>
            <button
                type="button"
                onclick="closeMobileActionModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer shrink-0"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div id="mobileActionList" class="p-3 space-y-1.5"></div>

        <!-- Verstecktes Lösch-Formular für das mobile Aktionsmenü -->
        <form id="mobileDeleteForm" action="delete.php" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="file_id" id="mobileDeleteFileId" value="">
            <input type="hidden" name="delete_folder" id="mobileDeleteFolder" value="">
            <?php if ($currentFolder !== ''): ?>
                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
            <?php endif; ?>
            <?php if ($activeSharedFolder !== null): ?>
                <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
            <?php elseif ($activeGroupFolder !== null): ?>
                <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- DATEI-VORSCHAU MODAL MIT DURCHBLÄTTER-FUNKTION -->
<div id="previewModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-xs p-3 sm:p-6">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">
        <!-- Kopfbereich: Dateiinfos, Zähler & Aktionen -->
        <div class="p-3.5 sm:p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <span id="previewBadge" class="inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold tracking-tight bg-slate-100 text-slate-700 shrink-0">
                    FILE
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="previewFilename" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate max-w-[200px] sm:max-w-md md:max-w-xl"></h3>
                        <span id="previewCounter" class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300 shrink-0"></span>
                    </div>
                    <p id="previewMeta" class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5"></p>
                </div>
            </div>

            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                <!-- Durchblättern oben (Zurück / Weiter) -->
                <div id="previewNavTop" class="hidden sm:inline-flex items-center gap-1 mr-1">
                    <button
                        type="button"
                        onclick="stepPreview(-1)"
                        title="Vorherige Datei (Pfeiltaste links)"
                        aria-label="Vorherige Datei"
                        class="inline-flex items-center justify-center p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button
                        type="button"
                        onclick="stepPreview(1)"
                        title="Nächste Datei (Pfeiltaste rechts)"
                        aria-label="Nächste Datei"
                        class="inline-flex items-center justify-center p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <a
                    id="previewOfficeBtn"
                    href="#"
                    title="In Office Light öffnen / bearbeiten"
                    aria-label="In Office Light öffnen / bearbeiten"
                    class="hidden items-center gap-1.5 px-2.5 sm:px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition-colors"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span>Bearbeiten</span>
                </a>

                <a
                    id="previewDownloadBtn"
                    href="#"
                    title="Herunterladen"
                    aria-label="Herunterladen"
                    class="inline-flex items-center justify-center p-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                </a>

                <button
                    type="button"
                    onclick="closePreviewModal()"
                    title="Vorschau schließen (Esc)"
                    aria-label="Vorschau schließen"
                    class="inline-flex items-center justify-center p-2 rounded-xl text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Haupt-Vorschaubereich mit seitlichen Durchblätter-Buttons -->
        <div class="relative flex-1 min-h-[320px] sm:min-h-[460px] bg-slate-100/70 dark:bg-slate-950/60 flex items-center justify-center overflow-auto p-4 sm:p-6">
            <button
                type="button"
                id="previewPrevFloating"
                onclick="stepPreview(-1)"
                title="Vorherige Datei (←)"
                aria-label="Vorherige Datei"
                class="hidden absolute left-3 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 text-slate-800 dark:text-white shadow-lg border border-slate-200 dark:border-slate-700 items-center justify-center transition-transform hover:scale-105 cursor-pointer"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <div id="previewStage" class="w-full h-full flex items-center justify-center"></div>

            <button
                type="button"
                id="previewNextFloating"
                onclick="stepPreview(1)"
                title="Nächste Datei (→)"
                aria-label="Nächste Datei"
                class="hidden absolute right-3 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 text-slate-800 dark:text-white shadow-lg border border-slate-200 dark:border-slate-700 items-center justify-center transition-transform hover:scale-105 cursor-pointer"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        <!-- Fußleiste zum komfortablen Durchblättern -->
        <div id="previewFooterNav" class="hidden px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/90 items-center justify-between gap-3 text-xs shrink-0">
            <button
                type="button"
                onclick="stepPreview(-1)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 font-semibold transition-colors cursor-pointer"
            >
                <span>&larr; Vorherige Datei</span>
            </button>
            <span id="previewFooterHint" class="text-slate-500 dark:text-slate-400 hidden sm:inline">
                Tipp: Nutzen Sie die Pfeiltasten <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono">&larr;</kbd> <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono">&rarr;</kbd> zum Durchblättern
            </span>
            <button
                type="button"
                onclick="stepPreview(1)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 font-semibold transition-colors cursor-pointer"
            >
                <span>Nächste Datei &rarr;</span>
            </button>
        </div>
    </div>
</div>

<!-- Client-Side Drag & Drop, Multi-File, Directory Upload, Preview & Share Modal Script -->
<script>
const csrfToken = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const previewItems = <?= json_encode($previewItemsList ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentPreviewIndex = 0;

// --- DATEI-VORSCHAU MODAL LOGIK ---
const previewModal = document.getElementById('previewModal');
const previewStage = document.getElementById('previewStage');
const previewFilename = document.getElementById('previewFilename');
const previewCounter = document.getElementById('previewCounter');
const previewMeta = document.getElementById('previewMeta');
const previewBadge = document.getElementById('previewBadge');
const previewDownloadBtn = document.getElementById('previewDownloadBtn');
const previewNavTop = document.getElementById('previewNavTop');
const previewPrevFloating = document.getElementById('previewPrevFloating');
const previewNextFloating = document.getElementById('previewNextFloating');
const previewFooterNav = document.getElementById('previewFooterNav');

function isImagePreviewable(item) {
    const imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
    return item.mime.startsWith('image/') && item.ext !== 'svg' || imgExts.includes(item.ext);
}

function isPdfPreviewable(item) {
    return item.mime === 'application/pdf' || item.ext === 'pdf';
}

function isVideoPreviewable(item) {
    const vidExts = ['mp4', 'webm', 'ogg'];
    return item.mime.startsWith('video/') || vidExts.includes(item.ext);
}

function isAudioPreviewable(item) {
    const audExts = ['mp3', 'wav', 'ogg', 'flac', 'm4a'];
    return item.mime.startsWith('audio/') || audExts.includes(item.ext);
}

function isTextPreviewable(item) {
    const textExts = [
        'txt', 'md', 'csv', 'json', 'xml', 'log', 'sql', 'yml', 'yaml',
        'ini', 'conf', 'php', 'js', 'ts', 'css', 'html', 'htm', 'sh', 'py', 'c', 'cpp', 'h', 'java'
    ];
    return item.mime.startsWith('text/')
        || item.mime === 'application/json'
        || item.mime === 'application/xml'
        || textExts.includes(item.ext);
}

function isZipPreviewable(item) {
    return item.ext === 'zip'
        || item.mime === 'application/zip'
        || item.mime === 'application/x-zip-compressed';
}

function submitZipExtract(fileId, intoSubfolder) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'folder_action.php';
    form.className = 'hidden';

    const addField = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    addField('csrf_token', csrfToken);
    addField('action', 'extract_zip');
    addField('file_id', String(fileId));
    addField('into_subfolder', intoSubfolder ? '1' : '0');
    const curF = <?= json_encode($currentFolder) ?>;
    if (curF !== '') {
        addField('return_folder', curF);
    }

    document.body.appendChild(form);
    form.submit();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function renderPreviewItem(index) {
    if (!previewItems || previewItems.length === 0) return;
    const total = previewItems.length;
    currentPreviewIndex = ((index % total) + total) % total;
    const item = previewItems[currentPreviewIndex];

    previewFilename.textContent = item.name;
    previewCounter.textContent = `${currentPreviewIndex + 1} / ${total}`;
    previewMeta.textContent = `${item.size} • ${item.date} • 📁 ${item.folder}`;
    previewBadge.textContent = item.badgeLabel;
    previewBadge.className = `inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold tracking-tight shrink-0 ${item.badgeBg} ${item.badgeText}`;
    previewDownloadBtn.href = item.downloadUrl;

    const previewOfficeBtn = document.getElementById('previewOfficeBtn');
    if (previewOfficeBtn) {
        if (item.officeUrl && item.officeUrl !== '') {
            previewOfficeBtn.href = item.officeUrl;
            previewOfficeBtn.classList.remove('hidden');
            previewOfficeBtn.classList.add('inline-flex');
        } else {
            previewOfficeBtn.classList.add('hidden');
            previewOfficeBtn.classList.remove('inline-flex');
        }
    }

    const hasMultiple = total > 1;
    previewNavTop.classList.toggle('sm:inline-flex', hasMultiple);
    previewPrevFloating.classList.toggle('hidden', !hasMultiple);
    previewPrevFloating.classList.toggle('inline-flex', hasMultiple);
    previewNextFloating.classList.toggle('hidden', !hasMultiple);
    previewNextFloating.classList.toggle('inline-flex', hasMultiple);
    previewFooterNav.classList.toggle('hidden', !hasMultiple);
    previewFooterNav.classList.toggle('flex', hasMultiple);

    previewStage.innerHTML = '<div class="text-xs text-slate-400 py-12">Lade Vorschau...</div>';

    if (item.isEncrypted) {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800/80 shadow-xs text-center max-w-md space-y-4">
                <div class="mx-auto w-16 h-14 rounded-2xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 flex items-center justify-center font-bold text-2xl">
                    🔒
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm break-all">${escapeHtml(item.name)}</h4>
                    <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">Im Tresor verschlüsselt (AES-256 Zero-Knowledge)</p>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    Diese Datei ist mit einem persönlichen Tresor-Passwort verschlüsselt. Bitte geben Sie das Passwort ein, um die Datei zu entschlüsseln.
                </p>
                <button
                    type="button"
                    onclick="closePreviewModal(); openVaultModal(${Number(item.id)}, ${escapeHtml(JSON.stringify(item.name))}, true, ${item.canManage ? 'true' : 'false'});"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    <span>🔓 Tresor-Passwort eingeben</span>
                </button>
            </div>
        `;
    } else if (isImagePreviewable(item)) {
        previewStage.innerHTML = `
            <img
                src="${item.inlineUrl}"
                alt="${escapeHtml(item.name)}"
                class="max-h-[70vh] max-w-full rounded-xl shadow-md object-contain bg-white/50 dark:bg-slate-900/50"
            >
        `;
    } else if (isPdfPreviewable(item)) {
        previewStage.innerHTML = `
            <iframe
                src="${item.inlineUrl}"
                title="${escapeHtml(item.name)}"
                class="w-full h-[70vh] rounded-xl border border-slate-200 dark:border-slate-800 bg-white"
            ></iframe>
        `;
    } else if (isVideoPreviewable(item)) {
        previewStage.innerHTML = `
            <video controls autoplay class="max-h-[70vh] max-w-full rounded-xl shadow-md bg-black">
                <source src="${item.inlineUrl}">
                Ihr Browser unterstützt dieses Videoformat nicht.
            </video>
        `;
    } else if (isAudioPreviewable(item)) {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs text-center space-y-4 w-full max-w-md">
                <div class="mx-auto w-14 h-14 rounded-2xl ${item.badgeBg} ${item.badgeText} flex items-center justify-center font-bold text-sm">
                    ${escapeHtml(item.badgeLabel)}
                </div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm truncate">${escapeHtml(item.name)}</p>
                <audio controls autoplay class="w-full">
                    <source src="${item.inlineUrl}">
                </audio>
            </div>
        `;
    } else if (isZipPreviewable(item)) {
        try {
            const fd = new FormData();
            fd.append('action', 'inspect_zip');
            fd.append('csrf_token', csrfToken);
            fd.append('file_id', String(item.id));
            const res = await fetch('folder_action.php', { method: 'POST', body: fd });
            const zipData = await res.json();
            if (zipData.success) {
                const rowsHtml = (zipData.entries || []).map(eItem => `
                    <tr class="border-b border-slate-100 dark:border-slate-800/70">
                        <td class="py-1.5 px-3 font-mono text-xs ${eItem.is_dir ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-700 dark:text-slate-200'} break-all">
                            ${eItem.is_dir ? '📁 ' : '📄 '}${escapeHtml(String(eItem.name || ''))}
                        </td>
                        <td class="py-1.5 px-3 text-right text-[11px] text-slate-400 whitespace-nowrap">
                            ${eItem.is_dir ? 'Ordner' : escapeHtml(String(eItem.size || ''))}
                        </td>
                    </tr>
                `).join('');

                previewStage.innerHTML = `
                    <div class="w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden flex flex-col max-h-[70vh]">
                        <div class="p-4 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/70 dark:bg-slate-800/40">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>📦 ZIP-Archiv-Inspektor</span>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                        ${Number(zipData.file_count || 0)} Dateien • Entpackt: ${escapeHtml(String(zipData.total_size_human || '0 B'))}
                                    </span>
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    Inhalt direkt im Browser prüfen oder mit 1 Klick auf dem Server entpacken
                                </p>
                            </div>
                            ${item.canManage ? `
                                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                                    <button
                                        type="button"
                                        onclick="submitZipExtract(${Number(item.id)}, true)"
                                        class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                                        title="Erstellt einen eigenen Unterordner mit dem Namen des ZIP-Archivs und entpackt alle Dateien dorthin"
                                    >
                                        📂 In Unterordner entpacken
                                    </button>
                                    <button
                                        type="button"
                                        onclick="submitZipExtract(${Number(item.id)}, false)"
                                        class="px-3 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100 text-xs font-semibold transition-colors cursor-pointer"
                                        title="Entpackt den Inhalt direkt in das aktuelle Verzeichnis"
                                    >
                                        📥 Direkt hierhin
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <table class="w-full text-left border-collapse">
                                <tbody>
                                    ${rowsHtml || '<tr><td class="p-4 text-xs text-slate-400 text-center">Archiv ist leer.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                previewStage.innerHTML = `<p class="text-xs text-red-500">${escapeHtml(zipData.message || 'ZIP-Archiv konnte nicht gelesen werden.')}</p>`;
            }
        } catch {
            previewStage.innerHTML = '<p class="text-xs text-red-500">Fehler beim Inspizieren des ZIP-Archivs.</p>';
        }
    } else if (isTextPreviewable(item)) {
        try {
            const res = await fetch(item.textUrl);
            const text = await res.text();
            previewStage.innerHTML = `
                <pre class="w-full h-[68vh] overflow-auto p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-xs font-mono text-slate-800 dark:text-slate-200 whitespace-pre-wrap break-words leading-relaxed">${escapeHtml(text)}</pre>
            `;
        } catch {
            previewStage.innerHTML = '<p class="text-xs text-red-500">Textvorschau konnte nicht geladen werden.</p>';
        }
    } else {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs text-center max-w-md space-y-4">
                <div class="mx-auto w-16 h-14 rounded-2xl ${item.badgeBg} ${item.badgeText} flex items-center justify-center font-bold text-sm">
                    ${escapeHtml(item.badgeLabel)}
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm break-all">${escapeHtml(item.name)}</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">${escapeHtml(item.size)} &bull; ${escapeHtml(item.mime)}</p>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    ${item.officeUrl ? 'Dieses Office-Dokument kann direkt online in <strong>MiniCloud Office Light</strong> geöffnet und bearbeitet werden.' : 'Für diesen Dateityp ist keine direkte Browser-Vorschau verfügbar.'}
                </p>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    ${item.officeUrl ? `
                        <a
                            href="${item.officeUrl}"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-xs transition-colors"
                        >
                            <span>✏️ In Office Light öffnen</span>
                        </a>
                    ` : ''}
                    <a
                        href="${item.downloadUrl}"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs transition-colors"
                    >
                        <span>Datei herunterladen</span>
                    </a>
                </div>
            </div>
        `;
    }
}

function openPreviewModal(index) {
    if (!previewItems || previewItems.length === 0) return;
    previewModal.classList.remove('hidden');
    previewModal.classList.add('flex');
    renderPreviewItem(index);
}

function closePreviewModal() {
    previewModal.classList.add('hidden');
    previewModal.classList.remove('flex');
    previewStage.innerHTML = '';
}

function stepPreview(delta) {
    if (!previewItems || previewItems.length <= 1) return;
    renderPreviewItem(currentPreviewIndex + delta);
}

document.addEventListener('keydown', (e) => {
    if (previewModal.classList.contains('hidden')) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        closePreviewModal();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        stepPreview(-1);
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        stepPreview(1);
    }
});

previewModal.addEventListener('click', (e) => {
    if (e.target === previewModal) {
        closePreviewModal();
    }
});

// Touch-Swipe-Unterstützung für Mobilgeräte im Vorschau-Modal
(() => {
    let touchStartX = 0;
    let touchStartY = 0;
    previewModal.addEventListener('touchstart', (e) => {
        if (!e.touches || e.touches.length !== 1) return;
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }, { passive: true });

    previewModal.addEventListener('touchend', (e) => {
        if (!e.changedTouches || e.changedTouches.length !== 1) return;
        const dx = e.changedTouches[0].clientX - touchStartX;
        const dy = e.changedTouches[0].clientY - touchStartY;
        if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            stepPreview(dx < 0 ? 1 : -1);
        }
    }, { passive: true });
})();

// --- TRESOR MODAL LOGIK (AES-256 Einzeldatei-Verschlüsselung) ---
const vaultModal = document.getElementById('vaultModal');
const vaultModalTitle = document.getElementById('vaultModalTitle');
const vaultModalFilename = document.getElementById('vaultModalFilename');
const vaultEncryptForm = document.getElementById('vaultEncryptForm');
const vaultProtectedSection = document.getElementById('vaultProtectedSection');
const vaultDecryptForm = document.getElementById('vaultDecryptForm');

function openVaultModal(fileId, fileName, isEncrypted, canManage = true) {
    vaultModalFilename.textContent = fileName || '';
    document.getElementById('vaultEncryptFileId').value = String(fileId || 0);
    document.getElementById('vaultDownloadFileId').value = String(fileId || 0);
    document.getElementById('vaultDecryptFileId').value = String(fileId || 0);

    document.getElementById('vaultEncPw').value = '';
    document.getElementById('vaultEncPwConfirm').value = '';
    document.getElementById('vaultDlPw').value = '';
    document.getElementById('vaultDecPw').value = '';

    if (isEncrypted) {
        vaultModalTitle.textContent = 'Tresor-geschützte Datei';
        vaultEncryptForm.classList.add('hidden');
        vaultProtectedSection.classList.remove('hidden');
        if (canManage) {
            vaultDecryptForm.classList.remove('hidden');
        } else {
            vaultDecryptForm.classList.add('hidden');
        }
        setTimeout(() => document.getElementById('vaultDlPw').focus(), 50);
    } else {
        vaultModalTitle.textContent = 'Datei im Tresor verschlüsseln';
        vaultProtectedSection.classList.add('hidden');
        vaultEncryptForm.classList.remove('hidden');
        setTimeout(() => document.getElementById('vaultEncPw').focus(), 50);
    }

    vaultModal.classList.remove('hidden');
    vaultModal.classList.add('flex');
}

function closeVaultModal() {
    vaultModal.classList.add('hidden');
    vaultModal.classList.remove('flex');
}

vaultModal.addEventListener('click', (e) => {
    if (e.target === vaultModal) {
        closeVaultModal();
    }
});

// --- ANSICHTSUMSCHALTER (Liste <-> Kacheln) & FAVORITEN & MEHRFACHAUSWAHL ---
function setFileViewMode(mode) {
    const listView = document.getElementById('fileListView');
    const gridView = document.getElementById('fileGridView');
    const btnList = document.getElementById('btnViewList');
    const btnGrid = document.getElementById('btnViewGrid');
    const isGrid = (mode === 'grid');

    try {
        localStorage.setItem('mc_file_view_mode', isGrid ? 'grid' : 'list');
    } catch {}

    if (listView && gridView) {
        if (isGrid) {
            listView.classList.add('hidden');
            gridView.classList.remove('hidden');
            gridView.classList.add('grid');
        } else {
            gridView.classList.add('hidden');
            gridView.classList.remove('grid');
            listView.classList.remove('hidden');
        }
    }

    const activeCls = ['bg-white', 'dark:bg-slate-700', 'text-brand-600', 'dark:text-brand-400', 'shadow-2xs'];
    const inactiveCls = ['text-slate-600', 'dark:text-slate-400'];

    if (btnList && btnGrid) {
        if (isGrid) {
            btnGrid.classList.add(...activeCls);
            btnGrid.classList.remove(...inactiveCls);
            btnList.classList.remove(...activeCls);
            btnList.classList.add(...inactiveCls);
        } else {
            btnList.classList.add(...activeCls);
            btnList.classList.remove(...inactiveCls);
            btnGrid.classList.remove(...activeCls);
            btnGrid.classList.add(...inactiveCls);
        }
    }
}

(() => {
    try {
        const savedView = localStorage.getItem('mc_file_view_mode');
        if (savedView === 'grid') {
            setFileViewMode('grid');
        } else {
            setFileViewMode('list');
        }
    } catch {
        setFileViewMode('list');
    }
})();

async function toggleFavorite(fileId, btnEl) {
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_favorite');
        fd.append('csrf_token', csrfToken);
        fd.append('file_id', String(fileId));
        const res = await fetch('folder_action.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
        });
        const data = await res.json();
        if (!data.success) return;
        const isFav = Boolean(data.is_favorite);
        document.querySelectorAll(`[data-fav-btn-id="${fileId}"]`).forEach(el => {
            el.setAttribute('data-fav', isFav ? '1' : '0');
            el.textContent = isFav ? '⭐' : '☆';
            el.title = isFav ? 'Aus Favoriten entfernen' : 'Als Favorit markieren';
            if (isFav) {
                el.classList.add('text-amber-400');
                el.classList.remove('text-slate-300', 'dark:text-slate-600');
            } else {
                el.classList.remove('text-amber-400');
                el.classList.add('text-slate-300', 'dark:text-slate-600');
            }
        });
        if (previewItems) {
            const pItem = previewItems.find(it => Number(it.id) === Number(fileId));
            if (pItem) pItem.isFavorite = isFav;
        }
    } catch {}
}

function getSelectedFileCheckboxes() {
    return Array.from(document.querySelectorAll('.file-select-checkbox:checked'));
}

function updateBatchSelection() {
    const allCbs = Array.from(document.querySelectorAll('.file-select-checkbox'));
    const checkedCbs = getSelectedFileCheckboxes();
    const selectAllCb = document.getElementById('selectAllFilesCheckbox');
    const bar = document.getElementById('batchActionBar');
    const countEl = document.getElementById('batchSelectedCount');
    const btnDelete = document.getElementById('btnBatchDelete');

    if (selectAllCb && allCbs.length > 0) {
        selectAllCb.checked = (checkedCbs.length === allCbs.length);
        selectAllCb.indeterminate = (checkedCbs.length > 0 && checkedCbs.length < allCbs.length);
    }

    if (!bar || !countEl) return;
    if (checkedCbs.length === 0) {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
        return;
    }

    countEl.textContent = String(checkedCbs.length);
    const anyDeletable = checkedCbs.some(cb => cb.getAttribute('data-can-delete') === '1');
    if (btnDelete) {
        btnDelete.classList.toggle('hidden', !anyDeletable);
    }
    bar.classList.remove('hidden');
    bar.classList.add('flex');
}

function toggleSelectAllFiles(masterCb) {
    const checked = Boolean(masterCb && masterCb.checked);
    document.querySelectorAll('.file-select-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateBatchSelection();
}

function clearBatchSelection() {
    const selectAllCb = document.getElementById('selectAllFilesCheckbox');
    if (selectAllCb) {
        selectAllCb.checked = false;
        selectAllCb.indeterminate = false;
    }
    document.querySelectorAll('.file-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateBatchSelection();
}

function downloadBatchZip() {
    const ids = getSelectedFileCheckboxes().map(cb => cb.value).filter(Boolean);
    if (ids.length === 0) return;
    window.location.href = 'download.php?batch_ids=' + encodeURIComponent(ids.join(','));
}

function openBatchTransferModal() {
    const checkedCbs = getSelectedFileCheckboxes();
    if (checkedCbs.length === 0) return;
    const ids = checkedCbs.map(cb => cb.value);
    const allCanManage = checkedCbs.every(cb => cb.getAttribute('data-can-manage') === '1');

    transferModalTitle.textContent = 'Ausgewählte Dateien verschieben / kopieren';
    transferModalItemName.textContent = `☑️ ${ids.length} ${ids.length === 1 ? 'ausgewählte Datei' : 'ausgewählte Dateien'}`;
    transferModalAction.value = 'batch_transfer_files';
    transferModalFileId.value = '';
    document.getElementById('transferModalBatchIds').value = ids.join(',');
    transferModalSourceFolder.value = '';
    transferTargetSelect.setAttribute('name', 'target_folder');
    transferTargetSelect.value = '';

    if (optGroupMovePersonal) {
        optGroupMovePersonal.disabled = !allCanManage;
        optGroupMovePersonal.hidden = !allCanManage;
    }
    if (optGroupMoveGroup) {
        optGroupMoveGroup.disabled = !allCanManage;
        optGroupMoveGroup.hidden = !allCanManage;
    }

    transferModal.classList.remove('hidden');
    transferModal.classList.add('flex');
    setTimeout(() => transferTargetSelect.focus(), 50);
}

function deleteBatchFiles() {
    const checkedCbs = getSelectedFileCheckboxes().filter(cb => cb.getAttribute('data-can-delete') === '1');
    if (checkedCbs.length === 0) return;
    const ids = checkedCbs.map(cb => cb.value);
    if (!confirm(`Möchten Sie die ${ids.length} ausgewählten Datei(en) wirklich in den Papierkorb verschieben?`)) {
        return;
    }
    const input = document.getElementById('batchDeleteIdsInput');
    const form = document.getElementById('batchDeleteForm');
    if (input && form) {
        input.value = ids.join(',');
        form.submit();
    }
}

// --- VERSCHIEBEN / KOPIEREN MODAL LOGIK ---
const transferModal = document.getElementById('transferModal');
const transferModalTitle = document.getElementById('transferModalTitle');
const transferModalItemName = document.getElementById('transferModalItemName');
const transferModalAction = document.getElementById('transferModalAction');
const transferModalFileId = document.getElementById('transferModalFileId');
const transferModalSourceFolder = document.getElementById('transferModalSourceFolder');
const transferTargetSelect = document.getElementById('transferTargetSelect');
const optGroupMovePersonal = document.getElementById('optGroupMovePersonal');
const optGroupMoveGroup = document.getElementById('optGroupMoveGroup');

function openTransferModal(type, fileId, displayName, folderPath = '', canMove = true) {
    const batchIdsEl = document.getElementById('transferModalBatchIds');
    if (batchIdsEl) batchIdsEl.value = '';
    if (type === 'folder') {
        transferModalTitle.textContent = 'Ordner verschieben oder kopieren';
        transferModalItemName.textContent = '📁 ' + displayName;
        transferModalAction.value = 'move_folder';
        transferModalFileId.value = '';
        transferModalSourceFolder.value = folderPath;
        transferTargetSelect.setAttribute('name', 'target_parent');
    } else {
        transferModalTitle.textContent = 'Datei verschieben oder kopieren';
        transferModalItemName.textContent = displayName;
        transferModalAction.value = 'move_file';
        transferModalFileId.value = String(fileId || 0);
        transferModalSourceFolder.value = '';
        transferTargetSelect.setAttribute('name', 'target_folder');
    }

    transferTargetSelect.value = '';
    if (optGroupMovePersonal) {
        optGroupMovePersonal.disabled = !canMove;
        optGroupMovePersonal.hidden = !canMove;
    }
    if (optGroupMoveGroup) {
        optGroupMoveGroup.disabled = !canMove;
        optGroupMoveGroup.hidden = !canMove;
    }

    transferModal.classList.remove('hidden');
    transferModal.classList.add('flex');
    setTimeout(() => transferTargetSelect.focus(), 50);
}

function closeTransferModal() {
    transferModal.classList.add('hidden');
    transferModal.classList.remove('flex');
}

transferModal.addEventListener('click', (e) => {
    if (e.target === transferModal) {
        closeTransferModal();
    }
});

// --- OFFICE LIGHT MODAL (Neues Dokument / Neue Tabelle anlegen) ---
const createOfficeModal = document.getElementById('createOfficeModal');
const officeCreateNameInput = document.getElementById('officeCreateName');
const officeCreateTargetFolder = document.getElementById('officeCreateTargetFolder');

function openCreateOfficeModal() {
    if (!createOfficeModal) return;
    const uploadFolderInput = document.getElementById('target_folder');
    if (uploadFolderInput && officeCreateTargetFolder) {
        officeCreateTargetFolder.value = uploadFolderInput.value;
    }
    createOfficeModal.classList.remove('hidden');
    createOfficeModal.classList.add('flex');
    if (officeCreateNameInput) {
        setTimeout(() => {
            officeCreateNameInput.focus();
            officeCreateNameInput.select();
        }, 50);
    }
}

function closeCreateOfficeModal() {
    if (!createOfficeModal) return;
    createOfficeModal.classList.add('hidden');
    createOfficeModal.classList.remove('flex');
}

function updateOfficeDefaultFilename(type) {
    if (!officeCreateNameInput) return;
    const today = <?= json_encode(date('Y-m-d')) ?>;
    const defaults = {
        docx: 'Neues_Dokument_' + today,
        xlsx: 'Neue_Tabelle_' + today,
        csv:  'Neue_Liste_' + today,
        txt:  'Neue_Notiz_' + today,
        md:   'Neues_Protokoll_' + today
    };
    const cur = officeCreateNameInput.value.trim();
    if (cur === '' || cur.startsWith('Neues_') || cur.startsWith('Neue_')) {
        officeCreateNameInput.value = defaults[type] || ('Neues_Dokument_' + today);
    }
}

if (createOfficeModal) {
    createOfficeModal.addEventListener('click', (e) => {
        if (e.target === createOfficeModal) {
            closeCreateOfficeModal();
        }
    });
}

// --- MOBILES AKTIONSMENÜ (⋯ Button pro Datei- oder Ordnerzeile auf Smartphones) ---
const mobileActionModal = document.getElementById('mobileActionModal');
const mobileActionTitle = document.getElementById('mobileActionTitle');
const mobileActionSubtitle = document.getElementById('mobileActionSubtitle');
const mobileActionList = document.getElementById('mobileActionList');
const mobileDeleteForm = document.getElementById('mobileDeleteForm');
const mobileDeleteFileId = document.getElementById('mobileDeleteFileId');
const mobileDeleteFolder = document.getElementById('mobileDeleteFolder');

function closeMobileActionModal() {
    if (!mobileActionModal) return;
    mobileActionModal.classList.add('hidden');
    mobileActionModal.classList.remove('flex');
}

if (mobileActionModal) {
    mobileActionModal.addEventListener('click', (e) => {
        if (e.target === mobileActionModal) {
            closeMobileActionModal();
        }
    });
}

function openMobileFileMenu(fileIdx, canDelete) {
    if (!previewItems || !previewItems[fileIdx] || !mobileActionModal) return;
    const item = previewItems[fileIdx];
    mobileActionTitle.textContent = item.name;
    mobileActionSubtitle.textContent = `${item.size} • ${item.date}`;
    mobileActionList.innerHTML = '';

    const addBtn = (label, cls, onClickFn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = `w-full flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition-colors cursor-pointer text-left ${cls}`;
        b.textContent = label;
        b.addEventListener('click', () => {
            closeMobileActionModal();
            onClickFn();
        });
        mobileActionList.appendChild(b);
    };

    // 1. Vorschau
    addBtn('👁️ Vorschau öffnen', 'bg-violet-50 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 hover:bg-violet-100', () => {
        openPreviewModal(fileIdx);
    });

    // 1b. Favorit umschalten
    if (item.canManage) {
        addBtn(item.isFavorite ? '⭐ Aus Favoriten entfernen' : '☆ Als Favorit markieren', 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 hover:bg-amber-100', () => {
            toggleFavorite(item.id);
        });
    }

    // 2. Office Light (falls unterstützt & unverschlüsselt)
    if (item.officeUrl && item.officeUrl !== '') {
        addBtn(item.canManage ? '✏️ In Office Light bearbeiten' : '📄 In Office Light öffnen', 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100', () => {
            window.location.href = item.officeUrl;
        });
    }

    // 3. Herunterladen
    addBtn(item.isEncrypted ? '🔓 Entschlüsseln & Herunterladen' : '⬇️ Datei herunterladen', 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100', () => {
        if (item.isEncrypted) {
            openVaultModal(item.id, item.name, true, item.canManage);
        } else {
            window.location.href = item.downloadUrl;
        }
    });

    // 4. Tresor
    if (item.canManage || item.isEncrypted) {
        addBtn(item.isEncrypted ? '🔒 Tresor-Schutz verwalten' : '🔒 Im Tresor verschlüsseln (AES-256)', 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-100', () => {
            openVaultModal(item.id, item.name, item.isEncrypted, item.canManage);
        });
    }

    // 5. Verschieben / Kopieren
    addBtn('⇄ Verschieben oder kopieren', 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200', () => {
        openTransferModal('file', item.id, item.name, item.rawFolder || '', item.canManage);
    });

    // 6. Teilen
    if (item.canManage) {
        addBtn('🔗 Teilen (Link / Benutzer)', 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100', () => {
            openShareModal(item.id, item.name, '');
        });
    }

    // 7. Löschen
    if (canDelete) {
        addBtn('🗑️ In den Papierkorb verschieben', 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 hover:bg-red-100', () => {
            if (confirm(`Möchten Sie die Datei "${item.name}" in den Papierkorb verschieben?`)) {
                mobileDeleteFolder.value = '';
                mobileDeleteFolder.disabled = true;
                mobileDeleteFileId.disabled = false;
                mobileDeleteFileId.value = String(item.id);
                mobileDeleteForm.submit();
            }
        });
    }

    mobileActionModal.classList.remove('hidden');
    mobileActionModal.classList.add('flex');
}

function openMobileFolderMenu(displayName, subPath, fileCount, canZip, canShare, canDelete) {
    if (!mobileActionModal) return;
    mobileActionTitle.textContent = '📁 ' + displayName;
    mobileActionSubtitle.textContent = `${fileCount} ${fileCount === 1 ? 'Datei' : 'Dateien'}`;
    mobileActionList.innerHTML = '';

    const addBtn = (label, cls, onClickFn) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = `w-full flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition-colors cursor-pointer text-left ${cls}`;
        b.textContent = label;
        b.addEventListener('click', () => {
            closeMobileActionModal();
            onClickFn();
        });
        mobileActionList.appendChild(b);
    };

    if (canZip) {
        addBtn('⬇️ Ordner als ZIP herunterladen', 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100', () => {
            window.location.href = 'download.php?folder=' + encodeURIComponent(subPath);
        });
    }

    addBtn('⇄ Ordner verschieben oder kopieren', 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-100', () => {
        openTransferModal('folder', 0, displayName, subPath, canDelete);
    });

    if (canShare) {
        addBtn('🔗 Ordner teilen', 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100', () => {
            openShareModal(0, subPath, subPath);
        });
    }

    if (canDelete) {
        addBtn('🗑️ Ordner in den Papierkorb verschieben', 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 hover:bg-red-100', () => {
            if (confirm(`Möchten Sie den Ordner "${subPath}" in den Papierkorb verschieben?`)) {
                mobileDeleteFileId.value = '';
                mobileDeleteFileId.disabled = true;
                mobileDeleteFolder.disabled = false;
                mobileDeleteFolder.value = subPath;
                mobileDeleteForm.submit();
            }
        });
    }

    mobileActionModal.classList.remove('hidden');
    mobileActionModal.classList.add('flex');
}

// --- SHARE MODAL LOGIK (für Dateien UND Ordner) ---
const shareModal = document.getElementById('shareModal');
const shareModalTitle = document.getElementById('shareModalTitle');
const shareModalFileId = document.getElementById('shareModalFileId');
const shareModalFolderPath = document.getElementById('shareModalFolderPath');
const shareModalFilename = document.getElementById('shareModalFilename');
const generatedLinkBox = document.getElementById('generatedLinkBox');
const generatedLinkInput = document.getElementById('generatedLinkInput');
const existingLinksList = document.getElementById('existingLinksList');
const existingUserSharesList = document.getElementById('existingUserSharesList');

function openShareModal(fileId, itemName, folderPath = '') {
    shareModalFileId.value = String(fileId || 0);
    shareModalFolderPath.value = folderPath || '';
    shareModalTitle.textContent = folderPath ? 'Ordner teilen' : 'Datei teilen';
    shareModalFilename.textContent = folderPath ? ('📁 ' + itemName) : itemName;
    document.getElementById('shareLinkPassword').value = '';
    document.getElementById('shareLinkExpires').value = '0';
    const maxDlSelect = document.getElementById('shareLinkMaxDownloads');
    if (maxDlSelect) maxDlSelect.value = '0';
    const modeWrapper = document.getElementById('shareLinkModeWrapper');
    const modeSelect = document.getElementById('shareLinkMode');
    if (modeWrapper && modeSelect) {
        modeSelect.value = 'read';
        if (folderPath) {
            modeWrapper.classList.remove('hidden');
        } else {
            modeWrapper.classList.add('hidden');
        }
    }
    generatedLinkBox.classList.add('hidden');
    shareModal.classList.remove('hidden');
    shareModal.classList.add('flex');
    loadShareDetails(fileId, folderPath);
}

function closeShareModal() {
    shareModal.classList.add('hidden');
    shareModal.classList.remove('flex');
}

function downloadQrCodeSvg(containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    const svg = el.querySelector('svg');
    if (!svg) return;
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'minicloud-freigabe-qr.svg';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function toggleExistingLinkQr(shareId) {
    const box = document.getElementById('linkQrBox_' + shareId);
    if (box) {
        box.classList.toggle('hidden');
        box.classList.toggle('flex');
    }
}

function copyGeneratedLink(customUrl) {
    const urlToCopy = customUrl || generatedLinkInput.value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(urlToCopy);
    } else {
        generatedLinkInput.select();
        document.execCommand('copy');
    }
    const fb = document.getElementById('copyFeedback');
    fb.classList.remove('hidden');
    setTimeout(() => fb.classList.add('hidden'), 2000);
}

async function loadShareDetails(fileId, folderPath = '') {
    existingLinksList.innerHTML = '<p class="text-xs text-slate-400">Lade Freigaben...</p>';
    existingUserSharesList.innerHTML = '';

    try {
        const queryParam = folderPath
            ? `folder_path=${encodeURIComponent(folderPath)}`
            : `file_id=${encodeURIComponent(fileId)}`;
        const res = await fetch(`share_action.php?action=list&${queryParam}`);
        const data = await res.json();
        if (!data.success) {
            existingLinksList.innerHTML = '';
            return;
        }

        // Bestehende Links rendern
        if (data.links.length === 0) {
            existingLinksList.innerHTML = '<p class="text-xs text-slate-400">Noch keine aktiven Freigabelinks vorhanden.</p>';
        } else {
            existingLinksList.innerHTML = data.links.map(l => {
                let modeBadge = '';
                if (folderPath) {
                    if (l.share_mode === 'upload_only') {
                        modeBadge = ' &bull; <span class="text-amber-600 dark:text-amber-400 font-semibold">📤 Digitaler Briefkasten (Nur Upload)</span>';
                    } else if (l.share_mode === 'read_write') {
                        modeBadge = ' &bull; <span class="text-emerald-600 dark:text-emerald-400 font-semibold">🔄 Download &amp; Upload</span>';
                    } else {
                        modeBadge = ' &bull; <span>👁️ Nur Download</span>';
                    }
                }
                let burnBadge = '';
                if (l.max_downloads !== null && Number(l.max_downloads) > 0) {
                    burnBadge = ` &bull; <span class="text-rose-600 dark:text-rose-400 font-semibold">🔥 ${Number(l.download_count || 0)}/${Number(l.max_downloads)} Downloads</span>`;
                }
                const sid = Number(l.id) || 0;
                return `
                <div class="p-2.5 rounded-xl bg-slate-100/80 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <input type="text" readonly value="${escapeHtml(String(l.url || ''))}" class="w-full bg-transparent font-mono text-slate-700 dark:text-slate-200 truncate focus:outline-none">
                            </div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                ${l.has_password ? '🔒 Passwortgeschützt' : '🌐 Öffentlich'}
                                ${l.expires_at ? ' &bull; Gültig bis ' + escapeHtml(String(l.expires_at)) : ' &bull; Unbegrenzt'}
                                ${burnBadge}
                                ${modeBadge}
                            </div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            ${l.qr_svg ? `
                                <button type="button" onclick="toggleExistingLinkQr(${sid})" class="px-2 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-brand-50 cursor-pointer" title="QR-Code anzeigen">
                                    📱 QR
                                </button>
                            ` : ''}
                            <button type="button" data-copy-url="${escapeHtml(String(l.url || ''))}" onclick="copyGeneratedLink(this.getAttribute('data-copy-url'))" class="px-2 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-brand-50 cursor-pointer">
                                Kopieren
                            </button>
                            <button type="button" onclick="deleteShareLink(${sid})" class="px-2 py-1 rounded bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300 hover:bg-red-100 cursor-pointer">
                                Löschen
                            </button>
                        </div>
                    </div>
                    ${l.qr_svg ? `
                        <div id="linkQrBox_${sid}" class="hidden items-center gap-3 pt-2 border-t border-slate-200 dark:border-slate-700">
                            <div id="linkQrSvg_${sid}" class="shrink-0">${l.qr_svg}</div>
                            <div class="space-y-1">
                                <p class="font-semibold text-slate-800 dark:text-slate-200">📱 QR-Code für diesen Link</p>
                                <button type="button" onclick="downloadQrCodeSvg('linkQrSvg_${sid}')" class="px-2.5 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-[11px] font-semibold cursor-pointer">
                                    ⬇️ Als SVG speichern
                                </button>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
            }).join('');
        }

        // Bestehende Benutzer-Freigaben rendern
        if (data.user_shares.length === 0) {
            existingUserSharesList.innerHTML = '<p class="text-xs text-slate-400">Noch mit keinem anderen Cloud-Benutzer geteilt.</p>';
        } else {
            existingUserSharesList.innerHTML = data.user_shares.map(u => {
                const permLabel = u.can_delete
                    ? '🗑️ Lesen, Schreiben & Löschen'
                    : (u.can_write ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen');
                return `
                <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 border border-blue-200/70 dark:border-blue-800/60 text-xs">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="font-medium text-blue-900 dark:text-blue-200 truncate">👤 <strong>${escapeHtml(String(u.username || ''))}</strong></span>
                        <span class="px-1.5 py-0.5 rounded bg-white/80 dark:bg-slate-800 text-[11px] font-semibold">
                            ${permLabel}
                        </span>
                    </div>
                    <button type="button" onclick="revokeUserShare(${Number(u.id) || 0})" class="text-red-600 dark:text-red-400 hover:underline font-medium cursor-pointer shrink-0">
                        Entfernen
                    </button>
                </div>
                `;
            }).join('');
        }
    } catch {
        existingLinksList.innerHTML = '';
    }
}

document.getElementById('createShareLinkForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fileId = shareModalFileId.value;
    const folderPath = shareModalFolderPath.value;
    const fd = new FormData();
    fd.append('action', 'create_link');
    fd.append('csrf_token', csrfToken);
    fd.append('file_id', fileId);
    fd.append('folder_path', folderPath);
    fd.append('password', document.getElementById('shareLinkPassword').value);
    fd.append('expires_days', document.getElementById('shareLinkExpires').value);
    const maxDlSelect = document.getElementById('shareLinkMaxDownloads');
    if (maxDlSelect) {
        fd.append('max_downloads', maxDlSelect.value);
    }
    const modeSelect = document.getElementById('shareLinkMode');
    if (modeSelect && folderPath) {
        fd.append('share_mode', modeSelect.value);
    }

    const res = await fetch('share_action.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        generatedLinkInput.value = data.share_url;
        generatedLinkBox.classList.remove('hidden');
        const qrContainer = document.getElementById('generatedLinkQrContainer');
        const qrSvgEl = document.getElementById('generatedLinkQrSvg');
        if (qrContainer && qrSvgEl && data.qr_svg) {
            qrSvgEl.innerHTML = data.qr_svg;
            qrContainer.classList.remove('hidden');
        }
        document.getElementById('shareLinkPassword').value = '';
        loadShareDetails(fileId, folderPath);
    } else {
        alert(data.message || 'Fehler beim Erstellen des Freigabelinks.');
    }
});

async function deleteShareLink(shareId) {
    const fd = new FormData();
    fd.append('action', 'delete_link');
    fd.append('csrf_token', csrfToken);
    fd.append('share_id', String(shareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadShareDetails(shareModalFileId.value, shareModalFolderPath.value);
}

const shareWithUserForm = document.getElementById('shareWithUserForm');
if (shareWithUserForm) {
    shareWithUserForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fileId = shareModalFileId.value;
        const folderPath = shareModalFolderPath.value;
        const targetUserId = document.getElementById('shareTargetUserId').value;
        const canWriteEl = document.getElementById('shareTargetCanWrite');
        const canWrite = canWriteEl ? canWriteEl.value : '0';
        if (!targetUserId) return;

        const fd = new FormData();
        fd.append('action', 'share_user');
        fd.append('csrf_token', csrfToken);
        fd.append('file_id', fileId);
        fd.append('folder_path', folderPath);
        fd.append('target_user_id', targetUserId);
        fd.append('can_write', canWrite);

        const res = await fetch('share_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('shareTargetUserId').value = '';
            loadShareDetails(fileId, folderPath);
        } else {
            alert(data.message || 'Fehler beim Teilen.');
        }
    });
}

async function revokeUserShare(userShareId) {
    const fd = new FormData();
    fd.append('action', 'unshare_user');
    fd.append('csrf_token', csrfToken);
    fd.append('user_share_id', String(userShareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadShareDetails(shareModalFileId.value, shareModalFolderPath.value);
}

// --- UPLOAD & DIREKTE ORDNER-DRAG-&-DROP LOGIK (Externer Auto-Upload + Internes Datei-Verschieben) ---
(() => {
    const mainFilesSection = document.getElementById('mainFilesSection');
    if (!mainFilesSection) return;

    const fileInput = document.getElementById('fileInput');
    const folderInput = document.getElementById('folderInput');
    const btnSelectFiles = document.getElementById('btnSelectFiles');
    const btnSelectFolder = document.getElementById('btnSelectFolder');
    const uploadForm = document.getElementById('uploadForm');
    const progressWrapper = document.getElementById('uploadProgressWrapper');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressPercent = document.getElementById('uploadProgressPercent');
    const progressText = document.getElementById('uploadProgressText');
    const globalDropHint = document.getElementById('globalDropHint');
    const globalDropHintText = document.getElementById('globalDropHintText');

    const remainingQuota = <?= (int)$storageStats['free'] ?>;
    const defaultTargetFolder = <?= json_encode($currentFolder) ?>;
    const defaultTargetLabel = defaultTargetFolder !== '' ? `„${defaultTargetFolder}“` : 'das aktuelle Verzeichnis';
    const activeGroupFolderId = <?= $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : 'null' ?>;
    let isUploading = false;
    let draggedInternalFileIds = [];
    let draggedInternalLabel = '';

    function formatSize(bytes) {
        if (bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 2).replace('.', ',') + ' ' + units[i];
    }

    function showDropHint(text) {
        if (!globalDropHint || !globalDropHintText) return;
        globalDropHintText.textContent = text;
        globalDropHint.classList.remove('hidden');
        globalDropHint.classList.add('flex');
    }

    function hideDropHint() {
        if (!globalDropHint) return;
        globalDropHint.classList.add('hidden');
        globalDropHint.classList.remove('flex');
    }

    function clearFolderDropHighlights() {
        document.querySelectorAll('[data-drop-folder]').forEach((el) => {
            el.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');
        });
        mainFilesSection.classList.remove('ring-2', 'ring-brand-500');
    }

    function startImmediateUpload(itemsToUpload, overrideTargetFolder = null, overrideLabel = null) {
        if (!uploadForm || isUploading || itemsToUpload.length === 0) return;

        const totalBytes = itemsToUpload.reduce((acc, item) => acc + (item.file.size || 0), 0);
        if (totalBytes > remainingQuota) {
            alert(`Fehler: ${itemsToUpload.length} Datei(en) (${formatSize(totalBytes)}) überschreiten Ihren freien Speicher (${formatSize(remainingQuota)})!`);
            return;
        }

        const effectiveFolder = overrideTargetFolder !== null
            ? overrideTargetFolder
            : (document.getElementById('target_folder')?.value ?? defaultTargetFolder);
        const targetDisplay = overrideLabel || (effectiveFolder !== '' ? `Ordner „${effectiveFolder}“` : 'Hauptverzeichnis');

        isUploading = true;
        hideDropHint();

        if (progressWrapper) {
            progressWrapper.classList.remove('hidden');
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = `Lade ${itemsToUpload.length} Datei(en) in ${targetDisplay} hoch...`;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('target_folder', effectiveFolder);
        const sfInput = uploadForm.querySelector('input[name="shared_folder_id"]');
        if (sfInput) {
            formData.append('shared_folder_id', sfInput.value);
        }
        const gfInput = uploadForm.querySelector('input[name="group_folder_id"]');
        if (gfInput) {
            formData.append('group_folder_id', gfInput.value);
        }

        for (const item of itemsToUpload) {
            formData.append('files[]', item.file, item.file.name);
            formData.append('relative_paths[]', item.relativePath);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', (evt) => {
            if (evt.lengthComputable && progressBar && progressPercent && progressText) {
                const pct = Math.round((evt.loaded / evt.total) * 100);
                progressBar.style.width = pct + '%';
                progressPercent.textContent = pct + '%';
                progressText.textContent = `Hochladen in ${targetDisplay} (${formatSize(evt.loaded)} von ${formatSize(evt.total)})...`;
            }
        });

        xhr.addEventListener('load', () => {
            try {
                const resp = JSON.parse(xhr.responseText);
                window.location.href = resp.redirect || 'index.php';
            } catch {
                window.location.href = 'index.php';
            }
        });

        xhr.addEventListener('error', () => {
            isUploading = false;
            alert('Netzwerkfehler beim Hochladen.');
            if (progressWrapper) progressWrapper.classList.add('hidden');
        });

        xhr.send(formData);
    }

    function handleFileListAndUpload(fileList, overrideTargetFolder = null, overrideLabel = null) {
        const items = [];
        for (const file of fileList) {
            const relPath = file.webkitRelativePath || file.name;
            items.push({ file, relativePath: relPath });
        }
        startImmediateUpload(items, overrideTargetFolder, overrideLabel);
    }

    if (btnSelectFiles && fileInput) {
        btnSelectFiles.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) handleFileListAndUpload(fileInput.files);
        });
    }

    if (btnSelectFolder && folderInput) {
        btnSelectFolder.addEventListener('click', () => folderInput.click());
        folderInput.addEventListener('change', () => {
            if (folderInput.files.length > 0) handleFileListAndUpload(folderInput.files);
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', (e) => e.preventDefault());
    }

    async function readEntryRecursively(entry, pathPrefix = '') {
        if (entry.isFile) {
            return new Promise((resolve) => {
                entry.file((file) => {
                    resolve([{ file, relativePath: pathPrefix + file.name }]);
                }, () => resolve([]));
            });
        }
        if (entry.isDirectory) {
            const dirReader = entry.createReader();
            const entries = [];
            const readBatch = () => new Promise((resolve) => {
                dirReader.readEntries((batch) => {
                    if (batch.length > 0) {
                        entries.push(...batch);
                        readBatch().then(resolve);
                    } else {
                        resolve();
                    }
                }, () => resolve());
            });
            await readBatch();
            const results = [];
            for (const childEntry of entries) {
                const subItems = await readEntryRecursively(childEntry, pathPrefix + entry.name + '/');
                results.push(...subItems);
            }
            return results;
        }
        return [];
    }

    async function extractDroppedItems(dataTransfer) {
        const items = dataTransfer?.items;
        if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
            const entries = [];
            for (let i = 0; i < items.length; i++) {
                const entry = items[i].webkitGetAsEntry();
                if (entry) entries.push(entry);
            }
            if (entries.length > 0) {
                const extractedItems = [];
                for (const entry of entries) {
                    const extracted = await readEntryRecursively(entry, '');
                    extractedItems.push(...extracted);
                }
                return extractedItems;
            }
        }
        if (dataTransfer?.files?.length > 0) {
            const fallback = [];
            for (const file of dataTransfer.files) {
                fallback.push({ file, relativePath: file.webkitRelativePath || file.name });
            }
            return fallback;
        }
        return [];
    }

    // 1. Internes Ziehen von Cloud-Dateien ([data-drag-file-id]) in Ordner
    document.querySelectorAll('[data-drag-file-id]').forEach((el) => {
        el.addEventListener('dragstart', (e) => {
            const fileId = el.getAttribute('data-drag-file-id');
            const fileName = el.getAttribute('data-drag-file-name') || 'Datei';
            if (!fileId) return;

            // Falls die gezogene Datei Teil einer Mehrfachauswahl ist, alle markierten Dateien gemeinsam verschieben
            const checkedIds = typeof getSelectedBatchCheckboxes === 'function'
                ? getSelectedBatchCheckboxes()
                    .filter(cb => cb.getAttribute('data-can-manage') === '1')
                    .map(cb => cb.value)
                : [];

            if (checkedIds.length > 1 && checkedIds.includes(fileId)) {
                draggedInternalFileIds = checkedIds;
                draggedInternalLabel = `${checkedIds.length} ausgewählte Dateien`;
            } else {
                draggedInternalFileIds = [fileId];
                draggedInternalLabel = `„${fileName}“`;
            }

            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/x-minicloud-files', draggedInternalFileIds.join(','));
            el.classList.add('opacity-50');
        });

        el.addEventListener('dragend', () => {
            el.classList.remove('opacity-50');
            draggedInternalFileIds = [];
            draggedInternalLabel = '';
            clearFolderDropHighlights();
            hideDropHint();
        });
    });

    function submitInternalMoveToFolder(fileIds, targetFolderPath) {
        if (!fileIds || fileIds.length === 0) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'folder_action.php';
        form.className = 'hidden';

        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        addField('csrf_token', csrfToken);
        addField('action', 'batch_transfer_files');
        addField('batch_file_ids', fileIds.join(','));
        addField('target_folder', 'move|' + (targetFolderPath !== '' ? targetFolderPath : '__root__'));
        if (defaultTargetFolder !== '') {
            addField('return_folder', defaultTargetFolder);
        }
        if (activeGroupFolderId !== null) {
            addField('group_folder_id', String(activeGroupFolderId));
        }

        document.body.appendChild(form);
        form.submit();
    }

    // 2. Direkte Drop-Zonen auf allen Ordner-Zeilen & Ordner-Kacheln ([data-drop-folder])
    document.querySelectorAll('[data-drop-folder]').forEach((folderEl) => {
        const folderPath = folderEl.getAttribute('data-drop-folder') ?? '';
        const folderLabel = folderEl.getAttribute('data-drop-folder-label') || (folderPath !== '' ? folderPath : 'Hauptverzeichnis');

        folderEl.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearFolderDropHighlights();
            folderEl.classList.add('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');

            if (draggedInternalFileIds.length > 0) {
                e.dataTransfer.dropEffect = 'move';
                showDropHint(`📂 Loslassen, um ${draggedInternalLabel} direkt in „${folderLabel}“ zu verschieben`);
            } else if (uploadForm) {
                e.dataTransfer.dropEffect = 'copy';
                showDropHint(`📥 Loslassen, um Dateien direkt in den Ordner „${folderLabel}“ hochzuladen`);
            }
        });

        folderEl.addEventListener('dragleave', (e) => {
            if (!folderEl.contains(e.relatedTarget)) {
                folderEl.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');
            }
        });

        folderEl.addEventListener('drop', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearFolderDropHighlights();
            hideDropHint();

            // Fall A: Internes Verschieben von Cloud-Dateien in diesen Ordner
            if (draggedInternalFileIds.length > 0) {
                const idsToMove = [...draggedInternalFileIds];
                draggedInternalFileIds = [];
                submitInternalMoveToFolder(idsToMove, folderPath);
                return;
            }

            // Fall B: Externer Datei-/Ordner-Upload direkt in diesen Ordner
            if (!uploadForm) return;
            const extractedItems = await extractDroppedItems(e.dataTransfer);
            if (extractedItems.length > 0) {
                startImmediateUpload(extractedItems, folderPath, `Ordner „${folderLabel}“`);
            }
        });
    });

    // 3. Fallback Drop-Zone auf dem gesamten Dateibereich (#mainFilesSection) für das aktuelle Verzeichnis
    mainFilesSection.addEventListener('dragover', (e) => {
        if (draggedInternalFileIds.length > 0 || !uploadForm) return;
        e.preventDefault();
        if (!e.target.closest('[data-drop-folder]')) {
            clearFolderDropHighlights();
            mainFilesSection.classList.add('ring-2', 'ring-brand-500');
            showDropHint(`📥 Dateien oder Ordner hier ablegen, um sie direkt in ${defaultTargetLabel} hochzuladen`);
        }
    });

    mainFilesSection.addEventListener('dragleave', (e) => {
        if (!mainFilesSection.contains(e.relatedTarget)) {
            clearFolderDropHighlights();
            hideDropHint();
        }
    });

    mainFilesSection.addEventListener('drop', async (e) => {
        if (draggedInternalFileIds.length > 0 || !uploadForm) return;
        if (e.target.closest('[data-drop-folder]')) return;
        e.preventDefault();
        clearFolderDropHighlights();
        hideDropHint();

        const extractedItems = await extractDroppedItems(e.dataTransfer);
        if (extractedItems.length > 0) {
            startImmediateUpload(extractedItems, null, null);
        }
    });

    // 4. Zwischenablage-Upload per Strg+V (Screenshots & kopierte Bilder/Dateien sofort hochladen)
    window.addEventListener('paste', (e) => {
        if (!uploadForm || isUploading) return;
        const activeTag = document.activeElement?.tagName?.toLowerCase() || '';
        const isTyping = activeTag === 'input' || activeTag === 'textarea' || document.activeElement?.isContentEditable;

        const clipboardItems = e.clipboardData?.items;
        if (!clipboardItems || clipboardItems.length === 0) return;

        const pastedUploadItems = [];
        const now = new Date();
        const pad2 = (n) => String(n).padStart(2, '0');
        const ts = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}_${pad2(now.getHours())}-${pad2(now.getMinutes())}-${pad2(now.getSeconds())}`;

        for (let i = 0; i < clipboardItems.length; i++) {
            const item = clipboardItems[i];
            if (item.kind === 'file') {
                const rawFile = item.getAsFile();
                if (!rawFile) continue;
                let finalFile = rawFile;
                // Wenn ein Screenshot aus der Zwischenablage eingefügt wird (Standardname "image.png"), Zeitstempel-Namen vergeben
                if (!rawFile.name || /^image\.(png|jpe?g|webp|gif)$/i.test(rawFile.name)) {
                    const ext = (rawFile.type.split('/')[1] || 'png').replace(/[^a-z0-9]/gi, '');
                    const customName = `Screenshot_${ts}.${ext}`;
                    try {
                        finalFile = new File([rawFile], customName, { type: rawFile.type });
                    } catch {
                        finalFile = rawFile;
                    }
                }
                pastedUploadItems.push({ file: finalFile, relativePath: finalFile.name });
            }
        }

        if (pastedUploadItems.length > 0 && (!isTyping || pastedUploadItems.some(it => it.file.type.startsWith('image/')))) {
            e.preventDefault();
            startImmediateUpload(pastedUploadItems, null, null);
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
