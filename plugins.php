<?php
declare(strict_types=1);

/**
 * MiniCloud – App-Store & GitHub-Plugin-Manager (plugins.php)
 *
 * Ermöglicht Administratoren sowohl im Voll-Zweig (`main`) als auch im schlanken
 * Datei-Zweig (`files-only`), einzelne Cloud-Module bei Bedarf per 1-Klick direkt
 * von GitHub nachzuladen, zu aktualisieren, zu pausieren oder vom Server zu entfernen.
 *
 * DATENSCHUTZ-GARANTIE: Sämtliche Datenbank-Tabellen und Nutzerdaten bleiben beim
 * Pausieren, Aktualisieren oder Entfernen einer Modul-Datei zu 100 % erhalten!
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/plugins_catalog.php';

requireLogin();
if (!isAdmin()) {
    setFlash('error', 'Nur Administratoren können den Plugin-Manager & App-Store verwalten.');
    header('Location: index.php');
    exit;
}

$pdo         = getDB();
$catalog     = getMiniCloudPluginCatalog();
$categories  = getMiniCloudPluginCategories();
$rootDir     = __DIR__;

// --- POST-Aktionen (Installieren, Aktualisieren, Aktivieren/Pausieren, Entfernen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiger Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: plugins.php');
        exit;
    }

    $action   = (string)($_POST['action'] ?? '');
    $pluginId = trim((string)($_POST['plugin_id'] ?? ''));
    $filterCat = trim((string)($_POST['return_cat'] ?? 'all'));
    $redirectUrl = 'plugins.php' . ($filterCat !== '' && $filterCat !== 'all' ? '?cat=' . rawurlencode($filterCat) : '');

    if ($action === 'install_plugin' || $action === 'update_plugin') {
        $res = installMiniCloudPluginFromGitHub($pdo, $pluginId);
        setFlash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'toggle_plugin' && isset($catalog[$pluginId])) {
        $plugin = $catalog[$pluginId];
        if (!isMiniCloudPluginInstalled($pluginId)) {
            setFlash('error', 'Dieses Modul ist noch nicht lokal installiert. Bitte zuerst von GitHub nachladen.');
            header('Location: ' . $redirectUrl);
            exit;
        }
        $currentSettings = getAppSettings($pdo);
        $sKey = $plugin['setting_key'];
        $newVal = (($currentSettings[$sKey] ?? '1') === '1') ? '0' : '1';
        saveAppSetting($pdo, $sKey, $newVal);
        setFlash(
            'success',
            sprintf('Plugin „%s“ wurde %s.', $plugin['name'], $newVal === '1' ? 'im Menü aktiviert' : 'pausiert (Daten & Datei bleiben erhalten)')
        );
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'uninstall_plugin') {
        $res = uninstallMiniCloudPluginFile($pdo, $pluginId);
        setFlash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'install_missing_category' || $action === 'install_all_missing') {
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
        if ($errCount === 0) {
            setFlash('success', sprintf('%d fehlende Module erfolgreich von GitHub nachgeladen und aktiviert!', $okCount));
        } else {
            setFlash('error', sprintf('%d installiert, %d fehlgeschlagen (%s)', $okCount, $errCount, $lastErr));
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'update_all_installed') {
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
        if ($errCount === 0) {
            setFlash('success', sprintf('Alle %d installierten Plugins wurden erfolgreich von GitHub aktualisiert!', $okCount));
        } else {
            setFlash('error', sprintf('%d aktualisiert, %d fehlgeschlagen (%s)', $okCount, $errCount, $lastErr));
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

$appSettings = getAppSettings($pdo);
$githubRepo  = trim((string)($appSettings['github_repo'] ?? 'uWiiiDev/MiniCloud')) ?: 'uWiiiDev/MiniCloud';
$selectedCat = trim((string)($_GET['cat'] ?? 'all'));
if ($selectedCat !== 'all' && !isset($categories[$selectedCat])) {
    $selectedCat = 'all';
}

// --- Statistik berechnen ---
$totalCatalogCount   = count($catalog);
$installedCount      = 0;
$activeCount         = 0;
$missingCount        = 0;
$pluginRuntimeStates = [];

foreach ($catalog as $pId => $pMeta) {
    $entryPath   = $rootDir . '/' . ltrim($pMeta['entry_file'], '/');
    $isInstalled = is_file($entryPath);
    $isEnabled   = $isInstalled && (($appSettings[$pMeta['setting_key']] ?? '1') === '1');
    $fileSize    = $isInstalled ? (int)(@filesize($entryPath) ?: 0) : 0;
    $fileMtime   = $isInstalled ? (int)(@filemtime($entryPath) ?: 0) : 0;

    if ($isInstalled) {
        $installedCount++;
        if ($isEnabled) {
            $activeCount++;
        }
    } else {
        $missingCount++;
    }

    $pluginRuntimeStates[$pId] = [
        'installed' => $isInstalled,
        'enabled'   => $isEnabled,
        'size'      => $fileSize,
        'mtime'     => $fileMtime,
    ];
}

$pageTitle = 'App-Store & Plugin-Manager';
require_once __DIR__ . '/includes/header.php';
?>

<main class="flex-1 max-w-7xl w-full mx-auto px-3 sm:px-6 lg:px-8 py-6 space-y-6">
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
                    Lade benötigte Cloud-Module mit einem Klick live aus dem GitHub-Repository (<code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-xs"><?= e($githubRepo) ?></code>) nach, aktualisiere einzelne Module oder entferne nicht benötigte Programmdateien vom Server.
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
            <p class="mt-1.5 text-2xl font-extrabold text-slate-900 dark:text-white"><?= $totalCatalogCount ?></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">+ Datei-Manager &amp; WebDAV-Kern</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Lokal installiert</span>
                <span class="text-lg">💾</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400"><?= $installedCount ?> <span class="text-sm font-normal text-slate-400">/ <?= $totalCatalogCount ?></span></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Physisch auf diesem Server</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Im Menü aktiv</span>
                <span class="text-lg">🟢</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-brand-600 dark:text-brand-400"><?= $activeCount ?></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><?= max(0, $installedCount - $activeCount) ?> lokal pausiert</p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Auf GitHub abrufbar</span>
                <span class="text-lg">☁️</span>
            </div>
            <p class="mt-1.5 text-2xl font-extrabold text-indigo-600 dark:text-indigo-400"><?= $missingCount ?></p>
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
                    <option value="all">Alle Status anzeigen (<?= $totalCatalogCount ?>)</option>
                    <option value="installed">🟢 Lokal installiert (<?= $installedCount ?>)</option>
                    <option value="missing">☁️ Noch nicht installiert / Auf GitHub (<?= $missingCount ?>)</option>
                    <option value="paused">🟡 Installiert, aber pausiert (<?= max(0, $installedCount - $activeCount) ?>)</option>
                </select>
            </div>

            <!-- Sammelaktionen -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($missingCount > 0): ?>
                    <form method="post" onsubmit="return confirm('Möchtest du alle <?= $missingCount ?> noch fehlenden Module direkt von GitHub herunterladen und aktivieren?');" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="install_all_missing">
                        <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-2xs transition-colors cursor-pointer">
                            <span>📥</span>
                            <span>Alle fehlenden nachladen (<?= $missingCount ?>)</span>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($installedCount > 0): ?>
                    <form method="post" onsubmit="return confirm('Alle <?= $installedCount ?> lokal installierten Module mit dem aktuellen Stand auf GitHub (main) abgleichen und aktualisieren?');" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="update_all_installed">
                        <input type="hidden" name="return_cat" value="<?= e($selectedCat) ?>">
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer">
                            <span>🔄</span>
                            <span>Installierte von GitHub aktualisieren</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Kategorie-Reiter -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 pt-1 border-t border-slate-100 dark:border-slate-800">
            <a
                href="plugins.php?cat=all"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap transition-colors <?= $selectedCat === 'all' ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                <span>✨</span>
                <span>Alle Kategorien (<?= $totalCatalogCount ?>)</span>
            </a>
            <?php foreach ($categories as $catKey => $catMeta): ?>
                <?php
                $catTotal = 0;
                $catInst  = 0;
                foreach ($catalog as $pId => $pMeta) {
                    if ($pMeta['category'] === $catKey) {
                        $catTotal++;
                        if (!empty($pluginRuntimeStates[$pId]['installed'])) {
                            $catInst++;
                        }
                    }
                }
                ?>
                <a
                    href="plugins.php?cat=<?= e($catKey) ?>"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap transition-colors <?= $selectedCat === $catKey ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span><?= e($catMeta['icon']) ?></span>
                    <span><?= e($catMeta['label']) ?></span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $selectedCat === $catKey ? 'bg-white/20 text-white' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                        <?= $catInst ?>/<?= $catTotal ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Plugin-Karten nach Kategorien -->
    <?php foreach ($categories as $catKey => $catMeta): ?>
        <?php
        if ($selectedCat !== 'all' && $selectedCat !== $catKey) {
            continue;
        }
        $catPlugins = array_filter($catalog, static fn(array $p): bool => $p['category'] === $catKey);
        if (empty($catPlugins)) {
            continue;
        }
        ?>
        <section class="space-y-3 plugin-category-section" data-cat-section="<?= e($catKey) ?>">
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
                    $isInstalled = $st['installed'];
                    $isEnabled   = $st['enabled'];
                    $statusCode  = !$isInstalled ? 'missing' : ($isEnabled ? 'installed' : 'paused');
                    $searchHay   = mb_strtolower($plugin['name'] . ' ' . $plugin['description'] . ' ' . $plugin['entry_file'] . ' ' . $pId, 'UTF-8');
                    ?>
                    <div
                        class="plugin-card bg-white dark:bg-slate-900 rounded-2xl border <?= $isInstalled ? ($isEnabled ? 'border-slate-200/90 dark:border-slate-800' : 'border-amber-300/70 dark:border-amber-800/60') : 'border-dashed border-slate-300 dark:border-slate-700' ?> p-4 sm:p-5 shadow-xs flex flex-col justify-between gap-4 transition-all hover:shadow-md"
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
                                            <?php if ($isInstalled && $st['size'] > 0): ?>
                                                <span class="text-[10px] text-slate-400 dark:text-slate-500">
                                                    • <?= e(formatBytes($st['size'], 1)) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($isInstalled && $isEnabled): ?>
                                    <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        <span>Aktiv</span>
                                    </span>
                                <?php elseif ($isInstalled && !$isEnabled): ?>
                                    <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-300/60 dark:border-amber-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        <span>Pausiert</span>
                                    </span>
                                <?php else: ?>
                                    <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                        <span>☁️ GitHub</span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Beschreibung -->
                            <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                                <?= e($plugin['description']) ?>
                            </p>
                        </div>

                        <!-- Fußbereich mit Aktions-Buttons -->
                        <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-2">
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
                                    <form method="post" class="m-0">
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
                                    <form method="post" class="m-0">
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
                                        onsubmit="return confirm('Programmdatei „<?= e($plugin['entry_file']) ?>“ vom Server entfernen?\n\nHinweis: Sämtliche gespeicherten Nutzerdaten in der Datenbank bleiben zu 100 % erhalten und stehen sofort wieder bereit, falls du das Plugin später erneut installierst.');"
                                        class="m-0"
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
                                <form method="post" class="w-full m-0">
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
</main>

<script>
function filterPluginCards() {
    const query = (document.getElementById('pluginSearchInput')?.value || '').trim().toLowerCase();
    const statusFilter = document.getElementById('pluginStatusFilter')?.value || 'all';

    document.querySelectorAll('.plugin-card').forEach(card => {
        const hay = card.getAttribute('data-plugin-search') || '';
        const st  = card.getAttribute('data-plugin-status') || '';
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
        const visibleCards = sec.querySelectorAll('.plugin-card:not([style*="display: none"])');
        sec.style.display = visibleCards.length > 0 ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
