<?php
declare(strict_types=1);

/**
 * uWiiiMiniCloud – 🔢 2FA-Authenticator & TOTP-Studio (authenticator.php)
 *
 * Funktionen:
 * - AES-256-GCM verschlüsselter Tresor für 2FA-Einmalpasswörter (TOTP nach RFC 6238, kompatibel mit Google Authenticator, Authy, Microsoft Authenticator, 2FAS)
 * - Unterstützt 6 & 8 Stellen, 30s & 60s Intervalle sowie SHA1, SHA256 & SHA512
 * - Echtzeit-Countdown-Ring, automatische Code-Aktualisierung ohne Seiten-Reload & „Nächster Code“-Vorschau
 * - 1-Klick-Kopieren in die Zwischenablage
 * - QR-Code-Import per Kamera / Bild-Upload / Zwischenablage (otpauth://totp/...)
 * - Lokaler SVG-QR-Code-Generator (100 % offline), um Accounts jederzeit aufs Smartphone zu übertragen
 * - Optionale Freigabe einzelner 2FA-Codes an Cloud-Nutzer oder Gruppen (Team-/Familien-Accounts)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');

if (($appSettings['enable_authenticator'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('warning', 'Das Modul „2FA-Authenticator“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

// =========================================================================
// DATENBANK-TABELLE FÜR VERSCHLÜSSELTE TOTP-ACCOUNTS INITIALISIEREN
// =========================================================================
function ensureAuthenticatorTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS totp_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            issuer VARCHAR(140) NOT NULL DEFAULT '',
            account_name VARCHAR(190) NOT NULL DEFAULT '',
            category VARCHAR(60) NOT NULL DEFAULT 'Allgemein',
            icon VARCHAR(24) NOT NULL DEFAULT '🔐',
            color VARCHAR(24) NOT NULL DEFAULT 'blue',
            secret_encrypted TEXT NOT NULL,
            digits TINYINT NOT NULL DEFAULT 6,
            period SMALLINT NOT NULL DEFAULT 30,
            algorithm VARCHAR(12) NOT NULL DEFAULT 'SHA1',
            notes_encrypted TEXT NULL,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            shared_user_id INT NULL DEFAULT NULL,
            group_id INT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_fav (user_id, is_favorite),
            INDEX idx_shared_user (shared_user_id),
            INDEX idx_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureAuthenticatorTable($pdo);

/**
 * Berechnet einen flexiblen RFC-6238-TOTP-Code mit wählbarem Algorithmus (SHA1/SHA256/SHA512),
 * Codelänge (6 oder 8 Stellen) und Zeitintervall (30 oder 60 Sekunden).
 */
function computeFlexibleTotpCode(
    string $base32Secret,
    int $period = 30,
    int $digits = 6,
    string $algo = 'SHA1',
    ?int $timestamp = null
): string {
    $secretKey = base32Decode($base32Secret);
    if ($secretKey === '') {
        return '';
    }
    $period = in_array($period, [15, 30, 60], true) ? $period : 30;
    $digits = in_array($digits, [6, 7, 8], true) ? $digits : 6;
    $algoLower = strtolower(trim($algo));
    if (!in_array($algoLower, ['sha1', 'sha256', 'sha512'], true)) {
        $algoLower = 'sha1';
    }

    $now = $timestamp ?? time();
    $timeSlice = (int)floor($now / $period);
    $timeBytes = pack('N*', 0, $timeSlice);
    $hmac = hash_hmac($algoLower, $timeBytes, $secretKey, true);
    $hmacLen = strlen($hmac);
    if ($hmacLen < 20) {
        return '';
    }

    $offset = ord($hmac[$hmacLen - 1]) & 0x0F;
    $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
        | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
        | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
        | (ord($hmac[$offset + 3]) & 0xFF);

    $modulo = 10 ** $digits;
    $otp = $binary % $modulo;
    return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
}

/**
 * Formatiert einen 6- oder 8-stelligen TOTP-Code übersichtlich mit Leerzeichen (z. B. "482 910" oder "4829 1034").
 */
function formatTotpDisplay(string $code): string
{
    $len = strlen($code);
    if ($len === 6) {
        return substr($code, 0, 3) . ' ' . substr($code, 3, 3);
    }
    if ($len === 8) {
        return substr($code, 0, 4) . ' ' . substr($code, 4, 4);
    }
    return $code;
}

/**
 * Baut eine vollständige otpauth://totp/ URI inkl. Algorithmus, Stellen & Periode.
 */
function buildFlexibleOtpAuthUri(
    string $issuer,
    string $accountName,
    string $secret,
    int $digits = 6,
    int $period = 30,
    string $algorithm = 'SHA1'
): string {
    $cleanIssuer  = trim((string)preg_replace('/[:\r\n]/', ' ', $issuer)) ?: 'Service';
    $cleanAccount = trim((string)preg_replace('/[:\r\n]/', ' ', $accountName)) ?: 'account';
    $label        = rawurlencode($cleanIssuer) . ':' . rawurlencode($cleanAccount);
    $cleanSecret  = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', $secret));

    return sprintf(
        'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
        $label,
        rawurlencode($cleanSecret),
        rawurlencode($cleanIssuer),
        rawurlencode(strtoupper($algorithm)),
        $digits,
        $period
    );
}

$categories = [
    'Cloud & E-Mail'     => '☁️ Cloud, E-Mail & Google/Microsoft',
    'Server & Hosting'   => '🖥️ Server, Hosting & IT (Contabo, GitHub, SSH)',
    'Banking & Zahlung'  => '💳 Banking, PayPal & Krypto',
    'Shopping & Dienste' => '🛒 Shopping & Online-Dienste',
    'Social & Gaming'    => '🎮 Social Media & Gaming',
    'Verein & Team'      => '🏛️ Verein, Familie & Geteilte Konten',
    'Allgemein'          => '📁 Allgemein',
];

$servicePresets = [
    ['label' => 'Google',     'icon' => '🌐', 'color' => 'blue',    'cat' => 'Cloud & E-Mail'],
    ['label' => 'Microsoft',  'icon' => '🪟', 'color' => 'sky',     'cat' => 'Cloud & E-Mail'],
    ['label' => 'GitHub',     'icon' => '🐙', 'color' => 'slate',   'cat' => 'Server & Hosting'],
    ['label' => 'Contabo',    'icon' => '🖥️', 'color' => 'indigo',  'cat' => 'Server & Hosting'],
    ['label' => 'PayPal',     'icon' => '💳', 'color' => 'blue',    'cat' => 'Banking & Zahlung'],
    ['label' => 'Amazon',     'icon' => '📦', 'color' => 'amber',   'cat' => 'Shopping & Dienste'],
    ['label' => 'Apple / ID', 'icon' => '🍎', 'color' => 'slate',   'cat' => 'Cloud & E-Mail'],
    ['label' => 'Discord',    'icon' => '💬', 'color' => 'violet',  'cat' => 'Social & Gaming'],
    ['label' => 'Steam',      'icon' => '🎮', 'color' => 'indigo',  'cat' => 'Social & Gaming'],
    ['label' => 'Bitwarden',  'icon' => '🛡️', 'color' => 'emerald', 'cat' => 'Server & Hosting'],
];

// =========================================================================
// ZUGRIFFSBERECHTIGTE TOTP-KONTEN DES NUTZERS LADEN
// =========================================================================
$userGroups   = getGroupsForUser($pdo, $userId);
$visibleUsers = getVisibleUsersForUser($pdo, $userId);
$groupIds     = array_map(static fn($g) => (int)$g['id'], $userGroups);

function fetchAccessibleTotpAccounts(PDO $pdo, int $userId, array $groupIds): array
{
    $sql = 'SELECT ta.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username
            FROM totp_accounts ta
            INNER JOIN users u ON u.id = ta.user_id
            LEFT JOIN `groups` g ON g.id = ta.group_id
            LEFT JOIN users su ON su.id = ta.shared_user_id
            WHERE ta.user_id = :uid1 OR ta.shared_user_id = :uid2';
    $params = [':uid1' => $userId, ':uid2' => $userId];

    if (!empty($groupIds)) {
        $phs = [];
        foreach ($groupIds as $idx => $gid) {
            $ph = ':gid_' . $idx;
            $phs[] = $ph;
            $params[$ph] = $gid;
        }
        $sql .= ' OR ta.group_id IN (' . implode(',', $phs) . ')';
    }
    $sql .= ' ORDER BY ta.is_favorite DESC, ta.issuer ASC, ta.account_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =========================================================================
// AJAX-ENDPUNKT: FRISCHE TOTP-CODES IM HINTERGRUND ABRUFEN (OHNE RELOAD)
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'codes') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $rows = fetchAccessibleTotpAccounts($pdo, $userId, $groupIds);
    $now  = time();
    $out  = [];

    foreach ($rows as $r) {
        $id     = (int)$r['id'];
        $secret = decryptPasswordEntry((string)$r['secret_encrypted']);
        $period = max(15, (int)($r['period'] ?: 30));
        $digits = max(6, (int)($r['digits'] ?: 6));
        $algo   = (string)($r['algorithm'] ?: 'SHA1');

        $curCode  = computeFlexibleTotpCode($secret, $period, $digits, $algo, $now);
        $nextCode = computeFlexibleTotpCode($secret, $period, $digits, $algo, $now + $period);
        $remSec   = $period - ($now % $period);

        $out[] = [
            'id'           => $id,
            'code_raw'     => $curCode,
            'code_fmt'     => formatTotpDisplay($curCode),
            'next_raw'     => $nextCode,
            'next_fmt'     => formatTotpDisplay($nextCode),
            'period'       => $period,
            'remaining'    => $remSec,
        ];
    }

    echo json_encode([
        'ok'          => true,
        'server_time' => $now,
        'rem_30'      => 30 - ($now % 30),
        'items'       => $out,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// POST-AKTIONEN (SPEICHERN, FAVORIT, LÖSCHEN, MASSEN-IMPORT)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: authenticator.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_totp') {
        $entryId     = (int)($_POST['entry_id'] ?? 0);
        $issuer      = mb_substr(trim((string)($_POST['issuer'] ?? '')), 0, 140);
        $accountName = mb_substr(trim((string)($_POST['account_name'] ?? '')), 0, 190);
        $category    = trim((string)($_POST['category'] ?? 'Allgemein'));
        if (!array_key_exists($category, $categories)) {
            $category = 'Allgemein';
        }
        $icon        = mb_substr(trim((string)($_POST['icon'] ?? '🔐')), 0, 16) ?: '🔐';
        $color       = trim((string)($_POST['color'] ?? 'blue'));
        if (!in_array($color, ['blue', 'sky', 'indigo', 'violet', 'emerald', 'amber', 'rose', 'slate'], true)) {
            $color = 'blue';
        }
        $rawSecret   = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', (string)($_POST['secret_plain'] ?? '')));
        $digits      = in_array((int)($_POST['digits'] ?? 6), [6, 8], true) ? (int)$_POST['digits'] : 6;
        $period      = in_array((int)($_POST['period'] ?? 30), [30, 60], true) ? (int)$_POST['period'] : 30;
        $algorithm   = strtoupper(trim((string)($_POST['algorithm'] ?? 'SHA1')));
        if (!in_array($algorithm, ['SHA1', 'SHA256', 'SHA512'], true)) {
            $algorithm = 'SHA1';
        }
        $plainNotes  = trim((string)($_POST['notes_plain'] ?? ''));
        $isFavorite  = isset($_POST['is_favorite']) && (string)$_POST['is_favorite'] === '1' ? 1 : 0;
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        $sharedUserId = null;
        $groupId      = null;
        if (str_starts_with($shareTarget, 'user:')) {
            $candidateUid = max(0, (int)substr($shareTarget, 5));
            if ($candidateUid > 0) {
                $stmtVis = $pdo->prepare('SELECT id FROM users WHERE id = :id AND allow_user_sharing = 1 LIMIT 1');
                $stmtVis->execute([':id' => $candidateUid]);
                if ($stmtVis->fetchColumn() !== false) {
                    $sharedUserId = $candidateUid;
                }
            }
        } elseif (str_starts_with($shareTarget, 'group:')) {
            $candidateGid = max(0, (int)substr($shareTarget, 6));
            if ($candidateGid > 0 && (isAdmin() || in_array($candidateGid, $groupIds, true))) {
                $groupId = $candidateGid;
            }
        }

        if ($issuer === '') {
            $issuer = $accountName !== '' ? $accountName : 'Unbenannter Dienst';
        }

        if ($entryId > 0 && $rawSecret === '') {
            // Beim Bearbeiten darf das Secret leer gelassen werden -> bestehendes behalten
            $stmtOld = $pdo->prepare('SELECT secret_encrypted FROM totp_accounts WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtOld->execute([':id' => $entryId, ':uid' => $userId]);
            $oldEnc = $stmtOld->fetchColumn();
            if (is_string($oldEnc) && $oldEnc !== '') {
                $rawSecret = decryptPasswordEntry($oldEnc);
            }
        }

        if ($rawSecret === '' || base32Decode($rawSecret) === '') {
            setFlash('error', 'Bitte gib einen gültigen Base32-Geheimschlüssel (Buchstaben A–Z und Ziffern 2–7) oder eine otpauth://-URI ein.');
            header('Location: authenticator.php');
            exit;
        }

        $encSecret = encryptPasswordEntry($rawSecret);
        $encNotes  = $plainNotes !== '' ? encryptPasswordEntry($plainNotes) : '';

        if ($entryId > 0) {
            $stmtUpd = $pdo->prepare('
                UPDATE totp_accounts
                SET issuer = :iss,
                    account_name = :acc,
                    category = :cat,
                    icon = :ic,
                    color = :col,
                    secret_encrypted = :sec,
                    digits = :dig,
                    period = :per,
                    algorithm = :alg,
                    notes_encrypted = :not,
                    is_favorite = :fav,
                    shared_user_id = :suid,
                    group_id = :gid
                WHERE id = :id AND user_id = :uid
            ');
            $stmtUpd->execute([
                ':iss'  => $issuer,
                ':acc'  => $accountName,
                ':cat'  => $category,
                ':ic'   => $icon,
                ':col'  => $color,
                ':sec'  => $encSecret,
                ':dig'  => $digits,
                ':per'  => $period,
                ':alg'  => $algorithm,
                ':not'  => $encNotes,
                ':fav'  => $isFavorite,
                ':suid' => $sharedUserId,
                ':gid'  => $groupId,
                ':id'   => $entryId,
                ':uid'  => $userId,
            ]);
            setFlash('success', '2FA-Account „' . $issuer . '“ wurde aktualisiert. 🔐');
        } else {
            $stmtIns = $pdo->prepare('
                INSERT INTO totp_accounts
                (user_id, issuer, account_name, category, icon, color, secret_encrypted, digits, period, algorithm, notes_encrypted, is_favorite, shared_user_id, group_id)
                VALUES (:uid, :iss, :acc, :cat, :ic, :col, :sec, :dig, :per, :alg, :not, :fav, :suid, :gid)
            ');
            $stmtIns->execute([
                ':uid'  => $userId,
                ':iss'  => $issuer,
                ':acc'  => $accountName,
                ':cat'  => $category,
                ':ic'   => $icon,
                ':col'  => $color,
                ':sec'  => $encSecret,
                ':dig'  => $digits,
                ':per'  => $period,
                ':alg'  => $algorithm,
                ':not'  => $encNotes,
                ':fav'  => $isFavorite,
                ':suid' => $sharedUserId,
                ':gid'  => $groupId,
            ]);
            logAuditEvent($pdo, 'totp_account_created', '2FA-Authenticator-Eintrag angelegt: ' . $issuer, $userId, $username);
            setFlash('success', '2FA-Account „' . $issuer . '“ wurde AES-256-GCM verschlüsselt gespeichert! ✅');
        }

        header('Location: authenticator.php');
        exit;
    }

    if ($action === 'import_otpauth_batch') {
        $batchText = trim((string)($_POST['otpauth_batch'] ?? ''));
        $lines     = preg_split('/\r\n|\r|\n/', $batchText) ?: [];
        $imported  = 0;

        $stmtIns = $pdo->prepare('
            INSERT INTO totp_accounts
            (user_id, issuer, account_name, category, icon, color, secret_encrypted, digits, period, algorithm, notes_encrypted, is_favorite)
            VALUES (:uid, :iss, :acc, :cat, :ic, :col, :sec, :dig, :per, :alg, "", 0)
        ');

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with(strtolower($line), 'otpauth://totp/')) {
                continue;
            }
            $parts = parse_url($line);
            if (!is_array($parts)) {
                continue;
            }
            $pathLabel = rawurldecode(ltrim((string)($parts['path'] ?? ''), '/'));
            parse_str((string)($parts['query'] ?? ''), $q);

            $sec = strtoupper((string)preg_replace('/[^A-Za-z2-7]/', '', (string)($q['secret'] ?? '')));
            if ($sec === '' || base32Decode($sec) === '') {
                continue;
            }

            $iss = trim((string)($q['issuer'] ?? ''));
            $acc = $pathLabel;
            if (str_contains($pathLabel, ':')) {
                [$labelIss, $labelAcc] = explode(':', $pathLabel, 2);
                if ($iss === '') {
                    $iss = trim($labelIss);
                }
                $acc = trim($labelAcc);
            }
            if ($iss === '') {
                $iss = $acc !== '' ? $acc : 'Importierter Dienst';
            }

            $dig = in_array((int)($q['digits'] ?? 6), [6, 8], true) ? (int)$q['digits'] : 6;
            $per = in_array((int)($q['period'] ?? 30), [30, 60], true) ? (int)$q['period'] : 30;
            $alg = strtoupper(trim((string)($q['algorithm'] ?? 'SHA1')));
            if (!in_array($alg, ['SHA1', 'SHA256', 'SHA512'], true)) {
                $alg = 'SHA1';
            }

            $stmtIns->execute([
                ':uid' => $userId,
                ':iss' => mb_substr($iss, 0, 140),
                ':acc' => mb_substr($acc, 0, 190),
                ':cat' => 'Allgemein',
                ':ic'  => '🔐',
                ':col' => 'blue',
                ':sec' => encryptPasswordEntry($sec),
                ':dig' => $dig,
                ':per' => $per,
                ':alg' => $alg,
            ]);
            $imported++;
        }

        if ($imported > 0) {
            setFlash('success', $imported . ' 2FA-Account(s) erfolgreich importiert! 🎉');
        } else {
            setFlash('warning', 'Es wurden keine gültigen otpauth://totp/-Zeilen zum Importieren gefunden.');
        }
        header('Location: authenticator.php');
        exit;
    }

    if ($action === 'toggle_favorite') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pdo->prepare('UPDATE totp_accounts SET is_favorite = IF(is_favorite = 1, 0, 1) WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $entryId, ':uid' => $userId]);
        header('Location: authenticator.php');
        exit;
    }

    if ($action === 'delete_totp') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pdo->prepare('DELETE FROM totp_accounts WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $entryId, ':uid' => $userId]);
        setFlash('success', '2FA-Account wurde gelöscht.');
        header('Location: authenticator.php');
        exit;
    }
}

$entries = fetchAccessibleTotpAccounts($pdo, $userId, $groupIds);
$nowTs   = time();
$rem30   = 30 - ($nowTs % 30);

$pageTitle = '2FA-Authenticator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich & Globale 30s-Countdown-Anzeige -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/70 border border-indigo-200/70 dark:border-indigo-800/70 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl shrink-0">
                🔢
            </div>
            <div>
                <div class="inline-flex items-center gap-2 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800 mb-1">
                    <span>🔒 AES-256-GCM Tresor</span>
                    <span>&middot;</span>
                    <span>RFC 6238 TOTP (Google Authenticator kompatibel)</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                    2FA-Authenticator &amp; Einmalcode-Studio
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Erzeugt alle 30 Sekunden deine 6- oder 8-stelligen Sicherheitscodes für Google, Microsoft, PayPal, Amazon, GitHub, Contabo &amp; Co. – inkl. QR-Scanner und Smartphone-Transfer.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            <!-- Live 30s Taktgeber (Server-Atomuhr synchronisiert) -->
            <div class="flex items-center gap-2.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700" title="Synchronisiert mit der NTP/PTB-Atomuhrzeit von runready.cloud (Europe/Berlin)">
                <div class="relative w-8 h-8 flex items-center justify-center">
                    <svg class="w-8 h-8 -rotate-90" viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15" fill="none" class="stroke-slate-300 dark:stroke-slate-700" stroke-width="3.5"></circle>
                        <circle id="globalTotpRing" cx="18" cy="18" r="15" fill="none" class="stroke-indigo-600 dark:stroke-indigo-400 transition-all duration-300" stroke-width="3.5" stroke-dasharray="94.25" stroke-dashoffset="<?= number_format(94.25 * (1 - $rem30 / 30), 2, '.', '') ?>" stroke-linecap="round"></circle>
                    </svg>
                    <span id="globalTotpSec" class="absolute text-[11px] font-mono font-extrabold text-slate-800 dark:text-slate-100"><?= $rem30 ?>s</span>
                </div>
                <div class="text-xs">
                    <div class="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span id="globalServerClock"><?= date('H:i:s', $nowTs) ?> Uhr</span>
                    </div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400">PTB-Atomuhr Sync aktiv</div>
                </div>
            </div>

            <button
                type="button"
                onclick="openBatchImportModal()"
                class="px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold text-xs sm:text-sm border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                title="Mehrere otpauth://-Links importieren oder eigene Schlüssel exportieren"
            >
                📥 Import / Backup
            </button>

            <button
                type="button"
                onclick="openTotpModal()"
                class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
            >
                ➕ Neuer 2FA-Code
            </button>
        </div>
    </div>

    <!-- Kopier-Toast-Banner (erscheint bei Klick auf einen Code) -->
    <div id="totpCopyToast" class="hidden fixed bottom-5 right-5 z-50 px-4 py-3 rounded-2xl bg-emerald-600 text-white text-xs sm:text-sm font-bold shadow-2xl flex items-center gap-2">
        <span>✅</span>
        <span id="totpCopyToastMsg">2FA-Code in die Zwischenablage kopiert!</span>
    </div>

    <!-- Such- und Kategorie-Filter -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div class="flex-1">
            <input
                type="search"
                id="totpSearchInput"
                oninput="filterTotpCards()"
                placeholder="🔍 Nach Dienst (z. B. Google, PayPal, Contabo), E-Mail/Benutzername oder Kategorie filtern…"
                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
            >
        </div>
        <div class="flex items-center gap-2">
            <select
                id="totpCategoryFilter"
                onchange="filterTotpCards()"
                class="w-full sm:w-auto px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200"
            >
                <option value="">Alle Kategorien (<?= count($entries) ?>)</option>
                <?php foreach ($categories as $catKey => $catLabel): ?>
                    <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <a href="passwords.php" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-bold text-slate-700 dark:text-slate-200 shrink-0" title="Zum Passwort-Schlüsselbund wechseln">
                🔐 Passwörter &rarr;
            </a>
        </div>
    </div>

    <!-- 2FA-Karten-Raster -->
    <?php if (empty($entries)): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center shadow-xs space-y-4">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800 flex items-center justify-center text-3xl">
                🔢
            </div>
            <div class="max-w-md mx-auto space-y-1.5">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Noch keine 2FA-Authenticator-Codes hinterlegt</h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                    Scanne einen 2FA-QR-Code per Kamera/Screenshot oder gib den Base32-Geheimschlüssel eines Dienstes ein. Die 6-stelligen Einmalcodes werden alle 30 Sekunden live berechnet.
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                <button
                    type="button"
                    onclick="openTotpModal()"
                    class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                >
                    ➕ Ersten 2FA-Account anlegen
                </button>
                <button
                    type="button"
                    onclick="openBatchImportModal()"
                    class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 font-semibold text-xs sm:text-sm transition-colors cursor-pointer"
                >
                    📥 otpauth://-Links importieren
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="totpGrid">
            <?php
            $exportOtpAuthLines = [];
            foreach ($entries as $item):
                $id          = (int)$item['id'];
                $isOwner     = ((int)$item['user_id'] === $userId);
                $isFav       = ((int)$item['is_favorite'] === 1);
                $issuer      = (string)$item['issuer'];
                $accountName = (string)$item['account_name'];
                $catKey      = (string)$item['category'];
                $icon        = (string)($item['icon'] ?: '🔐');
                $color       = (string)($item['color'] ?: 'blue');
                $digits      = max(6, (int)($item['digits'] ?: 6));
                $period      = max(15, (int)($item['period'] ?: 30));
                $algo        = (string)($item['algorithm'] ?: 'SHA1');

                $plainSecret = decryptPasswordEntry((string)$item['secret_encrypted']);
                $plainNotes  = !empty($item['notes_encrypted']) ? decryptPasswordEntry((string)$item['notes_encrypted']) : '';

                $curCode     = computeFlexibleTotpCode($plainSecret, $period, $digits, $algo, $nowTs);
                $nextCode    = computeFlexibleTotpCode($plainSecret, $period, $digits, $algo, $nowTs + $period);
                $remSec      = $period - ($nowTs % $period);

                $otpAuthUri  = buildFlexibleOtpAuthUri($issuer, $accountName, $plainSecret, $digits, $period, $algo);
                $qrSvg       = $isOwner ? renderQrCodeSvg($otpAuthUri, 200) : '';
                if ($isOwner) {
                    $exportOtpAuthLines[] = $otpAuthUri;
                }

                $searchBlob  = mb_strtolower($issuer . ' ' . $accountName . ' ' . $catKey . ' ' . $plainNotes);

                $itemEditJson = htmlspecialchars(json_encode([
                    'id'           => $id,
                    'issuer'       => $issuer,
                    'account_name' => $accountName,
                    'category'     => $catKey,
                    'icon'         => $icon,
                    'color'        => $color,
                    'digits'       => $digits,
                    'period'       => $period,
                    'algorithm'    => $algo,
                    'notes_plain'  => $plainNotes,
                    'is_favorite'  => $isFav ? 1 : 0,
                    'share_target' => !empty($item['shared_user_id'])
                        ? 'user:' . (int)$item['shared_user_id']
                        : (!empty($item['group_id']) ? 'group:' . (int)$item['group_id'] : ''),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
                <div
                    class="totp-card mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5 shadow-xs flex flex-col justify-between gap-3.5 transition-all hover:border-indigo-300 dark:hover:border-indigo-700"
                    data-totp-id="<?= $id ?>"
                    data-search="<?= e($searchBlob) ?>"
                    data-category="<?= e($catKey) ?>"
                >
                    <!-- Kopfzeile der Karte -->
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 flex items-center justify-center text-xl shrink-0">
                                <?= e($icon) ?>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                                        <?= e($issuer) ?>
                                    </h3>
                                    <?php if (!$isOwner): ?>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 shrink-0" title="Geteilt von <?= e((string)$item['owner_name']) ?>">
                                            👥 Geteilt
                                        </span>
                                    <?php elseif (!empty($item['group_name']) || !empty($item['shared_username'])): ?>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 shrink-0">
                                            🔗 Freigegeben
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-slate-500 dark:text-slate-400 truncate">
                                    <?= $accountName !== '' ? e($accountName) : e($catKey) ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($isOwner): ?>
                            <form method="post" action="authenticator.php" class="m-0 shrink-0">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <input type="hidden" name="entry_id" value="<?= $id ?>">
                                <button type="submit" class="text-base hover:scale-110 transition-transform cursor-pointer" title="<?= $isFav ? 'Aus Favoriten lösen' : 'Als Favorit oben anheften' ?>">
                                    <?= $isFav ? '⭐' : '☆' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Großes 1-Klick-TOTP-Display + Countdown -->
                    <div
                        onclick="copyTotpCode('<?= e($curCode) ?>', '<?= e($issuer) ?>', this)"
                        data-code-box="<?= $id ?>"
                        data-raw-code="<?= e($curCode) ?>"
                        class="group relative p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/80 hover:bg-indigo-50/70 dark:hover:bg-indigo-950/40 border border-slate-200/80 dark:border-slate-700/80 hover:border-indigo-300 dark:hover:border-indigo-700 transition-all cursor-pointer select-none flex items-center justify-between gap-3"
                        title="Klicken, um den Code sofort zu kopieren"
                    >
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                                📋 Klicken zum Kopieren
                            </div>
                            <div
                                data-code-display="<?= $id ?>"
                                class="text-2xl sm:text-3xl font-mono font-extrabold tracking-widest text-indigo-600 dark:text-indigo-400 mt-0.5"
                            >
                                <?= e(formatTotpDisplay($curCode)) ?>
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-200/70 dark:bg-slate-700 text-[11px] font-mono font-bold text-slate-700 dark:text-slate-200">
                                <span>⏱️</span>
                                <span data-rem-display="<?= $id ?>"><?= $remSec ?>s</span>
                            </div>
                            <div class="text-[11px] text-slate-400 dark:text-slate-500 mt-1 font-mono" title="Folgender Code für das nächste Zeitfenster">
                                Nächster: <strong data-next-display="<?= $id ?>" class="text-slate-600 dark:text-slate-300"><?= e(formatTotpDisplay($nextCode)) ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($plainNotes !== ''): ?>
                        <div class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50/60 dark:bg-slate-800/40 px-3 py-1.5 rounded-lg truncate" title="<?= e($plainNotes) ?>">
                            💡 <?= e($plainNotes) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Fußzeile mit Aktionen (Nächsten Code kopieren, Smartphone-QR, Bearbeiten, Löschen) -->
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                onclick="copyNextTotpCode(<?= $id ?>, '<?= e($issuer) ?>')"
                                class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold transition-colors cursor-pointer"
                                title="Kopiert direkt den nächsten Code (praktisch, wenn der aktuelle Code in wenigen Sekunden abläuft)"
                            >
                                ⏭️ Nächsten kopieren
                            </button>

                            <?php if ($isOwner && $qrSvg !== ''): ?>
                                <button
                                    type="button"
                                    onclick="document.getElementById('qrBox-<?= $id ?>').classList.toggle('hidden')"
                                    class="px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 font-semibold transition-colors cursor-pointer"
                                    title="QR-Code einblenden, um diesen Account zusätzlich mit dem Smartphone zu scannen"
                                >
                                    📱 Handy-QR
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($isOwner): ?>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onclick="openTotpModal(<?= $itemEditJson ?>)"
                                    class="px-2 py-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 cursor-pointer"
                                    title="Eintrag bearbeiten"
                                >
                                    ✏️
                                </button>
                                <form method="post" action="authenticator.php" class="m-0 inline" onsubmit="return confirm('Möchtest du den 2FA-Account „<?= e(addslashes($issuer)) ?>“ wirklich löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_totp">
                                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                                    <button type="submit" class="px-2 py-1 rounded-lg hover:bg-red-50 dark:hover:bg-red-950/50 text-red-600 dark:text-red-400 cursor-pointer" title="Löschen">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isOwner && $qrSvg !== ''): ?>
                        <!-- Ausklappbarer QR-Code für Smartphone-Transfer -->
                        <div id="qrBox-<?= $id ?>" class="hidden pt-3 border-t border-slate-200/80 dark:border-slate-800 text-center space-y-2">
                            <div class="text-[11px] font-bold text-slate-700 dark:text-slate-200">
                                📱 Mit Google Authenticator / 2FAS auf dem Smartphone scannen:
                            </div>
                            <div class="inline-block p-3 bg-white rounded-2xl border border-slate-200 shadow-xs">
                                <?= $qrSvg ?>
                            </div>
                            <div class="text-[10px] text-slate-400 font-mono break-all px-2">
                                Secret: <?= e($plainSecret) ?> (<?= e($algo) ?>, <?= $digits ?> Stellen, <?= $period ?>s)
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ======================================================================= -->
<!-- MODAL 1: NEUEN 2FA-ACCOUNT ANLEGEN / BEARBEITEN (INKL. QR-SCANNER)      -->
<!-- ======================================================================= -->
<div id="totpEntryModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-3 bg-slate-950/60 backdrop-blur-xs" onclick="if(event.target===this)closeTotpModal()">
    <div class="modal-box w-full max-w-xl max-h-[92vh] overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl p-5 sm:p-6 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <h2 id="totpModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                ➕ Neuen 2FA-Authenticator-Code einrichten
            </h2>
            <button type="button" onclick="closeTotpModal()" class="text-slate-400 hover:text-slate-700 dark:hover:text-white font-bold cursor-pointer">✕</button>
        </div>

        <!-- Schnell-Import: QR-Bild hochladen / Kamera scannen / otpauth:// einfügen -->
        <div class="p-3.5 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-800/70 space-y-2.5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-xs font-bold text-indigo-900 dark:text-indigo-200">
                    ⚡ Schnell-Erkennung (QR-Code-Bild oder `otpauth://`-Link)
                </span>
                <div class="flex items-center gap-1.5">
                    <label class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-[11px] font-bold cursor-pointer">
                        🖼️ QR-Screenshot wählen
                        <input type="file" accept="image/*" class="hidden" onchange="decodeTotpQrFromFile(this.files[0])">
                    </label>
                    <button type="button" onclick="toggleTotpCameraScanner()" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-[11px] font-bold cursor-pointer">
                        📷 Kamera-Scan
                    </button>
                </div>
            </div>

            <video id="totpQrVideo" class="hidden w-full max-h-52 rounded-xl bg-black object-cover" playsinline muted></video>
            <div id="totpScanStatus" class="hidden text-xs font-semibold text-emerald-600 dark:text-emerald-400"></div>

            <input
                type="text"
                id="otpauthQuickInput"
                oninput="parseOtpAuthUriIntoForm(this.value)"
                placeholder="Oder otpauth://totp/... Link hier einfügen (füllt alle Felder automatisch aus)"
                class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 text-slate-900 dark:text-white border border-indigo-200 dark:border-indigo-800 text-xs font-mono"
            >
        </div>

        <!-- Dienst-Schnellwahl-Pills -->
        <div>
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1.5">
                Beliebte Dienste (1-Klick-Vorlage):
            </div>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($servicePresets as $sp): ?>
                    <button
                        type="button"
                        onclick="applyTotpServicePreset('<?= e($sp['label']) ?>', '<?= e($sp['icon']) ?>', '<?= e($sp['cat']) ?>')"
                        class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                    >
                        <?= e($sp['icon']) ?> <?= e($sp['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="post" action="authenticator.php" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_totp">
            <input type="hidden" name="entry_id" id="totpEntryId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Icon</label>
                    <input type="text" name="icon" id="totpIcon" value="🔐" maxlength="4" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-center text-base">
                </div>
                <div class="sm:col-span-5">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dienst / Anbieter *</label>
                    <input type="text" name="issuer" id="totpIssuer" required placeholder="z. B. Google, PayPal, Contabo" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                </div>
                <div class="sm:col-span-5">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Konto / E-Mail-Adresse</label>
                    <input type="text" name="account_name" id="totpAccountName" placeholder="z. B. name@beispiel.de" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">
                        🔑 Geheimer 2FA-Schlüssel (Base32 Secret) *
                    </label>
                    <button
                        type="button"
                        onclick="generateRandomBase32InModal()"
                        class="text-[11px] font-bold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer"
                        title="Erzeugt einen neuen zufälligen 32-Zeichen-Base32-Schlüssel"
                    >
                        🎲 Zufälligen Schlüssel generieren
                    </button>
                </div>
                <input
                    type="text"
                    name="secret_plain"
                    id="totpSecretPlain"
                    placeholder="z. B. JBSWY3DPEHPK3PXP (Leerzeichen & Bindestriche werden automatisch entfernt)"
                    class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 font-mono text-xs sm:text-sm uppercase"
                >
                <p id="totpSecretEditHint" class="hidden text-[11px] text-slate-400 mt-1">
                    Leer lassen, um den bereits gespeicherten Geheimschlüssel unverändert beizubehalten.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Kategorie</label>
                    <select name="category" id="totpCategory" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        <?php foreach ($categories as $cKey => $cLab): ?>
                            <option value="<?= e($cKey) ?>"><?= e($cLab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Code-Länge &amp; Intervall</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <select name="digits" id="totpDigits" class="px-2 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="6">6 Stellen</option>
                            <option value="8">8 Stellen</option>
                        </select>
                        <select name="period" id="totpPeriod" class="px-2 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="30">30 Sek.</option>
                            <option value="60">60 Sek.</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Algorithmus</label>
                    <select name="algorithm" id="totpAlgorithm" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        <option value="SHA1">SHA1 (Standard)</option>
                        <option value="SHA256">SHA256</option>
                        <option value="SHA512">SHA512</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Notiz / Backup-Codes (optional)</label>
                    <input type="text" name="notes_plain" id="totpNotes" placeholder="z. B. Notfall-Wiederherstellungscode" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">👥 Mit Familie / Gruppe teilen</label>
                    <select name="share_target" id="totpShareTarget" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        <option value="">🔒 Nur für mich privat</option>
                        <?php if (!empty($userGroups)): ?>
                            <optgroup label="👥 Cloud-Gruppen">
                                <?php foreach ($userGroups as $grp): ?>
                                    <option value="group:<?= (int)$grp['id'] ?>">👥 Gruppe: <?= e((string)$grp['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($visibleUsers)): ?>
                            <optgroup label="👤 Einzelne Cloud-Mitglieder">
                                <?php foreach ($visibleUsers as $vu): ?>
                                    <option value="user:<?= (int)$vu['id'] ?>">👤 <?= e((string)$vu['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                <input type="checkbox" name="is_favorite" id="totpFavorite" value="1" class="rounded text-brand-600">
                <span>⭐ Als Favorit ganz oben anheften</span>
            </label>

            <div class="pt-3 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-2">
                <button type="button" onclick="closeTotpModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer">
                    💾 Verschlüsselt speichern
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 2: MASSEN-IMPORT & BACKUP-EXPORT (otpauth://totp/...)             -->
<!-- ======================================================================= -->
<div id="totpBatchModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-3 bg-slate-950/60 backdrop-blur-xs" onclick="if(event.target===this)closeBatchImportModal()">
    <div class="modal-box w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl p-5 sm:p-6 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                📥 2FA Massen-Import &amp; Backup-Export (`otpauth://`)
            </h2>
            <button type="button" onclick="closeBatchImportModal()" class="text-slate-400 hover:text-slate-700 dark:hover:text-white font-bold cursor-pointer">✕</button>
        </div>

        <form method="post" action="authenticator.php" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="import_otpauth_batch">
            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300">
                📥 Mehrere `otpauth://totp/...`-Zeilen importieren (eine URI pro Zeile):
            </label>
            <textarea
                name="otpauth_batch"
                rows="4"
                placeholder="otpauth://totp/Google:user@gmail.com?secret=JBSWY3DPEHPK3PXP&issuer=Google"
                class="w-full p-3 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 font-mono text-xs"
            ></textarea>
            <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold cursor-pointer">
                📥 Jetzt importieren
            </button>
        </form>

        <?php if (!empty($exportOtpAuthLines)): ?>
            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300">
                        📤 Backup deiner eigenen 2FA-Konten (<?= count($exportOtpAuthLines) ?>):
                    </span>
                    <button
                        type="button"
                        onclick="document.getElementById('totpExportBox').classList.toggle('hidden')"
                        class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline cursor-pointer"
                    >
                        🔓 Backup-Liste ein-/ausblenden
                    </button>
                </div>
                <div id="totpExportBox" class="hidden space-y-2">
                    <textarea
                        id="totpExportTextarea"
                        readonly
                        rows="5"
                        class="w-full p-3 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white border border-slate-300 dark:border-slate-700 font-mono text-[11px]"
                    ><?= e(implode("\n", $exportOtpAuthLines)) ?></textarea>
                    <button
                        type="button"
                        onclick="navigator.clipboard.writeText(document.getElementById('totpExportTextarea').value); alert('Backup-Liste in die Zwischenablage kopiert!');"
                        class="px-3.5 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-xs font-bold cursor-pointer"
                    >
                        📋 Alle Backup-URIs kopieren
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// =========================================================================
// LIVE-COUNTDOWN & AUTOMATISCHER HINTERGRUND-REFRESH ALLER TOTP-CODES
// (Synchronisiert mit der PTB-Atomuhrzeit des Servers statt Browser-Uhr)
// =========================================================================
let serverTimeOffsetMs = (<?= (int)$nowTs ?> * 1000) - Date.now();
function getSyncedServerUnixSec() {
    return Math.floor((Date.now() + serverTimeOffsetMs) / 1000);
}
let lastFetchedSlice = Math.floor(getSyncedServerUnixSec() / 30);
let isFetchingTotp   = false;

async function refreshAllTotpCodesAjax() {
    if (isFetchingTotp) return;
    isFetchingTotp = true;
    try {
        const res = await fetch('authenticator.php?ajax=codes', { cache: 'no-store' });
        const data = await res.json();
        if (data && data.ok) {
            if (typeof data.server_time === 'number' && data.server_time > 0) {
                serverTimeOffsetMs = (data.server_time * 1000) - Date.now();
            }
            if (Array.isArray(data.items)) {
                data.items.forEach(function(item) {
                    const box  = document.querySelector('[data-code-box="' + item.id + '"]');
                    const disp = document.querySelector('[data-code-display="' + item.id + '"]');
                    const next = document.querySelector('[data-next-display="' + item.id + '"]');
                    const rem  = document.querySelector('[data-rem-display="' + item.id + '"]');
                    if (box)  box.setAttribute('data-raw-code', item.code_raw);
                    if (disp) disp.textContent = item.code_fmt;
                    if (next) {
                        next.textContent = item.next_fmt;
                        next.setAttribute('data-next-raw', item.next_raw);
                    }
                    if (rem)  rem.textContent = item.remaining + 's';
                });
            }
        }
    } catch (e) {}
    isFetchingTotp = false;
}

setInterval(function() {
    const nowSec   = getSyncedServerUnixSec();
    const rem30    = 30 - (nowSec % 30);
    const curSlice = Math.floor(nowSec / 30);

    const clockEl = document.getElementById('globalServerClock');
    if (clockEl) {
        const d = new Date(nowSec * 1000);
        clockEl.textContent = d.toLocaleTimeString('de-DE', { timeZone: 'Europe/Berlin' }) + ' Uhr';
    }

    const secEl  = document.getElementById('globalTotpSec');
    const ringEl = document.getElementById('globalTotpRing');
    if (secEl) secEl.textContent = rem30 + 's';
    if (ringEl) {
        const offset = 94.25 * (1 - rem30 / 30);
        ringEl.setAttribute('stroke-dashoffset', offset.toFixed(2));
        if (rem30 <= 5) {
            ringEl.classList.remove('stroke-indigo-600', 'dark:stroke-indigo-400');
            ringEl.classList.add('stroke-rose-500');
        } else {
            ringEl.classList.remove('stroke-rose-500');
            ringEl.classList.add('stroke-indigo-600', 'dark:stroke-indigo-400');
        }
    }

    document.querySelectorAll('[data-rem-display]').forEach(function(el) {
        el.textContent = rem30 + 's';
    });

    if (curSlice !== lastFetchedSlice) {
        lastFetchedSlice = curSlice;
        refreshAllTotpCodesAjax();
    }
}, 1000);

function showTotpToast(msg) {
    const t = document.getElementById('totpCopyToast');
    const m = document.getElementById('totpCopyToastMsg');
    if (!t || !m) return;
    m.textContent = msg;
    t.classList.remove('hidden');
    clearTimeout(window._totpToastTimer);
    window._totpToastTimer = setTimeout(function() {
        t.classList.add('hidden');
    }, 2200);
}

function copyTotpCode(fallbackCode, issuer, boxEl) {
    const code = (boxEl && boxEl.getAttribute('data-raw-code')) ? boxEl.getAttribute('data-raw-code') : fallbackCode;
    navigator.clipboard.writeText(code).then(function() {
        showTotpToast('✅ Code ' + code + ' (' + issuer + ') kopiert!');
    });
}

function copyNextTotpCode(id, issuer) {
    const nextEl = document.querySelector('[data-next-display="' + id + '"]');
    if (!nextEl) return;
    const raw = (nextEl.getAttribute('data-next-raw') || nextEl.textContent || '').replace(/\s+/g, '');
    navigator.clipboard.writeText(raw).then(function() {
        showTotpToast('⏭️ Nächster Code ' + raw + ' (' + issuer + ') kopiert!');
    });
}

function filterTotpCards() {
    const q   = (document.getElementById('totpSearchInput')?.value || '').toLowerCase().trim();
    const cat = document.getElementById('totpCategoryFilter')?.value || '';
    document.querySelectorAll('.totp-card').forEach(function(card) {
        const s = card.getAttribute('data-search') || '';
        const c = card.getAttribute('data-category') || '';
        const matchQ = (q === '' || s.indexOf(q) !== -1);
        const matchC = (cat === '' || c === cat);
        card.style.display = (matchQ && matchC) ? '' : 'none';
    });
}

// =========================================================================
// MODAL, DIENST-VORLAGEN & OTPAUTH-QR-SCANNER
// =========================================================================
let totpCameraStream = null;

function openTotpModal(item) {
    const modal = document.getElementById('totpEntryModal');
    if (!modal) return;

    document.getElementById('otpauthQuickInput').value = '';
    const hint = document.getElementById('totpSecretEditHint');

    if (item && item.id) {
        document.getElementById('totpModalTitle').textContent = '✏️ 2FA-Account bearbeiten';
        document.getElementById('totpEntryId').value          = item.id;
        document.getElementById('totpIcon').value             = item.icon || '🔐';
        document.getElementById('totpIssuer').value           = item.issuer || '';
        document.getElementById('totpAccountName').value      = item.account_name || '';
        document.getElementById('totpSecretPlain').value      = '';
        document.getElementById('totpSecretPlain').required   = false;
        document.getElementById('totpCategory').value         = item.category || 'Allgemein';
        document.getElementById('totpDigits').value           = String(item.digits || 6);
        document.getElementById('totpPeriod').value           = String(item.period || 30);
        document.getElementById('totpAlgorithm').value        = item.algorithm || 'SHA1';
        document.getElementById('totpNotes').value            = item.notes_plain || '';
        document.getElementById('totpShareTarget').value      = item.share_target || '';
        document.getElementById('totpFavorite').checked       = Number(item.is_favorite) === 1;
        if (hint) hint.classList.remove('hidden');
    } else {
        document.getElementById('totpModalTitle').textContent = '➕ Neuen 2FA-Authenticator-Code einrichten';
        document.getElementById('totpEntryId').value          = '0';
        document.getElementById('totpIcon').value             = '🔐';
        document.getElementById('totpIssuer').value           = '';
        document.getElementById('totpAccountName').value      = '';
        document.getElementById('totpSecretPlain').value      = '';
        document.getElementById('totpSecretPlain').required   = true;
        document.getElementById('totpCategory').value         = 'Cloud & E-Mail';
        document.getElementById('totpDigits').value           = '6';
        document.getElementById('totpPeriod').value           = '30';
        document.getElementById('totpAlgorithm').value        = 'SHA1';
        document.getElementById('totpNotes').value            = '';
        document.getElementById('totpShareTarget').value      = '';
        document.getElementById('totpFavorite').checked       = false;
        if (hint) hint.classList.add('hidden');
    }

    modal.classList.remove('hidden');
}

function closeTotpModal() {
    stopTotpCameraScanner();
    const modal = document.getElementById('totpEntryModal');
    if (modal) modal.classList.add('hidden');
}

function openBatchImportModal() {
    document.getElementById('totpBatchModal')?.classList.remove('hidden');
}

function closeBatchImportModal() {
    document.getElementById('totpBatchModal')?.classList.add('hidden');
}

function applyTotpServicePreset(label, icon, cat) {
    document.getElementById('totpIssuer').value   = label;
    document.getElementById('totpIcon').value     = icon;
    document.getElementById('totpCategory').value = cat;
    document.getElementById('totpSecretPlain').focus();
}

function generateRandomBase32InModal() {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let out = '';
    const arr = new Uint8Array(32);
    window.crypto.getRandomValues(arr);
    for (let i = 0; i < 32; i++) {
        out += alphabet[arr[i] % 32];
    }
    document.getElementById('totpSecretPlain').value = out;
}

function parseOtpAuthUriIntoForm(rawUri) {
    const val = (rawUri || '').trim();
    if (!val.toLowerCase().startsWith('otpauth://')) return false;
    try {
        const u = new URL(val);
        let label = decodeURIComponent(u.pathname.replace(/^\/?(totp|hotp)\/?/i, ''));
        let issuer = u.searchParams.get('issuer') || '';
        let account = label;
        if (label.indexOf(':') !== -1) {
            const parts = label.split(':');
            if (!issuer) issuer = parts[0].trim();
            account = parts.slice(1).join(':').trim();
        }
        const secret = (u.searchParams.get('secret') || '').replace(/[^A-Za-z2-7]/g, '').toUpperCase();
        const digits = u.searchParams.get('digits') || '6';
        const period = u.searchParams.get('period') || '30';
        const algo   = (u.searchParams.get('algorithm') || 'SHA1').toUpperCase();

        if (issuer)  document.getElementById('totpIssuer').value      = issuer;
        if (account) document.getElementById('totpAccountName').value = account;
        if (secret)  document.getElementById('totpSecretPlain').value = secret;
        if (digits === '6' || digits === '8') document.getElementById('totpDigits').value = digits;
        if (period === '30' || period === '60') document.getElementById('totpPeriod').value = period;
        if (['SHA1', 'SHA256', 'SHA512'].includes(algo)) document.getElementById('totpAlgorithm').value = algo;

        const st = document.getElementById('totpScanStatus');
        if (st) {
            st.textContent = '✅ QR-Code / otpauth-Link erfolgreich ausgelesen: ' + (issuer || account);
            st.classList.remove('hidden');
        }
        return true;
    } catch (e) {
        return false;
    }
}

async function decodeTotpQrFromFile(file) {
    if (!file) return;
    const st = document.getElementById('totpScanStatus');
    if ('BarcodeDetector' in window) {
        try {
            const bmp = await createImageBitmap(file);
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const codes = await detector.detect(bmp);
            if (codes && codes.length > 0 && codes[0].rawValue) {
                document.getElementById('otpauthQuickInput').value = codes[0].rawValue;
                parseOtpAuthUriIntoForm(codes[0].rawValue);
                return;
            }
        } catch (e) {}
    }
    if (st) {
        st.textContent = 'ℹ️ Hinweis: Dein aktueller Browser unterstützt kein direktes QR-Bild-Decoding. Tipp: Klicke beim Einrichten des Dienstes auf „Code nicht scannbar / Schlüssel manuell eingeben“ und füge den Base32-Code unten ein.';
        st.classList.remove('hidden');
    }
}

async function toggleTotpCameraScanner() {
    const video = document.getElementById('totpQrVideo');
    const st    = document.getElementById('totpScanStatus');
    if (!video) return;

    if (totpCameraStream) {
        stopTotpCameraScanner();
        return;
    }

    if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (st) {
            st.textContent = 'ℹ️ Kamera-QR-Erkennung wird von diesem Browser nicht unterstützt – bitte gib den Base32-Schlüssel manuell ein.';
            st.classList.remove('hidden');
        }
        return;
    }

    try {
        totpCameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = totpCameraStream;
        video.classList.remove('hidden');
        await video.play();

        const detector = new BarcodeDetector({ formats: ['qr_code'] });
        const scanLoop = async function() {
            if (!totpCameraStream) return;
            try {
                const codes = await detector.detect(video);
                if (codes && codes.length > 0 && codes[0].rawValue) {
                    document.getElementById('otpauthQuickInput').value = codes[0].rawValue;
                    parseOtpAuthUriIntoForm(codes[0].rawValue);
                    stopTotpCameraScanner();
                    return;
                }
            } catch (e) {}
            requestAnimationFrame(scanLoop);
        };
        requestAnimationFrame(scanLoop);
    } catch (e) {
        if (st) {
            st.textContent = '⚠️ Kamerazugriff nicht möglich.';
            st.classList.remove('hidden');
        }
    }
}

function stopTotpCameraScanner() {
    const video = document.getElementById('totpQrVideo');
    if (totpCameraStream) {
        totpCameraStream.getTracks().forEach(t => t.stop());
        totpCameraStream = null;
    }
    if (video) {
        video.pause();
        video.srcObject = null;
        video.classList.add('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
