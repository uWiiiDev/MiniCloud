<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/paypal_helper.php';

$pdo = getDB();
ensureUserSecurityColumns($pdo);
$appSettings = getAppSettings($pdo);

// =========================================================================
// 1. AUTOMATISCHER PAYPAL-WEBHOOK-EMPFÄNGER (subscription.php?webhook=1)
// =========================================================================
if (isset($_GET['webhook']) && $_GET['webhook'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = (string)file_get_contents('php://input');
    $event   = json_decode($rawBody, true);

    if (!is_array($event) || empty($event['event_type'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid webhook payload']);
        exit;
    }

    $eventType = strtoupper((string)$event['event_type']);
    $resource  = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : [];

    // Subscription-ID je nach Event-Typ auslesen
    $subId = trim((string)($resource['id'] ?? $resource['billing_agreement_id'] ?? ''));
    if ($subId !== '') {
        $stmtFind = $pdo->prepare('SELECT id, subscription_expires_at FROM users WHERE paypal_subscription_id = :sid LIMIT 1');
        $stmtFind->execute([':sid' => $subId]);
        $uRow = $stmtFind->fetch();

        if ($uRow) {
            $uid = (int)$uRow['id'];

            if (in_array($eventType, ['BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.RENEWED', 'PAYMENT.SALE.COMPLETED'], true)) {
                $err = null;
                $verified = verifyPayPalSubscription($appSettings, $subId, $err);
                $nextBill = $verified['next_billing_time'] ?? null;
                activateUserPayPalSubscription($pdo, $uid, $subId, $nextBill, $appSettings);
            } elseif ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                $stmtUpd = $pdo->prepare("UPDATE users SET subscription_status = 'cancelled' WHERE id = :id");
                $stmtUpd->execute([':id' => $uid]);
            } elseif (in_array($eventType, ['BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.SUSPENDED'], true)) {
                $stmtUpd = $pdo->prepare(
                    "UPDATE users
                     SET subscription_status = 'expired',
                         is_locked = 1,
                         lock_reason = 'PayPal-Abo abgelaufen oder pausiert'
                     WHERE id = :id AND is_admin = 0"
                );
                $stmtUpd->execute([':id' => $uid]);
            }
        }
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true]);
    exit;
}

// =========================================================================
// 2. ZIEL-BENUTZERKONTO ERMITTELN (EINGELOGGT ODER AUSSTEHENDE REGISTRIERUNG)
// =========================================================================
$targetUserId = 0;
if (isLoggedIn()) {
    $targetUserId = (int)$_SESSION['user_id'];
} elseif (!empty($_SESSION['pending_sub_user_id']) && is_int($_SESSION['pending_sub_user_id'])) {
    $targetUserId = (int)$_SESSION['pending_sub_user_id'];
}

if ($targetUserId <= 0) {
    header('Location: login.php');
    exit;
}

$stmtUser = $pdo->prepare(
    'SELECT id, username, email, is_admin, is_locked, lock_reason, storage_quota,
            subscription_status, paypal_subscription_id, subscription_expires_at
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$stmtUser->execute([':id' => $targetUserId]);
$userRow = $stmtUser->fetch();

if (!$userRow) {
    unset($_SESSION['pending_sub_user_id']);
    header('Location: register.php');
    exit;
}

$paypalMode     = strtolower(trim((string)($appSettings['paypal_mode'] ?? 'sandbox')));
$paypalClientId = trim((string)($appSettings['paypal_client_id'] ?? ''));
$paypalPlanId   = trim((string)($appSettings['paypal_plan_id'] ?? ''));
$priceEur       = max(0.50, round((float)str_replace(',', '.', (string)($appSettings['paypal_price_eur'] ?? '1.00')), 2));
$priceFormatted = number_format($priceEur, 2, ',', '.');
$interval       = strtoupper(trim((string)($appSettings['paypal_billing_interval'] ?? 'MONTH')));
$intervalLabel  = $interval === 'YEAR' ? 'Jahr' : 'Monat';
$paidQuotaMb    = max(100, (int)($appSettings['paypal_paid_quota_mb'] ?? 1024));
$paidQuotaLabel = $paidQuotaMb >= 1024
    ? rtrim(rtrim(number_format($paidQuotaMb / 1024, 2, ',', '.'), '0'), ',') . ' GB'
    : $paidQuotaMb . ' MB';

// Falls Client-ID & Secret vorhanden sind, aber noch keine Plan-ID erzeugt wurde, automatisch anlegen
if ($paypalPlanId === '' && $paypalClientId !== '' && trim((string)($appSettings['paypal_client_secret'] ?? '')) !== '') {
    $autoErr = null;
    $createdPlan = createOrSyncPayPalBillingPlan($pdo, $appSettings, $autoErr);
    if ($createdPlan !== null) {
        $paypalPlanId = $createdPlan;
        $appSettings  = getAppSettings($pdo, true);
    }
}

// =========================================================================
// 3. POST-AKTIONEN: ZAHLUNG BESTÄTIGEN ODER ABO KÜNDIGEN
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
        header('Location: subscription.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // --- 3a. PayPal-Subscription nach Abschluss im Popup/Button verifizieren & Konto freischalten ---
    if ($action === 'confirm_paypal_subscription') {
        $subscriptionId = trim((string)($_POST['subscription_id'] ?? ''));
        $isSandboxSim   = isset($_POST['sandbox_simulate']) && $_POST['sandbox_simulate'] === '1';

        if ($isSandboxSim && $paypalMode === 'sandbox') {
            $simSubId = 'I-SANDBOX-SIM-' . strtoupper(bin2hex(random_bytes(4)));
            $nextExp  = date('Y-m-d H:i:s', strtotime($interval === 'YEAR' ? '+1 year' : '+1 month'));
            activateUserPayPalSubscription($pdo, $targetUserId, $simSubId, $nextExp, $appSettings);
        } else {
            $verifyErr = null;
            $verified  = verifyPayPalSubscription($appSettings, $subscriptionId, $verifyErr);
            if ($verified === null || !$verified['ok']) {
                setFlash('error', 'Die PayPal-Zahlung konnte noch nicht bestätigt werden: ' . ($verifyErr ?: 'Status nicht aktiv.'));
                header('Location: subscription.php?checkout=1');
                exit;
            }
            activateUserPayPalSubscription(
                $pdo,
                $targetUserId,
                $verified['subscription_id'],
                $verified['next_billing_time'],
                $appSettings
            );
        }

        // Benutzer direkt einloggen
        unset($_SESSION['pending_sub_user_id']);
        session_regenerate_id(true);
        $_SESSION['user_id']         = (int)$userRow['id'];
        $_SESSION['username']        = (string)$userRow['username'];
        $_SESSION['is_admin']        = ((int)$userRow['is_admin'] === 1);
        $_SESSION['theme']           = getCurrentTheme();
        $_SESSION['session_timeout'] = 30;
        $_SESSION['last_activity']   = time();
        $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

        setFlash(
            'success',
            sprintf(
                '🎉 Vielen Dank! Ihr Cloud-Abo ist aktiv und Ihr Konto (%s Speicherplatz & alle Module) wurde sofort freigeschaltet.',
                $paidQuotaLabel
            )
        );
        header('Location: index.php');
        exit;
    }

    // --- 3b. Nachträgliche Einladungscode-Freischaltung auf der Checkout-Seite ---
    if ($action === 'redeem_invite_code') {
        $codeInput    = trim((string)($_POST['invite_code'] ?? ''));
        $expectedCode = trim((string)($appSettings['registration_code'] ?? ''));
        $allowBypass  = ($appSettings['paypal_allow_invite_bypass'] ?? '1') === '1';

        if ($allowBypass && $expectedCode !== '' && hash_equals($expectedCode, $codeInput)) {
            $freeQuotaMb    = max(1, (int)($appSettings['registration_default_quota_mb'] ?? 100));
            $freeQuotaBytes = $freeQuotaMb * 1024 * 1024;
            $stmtFree = $pdo->prepare(
                "UPDATE users
                 SET is_locked = 0,
                     lock_reason = '',
                     storage_quota = :q,
                     subscription_status = 'free_invite'
                 WHERE id = :uid"
            );
            $stmtFree->execute([':q' => $freeQuotaBytes, ':uid' => $targetUserId]);

            unset($_SESSION['pending_sub_user_id']);
            session_regenerate_id(true);
            $_SESSION['user_id']         = (int)$userRow['id'];
            $_SESSION['username']        = (string)$userRow['username'];
            $_SESSION['is_admin']        = ((int)$userRow['is_admin'] === 1);
            $_SESSION['theme']           = getCurrentTheme();
            $_SESSION['session_timeout'] = 30;
            $_SESSION['last_activity']   = time();
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));

            setFlash('success', sprintf('Einladungs-Code bestätigt! Ihr kostenloses Benutzerkonto (%d MB) wurde freigeschaltet.', $freeQuotaMb));
            header('Location: index.php');
            exit;
        }

        setFlash('error', 'Der eingegebene Einladungs-Code ist ungültig.');
        header('Location: subscription.php?checkout=1');
        exit;
    }

    // --- 3c. Laufendes Abo mit 1 Klick kündigen ---
    if ($action === 'cancel_my_subscription' && isLoggedIn()) {
        $subId = trim((string)($userRow['paypal_subscription_id'] ?? ''));
        if ($subId === '') {
            setFlash('warning', 'Für Ihr Konto ist kein aktives PayPal-Abo hinterlegt.');
            header('Location: subscription.php');
            exit;
        }

        $cancelErr = null;
        $ok = cancelPayPalSubscription($appSettings, $subId, 'Kündigung durch Benutzer über MiniCloud', $cancelErr);
        if (!$ok) {
            setFlash('error', 'Kündigung bei PayPal fehlgeschlagen: ' . ($cancelErr ?: 'Unbekannter Fehler.'));
            header('Location: subscription.php');
            exit;
        }

        $stmtCancel = $pdo->prepare("UPDATE users SET subscription_status = 'cancelled' WHERE id = :uid");
        $stmtCancel->execute([':uid' => $targetUserId]);

        setFlash('success', 'Ihr PayPal-Abonnement wurde erfolgreich gekündigt. Es erfolgen keine weiteren Abbuchungen.');
        header('Location: subscription.php');
        exit;
    }
}

$subStatus = (string)($userRow['subscription_status'] ?? 'none');
$subId     = (string)($userRow['paypal_subscription_id'] ?? '');
$subExp    = (string)($userRow['subscription_expires_at'] ?? '');
$isPending = ($subStatus === 'pending' || (int)($userRow['is_locked'] ?? 0) === 1);

$pageTitle = $isPending ? 'Cloud-Abo freischalten' : 'Mein Cloud-Abo';
require_once __DIR__ . '/includes/header.php';
$headerTitle = $appSettings['app_title'] ?: APP_NAME;
?>

<div class="max-w-xl mx-auto mt-4 sm:mt-8 mb-12">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sm:p-8 shadow-sm space-y-6">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 dark:border-slate-800 pb-5">
            <div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 mb-2">
                    💳 <?= e($headerTitle) ?> Cloud-Mitgliedschaft
                </span>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white">
                    <?= $isPending ? 'Konto mit PayPal freischalten' : 'Ihr Cloud-Abonnement' ?>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Benutzerkonto: <strong class="text-slate-800 dark:text-slate-200"><?= e((string)$userRow['username']) ?></strong>
                </p>
            </div>
            <?php if ($paypalMode === 'sandbox'): ?>
                <span class="px-2.5 py-1 rounded-xl text-[11px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 shrink-0">
                    🧪 Sandbox-Modus
                </span>
            <?php endif; ?>
        </div>

        <!-- Paket-Übersicht -->
        <div class="rounded-2xl bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-white p-5 border border-indigo-500/30 shadow-md">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-xs uppercase tracking-wider font-bold text-indigo-300">Komplettpaket inklusive</div>
                    <div class="text-lg sm:text-xl font-extrabold mt-0.5"><?= e($paidQuotaLabel) ?> Cloud-Speicher &amp; alle Module</div>
                </div>
                <div class="text-right">
                    <div class="text-2xl sm:text-3xl font-black text-emerald-400"><?= e($priceFormatted) ?> €</div>
                    <div class="text-[11px] text-indigo-200">pro <?= e($intervalLabel) ?> • jederzeit kündbar</div>
                </div>
            </div>

            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4 pt-4 border-t border-white/10 text-xs text-indigo-100">
                <li class="flex items-center gap-2"><span>✅</span> <span><strong><?= e($paidQuotaLabel) ?></strong> persönlicher Cloud-Speicher</span></li>
                <li class="flex items-center gap-2"><span>✅</span> <span>Alle Cloud-, Office- &amp; Media-Module</span></li>
                <li class="flex items-center gap-2"><span>✅</span> <span>Sofortige vollautomatische Freischaltung</span></li>
                <li class="flex items-center gap-2"><span>✅</span> <span>1-Klick-Kündigung jederzeit im Profil</span></li>
            </ul>
        </div>

        <?php if ($subStatus === 'active' && !$isPending): ?>
            <!-- AKTIVES ABO -->
            <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800/80 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-extrabold text-emerald-800 dark:text-emerald-300">🟢 Abonnement aktiv</span>
                    <?php if ($subId !== ''): ?>
                        <span class="text-[11px] font-mono text-emerald-700 dark:text-emerald-400">ID: <?= e($subId) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($subExp !== ''): ?>
                    <p class="text-xs text-emerald-700 dark:text-emerald-300">
                        Nächste automatische Abrechnung / gültig bis: <strong><?= e(date('d.m.Y H:i', strtotime($subExp))) ?> Uhr</strong>
                    </p>
                <?php endif; ?>
            </div>

            <form action="subscription.php" method="post" onsubmit="return confirm('Möchten Sie Ihr PayPal-Abonnement wirklich zum Ende des aktuellen Abrechnungszeitraums kündigen?');">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="cancel_my_subscription">
                <button type="submit"
                        class="w-full py-2.5 px-4 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer">
                    Vertrag / Abonnement hier mit 1 Klick kündigen
                </button>
            </form>

        <?php else: ?>
            <!-- CHECKOUT / BEZAHLSCHRANKE -->
            <?php if ($paypalClientId !== '' && $paypalPlanId !== ''): ?>
                <div class="space-y-3">
                    <p class="text-xs text-slate-600 dark:text-slate-300 text-center">
                        Schließen Sie Ihr Abonnement sicher über PayPal ab. Ihr Konto wird unmittelbar nach der Bestätigung freigeschaltet:
                    </p>
                    <div id="paypal-subscription-button-container" class="min-h-[48px]"></div>
                </div>

                <form id="paypalConfirmForm" action="subscription.php" method="post" class="hidden">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="confirm_paypal_subscription">
                    <input type="hidden" name="subscription_id" id="paypalSubscriptionIdInput" value="">
                </form>

                <script src="https://www.paypal.com/sdk/js?client-id=<?= rawurlencode($paypalClientId) ?>&amp;vault=true&amp;intent=subscription&amp;currency=EUR&amp;locale=de_DE"></script>
                <script>
                if (window.paypal && typeof window.paypal.Buttons === 'function') {
                    window.paypal.Buttons({
                        style: {
                            shape: 'rect',
                            color: 'gold',
                            layout: 'vertical',
                            label: 'subscribe'
                        },
                        createSubscription: function(data, actions) {
                            return actions.subscription.create({
                                plan_id: <?= json_encode($paypalPlanId) ?>,
                                custom_id: <?= json_encode('mc_user_' . (int)$userRow['id']) ?>
                            });
                        },
                        onApprove: function(data) {
                            const inp = document.getElementById('paypalSubscriptionIdInput');
                            const frm = document.getElementById('paypalConfirmForm');
                            if (inp && frm && data && data.subscriptionID) {
                                inp.value = data.subscriptionID;
                                frm.submit();
                            }
                        }
                    }).render('#paypal-subscription-button-container');
                }
                </script>
            <?php else: ?>
                <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-200">
                    ℹ️ Der Administrator hat die PayPal-Zugangsdaten (Client-ID / Plan-ID) noch nicht vollständig hinterlegt.
                </div>
            <?php endif; ?>

            <?php if ($paypalMode === 'sandbox'): ?>
                <!-- Sandbox-Schnelltest zum Ausprobieren des kompletten Ablaufs ohne echte Zahlung -->
                <div class="p-4 rounded-xl bg-amber-50/80 dark:bg-amber-950/40 border border-dashed border-amber-300 dark:border-amber-700 space-y-2.5">
                    <div class="text-xs font-bold text-amber-900 dark:text-amber-200 flex items-center gap-1.5">
                        <span>🧪</span>
                        <span>Sandbox-Testmodus aktiv</span>
                    </div>
                    <p class="text-[11px] text-amber-800 dark:text-amber-300">
                        Solange der Sandbox-Modus in den Admin-Einstellungen aktiv ist, können Sie die erfolgreiche PayPal-Rückmeldung und sofortige 1-GB-Freischaltung hier mit 1 Klick simulieren:
                    </p>
                    <form action="subscription.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="confirm_paypal_subscription">
                        <input type="hidden" name="sandbox_simulate" value="1">
                        <button type="submit"
                                class="w-full py-2.5 px-4 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer">
                            ⚡ Sandbox-Zahlung simulieren &amp; Konto sofort freischalten (<?= e($paidQuotaLabel) ?>)
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (($appSettings['paypal_allow_invite_bypass'] ?? '1') === '1' && trim((string)($appSettings['registration_code'] ?? '')) !== ''): ?>
                <div class="pt-4 border-t border-slate-100 dark:border-slate-800">
                    <form action="subscription.php" method="post" class="flex flex-col sm:flex-row gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="redeem_invite_code">
                        <input type="password" name="invite_code" required placeholder="🔑 Kostenloser Einladungs-Code vorhanden?"
                               class="flex-1 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <button type="submit"
                                class="px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100 text-xs font-bold cursor-pointer shrink-0">
                            Code einlösen
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <?php if (isLoggedIn()): ?>
                <a href="settings.php" class="font-semibold text-brand-600 dark:text-brand-400 hover:underline">&larr; Zurück zu den Einstellungen</a>
            <?php else: ?>
                <a href="login.php" class="font-semibold text-brand-600 dark:text-brand-400 hover:underline">&larr; Zur Anmeldung</a>
            <?php endif; ?>
            <span>Sichere Zahlung via PayPal</span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
