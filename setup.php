<?php
declare(strict_types=1);

/**
 * MiniCloud – Erstinstallations-Assistent (Web-Installer)
 * Prüft Systemvoraussetzungen, fragt die Datenbank-Verbindungsdaten ab,
 * legt config/db_credentials.php an und initialisiert das Datenbankschema.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$credFile        = __DIR__ . '/config/db_credentials.php';
$legacyLocalFile = __DIR__ . '/config/database.local.php';
$isAlreadyConfigured = is_file($credFile) || is_file($legacyLocalFile) || (defined('DB_CREDENTIALS_CONFIGURED') && DB_CREDENTIALS_CONFIGURED);

if (empty($_SESSION['setup_csrf_token'])) {
    $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['setup_csrf_token'];

/**
 * HTML-Escaping für das Setup-Skript.
 */
function setupEscape(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Baut testweise eine PDO-Verbindung mit den übergebenen Zugangsdaten auf.
 *
 * @return array{ok: bool, pdo?: PDO, message: string, server_version?: string, db_created?: bool}
 */
function testSetupDatabaseConnection(
    string $host,
    int $port,
    string $dbName,
    string $dbUser,
    string $dbPass,
    bool $autoCreateDb = true
): array {
    if (!extension_loaded('pdo_mysql')) {
        return [
            'ok'      => false,
            'message' => 'Die PHP-Erweiterung pdo_mysql ist auf diesem Server nicht aktiviert.',
        ];
    }

    if ($host === '' || $dbName === '' || $dbUser === '') {
        return [
            'ok'      => false,
            'message' => 'Bitte füllen Sie Datenbank-Host, Datenbank-Name und Datenbank-Benutzer aus.',
        ];
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $dbName)) {
        return [
            'ok'      => false,
            'message' => 'Der Datenbank-Name enthält ungültige Sonderzeichen.',
        ];
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 8,
    ];

    $dsnWithDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $dbCreated = false;

    try {
        $pdo = new PDO($dsnWithDb, $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $errCode = (int)$e->getCode();
        $errMsg  = $e->getMessage();

        // Fehler 1049 = Unknown database -> versuche Datenbank anzulegen, falls gewünscht
        if ($autoCreateDb && ($errCode === 1049 || str_contains($errMsg, '1049') || stripos($errMsg, 'Unknown database') !== false)) {
            try {
                $dsnNoDb = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
                $pdoRoot = new PDO($dsnNoDb, $dbUser, $dbPass, $options);
                $safeDb  = str_replace('`', '``', $dbName);
                $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdoRoot = null;

                $pdo = new PDO($dsnWithDb, $dbUser, $dbPass, $options);
                $dbCreated = true;
            } catch (PDOException $eCreate) {
                return [
                    'ok'      => false,
                    'message' => 'Die Datenbank „' . $dbName . '“ existiert noch nicht und konnte nicht automatisch angelegt werden (' . $eCreate->getMessage() . '). Bitte legen Sie die Datenbank im Kundenmenü Ihres Hosters (z. B. STRATO) an.',
                ];
            }
        } else {
            return [
                'ok'      => false,
                'message' => 'Datenbankverbindung fehlgeschlagen: ' . $errMsg,
            ];
        }
    }

    $serverVersion = '';
    try {
        $serverVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable) {
        $serverVersion = 'MySQL / MariaDB';
    }

    return [
        'ok'             => true,
        'pdo'            => $pdo,
        'server_version' => $serverVersion,
        'db_created'     => $dbCreated,
        'message'        => sprintf(
            'Verbindung erfolgreich hergestellt (%s)%s!',
            $serverVersion !== '' ? $serverVersion : 'MySQL/MariaDB',
            $dbCreated ? ' – Datenbank wurde automatisch neu angelegt' : ''
        ),
    ];
}

// --- AJAX-Verbindungstest (bevor installiert wird) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    header('Content-Type: application/json; charset=UTF-8');

    if ($isAlreadyConfigured) {
        http_response_code(403);
        exit((string)json_encode(['ok' => false, 'message' => 'MiniCloud ist bereits fertig eingerichtet.']));
    }

    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedCsrf)) {
        http_response_code(400);
        exit((string)json_encode(['ok' => false, 'message' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.']));
    }

    $dbHost   = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort   = max(1, min(65535, (int)($_POST['db_port'] ?? 3306)));
    $dbName   = trim((string)($_POST['db_name'] ?? ''));
    $dbUser   = trim((string)($_POST['db_user'] ?? ''));
    $dbPass   = (string)($_POST['db_pass'] ?? '');
    $createDb = !empty($_POST['create_db']);

    $res = testSetupDatabaseConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $createDb);
    unset($res['pdo']);
    exit((string)json_encode($res));
}

// System-Checks durchführen
$phpVersionOk   = version_compare(PHP_VERSION, '8.1.0', '>=');
$pdoMysqlOk     = extension_loaded('pdo_mysql');
$mbstringOk     = extension_loaded('mbstring');
$zipOk          = class_exists('ZipArchive');
$configDir      = __DIR__ . '/config';
$uploadsDir     = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0750, true);
}
$configWritable  = is_dir($configDir) && is_writable($configDir);
$uploadsWritable = is_dir($uploadsDir) && is_writable($uploadsDir);
$allCriticalOk   = $phpVersionOk && $pdoMysqlOk && $configWritable;

// Formular-Standardwerte
$formHost       = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
$formPort       = trim((string)($_POST['db_port'] ?? '3306'));
$formName       = trim((string)($_POST['db_name'] ?? 'minicloud'));
$formUser       = trim((string)($_POST['db_user'] ?? ''));
$formPass       = (string)($_POST['db_pass'] ?? '');
$formCreateDb   = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['create_db']) : true;
$formAppTitle   = trim((string)($_POST['app_title'] ?? 'MiniCloud'));
$formAdminUser  = trim((string)($_POST['admin_user'] ?? 'admin'));
$formAdminEmail = trim((string)($_POST['admin_email'] ?? ''));
$formAdminPass  = (string)($_POST['admin_pass'] ?? '');

$errors = [];

// --- Vollständige Installation durchführen ---
if (!$isAlreadyConfigured && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedCsrf)) {
        $errors[] = 'Ungültiges Sicherheits-Token (CSRF). Bitte laden Sie die Seite neu.';
    }

    if (!$allCriticalOk) {
        $errors[] = 'Bitte beheben Sie zuerst die rot markierten Systemvoraussetzungen (insb. Schreibrechte für config/).';
    }

    $portInt = max(1, min(65535, (int)$formPort));
    if ($formHost === '' || $formName === '' || $formUser === '') {
        $errors[] = 'Bitte geben Sie Datenbank-Host, Datenbank-Name und Datenbank-Benutzer an.';
    }

    if ($formAdminUser === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $formAdminUser)) {
        $errors[] = 'Der Administrator-Benutzername muss zwischen 3 und 50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Bindestrich, Unterstrich).';
    }

    if ($formAdminEmail !== '' && filter_var($formAdminEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Bitte geben Sie eine gültige Administrator-E-Mail-Adresse ein (oder lassen Sie das Feld leer).';
    }

    if (strlen($formAdminPass) < 8) {
        $errors[] = 'Das Administrator-Passwort muss aus Sicherheitsgründen mindestens 8 Zeichen lang sein.';
    }

    if (empty($errors)) {
        $connResult = testSetupDatabaseConnection($formHost, $portInt, $formName, $formUser, $formPass, $formCreateDb);
        if (!$connResult['ok'] || !isset($connResult['pdo'])) {
            $errors[] = $connResult['message'];
        } else {
            /** @var PDO $pdo */
            $pdo = $connResult['pdo'];

            // 1. Konfigurationsdatei config/db_credentials.php schreiben
            $credContent = "<?php\n"
                . "declare(strict_types=1);\n\n"
                . "/**\n"
                . " * MiniCloud – Lokale Datenbank-Zugangsdaten\n"
                . " * Erstellt durch setup.php am " . date('d.m.Y H:i:s') . "\n"
                . " */\n"
                . "define('DB_HOST', " . var_export($formHost, true) . ");\n"
                . "define('DB_PORT', " . var_export((string)$portInt, true) . ");\n"
                . "define('DB_NAME', " . var_export($formName, true) . ");\n"
                . "define('DB_USER', " . var_export($formUser, true) . ");\n"
                . "define('DB_PASS', " . var_export($formPass, true) . ");\n";

            if (@file_put_contents($credFile, $credContent) === false) {
                $errors[] = 'Die Datei config/db_credentials.php konnte nicht gespeichert werden. Bitte prüfen Sie die Schreibrechte des Ordners config/.';
            } else {
                @chmod($credFile, 0640);

                try {
                    // 2. Datenbankschema und alle Tabellen anlegen
                    ensureSchemaUpdated($pdo);

                    // 3. Gewünschten Cloud-Namen speichern
                    if ($formAppTitle !== '') {
                        $stmtTitle = $pdo->prepare(
                            "INSERT INTO `settings` (`setting_key`, `setting_value`)
                             VALUES ('app_title', :val)
                             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
                        );
                        $stmtTitle->execute([':val' => $formAppTitle]);
                    }

                    // 4. Ersten Administrator-Account mit den gewählten Zugangsdaten einrichten
                    $adminHash = password_hash($formAdminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $emailVal  = $formAdminEmail !== '' ? $formAdminEmail : null;

                    // Prüfen, ob nur der automatisch von ensureSchemaUpdated() erzeugte Standard-'admin' existiert
                    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
                    $stmtFindUser = $pdo->prepare("SELECT `id` FROM `users` WHERE `username` = :u LIMIT 1");
                    $stmtFindUser->execute([':u' => $formAdminUser]);
                    $existingId = $stmtFindUser->fetchColumn();

                    if ($existingId !== false) {
                        $stmtUpdAdmin = $pdo->prepare(
                            "UPDATE `users`
                             SET `password_hash` = :hash,
                                 `email` = COALESCE(:email, `email`),
                                 `is_admin` = 1
                             WHERE `id` = :id"
                        );
                        $stmtUpdAdmin->execute([
                            ':hash'  => $adminHash,
                            ':email' => $emailVal,
                            ':id'    => (int)$existingId,
                        ]);
                    } elseif ($totalUsers === 1) {
                        // Den gerade beim Schema-Init angelegten Standard-'admin' auf den Wunsch-Namen & Wunsch-Passwort umstellen
                        $stmtUpdDefault = $pdo->prepare(
                            "UPDATE `users`
                             SET `username` = :u,
                                 `email` = :email,
                                 `password_hash` = :hash,
                                 `is_admin` = 1
                              WHERE `username` = 'admin'
                             LIMIT 1"
                        );
                        $stmtUpdDefault->execute([
                            ':u'     => $formAdminUser,
                            ':email' => $emailVal,
                            ':hash'  => $adminHash,
                        ]);
                    } else {
                        $stmtInsAdmin = $pdo->prepare(
                            "INSERT INTO `users` (`username`, `email`, `password_hash`, `is_admin`, `theme`, `storage_quota`)
                             VALUES (:u, :email, :hash, 1, 'light', :quota)"
                        );
                        $stmtInsAdmin->execute([
                            ':u'     => $formAdminUser,
                            ':email' => $emailVal,
                            ':hash'  => $adminHash,
                            ':quota' => DEFAULT_STORAGE_QUOTA * 5,
                        ]);
                    }

                    unset($_SESSION['setup_csrf_token']);
                    $_SESSION['flashes'][] = [
                        'type'    => 'success',
                        'message' => '🎉 Erstinstallation erfolgreich abgeschlossen! Sie können sich jetzt mit Ihrem Administrator-Konto „' . $formAdminUser . '“ anmelden.',
                    ];

                    header('Location: login.php');
                    exit;
                } catch (Throwable $eSchema) {
                    @unlink($credFile);
                    $errors[] = 'Fehler beim Initialisieren der Datenbanktabellen: ' . $eSchema->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erstinstallation – MiniCloud Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                        }
                    }
                }
            }
        };
    </script>
</head>
<body class="min-h-full bg-slate-100 text-slate-800 antialiased py-8 px-4 sm:px-6">
    <div class="max-w-2xl mx-auto">
        <!-- Kopfbereich -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-600 text-white shadow-md mb-3 text-3xl">
                ☁️
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">MiniCloud – Erstinstallation</h1>
            <p class="text-sm text-slate-600 mt-1">
                Datenbankverbindung &amp; Administrator-Zugang in wenigen Sekunden einrichten
            </p>
        </div>

        <?php if ($isAlreadyConfigured): ?>
            <!-- Bereits installiert Sperr-Hinweis -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 text-center">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-emerald-100 text-emerald-700 text-2xl mb-4">
                    ✅
                </div>
                <h2 class="text-xl font-bold text-slate-900 mb-2">MiniCloud ist bereits eingerichtet</h2>
                <p class="text-sm text-slate-600 max-w-md mx-auto mb-6">
                    Es sind bereits Datenbank-Verbindungsdaten hinterlegt. Aus Sicherheitsgründen ist der Installations-Assistent im laufenden Betrieb gesperrt.
                </p>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs text-slate-600 text-left mb-6">
                    <strong>Hinweis für Administratoren:</strong> Wenn Sie die Datenbank-Verbindungsdaten neu einrichten möchten, löschen Sie bitte per FTP/SSH die Datei <code class="px-1.5 py-0.5 rounded bg-slate-200 text-slate-800 font-mono">config/db_credentials.php</code> auf dem Server und rufen Sie diese Seite erneut auf.
                </div>
                <a href="login.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold shadow-xs transition">
                    🔐 Weiter zur Anmeldung
                </a>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="mb-6 rounded-2xl bg-red-50 border border-red-200 p-4 text-sm text-red-800 shadow-xs">
                    <div class="font-semibold mb-1">⚠️ Bitte prüfen Sie folgende Angaben:</div>
                    <ul class="list-disc list-inside space-y-1 text-xs sm:text-sm">
                        <?php foreach ($errors as $err): ?>
                            <li><?= setupEscape($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Schritt 1: Systemvoraussetzungen -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 mb-6">
                <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-2">
                    <span>1. Systemvoraussetzungen</span>
                    <?php if ($allCriticalOk): ?>
                        <span class="px-2 py-0.5 text-[11px] rounded-full bg-emerald-100 text-emerald-700 font-semibold">Alles bereit</span>
                    <?php else: ?>
                        <span class="px-2 py-0.5 text-[11px] rounded-full bg-red-100 text-red-700 font-semibold">Aktion erforderlich</span>
                    <?php endif; ?>
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $phpVersionOk ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-red-50 border-red-200 text-red-900' ?>">
                        <span class="font-medium">PHP-Version (mind. 8.1)</span>
                        <span class="font-bold"><?= $phpVersionOk ? '✅ ' . setupEscape(PHP_VERSION) : '❌ ' . setupEscape(PHP_VERSION) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $pdoMysqlOk ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-red-50 border-red-200 text-red-900' ?>">
                        <span class="font-medium">PDO MySQL-Treiber</span>
                        <span class="font-bold"><?= $pdoMysqlOk ? '✅ Aktiv' : '❌ Fehlt' ?></span>
                    </div>
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $configWritable ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-red-50 border-red-200 text-red-900' ?>">
                        <span class="font-medium">Ordner <code class="font-mono">config/</code> beschreibbar</span>
                        <span class="font-bold"><?= $configWritable ? '✅ Ja' : '❌ Nein (CHMOD prüfen)' ?></span>
                    </div>
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $uploadsWritable ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
                        <span class="font-medium">Ordner <code class="font-mono">uploads/</code> beschreibbar</span>
                        <span class="font-bold"><?= $uploadsWritable ? '✅ Ja' : '⚠️ Bitte prüfen' ?></span>
                    </div>
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $mbstringOk ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
                        <span class="font-medium">PHP <code class="font-mono">mbstring</code></span>
                        <span class="font-bold"><?= $mbstringOk ? '✅ Aktiv' : '⚠️ Empfohlen' ?></span>
                    </div>
                    <div class="flex items-center justify-between p-2.5 rounded-xl border <?= $zipOk ? 'bg-emerald-50/50 border-emerald-200 text-emerald-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
                        <span class="font-medium">PHP <code class="font-mono">ZipArchive</code> (Updates)</span>
                        <span class="font-bold"><?= $zipOk ? '✅ Aktiv' : '⚠️ Optional' ?></span>
                    </div>
                </div>
            </div>

            <!-- Schritt 2 & 3: Installations-Formular -->
            <form method="post" action="setup.php" id="setupForm" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-7 space-y-6">
                <input type="hidden" name="csrf_token" value="<?= setupEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="install">

                <!-- Datenbank-Verbindungsdaten -->
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                            2. Datenbank-Verbindungsdaten (MySQL / MariaDB)
                        </h2>
                        <div class="flex items-center gap-1.5 text-[11px]">
                            <span class="text-slate-400">Schnellauswahl:</span>
                            <button type="button" onclick="setHostPreset('127.0.0.1', '3306')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium">127.0.0.1</button>
                            <button type="button" onclick="setHostPreset('localhost', '3306')" class="px-2 py-0.5 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium">localhost</button>
                            <button type="button" onclick="setHostPreset('rdbms.strato.de', '3306')" class="px-2 py-0.5 rounded bg-blue-50 hover:bg-blue-100 text-blue-700 font-medium border border-blue-200">STRATO</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Datenbank-Host *</label>
                            <input type="text" name="db_host" id="db_host" required value="<?= setupEscape($formHost) ?>" placeholder="z. B. 127.0.0.1 oder rdbms.strato.de"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Port *</label>
                            <input type="number" name="db_port" id="db_port" required value="<?= setupEscape($formPort) ?>" min="1" max="65535"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Datenbank-Name (DB_NAME) *</label>
                            <input type="text" name="db_name" id="db_name" required value="<?= setupEscape($formName) ?>" placeholder="z. B. minicloud oder dbs123456"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Datenbank-Benutzer (DB_USER) *</label>
                            <input type="text" name="db_user" id="db_user" required value="<?= setupEscape($formUser) ?>" placeholder="z. B. minicloud_user oder dbu123456"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Datenbank-Passwort (DB_PASS)</label>
                        <div class="relative">
                            <input type="password" name="db_pass" id="db_pass" value="<?= setupEscape($formPass) ?>" placeholder="Passwort des Datenbank-Benutzers"
                                   class="w-full px-3.5 py-2 pr-16 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                            <button type="button" onclick="togglePw('db_pass', this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-500 hover:text-slate-800 px-2 py-1">
                                Zeigen
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
                            <input type="checkbox" name="create_db" id="create_db" value="1" <?= $formCreateDb ? 'checked' : '' ?> class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span>Datenbank automatisch anlegen, falls sie noch nicht existiert</span>
                        </label>
                        <button type="button" onclick="testConnectionAjax()" id="btnTestConn"
                                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">
                            🔌 Verbindung testen
                        </button>
                    </div>

                    <!-- Live-Feedback für den Verbindungstest -->
                    <div id="connTestResult" class="hidden mt-3 p-3 rounded-xl text-xs font-medium border"></div>
                </div>

                <hr class="border-slate-200">

                <!-- Erster Administrator & Cloud-Name -->
                <div>
                    <h2 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-3">
                        3. Cloud-Name &amp; Erster Administrator-Zugang
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Name der Cloud</label>
                            <input type="text" name="app_title" value="<?= setupEscape($formAppTitle) ?>" placeholder="MiniCloud"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Admin-Benutzername *</label>
                            <input type="text" name="admin_user" required value="<?= setupEscape($formAdminUser) ?>" placeholder="admin"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Admin-E-Mail (optional, für 2FA)</label>
                            <input type="email" name="admin_email" value="<?= setupEscape($formAdminEmail) ?>" placeholder="admin@beispiel.de"
                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Admin-Passwort (mind. 8 Zeichen) *</label>
                            <div class="relative">
                                <input type="password" name="admin_pass" id="admin_pass" required minlength="8" value="<?= setupEscape($formAdminPass) ?>" placeholder="Sicheres Passwort wählen"
                                       class="w-full px-3.5 py-2 pr-16 rounded-xl border border-slate-300 text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none">
                                <button type="button" onclick="togglePw('admin_pass', this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-500 hover:text-slate-800 px-2 py-1">
                                    Zeigen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Absenden -->
                <div class="pt-2 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <p class="text-[11px] text-slate-500">
                        Die Zugangsdaten werden geschützt in <code class="font-mono">config/db_credentials.php</code> gespeichert und bei künftigen Updates nicht überschrieben.
                    </p>
                    <button type="submit" <?= !$allCriticalOk ? 'disabled' : '' ?>
                            class="w-full sm:w-auto whitespace-nowrap inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold shadow-sm transition">
                        🚀 Jetzt installieren &amp; starten
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function setHostPreset(host, port) {
            document.getElementById('db_host').value = host;
            document.getElementById('db_port').value = port;
        }

        function togglePw(fieldId, btn) {
            const el = document.getElementById(fieldId);
            if (!el) return;
            if (el.type === 'password') {
                el.type = 'text';
                btn.textContent = 'Verbergen';
            } else {
                el.type = 'password';
                btn.textContent = 'Zeigen';
            }
        }

        async function testConnectionAjax() {
            const box = document.getElementById('connTestResult');
            const btn = document.getElementById('btnTestConn');
            box.classList.remove('hidden', 'bg-emerald-50', 'border-emerald-200', 'text-emerald-800', 'bg-red-50', 'border-red-200', 'text-red-800');
            box.classList.add('bg-slate-50', 'border-slate-200', 'text-slate-600');
            box.textContent = '⏳ Prüfe Verbindung zum Datenbank-Server ...';
            btn.disabled = true;

            const fd = new FormData();
            fd.append('action', 'test_connection');
            fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
            fd.append('db_host', document.getElementById('db_host').value);
            fd.append('db_port', document.getElementById('db_port').value);
            fd.append('db_name', document.getElementById('db_name').value);
            fd.append('db_user', document.getElementById('db_user').value);
            fd.append('db_pass', document.getElementById('db_pass').value);
            if (document.getElementById('create_db').checked) {
                fd.append('create_db', '1');
            }

            try {
                const resp = await fetch('setup.php', { method: 'POST', body: fd });
                const data = await resp.json();
                box.classList.remove('bg-slate-50', 'border-slate-200', 'text-slate-600');
                if (data.ok) {
                    box.classList.add('bg-emerald-50', 'border-emerald-200', 'text-emerald-800');
                    box.textContent = '✅ ' + data.message;
                } else {
                    box.classList.add('bg-red-50', 'border-red-200', 'text-red-800');
                    box.textContent = '❌ ' + (data.message || 'Verbindung fehlgeschlagen.');
                }
            } catch (e) {
                box.classList.remove('bg-slate-50', 'border-slate-200', 'text-slate-600');
                box.classList.add('bg-red-50', 'border-red-200', 'text-red-800');
                box.textContent = '❌ Netzwerk- oder Serverfehler beim Verbindungstest.';
            } finally {
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
