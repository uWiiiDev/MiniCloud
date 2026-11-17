<?php
declare(strict_types=1);

/**
 * MiniCloud REST API - Authentifizierung & Sitzungsverwaltung für Android-App
 */

require_once __DIR__ . '/common.php';

$pdo = getDB();
ensureApiTokensTable($pdo);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// JSON Input parsen falls vorhanden
$jsonInput = [];
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && trim($rawBody) !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? $jsonInput['action'] ?? 'check';

if ($action === 'login') {
    if ($method !== 'POST') {
        sendJsonResponse(['ok' => false, 'error' => 'Login erfordert HTTP POST.'], 405);
    }

    $username = trim((string)($_POST['username'] ?? $jsonInput['username'] ?? ''));
    $password = (string)($_POST['password'] ?? $jsonInput['password'] ?? '');
    $deviceName = trim((string)($_POST['device_name'] ?? $jsonInput['device_name'] ?? 'Android App'));

    if ($username === '' || $password === '') {
        sendJsonResponse(['ok' => false, 'error' => 'Benutzername und Passwort erforderlich.'], 400);
    }

    if (mb_strlen($deviceName, 'UTF-8') > 100) {
        $deviceName = mb_substr($deviceName, 0, 100, 'UTF-8');
    }

    $userRow = verifyDavBasicAuth($pdo, $username, $password, 'Android App');
    if ($userRow === null) {
        sendJsonResponse(['ok' => false, 'error' => 'Ungültige Anmeldedaten.'], 401);
    }

    $userId = (int)$userRow['id'];

    // Admin-Status ermitteln
    $stmtAdmin = $pdo->prepare('SELECT is_admin FROM users WHERE id = :uid LIMIT 1');
    $stmtAdmin->execute([':uid' => $userId]);
    $isAdmin = (int)($stmtAdmin->fetchColumn() ?: 0) === 1;
    $role = $isAdmin ? 'admin' : 'user';

    // Token erzeugen
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $stmtInsert = $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, device_name, last_used_at, created_at)
        VALUES (:uid, :thash, :dev, NOW(), NOW())');
    $stmtInsert->execute([
        ':uid'   => $userId,
        ':thash' => $tokenHash,
        ':dev'   => $deviceName !== '' ? $deviceName : 'Android App',
    ]);

    // Speicherdaten ermitteln
    $stats = getUserStorageStats($pdo, $userId);

    sendJsonResponse([
        'ok'    => true,
        'token' => $rawToken,
        'user'  => [
            'id'       => $userId,
            'username' => (string)$userRow['username'],
            'role'     => $role,
            'is_admin' => ($role === 'admin'),
        ],
        'quota' => [
            'used'            => $stats['used'],
            'quota'           => $stats['quota'],
            'free'            => $stats['free'],
            'percent'         => $stats['percent'],
            'used_formatted'  => formatBytes($stats['used']),
            'quota_formatted' => formatBytes($stats['quota']),
            'free_formatted'  => formatBytes($stats['free']),
        ],
    ]);
}

if ($action === 'logout') {
    $user = requireApiAuth($pdo);
    $token = extractBearerToken();
    if ($token !== null) {
        $tokenHash = hash('sha256', $token);
        $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = :hash')->execute([':hash' => $tokenHash]);
    }
    sendJsonResponse(['ok' => true, 'message' => 'Erfolgreich abgemeldet.']);
}

// Standard: Token prüfen / Benutzerdaten & Quota abrufen
$user = requireApiAuth($pdo);
$stats = getUserStorageStats($pdo, $user['id']);

sendJsonResponse([
    'ok'    => true,
    'user'  => [
        'id'       => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'is_admin' => $user['is_admin'],
    ],
    'quota' => [
        'used'            => $stats['used'],
        'quota'           => $stats['quota'],
        'free'            => $stats['free'],
        'percent'         => $stats['percent'],
        'used_formatted'  => formatBytes($stats['used']),
        'quota_formatted' => formatBytes($stats['quota']),
        'free_formatted'  => formatBytes($stats['free']),
    ],
]);
