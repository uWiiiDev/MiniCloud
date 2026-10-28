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
    $stmtU = $pdo->prepare('SELECT id, username, email, two_factor_enabled, totp_secret, is_admin, theme, session_timeout, flash_duration, chat_notify_mode, allow_user_sharing, storage_quota FROM users WHERE id = :id LIMIT 1');
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
        $emailInput = trim((string)($_POST['email'] ?? ''));
        $tfaMode    = isset($_POST['two_factor_mode'])
            ? max(0, min(2, (int)$_POST['two_factor_mode']))
            : (isset($_POST['two_factor_enabled']) && (string)$_POST['two_factor_enabled'] === '1' ? 1 : 0);
        $hasTotp    = trim((string)($userRow['totp_secret'] ?? '')) !== '';

        if ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        } elseif ($tfaMode === 1 && $emailInput === '') {
            setFlash('error', 'Für die 2FA per E-Mail ist eine gültige E-Mail-Adresse erforderlich.');
        } elseif ($tfaMode === 2 && !$hasTotp) {
            setFlash('error', 'Bitte richten Sie zuerst unten Ihre Authenticator-App (Google Authenticator) per QR-Code ein.');
        } else {
            $stmtUpd2fa = $pdo->prepare(
                'UPDATE users SET email = :email, two_factor_enabled = :tfa WHERE id = :id'
            );
            $stmtUpd2fa->execute([
                ':email' => $emailInput !== '' ? $emailInput : null,
                ':tfa'   => $tfaMode,
                ':id'    => (int)$userRow['id'],
            ]);
            if ($tfaMode === 2) {
                setFlash('success', 'Zwei-Faktor-Authentifizierung per Authenticator-App (Google Authenticator / TOTP) ist nun aktiv.');
            } elseif ($tfaMode === 1) {
                setFlash('success', 'Zwei-Faktor-Authentifizierung per E-Mail-Code wurde für Ihr Konto aktiviert.');
            } else {
                setFlash('success', 'Ihre E-Mail-Adresse wurde gespeichert (2FA beim Anmelden ist deaktiviert).');
            }
        }

        header('Location: settings.php#sec-2fa');
        exit;
    }

    if ($action === 'setup_totp_2fa') {
        $pendingSecret = trim((string)($_SESSION['pending_totp_secret'] ?? ''));
        $verifyCode    = (string)($_POST['totp_verify_code'] ?? '');

        if ($pendingSecret === '') {
            setFlash('error', 'Die Einrichtungssitzung ist abgelaufen. Bitte versuchen Sie es erneut.');
        } elseif (!verifyTotpCode($pendingSecret, $verifyCode)) {
            setFlash('error', 'Der eingegebene 6-stellige Authenticator-Code ist ungültig. Bitte prüfen Sie die Uhrzeit Ihres Smartphones und versuchen Sie es erneut.');
        } else {
            $stmtSaveTotp = $pdo->prepare(
                'UPDATE users SET totp_secret = :sec, two_factor_enabled = 2 WHERE id = :id'
            );
            $stmtSaveTotp->execute([
                ':sec' => $pendingSecret,
                ':id'  => (int)$userRow['id'],
            ]);
            unset($_SESSION['pending_totp_secret']);
            setFlash('success', 'Google Authenticator / TOTP wurde erfolgreich eingerichtet und als 2FA-Methode aktiviert!');
        }

        header('Location: settings.php#sec-2fa');
        exit;
    }

    if ($action === 'reset_totp_secret') {
        $newFallbackMode = ((int)$userRow['two_factor_enabled'] === 2) ? 0 : (int)$userRow['two_factor_enabled'];
        $stmtResetTotp = $pdo->prepare(
            'UPDATE users SET totp_secret = NULL, two_factor_enabled = :tfa WHERE id = :id'
        );
        $stmtResetTotp->execute([
            ':tfa' => $newFallbackMode,
            ':id'  => (int)$userRow['id'],
        ]);
        $_SESSION['pending_totp_secret'] = generateTotpSecret(32);
        setFlash('success', 'Die bisherige Authenticator-Verknüpfung wurde entfernt. Sie können unten jederzeit einen neuen QR-Code scannen.');
        header('Location: settings.php#sec-2fa');
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

    if ($action === 'update_chat_notify_mode') {
        $newMode = isset($_POST['chat_notify_mode']) ? max(0, min(3, (int)$_POST['chat_notify_mode'])) : 1;

        $stmtUpdNotify = $pdo->prepare('UPDATE users SET chat_notify_mode = :m WHERE id = :id');
        $stmtUpdNotify->execute([
            ':m'  => $newMode,
            ':id' => (int)$userRow['id'],
        ]);
        $_SESSION['chat_notify_mode'] = $newMode;

        $msgMap = [
            1 => 'Chat-Benachrichtigungen wurden auf „Nur System-Benachrichtigung (PC & Mobilgerät)“ gesetzt.',
            2 => 'Chat-Benachrichtigungen wurden auf „System-Benachrichtigung & In-App-Banner“ gesetzt.',
            3 => 'Chat-Benachrichtigungen wurden auf „Nur In-App-Banner“ gesetzt.',
            0 => 'Chat-Benachrichtigungen wurden deaktiviert (es wird nur der rote Zähler angezeigt).',
        ];
        setFlash('success', $msgMap[$newMode] ?? $msgMap[1]);

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

    if ($action === 'create_app_password') {
        $label = trim((string)($_POST['app_label'] ?? ''));
        if ($label === '') {
            $label = 'Gerät vom ' . date('d.m.Y');
        }
        $label = mb_substr($label, 0, 120);

        $part1 = bin2hex(random_bytes(2));
        $part2 = bin2hex(random_bytes(2));
        $part3 = bin2hex(random_bytes(2));
        $part4 = bin2hex(random_bytes(2));
        $plainToken = sprintf('mc-%s-%s-%s-%s', $part1, $part2, $part3, $part4);
        $prefix     = 'mc-' . $part1;
        $hash       = password_hash($plainToken, PASSWORD_BCRYPT, ['cost' => 11]);

        $stmtInsApp = $pdo->prepare(
            'INSERT INTO app_passwords (user_id, label, token_prefix, password_hash)
             VALUES (:uid, :lbl, :pfx, :hash)'
        );
        $stmtInsApp->execute([
            ':uid'  => (int)$userRow['id'],
            ':lbl'  => $label,
            ':pfx'  => $prefix,
            ':hash' => $hash,
        ]);

        $_SESSION['new_app_password_plain'] = $plainToken;
        $_SESSION['new_app_password_label'] = $label;

        logAuditEvent($pdo, 'app_password_created', 'App-Passwort erstellt: ' . $label, (int)$userRow['id'], (string)$userRow['username']);
        setFlash('success', 'Neues App-Passwort für „' . $label . '“ wurde erzeugt. Bitte jetzt kopieren!');
        header('Location: settings.php#app-passwords');
        exit;
    }

    if ($action === 'revoke_app_password') {
        $appId = (int)($_POST['app_password_id'] ?? 0);
        if ($appId > 0) {
            $pdo->prepare('DELETE FROM app_passwords WHERE id = :id AND user_id = :uid')
                ->execute([':id' => $appId, ':uid' => (int)$userRow['id']]);
            logAuditEvent($pdo, 'app_password_revoked', 'App-Passwort widerrufen (ID ' . $appId . ')', (int)$userRow['id'], (string)$userRow['username']);
            setFlash('success', 'Das App-Passwort wurde widerrufen.');
        }
        header('Location: settings.php#app-passwords');
        exit;
    }
}

$newAppPasswordPlain = $_SESSION['new_app_password_plain'] ?? null;
$newAppPasswordLabel = $_SESSION['new_app_password_label'] ?? null;
unset($_SESSION['new_app_password_plain'], $_SESSION['new_app_password_label']);

$userAppPasswords = [];
try {
    $stmtListApps = $pdo->prepare('SELECT * FROM app_passwords WHERE user_id = :uid ORDER BY created_at DESC');
    $stmtListApps->execute([':uid' => (int)$userRow['id']]);
    $userAppPasswords = $stmtListApps->fetchAll();
} catch (Throwable) {
    $userAppPasswords = [];
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
        'dark'     => ['label' => '🌙 Dunkel',                    'bg' => '#020617', 'card' => '#0f172a', 'text' => '#f8fafc'],
        'ocean'    => ['label' => '🌊 Ozean',                     'bg' => '#061326', 'card' => '#0c1f3d', 'text' => '#e0f2fe'],
        'forest'   => ['label' => '🌲 Wald',                      'bg' => '#071a12', 'card' => '#0e291e', 'text' => '#dcfce7'],
        'obsidian' => ['label' => '🌌 Mitternacht (Tiefviolett)', 'bg' => '#0b0719', 'card' => '#16102c', 'text' => '#ede9fe'],
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

    <?php if ($loggedIn && hasAdminPermission()): ?>
        <!-- Direktzugang zur System- bzw. Teil-Administration -->
        <div class="mc-card <?= isAdmin() ? 'bg-amber-50/70 dark:bg-amber-950/30 border-amber-200 dark:border-amber-800/70' : 'bg-indigo-50/70 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-800/70' ?> rounded-2xl border p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-xs">
            <div class="flex items-start sm:items-center gap-3.5">
                <div class="w-10 h-10 rounded-xl <?= isAdmin() ? 'bg-amber-500/20 text-amber-700 dark:text-amber-300' : 'bg-indigo-500/20 text-indigo-700 dark:text-indigo-300' ?> flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white"><?= isAdmin() ? 'System-Administration' : '🛡️ Teil-Administration' ?></h2>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                        <?= isAdmin()
                            ? 'Benutzerkonten, 2FA, E-Mail/SMTP-Versand, Gruppenordner, Anwendungstitel, Logo, Impressum &amp; Server-Status verwalten.'
                            : 'Zugriff auf Ihre vom Haupt-Administrator freigeschalteten Verwaltungsbereiche.' ?>
                    </p>
                </div>
            </div>
            <a
                href="admin.php"
                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl <?= isAdmin() ? 'bg-amber-600 hover:bg-amber-700' : 'bg-indigo-600 hover:bg-indigo-700' ?> text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors shrink-0"
            >
                <span><?= isAdmin() ? 'Administration öffnen' : 'Teil-Administration öffnen' ?> &rarr;</span>
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
        <!-- 3. E-MAIL-ADRESSE (PASSWORT-WIEDERHERSTELLUNG) & ZWEI-FAKTOR-AUTHENTIFIZIERUNG (2FA) -->
        <?php
        $userTfaMode = (int)($userRow['two_factor_enabled'] ?? 0);
        $userTotpSecret = trim((string)($userRow['totp_secret'] ?? ''));
        $hasTotpConfigured = ($userTotpSecret !== '');

        if (!$hasTotpConfigured) {
            if (empty($_SESSION['pending_totp_secret'])) {
                $_SESSION['pending_totp_secret'] = generateTotpSecret(32);
            }
            $setupTotpSecret = (string)$_SESSION['pending_totp_secret'];
            $otpAuthUri = buildTotpOtpAuthUri($appTitle ?? APP_NAME, (string)$userRow['username'], $setupTotpSecret);
            $qrCodeSvg = renderQrCodeSvg($otpAuthUri, 168);
        } else {
            $setupTotpSecret = '';
            $otpAuthUri = '';
            $qrCodeSvg = '';
        }

        $tfaBadgeText = match ($userTfaMode) {
            2       => '📱 2FA Aktiv (Google Authenticator / TOTP)',
            1       => '✉️ 2FA Aktiv (E-Mail-Code)',
            default => '2FA Inaktiv',
        };
        ?>
        <section id="sec-2fa" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔐 Zwei-Faktor-Authentifizierung (2FA) &amp; E-Mail-Adresse</h2>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $userTfaMode > 0 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                        <?= e($tfaBadgeText) ?>
                    </span>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Hinterlegen Sie Ihre E-Mail-Adresse für die Passwort-Wiederherstellung und wählen Sie Ihre bevorzugte 2FA-Methode beim Anmelden: <strong>Per E-Mail-Code</strong> oder <strong>Per Authenticator-App (Google Authenticator, Microsoft Authenticator, Authy, Bitwarden)</strong>.
                </p>
            </div>

            <form action="settings.php" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_own_2fa">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="user_2fa_email" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Ihre E-Mail-Adresse (für Passwort-Reset &amp; E-Mail-2FA)
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

                    <div>
                        <label for="two_factor_mode" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Zwei-Faktor-Authentifizierung (2FA) beim Login
                        </label>
                        <select
                            id="two_factor_mode"
                            name="two_factor_mode"
                            class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="0" <?= $userTfaMode === 0 ? 'selected' : '' ?>>
                                🔓 Deaktiviert (Nur Benutzername &amp; Passwort)
                            </option>
                            <option value="1" <?= $userTfaMode === 1 ? 'selected' : '' ?>>
                                ✉️ Per E-Mail-Code (6-stelliger Code per E-Mail)
                            </option>
                            <option value="2" <?= $userTfaMode === 2 ? 'selected' : '' ?> <?= !$hasTotpConfigured ? 'disabled' : '' ?>>
                                📱 Per Authenticator-App (Google Authenticator / TOTP)<?= !$hasTotpConfigured ? ' – bitte unten zuerst einrichten' : '' ?>
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                    >
                        E-Mail &amp; 2FA-Methode speichern
                    </button>
                </div>
            </form>

            <!-- Bereich für Google Authenticator / TOTP Einrichtung & Status -->
            <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
                <?php if ($hasTotpConfigured): ?>
                    <div class="p-4 rounded-xl bg-emerald-50/60 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/70 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-lg shrink-0">
                                📱
                            </span>
                            <div>
                                <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                                    Authenticator-App (Google Authenticator / TOTP) ist eingerichtet
                                </p>
                                <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                                    <?= $userTfaMode === 2
                                        ? 'Beim Anmelden wird der 6-stellige Zeitcode aus Ihrer Authenticator-App abgefragt. Falls Sie eine E-Mail-Adresse hinterlegt haben, können Sie beim Login im Notfall auch jederzeit einen E-Mail-Code anfordern.'
                                        : 'Ihre Authenticator-App ist verknüpft. Sie können oben im Auswahlfeld jederzeit auf „Per Authenticator-App“ umschalten.' ?>
                                </p>
                            </div>
                        </div>

                        <form
                            action="settings.php"
                            method="post"
                            onsubmit="return confirm('Möchten Sie die aktuelle Authenticator-App-Verknüpfung wirklich zurücksetzen, um z.B. ein neues Smartphone einzurichten?');"
                            class="shrink-0"
                        >
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="reset_totp_secret">
                            <button
                                type="submit"
                                class="px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/50 text-red-600 dark:text-red-300 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                            >
                                🔄 Neu einrichten / Entfernen
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <details class="group rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 p-4">
                        <summary class="flex items-center justify-between gap-3 cursor-pointer list-none select-none">
                            <div class="flex items-center gap-2.5">
                                <span class="text-lg">📱</span>
                                <div>
                                    <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                                        Google Authenticator / TOTP-App jetzt einrichten (Alternative zu E-Mail-2FA)
                                    </p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                        Kompatibel mit Google Authenticator, Microsoft Authenticator, Authy, Bitwarden &amp; Apple Passwörter
                                    </p>
                                </div>
                            </div>
                            <span class="px-3 py-1 rounded-lg bg-brand-600 text-white text-xs font-semibold group-open:bg-slate-200 dark:group-open:bg-slate-700 group-open:text-slate-700 dark:group-open:text-slate-200 transition-colors">
                                QR-Code anzeigen
                            </span>
                        </summary>

                        <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700 grid grid-cols-1 md:grid-cols-12 gap-5 items-center">
                            <!-- Links: Lokaler SVG QR-Code -->
                            <div class="md:col-span-4 flex flex-col items-center text-center">
                                <?= $qrCodeSvg ?>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">
                                    100 % lokal auf Ihrem Server erzeugt (keine Datenweitergabe an Dritte)
                                </p>
                            </div>

                            <!-- Rechts: Manuelles Secret & Bestätigungs-Formular -->
                            <div class="md:col-span-8 space-y-3.5">
                                <div>
                                    <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">
                                        1. QR-Code scannen oder Schlüssel manuell eingeben:
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Öffnen Sie die App <strong>Google Authenticator</strong> auf Ihrem Smartphone, tippen Sie auf <strong>„+“ &rarr; „QR-Code scannen“</strong> oder geben Sie diesen Base32-Schlüssel manuell ein:
                                    </p>
                                    <div class="mt-2 p-2.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-xs font-bold tracking-wider text-brand-700 dark:text-brand-300 select-all break-all">
                                        <?= e(trim(chunk_split($setupTotpSecret, 4, ' '))) ?>
                                    </div>
                                </div>

                                <form action="settings.php" method="post" class="space-y-2.5">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="setup_totp_2fa">

                                    <label for="totp_verify_code" class="block text-xs font-semibold text-slate-800 dark:text-slate-200">
                                        2. 6-stelligen Code aus der App zur Bestätigung eingeben:
                                    </label>
                                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                                        <input
                                            type="text"
                                            id="totp_verify_code"
                                            name="totp_verify_code"
                                            inputmode="numeric"
                                            pattern="[0-9]{6}"
                                            maxlength="6"
                                            required
                                            autocomplete="one-time-code"
                                            placeholder="000000"
                                            class="w-full sm:w-44 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-center font-mono text-base tracking-[0.3em] text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                                        >
                                        <button
                                            type="submit"
                                            class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                                        >
                                            ✅ Code prüfen &amp; Google Authenticator aktivieren
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
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

        <!-- 3b-3. CHAT-BENACHRICHTIGUNGEN (PC & MOBILGERÄT) -->
        <?php
        $userChatNotifyMode = isset($userRow['chat_notify_mode']) ? max(0, min(3, (int)$userRow['chat_notify_mode'])) : 1;
        $chatNotifyModes = [
            1 => '🔔 Nur System-Benachrichtigung (Standard)',
            2 => '🔔 + 💬 System & In-App-Banner',
            3 => '💬 Nur In-App-Banner',
            0 => '🔕 Deaktiviert (Nur roter Zähler)',
        ];
        ?>
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">💬 Chat-Benachrichtigungen (PC &amp; Mobilgerät)</h2>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $userChatNotifyMode > 0 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' ?>">
                    <?= e($chatNotifyModes[$userChatNotifyMode] ?? $chatNotifyModes[1]) ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Legen Sie fest, wie Sie am Rechner und auf dem Smartphone über neue Chat-Nachrichten informiert werden möchten. Standardmäßig sind nur echte System-Benachrichtigungen (ohne In-App-Banner) aktiv.
            </p>

            <form action="settings.php" method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_chat_notify_mode">

                <?php foreach ($chatNotifyModes as $nMode => $nLabel): ?>
                    <?php $isSelectedNotify = ($userChatNotifyMode === (int)$nMode); ?>
                    <button
                        type="submit"
                        name="chat_notify_mode"
                        value="<?= (int)$nMode ?>"
                        class="flex items-center justify-between gap-2 px-4 py-3 rounded-xl border text-left text-xs sm:text-sm font-semibold transition-all cursor-pointer <?= $isSelectedNotify ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-800 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500' ?>"
                    >
                        <span class="truncate"><?= e($nLabel) ?></span>
                        <?php if ($isSelectedNotify): ?>
                            <span class="text-xs font-bold shrink-0">✓</span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </form>

            <div class="mt-3.5 pt-3 border-t border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                <p id="browserNotifyStatusText" class="text-xs text-slate-500 dark:text-slate-400">
                    Für System-Benachrichtigungen auf PC &amp; Mobilgerät muss Ihr Browser einmalig die Berechtigung erhalten.
                </p>
                <button
                    type="button"
                    id="btnRequestBrowserNotify"
                    class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer shrink-0 self-start sm:self-auto"
                >
                    🔔 System-Benachrichtigung auf diesem Gerät freigeben / testen
                </button>
            </div>
            <script>
            (() => {
                const btn = document.getElementById('btnRequestBrowserNotify');
                const statusEl = document.getElementById('browserNotifyStatusText');
                if (!btn || !statusEl) return;

                const updatePermStatus = () => {
                    if (!('Notification' in window)) {
                        statusEl.textContent = 'Hinweis: Dieser Browser unterstützt keine nativen System-Benachrichtigungen (auf iPhone/iPad bitte zuerst als App zum Home-Bildschirm hinzufügen).';
                        return;
                    }
                    if (Notification.permission === 'granted') {
                        statusEl.textContent = '✅ System-Benachrichtigungen sind auf diesem Gerät freigegeben.';
                        btn.textContent = '🔔 Test-Benachrichtigung senden';
                    } else if (Notification.permission === 'denied') {
                        statusEl.textContent = '⚠️ System-Benachrichtigungen wurden in den Browser-Einstellungen blockiert.';
                    }
                };
                updatePermStatus();

                btn.addEventListener('click', async () => {
                    if (!('Notification' in window)) {
                        alert('Dieser Browser unterstützt keine System-Benachrichtigungen. Auf iOS fügen Sie die Cloud bitte zuerst zum Home-Bildschirm hinzu.');
                        return;
                    }
                    let perm = Notification.permission;
                    if (perm !== 'granted') {
                        try {
                            perm = await Notification.requestPermission();
                        } catch (_) {}
                    }
                    updatePermStatus();
                    if (perm === 'granted') {
                        const title = '💬 Chat-Benachrichtigung aktiv';
                        const opts = {
                            body: 'Sie erhalten neue Chat-Nachrichten nun direkt auf diesem Gerät.',
                            icon: 'app-icon.php?size=180',
                            badge: 'app-icon.php?size=180',
                            data: { url: 'messages.php' }
                        };
                        if ('serviceWorker' in navigator) {
                            navigator.serviceWorker.ready.then((reg) => reg.showNotification(title, opts)).catch(() => new Notification(title, opts));
                        } else {
                            new Notification(title, opts);
                        }
                    }
                });
            })();
            </script>
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

        <?php if (($appSettings['enable_webdav'] ?? '1') === '1'):
            $webdavEndpointUrl = getBaseUrl() . '/webdav.php/';
            $davsEndpointUrl   = preg_replace('/^https:\/\//i', 'davs://', preg_replace('/^http:\/\//i', 'dav://', $webdavEndpointUrl));
        ?>
        <!-- WEBDAV NETZLAUFWERK -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">📡 WebDAV-Netzlaufwerk einbinden</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Binde deine persönlichen Cloud-Ordner direkt als Laufwerk im Windows Explorer, macOS Finder, unter Linux (Debian/Ubuntu) oder auf dem Smartphone ein.
                    </p>
                </div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300">
                    Aktiv
                </span>
            </div>

            <div class="space-y-2 mb-4">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">Standard-WebDAV-URL (Windows, macOS, davfs2, Smartphone):</label>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <input
                            type="text"
                            id="webdavUrlField"
                            readonly
                            value="<?= e($webdavEndpointUrl) ?>"
                            class="flex-1 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-mono text-slate-900 dark:text-white"
                        >
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('webdavUrlField').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='📋 HTTPS-URL kopieren', 2000);"
                            class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                        >
                            📋 HTTPS-URL kopieren
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-1">Linux Desktop-URL (Debian / Ubuntu – GNOME Dateien / Nautilus / Nemo / Thunar):</label>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <input
                            type="text"
                            id="webdavDavsField"
                            readonly
                            value="<?= e($davsEndpointUrl) ?>"
                            class="flex-1 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-mono text-slate-900 dark:text-white"
                        >
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('webdavDavsField').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='🐧 davs:// kopieren', 2000);"
                            class="px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                        >
                            🐧 davs:// kopieren
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-slate-600 dark:text-slate-300">
                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/70">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">🪟 Windows Explorer</div>
                    Rechtsklick auf <em>Dieser PC</em> &rarr; <em>Netzlaufwerk verbinden…</em> &rarr; HTTPS-URL einfügen und mit deinem Benutzernamen (<code><?= e((string)$userRow['username']) ?></code>) &amp; Passwort anmelden.
                </div>
                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/70">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">🍎 macOS Finder</div>
                    Im Finder <code>⌘ + K</code> (<em>Gehe zu &rarr; Mit Server verbinden…</em>) drücken, die HTTPS-URL eingeben und mit deinen Cloud-Zugangsdaten verbinden.
                </div>
                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/70">
                    <div class="font-bold text-slate-900 dark:text-white mb-1">🐧 Linux (Debian/Ubuntu)</div>
                    In GNOME/Nautilus/Nemo unter <em>„Andere Orte &rarr; Mit Server verbinden“</em> die <code>davs://</code>-Adresse einfügen (in KDE Dolphin: <code>webdavs://</code>, bei <code>mount.davfs</code>: <code>https://</code>).
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- SEPARATE APP-PASSWÖRTER FÜR WEBDAV, CARDDAV & CALDAV -->
        <section id="app-passwords" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔑 App-Passwörter für Geräte (WebDAV, CardDAV &amp; CalDAV)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Erzeuge eigene Geräte-Passwörter (z. B. für deinen Debian-PC, das iPhone-Adressbuch, den Kalender oder Thunderbird), damit du dein Hauptpasswort auf keinem Endgerät hinterlegen musst. Einzelne Geräte lassen sich jederzeit mit einem Klick widerrufen.
                    </p>
                </div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 shrink-0">
                    <?= count($userAppPasswords) ?> aktiv
                </span>
            </div>

            <?php if (!empty($newAppPasswordPlain)): ?>
                <div class="mb-4 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-700">
                    <div class="text-xs font-bold text-emerald-900 dark:text-emerald-200 mb-1">
                        ✅ Neues App-Passwort für „<?= e((string)$newAppPasswordLabel) ?>“ erstellt (wird aus Sicherheitsgründen nur jetzt einmalig im Klartext angezeigt!):
                    </div>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mt-2">
                        <input
                            type="text"
                            id="newAppPwField"
                            readonly
                            value="<?= e((string)$newAppPasswordPlain) ?>"
                            class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-emerald-400 dark:border-emerald-600 text-sm font-mono font-bold text-emerald-900 dark:text-emerald-200"
                        >
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('newAppPwField').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='📋 App-Passwort kopieren', 2000);"
                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs sm:text-sm shadow-xs cursor-pointer shrink-0"
                        >
                            📋 App-Passwort kopieren
                        </button>
                    </div>
                    <p class="text-[11px] text-emerald-800 dark:text-emerald-300 mt-2">
                        Verwende auf dem Gerät als Benutzernamen <code><?= e((string)$userRow['username']) ?></code> und füge als Passwort diesen Schlüssel ein.
                    </p>
                </div>
            <?php endif; ?>

            <form action="settings.php" method="post" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2.5 mb-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_app_password">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Geräte- oder App-Bezeichnung</label>
                    <input
                        type="text"
                        name="app_label"
                        required
                        maxlength="120"
                        placeholder="z. B. Debian-PC WebDAV, iPhone Kontakte & Kalender, Thunderbird Büro…"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>
                <button
                    type="submit"
                    class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
                >
                    ➕ App-Passwort erzeugen
                </button>
            </form>

            <?php if (!empty($userAppPasswords)): ?>
                <div class="divide-y divide-slate-200 dark:divide-slate-800 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden">
                    <?php foreach ($userAppPasswords as $ap): ?>
                        <div class="p-3 bg-slate-50/60 dark:bg-slate-800/40 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>🔑 <?= e((string)$ap['label']) ?></span>
                                    <code class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-[11px]"><?= e((string)$ap['token_prefix']) ?>-••••-••••-••••</code>
                                </div>
                                <div class="text-slate-500 dark:text-slate-400 mt-0.5">
                                    Erstellt am <?= e(formatDateTime((string)$ap['created_at'])) ?>
                                    <?php if (!empty($ap['last_used_at'])): ?>
                                        · Zuletzt genutzt: <strong><?= e(formatDateTime((string)$ap['last_used_at'])) ?></strong>
                                        <?php if (!empty($ap['last_used_service'])): ?>
                                            (<?= e((string)$ap['last_used_service']) ?>)
                                        <?php endif; ?>
                                    <?php else: ?>
                                        · <em>Noch nicht verwendet</em>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form action="settings.php" method="post" onsubmit="return confirm('Dieses App-Passwort wirklich widerrufen? Das zugehörige Gerät verliert sofort den Zugriff.');">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="revoke_app_password">
                                <input type="hidden" name="app_password_id" value="<?= (int)$ap['id'] ?>">
                                <button
                                    type="submit"
                                    class="px-3 py-1.5 rounded-lg bg-red-600/10 hover:bg-red-600 text-red-600 hover:text-white font-semibold text-xs transition-colors cursor-pointer"
                                >
                                    🗑️ Widerrufen
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 italic">
                    Noch keine separaten App-Passwörter angelegt.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
