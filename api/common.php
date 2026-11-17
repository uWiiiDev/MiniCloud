<?php
declare(strict_types=1);

/**
 * MiniCloud - REST-API Basisfunktionen & Token-Authentifizierung
 * Ermöglicht schnellen, sicheren Zugriff für native Android- und Mobile-Apps.
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// CORS-Header für mobile Apps und Web-Clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Range, X-Requested-With');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Disposition');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Sendet eine strukturierte JSON-Antwort und beendet das Skript.
 */
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Stellt sicher, dass die Tabelle für API-Tokens existiert.
 */
function ensureApiTokensTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS `api_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `token_hash` VARCHAR(64) NOT NULL,
        `device_name` VARCHAR(100) NOT NULL DEFAULT 'Android Device',
        `last_used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_token_hash` (`token_hash`),
        KEY `idx_user_token` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Extrahiert den Bearer-Token aus den HTTP-Headern oder Parametern.
 */
function extractBearerToken(): ?string
{
    $headers = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];

    if (function_exists('apache_request_headers')) {
        $apache = apache_request_headers();
        if (!empty($apache['Authorization'])) {
            $headers[] = $apache['Authorization'];
        }
    }

    foreach ($headers as $h) {
        if (!empty($h) && is_string($h)) {
            if (preg_match('/Bearer\s+([a-zA-Z0-9_\-\.]+)/i', $h, $m)) {
                return $m[1];
            }
        }
    }

    // Fallback: URL-Parameter oder POST-Feld (z.B. für Download-Links)
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }
    if (!empty($_POST['token']) && is_string($_POST['token'])) {
        return trim($_POST['token']);
    }

    return null;
}

/**
 * Verifiziert den aktuellen API-Aufruf und liefert den Benutzerdatensatz zurück.
 * Bricht bei ungültiger Authentifizierung mit HTTP 401 ab.
 *
 * @return array{id: int, username: string, role: string, is_admin: bool}
 */
function requireApiAuth(PDO $pdo): array
{
    ensureApiTokensTable($pdo);

    $token = extractBearerToken();

    // 1. Token-Authentifizierung (Bevorzugt für Apps)
    if ($token !== null && strlen($token) >= 20) {
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT t.id AS token_id, t.user_id, u.username, u.is_locked, u.role
            FROM api_tokens t
            JOIN users u ON t.user_id = u.id
            WHERE t.token_hash = :hash
            LIMIT 1');
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)($row['is_locked'] ?? 0) === 0) {
            // Letzte Nutzung aktualisieren
            $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :tid')
                ->execute([':tid' => (int)$row['token_id']]);

            return [
                'id'       => (int)$row['user_id'],
                'username' => (string)$row['username'],
                'role'     => (string)($row['role'] ?? 'user'),
                'is_admin' => ((string)($row['role'] ?? 'user') === 'admin')
            ];
        }
    }

    // 2. HTTP Basic Auth Fallback (falls App direkt Basic Auth sendet)
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $authPass = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($authUser === null) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$authUser, $authPass] = explode(':', $decoded, 2);
            }
        }
    }

    if ($authUser !== null && $authPass !== null && trim($authUser) !== '') {
        $userRow = verifyDavBasicAuth($pdo, trim($authUser), (string)$authPass, 'MiniCloud API');
        if ($userRow !== null) {
            $stmtRole = $pdo->prepare('SELECT role FROM users WHERE id = :uid');
            $stmtRole->execute([':uid' => (int)$userRow['id']]);
            $role = (string)($stmtRole->fetchColumn() ?: 'user');

            return [
                'id'       => (int)$userRow['id'],
                'username' => (string)$userRow['username'],
                'role'     => $role,
                'is_admin' => ($role === 'admin')
            ];
        }
    }

    sendJsonResponse([
        'ok'    => false,
        'error' => 'Nicht authentifiziert oder Sitzung abgelaufen. Bitte erneut anmelden.'
    ], 401);
    exit;
}
