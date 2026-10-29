<?php
declare(strict_types=1);

/**
 * MiniCloud – WebAuthn / Passkey API (passkey_api.php)
 * Ermöglicht passwortloses Anmelden per Fingerabdruck (TouchID / Android),
 * Gesichtserkennung (FaceID / Windows Hello) oder Hardware-Sicherheitsschlüssel (YubiKey).
 * 100 % lokal in nativem PHP + OpenSSL ohne externe Abhängigkeiten.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getDB();
ensurePasskeySchema($pdo);

function ensurePasskeySchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked || (!empty($_SESSION['_mc_passkey_schema_ok']) && $_SESSION['_mc_passkey_schema_ok'] === 1)) {
        $checked = true;
        return;
    }
    $checked = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `user_passkeys` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `credential_id` VARCHAR(512) NOT NULL,
                `public_key_pem` TEXT NOT NULL,
                `cose_alg` INT NOT NULL DEFAULT -7,
                `device_name` VARCHAR(120) NOT NULL DEFAULT 'Passkey-Gerät',
                `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_used_at` DATETIME NULL DEFAULT NULL,
                UNIQUE KEY `uq_cred_id` (`credential_id`(255)),
                INDEX `idx_user_passkeys` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $_SESSION['_mc_passkey_schema_ok'] = 1;
    } catch (Throwable) {
    }
}

function b64urlEncode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64urlDecode(string $str): string
{
    $remainder = strlen($str) % 4;
    if ($remainder > 0) {
        $str .= str_repeat('=', 4 - $remainder);
    }
    return (string)base64_decode(strtr($str, '-_', '+/'), true);
}

function getWebAuthnRpId(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    // Port entfernen, falls vorhanden
    $host = preg_replace('/:\d+$/', '', strtolower(trim($host))) ?? 'localhost';
    return $host !== '' ? $host : 'localhost';
}

/**
 * Hilfsfunktion: Extrahiert aus einem unkomprimierten P-256 Punkt (x, y je 32 Bytes)
 * im AuthenticatorData-Puffer einen gültigen SPKI-PEM Public Key, falls ein älterer
 * Browser getPublicKey() nicht direkt im JS mitliefert.
 */
function extractP256PemFromAuthData(string $authDataBin): ?string
{
    if (strlen($authDataBin) < 55) {
        return null;
    }
    $flags = ord($authDataBin[32]);
    // Bit 6 (0x40) = AttestedCredentialData vorhanden
    if (($flags & 0x40) === 0) {
        return null;
    }
    $credIdLen = unpack('n', substr($authDataBin, 53, 2))[1] ?? 0;
    $coseOffset = 55 + $credIdLen;
    if (strlen($authDataBin) < $coseOffset + 70) {
        return null;
    }
    $coseSlice = substr($authDataBin, $coseOffset);

    // Suche nach COSE EC2 x-Koordinate (0x21 0x58 0x20) und y-Koordinate (0x22 0x58 0x20)
    $posX = strpos($coseSlice, "\x21\x58\x20");
    $posY = strpos($coseSlice, "\x22\x58\x20");
    if ($posX === false || $posY === false) {
        return null;
    }
    $x = substr($coseSlice, $posX + 3, 32);
    $y = substr($coseSlice, $posY + 3, 32);
    if (strlen($x) !== 32 || strlen($y) !== 32) {
        return null;
    }

    // ASN.1 SPKI Header für prime256v1 (P-256) unkomprimierten Punkt (0x04 || X || Y)
    $spkiPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
    if ($spkiPrefix === false) {
        return null;
    }
    $der = $spkiPrefix . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

// =============================================================================
// 1. REGISTRIERUNGS-OPTIONEN ABRUFEN (Eingeloggter Benutzer in settings.php)
// =============================================================================
if ($action === 'register_options') {
    if (!isLoggedIn()) {
        echo json_encode(['ok' => false, 'error' => 'Bitte melden Sie sich zuerst an.']);
        exit;
    }
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
        exit;
    }

    $userId   = (int)$_SESSION['user_id'];
    $username = (string)$_SESSION['username'];
    $rpId     = getWebAuthnRpId();

    $challenge = random_bytes(32);
    $_SESSION['webauthn_reg_challenge'] = b64urlEncode($challenge);
    $_SESSION['webauthn_reg_expires']   = time() + 300;

    $stmtEx = $pdo->prepare("SELECT `credential_id` FROM `user_passkeys` WHERE `user_id` = :uid");
    $stmtEx->execute([':uid' => $userId]);
    $excludeCreds = [];
    foreach ($stmtEx->fetchAll() as $r) {
        $excludeCreds[] = [
            'type' => 'public-key',
            'id'   => (string)$r['credential_id'],
        ];
    }

    echo json_encode([
        'ok'                 => true,
        'rpId'               => $rpId,
        'rpName'             => 'MiniCloud',
        'userIdB64'          => b64urlEncode(pack('N', $userId)),
        'userName'           => $username,
        'challengeB64'       => $_SESSION['webauthn_reg_challenge'],
        'excludeCredentials' => $excludeCreds,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// 2. NEUEN PASSKEY SPEICHERN & VERIFIZIEREN (Eingeloggter Benutzer)
// =============================================================================
if ($action === 'register_verify') {
    if (!isLoggedIn()) {
        echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
        exit;
    }
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
        exit;
    }

    $expectedChallenge = (string)($_SESSION['webauthn_reg_challenge'] ?? '');
    $expiresAt         = (int)($_SESSION['webauthn_reg_expires'] ?? 0);
    unset($_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_reg_expires']);

    if ($expectedChallenge === '' || time() > $expiresAt) {
        echo json_encode(['ok' => false, 'error' => 'Die Passkey-Registrierung ist abgelaufen. Bitte versuchen Sie es erneut.']);
        exit;
    }

    $credentialId  = trim((string)($_POST['credential_id'] ?? ''));
    $clientDataB64 = (string)($_POST['client_data_json'] ?? '');
    $authDataB64   = (string)($_POST['authenticator_data'] ?? '');
    $spkiDerB64    = (string)($_POST['public_key_spki'] ?? '');
    $coseAlg       = (int)($_POST['public_key_alg'] ?? -7);
    $deviceName    = trim((string)($_POST['device_name'] ?? ''));
    if ($deviceName === '') {
        $deviceName = 'Passkey vom ' . date('d.m.Y');
    }
    $deviceName = mb_substr($deviceName, 0, 100);

    $clientDataRaw = b64urlDecode($clientDataB64);
    $clientData    = json_decode($clientDataRaw, true);
    if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.create') {
        echo json_encode(['ok' => false, 'error' => 'Ungültige WebAuthn-ClientData.']);
        exit;
    }
    if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'Sicherheits-Challenge stimmt nicht überein.']);
        exit;
    }
    $originHost = strtolower((string)parse_url((string)($clientData['origin'] ?? ''), PHP_URL_HOST));
    if ($originHost !== '' && $originHost !== getWebAuthnRpId()) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Origin-Domain bei der Passkey-Registrierung.']);
        exit;
    }
    if ($authDataB64 !== '') {
        $regAuthBin = b64urlDecode($authDataB64);
        if (strlen($regAuthBin) >= 32 && !hash_equals(hash('sha256', getWebAuthnRpId(), true), substr($regAuthBin, 0, 32))) {
            echo json_encode(['ok' => false, 'error' => 'RP-ID-Hash der Passkey-Registrierung stimmt nicht überein.']);
            exit;
        }
    }

    // Public Key im PEM-Format gewinnen (1. direkt per SPKI aus Browser getPublicKey(), 2. Fallback aus AuthenticatorData)
    $pem = null;
    if ($spkiDerB64 !== '') {
        $spkiDer = b64urlDecode($spkiDerB64);
        if ($spkiDer !== '') {
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spkiDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }
    }
    if ($pem === null && $authDataB64 !== '') {
        $pem = extractP256PemFromAuthData(b64urlDecode($authDataB64));
    }

    if ($pem === null || @openssl_pkey_get_public($pem) === false) {
        echo json_encode(['ok' => false, 'error' => 'Öffentlicher Schlüssel des Passkeys konnte nicht gelesen werden.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    try {
        $stmtIns = $pdo->prepare(
            "INSERT INTO `user_passkeys` (`user_id`, `credential_id`, `public_key_pem`, `cose_alg`, `device_name`)
             VALUES (:uid, :cid, :pem, :alg, :dev)"
        );
        $stmtIns->execute([
            ':uid' => $userId,
            ':cid' => $credentialId,
            ':pem' => $pem,
            ':alg' => $coseAlg,
            ':dev' => $deviceName,
        ]);

        logAuditEvent($pdo, 'security_change', 'Neuer Passkey registriert (' . $deviceName . ')', $userId, (string)$_SESSION['username']);
        echo json_encode(['ok' => true, 'message' => 'Passkey „' . $deviceName . '“ erfolgreich eingerichtet!']);
    } catch (Throwable) {
        echo json_encode(['ok' => false, 'error' => 'Dieser Passkey ist bereits registriert.']);
    }
    exit;
}

// =============================================================================
// 3. PASSKEY LÖSCHEN (Eingeloggter Benutzer in settings.php)
// =============================================================================
if ($action === 'delete_passkey') {
    if (!isLoggedIn()) {
        echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
        exit;
    }
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: settings.php#sec-passkeys');
        exit;
    }

    $pkId   = (int)($_POST['passkey_id'] ?? 0);
    $userId = (int)$_SESSION['user_id'];
    if ($pkId > 0) {
        $stmtDel = $pdo->prepare("DELETE FROM `user_passkeys` WHERE `id` = :id AND `user_id` = :uid");
        $stmtDel->execute([':id' => $pkId, ':uid' => $userId]);
        setFlash('success', 'Der ausgewählte Passkey wurde entfernt.');
    }
    header('Location: settings.php#sec-passkeys');
    exit;
}

// =============================================================================
// 4. ANMELDE-CHALLENGE FÜR LOGIN.PHP ERZEUGEN (Ohne vorherigen Login)
// =============================================================================
if ($action === 'login_options') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.']);
        exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $rpId     = getWebAuthnRpId();

    $challenge = random_bytes(32);
    $_SESSION['webauthn_auth_challenge'] = b64urlEncode($challenge);
    $_SESSION['webauthn_auth_expires']   = time() + 300;

    $allowCredentials = [];
    if ($username !== '') {
        $stmtU = $pdo->prepare(
            "SELECT pk.`credential_id`
             FROM `user_passkeys` pk
             INNER JOIN `users` u ON u.`id` = pk.`user_id`
             WHERE u.`username` = :uname"
        );
        $stmtU->execute([':uname' => $username]);
        foreach ($stmtU->fetchAll() as $r) {
            $allowCredentials[] = [
                'type' => 'public-key',
                'id'   => (string)$r['credential_id'],
            ];
        }
    }

    echo json_encode([
        'ok'               => true,
        'rpId'             => $rpId,
        'challengeB64'     => $_SESSION['webauthn_auth_challenge'],
        'allowCredentials' => $allowCredentials,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================================
// 5. PASSKEY-SIGNATUR PRÜFEN & BENUTZER EINLOGGEN (login.php)
// =============================================================================
if ($action === 'login_verify') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
        exit;
    }

    $clientIp = getClientIpForRateLimit();
    if (isLoginIpRateLimited($pdo, $clientIp)) {
        echo json_encode(['ok' => false, 'error' => 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte warten Sie 15 Minuten.']);
        exit;
    }

    $expectedChallenge = (string)($_SESSION['webauthn_auth_challenge'] ?? '');
    $expiresAt         = (int)($_SESSION['webauthn_auth_expires'] ?? 0);
    unset($_SESSION['webauthn_auth_challenge'], $_SESSION['webauthn_auth_expires']);

    if ($expectedChallenge === '' || time() > $expiresAt) {
        echo json_encode(['ok' => false, 'error' => 'Die Anmelde-Anfrage ist abgelaufen. Bitte erneut versuchen.']);
        exit;
    }

    $credentialId  = trim((string)($_POST['credential_id'] ?? ''));
    $clientDataB64 = (string)($_POST['client_data_json'] ?? '');
    $authDataB64   = (string)($_POST['authenticator_data'] ?? '');
    $signatureB64  = (string)($_POST['signature'] ?? '');

    if ($credentialId === '' || $clientDataB64 === '' || $authDataB64 === '' || $signatureB64 === '') {
        echo json_encode(['ok' => false, 'error' => 'Unvollständige Passkey-Antwort vom Browser.']);
        exit;
    }

    $clientDataRaw = b64urlDecode($clientDataB64);
    $authDataBin   = b64urlDecode($authDataB64);
    $signatureBin  = b64urlDecode($signatureB64);

    $clientData = json_decode($clientDataRaw, true);
    if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get') {
        echo json_encode(['ok' => false, 'error' => 'Ungültige WebAuthn-Antwort.']);
        exit;
    }
    if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'Sicherheits-Challenge stimmt nicht überein.']);
        exit;
    }
    $originHost = strtolower((string)parse_url((string)($clientData['origin'] ?? ''), PHP_URL_HOST));
    if ($originHost !== '' && $originHost !== getWebAuthnRpId()) {
        echo json_encode(['ok' => false, 'error' => 'Passkey-Antwort stammt von einer fremden Domain (Origin-Konflikt).']);
        exit;
    }

    // Passkey + zugehörigen Benutzer in der Datenbank suchen
    ensureUserSecurityColumns($pdo);
    $stmtPk = $pdo->prepare(
        "SELECT pk.`id` AS `pk_id`, pk.`public_key_pem`, pk.`device_name`, pk.`sign_count`,
                u.`id` AS `user_id`, u.`username`, u.`is_admin`, u.`is_locked`, u.`lock_reason`, u.`admin_permissions`, u.`theme`
         FROM `user_passkeys` pk
         INNER JOIN `users` u ON u.`id` = pk.`user_id`
         WHERE pk.`credential_id` = :cid
         LIMIT 1"
    );
    $stmtPk->execute([':cid' => $credentialId]);
    $row = $stmtPk->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Dieser Passkey ist in der Cloud noch nicht hinterlegt. Bitte melden Sie sich einmalig mit Passwort an und richten Sie den Passkey unter Einstellungen -> Sicherheit ein.']);
        exit;
    }

    if ((int)($row['is_locked'] ?? 0) === 1) {
        $reason = trim((string)($row['lock_reason'] ?? ''));
        echo json_encode(['ok' => false, 'error' => 'Ihr Benutzerkonto wurde gesperrt' . ($reason !== '' ? ' (' . $reason . ')' : '') . '.']);
        exit;
    }

    // RP-ID-Hash (erste 32 Bytes von authenticatorData) und User-Present-Bit prüfen
    if (strlen($authDataBin) < 37) {
        echo json_encode(['ok' => false, 'error' => 'Ungültige Authenticator-Daten.']);
        exit;
    }
    $expectedRpHash = hash('sha256', getWebAuthnRpId(), true);
    $actualRpHash   = substr($authDataBin, 0, 32);
    if (!hash_equals($expectedRpHash, $actualRpHash)) {
        echo json_encode(['ok' => false, 'error' => 'Passkey gehört zu einer anderen Domain (RP-ID-Konflikt).']);
        exit;
    }
    $flags = ord($authDataBin[32]);
    if (($flags & 0x01) === 0) {
        echo json_encode(['ok' => false, 'error' => 'Anwesenheitsprüfung (User Presence) fehlgeschlagen.']);
        exit;
    }

    // Kryptografische Signatur über (authenticatorData || SHA256(clientDataJSON)) prüfen
    $signedData = $authDataBin . hash('sha256', $clientDataRaw, true);
    $pubKey     = @openssl_pkey_get_public((string)$row['public_key_pem']);
    if ($pubKey === false) {
        echo json_encode(['ok' => false, 'error' => 'Gespeicherter öffentlicher Schlüssel ist ungültig.']);
        exit;
    }

    $verified = @openssl_verify($signedData, $signatureBin, $pubKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        recordFailedLoginAttempt($pdo, $clientIp, (string)$row['username']);
        echo json_encode(['ok' => false, 'error' => 'Kryptografische Passkey-Signatur konnte nicht verifiziert werden.']);
        exit;
    }

    // Signatur-Zähler (Clone-Detection nach WebAuthn-Standard) prüfen & aktualisieren
    $newSignCount    = (int)(unpack('N', substr($authDataBin, 33, 4))[1] ?? 0);
    $storedSignCount = (int)($row['sign_count'] ?? 0);
    if ($storedSignCount > 0 && $newSignCount > 0 && $newSignCount <= $storedSignCount) {
        logAuditEvent($pdo, 'login_failed', 'Passkey-Signaturzähler-Warnung (möglicher Klon): ' . $row['device_name'], (int)$row['user_id'], (string)$row['username']);
        echo json_encode(['ok' => false, 'error' => 'Sicherheitswarnung: Ungültiger Signaturzähler des Passkeys.']);
        exit;
    }

    // Signatur gültig -> Nutzungszeitpunkt & Zähler aktualisieren & Sitzung starten!
    $stmtTouch = $pdo->prepare("UPDATE `user_passkeys` SET `last_used_at` = NOW(), `sign_count` = :sc WHERE `id` = :id");
    $stmtTouch->execute([':sc' => max($storedSignCount, $newSignCount), ':id' => (int)$row['pk_id']]);
    clearFailedLoginAttempts($pdo, $clientIp);

    session_regenerate_id(true);
    unset(
        $_SESSION['login_attempts'],
        $_SESSION['pending_2fa_user_id'],
        $_SESSION['pending_2fa_username'],
        $_SESSION['pending_2fa_mode'],
        $_SESSION['pending_2fa_email_masked'],
        $_SESSION['pending_2fa_last_sent']
    );

    $_SESSION['user_id']           = (int)$row['user_id'];
    $_SESSION['username']          = (string)$row['username'];
    $_SESSION['is_admin']          = ((int)$row['is_admin'] === 1);
    $_SESSION['admin_permissions'] = parseAdminPermissionsString((string)($row['admin_permissions'] ?? ''));
    $_SESSION['theme']             = (string)($row['theme'] ?: 'light');
    $_SESSION['last_activity']     = time();
    $_SESSION['csrf_token']        = bin2hex(random_bytes(32));

    logAuditEvent(
        $pdo,
        'login_success',
        'Erfolgreiche Anmeldung per Passkey / Biometrie (' . $row['device_name'] . ')',
        (int)$row['user_id'],
        (string)$row['username']
    );

    setFlash('success', 'Willkommen zurück, ' . $row['username'] . '! (Angemeldet per 🔑 Passkey)');
    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
