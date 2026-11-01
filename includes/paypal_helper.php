<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * MiniCloud – PayPal Subscriptions & Bezahlschranken-Helfer
 * Unterstützt Sandbox- und Live-Betrieb über die offizielle PayPal REST API v1.
 */

/**
 * Liefert die PayPal REST API Basis-URL abhängig vom gewählten Modus ('sandbox' oder 'live').
 *
 * @param array<string, mixed> $settings
 */
function getPayPalApiBaseUrl(array $settings): string
{
    $mode = strtolower(trim((string)($settings['paypal_mode'] ?? 'sandbox')));
    return $mode === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

/**
 * Holt ein OAuth2 Access-Token von der PayPal REST API anhand von Client-ID und Secret.
 *
 * @param array<string, mixed> $settings
 */
function getPayPalAccessToken(array $settings, ?string &$errorOut = null): ?string
{
    $clientId     = trim((string)($settings['paypal_client_id'] ?? ''));
    $clientSecret = trim((string)($settings['paypal_client_secret'] ?? ''));

    if ($clientId === '' || $clientSecret === '') {
        $errorOut = 'PayPal Client-ID oder Secret-Key ist noch nicht in den Admin-Einstellungen hinterlegt.';
        return null;
    }

    $url = getPayPalApiBaseUrl($settings) . '/v1/oauth2/token';
    $ch  = curl_init($url);
    if ($ch === false) {
        $errorOut = 'cURL konnte nicht initialisiert werden.';
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Language: de_DE',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        $errorOut = $err !== '' ? $err : 'Keine Antwort von der PayPal-OAuth-Schnittstelle erhalten.';
        return null;
    }

    $json = json_decode($raw, true);
    if ($code >= 200 && $code < 300 && is_array($json) && !empty($json['access_token'])) {
        return (string)$json['access_token'];
    }

    $desc = is_array($json) ? (string)($json['error_description'] ?? $json['message'] ?? '') : '';
    $errorOut = sprintf('PayPal-Authentifizierung fehlgeschlagen (HTTP %d)%s', $code, $desc !== '' ? ': ' . $desc : '.');
    return null;
}

/**
 * Führt einen authentifizierten JSON-Request gegen die PayPal REST API aus.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed>|null $payload
 * @return array<string, mixed>|null
 */
function paypalApiRequest(
    array $settings,
    string $method,
    string $endpoint,
    ?array $payload = null,
    ?int &$httpCodeOut = null,
    ?string &$errorOut = null
): ?array {
    $token = getPayPalAccessToken($settings, $errorOut);
    if ($token === null) {
        return null;
    }

    $url = rtrim(getPayPalApiBaseUrl($settings), '/') . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    if ($ch === false) {
        $errorOut = 'cURL konnte nicht initialisiert werden.';
        return null;
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = empty($payload) ? '{}' : (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $httpCodeOut = $code;

    if ($raw === false) {
        $errorOut = $err !== '' ? $err : 'Verbindungsfehler zur PayPal API.';
        return null;
    }

    if ($code === 204 && trim((string)$raw) === '') {
        return ['ok' => true, 'http_code' => 204];
    }

    $decoded = json_decode((string)$raw, true);
    if ($code >= 200 && $code < 300 && is_array($decoded)) {
        return $decoded;
    }

    $msg = is_array($decoded)
        ? (string)($decoded['message'] ?? $decoded['error_description'] ?? '')
        : '';
    $errorOut = sprintf('PayPal-API-Fehler (HTTP %d)%s', $code, $msg !== '' ? ': ' . $msg : '.');
    return null;
}

/**
 * Erstellt automatisch ein PayPal-Produkt und einen passenden Abo-Zahlungsplan (Billing Plan)
 * anhand des in MiniCloud eingestellten Preises, Intervalls und Speicherplatzes und speichert
 * die erzeugte Plan-ID in der Datenbank.
 *
 * @param array<string, mixed> $settings
 */
function createOrSyncPayPalBillingPlan(PDO $pdo, array $settings, ?string &$errorOut = null): ?string
{
    $appTitle  = trim((string)($settings['app_title'] ?? 'MiniCloud')) ?: 'MiniCloud';
    $priceRaw  = str_replace(',', '.', trim((string)($settings['paypal_price_eur'] ?? '1.00')));
    $priceVal  = max(0.50, round((float)$priceRaw, 2));
    $priceStr  = number_format($priceVal, 2, '.', '');
    $interval  = strtoupper(trim((string)($settings['paypal_billing_interval'] ?? 'MONTH')));
    if (!in_array($interval, ['MONTH', 'YEAR'], true)) {
        $interval = 'MONTH';
    }
    $quotaMb   = max(100, (int)($settings['paypal_paid_quota_mb'] ?? 1024));
    $quotaLabel = $quotaMb >= 1024
        ? rtrim(rtrim(number_format($quotaMb / 1024, 2, ',', '.'), '0'), ',') . ' GB'
        : $quotaMb . ' MB';

    // 1. Produkt anlegen
    $productPayload = [
        'name'        => $appTitle . ' Cloud-Mitgliedschaft (' . $quotaLabel . ')',
        'description' => sprintf('Vollzugriff auf alle %s-Module inklusive %s persönlichem Cloud-Speicher.', $appTitle, $quotaLabel),
        'type'        => 'SERVICE',
        'category'    => 'SOFTWARE',
    ];

    $prodCode = 0;
    $prodRes  = paypalApiRequest($settings, 'POST', '/v1/catalogs/products', $productPayload, $prodCode, $errorOut);
    if ($prodRes === null || empty($prodRes['id'])) {
        return null;
    }
    $productId = (string)$prodRes['id'];

    // 2. Billing Plan für dieses Produkt anlegen
    $intervalLabel = $interval === 'YEAR' ? 'Jährlich' : 'Monatlich';
    $planPayload = [
        'product_id'          => $productId,
        'name'                => sprintf('%s Abo (%s – %s € / %s)', $appTitle, $quotaLabel, number_format($priceVal, 2, ',', '.'), $intervalLabel),
        'description'         => sprintf('%s Cloud-Speicher & alle Module (%s)', $quotaLabel, $intervalLabel),
        'status'              => 'ACTIVE',
        'billing_cycles'      => [
            [
                'frequency' => [
                    'interval_unit'  => $interval,
                    'interval_count' => 1,
                ],
                'tenure_type'    => 'REGULAR',
                'sequence'       => 1,
                'total_cycles'   => 0, // 0 = unbegrenzt laufendes Abo bis zur Kündigung
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value'         => $priceStr,
                        'currency_code' => 'EUR',
                    ],
                ],
            ],
        ],
        'payment_preferences' => [
            'auto_bill_outstanding'     => true,
            'setup_fee_failure_action'  => 'CONTINUE',
            'payment_failure_threshold' => 2,
        ],
    ];

    $planCode = 0;
    $planRes  = paypalApiRequest($settings, 'POST', '/v1/billing/plans', $planPayload, $planCode, $errorOut);
    if ($planRes === null || empty($planRes['id'])) {
        return null;
    }

    $planId = (string)$planRes['id'];
    saveAppSetting($pdo, 'paypal_plan_id', $planId);
    return $planId;
}

/**
 * Prüft eine PayPal-Subscription-ID direkt bei PayPal und liefert Status, nächstes Abrechnungsdatum und Zahler-Infos.
 *
 * @param array<string, mixed> $settings
 * @return array{ok: bool, status: string, subscription_id: string, plan_id: string, next_billing_time: ?string, payer_email: string}|null
 */
function verifyPayPalSubscription(array $settings, string $subscriptionId, ?string &$errorOut = null): ?array
{
    $subscriptionId = trim($subscriptionId);
    if ($subscriptionId === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $subscriptionId)) {
        $errorOut = 'Ungültige PayPal-Subscription-ID.';
        return null;
    }

    $code = 0;
    $res  = paypalApiRequest($settings, 'GET', '/v1/billing/subscriptions/' . rawurlencode($subscriptionId), null, $code, $errorOut);
    if ($res === null) {
        return null;
    }

    $status     = strtoupper((string)($res['status'] ?? ''));
    $planId     = (string)($res['plan_id'] ?? '');
    $nextBill   = isset($res['billing_info']['next_billing_time']) ? (string)$res['billing_info']['next_billing_time'] : null;
    $payerEmail = isset($res['subscriber']['email_address']) ? (string)$res['subscriber']['email_address'] : '';

    $expectedPlan = trim((string)($settings['paypal_plan_id'] ?? ''));
    if ($expectedPlan !== '' && $planId !== '' && $planId !== $expectedPlan) {
        $errorOut = 'Der abgerechnete PayPal-Plan stimmt nicht mit dem hinterlegten Cloud-Plan überein.';
        return null;
    }

    $nextMysql = null;
    if ($nextBill !== null && $nextBill !== '') {
        $ts = strtotime($nextBill);
        if ($ts !== false) {
            $nextMysql = date('Y-m-d H:i:s', $ts);
        }
    }
    if ($nextMysql === null) {
        $interval = strtoupper(trim((string)($settings['paypal_billing_interval'] ?? 'MONTH')));
        $nextMysql = date('Y-m-d H:i:s', strtotime($interval === 'YEAR' ? '+1 year' : '+1 month'));
    }

    return [
        'ok'                => in_array($status, ['ACTIVE', 'APPROVED'], true),
        'status'            => $status,
        'subscription_id'   => (string)($res['id'] ?? $subscriptionId),
        'plan_id'           => $planId,
        'next_billing_time' => $nextMysql,
        'payer_email'       => $payerEmail,
    ];
}

/**
 * Kündigt ein aktives PayPal-Abo über die PayPal REST API.
 *
 * @param array<string, mixed> $settings
 */
function cancelPayPalSubscription(array $settings, string $subscriptionId, string $reason = 'Kündigung durch Benutzer in MiniCloud', ?string &$errorOut = null): bool
{
    $subscriptionId = trim($subscriptionId);
    if ($subscriptionId === '') {
        $errorOut = 'Keine PayPal-Subscription-ID vorhanden.';
        return false;
    }

    // Falls es eine lokale Sandbox-Simulation-ID ist
    if (str_starts_with($subscriptionId, 'I-SANDBOX-SIM-')) {
        return true;
    }

    $code = 0;
    $res  = paypalApiRequest(
        $settings,
        'POST',
        '/v1/billing/subscriptions/' . rawurlencode($subscriptionId) . '/cancel',
        ['reason' => mb_substr($reason, 0, 127)],
        $code,
        $errorOut
    );

    return $res !== null && ($code === 204 || ($code >= 200 && $code < 300));
}

/**
 * Schaltet ein Benutzerkonto nach erfolgreicher PayPal-Abo-Zahlung frei und weist das Abo-Speicher-Kontingent zu.
 *
 * @param array<string, mixed> $settings
 */
function activateUserPayPalSubscription(
    PDO $pdo,
    int $userId,
    string $subscriptionId,
    ?string $expiresAtMysql,
    array $settings
): void {
    ensureUserSecurityColumns($pdo);

    $paidQuotaMb    = max(100, (int)($settings['paypal_paid_quota_mb'] ?? 1024));
    $paidQuotaBytes = $paidQuotaMb * 1024 * 1024;

    if ($expiresAtMysql === null || $expiresAtMysql === '') {
        $interval = strtoupper(trim((string)($settings['paypal_billing_interval'] ?? 'MONTH')));
        $expiresAtMysql = date('Y-m-d H:i:s', strtotime($interval === 'YEAR' ? '+1 year' : '+1 month'));
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET is_locked = 0,
             lock_reason = \'\',
             storage_quota = GREATEST(storage_quota, :quota),
             subscription_status = \'active\',
             paypal_subscription_id = :sub_id,
             subscription_expires_at = :exp
         WHERE id = :uid'
    );
    $stmt->execute([
        ':quota'  => $paidQuotaBytes,
        ':sub_id' => $subscriptionId,
        ':exp'    => $expiresAtMysql,
        ':uid'    => $userId,
    ]);
}
