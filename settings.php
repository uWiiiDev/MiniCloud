<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$pdo = getDB();
$loggedIn = isLoggedIn();
$userRow = null;

if ($loggedIn) {
    $stmtU = $pdo->prepare('SELECT id, username, email, two_factor_enabled, is_admin, theme, session_timeout, flash_duration, allow_user_sharing, storage_quota FROM users WHERE id = :id LIMIT 1');
    $stmtU->execute([':id' => (int)$_SESSION['user_id']]);
    $userRow = $stmtU->fetch() ?: null;
}

// Eigenes Passwort ändern (für angemeldete Benutzer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loggedIn && $userRow !== null) {
    $action = (string)($_POST['action'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
        header('Location: settings.php');
        exit;
    }

    if ($action === 'change_own_password') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass     = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        $stmtHash = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmtHash->execute([':id' => (int)$userRow['id']]);
        $currentHash = (string)($stmtHash->fetchColumn() ?: '');

        if ($currentHash === '' || !password_verify($currentPass, $currentHash)) {
            setFlash('error', 'Das eingegebene aktuelle Passwort ist nicht korrekt.');
        } elseif (mb_strlen($newPass) < 8) {
            setFlash('error', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.');
        } elseif ($newPass !== $confirmPass) {
            setFlash('error', 'Die Wiederholung des neuen Passworts stimmt nicht überein.');
        } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtUpd = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
            $stmtUpd->execute([
                ':h'  => $newHash,
                ':id' => (int)$userRow['id'],
            ]);
            setFlash('success', 'Ihr Passwort wurde erfolgreich geändert.');
        }

        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_own_2fa') {
        $emailInput  = trim((string)($_POST['email'] ?? ''));
        $enable2fa   = isset($_POST['two_factor_enabled']) && (string)$_POST['two_factor_enabled'] === '1';

        if ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        } elseif ($enable2fa && $emailInput === '') {
            setFlash('error', 'Für die Aktivierung der Zwei-Faktor-Authentifizierung (2FA) ist eine gültige E-Mail-Adresse erforderlich.');
        } else {
            $stmtUpd2fa = $pdo->prepare(
                'UPDATE users SET email = :email, two_factor_enabled = :tfa WHERE id = :id'
            );
            $stmtUpd2fa->execute([
                ':email' => $emailInput !== '' ? $emailInput : null,
                ':tfa'   => $enable2fa ? 1 : 0,
                ':id'    => (int)$userRow['id'],
            ]);
            setFlash(
                'success',
                $enable2fa
                    ? 'Zwei-Faktor-Authentifizierung (2FA per E-Mail) wurde für Ihr Konto aktiviert.'
                    : 'Ihre 2FA- und E-Mail-Einstellungen wurden gespeichert (2FA ist deaktiviert).'
            );
        }

        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_session_timeout') {
        $allowedTimeouts = getAllowedSessionTimeouts();
        $newTimeout = isset($_POST['session_timeout']) ? (int)$_POST['session_timeout'] : 30;
        if (!array_key_exists($newTimeout, $allowedTimeouts)) {
            $newTimeout = 30;
        }

        $stmtUpdTimeout = $pdo->prepare('UPDATE users SET session_timeout = :t WHERE id = :id');
        $stmtUpdTimeout->execute([
            ':t'  => $newTimeout,
            ':id' => (int)$userRow['id'],
        ]);
        $_SESSION['session_timeout'] = $newTimeout;
        $_SESSION['last_activity']   = time();

        setFlash(
            'success',
            $newTimeout > 0
                ? 'Das Timeout bis zur automatischen Abmeldung wurde auf ' . $newTimeout . ' Minuten festgelegt.'
                : 'Das automatische Timeout wurde deaktiviert (Sie bleiben angemeldet).'
        );

        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_flash_duration') {
        if (isset($_POST['flash_duration_preset']) && $_POST['flash_duration_preset'] !== '') {
            $newFlashSec = max(0, min(300, (int)$_POST['flash_duration_preset']));
        } else {
            $newFlashSec = max(0, min(300, (int)($_POST['custom_flash_duration'] ?? 10)));
        }

        $stmtUpdFlash = $pdo->prepare('UPDATE users SET flash_duration = :fd WHERE id = :id');
        $stmtUpdFlash->execute([
            ':fd' => $newFlashSec,
            ':id' => (int)$userRow['id'],
        ]);
        $_SESSION['flash_duration'] = $newFlashSec;

        setFlash(
            'success',
            $newFlashSec > 0
                ? sprintf('Die Anzeigedauer für Statusmeldungen wurde auf %d Sekunde(n) festgelegt.', $newFlashSec)
                : 'Statusmeldungen bleiben nun dauerhaft sichtbar, bis Sie diese manuell schließen.'
        );

        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_share_visibility') {
        $allowShare = isset($_POST['allow_user_sharing']) && (string)$_POST['allow_user_sharing'] === '1' ? 1 : 0;
        $stmtUpdShareVis = $pdo->prepare('UPDATE users SET allow_user_sharing = :v WHERE id = :id');
        $stmtUpdShareVis->execute([
            ':v'  => $allowShare,
            ':id' => (int)$userRow['id'],
        ]);

        setFlash(
            'success',
            $allowShare === 1
                ? 'Sie sind nun in der Teilen-Auswahl für andere Cloud-Benutzer sichtbar.'
                : 'Sie werden in der Teilen-Auswahl anderer Cloud-Benutzer nicht mehr angezeigt.'
        );

        header('Location: settings.php');
        exit;
    }
}

$pageTitle = 'Einstellungen';
require_once __DIR__ . '/includes/header.php';

$themeGroups = [
    'Sanft & Gedämpft Hell' => [
        'sand'     => ['label' => '☕ Sand (Warmbeige)',     'bg' => '#cfc2ab', 'card' => '#dfd4c0', 'text' => '#241e18'],
        'sage'     => ['label' => '🌿 Salbei (Naturgrün)',   'bg' => '#b6c8bd', 'card' => '#cad9d0', 'text' => '#16261e'],
        'nordic'   => ['label' => '☁️ Nordisch (Blaugrau)',  'bg' => '#b5c4d4', 'card' => '#c9d6e4', 'text' => '#172232'],
        'lavender' => ['label' => '🌸 Lavendel (Flieder)',   'bg' => '#c5bed4', 'card' => '#d6d0e3', 'text' => '#211a30'],
        'light'    => ['label' => '☀️ Hell (Klassisch)',     'bg' => '#cbd5e1', 'card' => '#e2e8f0', 'text' => '#1e293b'],
    ],
    'Halbdunkle Designs' => [
        'dusk'   => ['label' => '🌆 Dämmerung (Schiefer)',   'bg' => '#1e293b', 'card' => '#283548', 'text' => '#e2e8f0'],
        'mocha'  => ['label' => '🍫 Mokka (Warmbraun)',      'bg' => '#26201b', 'card' => '#332b24', 'text' => '#eaddcf'],
        'petrol' => ['label' => '💎 Petrol (Blaugrün)',      'bg' => '#132a31', 'card' => '#1b3842', 'text' => '#d9f2f7'],
        'plum'   => ['label' => '🍇 Pflaume (Violettgrau)',  'bg' => '#251b2e', 'card' => '#32253e', 'text' => '#ebdcf5'],
    ],
    'Dunkle Designs' => [
        'dark'   => ['label' => '🌙 Dunkel',                 'bg' => '#020617', 'card' => '#0f172a', 'text' => '#f8fafc'],
        'ocean'  => ['label' => '🌊 Ozean',                  'bg' => '#061326', 'card' => '#0c1f3d', 'text' => '#e0f2fe'],
        'forest' => ['label' => '🌲 Wald',                   'bg' => '#071a12', 'card' => '#0e291e', 'text' => '#dcfce7'],
    ],
];
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Kopfbereich -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Einstellungen</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Sprache, Farbdesign<?= $loggedIn ? ' und persönliche Kontoeinstellungen' : '' ?> anpassen.
            </p>
        </div>
        <a
            href="<?= $loggedIn ? 'index.php' : 'login.php' ?>"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto"
        >
            &larr; Zurück
        </a>
    </div>

    <?php if ($loggedIn && isAdmin()): ?>
        <!-- Direktzugang zur System-Administration für Admins -->
        <div class="mc-card bg-amber-50/70 dark:bg-amber-950/30 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs">
            <div class="flex items-start sm:items-center gap-3.5">
                <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-700 dark:text-amber-300 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">System-Administration</h2>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                        Benutzerkonten, 2FA, E-Mail/SMTP-Versand, Gruppenordner, Anwendungstitel, Logo, Impressum &amp; Server-Status verwalten.
                    </p>
                </div>
            </div>
            <a
                href="admin.php"
                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors shrink-0"
            >
                <span>Administration öffnen &rarr;</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- 1. SPRACHE WÄHLEN -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white mb-1">Sprache wählen</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
            Wählen Sie Ihre bevorzugte Sprache für die Benutzeroberfläche.
        </p>

        <form action="lang.php" method="post" class="grid grid-cols-2 sm:grid-cols-5 gap-2.5">
            <?php foreach (getAllowedLanguages() as $lCode => $lLabel): ?>
                <?php $isSelectedLang = ($currentLang === $lCode); ?>
                <button
                    type="submit"
                    name="lang"
                    value="<?= e($lCode) ?>"
                    class="flex items-center justify-center gap-2 px-3.5 py-2.5 rounded-xl border text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= $isSelectedLang ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                >
                    <span><?= e($lLabel) ?></span>
                </button>
            <?php endforeach; ?>
        </form>
    </section>

    <!-- 2. FARBDESIGN / THEME WÄHLEN -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white mb-1">Design-Theme wählen</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
            Alle hellen Themes sind angenehm abgedunkelt, um ein grelles Blenden zu vermeiden.
        </p>

        <form action="theme.php" method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

            <?php foreach ($themeGroups as $groupTitle => $themesInGroup): ?>
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2.5">
                        <?= e($groupTitle) ?>
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5">
                        <?php foreach ($themesInGroup as $tKey => $tMeta): ?>
                            <?php $isSelectedTheme = ($currentTheme === $tKey); ?>
                            <button
                                type="submit"
                                name="theme"
                                value="<?= e($tKey) ?>"
                                class="flex items-center justify-between gap-3 p-3 rounded-xl border text-left transition-all cursor-pointer <?= $isSelectedTheme ? 'ring-2 ring-brand-500 border-brand-500 bg-brand-50/40 dark:bg-brand-950/30' : 'border-slate-200 dark:border-slate-700/80 hover:border-brand-400 bg-slate-50/50 dark:bg-slate-800/50' ?>"
                            >
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <span
                                        class="w-6 h-6 rounded-lg border border-black/15 shrink-0 flex items-center justify-center"
                                        style="background-color: <?= e($tMeta['bg']) ?>;"
                                    >
                                        <span class="w-3 h-3 rounded-xs" style="background-color: <?= e($tMeta['card']) ?>;"></span>
                                    </span>
                                    <span class="text-xs sm:text-sm font-semibold text-slate-900 dark:text-white truncate">
                                        <?= e($tMeta['label']) ?>
                                    </span>
                                </div>
                                <?php if ($isSelectedTheme): ?>
                                    <span class="text-xs font-bold text-brand-600 dark:text-brand-400 shrink-0">✓</span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    </section>

    <?php if ($loggedIn && $userRow !== null): ?>
        <!-- 3. ZWEI-FAKTOR-AUTHENTIFIZIERUNG (2FA PER E-MAIL) -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔒 Zwei-Faktor-Authentifizierung (2FA)</h2>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= (int)$userRow['two_factor_enabled'] === 1 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                    <?= (int)$userRow['two_factor_enabled'] === 1 ? '🔒 2FA Aktiv' : '2FA Inaktiv' ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Sie können selbst entscheiden, ob Sie bei der Anmeldung zusätzlich zu Ihrem Passwort einen 6-stelligen Sicherheitscode per E-Mail erhalten möchten.
            </p>

            <form action="settings.php" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_own_2fa">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                    <div>
                        <label for="user_2fa_email" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Ihre E-Mail-Adresse für den 2FA-Code
                        </label>
                        <input
                            type="email"
                            id="user_2fa_email"
                            name="email"
                            value="<?= e((string)($userRow['email'] ?? '')) ?>"
                            placeholder="name@beispiel.de"
                            class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>

                    <div class="pt-1 sm:pt-5">
                        <label class="inline-flex items-center gap-2.5 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                name="two_factor_enabled"
                                value="1"
                                <?= (int)$userRow['two_factor_enabled'] === 1 ? 'checked' : '' ?>
                                class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300 dark:border-slate-600 cursor-pointer"
                            >
                            <span class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200">
                                2FA per E-Mail beim Anmelden aktivieren
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                    >
                        2FA-Einstellung speichern
                    </button>
                </div>
            </form>
        </section>

        <!-- 3b. AUTOMATISCHE ABMELDUNG (SITZUNGS-TIMEOUT) -->
        <?php
        $userTimeoutMin = isset($userRow['session_timeout']) ? (int)$userRow['session_timeout'] : 30;
        $allowedTimeouts = getAllowedSessionTimeouts();
        if (!array_key_exists($userTimeoutMin, $allowedTimeouts)) {
            $userTimeoutMin = 30;
        }
        ?>
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">⏱️ Automatische Abmeldung (Timeout)</h2>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $userTimeoutMin > 0 ? 'bg-brand-100 dark:bg-brand-950 text-brand-800 dark:text-brand-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                    <?= e($allowedTimeouts[$userTimeoutMin]) ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Legen Sie fest, nach wie vielen Minuten ohne Aktivität sich die Anwendung zu Ihrer Sicherheit automatisch abmeldet.
            </p>

            <form action="settings.php" method="post" class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_session_timeout">

                <?php foreach ($allowedTimeouts as $tMin => $tLabel): ?>
                    <?php $isSelectedTimeout = ($userTimeoutMin === (int)$tMin); ?>
                    <button
                        type="submit"
                        name="session_timeout"
                        value="<?= (int)$tMin ?>"
                        class="flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-xl border text-left text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= $isSelectedTimeout ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                    >
                        <span class="truncate"><?= e($tLabel) ?></span>
                        <?php if ($isSelectedTimeout): ?>
                            <span class="text-xs font-bold shrink-0">✓</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </form>
        </section>

        <!-- 3b-2. ANZEIGEDAUER VON STATUSMELDUNGEN -->
        <?php
        $userFlashSec = isset($userRow['flash_duration']) ? max(0, min(300, (int)$userRow['flash_duration'])) : 10;
        $flashPresets = [
            1  => '1 Sekunde',
            3  => '3 Sekunden',
            5  => '5 Sekunden',
            10 => '10 Sekunden (Standard)',
            15 => '15 Sekunden',
            0  => 'Nicht ausblenden (Manuell)',
        ];
        $flashBadgeLabel = $userFlashSec === 0
            ? 'Manuell schließen'
            : ($userFlashSec === 1 ? '1 Sekunde' : $userFlashSec . ' Sekunden');
        ?>
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔔 Anzeigedauer von Statusmeldungen</h2>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $userFlashSec > 0 ? 'bg-brand-100 dark:bg-brand-950 text-brand-800 dark:text-brand-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                    <?= e($flashBadgeLabel) ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Bestimmen Sie, nach wie vielen Sekunden grüne, gelbe oder rote Rückmeldungen oben auf der Seite automatisch ausgeblendet werden – oder geben Sie eine eigene Sekundenzahl ein.
            </p>

            <form action="settings.php" method="post" class="space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_flash_duration">

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                    <?php foreach ($flashPresets as $fSec => $fLabel): ?>
                        <?php $isSelectedFlash = ($userFlashSec === (int)$fSec); ?>
                        <button
                            type="submit"
                            name="flash_duration_preset"
                            value="<?= (int)$fSec ?>"
                            class="flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-xl border text-left text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= $isSelectedFlash ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                        >
                            <span class="truncate"><?= e($fLabel) ?></span>
                            <?php if ($isSelectedFlash): ?>
                                <span class="text-xs font-bold shrink-0">✓</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="pt-2 border-t border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <label for="custom_flash_duration" class="text-xs font-medium text-slate-700 dark:text-slate-300">
                        Eigene Dauer in Sekunden (1–300 s, oder 0 = nie automatisch ausblenden):
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            type="number"
                            id="custom_flash_duration"
                            name="custom_flash_duration"
                            min="0"
                            max="300"
                            value="<?= (int)$userFlashSec ?>"
                            class="w-24 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                        <span class="text-xs text-slate-500 dark:text-slate-400">Sek.</span>
                        <button
                            type="submit"
                            class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                        >
                            Übernehmen
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <!-- 3c. SICHTBARKEIT IN DER TEILEN-AUSWAHL (DATENSCHUTZ BEI DIREKTFREIGABEN) -->
        <?php $allowUserSharing = isset($userRow['allow_user_sharing']) && (int)$userRow['allow_user_sharing'] === 1; ?>
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">👥 Sichtbarkeit in der Teilen-Auswahl</h2>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $allowUserSharing ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                    <?= $allowUserSharing ? '👁️ Sichtbar für Direktfreigaben' : '🔒 Versteckt (Standard)' ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Standardmäßig ist Ihr Konto zu Ihrem Schutz versteckt, sodass andere Benutzer Ihren Namen nicht in der Teilen-Auswahl sehen. Wenn Sie die Sichtbarkeit aktivieren, können andere angemeldete Cloud-Benutzer Dateien und Ordner direkt mit Ihnen teilen.
            </p>

            <form action="settings.php" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_share_visibility">

                <button
                    type="submit"
                    name="allow_user_sharing"
                    value="0"
                    class="flex items-center justify-between gap-2 px-4 py-3 rounded-xl border text-left text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= !$allowUserSharing ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                >
                    <span>🔒 Nicht in Teilen-Auswahl anzeigen (Standard)</span>
                    <?php if (!$allowUserSharing): ?>
                        <span class="text-xs font-bold shrink-0">✓</span>
                    <?php endif; ?>
                </button>

                <button
                    type="submit"
                    name="allow_user_sharing"
                    value="1"
                    class="flex items-center justify-between gap-2 px-4 py-3 rounded-xl border text-left text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= $allowUserSharing ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                >
                    <span>👁️ In Teilen-Auswahl anzeigen (Direktfreigaben erlauben)</span>
                    <?php if ($allowUserSharing): ?>
                        <span class="text-xs font-bold shrink-0">✓</span>
                    <?php endif; ?>
                </button>
            </form>
        </section>

        <!-- 4. PERSÖNLICHES KONTO & PASSWORT ÄNDERN -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white mb-1">Eigenes Passwort ändern</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Angemeldet als <strong><?= e((string)$userRow['username']) ?></strong>
            </p>

            <form action="settings.php" method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 items-end">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="change_own_password">

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Aktuelles Passwort</label>
                    <input
                        type="password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Neues Passwort</label>
                    <input
                        type="password"
                        name="new_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Mindestens 8 Zeichen"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Neues Passwort bestätigen</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="password"
                            name="confirm_password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                        <button
                            type="submit"
                            class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                        >
                            Speichern
                        </button>
                    </div>
                </div>
            </form>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
