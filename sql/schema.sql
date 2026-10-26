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
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = deaktiviert, 1 = 2FA per E-Mail, 2 = 2FA per Authenticator-App (TOTP)',
    `totp_secret` VARCHAR(64) DEFAULT NULL COMMENT 'Base32-kodierter TOTP-Geheimschlüssel für Google Authenticator / RFC 6238',
    `two_factor_code_hash` VARCHAR(255) DEFAULT NULL COMMENT 'BCRYPT-Hash des aktuellen 6-stelligen 2FA-Codes',
    `two_factor_expires_at` DATETIME DEFAULT NULL COMMENT 'Ablaufzeitpunkt des aktuellen 2FA-Codes (10 Min.)',
    `two_factor_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fehlversuche beim aktuellen 2FA-Code',
    `reset_token_hash` VARCHAR(255) DEFAULT NULL COMMENT 'SHA-256-Hash des einmaligen Passwort-Reset-Tokens',
    `reset_expires_at` DATETIME DEFAULT NULL COMMENT 'Ablaufzeitpunkt des Passwort-Reset-Tokens (30 Min.)',
    `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Administrator, 0 = Standardbenutzer',
    `theme` VARCHAR(24) NOT NULL DEFAULT 'light' COMMENT '13 Farbthemes inkl. light, dark, ocean, forest, obsidian',
    `session_timeout` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Inaktivitäts-Timeout in Minuten (0 = Nie)',
    `flash_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Anzeigedauer für Statusmeldungen in Sekunden (0 = manuell schließen)',
    `chat_notify_mode` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = Nur System-Benachrichtigung, 2 = System + In-App, 3 = Nur In-App, 0 = Aus',
    `allow_user_sharing` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Für andere Nutzer beim Teilen/Chat sichtbar, 0 = Datenschutz (verborgen)',
    `calendar_token` VARCHAR(64) DEFAULT NULL COMMENT 'Persönlicher Token für .ics Kalender-Abo-Feed',
    `contacts_token` VARCHAR(64) DEFAULT NULL COMMENT 'Persönlicher Token für .vcf Adressbuch-Abo-Feed',
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
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Mit individuellem Tresor-Passwort (AES-256-GCM) verschlüsselt',
    `is_favorite` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Als Favorit markiert (Schnellzugriff)',
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
    `share_mode` VARCHAR(20) NOT NULL DEFAULT 'read' COMMENT 'read = Nur Download, upload_only = Briefkasten (File Drop), read_write = Download & Upload',
    `password_hash` VARCHAR(255) DEFAULT NULL COMMENT 'Optionaler BCRYPT-Passwort-Hash',
    `expires_at` DATETIME DEFAULT NULL COMMENT 'Optionales Ablaufdatum der Freigabe',
    `max_downloads` INT UNSIGNED DEFAULT NULL COMMENT 'Optionales Download-Limit (Selbstzerstörung / Burn after reading)',
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Anzahl bisheriger Downloads über diesen Link',
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
    `can_write` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Lesen & Schreiben (Upload & Unterordner), 0 = Nur Lesen',
    `can_delete` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Darf Dateien/Unterordner im geteilten Ordner löschen',
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

-- 10. Tabelle für Systemeinstellungen (Anwendungstitel, Logo, Impressum, Module, GitHub-Updater)
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` MEDIUMTEXT DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tabelle für Kalender-Termine (Einzeltermine, Ganztagestermine, Serien, Gruppen- & Direktfreigaben)
CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Ersteller / Eigentümer des Termins',
    `title` VARCHAR(190) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `location` VARCHAR(190) DEFAULT NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `all_day` TINYINT(1) NOT NULL DEFAULT 0,
    `recurrence` VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none, daily, weekly, monthly, yearly',
    `color` VARCHAR(24) NOT NULL DEFAULT '#2563eb',
    `group_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Geteilt mit Benutzergruppe',
    `group_can_edit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Gruppenmitglieder dürfen bearbeiten',
    `shared_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Direkt geteilt mit einzelnem Benutzer',
    `attached_file_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Verknüpfte Datei aus der Cloud',
    `is_private` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Privat (trotz Kalender-Freigabe nur für Eigentümer sichtbar)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ce_user` (`user_id`),
    KEY `idx_ce_start` (`start_at`),
    KEY `idx_ce_group` (`group_id`),
    KEY `idx_ce_shared_user` (`shared_user_id`),
    CONSTRAINT `fk_ce_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ce_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Tabelle für vollständige Kalender-Freigaben (eigenen Kalender mit Benutzern oder Gruppen teilen)
CREATE TABLE IF NOT EXISTS `calendar_shares` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_user_id` INT UNSIGNED NOT NULL COMMENT 'Kalender-Eigentümer',
    `target_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Freigegeben für einzelnen Benutzer',
    `target_group_id` INT UNSIGNED DEFAULT NULL COMMENT 'Freigegeben für Benutzergruppe',
    `can_edit` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Darf Termine eintragen/bearbeiten, 0 = Nur Lesen',
    `can_delete` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Darf Termine im geteilten Kalender auch löschen',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cs_owner` (`owner_user_id`),
    KEY `idx_cs_target_user` (`target_user_id`),
    KEY `idx_cs_target_group` (`target_group_id`),
    CONSTRAINT `fk_cs_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_target_group` FOREIGN KEY (`target_group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Tabelle für interne Nachrichten (Direkt-Chat zwischen Benutzern & Admin-Rundnachrichten)
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_id` INT UNSIGNED NOT NULL,
    `recipient_id` INT UNSIGNED DEFAULT NULL COMMENT 'NULL bei Rundnachricht an alle (is_broadcast = 1)',
    `is_broadcast` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Admin-Rundnachricht an alle Benutzer',
    `message_text` TEXT NOT NULL,
    `attached_file_id` INT UNSIGNED DEFAULT NULL COMMENT 'Optional: Im Chat angehängte/geteilte Datei',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Vom Empfänger gelesen (bei Direktnachrichten)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_msg_sender` (`sender_id`),
    KEY `idx_msg_recipient_read` (`recipient_id`, `is_read`),
    KEY `idx_msg_broadcast` (`is_broadcast`, `id`),
    CONSTRAINT `fk_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Tabelle für gelesene Admin-Rundnachrichten pro Benutzer
CREATE TABLE IF NOT EXISTS `message_broadcast_reads` (
    `user_id` INT UNSIGNED NOT NULL,
    `last_read_broadcast_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_mbr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Tabelle für Brute-Force-Schutz (Login, WebDAV & CardDAV)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `username` VARCHAR(64) NOT NULL DEFAULT '',
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Tabelle für Sicherheits- & Aktivitätsprotokoll (Audit-Log, DSGVO-konform)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `username` VARCHAR(64) DEFAULT NULL,
    `event_type` VARCHAR(40) NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_type` (`event_type`),
    KEY `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Tabelle für automatische Dateiversionierung (Zeitreise bis 5 Versionen)
CREATE TABLE IF NOT EXISTS `file_versions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
    `version_note` VARCHAR(190) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fv_file` (`file_id`),
    CONSTRAINT `fk_fv_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Tabelle für Datei-Kommentare & Notizen an Dateien
CREATE TABLE IF NOT EXISTS `file_comments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `comment_text` VARCHAR(1000) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fc_file` (`file_id`),
    CONSTRAINT `fk_fc_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Tabelle für Aufgaben & Kanban-Board (tasks.php)
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'todo' COMMENT 'todo, in_progress, done',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'medium' COMMENT 'low, medium, high, urgent',
    `due_date` DATE DEFAULT NULL,
    `checklist_json` TEXT DEFAULT NULL,
    `attached_file_id` INT UNSIGNED DEFAULT NULL,
    `shared_user_id` INT UNSIGNED DEFAULT NULL,
    `group_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tasks_user` (`user_id`),
    KEY `idx_tasks_shared_user` (`shared_user_id`),
    KEY `idx_tasks_group` (`group_id`),
    CONSTRAINT `fk_tasks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tasks_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_file` FOREIGN KEY (`attached_file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Tabelle für digitale Haftnotizen / Pinboard (notes.php)
CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(190) NOT NULL DEFAULT '',
    `content` TEXT NOT NULL,
    `color` VARCHAR(24) NOT NULL DEFAULT 'amber' COMMENT 'amber, blue, emerald, rose, violet, slate',
    `tags` VARCHAR(255) NOT NULL DEFAULT '',
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `shared_user_id` INT UNSIGNED DEFAULT NULL,
    `group_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notes_user` (`user_id`),
    KEY `idx_notes_shared_user` (`shared_user_id`),
    KEY `idx_notes_group` (`group_id`),
    CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notes_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_notes_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Tabelle für Adressbuch & Kontakte inkl. CardDAV (contacts.php, carddav.php)
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
    `vcard_uid` VARCHAR(120) DEFAULT NULL,
    `shared_user_id` INT UNSIGNED DEFAULT NULL,
    `group_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contacts_user` (`user_id`),
    KEY `idx_contacts_shared_user` (`shared_user_id`),
    KEY `idx_contacts_group` (`group_id`),
    CONSTRAINT `fk_contacts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_contacts_shared_user` FOREIGN KEY (`shared_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_contacts_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
