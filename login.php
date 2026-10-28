<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
if (defined('DB_CREDENTIALS_CONFIGURED') && !DB_CREDENTIALS_CONFIGURED) {
    header('Location: setup.php');
    exit;
}
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isset($_GET['timeout'])) {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Ihre Sitzung wurde nach Inaktivität automatisch beendet. Bitte melden Sie sich erneut an.');
    header('Location: login.php');
    exit;
}

redirectIfLoggedIn();

$errors = [];
$infoMessage = '';
$usernameInput = '';

// 2FA-Vorgang abbrechen und zur normalen Anmeldung zurückkehren
if (isset($_GET['cancel_2fa'])) {
    unset(
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_username'],
        $_SESSION['pending_2fa_mode'],
        $_SESSION['pending_2fa_email_masked'],
        $_SESSION['pending_2fa_last_sent']
    );
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $postAction = (string)($_POST['action'] ?? 'login');

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.';
    }

    // --- SCHRITT 2a: 2FA-Code überprüfen (Google Authenticator / TOTP oder E-Mail-Code) ---
    if (empty($errors) && $postAction === 'verify_2fa' && !empty($_SESSION['pending_2fa_user_id'])) {
        $pendingUserId = (int)$_SESSION['pending_2fa_user_id'];
        $pendingMode   = (string)($_SESSION['pending_2fa_mode'] ?? 'email');
        $codeInput     = preg_replace('/\s+/', '', trim((string)($_POST['two_factor_code'] ?? ''))) ?? '';

        if (!preg_match('/^\d{6}$/', $codeInput)) {
            $errors[] = 'Bitte geben Sie den 6-stelligen Zahlencode ein.';
        } else {
            $pdo = getDB();
            ensureUserSecurityColumns($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, username, email, is_admin, is_locked, lock_reason, admin_permissions, theme, two_factor_enabled, totp_secret,
                        two_factor_code_hash, two_factor_attempts,
                        (two_factor_expires_at IS NOT NULL AND two_factor_expires_at >= NOW()) AS code_not_expired
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $pendingUserId]);
            $user = $stmt->fetch();

            if (!$user || (int)($user['is_locked'] ?? 0) === 1) {
                unset(
                    $_SESSION['pending_2fa_user_id'],
                    $_SESSION['pending_2fa_username'],
                    $_SESSION['pending_2fa_mode'],
                    $_SESSION['pending_2fa_email_masked'],
                    $_SESSION['pending_2fa_last_sent']
                );
                if ($user && (int)($user['is_locked'] ?? 0) === 1) {
                    $reason = trim((string)($user['lock_reason'] ?? ''));
                    $errors[] = 'Dieses Benutzerkonto wurde vom Administrator gesperrt' . ($reason !== '' ? ' (Grund: ' . $reason . ')' : '') . '.';
                } else {
                    $errors[] = 'Die 2FA-Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';
                }
            } elseif ((int)$user['two_factor_attempts'] >= 5) {
                $errors[] = $pendingMode === 'totp'
                    ? 'Zu viele ungültige Code-Eingaben. Bitte melden Sie sich erneut an oder fordern Sie unten einen E-Mail-Code an.'
                    : 'Zu viele ungültige Code-Eingaben. Bitte fordern Sie unten einen neuen Sicherheitscode an.';
            } else {
                $totpSecret = trim((string)($user['totp_secret'] ?? ''));
                $totpMatched = ($totpSecret !== '' && verifyTotpCode($totpSecret, $codeInput));
                $emailMatched = (!empty($user['two_factor_code_hash']) && (int)$user['code_not_expired'] === 1 && password_verify($codeInput, (string)$user['two_factor_code_hash']));

                if ($totpMatched || $emailMatched) {
                    // Code korrekt -> 2FA-Felder zurücksetzen & Login abschließen
                    $stmtClear = $pdo->prepare(
                        'UPDATE users
                         SET two_factor_code_hash = NULL,
                             two_factor_expires_at = NULL,
                             two_factor_attempts = 0
                         WHERE id = :id'
                    );
                    $stmtClear->execute([':id' => (int)$user['id']]);

                    session_regenerate_id(true);
                    unset(
                        $_SESSION['login_attempts'],
                        $_SESSION['pending_2fa_user_id'],
                        $_SESSION['pending_2fa_username'],
                        $_SESSION['pending_2fa_mode'],
                        $_SESSION['pending_2fa_email_masked'],
                        $_SESSION['pending_2fa_last_sent']
                    );
                    $_SESSION['user_id']           = (int)$user['id'];
                    $_SESSION['username']          = (string)$user['username'];
                    $_SESSION['is_admin']          = ((int)$user['is_admin'] === 1);
                    $_SESSION['admin_permissions'] = parseAdminPermissionsString((string)($user['admin_permissions'] ?? ''));
                    $_SESSION['theme']             = (string)($user['theme'] ?: 'light');
                    $_SESSION['last_activity']     = time();
                    $_SESSION['csrf_token']        = bin2hex(random_bytes(32));

                    logAuditEvent(
                        $pdo,
                        'login_success',
                        sprintf('Erfolgreiche Anmeldung mit 2FA (%s)', $totpMatched ? 'TOTP / Authenticator' : 'E-Mail-Code'),
                        (int)$user['id'],
                        (string)$user['username']
                    );

                    setFlash('success', 'Willkommen zurück, ' . $user['username'] . '!');
                    header('Location: index.php');
                    exit;
                } elseif ($pendingMode === 'email' && !empty($user['two_factor_code_hash']) && (int)$user['code_not_expired'] !== 1) {
                    $errors[] = 'Der Sicherheitscode ist abgelaufen (10 Minuten Gültigkeit). Bitte fordern Sie unten einen neuen Code an.';
                } else {
                    $newAttempts = (int)$user['two_factor_attempts'] + 1;
                    $stmtFail = $pdo->prepare('UPDATE users SET two_factor_attempts = :att WHERE id = :id');
                    $stmtFail->execute([':att' => $newAttempts, ':id' => (int)$user['id']]);
                    logAuditEvent(
                        $pdo,
                        'login_failed',
                        sprintf('Ungültiger 2FA-Code eingegeben (Versuch %d/5)', $newAttempts),
                        (int)$user['id'],
                        (string)$user['username']
                    );
                    $remaining = max(0, 5 - $newAttempts);
                    if ($remaining > 0) {
                        $errors[] = sprintf('Ungültiger Sicherheitscode. Noch %d %s übrig.', $remaining, $remaining === 1 ? 'Versuch' : 'Versuche');
                    } else {
                        $errors[] = '2FA-Prüfung nach 5 Fehlversuchen gesperrt. Bitte melden Sie sich erneut an.';
                    }
                }
            }
        }
    }

    // --- SCHRITT 2b: Neuen 2FA-Code per E-Mail anfordern (auch als Fallback bei TOTP) ---
    elseif (empty($errors) && $postAction === 'resend_2fa' && !empty($_SESSION['pending_2fa_user_id'])) {
        $pendingUserId = (int)$_SESSION['pending_2fa_user_id'];
        $lastSent = (int)($_SESSION['pending_2fa_last_sent'] ?? 0);
        $waitResend = ($lastSent + 45) - time();

        if ($waitResend > 0) {
            $errors[] = sprintf('Bitte warten Sie noch %d Sekunden, bevor Sie einen neuen Code anfordern.', $waitResend);
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $pendingUserId]);
            $user = $stmt->fetch();

            if ($user && filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) !== false) {
                $newCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $codeHash = password_hash($newCode, PASSWORD_BCRYPT, ['cost' => 10]);

                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET two_factor_code_hash = :h,
                         two_factor_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                         two_factor_attempts = 0
                     WHERE id = :id'
                );
                $stmtUpd->execute([':h' => $codeHash, ':id' => (int)$user['id']]);

                if (sendTwoFactorEmail($pdo, (string)$user['email'], (string)$user['username'], $newCode)) {
                    $_SESSION['pending_2fa_mode'] = 'email';
                    $_SESSION['pending_2fa_email_masked'] = maskEmailAddress((string)$user['email']);
                    $_SESSION['pending_2fa_last_sent'] = time();
                    $infoMessage = 'Ein 6-stelliger Sicherheitscode wurde an ' . $_SESSION['pending_2fa_email_masked'] . ' gesendet.';
                } else {
                    $errors[] = 'E-Mail konnte vom Server nicht versendet werden. Bitte prüfen Sie die Server-Mailkonfiguration.';
                }
            } else {
                $errors[] = 'Keine gültige E-Mail-Adresse für dieses Benutzerkonto hinterlegt.';
            }
        }
    }

    // --- SCHRITT 1: Reguläre Anmeldung (Benutzername + Passwort) ---
    elseif (empty($errors) && $postAction === 'login') {
        $usernameInput = trim((string)($_POST['username'] ?? ''));
        $password      = (string)($_POST['password'] ?? '');

        // Brute-Force-Schutz: Maximal 5 Fehlversuche innerhalb von 5 Minuten
        $lockoutWindow = 300;
        $maxAttempts   = 5;
        $failedLogins  = isset($_SESSION['login_attempts']) && is_array($_SESSION['login_attempts'])
            ? array_values(array_filter($_SESSION['login_attempts'], static fn($ts): bool => is_int($ts) && $ts > (time() - $lockoutWindow)))
            : [];
        $_SESSION['login_attempts'] = $failedLogins;

        if (count($failedLogins) >= $maxAttempts) {
            $waitSecs = max(1, ($failedLogins[0] + $lockoutWindow) - time());
            $errors[] = sprintf('Zu viele fehlgeschlagene Anmeldeversuche. Bitte warten Sie %d Sekunden.', $waitSecs);
        }

        if ($usernameInput === '' || $password === '') {
            $errors[] = 'Bitte geben Sie Ihren Benutzernamen und Ihr Passwort ein.';
        }

        if (empty($errors)) {
            $pdo = getDB();
            ensureUserSecurityColumns($pdo);
            $stmt = $pdo->prepare(
                'SELECT id, username, email, password_hash, two_factor_enabled, totp_secret, is_admin, is_locked, lock_reason, admin_permissions, theme
                 FROM users
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $usernameInput]);
            $user = $stmt->fetch();

            // Dummy-BCRYPT-Hash gegen Timing-Attacken bei nicht existierendem Benutzernamen
            $dummyHash = '$2y$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW';
            $hashToVerify = $user ? (string)$user['password_hash'] : $dummyHash;

            if ($user && password_verify($password, $hashToVerify)) {
                if ((int)($user['is_locked'] ?? 0) === 1) {
                    $reason = trim((string)($user['lock_reason'] ?? ''));
                    logAuditEvent(
                        $pdo,
                        'login_failed',
                        'Anmeldeversuch für gesperrtes Benutzerkonto blockiert',
                        (int)$user['id'],
                        (string)$user['username']
                    );
                    $errors[] = 'Ihr Benutzerkonto wurde vom Administrator gesperrt' . ($reason !== '' ? ' (Grund: ' . $reason . ')' : '') . '.';
                } else {
                    unset($_SESSION['login_attempts']);
                    $userEmail  = trim((string)($user['email'] ?? ''));
                    $totpSecret = trim((string)($user['totp_secret'] ?? ''));
                    $tfaMode    = (int)($user['two_factor_enabled'] ?? 0);
                    $hasValidEmail = (filter_var($userEmail, FILTER_VALIDATE_EMAIL) !== false);

                    if ($tfaMode === 2 && $totpSecret !== '') {
                        // 2FA per Authenticator-App (Google Authenticator / TOTP)
                        $pdo->prepare('UPDATE users SET two_factor_attempts = 0 WHERE id = :id')
                            ->execute([':id' => (int)$user['id']]);

                        $_SESSION['pending_2fa_user_id']      = (int)$user['id'];
                        $_SESSION['pending_2fa_username']     = (string)$user['username'];
                        $_SESSION['pending_2fa_mode']         = 'totp';
                        $_SESSION['pending_2fa_email_masked'] = $hasValidEmail ? maskEmailAddress($userEmail) : '';
                        $_SESSION['pending_2fa_last_sent']    = 0;
                    } elseif ($tfaMode === 1 && $hasValidEmail) {
                        // 6-stelligen E-Mail-Code erzeugen und versenden
                        $otpCode  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $codeHash = password_hash($otpCode, PASSWORD_BCRYPT, ['cost' => 10]);

                        $stmtOtp = $pdo->prepare(
                            'UPDATE users
                             SET two_factor_code_hash = :h,
                                 two_factor_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                                 two_factor_attempts = 0
                             WHERE id = :id'
                        );
                        $stmtOtp->execute([
                            ':h'  => $codeHash,
                            ':id' => (int)$user['id'],
                        ]);

                        if (sendTwoFactorEmail($pdo, $userEmail, (string)$user['username'], $otpCode)) {
                            $_SESSION['pending_2fa_user_id']      = (int)$user['id'];
                            $_SESSION['pending_2fa_username']     = (string)$user['username'];
                            $_SESSION['pending_2fa_mode']         = 'email';
                            $_SESSION['pending_2fa_email_masked'] = maskEmailAddress($userEmail);
                            $_SESSION['pending_2fa_last_sent']    = time();
                            $infoMessage = 'Ein 6-stelliger Bestätigungscode wurde an ' . $_SESSION['pending_2fa_email_masked'] . ' gesendet.';
                        } else {
                            $errors[] = 'Der 2FA-Sicherheitscode konnte nicht per E-Mail gesendet werden. Bitte kontaktieren Sie den Administrator.';
                        }
                    } else {
                        // Direkter Login ohne 2FA
                        session_regenerate_id(true);
                        $_SESSION['user_id']           = (int)$user['id'];
                        $_SESSION['username']          = (string)$user['username'];
                        $_SESSION['is_admin']          = ((int)$user['is_admin'] === 1);
                        $_SESSION['admin_permissions'] = parseAdminPermissionsString((string)($user['admin_permissions'] ?? ''));
                        $_SESSION['theme']             = (string)($user['theme'] ?: 'light');
                        $_SESSION['csrf_token']        = bin2hex(random_bytes(32));

                        logAuditEvent(
                            $pdo,
                            'login_success',
                            'Erfolgreiche Anmeldung (Benutzername & Passwort)',
                            (int)$user['id'],
                            (string)$user['username']
                        );

                        setFlash('success', 'Willkommen zurück, ' . $user['username'] . '!');
                        header('Location: index.php');
                        exit;
                    }
                }
            } else {
                $_SESSION['login_attempts'][] = time();
                logAuditEvent(
                    $pdo,
                    'login_failed',
                    sprintf('Fehlgeschlagener Anmeldeversuch für Benutzername „%s“', mb_substr($usernameInput, 0, 50)),
                    $user ? (int)$user['id'] : null,
                    $user ? (string)$user['username'] : mb_substr($usernameInput, 0, 50)
                );
                $errors[] = 'Ungültiger Benutzername oder falsches Passwort.';
            }
        }
    }
}

$isTwoFactorStep = !empty($_SESSION['pending_2fa_user_id']);
$pending2faMode  = (string)($_SESSION['pending_2fa_mode'] ?? 'email');
$pageTitle = $isTwoFactorStep ? '2FA-Bestätigung' : 'Anmelden';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto mt-6 sm:mt-12 px-1">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8">
        <div class="text-center mb-6">
            <?php if (!empty($appLogoUri)): ?>
                <img
                    src="<?= e($appLogoUri) ?>"
                    alt="<?= e($appTitle ?? APP_NAME) ?>"
                    class="w-16 h-16 mx-auto mb-3 rounded-2xl object-contain bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-1.5 shadow-xs"
                >
            <?php endif; ?>
            <?php if ($isTwoFactorStep): ?>
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 dark:bg-brand-950/70 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 text-xs font-semibold mb-2">
                    <span><?= $pending2faMode === 'totp' ? '📱 Google Authenticator / TOTP' : '🔒 Zwei-Faktor-Authentifizierung' ?></span>
                </div>
                <?php if ($pending2faMode === 'totp'): ?>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Authenticator-Code eingeben</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Bitte geben Sie den aktuellen 6-stelligen Code aus Ihrer <strong>Authenticator-App</strong> ein.
                    </p>
                <?php else: ?>
                    <h1 class="text-2xl font-bold text-slate-900 dark:text-white">E-Mail-Code eingeben</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Wir haben einen 6-stelligen Code an <strong class="text-slate-800 dark:text-slate-200"><?= e((string)($_SESSION['pending_2fa_email_masked'] ?? '')) ?></strong> gesendet.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Anmelden bei <?= e($appTitle ?? APP_NAME) ?></h1>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Greifen Sie sicher auf Ihre persönlichen Cloud-Dateien und Gruppenordner zu.</p>
            <?php endif; ?>
        </div>

        <?php if ($infoMessage !== ''): ?>
            <div class="mb-5 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm">
                <?= e($infoMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-5 p-4 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm space-y-1">
                <?php foreach ($errors as $err): ?>
                    <p class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span>
                        <span><?= e($err) ?></span>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isTwoFactorStep): ?>
            <form action="login.php" method="post" class="space-y-4" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="verify_2fa">

                <div>
                    <label for="two_factor_code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        6-stelliger Sicherheitscode
                    </label>
                    <input
                        type="text"
                        id="two_factor_code"
                        name="two_factor_code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        autofocus
                        autocomplete="one-time-code"
                        placeholder="000000"
                        class="w-full px-4 py-3 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-center text-xl font-mono tracking-[0.35em] focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                    >
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 text-center">
                        <?= $pending2faMode === 'totp' ? '30-Sekunden-Zeitcode (TOTP)' : 'Gültig für 10 Minuten' ?> &bull; Konto: <strong><?= e((string)($_SESSION['pending_2fa_username'] ?? '')) ?></strong>
                    </p>
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Code bestätigen &amp; Anmelden
                </button>
            </form>

            <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2 text-xs">
                <?php if (!empty($_SESSION['pending_2fa_email_masked'])): ?>
                    <form action="login.php" method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="resend_2fa">
                        <button type="submit" class="text-brand-600 dark:text-brand-400 hover:underline font-medium cursor-pointer">
                            <?= $pending2faMode === 'totp' ? '✉️ Stattdessen Code per E-Mail senden' : 'Neuen Code per E-Mail senden' ?>
                        </button>
                    </form>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>

                <a href="login.php?cancel_2fa=1" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">
                    &larr; Andere Anmeldung
                </a>
            </div>
        <?php else: ?>
            <form action="login.php" method="post" class="space-y-4" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="login">

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Benutzername</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        value="<?= e($usernameInput) ?>"
                        required
                        autocomplete="username"
                        placeholder="Ihr Benutzername"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                    >
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Passwort</label>
                        <a href="forgot_password.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                            Passwort vergessen?
                        </a>
                    </div>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="Ihr Passwort"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Anmelden
                </button>
            </form>
        <?php endif; ?>

        <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-500 dark:text-slate-400">
            <?php if (($appSettings['allow_registration'] ?? '0') === '1'): ?>
                <span>Noch kein Benutzerkonto?</span>
                <a href="register.php" class="ml-1 font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                    Jetzt registrieren &rarr;
                </a>
            <?php else: ?>
                Geschlossenes Cloud-System &bull; Benutzerkonten werden durch den Administrator angelegt.
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
