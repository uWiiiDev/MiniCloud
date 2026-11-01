<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

redirectIfLoggedIn();

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['allow_registration'] ?? '0') !== '1') {
    setFlash('warning', 'Eine öffentliche Selbstregistrierung ist derzeit deaktiviert. Benutzerkonten werden durch den Administrator angelegt.');
    header('Location: login.php');
    exit;
}

$requiredInviteCode = trim((string)($appSettings['registration_code'] ?? ''));
$defaultQuotaMb     = max(1, min(102400, (int)($appSettings['registration_default_quota_mb'] ?? 100)));
$paywallEnabled     = ($appSettings['registration_paywall_enabled'] ?? '0') === '1';
$allowInviteBypass  = ($appSettings['paypal_allow_invite_bypass'] ?? '1') === '1';
$paidQuotaMb        = max(100, min(1024000, (int)($appSettings['paypal_paid_quota_mb'] ?? 1024)));
$paidQuotaLabel     = $paidQuotaMb >= 1024
    ? rtrim(rtrim(number_format($paidQuotaMb / 1024, 2, ',', '.'), '0'), ',') . ' GB'
    : $paidQuotaMb . ' MB';
$paypalPriceEur     = max(0.50, round((float)str_replace(',', '.', (string)($appSettings['paypal_price_eur'] ?? '1.00')), 2));
$paypalPriceLabel   = number_format($paypalPriceEur, 2, ',', '.') . ' €';
$paypalIntervalLbl  = strtoupper(trim((string)($appSettings['paypal_billing_interval'] ?? 'MONTH'))) === 'YEAR' ? 'Jahr' : 'Monat';

$errors             = [];
$usernameInput      = '';
$emailInput         = '';
$allowSharingInput  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken         = $_POST['csrf_token'] ?? null;
    $honeypot          = trim((string)($_POST['website_url'] ?? ''));
    $usernameInput     = trim((string)($_POST['username'] ?? ''));
    $emailInput        = trim((string)($_POST['email'] ?? ''));
    $password          = (string)($_POST['password'] ?? '');
    $confirmPassword   = (string)($_POST['confirm_password'] ?? '');
    $inviteCodeInput   = trim((string)($_POST['invite_code'] ?? ''));
    $allowSharingInput = isset($_POST['allow_user_sharing']) && $_POST['allow_user_sharing'] === '1';

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.';
    }

    // Honeypot gegen automatisierte Bots
    if ($honeypot !== '') {
        $errors[] = 'Die Registrierung konnte nicht verarbeitet werden.';
    }

    // Ratenbegrenzung: Maximal 5 Registrierungsversuche innerhalb von 10 Minuten
    $regWindow   = 600;
    $maxRegTries = 5;
    $regAttempts = isset($_SESSION['reg_attempts']) && is_array($_SESSION['reg_attempts'])
        ? array_values(array_filter($_SESSION['reg_attempts'], static fn($ts): bool => is_int($ts) && $ts > (time() - $regWindow)))
        : [];
    $_SESSION['reg_attempts'] = $regAttempts;

    if (count($regAttempts) >= $maxRegTries) {
        $waitSecs = max(1, ($regAttempts[0] + $regWindow) - time());
        $errors[] = sprintf('Zu viele Registrierungsversuche. Bitte warten Sie %d Sekunden.', $waitSecs);
    }

    // Prüfen, ob ein gültiger Einladungs-Code eingegeben wurde
    $validInviteBypass = false;
    if (!$paywallEnabled) {
        if ($requiredInviteCode !== '' && !hash_equals($requiredInviteCode, $inviteCodeInput)) {
            $_SESSION['reg_attempts'][] = time();
            $errors[] = 'Der eingegebene Einladungs-Code ist ungültig.';
        }
    } else {
        // Bei aktiver Bezahlschranke ist der Einladungs-Code ein optionaler Gratis-Gutschein
        if ($inviteCodeInput !== '') {
            if ($allowInviteBypass && $requiredInviteCode !== '' && hash_equals($requiredInviteCode, $inviteCodeInput)) {
                $validInviteBypass = true;
            } else {
                $_SESSION['reg_attempts'][] = time();
                $errors[] = 'Der eingegebene Einladungs-/Gutscheincode ist ungültig.';
            }
        }
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $usernameInput)) {
        $errors[] = 'Benutzername muss 3–32 Zeichen lang sein (Buchstaben, Zahlen, . _ -).';
    }

    if ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    }

    if (mb_strlen($password) < 8) {
        $errors[] = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Die eingegebenen Passwörter stimmen nicht überein.';
    }

    if (empty($errors)) {
        ensureUserSecurityColumns($pdo);
        $stmtCheck = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmtCheck->execute([':u' => $usernameInput]);
        if ($stmtCheck->fetch()) {
            $_SESSION['reg_attempts'][] = time();
            $errors[] = 'Dieser Benutzername ist bereits vergeben. Bitte wählen Sie einen anderen Namen.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $needsPaywallCheckout = ($paywallEnabled && !$validInviteBypass);

            $initialQuotaBytes = $needsPaywallCheckout
                ? ($paidQuotaMb * 1024 * 1024)
                : ($defaultQuotaMb * 1024 * 1024);

            $stmtIns = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, two_factor_enabled, is_admin, is_locked, lock_reason, theme, session_timeout, allow_user_sharing, storage_quota, subscription_status)
                 VALUES (:u, :em, :h, 0, 0, :locked, :reason, :theme, 30, :aus, :q, :sub_stat)'
            );
            $stmtIns->execute([
                ':u'        => $usernameInput,
                ':em'       => $emailInput !== '' ? $emailInput : null,
                ':h'        => $hash,
                ':locked'   => $needsPaywallCheckout ? 1 : 0,
                ':reason'   => $needsPaywallCheckout ? 'Ausstehende PayPal-Abo-Freischaltung' : '',
                ':theme'    => getCurrentTheme(),
                ':aus'      => $allowSharingInput ? 1 : 0,
                ':q'        => $initialQuotaBytes,
                ':sub_stat' => $needsPaywallCheckout ? 'pending' : ($validInviteBypass ? 'free_invite' : 'none'),
            ]);

            $newUserId = (int)$pdo->lastInsertId();
            unset($_SESSION['reg_attempts']);

            if ($needsPaywallCheckout) {
                $_SESSION['pending_sub_user_id'] = $newUserId;
                header('Location: subscription.php?checkout=1');
                exit;
            }

            session_regenerate_id(true);
            $_SESSION['user_id']         = $newUserId;
            $_SESSION['username']        = $usernameInput;
            $_SESSION['is_admin']        = false;
            $_SESSION['theme']           = getCurrentTheme();
            $_SESSION['session_timeout'] = 30;
            $_SESSION['last_activity']   = time();
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

            setFlash('success', sprintf('Willkommen, %s! Ihr Benutzerkonto (%d MB Speicherplatz) wurde erfolgreich erstellt.', $usernameInput, $defaultQuotaMb));
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Registrieren';
require_once __DIR__ . '/includes/header.php';
$headerTitle = $appSettings['app_title'] ?: APP_NAME;
?>

<div class="max-w-md mx-auto mt-4 sm:mt-8">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sm:p-8 shadow-sm">
        <div class="text-center mb-6">
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                Neues Konto erstellen
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                <?php if ($paywallEnabled): ?>
                    Registrieren Sie sich bei <?= e($headerTitle) ?> (<?= e($paidQuotaLabel) ?> Cloud-Speicher &amp; alle Module)
                <?php else: ?>
                    Registrieren Sie sich bei <?= e($headerTitle) ?> (<?= $defaultQuotaMb ?> MB persönlicher Cloud-Speicher)
                <?php endif; ?>
            </p>
        </div>

        <?php if ($paywallEnabled): ?>
            <div class="mb-5 p-4 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white border border-indigo-500/30 shadow-sm">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <span class="text-[10px] uppercase tracking-wider font-bold text-indigo-300">💳 Cloud-Mitgliedschaft (PayPal-Abo)</span>
                        <div class="text-sm font-extrabold mt-0.5"><?= e($paidQuotaLabel) ?> Speicherplatz &amp; alle Module</div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-lg font-black text-emerald-400"><?= e($paypalPriceLabel) ?></div>
                        <div class="text-[10px] text-indigo-200">pro <?= e($paypalIntervalLbl) ?></div>
                    </div>
                </div>
                <p class="text-[11px] text-indigo-200/90 mt-2">
                    Nach dem Anlegen Ihres Kontos werden Sie direkt zur sicheren PayPal-Freischaltung weitergeleitet. Jederzeit mit 1 Klick kündbar.
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-5 p-3.5 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-xs sm:text-sm text-red-700 dark:text-red-300 space-y-1">
                <?php foreach ($errors as $err): ?>
                    <p><?= e($err) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="post" class="space-y-4" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">

            <!-- Unsichtbares Honeypot-Feld gegen Bots -->
            <div class="hidden" aria-hidden="true">
                <label for="website_url">Website</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
            </div>

            <?php if ($requiredInviteCode !== '' && (!$paywallEnabled || $allowInviteBypass)): ?>
                <div>
                    <label for="invite_code" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        <?php if ($paywallEnabled): ?>
                            🔑 Gutschein- / Einladungs-Code (optional für kostenlose Freischaltung)
                        <?php else: ?>
                            🔑 Einladungs-Code
                        <?php endif; ?>
                    </label>
                    <input
                        type="password"
                        id="invite_code"
                        name="invite_code"
                        <?= !$paywallEnabled ? 'required' : '' ?>
                        placeholder="<?= $paywallEnabled ? 'Optional: Code für Gratis-Zugang' : 'Vom Administrator erhaltener Code' ?>"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>
            <?php endif; ?>

            <div>
                <label for="reg_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Benutzername
                </label>
                <input
                    type="text"
                    id="reg_username"
                    name="username"
                    value="<?= e($usernameInput) ?>"
                    required
                    minlength="3"
                    maxlength="32"
                    autocomplete="username"
                    placeholder="3–32 Zeichen (Buchstaben, Zahlen, . _ -)"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div>
                <label for="reg_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                    E-Mail-Adresse (optional, für 2FA)
                </label>
                <input
                    type="email"
                    id="reg_email"
                    name="email"
                    value="<?= e($emailInput) ?>"
                    maxlength="190"
                    autocomplete="email"
                    placeholder="name@beispiel.de"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div>
                <label for="reg_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Passwort (mind. 8 Zeichen)
                </label>
                <input
                    type="password"
                    id="reg_password"
                    name="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Mindestens 8 Zeichen"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div>
                <label for="reg_confirm_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Passwort bestätigen
                </label>
                <input
                    type="password"
                    id="reg_confirm_password"
                    name="confirm_password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Passwort wiederholen"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                    <input
                        type="checkbox"
                        name="allow_user_sharing"
                        value="1"
                        <?= $allowSharingInput ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    >
                    <span>
                        <strong>👁️ In der Teilen-Auswahl anderer Benutzer sichtbar sein</strong><br>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">Standardmäßig deaktiviert (Datenschutz). Sie können dies jederzeit in den Einstellungen ändern.</span>
                    </span>
                </label>
            </div>

            <button
                type="submit"
                class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
            >
                <?= $paywallEnabled ? ('Weiter zur PayPal-Freischaltung (' . e($paypalPriceLabel) . ' / ' . e($paypalIntervalLbl) . ') &rarr;') : 'Benutzerkonto erstellen' ?>
            </button>
        </form>

        <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-500 dark:text-slate-400">
            <span>Bereits registriert?</span>
            <a href="login.php" class="ml-1 font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                &larr; Zur Anmeldung
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
