<?php
declare(strict_types=1);

/**
 * MiniCloud – App-Store & GitHub-Plugin-Manager (plugins.php)
 *
 * Ermöglicht Administratoren sowohl im Voll-Zweig (`main`) als auch im schlanken
 * Datei-Zweig (`files-only`), einzelne Cloud-Module bei Bedarf per 1-Klick direkt
 * von GitHub nachzuladen, zu aktualisieren, zu pausieren oder vom Server zu entfernen.
 *
 * Alle Aktionen (Nachladen, Aktualisieren, Aktivieren/Pausieren, Entfernen sowie
 * Kategorie-Wechsel) laufen asynchron im Hintergrund ohne Seiten-Reload.
 *
 * DATENSCHUTZ-GARANTIE: Sämtliche Datenbank-Tabellen und Nutzerdaten bleiben beim
 * Pausieren, Aktualisieren oder Entfernen einer Modul-Datei zu 100 % erhalten!
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins_catalog.php';

requireLogin();
$isAjaxRequest = (
    ($_POST['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
);

if (!isAdmin()) {
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Nur Administratoren können den Plugin-Manager & App-Store verwalten.']);
        exit;
    }
    setFlash('error', 'Nur Administratoren können den Plugin-Manager & App-Store verwalten.');
    header('Location: index.php');
    exit;
}

$pdo        = getDB();
$catalog    = getMiniCloudPluginCatalog();
$categories = getMiniCloudPluginCategories();
$rootDir    = __DIR__;

/**
 * Erstellt eine vollständige Momentaufnahme aller Plugin-Zustände und Zähler
 * für die Seite und für asynchrone Hintergrund-Updates (JSON).
 *
 * @param array<string, array<string, mixed>> $catalog
 * @param array<string, array<string, mixed>> $categories
 * @return array{
 *   stats: array<string, mixed>,
 *   plugins: array<string, array<string, mixed>>
 * }
 */
function buildPluginManagerSnapshot(PDO $pdo, array $catalog, array $categories, string $rootDir): array
{
    $appSettings    = getAppSettings($pdo);
    $totalCount     = count($catalog);
    $installedCount = 0;
    $activeCount    = 0;
    $missingCount   = 0;
    $pluginsState   = [];
    $categoryCounts = [];

    foreach ($categories as $catKey => $_catMeta) {
        $categoryCounts[$catKey] = ['installed' => 0, 'total' => 0];
    }

    foreach ($catalog as $pId => $pMeta) {
        $catKey      = (string)$pMeta['category'];
        $entryPath   = $rootDir . '/' . ltrim((string)$pMeta['entry_file'], '/');
        $isInstalled = is_file($entryPath);
        $isEnabled   = $isInstalled && (($appSettings[$pMeta['setting_key']] ?? '1') === '1');
        $fileSize    = $isInstalled ? (int)(@filesize($entryPath) ?: 0) : 0;
        $fileMtime   = $isInstalled ? (int)(@filemtime($entryPath) ?: 0) : 0;

        if (!isset($categoryCounts[$catKey])) {
            $categoryCounts[$catKey] = ['installed' => 0, 'total' => 0];
        }
        $categoryCounts[$catKey]['total']++;

        if ($isInstalled) {
            $installedCount++;
            $categoryCounts[$catKey]['installed']++;
            if ($isEnabled) {
                $activeCount++;
            }
        } else {
            $missingCount++;
        }

        $statusCode = !$isInstalled ? 'missing' : ($isEnabled ? 'installed' : 'paused');

        $pluginsState[$pId] = [
            'id'             => $pId,
            'name'           => (string)$pMeta['name'],
            'entry_file'     => (string)$pMeta['entry_file'],
            'category'       => $catKey,
            'installed'      => $isInstalled,
            'enabled'        => $isEnabled,
            'status'         => $statusCode,
            'size'           => $fileSize,
            'size_formatted' => $fileSize > 0 ? formatBytes($fileSize, 1) : '',
            'mtime'          => $fileMtime,
        ];
    }

    return [
        'stats' => [
            'total'      => $totalCount,
            'installed'  => $installedCount,
            'active'     => $activeCount,
            'paused'     => max(0, $installedCount - $activeCount),
            'missing'    => $missingCount,
            'categories' => $categoryCounts,
        ],
        'plugins' => $pluginsState,
    ];
}

// --- POST-Aktionen (Installieren, Aktualisieren, Aktivieren/Pausieren, Entfernen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Ungültiger Sicherheits-Token (CSRF). Bitte Seite neu laden.']);
            exit;
        }
        setFlash('error', 'Ungültiger Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: plugins.php');
        exit;
    }

    $action      = (string)($_POST['action'] ?? '');
    $pluginId    = trim((string)($_POST['plugin_id'] ?? ''));
    $filterCat   = trim((string)($_POST['return_cat'] ?? 'all'));
    $redirectUrl = 'plugins.php' . ($filterCat !== '' && $filterCat !== 'all' ? '?cat=' . rawurlencode($filterCat) : '');

    // Bei AJAX-Anfragen Session-Lock sofort freigeben, damit mehrere Module parallel
    // im Hintergrund geladen, aktiviert oder gelöscht werden können, ohne die Cloud zu blockieren.
    if ($isAjaxRequest && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }

    $resultOk  = false;
    $resultMsg = 'Unbekannte Aktion.';

    if ($action === 'install_plugin' || $action === 'update_plugin') {
        $res       = installMiniCloudPluginFromGitHub($pdo, $pluginId);
        $resultOk  = (bool)$res['ok'];
        $resultMsg = (string)$res['message'];
    } elseif ($action === 'toggle_plugin' && isset($catalog[$pluginId])) {
        $plugin = $catalog[$pluginId];
        if (!isMiniCloudPluginInstalled($pluginId)) {
            $resultOk  = false;
            $resultMsg = 'Dieses Modul ist noch nicht lokal installiert. Bitte zuerst von GitHub nachladen.';
        } else {
            $currentSettings = getAppSettings($pdo);
            $sKey            = (string)$plugin['setting_key'];
            $newVal          = (($currentSettings[$sKey] ?? '1') === '1') ? '0' : '1';
            saveAppSetting($pdo, $sKey, $newVal);
            $resultOk  = true;
            $resultMsg = sprintf(
                'Plugin „%s“ wurde %s.',
                $plugin['name'],
                $newVal === '1' ? 'im Menü aktiviert' : 'pausiert (Daten & Datei bleiben erhalten)'
            );
        }
    } elseif ($action === 'uninstall_plugin') {
        $res       = uninstallMiniCloudPluginFile($pdo, $pluginId);
        $resultOk  = (bool)$res['ok'];
        $resultMsg = (string)$res['message'];
    } elseif ($action === 'install_missing_category' || $action === 'install_all_missing') {
        $targetCat = $action === 'install_missing_category' ? trim((string)($_POST['target_cat'] ?? '')) : '';
        $okCount   = 0;
        $errCount  = 0;
        $lastErr   = '';
        foreach ($catalog as $pId => $pMeta) {
            if ($targetCat !== '' && $pMeta['category'] !== $targetCat) {
                continue;
            }
            if (!isMiniCloudPluginInstalled($pId)) {
                $res = installMiniCloudPluginFromGitHub($pdo, $pId);
                if ($res['ok']) {
                    $okCount++;
                } else {
                    $errCount++;
                    $lastErr = $res['message'];
                }
            }
        }
        $resultOk  = ($errCount === 0);
        $resultMsg = $errCount === 0
            ? sprintf('%d fehlende Module erfolgreich von GitHub nachgeladen und aktiviert!', $okCount)
            : sprintf('%d installiert, %d fehlgeschlagen (%s)', $okCount, $errCount, $lastErr);
    } elseif ($action === 'update_all_installed') {
        $okCount  = 0;
        $errCount = 0;
        $lastErr  = '';
        foreach ($catalog as $pId => $pMeta) {
            if (isMiniCloudPluginInstalled($pId)) {
                $res = installMiniCloudPluginFromGitHub($pdo, $pId);
                if ($res['ok']) {
                    $okCount++;
                } else {
                    $errCount++;
                    $lastErr = $res['message'];
                }
            }
        }
        $resultOk  = ($errCount === 0);
        $resultMsg = $errCount === 0
            ? sprintf('Alle %d installierten Plugins wurden erfolgreich von GitHub aktualisiert!', $okCount)
            : sprintf('%d aktualisiert, %d fehlgeschlagen (%s)', $okCount, $errCount, $lastErr);
    }

    if ($isAjaxRequest) {
        clearstatcache();
        $snapshot = buildPluginManagerSnapshot($pdo, $catalog, $categories, $rootDir);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => $resultOk,
            'message'   => $resultMsg,
            'action'    => $action,
            'plugin_id' => $pluginId,
            'plugin'    => $pluginId !== '' ? ($snapshot['plugins'][$pluginId] ?? null) : null,
            'plugins'   => $snapshot['plugins'],
            'stats'     => $snapshot['stats'],
        ]);
        exit;
    }

    setFlash($resultOk ? 'success' : 'error', $resultMsg);
    header('Location: ' . $redirectUrl);
    exit;
}

$appSettings = getAppSettings($pdo);
$githubRepo  = trim((string)($appSettings['github_repo'] ?? 'uWiiiDev/MiniCloud')) ?: 'uWiiiDev/MiniCloud';
$selectedCat = trim((string)($_GET['cat'] ?? 'all'));
if ($selectedCat !== 'all' && !isset($categories[$selectedCat])) {
    $selectedCat = 'all';
}

// --- Statistik & Laufzeitstatus berechnen ---
$snapshot            = buildPluginManagerSnapshot($pdo, $catalog, $categories, $rootDir);
$stats               = $snapshot['stats'];
$totalCatalogCount   = (int)$stats['total'];
$installedCount      = (int)$stats['installed'];
$activeCount         = (int)$stats['active'];
$pausedCount         = (int)$stats['paused'];
$missingCount        = (int)$stats['missing'];
$pluginRuntimeStates = $snapshot['plugins'];

$pageTitle = 'App-Store & Plugin-Manager';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="space-y-1.5">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-bold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800/60">
                    <span>🧩</span>
                    <span>Modulares GitHub-Plugin-System (main &amp; files-only kompatibel)</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight flex items-center gap-2.5">
                    <span>🛒 MiniCloud App-Store &amp; Plugin-Manager</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 max-w-3xl">
                    Lade benötigte Cloud-Module mit einem Klick live aus dem GitHub-Repository (<code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-xs"><?= e($githubRepo) ?></code>) nach, aktualisiere einzelne Module oder entferne nicht benötigte Programmdateien vom Server – alles direkt im Hintergrund ohne Seiten-Reload.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="modules.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors">
                    <span>⊞</span>
                    <span>Zur Modul-Übersicht</span>
                </a>
                <a href="admin.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors">
                    <span>🛡️</span>
                    <span>System-Administration</span>
                </a>
            </div>
        </div>

        <!-- Datenschutz-Garantie-Banner -->
        <div class="mt-4 p-3.5 rounded-xl bg-emerald-50/80 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-800/60 flex items-start gap-3">
            <span class="text-lg leading-none mt-0.5">🛡️</span>
            <div class="text-xs text-emerald-900 dark:text-emerald-200 space-y-0.5">
                <p class="font-bold">100 % Daten-Erhalt-Garantie für alle Nutzer:</p>
                <p class="text-emerald-800/90 dark:text-emerald-300/90">
                    Wenn du ein Modul pausierst, aktualisierst oder dessen <code class="font-mono">.php</code>-Datei vom Server entfernst, <strong>bleiben sämtliche Datenbank-Tabellen und Benutzereinträge (Termine, Notizen, Kontakte, Passwörter, Highscores, Finanzen usw.) vollständig unangetastet</strong>. Sobald du das Plugin wieder von GitHub nachlädst, sind alle Daten sofort wieder da.
                </p>
            </div>
        </div>
    </div>

    <!-- 4 KPI-Karten -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Katalog gesamt</span>
                <span class="text-lg">📦</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-slate-900 dark:text-white" id="kpiTotalCount"><?= $totalCatalogCount ?></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">+ Datei-Manager &amp; WebDAV-Kern</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Lokal installiert</span>
                <span class="text-lg">💾</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">
                <span id="kpiInstalledCount"><?= $installedCount ?></span>
                <span class="text-sm font-normal text-slate-400">/ <?= $totalCatalogCount ?></span>
            </p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Physisch auf diesem Server</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Im Menü aktiv</span>
                <span class="text-lg">🟢</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-brand-600 dark:text-brand-400" id="kpiActiveCount"><?= $activeCount ?></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><span id="kpiPausedCount"><?= $pausedCount ?></span> lokal pausiert</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Auf GitHub abrufbar</span>
                <span class="text-lg">☁️</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-indigo-600 dark:text-indigo-400" id="kpiMissingCount"><?= $missingCount ?></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Per 1-Klick nachladbar</p>
        </div>
    </div>

    <!-- Filter-, Such- & Sammelaktions-Leiste -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- Suchfeld & Status-Filter -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5 flex-1">
                <div class="relative flex-1 max-w-md">
                    <input
                        type="search"
                        id="pluginSearchInput"
                        oninput="filterPluginCards()"
                        placeholder="Plugin suchen (z.B. Wetter, Kalender, Flappy, PDF, Finanzen)…"
                        class="w-full pl-9 pr-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 focus:outline-none focus:border-brand-500"
                    >
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>

                <select
                    id="pluginStatusFilter"
                    onchange="filterPluginCards()"
                    class="px-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-medium"
                >
                    <option value="all" id="optStatusAll">Alle Status anzeigen (<?= $totalCatalogCount ?>)</option>
                    <option value="installed" id="optStatusInstalled">🟢 Lokal installiert (<?= $installedCount ?>)</option>
                    <option value="missing" id="optStatusMissing">☁️ Noch nicht installiert / Auf GitHub (<?= $missingCount ?>)</option>
                    <option value="paused" id="optStatusPaused">🟡 Installiert, aber pausiert (<?= $pausedCount ?>)</option>
                </select>
            </div>

            <!-- Sammelaktionen -->
            <div class="flex flex-wrap items-center gap-2">
                <form
                    method="post"
                    id="bulkInstallMissingForm"
                    data-confirm-msg="Möchtest du alle noch fehlenden Module direkt im Hintergrund von GitHub herunterladen und aktivieren?"
                    class="js-plugin-action-form m-0 <?= $missingCount > 0 ? '' : 'hidden' ?>"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="install_all_missing">
                    <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-2xs transition-colors cursor-pointer">
                        <span>📥</span>
                        <span>Alle fehlenden nachladen (<span id="bulkMissingCountBadge"><?= $missingCount ?></span>)</span>
                    </button>
                </form>

                <form
                    method="post"
                    id="bulkUpdateInstalledForm"
                    data-confirm-msg="Alle lokal installierten Module im Hintergrund mit dem aktuellen Stand auf GitHub (main) abgleichen und aktualisieren?"
                    class="js-plugin-action-form m-0 <?= $installedCount > 0 ? '' : 'hidden' ?>"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update_all_installed">
                    <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                        <span>🔄</span>
                        <span>Installierte von GitHub aktualisieren</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Kategorie-Reiter (Client-seitig ohne Seiten-Reload umschaltbar) -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 pt-1 border-t border-slate-100 dark:border-slate-800">
            <button
                type="button"
                data-cat-tab="all"
                onclick="selectPluginCategory('all')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap transition-colors cursor-pointer <?= $selectedCat === 'all' ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                <span>✨</span>
                <span>Alle Kategorien (<?= $totalCatalogCount ?>)</span>
            </button>
            <?php foreach ($categories as $catKey => $catMeta): ?>
                <?php
                $catTotal = (int)($stats['categories'][$catKey]['total'] ?? 0);
                $catInst  = (int)($stats['categories'][$catKey]['installed'] ?? 0);
                ?>
                <button
                    type="button"
                    data-cat-tab="<?= e($catKey) ?>"
                    onclick="selectPluginCategory('<?= e($catKey) ?>')"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap transition-colors cursor-pointer <?= $selectedCat === $catKey ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span><?= e($catMeta['icon']) ?></span>
                    <span><?= e($catMeta['label']) ?></span>
                    <span
                        data-cat-badge="<?= e($catKey) ?>"
                        class="px-1.5 py-0.2 rounded-full text-[10px] <?= $selectedCat === $catKey ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>"
                    >
                        <?= $catInst ?>/<?= $catTotal ?>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Plugin-Karten nach Kategorien (alle im DOM vorgerendert für sofortiges Filtern & Aktualisieren ohne Reload) -->
    <?php foreach ($categories as $catKey => $catMeta): ?>
        <?php
        $catPlugins = array_filter($catalog, static fn(array $p): bool => $p['category'] === $catKey);
        if (empty($catPlugins)) {
            continue;
        }
        ?>
        <section
            class="space-y-3 plugin-category-section"
            data-cat-section="<?= e($catKey) ?>"
            style="<?= ($selectedCat !== 'all' && $selectedCat !== $catKey) ? 'display: none;' : '' ?>"
        >
            <div class="flex items-center justify-between gap-2 px-1">
                <div>
                    <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span><?= e($catMeta['icon']) ?></span>
                        <span><?= e($catMeta['label']) ?></span>
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= e($catMeta['desc']) ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($catPlugins as $pId => $plugin): ?>
                    <?php
                    $st          = $pluginRuntimeStates[$pId];
                    $isInstalled = (bool)$st['installed'];
                    $isEnabled   = (bool)$st['enabled'];
                    $statusCode  = (string)$st['status'];
                    $searchHay   = mb_strtolower($plugin['name'] . ' ' . $plugin['description'] . ' ' . $plugin['entry_file'] . ' ' . $pId, 'UTF-8');
                    ?>
                    <div
                        id="pluginCard_<?= e($pId) ?>"
                        class="plugin-card bg-white dark:bg-slate-900 rounded-2xl border <?= $isInstalled ? ($isEnabled ? 'border-slate-200/90 dark:border-slate-800' : 'border-amber-300/70 dark:border-amber-800/60') : 'border-dashed border-slate-300 dark:border-slate-700' ?> p-4 sm:p-5 shadow-xs flex flex-col justify-between gap-4 transition-all hover:shadow-md"
                        data-plugin-id="<?= e($pId) ?>"
                        data-plugin-name="<?= e($plugin['name']) ?>"
                        data-plugin-entry="<?= e($plugin['entry_file']) ?>"
                        data-plugin-cat="<?= e($catKey) ?>"
                        data-plugin-status="<?= e($statusCode) ?>"
                        data-plugin-installed="<?= $isInstalled ? '1' : '0' ?>"
                        data-plugin-search="<?= e($searchHay) ?>"
                    >
                        <div class="space-y-3">
                            <!-- Kopf der Karte: Icon, Titel & Status-Badge -->
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-11 h-11 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-2xl shrink-0 border border-slate-200/70 dark:border-slate-700/70">
                                        <?= e($plugin['icon']) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-bold text-slate-900 dark:text-white truncate" title="<?= e($plugin['name']) ?>">
                                            <?= e($plugin['name']) ?>
                                        </h3>
                                        <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                                            <code class="text-[11px] font-mono px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                                <?= e($plugin['entry_file']) ?>
                                            </code>
                                            <span
                                                data-plugin-size-el
                                                class="text-[10px] text-slate-400 dark:text-slate-500 <?= ($isInstalled && $st['size'] > 0) ? '' : 'hidden' ?>"
                                            >
                                                • <?= ($isInstalled && $st['size'] > 0) ? e((string)$st['size_formatted']) : '' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div data-plugin-badge-wrap class="shrink-0">
                                    <?php if ($isInstalled && $isEnabled): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            <span>Aktiv</span>
                                        </span>
                                    <?php elseif ($isInstalled && !$isEnabled): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-300/60 dark:border-amber-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                            <span>Pausiert</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                            <span>☁️ GitHub</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Beschreibung -->
                            <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                                <?= e($plugin['description']) ?>
                            </p>
                        </div>

                        <!-- Fußbereich mit Aktions-Buttons (wird nach Hintergrund-Aktionen dynamisch aktualisiert) -->
                        <div data-plugin-footer class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-2">
                            <?php if ($isInstalled): ?>
                                <div class="flex items-center gap-1.5">
                                    <?php if ($isEnabled): ?>
                                        <a
                                            href="<?= e($plugin['entry_file']) ?>"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white transition-colors"
                                            title="Modul öffnen"
                                        >
                                            <span>🚀</span>
                                            <span>Öffnen</span>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Aktivieren / Pausieren -->
                                    <form method="post" class="js-plugin-action-form m-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_plugin">
                                        <input type="hidden" name="plugin_id" value="<?= e($pId) ?>">
                                        <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold <?= $isEnabled ? 'bg-slate-100 dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-amber-950/50 text-slate-700 dark:text-slate-300' : 'bg-emerald-600 hover:bg-emerald-700 text-white' ?> transition-colors cursor-pointer"
                                            title="<?= $isEnabled ? 'Modul im Menü ausblenden (Datei & Daten bleiben erhalten)' : 'Modul im Menü wieder einblenden' ?>"
                                        >
                                            <span><?= $isEnabled ? '⏸️' : '▶️' ?></span>
                                            <span><?= $isEnabled ? 'Pausieren' : 'Aktivieren' ?></span>
                                        </button>
                                    </form>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <!-- Update von GitHub -->
                                    <form method="post" class="js-plugin-action-form m-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_plugin">
                                        <input type="hidden" name="plugin_id" value="<?= e($pId) ?>">
                                        <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                                            title="Neueste Version dieser Modul-Datei von GitHub (main) herunterladen"
                                        >
                                            <span>🔄</span>
                                            <span>Update</span>
                                        </button>
                                    </form>

                                    <!-- Datei vom Server entfernen (DB-Daten bleiben erhalten) -->
                                    <form
                                        method="post"
                                        data-confirm-msg="Programmdatei „<?= e($plugin['entry_file']) ?>“ vom Server entfernen?&#10;&#10;Hinweis: Sämtliche gespeicherten Nutzerdaten in der Datenbank bleiben zu 100 % erhalten und stehen sofort wieder bereit, falls du das Plugin später erneut installierst."
                                        class="js-plugin-action-form m-0"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="uninstall_plugin">
                                        <input type="hidden" name="plugin_id" value="<?= e($pId) ?>">
                                        <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center justify-center p-1.5 rounded-xl text-xs font-semibold text-red-600 dark:text-red-400 bg-red-50/70 dark:bg-red-950/30 hover:bg-red-100 dark:hover:bg-red-950/60 border border-red-200/60 dark:border-red-900/50 transition-colors cursor-pointer"
                                            title="Modul-Datei vom Server löschen (Nutzerdaten in der Datenbank bleiben erhalten!)"
                                        >
                                            <span>🗑️</span>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <!-- Noch nicht installiert: 1-Klick-Download von GitHub -->
                                <form method="post" class="js-plugin-action-form w-full m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="install_plugin">
                                    <input type="hidden" name="plugin_id" value="<?= e($pId) ?>">
                                    <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                                    <button
                                        type="submit"
                                        class="w-full inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-2xs transition-colors cursor-pointer"
                                    >
                                        <span>📥</span>
                                        <span>Von GitHub nachladen &amp; aktivieren</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<!-- Schwebender Toast-Container für Hintergrund-Aktionen -->
<div id="pluginToastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none px-3 sm:px-0"></div>

<script>
(function() {
    const csrfToken = <?= json_encode(getCsrfToken()) ?>;
    let activeCategory = <?= json_encode($selectedCat) ?>;

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showPluginToast(message, isSuccess = true) {
        const container = document.getElementById('pluginToastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'pointer-events-auto flex items-start gap-2.5 p-3.5 rounded-2xl shadow-lg border text-xs font-semibold transition-all duration-200 ' + (
            isSuccess
                ? 'bg-emerald-950/95 text-emerald-100 border-emerald-700/80'
                : 'bg-red-950/95 text-red-100 border-red-700/80'
        );
        toast.innerHTML = `
            <span class="text-base leading-none mt-0.5">${isSuccess ? '✅' : '⚠️'}</span>
            <div class="flex-1 leading-relaxed">${escapeHtml(message)}</div>
            <button type="button" class="text-slate-400 hover:text-white ml-1 cursor-pointer">✕</button>
        `;
        toast.querySelector('button')?.addEventListener('click', () => toast.remove());
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(6px)';
            setTimeout(() => toast.remove(), 220);
        }, 4200);
    }

    window.selectPluginCategory = function(catKey) {
        activeCategory = catKey || 'all';

        // Reiter-Buttons optisch aktualisieren
        document.querySelectorAll('[data-cat-tab]').forEach(btn => {
            const btnCat = btn.getAttribute('data-cat-tab');
            const isSelected = (btnCat === activeCategory);
            btn.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap transition-colors cursor-pointer ' + (
                isSelected
                    ? 'bg-brand-600 text-white shadow-2xs'
                    : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
            );
            const badge = btn.querySelector('[data-cat-badge]');
            if (badge) {
                badge.className = 'px-1.5 py-0.2 rounded-full text-[10px] ' + (
                    isSelected
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300'
                );
            }
        });

        // URL-Parameter ohne Seiten-Reload aktualisieren
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            if (activeCategory === 'all') {
                url.searchParams.delete('cat');
            } else {
                url.searchParams.set('cat', activeCategory);
            }
            window.history.replaceState({}, '', url.toString());
        }

        filterPluginCards();
    };

    window.filterPluginCards = function() {
        const query = (document.getElementById('pluginSearchInput')?.value || '').trim().toLowerCase();
        const statusFilter = document.getElementById('pluginStatusFilter')?.value || 'all';

        document.querySelectorAll('.plugin-card').forEach(card => {
            const hay    = card.getAttribute('data-plugin-search') || '';
            const st     = card.getAttribute('data-plugin-status') || '';
            const isInst = card.getAttribute('data-plugin-installed') === '1';

            const matchQuery = !query || hay.includes(query);
            let matchStatus = true;
            if (statusFilter === 'installed') {
                matchStatus = isInst;
            } else if (statusFilter === 'missing') {
                matchStatus = (st === 'missing');
            } else if (statusFilter === 'paused') {
                matchStatus = (st === 'paused');
            }

            card.style.display = (matchQuery && matchStatus) ? '' : 'none';
        });

        document.querySelectorAll('.plugin-category-section').forEach(sec => {
            const secCat = sec.getAttribute('data-cat-section') || '';
            if (activeCategory !== 'all' && secCat !== activeCategory) {
                sec.style.display = 'none';
                return;
            }
            const visibleCards = sec.querySelectorAll('.plugin-card:not([style*="display: none"])');
            sec.style.display = visibleCards.length > 0 ? '' : 'none';
        });
    };

    /**
     * Aktualisiert die 4 oberen KPI-Karten, die Dropdown-Zähler, Sammelbuttons und Kategorie-Zähler.
     */
    function updatePluginStatsUI(stats) {
        if (!stats) return;

        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };

        setTxt('kpiTotalCount', stats.total);
        setTxt('kpiInstalledCount', stats.installed);
        setTxt('kpiActiveCount', stats.active);
        setTxt('kpiPausedCount', stats.paused);
        setTxt('kpiMissingCount', stats.missing);
        setTxt('bulkMissingCountBadge', stats.missing);

        const optAll = document.getElementById('optStatusAll');
        if (optAll) optAll.textContent = `Alle Status anzeigen (${stats.total})`;
        const optInst = document.getElementById('optStatusInstalled');
        if (optInst) optInst.textContent = `🟢 Lokal installiert (${stats.installed})`;
        const optMiss = document.getElementById('optStatusMissing');
        if (optMiss) optMiss.textContent = `☁️ Noch nicht installiert / Auf GitHub (${stats.missing})`;
        const optPaused = document.getElementById('optStatusPaused');
        if (optPaused) optPaused.textContent = `🟡 Installiert, aber pausiert (${stats.paused})`;

        const bulkInstallForm = document.getElementById('bulkInstallMissingForm');
        if (bulkInstallForm) {
            bulkInstallForm.classList.toggle('hidden', !(stats.missing > 0));
        }
        const bulkUpdateForm = document.getElementById('bulkUpdateInstalledForm');
        if (bulkUpdateForm) {
            bulkUpdateForm.classList.toggle('hidden', !(stats.installed > 0));
        }

        if (stats.categories) {
            Object.entries(stats.categories).forEach(([catKey, cInfo]) => {
                const badge = document.querySelector(`[data-cat-badge="${catKey}"]`);
                if (badge && cInfo) {
                    badge.textContent = `${cInfo.installed}/${cInfo.total}`;
                }
            });
        }
    }

    /**
     * Aktualisiert eine einzelne Plugin-Karte im DOM nach Installieren, Update, Aktivieren/Pausieren oder Löschen.
     */
    function updateSinglePluginCardUI(pluginState) {
        if (!pluginState || !pluginState.id) return;
        const card = document.getElementById('pluginCard_' + pluginState.id);
        if (!card) return;

        const pId         = pluginState.id;
        const entryFile   = pluginState.entry_file || card.getAttribute('data-plugin-entry') || '';
        const isInstalled = Boolean(pluginState.installed);
        const isEnabled   = Boolean(pluginState.enabled);
        const statusCode  = pluginState.status || (!isInstalled ? 'missing' : (isEnabled ? 'installed' : 'paused'));

        card.setAttribute('data-plugin-status', statusCode);
        card.setAttribute('data-plugin-installed', isInstalled ? '1' : '0');

        // Rahmenfarbe entsprechend Status anpassen
        card.className = 'plugin-card bg-white dark:bg-slate-900 rounded-2xl border ' + (
            isInstalled
                ? (isEnabled ? 'border-slate-200/90 dark:border-slate-800' : 'border-amber-300/70 dark:border-amber-800/60')
                : 'border-dashed border-slate-300 dark:border-slate-700'
        ) + ' p-4 sm:p-5 shadow-xs flex flex-col justify-between gap-4 transition-all hover:shadow-md';

        // Dateigröße aktualisieren
        const sizeEl = card.querySelector('[data-plugin-size-el]');
        if (sizeEl) {
            if (isInstalled && pluginState.size > 0 && pluginState.size_formatted) {
                sizeEl.textContent = '• ' + pluginState.size_formatted;
                sizeEl.classList.remove('hidden');
            } else {
                sizeEl.textContent = '';
                sizeEl.classList.add('hidden');
            }
        }

        // Status-Badge oben rechts aktualisieren
        const badgeWrap = card.querySelector('[data-plugin-badge-wrap]');
        if (badgeWrap) {
            if (isInstalled && isEnabled) {
                badgeWrap.innerHTML = `
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span>Aktiv</span>
                    </span>
                `;
            } else if (isInstalled && !isEnabled) {
                badgeWrap.innerHTML = `
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-300/60 dark:border-amber-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        <span>Pausiert</span>
                    </span>
                `;
            } else {
                badgeWrap.innerHTML = `
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                        <span>☁️ GitHub</span>
                    </span>
                `;
            }
        }

        // Fußbereich mit Aktions-Buttons neu rendern
        const footer = card.querySelector('[data-plugin-footer]');
        if (footer) {
            const safeId    = escapeHtml(pId);
            const safeEntry = escapeHtml(entryFile);
            const safeCsrf  = escapeHtml(csrfToken);
            const safeCat   = escapeHtml(activeCategory);

            if (isInstalled) {
                const openBtnHtml = isEnabled ? `
                    <a
                        href="${safeEntry}"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white transition-colors"
                        title="Modul öffnen"
                    >
                        <span>🚀</span>
                        <span>Öffnen</span>
                    </a>
                ` : '';

                const toggleBtnClass = isEnabled
                    ? 'bg-slate-100 dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-amber-950/50 text-slate-700 dark:text-slate-300'
                    : 'bg-emerald-600 hover:bg-emerald-700 text-white';
                const toggleTitle = isEnabled
                    ? 'Modul im Menü ausblenden (Datei & Daten bleiben erhalten)'
                    : 'Modul im Menü wieder einblenden';
                const toggleIcon  = isEnabled ? '⏸️' : '▶️';
                const toggleLabel = isEnabled ? 'Pausieren' : 'Aktivieren';

                const confirmDeleteMsg = `Programmdatei „${safeEntry}“ vom Server entfernen?\n\nHinweis: Sämtliche gespeicherten Nutzerdaten in der Datenbank bleiben zu 100 % erhalten und stehen sofort wieder bereit, falls du das Plugin später erneut installierst.`;

                footer.innerHTML = `
                    <div class="flex items-center gap-1.5">
                        ${openBtnHtml}
                        <form method="post" class="js-plugin-action-form m-0">
                            <input type="hidden" name="csrf_token" value="${safeCsrf}">
                            <input type="hidden" name="action" value="toggle_plugin">
                            <input type="hidden" name="plugin_id" value="${safeId}">
                            <input type="hidden" name="return_cat" value="${safeCat}">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold ${toggleBtnClass} transition-colors cursor-pointer"
                                title="${toggleTitle}"
                            >
                                <span>${toggleIcon}</span>
                                <span>${toggleLabel}</span>
                            </button>
                        </form>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <form method="post" class="js-plugin-action-form m-0">
                            <input type="hidden" name="csrf_token" value="${safeCsrf}">
                            <input type="hidden" name="action" value="update_plugin">
                            <input type="hidden" name="plugin_id" value="${safeId}">
                            <input type="hidden" name="return_cat" value="${safeCat}">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                                title="Neueste Version dieser Modul-Datei von GitHub (main) herunterladen"
                            >
                                <span>🔄</span>
                                <span>Update</span>
                            </button>
                        </form>
                        <form
                            method="post"
                            data-confirm-msg="${escapeHtml(confirmDeleteMsg)}"
                            class="js-plugin-action-form m-0"
                        >
                            <input type="hidden" name="csrf_token" value="${safeCsrf}">
                            <input type="hidden" name="action" value="uninstall_plugin">
                            <input type="hidden" name="plugin_id" value="${safeId}">
                            <input type="hidden" name="return_cat" value="${safeCat}">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center p-1.5 rounded-xl text-xs font-semibold text-red-600 dark:text-red-400 bg-red-50/70 dark:bg-red-950/30 hover:bg-red-100 dark:hover:bg-red-950/60 border border-red-200/60 dark:border-red-900/50 transition-colors cursor-pointer"
                                title="Modul-Datei vom Server löschen (Nutzerdaten in der Datenbank bleiben erhalten!)"
                            >
                                <span>🗑️</span>
                            </button>
                        </form>
                    </div>
                `;
            } else {
                footer.innerHTML = `
                    <form method="post" class="js-plugin-action-form w-full m-0">
                        <input type="hidden" name="csrf_token" value="${safeCsrf}">
                        <input type="hidden" name="action" value="install_plugin">
                        <input type="hidden" name="plugin_id" value="${safeId}">
                        <input type="hidden" name="return_cat" value="${safeCat}">
                        <button
                            type="submit"
                            class="w-full inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-2xs transition-colors cursor-pointer"
                        >
                            <span>📥</span>
                            <span>Von GitHub nachladen &amp; aktivieren</span>
                        </button>
                    </form>
                `;
            }
        }
    }

    // Event-Delegation für alle Plugin-Formulare (einzeln & Sammelaktionen)
    document.addEventListener('submit', async function(ev) {
        const form = ev.target.closest('.js-plugin-action-form');
        if (!form) return;

        ev.preventDefault();

        const confirmMsg = form.getAttribute('data-confirm-msg');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            return;
        }

        const formData = new FormData(form);
        formData.set('ajax', '1');
        formData.set('return_cat', activeCategory);

        const action   = String(formData.get('action') || '');
        const pluginId = String(formData.get('plugin_id') || '');
        const card     = pluginId ? document.getElementById('pluginCard_' + pluginId) : null;
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';

        // Nur die betroffene Karte bzw. den geklickten Button in den Ladezustand versetzen,
        // damit man sofort weiterscrollen und parallel weitere Module bearbeiten kann!
        const buttonsToDisable = card
            ? Array.from(card.querySelectorAll('button'))
            : (submitBtn ? [submitBtn] : []);

        buttonsToDisable.forEach(b => {
            b.disabled = true;
            b.classList.add('opacity-60', 'cursor-wait');
        });

        if (submitBtn) {
            if (action === 'install_plugin') {
                submitBtn.innerHTML = '<span>⏳</span><span>Lädt im Hintergrund…</span>';
            } else if (action === 'update_plugin' || action === 'update_all_installed') {
                submitBtn.innerHTML = '<span>⏳</span><span>Aktualisiere…</span>';
            } else if (action === 'toggle_plugin') {
                submitBtn.innerHTML = '<span>⏳</span><span>Schalte um…</span>';
            } else if (action === 'uninstall_plugin') {
                submitBtn.innerHTML = '<span>⏳</span>';
            } else if (action === 'install_all_missing') {
                submitBtn.innerHTML = '<span>⏳</span><span>Lade alle fehlenden Module…</span>';
            }
        }

        try {
            const response = await fetch('plugins.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const data = await response.json();

            if (!response.ok || !data || !data.ok) {
                showPluginToast(data?.message || 'Aktion konnte nicht ausgeführt werden.', false);
                if (submitBtn) submitBtn.innerHTML = originalBtnHtml;
                buttonsToDisable.forEach(b => {
                    b.disabled = false;
                    b.classList.remove('opacity-60', 'cursor-wait');
                });
                return;
            }

            // Bei Sammelaktionen alle Karten aktualisieren, sonst gezielt die bearbeitete Karte
            if (action === 'install_all_missing' || action === 'update_all_installed' || action === 'install_missing_category') {
                if (data.plugins) {
                    Object.values(data.plugins).forEach(pState => updateSinglePluginCardUI(pState));
                }
                if (submitBtn) submitBtn.innerHTML = originalBtnHtml;
                buttonsToDisable.forEach(b => {
                    b.disabled = false;
                    b.classList.remove('opacity-60', 'cursor-wait');
                });
            } else if (data.plugin) {
                updateSinglePluginCardUI(data.plugin);
            }

            if (data.stats) {
                updatePluginStatsUI(data.stats);
            }

            filterPluginCards();
            showPluginToast(data.message || 'Änderung erfolgreich gespeichert.', true);
        } catch (err) {
            console.error('Plugin-Manager Hintergrund-Fehler:', err);
            showPluginToast('Netzwerkfehler bei der Hintergrund-Aktion.', false);
            if (submitBtn) submitBtn.innerHTML = originalBtnHtml;
            buttonsToDisable.forEach(b => {
                b.disabled = false;
                b.classList.remove('opacity-60', 'cursor-wait');
            });
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
