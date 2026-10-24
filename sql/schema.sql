-- MiniCloud MySQL Database Schema (Erweitert um Shares, Benutzer-Freigaben, Gruppenordner, Rollen & Themes)
-- Kompatibel mit MySQL 5.7+ / 8.0+ und MariaDB 10.3+ (z.B. STRATO Webspace)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1. Tabelle für Benutzer (wird vom Administrator verwaltet)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `email` VARCHAR(190) DEFAULT NULL COMMENT 'Optionale E-Mail-Adresse für 2FA per E-Mail',
    `password_hash` VARCHAR(255) NOT NULL,
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = 2FA per E-Mail aktiv, 0 = deaktiviert',
    `two_factor_code_hash` VARCHAR(255) DEFAULT NULL COMMENT 'BCRYPT-Hash des aktuellen 6-stelligen 2FA-Codes',
    `two_factor_expires_at` DATETIME DEFAULT NULL COMMENT 'Ablaufzeitpunkt des aktuellen 2FA-Codes (10 Min.)',
    `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fehlversuche beim aktuellen 2FA-Code',
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Administrator, 0 = Standardbenutzer',
    `theme` VARCHAR(24) NOT NULL DEFAULT 'light' COMMENT 'light, dark, ocean, forest',
    `storage_quota` BIGINT UNSIGNED NOT NULL DEFAULT 104857600 COMMENT 'Standard: 100 MB in Bytes',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabelle für Benutzergruppen
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_groups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Zuordnung Benutzer <-> Gruppen
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabelle für Gruppenordner (vom Admin angelegt)
CREATE TABLE IF NOT EXISTS `group_folders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_group_folders_name` (`name`),
    CONSTRAINT `fk_gf_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Berechtigungen auf Gruppenordner (für einzelne Benutzer oder ganze Gruppen)
CREATE TABLE IF NOT EXISTS `group_folder_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_folder_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Direkt berechtigter Benutzer (optional)',
    `group_id` INT UNSIGNED DEFAULT NULL COMMENT 'Berechtigte Gruppe (optional)',
    `can_write` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Lesen & Hochladen/Löschen, 0 = Nur Lesen',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gfp_folder` (`group_folder_id`),
    KEY `idx_gfp_user` (`user_id`),
    KEY `idx_gfp_group` (`group_id`),
    CONSTRAINT `fk_gfp_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gfp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gfp_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabelle für hochgeladene Dateien
CREATE TABLE IF NOT EXISTS `files` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `group_folder_id` INT UNSIGNED DEFAULT NULL COMMENT 'Falls gesetzt, gehört die Datei zu einem Gruppenordner',
    `filename` VARCHAR(255) NOT NULL COMMENT 'Eindeutiger Dateiname im /uploads/ Ordner (per uniqid)',
    `original_name` VARCHAR(255) NOT NULL COMMENT 'Ursprünglicher Dateiname',
    `folder_path` VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'Optionaler relativer Ordnerpfad bei Ordner-Uploads',
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Dateigröße in Bytes',
    `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
    `upload_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL COMMENT 'Zeitstempel bei Verschiebung in den Papierkorb (30 Tage Aufbewahrung)',
    PRIMARY KEY (`id`),
    KEY `idx_files_user_id` (`user_id`),
    KEY `idx_files_deleted_at` (`deleted_at`),
    KEY `idx_files_group_folder` (`group_folder_id`),
    KEY `idx_files_user_folder` (`user_id`, `folder_path`(191)),
    CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_files_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabelle für öffentliche Freigabelinks (shares - für Einzeldateien und ganze Ordner)
CREATE TABLE IF NOT EXISTS `shares` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED DEFAULT NULL COMMENT 'Gesetzt bei Datei-Freigabe',
    `folder_path` VARCHAR(500) DEFAULT NULL COMMENT 'Gesetzt bei Ordner-Freigabe',
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Ersteller der Freigabe',
    `share_token` VARCHAR(64) NOT NULL COMMENT 'Eindeutiger Token per bin2hex(random_bytes(16))',
    `password_hash` VARCHAR(255) DEFAULT NULL COMMENT 'Optionaler BCRYPT-Passwort-Hash',
    `expires_at` DATETIME DEFAULT NULL COMMENT 'Optionales Ablaufdatum der Freigabe',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_shares_token` (`share_token`),
    KEY `idx_shares_file_id` (`file_id`),
    KEY `idx_shares_user_id` (`user_id`),
    CONSTRAINT `fk_shares_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shares_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabelle für interne Datei- & Ordner-Freigaben zwischen Cloud-Benutzern (user_shares)
CREATE TABLE IF NOT EXISTS `user_shares` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED DEFAULT NULL,
    `folder_path` VARCHAR(500) DEFAULT NULL,
    `owner_id` INT UNSIGNED NOT NULL,
    `shared_with_user_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_shares_unique` (`file_id`, `shared_with_user_id`),
    KEY `idx_user_shares_recipient` (`shared_with_user_id`),
    CONSTRAINT `fk_us_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_us_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_us_recipient` FOREIGN KEY (`shared_with_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabelle für explizit angelegte Ordner (ermöglicht leere Ordner & strukturierte Hierarchien)
CREATE TABLE IF NOT EXISTS `user_folders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `group_folder_id` INT UNSIGNED DEFAULT NULL,
    `folder_path` VARCHAR(500) NOT NULL COMMENT 'Relativer Ordnerpfad, z.B. Rechnungen oder Projekte/2026',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uf_user_gf` (`user_id`, `group_folder_id`),
    KEY `idx_uf_path` (`folder_path`(191)),
    CONSTRAINT `fk_uf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_uf_group_folder` FOREIGN KEY (`group_folder_id`) REFERENCES `group_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabelle für Systemeinstellungen (Anwendungstitel, Logo, Impressum)
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` MEDIUMTEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
