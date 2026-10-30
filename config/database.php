<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * MiniCloud - Datenbank- und Systemkonfiguration
 * Kompatibel mit PHP 8.2+ und MySQL / MariaDB (z.B. STRATO Webspace)
 */

// Lokale Datenbank-Zugangsdaten laden (werden durch setup.php erzeugt und nicht in Git versioniert)
$localCredsFile = __DIR__ . '/db_credentials.php';
$legacyLocalFile = __DIR__ . '/database.local.php';
if (is_file($localCredsFile)) {
    require_once $localCredsFile;
} elseif (is_file($legacyLocalFile)) {
    require_once $legacyLocalFile;
}

// Datenbank-Zugangsdaten (für STRATO z.B. DB_HOST = 'rdbms.strato.de')
$defaultSocket = file_exists('/tmp/minicloud_mysql/run/mysqld.sock') ? '/tmp/minicloud_mysql/run/mysqld.sock' : '';
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('MINICLOUD_DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', getenv('MINICLOUD_DB_PORT') ?: '3306');
}
if (!defined('DB_SOCKET')) {
    define('DB_SOCKET', getenv('MINICLOUD_DB_SOCKET') ?: $defaultSocket);
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('MINICLOUD_DB_NAME') ?: 'minicloud');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('MINICLOUD_DB_USER') ?: 'minicloud_user');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('MINICLOUD_DB_PASS') ?: 'minicloud_secret');
}

// Prüfen, ob bereits individuelle Verbindungsdaten hinterlegt wurden
if (!defined('DB_CREDENTIALS_CONFIGURED')) {
    $hasCredFile = is_file($localCredsFile) || is_file($legacyLocalFile);
    $hasEnvCreds = getenv('MINICLOUD_DB_HOST') !== false || getenv('MINICLOUD_DB_PASS') !== false;
    $hasCustomPass = (DB_PASS !== 'minicloud_secret' && DB_PASS !== '');
    define('DB_CREDENTIALS_CONFIGURED', $hasCredFile || $hasEnvCreds || $hasCustomPass || $defaultSocket !== '');
}

// Speicherplatz- & Papierkorb-Konfiguration
define('DEFAULT_STORAGE_QUOTA', 100 * 1024 * 1024); // 100 MB pro Benutzer (Standard)
define('MAX_FILE_SIZE', 1024 * 1024 * 1024);        // 1 GB maximale Einzeldateigröße
define('TRASH_RETENTION_DAYS', 30);                 // 30 Tage Aufbewahrung im Mülleimer
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');

// Standard-Zeitzone für alle Datums- und Zeitfunktionen
date_default_timezone_set('Europe/Berlin');

// Anwendungsname
define('APP_NAME', 'MiniCloud');

/**
 * Leitet bei einer Erstinstallation ohne hinterlegte Datenbank-Zugangsdaten automatisch zum Setup-Assistenten weiter.
 */
function redirectToSetupIfUnconfigured(string $reason = ''): never
{
    if (PHP_SAPI !== 'cli') {
        $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $acceptHeader  = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhrHeader     = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        $isJsonReq     = str_contains($acceptHeader, 'application/json') || strcasecmp($xhrHeader, 'XMLHttpRequest') === 0;

        if (!DB_CREDENTIALS_CONFIGURED && $currentScript !== 'setup.php') {
            if ($isJsonReq) {
                http_response_code(503);
                header('Content-Type: application/json; charset=UTF-8');
                exit((string)json_encode([
                    'error'    => 'Erstinstallation erforderlich. Bitte richten Sie zuerst die Datenbankverbindung ein.',
                    'redirect' => 'setup.php',
                ]));
            }
            header('Location: setup.php');
            exit;
        }
    }

    if ($reason !== '') {
        error_log('Datenbankverbindungsfehler: ' . $reason);
    }
    http_response_code(503);
    exit('Es konnte momentan keine Verbindung zur Datenbank hergestellt werden. Bitte versuchen Sie es in wenigen Augenblicken erneut oder prüfen Sie die Datenbank-Konfiguration.');
}

/**
 * Erstellt und gibt eine Singleton-PDO-Datenbankverbindung zurück.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Falls noch keinerlei Datenbank-Zugangsdaten hinterlegt sind -> direkt zum Installations-Assistenten
    if (!DB_CREDENTIALS_CONFIGURED) {
        redirectToSetupIfUnconfigured('Keine Datenbank-Zugangsdaten konfiguriert (Erstinstallation).');
    }

    if (DB_SOCKET !== '') {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', DB_SOCKET, DB_NAME);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        ensureSchemaUpdated($pdo);
    } catch (PDOException $e) {
        redirectToSetupIfUnconfigured($e->getMessage());
    }

    return $pdo;
}

/**
 * Stellt sicher, dass alle Tabellen und Erweiterungsspalten (Shares, Gruppenordner, Themes, Admin-Rolle)
 * in der Datenbank vorhanden sind und mindestens ein Administrator existiert.
 */
function ensureSchemaUpdated(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $targetSchemaVersion = '2026.10.30.2';
    try {
        $stmtVer = $pdo->prepare("SELECT `setting_value` FROM `settings` WHERE `setting_key` = 'schema_version' LIMIT 1");
        $stmtVer->execute();
        $installedSchemaVer = (string)($stmtVer->fetchColumn() ?: '');
        if ($installedSchemaVer === $targetSchemaVersion) {
            purgeExpiredTrashFiles($pdo, false);
            return;
        }
    } catch (Throwable) {
        // Tabelle settings existiert noch nicht -> vollständiges Schema-Update ausführen
    }

    // Basis-Tabellen anlegen, falls die Datenbank noch komplett leer ist (Zero-Click-Installation)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(64) NOT NULL,
            `email` VARCHAR(190) DEFAULT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `totp_secret` VARCHAR(64) DEFAULT NULL,
            `two_factor_code_hash` VARCHAR(255) DEFAULT NULL,
            `two_factor_expires_at` DATETIME DEFAULT NULL,
            `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `reset_token_hash` VARCHAR(255) DEFAULT NULL,
            `reset_expires_at` DATETIME DEFAULT NULL,
            `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `theme` VARCHAR(24) NOT NULL DEFAULT 'light',
            `session_timeout` INT UNSIGNED NOT NULL DEFAULT 30,
            `flash_duration` INT UNSIGNED NOT NULL DEFAULT 10,
            `chat_notify_mode` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `allow_user_sharing` TINYINT(1) NOT NULL DEFAULT 0,
            `calendar_token` VARCHAR(64) DEFAULT NULL,
            `contacts_token` VARCHAR(64) DEFAULT NULL,
            `storage_quota` BIGINT UNSIGNED NOT NULL DEFAULT 104857600,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_users_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Prüfen, ob Erweiterungsspalten in users existieren
    $colsUsers = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) DEFAULT NULL AFTER `username`");
    }
    if (!in_array('two_factor_enabled', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`");
    }
    if (!in_array('totp_secret', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) DEFAULT NULL AFTER `two_factor_enabled`");
    }
    if (!in_array('two_factor_code_hash', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_code_hash` VARCHAR(255) DEFAULT NULL AFTER `two_factor_enabled`");
    }
    if (!in_array('two_factor_expires_at', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_expires_at` DATETIME DEFAULT NULL AFTER `two_factor_code_hash`");
    }
    if (!in_array('two_factor_attempts', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `two_factor_expires_at`");
    }
    if (!in_array('reset_token_hash', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_token_hash` VARCHAR(255) DEFAULT NULL AFTER `two_factor_attempts`");
    }
    if (!in_array('reset_expires_at', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_expires_at` DATETIME DEFAULT NULL AFTER `reset_token_hash`");
    }
    if (!in_array('is_admin', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`");
    }
    if (!in_array('theme', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `theme` VARCHAR(24) NOT NULL DEFAULT 'light' AFTER `is_admin`");
    }
    if (!in_array('session_timeout', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `session_timeout` INT UNSIGNED NOT NULL DEFAULT 30 AFTER `theme`");
    }
    if (!in_array('flash_duration', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `flash_duration` INT UNSIGNED NOT NULL DEFAULT 10 AFTER `session_timeout`");
    }
    if (!in_array('chat_notify_mode', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `chat_notify_mode` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `flash_duration`");
    }
    if (!in_array('allow_user_sharing', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `allow_user_sharing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `session_timeout`");
    }
    if (!in_array('calendar_token', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `calendar_token` VARCHAR(64) DEFAULT NULL AFTER `allow_user_sharing`");
    }
    if (!in_array('contacts_token', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `contacts_token` VARCHAR(64) DEFAULT NULL AFTER `calendar_token`");
    }

    // Neue Tabellen anlegen, falls noch nicht vorhanden
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_groups_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `group_members` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_group_user` (`group_id`, `user_id`),
            KEY `idx_gm_user` (`user_id`),
            CONSTRAINT `fk_gm_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `group_folders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_group_folders_name` (`name`),
            CONSTRAINT `fk_gf_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `group_folder_permissions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_folder_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `can_write` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_gfp_folder` (`group_folder_id`),
            KEY `idx_gfp_user` (`user_id`),
            KEY `idx_gfp_group` (`group_id`),
            CONSTRAINT `fk_gfp_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gfp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gfp_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `files` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `group_folder_id` INT UNSIGNED DEFAULT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `folder_path` VARCHAR(512) NOT NULL DEFAULT '',
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `file_hash` VARCHAR(64) DEFAULT NULL,
            `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
            `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
            `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
            `upload_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_files_user_id` (`user_id`),
            KEY `idx_files_deleted_at` (`deleted_at`),
            KEY `idx_files_group_folder` (`group_folder_id`),
            KEY `idx_files_user_folder` (`user_id`, `folder_path`(191)),
            KEY `idx_files_hash` (`file_hash`),
            CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_files_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Prüfen, ob Spalte group_folder_id, file_hash, is_encrypted und deleted_at in files existieren
    $colsFiles = $pdo->query("SHOW COLUMNS FROM `files`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('group_folder_id', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `group_folder_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`");
        $pdo->exec("ALTER TABLE `files` ADD KEY `idx_files_group_folder` (`group_folder_id`)");
        $pdo->exec("ALTER TABLE `files` ADD CONSTRAINT `fk_files_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE");
    }
    if (!in_array('file_hash', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `file_hash` VARCHAR(64) DEFAULT NULL AFTER `file_size`");
        $pdo->exec("ALTER TABLE `files` ADD KEY `idx_files_hash` (`file_hash`)");
    }
    if (!in_array('is_encrypted', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mime_type`");
    }
    if (!in_array('is_favorite', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `is_favorite` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_encrypted`");
    }
    if (!in_array('deleted_at', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `upload_date`");
        $pdo->exec("ALTER TABLE `files` ADD KEY `idx_files_deleted_at` (`deleted_at`)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `shares` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` INT UNSIGNED DEFAULT NULL,
            `folder_path` VARCHAR(500) DEFAULT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `share_token` VARCHAR(64) NOT NULL,
            `share_mode` VARCHAR(20) NOT NULL DEFAULT 'read',
            `password_hash` VARCHAR(255) DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
            `max_downloads` INT UNSIGNED DEFAULT NULL,
            `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_downloaded_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_shares_token` (`share_token`),
            KEY `idx_shares_file_id` (`file_id`),
            KEY `idx_shares_user_id` (`user_id`),
            CONSTRAINT `fk_shares_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_shares_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsShares = $pdo->query("SHOW COLUMNS FROM `shares`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('folder_path', $colsShares, true)) {
        $pdo->exec("ALTER TABLE `shares` MODIFY COLUMN `file_id` INT UNSIGNED DEFAULT NULL");
        $pdo->exec("ALTER TABLE `shares` ADD COLUMN `folder_path` VARCHAR(500) DEFAULT NULL AFTER `file_id`");
    }
    if (!in_array('share_mode', $colsShares, true)) {
        $pdo->exec("ALTER TABLE `shares` ADD COLUMN `share_mode` VARCHAR(20) NOT NULL DEFAULT 'read' AFTER `share_token`");
    }
    if (!in_array('max_downloads', $colsShares, true)) {
        $pdo->exec("ALTER TABLE `shares` ADD COLUMN `max_downloads` INT UNSIGNED DEFAULT NULL AFTER `expires_at`");
    }
    if (!in_array('download_count', $colsShares, true)) {
        $pdo->exec("ALTER TABLE `shares` ADD COLUMN `download_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `max_downloads`");
    }
    if (!in_array('last_downloaded_at', $colsShares, true)) {
        $pdo->exec("ALTER TABLE `shares` ADD COLUMN `last_downloaded_at` DATETIME DEFAULT NULL AFTER `download_count`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_shares` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` INT UNSIGNED DEFAULT NULL,
            `folder_path` VARCHAR(500) DEFAULT NULL,
            `owner_id` INT UNSIGNED NOT NULL,
            `shared_with_user_id` INT UNSIGNED NOT NULL,
            `can_write` TINYINT(1) NOT NULL DEFAULT 0,
            `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_user_shares_unique` (`file_id`, `shared_with_user_id`),
            KEY `idx_user_shares_recipient` (`shared_with_user_id`),
            CONSTRAINT `fk_us_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_us_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_us_recipient` FOREIGN KEY (`shared_with_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsUserShares = $pdo->query("SHOW COLUMNS FROM `user_shares`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('folder_path', $colsUserShares, true)) {
        $pdo->exec("ALTER TABLE `user_shares` MODIFY COLUMN `file_id` INT UNSIGNED DEFAULT NULL");
        $pdo->exec("ALTER TABLE `user_shares` ADD COLUMN `folder_path` VARCHAR(500) DEFAULT NULL AFTER `file_id`");
    }
    if (!in_array('can_write', $colsUserShares, true)) {
        $pdo->exec("ALTER TABLE `user_shares` ADD COLUMN `can_write` TINYINT(1) NOT NULL DEFAULT 0 AFTER `shared_with_user_id`");
    }
    if (!in_array('can_delete', $colsUserShares, true)) {
        $pdo->exec("ALTER TABLE `user_shares` ADD COLUMN `can_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_write`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_folders` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `group_folder_id` INT UNSIGNED DEFAULT NULL,
            `folder_path` VARCHAR(500) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_uf_user_gf` (`user_id`, `group_folder_id`),
            KEY `idx_uf_path` (`folder_path`(191)),
            CONSTRAINT `fk_uf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_uf_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` MEDIUMTEXT DEFAULT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `calendar_events` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `location` VARCHAR(190) DEFAULT NULL,
            `start_at` DATETIME NOT NULL,
            `end_at` DATETIME NOT NULL,
            `all_day` TINYINT(1) NOT NULL DEFAULT 0,
            `recurrence` VARCHAR(20) NOT NULL DEFAULT 'none',
            `color` VARCHAR(24) NOT NULL DEFAULT '#2563eb',
            `group_id` INT UNSIGNED DEFAULT NULL,
            `group_can_edit` TINYINT(1) NOT NULL DEFAULT 0,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `attached_file_id` INT UNSIGNED DEFAULT NULL,
            `is_private` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ce_user` (`user_id`),
            KEY `idx_ce_dates` (`start_at`, `end_at`),
            KEY `idx_ce_group` (`group_id`),
            KEY `idx_ce_shared_user` (`shared_user_id`),
            CONSTRAINT `fk_ce_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ce_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_ce_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_ce_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `calendar_shares` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `owner_user_id` INT UNSIGNED NOT NULL,
            `target_user_id` INT UNSIGNED DEFAULT NULL,
            `target_group_id` INT UNSIGNED DEFAULT NULL,
            `can_edit` TINYINT(1) NOT NULL DEFAULT 0,
            `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cs_owner` (`owner_user_id`),
            KEY `idx_cs_target_user` (`target_user_id`),
            KEY `idx_cs_target_group` (`target_group_id`),
            CONSTRAINT `fk_cs_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cs_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cs_target_group` FOREIGN KEY (`target_group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsCalendarShares = $pdo->query("SHOW COLUMNS FROM `calendar_shares`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('can_delete', $colsCalendarShares, true)) {
        $pdo->exec("ALTER TABLE `calendar_shares` ADD COLUMN `can_delete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_edit`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `messages` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `sender_id` INT UNSIGNED NOT NULL,
            `recipient_id` INT UNSIGNED DEFAULT NULL,
            `is_broadcast` TINYINT(1) NOT NULL DEFAULT 0,
            `message_text` TEXT NOT NULL,
            `attached_file_id` INT UNSIGNED DEFAULT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_msg_sender` (`sender_id`),
            KEY `idx_msg_recipient` (`recipient_id`, `is_read`),
            KEY `idx_msg_broadcast` (`is_broadcast`, `id`),
            CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_msg_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_msg_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsMessages = $pdo->query("SHOW COLUMNS FROM `messages`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('attached_file_id', $colsMessages, true)) {
        $pdo->exec("ALTER TABLE `messages` ADD COLUMN `attached_file_id` INT UNSIGNED DEFAULT NULL AFTER `message_text`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `message_broadcast_reads` (
            `user_id` INT UNSIGNED NOT NULL,
            `last_read_broadcast_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_mbr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `event_type` VARCHAR(50) NOT NULL,
            `description` VARCHAR(500) NOT NULL,
            `ip_address` VARCHAR(64) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_created` (`created_at`),
            KEY `idx_audit_type` (`event_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `file_versions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `stored_filename` VARCHAR(255) NOT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mime_type` VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
            `version_note` VARCHAR(190) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_fv_file` (`file_id`, `id`),
            KEY `idx_fv_user` (`user_id`),
            CONSTRAINT `fk_fv_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_fv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `file_comments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `comment_text` VARCHAR(1000) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_fc_file` (`file_id`, `id`),
            CONSTRAINT `fk_fc_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_fc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `kanban_boards` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(150) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `icon` VARCHAR(16) NOT NULL DEFAULT '📋',
            `color` VARCHAR(24) NOT NULL DEFAULT 'brand',
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `share_token` VARCHAR(64) DEFAULT NULL,
            `share_mode` VARCHAR(16) NOT NULL DEFAULT 'disabled',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_kb_token` (`share_token`),
            KEY `idx_kb_user` (`user_id`),
            KEY `idx_kb_shared_user` (`shared_user_id`),
            KEY `idx_kb_group` (`group_id`),
            CONSTRAINT `fk_kb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_kb_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_kb_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tasks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `board_id` INT UNSIGNED DEFAULT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'todo',
            `priority` VARCHAR(16) NOT NULL DEFAULT 'medium',
            `due_date` DATE DEFAULT NULL,
            `checklist_json` TEXT DEFAULT NULL,
            `attached_file_id` INT UNSIGNED DEFAULT NULL,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tasks_user` (`user_id`, `status`),
            KEY `idx_tasks_board` (`board_id`),
            KEY `idx_tasks_shared` (`shared_user_id`),
            KEY `idx_tasks_group` (`group_id`),
            CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tasks_board` FOREIGN KEY (`board_id`) REFERENCES `kanban_boards` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tasks_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_tasks_shared` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_tasks_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsTasks = $pdo->query("SHOW COLUMNS FROM `tasks`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('board_id', $colsTasks, true)) {
        $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `board_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`");
        $pdo->exec("ALTER TABLE `tasks` ADD KEY `idx_tasks_board` (`board_id`)");
        $pdo->exec("ALTER TABLE `tasks` ADD CONSTRAINT `fk_tasks_board` FOREIGN KEY (`board_id`) REFERENCES `kanban_boards` (`id`) ON DELETE CASCADE");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL DEFAULT '',
            `content` TEXT NOT NULL,
            `color` VARCHAR(24) NOT NULL DEFAULT 'amber',
            `tags` VARCHAR(255) NOT NULL DEFAULT '',
            `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `audio_file_id` INT UNSIGNED DEFAULT NULL,
            `audio_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notes_user` (`user_id`, `is_pinned`),
            KEY `idx_notes_shared` (`shared_user_id`),
            KEY `idx_notes_group` (`group_id`),
            KEY `idx_notes_audio` (`audio_file_id`),
            CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_notes_shared` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_notes_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_notes_audio` FOREIGN KEY (`audio_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsNotes = $pdo->query("SHOW COLUMNS FROM `notes`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('audio_file_id', $colsNotes, true)) {
        $pdo->exec("ALTER TABLE `notes` ADD COLUMN `audio_file_id` INT UNSIGNED DEFAULT NULL AFTER `is_pinned`");
        $pdo->exec("ALTER TABLE `notes` ADD COLUMN `audio_duration_sec` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `audio_file_id`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `contacts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `first_name` VARCHAR(100) NOT NULL DEFAULT '',
            `last_name` VARCHAR(100) NOT NULL DEFAULT '',
            `organization` VARCHAR(150) NOT NULL DEFAULT '',
            `role_title` VARCHAR(120) NOT NULL DEFAULT '',
            `email` VARCHAR(190) NOT NULL DEFAULT '',
            `phone` VARCHAR(80) NOT NULL DEFAULT '',
            `mobile` VARCHAR(80) NOT NULL DEFAULT '',
            `street` VARCHAR(190) NOT NULL DEFAULT '',
            `postal_code` VARCHAR(30) NOT NULL DEFAULT '',
            `city` VARCHAR(100) NOT NULL DEFAULT '',
            `country` VARCHAR(100) NOT NULL DEFAULT '',
            `website` VARCHAR(255) NOT NULL DEFAULT '',
            `birthday` DATE DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `vcard_uid` VARCHAR(120) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_contacts_user` (`user_id`, `last_name`),
            KEY `idx_contacts_shared` (`shared_user_id`),
            KEY `idx_contacts_group` (`group_id`),
            KEY `idx_contacts_uid` (`user_id`, `vcard_uid`),
            CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_contacts_shared` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_contacts_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $colsContacts = $pdo->query("SHOW COLUMNS FROM `contacts`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('vcard_uid', $colsContacts, true)) {
        $pdo->exec("ALTER TABLE `contacts` ADD COLUMN `vcard_uid` VARCHAR(120) DEFAULT NULL AFTER `group_id`");
        $pdo->exec("ALTER TABLE `contacts` ADD KEY `idx_contacts_uid` (`user_id`, `vcard_uid`)");
    }
    if (!in_array('updated_at', $colsContacts, true)) {
        $pdo->exec("ALTER TABLE `contacts` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // CalDAV-Erweiterung für calendar_events (ical_uid & updated_at)
    $colsCalEvents = $pdo->query("SHOW COLUMNS FROM `calendar_events`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ical_uid', $colsCalEvents, true)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `ical_uid` VARCHAR(190) DEFAULT NULL AFTER `is_private`");
        $pdo->exec("ALTER TABLE `calendar_events` ADD KEY `idx_ce_ical_uid` (`user_id`, `ical_uid`)");
    }
    if (!in_array('updated_at', $colsCalEvents, true)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // Terminfinder & Umfragen (polls, poll_options, poll_votes)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `polls` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `location` VARCHAR(190) DEFAULT NULL,
            `poll_type` VARCHAR(20) NOT NULL DEFAULT 'date',
            `allow_maybe` TINYINT(1) NOT NULL DEFAULT 1,
            `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
            `share_token` VARCHAR(64) NOT NULL,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_polls_token` (`share_token`),
            KEY `idx_polls_user` (`user_id`),
            KEY `idx_polls_shared` (`shared_user_id`),
            KEY `idx_polls_group` (`group_id`),
            CONSTRAINT `fk_polls_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_polls_shared` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_polls_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `poll_options` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `poll_id` INT UNSIGNED NOT NULL,
            `option_label` VARCHAR(255) NOT NULL,
            `start_at` DATETIME DEFAULT NULL,
            `end_at` DATETIME DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_po_poll` (`poll_id`, `sort_order`),
            CONSTRAINT `fk_po_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `poll_votes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `poll_id` INT UNSIGNED NOT NULL,
            `option_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `voter_name` VARCHAR(100) NOT NULL,
            `vote_value` VARCHAR(16) NOT NULL DEFAULT 'yes',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pv_poll` (`poll_id`, `option_id`),
            KEY `idx_pv_voter` (`poll_id`, `voter_name`),
            CONSTRAINT `fk_pv_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pv_option` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Passwort-Manager / Schlüsselbund (passwords)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `passwords` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `category` VARCHAR(80) NOT NULL DEFAULT 'Allgemein',
            `username_login` VARCHAR(190) NOT NULL DEFAULT '',
            `password_encrypted` TEXT NOT NULL,
            `website_url` VARCHAR(255) NOT NULL DEFAULT '',
            `notes_encrypted` TEXT DEFAULT NULL,
            `is_favorite` TINYINT(1) NOT NULL DEFAULT 0,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `password_changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pw_user` (`user_id`, `category`),
            KEY `idx_pw_shared` (`shared_user_id`),
            KEY `idx_pw_group` (`group_id`),
            CONSTRAINT `fk_pw_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pw_shared` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_pw_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Separate App-Passwörter für WebDAV, CardDAV & CalDAV (app_passwords)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_passwords` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `label` VARCHAR(120) NOT NULL,
            `token_prefix` VARCHAR(16) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `last_used_at` DATETIME DEFAULT NULL,
            `last_used_service` VARCHAR(40) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ap_user` (`user_id`),
            CONSTRAINT `fk_ap_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Live-Mentimeter / Interaktive Präsentationen (menti_sessions, menti_slides, menti_responses)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menti_sessions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `pin_code` VARCHAR(12) NOT NULL,
            `public_token` VARCHAR(64) NOT NULL,
            `active_slide_id` INT UNSIGNED DEFAULT NULL,
            `show_results` TINYINT(1) NOT NULL DEFAULT 1,
            `voting_locked` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_menti_pin` (`pin_code`),
            UNIQUE KEY `idx_menti_token` (`public_token`),
            KEY `idx_menti_user` (`user_id`),
            CONSTRAINT `fk_menti_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menti_slides` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` INT UNSIGNED NOT NULL,
            `slide_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `slide_type` VARCHAR(24) NOT NULL DEFAULT 'choice',
            `question` VARCHAR(300) NOT NULL,
            `subtitle` VARCHAR(300) DEFAULT NULL,
            `options_json` MEDIUMTEXT DEFAULT NULL,
            `correct_option` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mslide_session` (`session_id`, `slide_order`),
            CONSTRAINT `fk_mslide_session` FOREIGN KEY (`session_id`) REFERENCES `menti_sessions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `menti_responses` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `session_id` INT UNSIGNED NOT NULL,
            `slide_id` INT UNSIGNED NOT NULL,
            `participant_token` VARCHAR(64) NOT NULL,
            `participant_name` VARCHAR(80) DEFAULT NULL,
            `answer_value` TEXT NOT NULL,
            `points` INT NOT NULL DEFAULT 0,
            `upvotes` INT UNSIGNED NOT NULL DEFAULT 0,
            `upvoted_by_json` MEDIUMTEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_mresp_slide` (`slide_id`),
            KEY `idx_mresp_session` (`session_id`),
            KEY `idx_mresp_participant` (`slide_id`, `participant_token`),
            CONSTRAINT `fk_mresp_session` FOREIGN KEY (`session_id`) REFERENCES `menti_sessions` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_mresp_slide` FOREIGN KEY (`slide_id`) REFERENCES `menti_slides` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Live-Team-Zusammenarbeit (Stufe 1: Anwesenheit, Absatz-/Zellen-Sperre & Delta-Sync in office.php)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `office_collab_presence` (
            `file_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `username` VARCHAR(64) NOT NULL,
            `focus_target` VARCHAR(120) NOT NULL DEFAULT '',
            `focus_label` VARCHAR(120) NOT NULL DEFAULT '',
            `color_hex` VARCHAR(16) NOT NULL DEFAULT '#3b82f6',
            `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`file_id`, `user_id`),
            KEY `idx_ocp_file_seen` (`file_id`, `last_seen`),
            CONSTRAINT `fk_ocp_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ocp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `office_collab_deltas` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `username` VARCHAR(64) NOT NULL,
            `delta_type` VARCHAR(32) NOT NULL,
            `target_key` VARCHAR(120) NOT NULL DEFAULT '',
            `payload_json` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ocd_file_id` (`file_id`, `id`),
            CONSTRAINT `fk_ocd_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ocd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Projektmanagement-Modul (projects, project_milestones, project_entries)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `projects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `code` VARCHAR(24) NOT NULL DEFAULT '',
            `title` VARCHAR(180) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `icon` VARCHAR(16) NOT NULL DEFAULT '🚀',
            `color` VARCHAR(24) NOT NULL DEFAULT 'brand',
            `status` VARCHAR(24) NOT NULL DEFAULT 'active',
            `start_date` DATE DEFAULT NULL,
            `end_date` DATE DEFAULT NULL,
            `budget_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `budget_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `kanban_board_id` INT UNSIGNED DEFAULT NULL,
            `group_folder_id` INT UNSIGNED DEFAULT NULL,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `share_token` VARCHAR(64) DEFAULT NULL,
            `share_mode` VARCHAR(16) NOT NULL DEFAULT 'disabled',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_proj_token` (`share_token`),
            KEY `idx_proj_user` (`user_id`, `status`),
            KEY `idx_proj_shared_user` (`shared_user_id`),
            KEY `idx_proj_group` (`group_id`),
            CONSTRAINT `fk_proj_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_proj_board` FOREIGN KEY (`kanban_board_id`) REFERENCES `kanban_boards` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_proj_gfolder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_proj_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_proj_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `project_milestones` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `start_date` DATE DEFAULT NULL,
            `due_date` DATE DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'open',
            `assigned_user_id` INT UNSIGNED DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pm_project` (`project_id`, `due_date`),
            CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pm_assigned` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `project_entries` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `entry_type` VARCHAR(20) NOT NULL DEFAULT 'time',
            `entry_date` DATE NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `hours` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            `cost_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `milestone_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pe_project` (`project_id`, `entry_date`),
            CONSTRAINT `fk_pe_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pe_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pe_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Foto-Alben & Medien-Galerie (gallery_albums, gallery_album_items)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_albums` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(180) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `cover_file_id` INT UNSIGNED DEFAULT NULL,
            `event_date` DATE DEFAULT NULL,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `share_token` VARCHAR(64) DEFAULT NULL,
            `password_hash` VARCHAR(255) DEFAULT NULL,
            `allow_guest_upload` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_ga_token` (`share_token`),
            KEY `idx_ga_user` (`user_id`),
            KEY `idx_ga_group` (`group_id`),
            KEY `idx_ga_shared` (`shared_user_id`),
            CONSTRAINT `fk_ga_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_album_items` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `album_id` INT UNSIGNED NOT NULL,
            `file_id` INT UNSIGNED NOT NULL,
            `caption` VARCHAR(255) NOT NULL DEFAULT '',
            `added_by_name` VARCHAR(100) NOT NULL DEFAULT '',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_gai_unique` (`album_id`, `file_id`),
            KEY `idx_gai_file` (`file_id`),
            CONSTRAINT `fk_gai_album` FOREIGN KEY (`album_id`) REFERENCES `gallery_albums` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gai_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Online-Formulare & Veranstaltungs-Anmeldung (forms, form_submissions)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `forms` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `category` VARCHAR(32) NOT NULL DEFAULT 'event',
            `fields_json` MEDIUMTEXT NOT NULL,
            `share_token` VARCHAR(64) NOT NULL,
            `allow_guests` TINYINT(1) NOT NULL DEFAULT 1,
            `max_submissions` INT UNSIGNED NOT NULL DEFAULT 0,
            `closes_at` DATE DEFAULT NULL,
            `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
            `shared_user_id` INT UNSIGNED DEFAULT NULL,
            `group_id` INT UNSIGNED DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_forms_token` (`share_token`),
            KEY `idx_forms_user` (`user_id`),
            KEY `idx_forms_group` (`group_id`),
            CONSTRAINT `fk_forms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `form_submissions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `form_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `submitter_name` VARCHAR(120) NOT NULL DEFAULT '',
            `submitter_email` VARCHAR(190) NOT NULL DEFAULT '',
            `answers_json` MEDIUMTEXT NOT NULL,
            `imported_to_club` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_fsub_form` (`form_id`, `created_at`),
            CONSTRAINT `fk_fsub_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Sicherstellen, dass mindestens ein Administrator existiert
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE `is_admin` = 1")->fetchColumn();
    if ($adminCount === 0) {
        $firstUserId = $pdo->query("SELECT `id` FROM `users` ORDER BY `id` ASC LIMIT 1")->fetchColumn();
        if ($firstUserId !== false) {
            $stmtPromote = $pdo->prepare("UPDATE `users` SET `is_admin` = 1 WHERE `id` = :id");
            $stmtPromote->execute([':id' => (int)$firstUserId]);
        } else {
            // Standard-Admin anlegen, falls die Datenbank noch komplett leer ist
            $defaultAdminHash = password_hash('Admin1234!', PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtSeed = $pdo->prepare(
                "INSERT INTO `users` (`username`, `password_hash`, `is_admin`, `theme`, `storage_quota`)
                 VALUES ('admin', :hash, 1, 'light', :quota)"
            );
            $stmtSeed->execute([
                ':hash'  => $defaultAdminHash,
                ':quota' => DEFAULT_STORAGE_QUOTA * 5,
            ]);
        }
    }

    try {
        $stmtSaveVer = $pdo->prepare(
            "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('schema_version', :ver)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
        );
        $stmtSaveVer->execute([':ver' => $targetSchemaVersion]);
    } catch (Throwable) {
    }

    // Automatische Bereinigung: Dateien aus dem Papierkorb nach Ablauf der Aufbewahrungsfrist endgültig löschen
    purgeExpiredTrashFiles($pdo, false);
}

/**
 * Entfernt automatisch alle Dateien aus dem Mülleimer, deren Löschdatum länger als die konfigurierte
 * Aufbewahrungsfrist (`trash_retention_days` in settings, Standard: 30 Tage; 0 = deaktiviert) zurückliegt.
 */
function purgeExpiredTrashFiles(PDO $pdo, bool $force = false): int
{
    $days = (int)TRASH_RETENTION_DAYS;
    try {
        $stmtCfg = $pdo->query(
            "SELECT `setting_key`, `setting_value` FROM `settings`
             WHERE `setting_key` IN ('trash_retention_days', 'last_trash_purge_ts')"
        );
        $cfgRows = $stmtCfg ? $stmtCfg->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        if (isset($cfgRows['trash_retention_days']) && is_numeric($cfgRows['trash_retention_days'])) {
            $days = max(0, min(3650, (int)$cfgRows['trash_retention_days']));
        }
        if ($days === 0) {
            return 0; // Automatische Papierkorb-Bereinigung durch Admin deaktiviert
        }
        $lastPurgeTs = isset($cfgRows['last_trash_purge_ts']) ? (int)$cfgRows['last_trash_purge_ts'] : 0;
        if (!$force && $lastPurgeTs > 0 && (time() - $lastPurgeTs) < 3600) {
            return 0; // Höchstens 1x pro Stunde automatisch prüfen
        }
        $stmtUpdTs = $pdo->prepare(
            "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('last_trash_purge_ts', :ts)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
        );
        $stmtUpdTs->execute([':ts' => (string)time()]);
    } catch (Throwable) {
    }

    $stmtExpired = $pdo->query(
        "SELECT id, filename FROM `files`
         WHERE `deleted_at` IS NOT NULL
           AND `deleted_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );
    $expiredRows = $stmtExpired->fetchAll();

    if (empty($expiredRows)) {
        return 0;
    }

    $stmtVersions = $pdo->prepare("SELECT `stored_filename` FROM `file_versions` WHERE `file_id` = :fid");
    $stmtDelete = $pdo->prepare("DELETE FROM `files` WHERE `id` = :id");
    $purgedCount = 0;
    $thumbDir = UPLOAD_DIR . '.thumbs/';

    foreach ($expiredRows as $row) {
        $fid = (int)$row['id'];
        $stmtVersions->execute([':fid' => $fid]);
        foreach ($stmtVersions->fetchAll(PDO::FETCH_COLUMN) as $vFile) {
            $vPath = UPLOAD_DIR . basename((string)$vFile);
            if (is_file($vPath)) {
                @unlink($vPath);
            }
        }
        $physicalPath = UPLOAD_DIR . basename((string)$row['filename']);
        if (is_file($physicalPath)) {
            @unlink($physicalPath);
        }
        if (is_dir($thumbDir)) {
            foreach (glob($thumbDir . 'thumb_' . $fid . '_*') ?: [] as $tFile) {
                if (is_file($tFile)) {
                    @unlink($tFile);
                }
            }
        }
        $stmtDelete->execute([':id' => $fid]);
        $purgedCount++;
    }

    return $purgedCount;
}
