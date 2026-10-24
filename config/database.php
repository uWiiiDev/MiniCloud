<?php
declare(strict_types=1);

/**
 * MiniCloud - Datenbank- und Systemkonfiguration
 * Kompatibel mit PHP 8.2+ und MySQL / MariaDB (z.B. STRATO Webspace)
 */

// Datenbank-Zugangsdaten (für STRATO z.B. DB_HOST = 'rdbms.strato.de')
$defaultSocket = file_exists('/tmp/minicloud_mysql/run/mysqld.sock') ? '/tmp/minicloud_mysql/run/mysqld.sock' : '';
define('DB_HOST', getenv('MINICLOUD_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('MINICLOUD_DB_PORT') ?: '3306');
define('DB_SOCKET', getenv('MINICLOUD_DB_SOCKET') ?: $defaultSocket);
define('DB_NAME', getenv('MINICLOUD_DB_NAME') ?: 'minicloud');
define('DB_USER', getenv('MINICLOUD_DB_USER') ?: 'minicloud_user');
define('DB_PASS', getenv('MINICLOUD_DB_PASS') ?: 'minicloud_secret');

// Speicherplatz- & Papierkorb-Konfiguration
define('DEFAULT_STORAGE_QUOTA', 100 * 1024 * 1024); // 100 MB pro Benutzer (Standard)
define('TRASH_RETENTION_DAYS', 30);                 // 30 Tage Aufbewahrung im Mülleimer
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');

// Standard-Zeitzone für alle Datums- und Zeitfunktionen
date_default_timezone_set('Europe/Berlin');

// Anwendungsname
define('APP_NAME', 'MiniCloud');

/**
 * Erstellt und gibt eine Singleton-PDO-Datenbankverbindung zurück.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
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
        error_log('Datenbankverbindungsfehler: ' . $e->getMessage());
        http_response_code(500);
        exit('Es konnte keine Verbindung zur Datenbank hergestellt werden. Bitte prüfen Sie die Konfiguration in config/database.php.');
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

    // Basis-Tabellen anlegen, falls die Datenbank noch komplett leer ist (Zero-Click-Installation)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(64) NOT NULL,
            `email` VARCHAR(190) DEFAULT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `two_factor_code_hash` VARCHAR(255) DEFAULT NULL,
            `two_factor_expires_at` DATETIME DEFAULT NULL,
            `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `theme` VARCHAR(24) NOT NULL DEFAULT 'light',
            `session_timeout` INT UNSIGNED NOT NULL DEFAULT 30,
            `flash_duration` INT UNSIGNED NOT NULL DEFAULT 10,
            `chat_notify_mode` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `allow_user_sharing` TINYINT(1) NOT NULL DEFAULT 0,
            `calendar_token` VARCHAR(64) DEFAULT NULL,
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
    if (!in_array('two_factor_code_hash', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_code_hash` VARCHAR(255) DEFAULT NULL AFTER `two_factor_enabled`");
    }
    if (!in_array('two_factor_expires_at', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_expires_at` DATETIME DEFAULT NULL AFTER `two_factor_code_hash`");
    }
    if (!in_array('two_factor_attempts', $colsUsers, true)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `two_factor_expires_at`");
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
            `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
            `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
            `upload_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_files_user_id` (`user_id`),
            KEY `idx_files_deleted_at` (`deleted_at`),
            KEY `idx_files_group_folder` (`group_folder_id`),
            KEY `idx_files_user_folder` (`user_id`, `folder_path`(191)),
            CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_files_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Prüfen, ob Spalte group_folder_id, is_encrypted und deleted_at in files existieren
    $colsFiles = $pdo->query("SHOW COLUMNS FROM `files`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('group_folder_id', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `group_folder_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`");
        $pdo->exec("ALTER TABLE `files` ADD KEY `idx_files_group_folder` (`group_folder_id`)");
        $pdo->exec("ALTER TABLE `files` ADD CONSTRAINT `fk_files_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE");
    }
    if (!in_array('is_encrypted', $colsFiles, true)) {
        $pdo->exec("ALTER TABLE `files` ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mime_type`");
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
            `password_hash` VARCHAR(255) DEFAULT NULL,
            `expires_at` DATETIME DEFAULT NULL,
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `message_broadcast_reads` (
            `user_id` INT UNSIGNED NOT NULL,
            `last_read_broadcast_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            CONSTRAINT `fk_mbr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
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

    // Automatische Bereinigung: Dateien aus dem Papierkorb endgültig löschen, die älter als 30 Tage sind
    purgeExpiredTrashFiles($pdo);
}

/**
 * Entfernt automatisch alle Dateien aus dem Mülleimer, deren Löschdatum länger als TRASH_RETENTION_DAYS (30 Tage) zurückliegt.
 */
function purgeExpiredTrashFiles(PDO $pdo): int
{
    $days = (int)TRASH_RETENTION_DAYS;
    $stmtExpired = $pdo->query(
        "SELECT id, filename FROM `files`
         WHERE `deleted_at` IS NOT NULL
           AND `deleted_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );
    $expiredRows = $stmtExpired->fetchAll();

    if (empty($expiredRows)) {
        return 0;
    }

    $stmtDelete = $pdo->prepare("DELETE FROM `files` WHERE `id` = :id");
    $purgedCount = 0;

    foreach ($expiredRows as $row) {
        $physicalPath = UPLOAD_DIR . basename((string)$row['filename']);
        if (is_file($physicalPath)) {
            @unlink($physicalPath);
        }
        $stmtDelete->execute([':id' => (int)$row['id']]);
        $purgedCount++;
    }

    return $purgedCount;
}
