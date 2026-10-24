<?php
declare(strict_types=1);

/**
 * MiniCloud - Authentifizierungs-, Session-, Rollen- und Sicherheits-Helfer
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    $currentSavePath = session_save_path();
    if ($currentSavePath === '' || !is_dir($currentSavePath) || !is_writable($currentSavePath)) {
        session_save_path(sys_get_temp_dir());
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Sicherheitsrelevante HTTP-Header setzen
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Prüft, ob ein Benutzer eingeloggt ist, synchronisiert Rolle, Theme & Timeout aus der Datenbank
 * und prüft das vom Benutzer gewählte Inaktivitäts-Timeout.
 */
function isLoggedIn(): bool
{
    if (!isset($_SESSION['user_id'], $_SESSION['username']) || !is_int($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
        return false;
    }

    static $verifiedThisRequest = false;
    if (!$verifiedThisRequest) {
        $verifiedThisRequest = true;
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT username, is_admin, theme, session_timeout FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row) {
                $timeoutMin = isset($row['session_timeout']) ? (int)$row['session_timeout'] : 30;
                if ($timeoutMin > 0 && isset($_SESSION['last_activity']) && is_int($_SESSION['last_activity'])) {
                    if ((time() - $_SESSION['last_activity']) > ($timeoutMin * 60)) {
                        $_SESSION = [];
                        setFlash('error', 'Ihre Sitzung wurde nach Inaktivität automatisch beendet. Bitte melden Sie sich erneut an.');
                        return false;
                    }
                }
                $_SESSION['username']        = (string)$row['username'];
                $_SESSION['is_admin']        = ((int)$row['is_admin'] === 1);
                $_SESSION['theme']           = (string)($row['theme'] ?: 'light');
                $_SESSION['session_timeout'] = $timeoutMin;
                $_SESSION['last_activity']   = time();
            } else {
                // Benutzer wurde gelöscht
                $_SESSION = [];
                return false;
            }
        } catch (Throwable) {
            $_SESSION['is_admin']        = $_SESSION['is_admin'] ?? false;
            $_SESSION['theme']           = $_SESSION['theme'] ?? 'light';
            $_SESSION['session_timeout'] = $_SESSION['session_timeout'] ?? 30;
            $_SESSION['last_activity']   = time();
        }
    }

    return true;
}

/**
 * Liefert die erlaubten Werte für das automatische Sitzungs-Timeout (in Minuten, 0 = Kein Timeout).
 *
 * @return array<int, string>
 */
function getAllowedSessionTimeouts(): array
{
    return [
        5   => '5 Minuten',
        15  => '15 Minuten',
        30  => '30 Minuten (Standard)',
        60  => '60 Minuten (1 Stunde)',
        120 => '120 Minuten (2 Stunden)',
        240 => '240 Minuten (4 Stunden)',
        480 => '480 Minuten (8 Stunden)',
        0   => 'Kein Timeout (Angemeldet bleiben)',
    ];
}

/**
 * Liefert das aktuell für den Benutzer eingestellte Sitzungs-Timeout in Minuten (0 = deaktiviert).
 */
function getCurrentSessionTimeout(): int
{
    if (!isLoggedIn()) {
        return 30;
    }
    return isset($_SESSION['session_timeout']) ? (int)$_SESSION['session_timeout'] : 30;
}

/**
 * Prüft, ob der aktuell eingeloggte Benutzer Administrator-Rechte besitzt.
 */
function isAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    return !empty($_SESSION['is_admin']);
}

/**
 * Schützt Seiten strikt vor unautorisiertem Zugriff.
 * Leitet nicht eingeloggte Benutzer sofort auf login.php um.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Schützt Administrationsseiten strikt vor Nicht-Administratoren.
 */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        setFlash('error', 'Zugriff verweigert: Für diesen Bereich sind Administrator-Rechte erforderlich.');
        header('Location: index.php');
        exit;
    }
}

/**
 * Leitet bereits eingeloggte Benutzer zum Dashboard weiter.
 */
function redirectIfLoggedIn(): void
{
    if (isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Liefert die Liste aller unterstützten Farbschemata.
 *
 * @return array<int, string>
 */
function getAllowedThemes(): array
{
    return [
        'light', 'sand', 'sage', 'nordic', 'lavender',
        'dusk', 'mocha', 'petrol', 'plum',
        'dark', 'ocean', 'forest',
    ];
}

/**
 * Liefert das aktuell gewählte Farbschema.
 */
function getCurrentTheme(): string
{
    $allowed = getAllowedThemes();
    if (isLoggedIn() && isset($_SESSION['theme']) && in_array($_SESSION['theme'], $allowed, true)) {
        return (string)$_SESSION['theme'];
    }
    if (isset($_COOKIE['mc_theme']) && in_array($_COOKIE['mc_theme'], $allowed, true)) {
        return (string)$_COOKIE['mc_theme'];
    }
    return 'light';
}

/**
 * Erzeugt oder liefert das aktuelle CSRF-Token der Session.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Überprüft ein übergebenes CSRF-Token zeitkonstant.
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Speichert eine Flash-Nachricht für den nächsten Seitenaufruf.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Liest und löscht alle vorhandenen Flash-Nachrichten.
 *
 * @return array<int, array{type: string, message: string}>
 */
function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}
