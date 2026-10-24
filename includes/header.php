<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/i18n.php';

$currentLang = getCurrentLang();
if ($currentLang !== 'de') {
    ob_start('translateHtmlBuffer');
}

$appSettings = getAppSettings();
$appTitle    = $appSettings['app_title'];
$appSubtitle = $appSettings['app_subtitle'];
$appLogoUri  = $appSettings['logo_data_uri'];

$pageTitle = isset($pageTitle) ? $pageTitle . ' – ' . $appTitle : $appTitle . ' – Sicherer Cloud-Speicher';
$flashes = getFlashes();
$currentTheme = getCurrentTheme();
$isDarkLike = in_array($currentTheme, ['dusk', 'mocha', 'petrol', 'plum', 'dark', 'ocean', 'forest'], true);

$headerStorageStats = null;
$headerBarColor = 'bg-brand-600';
if (isLoggedIn()) {
    $headerStorageStats = isset($storageStats) && is_array($storageStats)
        ? $storageStats
        : getUserStorageStats(getDB(), (int)$_SESSION['user_id']);
    if ($headerStorageStats['percent'] >= 90) {
        $headerBarColor = 'bg-red-600';
    } elseif ($headerStorageStats['percent'] >= 75) {
        $headerBarColor = 'bg-amber-500';
    }
}
?>
<?php
$pwaLogoHash = substr(hash('sha256', (string)$appLogoUri), 0, 10);
?>
<!DOCTYPE html>
<html lang="de" class="h-full <?= $isDarkLike ? 'dark' : '' ?> theme-<?= e($currentTheme) ?>" data-theme="<?= e($currentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= e($appTitle) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="manifest" href="manifest.php?v=<?= e($pwaLogoHash) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="app-icon.php?size=180&amp;v=<?= e($pwaLogoHash) ?>">
    <?php if ($appLogoUri !== ''): ?>
        <link rel="icon" href="<?= e($appLogoUri) ?>">
    <?php else: ?>
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%232563eb'/%3E%3Cstop offset='100%25' stop-color='%234338ca'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cpath d='M18 40a8 8 0 008 8h18a10 10 0 10-.2-19.998 10.004 10.004 0 10-19.56 4.192A8.002 8.002 0 0018 40z' fill='none' stroke='%23ffffff' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <?php endif; ?>
    <!-- Tailwind CSS (Lokal eingebunden – 100 % DSGVO-konform ohne externe Serveraufrufe) -->
    <script src="assets/tailwind.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    }
                }
            }
        };
    </script>
    <style>
        /* --- Gedämpfte, angenehm abgedunkelte helle Themes (kein grelles Weiß) --- */

        /* 0. Klassisch Hell (abgedunkelt auf sanftes Schiefergrau) */
        html.theme-light body { background-color: #cbd5e1 !important; color: #1e293b !important; }
        html.theme-light header,
        html.theme-light footer,
        html.theme-light .mc-card { background-color: #e2e8f0 !important; border-color: #b8c4d4 !important; }
        html.theme-light thead tr,
        html.theme-light #dropZone { background-color: #d5deea !important; border-color: #b8c4d4 !important; }
        html.theme-light tbody tr:hover { background-color: #d8e1ed !important; }
        html.theme-light input:not([type="checkbox"]),
        html.theme-light select,
        html.theme-light textarea { background-color: #edf2f7 !important; border-color: #b0bfd1 !important; color: #1e293b !important; }

        /* 1. Sand / Warmbeige (deutlich abgedunkelt) */
        html.theme-sand body { background-color: #cfc2ab !important; color: #241e18 !important; }
        html.theme-sand header,
        html.theme-sand footer,
        html.theme-sand .mc-card { background-color: #dfd4c0 !important; border-color: #bba98b !important; }
        html.theme-sand thead tr,
        html.theme-sand #dropZone { background-color: #d5c8b1 !important; border-color: #bba98b !important; }
        html.theme-sand tbody tr:hover { background-color: #d6c9b2 !important; }
        html.theme-sand input:not([type="checkbox"]),
        html.theme-sand select,
        html.theme-sand textarea { background-color: #e8dfd0 !important; border-color: #b5a283 !important; color: #241e18 !important; }

        /* 2. Salbei / Sanftes Naturgrün (deutlich abgedunkelt) */
        html.theme-sage body { background-color: #b6c8bd !important; color: #16261e !important; }
        html.theme-sage header,
        html.theme-sage footer,
        html.theme-sage .mc-card { background-color: #cad9d0 !important; border-color: #9ab3a4 !important; }
        html.theme-sage thead tr,
        html.theme-sage #dropZone { background-color: #bfd0c6 !important; border-color: #9ab3a4 !important; }
        html.theme-sage tbody tr:hover { background-color: #c0d1c7 !important; }
        html.theme-sage input:not([type="checkbox"]),
        html.theme-sage select,
        html.theme-sage textarea { background-color: #d5e2da !important; border-color: #93ad9d !important; color: #16261e !important; }

        /* 3. Nordisch / Gedämpftes Blaugrau (deutlich abgedunkelt) */
        html.theme-nordic body { background-color: #b5c4d4 !important; color: #172232 !important; }
        html.theme-nordic header,
        html.theme-nordic footer,
        html.theme-nordic .mc-card { background-color: #c9d6e4 !important; border-color: #9bb0c6 !important; }
        html.theme-nordic thead tr,
        html.theme-nordic #dropZone { background-color: #beccd9 !important; border-color: #9bb0c6 !important; }
        html.theme-nordic tbody tr:hover { background-color: #bfcedc !important; }
        html.theme-nordic input:not([type="checkbox"]),
        html.theme-nordic select,
        html.theme-nordic textarea { background-color: #d5e0ec !important; border-color: #93a9c0 !important; color: #172232 !important; }

        /* 4. Lavendel / Sanftes Flieder-Grau (deutlich abgedunkelt) */
        html.theme-lavender body { background-color: #c3bad4 !important; color: #1f1730 !important; }
        html.theme-lavender header,
        html.theme-lavender footer,
        html.theme-lavender .mc-card { background-color: #d4cde3 !important; border-color: #ab9fc2 !important; }
        html.theme-lavender thead tr,
        html.theme-lavender #dropZone { background-color: #cac2db !important; border-color: #ab9fc2 !important; }
        html.theme-lavender tbody tr:hover { background-color: #cbc3dc !important; }
        html.theme-lavender input:not([type="checkbox"]),
        html.theme-lavender select,
        html.theme-lavender textarea { background-color: #ded8ea !important; border-color: #a396bc !important; color: #1f1730 !important; }

        /* --- Halbdunkle Themes (Angenehme Mittelton-Dunkelheit, kein tiefes Schwarz) --- */

        /* 5. Dämmerung / Schiefer-Graublau (Halbdunkel) */
        html.theme-dusk.dark body { background-color: #334155 !important; color: #f1f5f9 !important; }
        html.theme-dusk.dark header,
        html.theme-dusk.dark footer,
        html.theme-dusk.dark .mc-card { background-color: #3e4e63 !important; border-color: #52647c !important; }
        html.theme-dusk.dark thead tr,
        html.theme-dusk.dark #dropZone { background-color: #38475b !important; border-color: #52647c !important; }
        html.theme-dusk.dark tbody tr:hover { background-color: #465870 !important; }
        html.theme-dusk.dark input:not([type="checkbox"]),
        html.theme-dusk.dark select,
        html.theme-dusk.dark textarea { background-color: #2d3a4b !important; border-color: #566a84 !important; color: #f8fafc !important; }

        /* 6. Mokka / Warmes Espresso-Grau (Halbdunkel) */
        html.theme-mocha.dark body { background-color: #3b332d !important; color: #f5f0eb !important; }
        html.theme-mocha.dark header,
        html.theme-mocha.dark footer,
        html.theme-mocha.dark .mc-card { background-color: #493f38 !important; border-color: #63564c !important; }
        html.theme-mocha.dark thead tr,
        html.theme-mocha.dark #dropZone { background-color: #423932 !important; border-color: #63564c !important; }
        html.theme-mocha.dark tbody tr:hover { background-color: #52473f !important; }
        html.theme-mocha.dark input:not([type="checkbox"]),
        html.theme-mocha.dark select,
        html.theme-mocha.dark textarea { background-color: #332c26 !important; border-color: #695b51 !important; color: #faf6f2 !important; }

        /* 7. Petrol / Gedämpftes Blaugrün (Halbdunkel) */
        html.theme-petrol.dark body { background-color: #243e47 !important; color: #ecfeff !important; }
        html.theme-petrol.dark header,
        html.theme-petrol.dark footer,
        html.theme-petrol.dark .mc-card { background-color: #2c4c57 !important; border-color: #3f6674 !important; }
        html.theme-petrol.dark thead tr,
        html.theme-petrol.dark #dropZone { background-color: #28454f !important; border-color: #3f6674 !important; }
        html.theme-petrol.dark tbody tr:hover { background-color: #335763 !important; }
        html.theme-petrol.dark input:not([type="checkbox"]),
        html.theme-petrol.dark select,
        html.theme-petrol.dark textarea { background-color: #1e353d !important; border-color: #456e7d !important; color: #f0fdfa !important; }

        /* 8. Pflaume / Sanftes Violett-Schiefer (Halbdunkel) */
        html.theme-plum.dark body { background-color: #393046 !important; color: #f5f3ff !important; }
        html.theme-plum.dark header,
        html.theme-plum.dark footer,
        html.theme-plum.dark .mc-card { background-color: #463b56 !important; border-color: #5e5073 !important; }
        html.theme-plum.dark thead tr,
        html.theme-plum.dark #dropZone { background-color: #3f354e !important; border-color: #5e5073 !important; }
        html.theme-plum.dark tbody tr:hover { background-color: #4f4361 !important; }
        html.theme-plum.dark input:not([type="checkbox"]),
        html.theme-plum.dark select,
        html.theme-plum.dark textarea { background-color: #2f273b !important; border-color: #65567b !important; color: #faf5ff !important; }

        /* --- Dunkle Themes (Dark, Ocean & Forest) --- */
        html.theme-ocean.dark body { background-color: #07192f !important; color: #e2e8f0; }
        html.theme-ocean.dark header,
        html.theme-ocean.dark footer,
        html.theme-ocean.dark .mc-card { background-color: #0c2544 !important; border-color: #193b66 !important; }
        html.theme-forest.dark body { background-color: #071f18 !important; color: #e2e8f0; }
        html.theme-forest.dark header,
        html.theme-forest.dark footer,
        html.theme-forest.dark .mc-card { background-color: #0c2e24 !important; border-color: #164e3d !important; }

        /* --- Mobile-Optimierungen (Verhindert ungewollten Auto-Zoom unter iOS/Android & verbessert Touch-Bedienung) --- */
        @media (max-width: 640px) {
            input:not([type="checkbox"]):not([type="radio"]),
            select,
            textarea {
                font-size: 16px !important;
            }
            #themeSelect {
                font-size: 13px !important;
            }
        }
        button, a, select, input, label {
            touch-action: manipulation;
        }
    </style>
</head>
<body class="min-h-full flex flex-col overflow-x-hidden bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 antialiased selection:bg-brand-600 selection:text-white transition-colors duration-200">

<!-- Top Navigation (Full Responsive) -->
<header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 sticky top-0 z-30 shadow-xs transition-colors">
    <div class="max-w-7xl mx-auto px-2.5 sm:px-6 lg:px-8 h-14 sm:h-16 flex items-center justify-between gap-1.5 sm:gap-2">
        <!-- Brand Logo & Application Title -->
        <a href="index.php" class="flex items-center gap-2 sm:gap-3 group min-w-0 shrink">
            <?php if ($appLogoUri !== ''): ?>
                <img
                    src="<?= e($appLogoUri) ?>"
                    alt="<?= e($appTitle) ?> Logo"
                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl object-contain bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 p-1 shadow-xs group-hover:scale-105 transition-transform shrink-0"
                >
            <?php else: ?>
                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-700 flex items-center justify-center text-white shadow-sm group-hover:scale-105 transition-transform shrink-0">
                    <svg class="w-4 h-4 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                    </svg>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <span class="block text-sm sm:text-lg font-bold tracking-tight text-slate-900 dark:text-white truncate max-w-[105px] sm:max-w-none"><?= e($appTitle) ?></span>
                <?php if (trim($appSubtitle) !== ''): ?>
                    <span class="hidden md:inline-block ml-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-50 dark:bg-brand-900/50 text-brand-700 dark:text-brand-300 border border-brand-100 dark:border-brand-800"><?= e($appSubtitle) ?></span>
                <?php endif; ?>
            </div>
        </a>

        <!-- Right Navigation & Controls -->
        <nav class="flex items-center gap-1 sm:gap-2.5 shrink-0">
            <?php if (isLoggedIn()): ?>
                <?php if ($headerStorageStats !== null): ?>
                    <!-- Kompakte Speicherplatz-Anzeige in der Kopfzeile -->
                    <div
                        class="hidden sm:flex flex-col justify-center px-3 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 min-w-[140px] lg:min-w-[175px]"
                        title="Belegt: <?= e(formatBytes($headerStorageStats['used'])) ?> von <?= e(formatBytes($headerStorageStats['quota'])) ?> (<?= e(formatBytes($headerStorageStats['free'])) ?> frei)"
                    >
                        <div class="flex items-center justify-between gap-2 text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1">
                            <span class="truncate"><?= e(formatBytes($headerStorageStats['used'], 1)) ?> / <?= e(formatBytes($headerStorageStats['quota'], 0)) ?></span>
                            <span class="font-semibold text-slate-800 dark:text-slate-100"><?= e(number_format($headerStorageStats['percent'], 0, ',', '.')) ?>%</span>
                        </div>
                        <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div
                                class="h-full <?= $headerBarColor ?> rounded-full transition-all duration-300"
                                style="width: <?= e((string)max($headerStorageStats['used'] > 0 ? 3 : 0, $headerStorageStats['percent'])) ?>%"
                            ></div>
                        </div>
                    </div>
                <?php endif; ?>

                <a
                    href="index.php"
                    class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    title="Meine Dateien"
                >
                    <svg class="w-4 h-4 text-brand-600 dark:text-brand-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                    <span class="hidden sm:inline">Dateien</span>
                </a>

                <?php if (($appSettings['enable_calendar'] ?? '1') === '1'): ?>
                    <a
                        href="calendar.php"
                        class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        title="Kalender &amp; Termine"
                    >
                        <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span class="hidden sm:inline">Kalender</span>
                    </a>
                <?php endif; ?>

                <?php if (($appSettings['enable_chat'] ?? '1') === '1'): ?>
                    <?php $headerUnreadChat = getUnreadMessagesCount(getDB(), (int)$_SESSION['user_id']); ?>
                    <a
                        href="messages.php"
                        class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors relative"
                        title="Chat &amp; Nachrichten"
                    >
                        <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <span class="hidden sm:inline">Chat</span>
                        <span
                            id="headerUnreadChatBadge"
                            class="<?= $headerUnreadChat > 0 ? 'inline-flex' : 'hidden' ?> items-center justify-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-red-600 text-white shadow-2xs"
                        >
                            <?= $headerUnreadChat ?>
                        </span>
                    </a>
                <?php endif; ?>

                <a
                    href="trash.php"
                    class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    title="Papierkorb (30 Tage Aufbewahrung)"
                >
                    <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span class="hidden sm:inline">Papierkorb</span>
                    <?php if ($headerStorageStats !== null && (int)($headerStorageStats['trash_count'] ?? 0) > 0): ?>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                            <?= (int)$headerStorageStats['trash_count'] ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="truncate max-w-[120px]" title="<?= e((string)$_SESSION['username']) ?>">
                        <?= e((string)$_SESSION['username']) ?>
                    </span>
                    <?php if (isAdmin()): ?>
                        <a href="admin.php" title="Benutzer- &amp; Gruppenordner-Administration" class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-brand-600 hover:bg-brand-700 text-white transition-colors">Admin</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Einstellungen (Zahnrädchen-Symbol) -->
            <a
                href="settings.php"
                class="inline-flex items-center justify-center p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/70 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700/80 transition-colors"
                title="Einstellungen"
                aria-label="Einstellungen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>

            <?php if (isLoggedIn()): ?>
                <form action="logout.php" method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <button
                        type="submit"
                        title="Abmelden"
                        class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 px-2 sm:px-3 py-1.5 rounded-xl hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span class="hidden sm:inline">Abmelden</span>
                    </button>
                </form>
            <?php else: ?>
                <a href="login.php" class="text-xs sm:text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 px-3 py-1.5 sm:px-3.5 sm:py-2 rounded-xl shadow-xs transition-colors">Anmelden</a>
            <?php endif; ?>
        </nav>
    </div>

    <?php if (isLoggedIn() && $headerStorageStats !== null): ?>
        <!-- Kompakte Mobil-Speicherleiste (nur auf Smartphones < 640px sichtbar) -->
        <div class="sm:hidden px-3 py-1.5 border-t border-slate-200/70 dark:border-slate-800/80 flex items-center justify-between gap-3 text-[11px] text-slate-600 dark:text-slate-300">
            <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                <span class="font-semibold truncate max-w-[110px]"><?= e((string)$_SESSION['username']) ?></span>
            </div>
            <div class="flex items-center gap-2 flex-1 max-w-[210px]">
                <div class="flex-1 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                    <div
                        class="h-full <?= $headerBarColor ?> rounded-full"
                        style="width: <?= e((string)max($headerStorageStats['used'] > 0 ? 3 : 0, $headerStorageStats['percent'])) ?>%"
                    ></div>
                </div>
                <span class="whitespace-nowrap font-medium text-[10px]">
                    <?= e(formatBytes($headerStorageStats['used'], 1)) ?> / <?= e(formatBytes($headerStorageStats['quota'], 0)) ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</header>

<!-- Main Container -->
<main class="flex-1 max-w-7xl w-full mx-auto px-2.5 sm:px-6 lg:px-8 py-4 sm:py-8">
    <?php if (!empty($flashes)): ?>
        <div id="flashContainer" class="space-y-3 mb-6" role="region" aria-label="Benachrichtigungen">
            <?php foreach ($flashes as $flash): ?>
                <?php
                $isError = ($flash['type'] === 'error');
                $isWarning = ($flash['type'] === 'warning');
                $boxClasses = $isError
                    ? 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800/80 text-red-800 dark:text-red-200'
                    : ($isWarning
                        ? 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800/80 text-amber-800 dark:text-amber-200'
                        : 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800/80 text-emerald-800 dark:text-emerald-200');
                ?>
                <div class="mc-flash-msg flex items-start justify-between gap-3 p-4 rounded-xl border <?= $boxClasses ?> shadow-xs transition-all duration-300">
                    <div class="flex items-center gap-2.5 text-sm font-medium">
                        <?php if ($isError): ?>
                            <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        <?php elseif ($isWarning): ?>
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        <?php else: ?>
                            <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        <?php endif; ?>
                        <span><?= e($flash['message']) ?></span>
                    </div>
                    <button type="button" onclick="const box = this.closest('.mc-flash-msg'); const wrap = document.getElementById('flashContainer'); if (box) box.remove(); if (wrap && wrap.querySelectorAll('.mc-flash-msg').length === 0) wrap.remove();" class="text-current opacity-60 hover:opacity-100 p-1 cursor-pointer" aria-label="Schließen">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php $flashDurationSec = getCurrentFlashDuration(); ?>
        <?php if ($flashDurationSec > 0): ?>
            <script>
                setTimeout(() => {
                    const wrap = document.getElementById('flashContainer');
                    if (!wrap) return;
                    wrap.querySelectorAll('.mc-flash-msg').forEach((msg) => {
                        msg.classList.add('opacity-0', '-translate-y-1');
                    });
                    setTimeout(() => {
                        wrap.remove();
                    }, 300);
                }, <?= (int)($flashDurationSec * 1000) ?>);
            </script>
        <?php endif; ?>
    <?php endif; ?>
