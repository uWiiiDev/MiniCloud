<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

redirectIfLoggedIn();

$pdo = getDB();
$appSettings = getAppSettings($pdo);

$errors      = [];
$infoMessage = '';
$identifier  = '';

// Prüfen, ob ein Wiederherstellungs-Link (?uid=...&token=...) aufgerufen wurde
$uidParam   = isset($_GET['uid']) ? (int)$_GET['uid'] : (isset($_POST['uid']) ? (int)$_POST['uid'] : 0);
$tokenParam = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));

$isResetForm = false;
$targetUser  = null;

if ($uidParam > 0 && $tokenParam !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $tokenParam)) {
        $errors[] = 'Der aufgerufene Link zum Zurücksetzen des Passworts ist ungültig.';
    } else {
        $stmtToken = $pdo->prepare(
            'SELECT id, username, reset_token_hash,
                    (reset_expires_at IS NOT NULL AND reset_expires_at >= NOW()) AS token_valid
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmtToken->execute([':id' => $uidParam]);
        $uRow = $stmtToken->fetch();

        $expectedHash = hash('sha256', $tokenParam);
        if (
            $uRow &&
            !empty($uRow['reset_token_hash']) &&
            (int)$uRow['token_valid'] === 1 &&
            hash_equals((string)$uRow['reset_token_hash'], $expectedHash)
        ) {
            $isResetForm = true;
            $targetUser  = $uRow;
        } else {
            $errors[] = 'Dieser Wiederherstellungs-Link ist abgelaufen (30 Minuten Gültigkeit) oder wurde bereits verwendet. Bitte fordern Sie unten einen neuen Link an.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken  = $_POST['csrf_token'] ?? null;
    $postAction = (string)($_POST['action'] ?? 'request_reset');

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        $errors[] = 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.';
    }

    // --- SCHRITT 2: Neues Passwort speichern (über gültigen Token-Link) ---
    if (empty($errors) && $postAction === 'set_new_password') {
        if (!$isResetForm || !$targetUser) {
            $errors[] = 'Ihre Wiederherstellungs-Sitzung ist abgelaufen. Bitte fordern Sie einen neuen Link an.';
        } else {
            $newPassword     = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (mb_strlen($newPassword) < 8) {
                $errors[] = 'Das neue Passwort muss mindestens 8 Zeichen lang sein.';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'Die eingegebenen Passwörter stimmen nicht überein.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET password_hash = :h,
                         reset_token_hash = NULL,
                         reset_expires_at = NULL,
                         two_factor_code_hash = NULL,
                         two_factor_expires_at = NULL,
                         two_factor_attempts = 0
                     WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':h'  => $newHash,
                    ':id' => (int)$targetUser['id'],
                ]);

                unset($_SESSION['login_attempts'], $_SESSION['pw_reset_attempts']);
                setFlash('success', 'Ihr Passwort wurde erfolgreich zurückgesetzt. Sie können sich jetzt mit Ihrem neuen Passwort anmelden.');
                header('Location: login.php');
                exit;
            }
        }
    }

    // --- SCHRITT 1: Wiederherstellungs-Link per E-Mail anfordern ---
    elseif (empty($errors) && $postAction === 'request_reset') {
        $honeypot   = trim((string)($_POST['website_url'] ?? ''));
        $identifier = trim((string)($_POST['identifier'] ?? ''));

        if ($honeypot !== '') {
            $errors[] = 'Die Anfrage konnte nicht verarbeitet werden.';
        }

        // Ratenbegrenzung: Maximal 5 Anfragen innerhalb von 10 Minuten
        $resetWindow = 600;
        $maxTries    = 5;
        $attempts    = isset($_SESSION['pw_reset_attempts']) && is_array($_SESSION['pw_reset_attempts'])
            ? array_values(array_filter($_SESSION['pw_reset_attempts'], static fn($ts): bool => is_int($ts) && $ts > (time() - $resetWindow)))
            : [];
        $_SESSION['pw_reset_attempts'] = $attempts;

        if (count($attempts) >= $maxTries) {
            $waitSecs = max(1, ($attempts[0] + $resetWindow) - time());
            $errors[] = sprintf('Zu viele Anfragen. Bitte warten Sie %d Sekunden.', $waitSecs);
        }

        if ($identifier === '') {
            $errors[] = 'Bitte geben Sie Ihren Benutzernamen oder Ihre hinterlegte E-Mail-Adresse ein.';
        }

        if (empty($errors)) {
            $_SESSION['pw_reset_attempts'][] = time();

            $stmtFind = $pdo->prepare(
                'SELECT id, username, email
                 FROM users
                 WHERE username = :u
                    OR (email IS NOT NULL AND email <> \'\' AND LOWER(email) = LOWER(:em))
                 ORDER BY (username = :u_exact) DESC, id ASC
                 LIMIT 1'
            );
            $stmtFind->execute([
                ':u'       => $identifier,
                ':em'      => $identifier,
                ':u_exact' => $identifier,
            ]);
            $foundUser = $stmtFind->fetch();

            if ($foundUser && filter_var(trim((string)($foundUser['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false) {
                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);

                $stmtSaveToken = $pdo->prepare(
                    'UPDATE users
                     SET reset_token_hash = :th,
                         reset_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                     WHERE id = :id'
                );
                $stmtSaveToken->execute([
                    ':th' => $tokenHash,
                    ':id' => (int)$foundUser['id'],
                ]);

                $resetUrl = getBaseUrl() . '/forgot_password.php?uid=' . (int)$foundUser['id'] . '&token=' . urlencode($rawToken);
                sendPasswordResetEmail($pdo, trim((string)$foundUser['email']), (string)$foundUser['username'], $resetUrl);
            }

            // Einheitliche Bestätigungsmeldung zum Schutz vor Benutzer-/E-Mail-Enumeration
            $infoMessage = 'Falls ein Benutzerkonto mit dieser Angabe existiert und eine gültige E-Mail-Adresse hinterlegt ist, wurde soeben ein Link zum Zurücksetzen des Passworts gesendet (gültig für 30 Minuten).';
            $identifier  = '';
        }
    }
}

$pageTitle = 'Passwort zurücksetzen';
require_once __DIR__ . '/includes/header.php';
$headerTitle = $appSettings['app_title'] ?: APP_NAME;
?>

<div class="max-w-md mx-auto mt-6 sm:mt-12 px-1">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-8">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-brand-50 dark:bg-brand-950/70 text-brand-600 dark:text-brand-400 text-xl mb-3 border border-brand-200/70 dark:border-brand-800/70">
                🔑
            </div>
            <?php if ($isResetForm && $targetUser): ?>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                    Neues Passwort festlegen
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Vergeben Sie ein neues Passwort für Ihr Konto <strong class="text-slate-800 dark:text-slate-200"><?= e((string)$targetUser['username']) ?></strong>.
                </p>
            <?php else: ?>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                    Passwort vergessen?
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Geben Sie Ihren Benutzernamen oder Ihre hinterlegte E-Mail-Adresse ein, um einen Wiederherstellungs-Link für <?= e($headerTitle) ?> zu erhalten.
                </p>
            <?php endif; ?>
        </div>

        <?php if ($infoMessage !== ''): ?>
            <div class="mb-5 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-xs sm:text-sm leading-relaxed">
                <?= e($infoMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-5 p-4 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-xs sm:text-sm space-y-1">
                <?php foreach ($errors as $err): ?>
                    <p class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span>
                        <span><?= e($err) ?></span>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isResetForm && $targetUser): ?>
            <!-- Formular zum Setzen des neuen Passworts -->
            <form action="forgot_password.php" method="post" class="space-y-4" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="set_new_password">
                <input type="hidden" name="uid" value="<?= (int)$targetUser['id'] ?>">
                <input type="hidden" name="token" value="<?= e($tokenParam) ?>">

                <div>
                    <label for="new_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Neues Passwort (mind. 8 Zeichen)
                    </label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        required
                        minlength="8"
                        autofocus
                        autocomplete="new-password"
                        placeholder="Mindestens 8 Zeichen"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Neues Passwort bestätigen
                    </label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Passwort wiederholen"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Neues Passwort speichern
                </button>
            </form>
        <?php else: ?>
            <!-- Formular zum Anfordern des Wiederherstellungs-Links -->
            <form action="forgot_password.php" method="post" class="space-y-4" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="request_reset">

                <!-- Unsichtbares Honeypot-Feld gegen Bots -->
                <div class="hidden" aria-hidden="true">
                    <label for="website_url">Website</label>
                    <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
                </div>

                <div>
                    <label for="identifier" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Benutzername oder E-Mail-Adresse
                    </label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= e($identifier) ?>"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="z. B. max oder max@beispiel.de"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Link zum Zurücksetzen senden
                </button>
            </form>

            <div class="mt-4 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                <strong>Hinweis:</strong> Der Versand setzt voraus, dass für Ihr Benutzerkonto eine E-Mail-Adresse hinterlegt wurde. Falls Sie keine E-Mail-Adresse hinterlegt haben, kann der Administrator Ihr Passwort jederzeit in der Benutzerverwaltung zurücksetzen.
            </div>
        <?php endif; ?>

        <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800 text-center text-xs text-slate-500 dark:text-slate-400">
            <a href="login.php" class="font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                &larr; Zurück zur Anmeldung
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
