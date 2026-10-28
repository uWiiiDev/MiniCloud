<?php
declare(strict_types=1);

/**
 * MiniCloud – Vereinsverwaltung (club.php)
 *
 * Funktionen:
 *  - Mehrere Vereine / Abteilungen verwalten (oder mit Vorstandsmitgliedern teilen)
 *  - Granulare Aufgaben- & Rollenverteilung auf verschiedene Cloud-Benutzer:
 *      * 👑 Vollzugriff (1./2. Vorsitzender)
 *      * 👥 Mitglieder & Abteilungen (Schriftführer / Mitgliederwart)
 *      * 💶 Beiträge, Tarife & Finanzen (Kassenwart / Schatzmeister)
 *      * 🛠️ Arbeitsstunden, Inventar & Schlüssel (Gerätewart / Platzwart / Jugendleiter)
 *      * 📑 Protokolle & Vereinsdokumente (Schriftführer)
 *      * 👀 Nur-Lese-Einblick (Kassenprüfer / Beisitzer)
 *  - Mitglieder-Stammdaten inkl. Mitgliedsnummer, Eintrittsdatum, Geburtsdatum (Jubiläen & Geburtstage),
 *    Abteilung/Sparte, Vorstandsamt, Status (Aktiv, Passiv, Jugend, Ehrenmitglied, Ausgetreten)
 *  - Beitragsgruppen/Tarife, 1-Klick-Jahres-Sollstellung, Zahlungsstatus (Bezahlt, Offen, Überfällig, Befreit),
 *    SEPA-Mandatsdaten, CSV-Bankexport in die Cloud & 1-Klick-Übergabe bezahlter Beiträge ins Haushaltsbuch (budget.php)
 *  - Helfer- & Pflichtstunden-Konto pro Mitglied sowie Inventar- & Schlüssel-Verleihliste
 *  - Vereinsdokumente & Protokolle (direkte Verknüpfung mit Cloud-Dateien aus Office Light / PDF & Scan)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_club'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('error', 'Das Modul „Vereinsverwaltung“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Legt die Tabellen für die Vereinsverwaltung automatisch an (mit Session-Cache für hohe Performance).
 */
function ensureClubSchema(PDO $pdo): void
{
    if (!empty($_SESSION['_mc_club_schema_ok'])) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `clubs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(180) NOT NULL,
            `short_name` VARCHAR(40) NOT NULL DEFAULT '',
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            `icon` VARCHAR(16) NOT NULL DEFAULT '🏛️',
            `currency` VARCHAR(8) NOT NULL DEFAULT '€',
            `default_work_hours` DECIMAL(6,1) NOT NULL DEFAULT 10.0,
            `budget_book_id` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_clubs_owner` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_roles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `role_title` VARCHAR(100) NOT NULL DEFAULT 'Vorstandsmitglied',
            `permissions` VARCHAR(255) NOT NULL DEFAULT 'readonly',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_cr_club_user` (`club_id`, `user_id`),
            KEY `idx_cr_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_fee_tiers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(120) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'yearly',
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cft_club` (`club_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_members` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `member_no` VARCHAR(40) NOT NULL DEFAULT '',
            `first_name` VARCHAR(100) NOT NULL,
            `last_name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(190) NOT NULL DEFAULT '',
            `phone` VARCHAR(80) NOT NULL DEFAULT '',
            `street` VARCHAR(190) NOT NULL DEFAULT '',
            `postal_code` VARCHAR(30) NOT NULL DEFAULT '',
            `city` VARCHAR(100) NOT NULL DEFAULT '',
            `birth_date` DATE DEFAULT NULL,
            `joined_date` DATE DEFAULT NULL,
            `left_date` DATE DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `department` VARCHAR(120) NOT NULL DEFAULT '',
            `board_role` VARCHAR(120) NOT NULL DEFAULT '',
            `fee_tier_id` INT UNSIGNED DEFAULT NULL,
            `iban` VARCHAR(50) NOT NULL DEFAULT '',
            `bic` VARCHAR(30) NOT NULL DEFAULT '',
            `sepa_mandate_ref` VARCHAR(80) NOT NULL DEFAULT '',
            `sepa_signed_date` DATE DEFAULT NULL,
            `cloud_user_id` INT UNSIGNED DEFAULT NULL,
            `notes` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cm_club_status` (`club_id`, `status`),
            KEY `idx_cm_club_name` (`club_id`, `last_name`, `first_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_fee_payments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `member_id` INT UNSIGNED NOT NULL,
            `period_label` VARCHAR(32) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` VARCHAR(20) NOT NULL DEFAULT 'open',
            `paid_date` DATE DEFAULT NULL,
            `payment_method` VARCHAR(40) NOT NULL DEFAULT 'Überweisung / SEPA',
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `synced_to_budget` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cfp_club_period` (`club_id`, `period_label`),
            KEY `idx_cfp_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_work_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `member_id` INT UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `event_title` VARCHAR(180) NOT NULL,
            `hours` DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cwl_club_date` (`club_id`, `work_date`),
            KEY `idx_cwl_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_inventory` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `item_name` VARCHAR(180) NOT NULL,
            `category` VARCHAR(60) NOT NULL DEFAULT 'Sonstiges',
            `identifier_code` VARCHAR(80) NOT NULL DEFAULT '',
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `condition_note` VARCHAR(255) NOT NULL DEFAULT '',
            `loaned_to_member_id` INT UNSIGNED DEFAULT NULL,
            `loaned_since` DATE DEFAULT NULL,
            `loaned_until` DATE DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ci_club` (`club_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `club_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `club_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `doc_type` VARCHAR(60) NOT NULL DEFAULT 'Sitzungsprotokoll',
            `doc_date` DATE NOT NULL,
            `file_id` INT UNSIGNED DEFAULT NULL,
            `note` VARCHAR(500) NOT NULL DEFAULT '',
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cd_club_date` (`club_id`, `doc_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $_SESSION['_mc_club_schema_ok'] = true;
}

ensureClubSchema($pdo);
ensureUserSecurityColumns($pdo);

/**
 * Verfügbare Einzelberechtigungen innerhalb eines Vereins für die Aufgabenteilung im Vorstand.
 *
 * @return array<string, array{label: string, icon: string, desc: string}>
 */
function getAvailableClubPermissions(): array
{
    return [
        'full' => [
            'label' => 'Vollzugriff (1./2. Vorsitzender)',
            'icon'  => '👑',
            'desc'  => 'Alle Vereinsbereiche verwalten sowie Vorstands-Rollen vergeben',
        ],
        'members' => [
            'label' => 'Mitglieder & Abteilungen',
            'icon'  => '👥',
            'desc'  => 'Mitgliederstammdaten, Ein-/Austritte, Abteilungen & Ämter pflegen (z. B. Schriftführer)',
        ],
        'fees' => [
            'label' => 'Beiträge, Tarife & Finanzen',
            'icon'  => '💶',
            'desc'  => 'Beitragstarife, Jahresbeiträge, Zahlungsstatus, SEPA-Daten & Kassen-Übergabe (z. B. Kassenwart)',
        ],
        'work_inventory' => [
            'label' => 'Arbeitsstunden, Inventar & Schlüssel',
            'icon'  => '🛠️',
            'desc'  => 'Helfer-/Pflichtstunden erfassen sowie Vereinsgeräte, Trikots & Schlüssel verleihen (z. B. Gerätewart)',
        ],
        'docs' => [
            'label' => 'Protokolle & Vereinsdokumente',
            'icon'  => '📑',
            'desc'  => 'Sitzungsprotokolle, Satzung und Cloud-Dokumente verknüpfen (z. B. Schriftführer)',
        ],
        'readonly' => [
            'label' => 'Nur-Lese-Einblick (Prüfer / Beisitzer)',
            'icon'  => '👀',
            'desc'  => 'Darf freigegebene Vereinsübersichten einsehen, aber keine Daten ändern (z. B. Kassenprüfer)',
        ],
    ];
}

/**
 * Parst eine kommagetrennte Vereins-Berechtigungsliste.
 *
 * @return array<int, string>
 */
function parseClubPermissions(string $raw): array
{
    $allowed = array_keys(getAvailableClubPermissions());
    $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));
    $valid = array_values(array_unique(array_intersect($parts, $allowed)));
    return $valid;
}

/**
 * Prüft, ob der aktuelle Benutzer in diesem Verein ein bestimmtes Recht besitzt.
 *
 * @param array<int, string> $userPerms
 */
function hasClubPerm(bool $isClubOwner, array $userPerms, string $requiredPerm, bool $allowReadonly = false): bool
{
    if ($isClubOwner || in_array('full', $userPerms, true)) {
        return true;
    }
    if (in_array($requiredPerm, $userPerms, true)) {
        return true;
    }
    if ($allowReadonly && in_array('readonly', $userPerms, true)) {
        return true;
    }
    return false;
}

function formatClubMoney(float $amount, string $currency = '€'): string
{
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
}

// Alle Vereine laden, auf die der Benutzer als Eigentümer oder über club_roles Zugriff hat
$stmtClubs = $pdo->prepare(
    'SELECT c.*, u.username AS owner_name, cr.role_title AS my_role_title, cr.permissions AS my_permissions
     FROM clubs c
     LEFT JOIN users u ON u.id = c.user_id
     LEFT JOIN club_roles cr ON cr.club_id = c.id AND cr.user_id = :uid_role
     WHERE c.user_id = :uid_owner OR cr.user_id IS NOT NULL
     ORDER BY c.id ASC'
);
$stmtClubs->execute([
    ':uid_role'  => $userId,
    ':uid_owner' => $userId,
]);
$allClubs = $stmtClubs->fetchAll();

// Falls noch gar kein Verein existiert, einen anschaulichen Muster-Verein mit Beispiel-Tarifen anlegen
if (empty($allClubs)) {
    $stmtInit = $pdo->prepare(
        "INSERT INTO clubs (user_id, name, short_name, description, icon, currency, default_work_hours)
         VALUES (:uid, 'Mein Verein e. V.', 'MV', 'Mitglieder-, Beitrags-, Inventar- & Vorstandsverwaltung', '🏛️', '€', 10.0)"
    );
    $stmtInit->execute([':uid' => $userId]);
    $firstClubId = (int)$pdo->lastInsertId();

    $stmtTierInit = $pdo->prepare(
        "INSERT INTO club_fee_tiers (club_id, title, amount, billing_cycle, description)
         VALUES (:cid, :t, :amt, 'yearly', :d)"
    );
    $stmtTierInit->execute([':cid' => $firstClubId, ':t' => 'Erwachsene (Aktiv)', ':amt' => 60.00, ':d' => 'Regulärer Jahresbeitrag für aktive Mitglieder']);
    $tierAdultId = (int)$pdo->lastInsertId();
    $stmtTierInit->execute([':cid' => $firstClubId, ':t' => 'Jugend / Ermäßigt', ':amt' => 30.00, ':d' => 'Schüler, Jugendliche, Auszubildende & Studierende']);
    $stmtTierInit->execute([':cid' => $firstClubId, ':t' => 'Passiv / Fördermitglied', ':amt' => 24.00, ':d' => 'Fördernde und passive Vereinsmitglieder']);
    $stmtTierInit->execute([':cid' => $firstClubId, ':t' => 'Ehrenmitglied (Beitragsfrei)', ':amt' => 0.00, ':d' => 'Beitragsfreie Ehrenmitgliedschaft']);

    // Beispiel-Mitglied anlegen
    $pdo->prepare(
        "INSERT INTO club_members (club_id, member_no, first_name, last_name, email, status, department, board_role, fee_tier_id, joined_date)
         VALUES (:cid, '1001', :fn, 'Mustermann (Beispiel)', '', 'active', 'Hauptverein', '1. Vorsitzender', :tid, CURDATE())"
    )->execute([
        ':cid' => $firstClubId,
        ':fn'  => ucfirst($username),
        ':tid' => $tierAdultId,
    ]);

    header('Location: club.php?club=' . $firstClubId);
    exit;
}

$activeClubId = isset($_GET['club']) ? (int)$_GET['club'] : (int)($_POST['club_id'] ?? $allClubs[0]['id']);
$activeClub = null;
foreach ($allClubs as $c) {
    if ((int)$c['id'] === $activeClubId) {
        $activeClub = $c;
        break;
    }
}
if ($activeClub === null) {
    $activeClub = $allClubs[0];
    $activeClubId = (int)$activeClub['id'];
}

$isClubOwner = ((int)$activeClub['user_id'] === $userId);
$myClubPerms = $isClubOwner ? ['full'] : parseClubPermissions((string)($activeClub['my_permissions'] ?? ''));
$myRoleTitle = $isClubOwner ? 'Vereins-Gründer / 1. Vorsitzender' : (string)($activeClub['my_role_title'] ?: 'Vorstandsmitglied');
$isFullClubAdmin = $isClubOwner || in_array('full', $myClubPerms, true);

// Einzelne Berechtigungen (Schreiben vs. Lesen)
$canWriteMembers  = hasClubPerm($isClubOwner, $myClubPerms, 'members', false);
$canReadMembers   = hasClubPerm($isClubOwner, $myClubPerms, 'members', true) || hasClubPerm($isClubOwner, $myClubPerms, 'fees', true) || hasClubPerm($isClubOwner, $myClubPerms, 'work_inventory', true);
$canWriteFees     = hasClubPerm($isClubOwner, $myClubPerms, 'fees', false);
$canReadFees      = hasClubPerm($isClubOwner, $myClubPerms, 'fees', true);
$canWriteWorkInv  = hasClubPerm($isClubOwner, $myClubPerms, 'work_inventory', false);
$canReadWorkInv   = hasClubPerm($isClubOwner, $myClubPerms, 'work_inventory', true);
$canWriteDocs     = hasClubPerm($isClubOwner, $myClubPerms, 'docs', false);
$canReadDocs      = hasClubPerm($isClubOwner, $myClubPerms, 'docs', true);

$currency = (string)($activeClub['currency'] ?: '€');
$selectedYear = (string)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if (!preg_match('/^\d{4}$/', $selectedYear)) {
    $selectedYear = date('Y');
}

$availableClubPerms = getAvailableClubPermissions();

// --- POST-Aktionen verarbeiten ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: club.php?club=' . $activeClubId);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Neuen Verein anlegen
    if ($action === 'create_club') {
        $name      = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 180) ?: 'Neuer Verein e. V.';
        $shortName = mb_substr(trim((string)($_POST['short_name'] ?? '')), 0, 40);
        $desc      = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 480);
        $icon      = mb_substr(trim((string)($_POST['icon'] ?? '🏛️')), 0, 12) ?: '🏛️';
        $curr      = mb_substr(trim((string)($_POST['currency'] ?? '€')), 0, 8) ?: '€';
        $workHours = max(0.0, min(500.0, (float)str_replace(',', '.', (string)($_POST['default_work_hours'] ?? '10'))));

        $stmt = $pdo->prepare(
            'INSERT INTO clubs (user_id, name, short_name, description, icon, currency, default_work_hours)
             VALUES (:uid, :name, :sn, :desc, :icon, :curr, :wh)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':name' => $name,
            ':sn'   => $shortName,
            ':desc' => $desc,
            ':icon' => $icon,
            ':curr' => $curr,
            ':wh'   => $workHours,
        ]);
        $newClubId = (int)$pdo->lastInsertId();

        // Standard-Tarif anlegen
        $pdo->prepare(
            "INSERT INTO club_fee_tiers (club_id, title, amount, billing_cycle, description)
             VALUES (:cid, 'Regulärer Jahresbeitrag', 60.00, 'yearly', 'Standard-Mitgliedsbeitrag')"
        )->execute([':cid' => $newClubId]);

        setFlash('success', 'Verein „' . $name . '“ wurde erfolgreich angelegt.');
        header('Location: club.php?club=' . $newClubId);
        exit;
    }

    // 2. Vereins-Stammdaten aktualisieren (nur Vollzugriff)
    if ($action === 'update_club' && $isFullClubAdmin) {
        $name      = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 180) ?: (string)$activeClub['name'];
        $shortName = mb_substr(trim((string)($_POST['short_name'] ?? '')), 0, 40);
        $desc      = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 480);
        $icon      = mb_substr(trim((string)($_POST['icon'] ?? '🏛️')), 0, 12) ?: '🏛️';
        $curr      = mb_substr(trim((string)($_POST['currency'] ?? '€')), 0, 8) ?: '€';
        $workHours = max(0.0, min(500.0, (float)str_replace(',', '.', (string)($_POST['default_work_hours'] ?? '10'))));
        $budgetId  = !empty($_POST['budget_book_id']) ? (int)$_POST['budget_book_id'] : null;

        // Prüfen, ob das verknüpfte Haushaltsbuch dem Benutzer gehört oder mit ihm geteilt ist
        if ($budgetId !== null && $budgetId > 0) {
            try {
                $stmtChkB = $pdo->prepare('SELECT id FROM budget_books WHERE id = :bid AND (user_id = :uid OR FIND_IN_SET(:uid_str, shared_users) > 0) LIMIT 1');
                $stmtChkB->execute([':bid' => $budgetId, ':uid' => $userId, ':uid_str' => (string)$userId]);
                if (!$stmtChkB->fetchColumn()) {
                    $budgetId = null;
                }
            } catch (Throwable) {
                $budgetId = null;
            }
        } else {
            $budgetId = null;
        }

        $pdo->prepare(
            'UPDATE clubs
             SET name = :n, short_name = :sn, description = :d, icon = :ic, currency = :c, default_work_hours = :wh, budget_book_id = :bid
             WHERE id = :id'
        )->execute([
            ':n'   => $name,
            ':sn'  => $shortName,
            ':d'   => $desc,
            ':ic'  => $icon,
            ':c'   => $curr,
            ':wh'  => $workHours,
            ':bid' => $budgetId,
            ':id'  => $activeClubId,
        ]);

        setFlash('success', 'Vereins-Einstellungen wurden gespeichert.');
        header('Location: club.php?club=' . $activeClubId . '#tab-roles');
        exit;
    }

    // 2b. Verein löschen (nur Eigentümer)
    if ($action === 'delete_club' && $isClubOwner && count($allClubs) > 1) {
        $pdo->prepare('DELETE FROM club_roles WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_fee_payments WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_fee_tiers WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_work_logs WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_inventory WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_documents WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_members WHERE club_id = :cid')->execute([':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM clubs WHERE id = :cid AND user_id = :uid')->execute([':cid' => $activeClubId, ':uid' => $userId]);
        setFlash('success', 'Verein und zugehörige Einträge wurden gelöscht.');
        header('Location: club.php');
        exit;
    }

    // 3. Vorstands-Rolle / Teil-Berechtigung an Cloud-Benutzer vergeben (nur Vollzugriff)
    if ($action === 'save_club_role' && $isFullClubAdmin) {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $roleTitle    = mb_substr(trim((string)($_POST['role_title'] ?? '')), 0, 100) ?: 'Vorstandsmitglied';
        $rawPerms     = isset($_POST['club_perms']) && is_array($_POST['club_perms']) ? $_POST['club_perms'] : [];
        $cleanPerms   = parseClubPermissions(implode(',', array_map('strval', $rawPerms)));

        if ($targetUserId <= 0 || $targetUserId === (int)$activeClub['user_id']) {
            setFlash('error', 'Bitte wählen Sie einen gültigen Cloud-Benutzer aus.');
        } elseif (empty($cleanPerms)) {
            setFlash('error', 'Bitte wählen Sie mindestens einen Aufgabenbereich (Berechtigung) für diesen Benutzer aus.');
        } else {
            // Datenschutz- & Sperrprüfung: Zielbenutzer muss allow_user_sharing = 1 und is_locked = 0 haben
            $stmtVis = $pdo->prepare('SELECT id, username FROM users WHERE id = :id AND allow_user_sharing = 1 AND is_locked = 0 LIMIT 1');
            $stmtVis->execute([':id' => $targetUserId]);
            $targetUserRow = $stmtVis->fetch();
            if (!$targetUserRow) {
                setFlash('error', 'Dieser Benutzer hat die Teilen-Sichtbarkeit nicht aktiviert oder ist gesperrt.');
            } else {
                $permsStr = implode(',', $cleanPerms);
                $pdo->prepare(
                    'INSERT INTO club_roles (club_id, user_id, role_title, permissions)
                     VALUES (:cid, :uid, :rt, :p)
                     ON DUPLICATE KEY UPDATE role_title = VALUES(role_title), permissions = VALUES(permissions)'
                )->execute([
                    ':cid' => $activeClubId,
                    ':uid' => $targetUserId,
                    ':rt'  => $roleTitle,
                    ':p'   => $permsStr,
                ]);
                setFlash('success', sprintf('Vereins-Berechtigung für „%s“ (%s) wurde gespeichert.', (string)$targetUserRow['username'], $roleTitle));
            }
        }
        header('Location: club.php?club=' . $activeClubId . '#tab-roles');
        exit;
    }

    if ($action === 'delete_club_role' && $isFullClubAdmin) {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_roles WHERE id = :id AND club_id = :cid')
            ->execute([':id' => $roleId, ':cid' => $activeClubId]);
        setFlash('success', 'Vorstands-/Verwaltungsberechtigung wurde entzogen.');
        header('Location: club.php?club=' . $activeClubId . '#tab-roles');
        exit;
    }

    // 4. Mitglied anlegen oder bearbeiten (Recht: members)
    if ($action === 'save_member' && $canWriteMembers) {
        $memberId   = (int)($_POST['member_id'] ?? 0);
        $memberNo   = mb_substr(trim((string)($_POST['member_no'] ?? '')), 0, 40);
        $firstName  = mb_substr(trim((string)($_POST['first_name'] ?? '')), 0, 100);
        $lastName   = mb_substr(trim((string)($_POST['last_name'] ?? '')), 0, 100);
        $email      = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190);
        $phone      = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 80);
        $street     = mb_substr(trim((string)($_POST['street'] ?? '')), 0, 190);
        $postalCode = mb_substr(trim((string)($_POST['postal_code'] ?? '')), 0, 30);
        $city       = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 100);
        $birthDate  = !empty($_POST['birth_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['birth_date']) ? (string)$_POST['birth_date'] : null;
        $joinedDate = !empty($_POST['joined_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['joined_date']) ? (string)$_POST['joined_date'] : date('Y-m-d');
        $leftDate   = !empty($_POST['left_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['left_date']) ? (string)$_POST['left_date'] : null;
        $status     = (string)($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'passive', 'youth', 'honorary', 'left'], true)) {
            $status = 'active';
        }
        $department = mb_substr(trim((string)($_POST['department'] ?? '')), 0, 120);
        $boardRole  = mb_substr(trim((string)($_POST['board_role'] ?? '')), 0, 120);
        $feeTierId  = !empty($_POST['fee_tier_id']) ? (int)$_POST['fee_tier_id'] : null;
        $iban       = strtoupper(preg_replace('/\s+/', '', mb_substr(trim((string)($_POST['iban'] ?? '')), 0, 50)) ?? '');
        $bic        = strtoupper(mb_substr(trim((string)($_POST['bic'] ?? '')), 0, 30));
        $sepaRef    = mb_substr(trim((string)($_POST['sepa_mandate_ref'] ?? '')), 0, 80);
        $sepaDate   = !empty($_POST['sepa_signed_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['sepa_signed_date']) ? (string)$_POST['sepa_signed_date'] : null;
        $notes      = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);

        if ($firstName === '' || $lastName === '') {
            setFlash('error', 'Bitte Vor- und Nachnamen des Mitglieds angeben.');
            header('Location: club.php?club=' . $activeClubId . '#tab-members');
            exit;
        }

        if ($memberNo === '') {
            $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM club_members WHERE club_id = :cid');
            $stmtCnt->execute([':cid' => $activeClubId]);
            $memberNo = (string)(1001 + (int)$stmtCnt->fetchColumn());
        }

        if ($memberId > 0) {
            // Falls Benutzer kein Kassen-Recht hat, bestehende IBAN/SEPA-Werte beibehalten
            if (!$canWriteFees) {
                $stmtOld = $pdo->prepare('SELECT iban, bic, sepa_mandate_ref, sepa_signed_date FROM club_members WHERE id = :id AND club_id = :cid LIMIT 1');
                $stmtOld->execute([':id' => $memberId, ':cid' => $activeClubId]);
                $oldRow = $stmtOld->fetch();
                if ($oldRow) {
                    $iban     = (string)$oldRow['iban'];
                    $bic      = (string)$oldRow['bic'];
                    $sepaRef  = (string)$oldRow['sepa_mandate_ref'];
                    $sepaDate = $oldRow['sepa_signed_date'] ?: null;
                }
            }

            $pdo->prepare(
                'UPDATE club_members
                 SET member_no = :mno, first_name = :fn, last_name = :ln, email = :em, phone = :ph,
                     street = :st, postal_code = :pc, city = :ct, birth_date = :bd, joined_date = :jd, left_date = :ld,
                     status = :stat, department = :dep, board_role = :br, fee_tier_id = :ft,
                     iban = :ib, bic = :bic, sepa_mandate_ref = :sref, sepa_signed_date = :sdt, notes = :nt
                 WHERE id = :id AND club_id = :cid'
            )->execute([
                ':mno'  => $memberNo,
                ':fn'   => $firstName,
                ':ln'   => $lastName,
                ':em'   => $email,
                ':ph'   => $phone,
                ':st'   => $street,
                ':pc'   => $postalCode,
                ':ct'   => $city,
                ':bd'   => $birthDate,
                ':jd'   => $joinedDate,
                ':ld'   => $leftDate,
                ':stat' => $status,
                ':dep'  => $department,
                ':br'   => $boardRole,
                ':ft'   => $feeTierId,
                ':ib'   => $iban,
                ':bic'  => $bic,
                ':sref' => $sepaRef,
                ':sdt'  => $sepaDate,
                ':nt'   => $notes,
                ':id'   => $memberId,
                ':cid'  => $activeClubId,
            ]);
            setFlash('success', sprintf('Mitglied „%s %s“ wurde aktualisiert.', $firstName, $lastName));
        } else {
            $pdo->prepare(
                'INSERT INTO club_members
                    (club_id, member_no, first_name, last_name, email, phone, street, postal_code, city,
                     birth_date, joined_date, left_date, status, department, board_role, fee_tier_id,
                     iban, bic, sepa_mandate_ref, sepa_signed_date, notes)
                 VALUES
                    (:cid, :mno, :fn, :ln, :em, :ph, :st, :pc, :ct,
                     :bd, :jd, :ld, :stat, :dep, :br, :ft,
                     :ib, :bic, :sref, :sdt, :nt)'
            )->execute([
                ':cid'  => $activeClubId,
                ':mno'  => $memberNo,
                ':fn'   => $firstName,
                ':ln'   => $lastName,
                ':em'   => $email,
                ':ph'   => $phone,
                ':st'   => $street,
                ':pc'   => $postalCode,
                ':ct'   => $city,
                ':bd'   => $birthDate,
                ':jd'   => $joinedDate,
                ':ld'   => $leftDate,
                ':stat' => $status,
                ':dep'  => $department,
                ':br'   => $boardRole,
                ':ft'   => $feeTierId,
                ':ib'   => $canWriteFees ? $iban : '',
                ':bic'  => $canWriteFees ? $bic : '',
                ':sref' => $canWriteFees ? $sepaRef : '',
                ':sdt'  => $canWriteFees ? $sepaDate : null,
                ':nt'   => $notes,
            ]);
            setFlash('success', sprintf('Mitglied „%s %s“ (Nr. %s) wurde aufgenommen.', $firstName, $lastName, $memberNo));
        }

        header('Location: club.php?club=' . $activeClubId . '#tab-members');
        exit;
    }

    if ($action === 'delete_member' && $canWriteMembers) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_fee_payments WHERE member_id = :mid AND club_id = :cid')->execute([':mid' => $memberId, ':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_work_logs WHERE member_id = :mid AND club_id = :cid')->execute([':mid' => $memberId, ':cid' => $activeClubId]);
        $pdo->prepare('UPDATE club_inventory SET loaned_to_member_id = NULL, loaned_since = NULL, loaned_until = NULL WHERE loaned_to_member_id = :mid AND club_id = :cid')->execute([':mid' => $memberId, ':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_members WHERE id = :mid AND club_id = :cid')->execute([':mid' => $memberId, ':cid' => $activeClubId]);
        setFlash('success', 'Mitglied wurde aus dem Vereinsverzeichnis gelöscht.');
        header('Location: club.php?club=' . $activeClubId . '#tab-members');
        exit;
    }

    // 5. Beitragstarife & Beitrags-Sollstellung (Recht: fees)
    if ($action === 'add_fee_tier' && $canWriteFees) {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120) ?: 'Beitragstarif';
        $amt   = round(max(0.0, abs((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')))), 2);
        $cycle = (string)($_POST['billing_cycle'] ?? 'yearly');
        if (!in_array($cycle, ['yearly', 'half_yearly', 'quarterly', 'monthly'], true)) {
            $cycle = 'yearly';
        }
        $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);

        $pdo->prepare(
            'INSERT INTO club_fee_tiers (club_id, title, amount, billing_cycle, description)
             VALUES (:cid, :t, :amt, :cyc, :d)'
        )->execute([
            ':cid' => $activeClubId,
            ':t'   => $title,
            ':amt' => $amt,
            ':cyc' => $cycle,
            ':d'   => $desc,
        ]);
        setFlash('success', 'Beitragstarif „' . $title . '“ wurde angelegt.');
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
        exit;
    }

    if ($action === 'delete_fee_tier' && $canWriteFees) {
        $tierId = (int)($_POST['tier_id'] ?? 0);
        $pdo->prepare('UPDATE club_members SET fee_tier_id = NULL WHERE fee_tier_id = :tid AND club_id = :cid')
            ->execute([':tid' => $tierId, ':cid' => $activeClubId]);
        $pdo->prepare('DELETE FROM club_fee_tiers WHERE id = :tid AND club_id = :cid')
            ->execute([':tid' => $tierId, ':cid' => $activeClubId]);
        setFlash('success', 'Beitragstarif wurde entfernt.');
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
        exit;
    }

    // 5b. 1-Klick-Jahres-Sollstellung für alle aktiven Mitglieder
    if ($action === 'generate_yearly_fees' && $canWriteFees) {
        $period = (string)($_POST['period_label'] ?? $selectedYear);
        if (!preg_match('/^\d{4}$/', $period)) {
            $period = date('Y');
        }

        $stmtMem = $pdo->prepare(
            "SELECT m.id, m.status, t.amount, t.billing_cycle, t.title AS tier_title
             FROM club_members m
             INNER JOIN club_fee_tiers t ON t.id = m.fee_tier_id AND t.club_id = m.club_id
             WHERE m.club_id = :cid AND m.status IN ('active', 'passive', 'youth')"
        );
        $stmtMem->execute([':cid' => $activeClubId]);
        $memRows = $stmtMem->fetchAll();

        $createdCount = 0;
        foreach ($memRows as $mr) {
            $baseAmt = (float)$mr['amount'];
            if ($baseAmt <= 0.0) {
                continue;
            }
            $annualAmt = match ((string)$mr['billing_cycle']) {
                'monthly'     => round($baseAmt * 12, 2),
                'quarterly'   => round($baseAmt * 4, 2),
                'half_yearly' => round($baseAmt * 2, 2),
                default       => $baseAmt,
            };

            $stmtChk = $pdo->prepare(
                'SELECT id FROM club_fee_payments WHERE club_id = :cid AND member_id = :mid AND period_label = :pl LIMIT 1'
            );
            $stmtChk->execute([
                ':cid' => $activeClubId,
                ':mid' => (int)$mr['id'],
                ':pl'  => $period,
            ]);
            if ($stmtChk->fetchColumn()) {
                continue;
            }

            $pdo->prepare(
                "INSERT INTO club_fee_payments (club_id, member_id, period_label, amount, status, note)
                 VALUES (:cid, :mid, :pl, :amt, 'open', :nt)"
            )->execute([
                ':cid' => $activeClubId,
                ':mid' => (int)$mr['id'],
                ':pl'  => $period,
                ':amt' => $annualAmt,
                ':nt'  => 'Jahresbeitrag ' . $period . ' (' . (string)$mr['tier_title'] . ')',
            ]);
            $createdCount++;
        }

        if ($createdCount > 0) {
            setFlash('success', sprintf('%d Beitragsforderungen für das Beitragsjahr %s wurden erfolgreich erstellt.', $createdCount, $period));
        } else {
            setFlash('warning', sprintf('Für das Jahr %s sind bereits alle Mitglieder mit Tarif sollgestellt.', $period));
        }
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($period) . '#tab-fees');
        exit;
    }

    // 5c. Einzelne Beitragszahlung Status umschalten (Bezahlt / Offen / Überfällig / Befreit)
    if ($action === 'update_fee_payment' && $canWriteFees) {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? 'paid');
        if (!in_array($newStatus, ['open', 'paid', 'overdue', 'waived'], true)) {
            $newStatus = 'paid';
        }
        $paidDate = ($newStatus === 'paid') ? date('Y-m-d') : null;

        $pdo->prepare(
            'UPDATE club_fee_payments SET status = :st, paid_date = :pd WHERE id = :id AND club_id = :cid'
        )->execute([
            ':st'  => $newStatus,
            ':pd'  => $paidDate,
            ':id'  => $paymentId,
            ':cid' => $activeClubId,
        ]);
        setFlash('success', 'Zahlungsstatus wurde aktualisiert.');
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
        exit;
    }

    if ($action === 'delete_fee_payment' && $canWriteFees) {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_fee_payments WHERE id = :id AND club_id = :cid')
            ->execute([':id' => $paymentId, ':cid' => $activeClubId]);
        setFlash('success', 'Beitragsposten wurde gelöscht.');
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
        exit;
    }

    // 5d. Bezahlte Mitgliedsbeiträge per 1-Klick ins verknüpfte Haushaltsbuch (budget.php) übertragen
    if ($action === 'sync_fees_to_budget' && $canWriteFees) {
        $budgetBookId = (int)($activeClub['budget_book_id'] ?? 0);
        if ($budgetBookId <= 0) {
            setFlash('error', 'Bitte wählen Sie zuerst im Reiter „Vorstand & Rollen“ ein verknüpftes Haushaltsbuch für die Vereinskasse aus.');
            header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-roles');
            exit;
        }

        // Zugriff auf das Haushaltsbuch prüfen
        $stmtChkB = $pdo->prepare('SELECT id FROM budget_books WHERE id = :bid AND (user_id = :uid OR FIND_IN_SET(:uid_str, shared_users) > 0) LIMIT 1');
        $stmtChkB->execute([':bid' => $budgetBookId, ':uid' => $userId, ':uid_str' => (string)$userId]);
        if (!$stmtChkB->fetchColumn()) {
            setFlash('error', 'Kein Zugriff auf das verknüpfte Haushaltsbuch.');
            header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
            exit;
        }

        $stmtUnsynced = $pdo->prepare(
            "SELECT p.id, p.amount, p.paid_date, p.period_label, m.first_name, m.last_name, m.member_no
             FROM club_fee_payments p
             INNER JOIN club_members m ON m.id = p.member_id
             WHERE p.club_id = :cid AND p.period_label = :pl AND p.status = 'paid' AND p.synced_to_budget = 0"
        );
        $stmtUnsynced->execute([':cid' => $activeClubId, ':pl' => $selectedYear]);
        $toSync = $stmtUnsynced->fetchAll();

        $syncedCount = 0;
        $totalSyncedAmt = 0.0;
        foreach ($toSync as $row) {
            $amt = (float)$row['amount'];
            $dt  = !empty($row['paid_date']) ? (string)$row['paid_date'] : date('Y-m-d');
            $entryTitle = sprintf('Mitgliedsbeitrag %s: %s %s (#%s)', (string)$row['period_label'], (string)$row['first_name'], (string)$row['last_name'], (string)$row['member_no']);

            $pdo->prepare(
                "INSERT INTO budget_entries (book_id, user_id, type, category, title, amount, entry_date, is_shared_split, note)
                 VALUES (:bid, :uid, 'income', 'Vereinsbeiträge', :title, :amt, :dt, 0, :nt)"
            )->execute([
                ':bid'   => $budgetBookId,
                ':uid'   => $userId,
                ':title' => mb_substr($entryTitle, 0, 180),
                ':amt'   => $amt,
                ':dt'    => $dt,
                ':nt'    => 'Übernommen aus Vereinsverwaltung (' . (string)$activeClub['name'] . ')',
            ]);

            $pdo->prepare('UPDATE club_fee_payments SET synced_to_budget = 1 WHERE id = :id')
                ->execute([':id' => (int)$row['id']]);
            $syncedCount++;
            $totalSyncedAmt += $amt;
        }

        if ($syncedCount > 0) {
            setFlash('success', sprintf('%d bezahlte Mitgliedsbeiträge (%s) wurden als Einnahmen ins Haushaltsbuch übertragen.', $syncedCount, formatClubMoney($totalSyncedAmt, $currency)));
        } else {
            setFlash('warning', 'Alle bezahlten Beiträge dieses Jahres wurden bereits ins Haushaltsbuch übertragen.');
        }
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-fees');
        exit;
    }

    // 6. Arbeitsstunden / Helfereinsätze erfassen (Recht: work_inventory)
    if ($action === 'add_work_log' && $canWriteWorkInv) {
        $memberId   = (int)($_POST['member_id'] ?? 0);
        $workDate   = !empty($_POST['work_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['work_date']) ? (string)$_POST['work_date'] : date('Y-m-d');
        $eventTitle = mb_substr(trim((string)($_POST['event_title'] ?? '')), 0, 180) ?: 'Arbeitseinsatz';
        $hours      = round(max(0.25, min(100.0, abs((float)str_replace(',', '.', (string)($_POST['hours'] ?? '1'))))), 2);
        $note       = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

        $stmtChkM = $pdo->prepare('SELECT id FROM club_members WHERE id = :mid AND club_id = :cid LIMIT 1');
        $stmtChkM->execute([':mid' => $memberId, ':cid' => $activeClubId]);
        if ($stmtChkM->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO club_work_logs (club_id, member_id, work_date, event_title, hours, note, created_by)
                 VALUES (:cid, :mid, :dt, :ev, :hr, :nt, :uid)'
            )->execute([
                ':cid' => $activeClubId,
                ':mid' => $memberId,
                ':dt'  => $workDate,
                ':ev'  => $eventTitle,
                ':hr'  => $hours,
                ':nt'  => $note,
                ':uid' => $userId,
            ]);
            setFlash('success', sprintf('%s Arbeitsstunden für „%s“ eingetragen.', number_format($hours, 1, ',', '.'), $eventTitle));
        }
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-work');
        exit;
    }

    if ($action === 'delete_work_log' && $canWriteWorkInv) {
        $logId = (int)($_POST['log_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_work_logs WHERE id = :id AND club_id = :cid')
            ->execute([':id' => $logId, ':cid' => $activeClubId]);
        setFlash('success', 'Arbeitsstunden-Eintrag wurde gelöscht.');
        header('Location: club.php?club=' . $activeClubId . '&year=' . urlencode($selectedYear) . '#tab-work');
        exit;
    }

    // 7. Inventar & Schlüssel-Verleih (Recht: work_inventory)
    if ($action === 'save_inventory' && $canWriteWorkInv) {
        $itemId     = (int)($_POST['item_id'] ?? 0);
        $itemName   = mb_substr(trim((string)($_POST['item_name'] ?? '')), 0, 180);
        $category   = mb_substr(trim((string)($_POST['category'] ?? 'Sonstiges')), 0, 60);
        $code       = mb_substr(trim((string)($_POST['identifier_code'] ?? '')), 0, 80);
        $qty        = max(1, min(9999, (int)($_POST['quantity'] ?? 1)));
        $condNote   = mb_substr(trim((string)($_POST['condition_note'] ?? '')), 0, 255);
        $loanMemId  = !empty($_POST['loaned_to_member_id']) ? (int)$_POST['loaned_to_member_id'] : null;
        $loanSince  = !empty($_POST['loaned_since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['loaned_since']) ? (string)$_POST['loaned_since'] : ($loanMemId ? date('Y-m-d') : null);
        $loanUntil  = !empty($_POST['loaned_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['loaned_until']) ? (string)$_POST['loaned_until'] : null;

        if ($loanMemId !== null && $loanMemId > 0) {
            $stmtChkM = $pdo->prepare('SELECT id FROM club_members WHERE id = :mid AND club_id = :cid LIMIT 1');
            $stmtChkM->execute([':mid' => $loanMemId, ':cid' => $activeClubId]);
            if (!$stmtChkM->fetchColumn()) {
                $loanMemId = null;
                $loanSince = null;
                $loanUntil = null;
            }
        } else {
            $loanMemId = null;
            $loanSince = null;
            $loanUntil = null;
        }

        if ($itemName !== '') {
            if ($itemId > 0) {
                $pdo->prepare(
                    'UPDATE club_inventory
                     SET item_name = :in, category = :cat, identifier_code = :cd, quantity = :q,
                         condition_note = :cn, loaned_to_member_id = :lm, loaned_since = :ls, loaned_until = :lu
                     WHERE id = :id AND club_id = :cid'
                )->execute([
                    ':in'  => $itemName,
                    ':cat' => $category,
                    ':cd'  => $code,
                    ':q'   => $qty,
                    ':cn'  => $condNote,
                    ':lm'  => $loanMemId,
                    ':ls'  => $loanSince,
                    ':lu'  => $loanUntil,
                    ':id'  => $itemId,
                    ':cid' => $activeClubId,
                ]);
                setFlash('success', 'Inventar-/Schlüsseleintrag wurde aktualisiert.');
            } else {
                $pdo->prepare(
                    'INSERT INTO club_inventory (club_id, item_name, category, identifier_code, quantity, condition_note, loaned_to_member_id, loaned_since, loaned_until)
                     VALUES (:cid, :in, :cat, :cd, :q, :cn, :lm, :ls, :lu)'
                )->execute([
                    ':cid' => $activeClubId,
                    ':in'  => $itemName,
                    ':cat' => $category,
                    ':cd'  => $code,
                    ':q'   => $qty,
                    ':cn'  => $condNote,
                    ':lm'  => $loanMemId,
                    ':ls'  => $loanSince,
                    ':lu'  => $loanUntil,
                ]);
                setFlash('success', 'Inventar-/Schlüsseleintrag „' . $itemName . '“ wurde angelegt.');
            }
        }
        header('Location: club.php?club=' . $activeClubId . '#tab-work');
        exit;
    }

    if ($action === 'delete_inventory' && $canWriteWorkInv) {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_inventory WHERE id = :id AND club_id = :cid')
            ->execute([':id' => $itemId, ':cid' => $activeClubId]);
        setFlash('success', 'Inventar-Eintrag wurde gelöscht.');
        header('Location: club.php?club=' . $activeClubId . '#tab-work');
        exit;
    }

    // 8. Vereinsdokumente & Sitzungsprotokolle verknüpfen (Recht: docs)
    if ($action === 'add_document' && $canWriteDocs) {
        $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 190);
        $docType = mb_substr(trim((string)($_POST['doc_type'] ?? 'Sitzungsprotokoll')), 0, 60);
        $docDate = !empty($_POST['doc_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['doc_date']) ? (string)$_POST['doc_date'] : date('Y-m-d');
        $fileId  = !empty($_POST['file_id']) ? (int)$_POST['file_id'] : null;
        $note    = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);

        // Sicherheitsprüfung gegen IDOR: Datei muss dem Benutzer gehören
        if ($fileId !== null && $fileId > 0) {
            $stmtChkF = $pdo->prepare('SELECT id FROM files WHERE id = :fid AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmtChkF->execute([':fid' => $fileId, ':uid' => $userId]);
            if (!$stmtChkF->fetchColumn()) {
                $fileId = null;
            }
        } else {
            $fileId = null;
        }

        if ($title !== '') {
            $pdo->prepare(
                'INSERT INTO club_documents (club_id, title, doc_type, doc_date, file_id, note, created_by)
                 VALUES (:cid, :t, :dt, :dd, :fid, :nt, :uid)'
            )->execute([
                ':cid' => $activeClubId,
                ':t'   => $title,
                ':dt'  => $docType,
                ':dd'  => $docDate,
                ':fid' => $fileId,
                ':nt'  => $note,
                ':uid' => $userId,
            ]);
            setFlash('success', 'Vereinsdokument / Protokoll „' . $title . '“ wurde hinterlegt.');
        }
        header('Location: club.php?club=' . $activeClubId . '#tab-docs');
        exit;
    }

    if ($action === 'delete_document' && $canWriteDocs) {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $pdo->prepare('DELETE FROM club_documents WHERE id = :id AND club_id = :cid')
            ->execute([':id' => $docId, ':cid' => $activeClubId]);
        setFlash('success', 'Dokumenten-Verknüpfung wurde entfernt.');
        header('Location: club.php?club=' . $activeClubId . '#tab-docs');
        exit;
    }

    // 9. CSV-Export (Mitgliederliste oder Beitrags-/SEPA-Liste) direkt in die Cloud speichern
    if ($action === 'export_csv_to_cloud' && ($canReadMembers || $canReadFees)) {
        $exportType = ($_POST['export_type'] ?? 'members') === 'fees' ? 'fees' : 'members';
        if ($exportType === 'fees' && !$canReadFees) {
            setFlash('error', 'Keine Berechtigung für den Beitrags-Export.');
            header('Location: club.php?club=' . $activeClubId);
            exit;
        }

        $csvLines = [];
        if ($exportType === 'members') {
            $csvLines[] = 'Mitglieds-Nr.;Nachname;Vorname;Status;Abteilung;Vorstandsamt;E-Mail;Telefon;Straße;PLZ;Ort;Geburtsdatum;Eintrittsdatum';
            $stmtEx = $pdo->prepare('SELECT * FROM club_members WHERE club_id = :cid ORDER BY last_name ASC, first_name ASC');
            $stmtEx->execute([':cid' => $activeClubId]);
            foreach ($stmtEx->fetchAll() as $m) {
                $csvLines[] = implode(';', [
                    (string)$m['member_no'],
                    str_replace(';', ',', (string)$m['last_name']),
                    str_replace(';', ',', (string)$m['first_name']),
                    (string)$m['status'],
                    str_replace(';', ',', (string)$m['department']),
                    str_replace(';', ',', (string)$m['board_role']),
                    str_replace(';', ',', (string)$m['email']),
                    str_replace(';', ',', (string)$m['phone']),
                    str_replace(';', ',', (string)$m['street']),
                    (string)$m['postal_code'],
                    str_replace(';', ',', (string)$m['city']),
                    (string)($m['birth_date'] ?? ''),
                    (string)($m['joined_date'] ?? ''),
                ]);
            }
            $origName = 'Mitgliederliste_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$activeClub['name']) . '_' . date('Y-m-d') . '.csv';
        } else {
            $csvLines[] = 'Periode;Mitglieds-Nr.;Nachname;Vorname;Betrag;Waehrung;Status;Bezahlt_am;IBAN;BIC;SEPA_Mandatsreferenz;Notiz';
            $stmtEx = $pdo->prepare(
                'SELECT p.*, m.member_no, m.first_name, m.last_name, m.iban, m.bic, m.sepa_mandate_ref
                 FROM club_fee_payments p
                 INNER JOIN club_members m ON m.id = p.member_id
                 WHERE p.club_id = :cid AND p.period_label = :pl
                 ORDER BY m.last_name ASC, m.first_name ASC'
            );
            $stmtEx->execute([':cid' => $activeClubId, ':pl' => $selectedYear]);
            foreach ($stmtEx->fetchAll() as $p) {
                $csvLines[] = implode(';', [
                    (string)$p['period_label'],
                    (string)$p['member_no'],
                    str_replace(';', ',', (string)$p['last_name']),
                    str_replace(';', ',', (string)$p['first_name']),
                    number_format((float)$p['amount'], 2, ',', ''),
                    $currency,
                    (string)$p['status'],
                    (string)($p['paid_date'] ?? ''),
                    (string)$p['iban'],
                    (string)$p['bic'],
                    (string)$p['sepa_mandate_ref'],
                    str_replace(';', ',', (string)$p['note']),
                ]);
            }
            $origName = 'Beitragsliste_' . $selectedYear . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$activeClub['name']) . '.csv';
        }

        $csvBytes = "\xEF\xBB\xBF" . implode("\r\n", $csvLines) . "\r\n";
        $byteLen  = strlen($csvBytes);

        if (hasAvailableQuota($pdo, $userId, $byteLen)) {
            ensureUploadDirReady();
            $storedName = generateSafeStoredFilename($origName);
            $destPath   = UPLOAD_DIR . $storedName;
            if (@file_put_contents($destPath, $csvBytes) !== false) {
                @chmod($destPath, 0640);
                $folderPath = 'Vereinsverwaltung';
                $pdo->prepare('INSERT IGNORE INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                    ->execute([':uid' => $userId, ':fp' => $folderPath]);
                $pdo->prepare(
                    'INSERT INTO files (user_id, filename, original_name, folder_path, file_size, mime_type)
                     VALUES (:uid, :fn, :on, :fp, :sz, :mt)'
                )->execute([
                    ':uid' => $userId,
                    ':fn'  => $storedName,
                    ':on'  => $origName,
                    ':fp'  => $folderPath,
                    ':sz'  => $byteLen,
                    ':mt'  => 'text/csv',
                ]);
                setFlash('success', 'CSV-Export „' . $origName . '“ wurde im Cloud-Ordner „Vereinsverwaltung“ gespeichert.');
            }
        } else {
            setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für den CSV-Export.');
        }

        header('Location: club.php?club=' . $activeClubId . ($exportType === 'fees' ? '#tab-fees' : '#tab-members'));
        exit;
    }

    // 9. Muster-CSV für Mitglieder-Import herunterladen
    if ($action === 'download_member_csv_template' && $canWriteMembers) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Muster_Mitgliederimport.csv"');
        $sample = [
            'Mitglieds-Nr.;Vorname;Nachname;Status;Abteilung;Amt;E-Mail;Telefon;Strasse;PLZ;Ort;Geburtsdatum;Eintrittsdatum;IBAN;BIC;SEPA_Mandatsreferenz;Notiz',
            '1005;Max;Mustermann;active;Fussball;Trainer;max@example.org;0170-1234567;Hauptstr. 12;12345;Musterstadt;15.05.1985;01.01.2020;DE89370400440532013000;;MV-1005;Übungsleiter C-Lizenz',
            '1006;Anna;Beispiel;youth;Jugend;;anna@example.org;0171-9876543;Gartenweg 4;12345;Musterstadt;20.09.2011;01.03.2023;;;;',
        ];
        echo "\xEF\xBB\xBF" . implode("\r\n", $sample) . "\r\n";
        exit;
    }

    // 10. Mitglieder importieren (CSV-Datei, Copy-&-Paste aus Excel oder Übernahme aus Cloud-Kontakten)
    if ($action === 'import_members' && $canWriteMembers) {
        $importSource   = (string)($_POST['import_source'] ?? 'csv'); // 'csv' oder 'contacts'
        $defaultTierId  = !empty($_POST['default_fee_tier_id']) ? (int)$_POST['default_fee_tier_id'] : null;
        $defaultDept    = mb_substr(trim((string)($_POST['default_department'] ?? '')), 0, 120);
        $skipDuplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1';

        // Bestehende Mitglieder für Duplikats-Check und nächste Mitgliedsnummer laden
        $stmtExisting = $pdo->prepare('SELECT member_no, first_name, last_name, email FROM club_members WHERE club_id = :cid');
        $stmtExisting->execute([':cid' => $activeClubId]);
        $existingRows = $stmtExisting->fetchAll();

        $existingKeys = [];
        $nextAutoNo   = 1001 + count($existingRows);
        foreach ($existingRows as $er) {
            $kName = mb_strtolower(trim((string)$er['first_name'] . '|' . (string)$er['last_name']), 'UTF-8');
            $existingKeys[$kName] = true;
            if (trim((string)$er['email']) !== '') {
                $existingKeys['mail:' . mb_strtolower(trim((string)$er['email']), 'UTF-8')] = true;
            }
            if (ctype_digit((string)$er['member_no']) && (int)$er['member_no'] >= $nextAutoNo) {
                $nextAutoNo = (int)$er['member_no'] + 1;
            }
        }

        $parseFlexibleDate = static function (string $val): ?string {
            $val = trim($val);
            if ($val === '') {
                return null;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val)) {
                return $val;
            }
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $val, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            return null;
        };

        $normalizeStatus = static function (string $val): string {
            $v = mb_strtolower(trim($val), 'UTF-8');
            return match (true) {
                in_array($v, ['youth', 'jugend', 'kind', 'kinder', 'schüler', 'schueler', 'junior'], true) => 'youth',
                in_array($v, ['passive', 'passiv', 'förder', 'foerder', 'fördermitglied', 'ruhend'], true) => 'passive',
                in_array($v, ['honorary', 'ehren', 'ehrenmitglied'], true) => 'honorary',
                in_array($v, ['left', 'ausgetreten', 'gekündigt', 'gekuendigt', 'inaktiv'], true) => 'left',
                default => 'active',
            };
        };

        $importedCount = 0;
        $skippedCount  = 0;

        $stmtInsMem = $pdo->prepare(
            'INSERT INTO club_members
                (club_id, member_no, first_name, last_name, email, phone, street, postal_code, city,
                 birth_date, joined_date, left_date, status, department, board_role, fee_tier_id,
                 iban, bic, sepa_mandate_ref, sepa_signed_date, notes)
             VALUES
                (:cid, :mno, :fn, :ln, :em, :ph, :st, :pc, :ct,
                 :bd, :jd, NULL, :stat, :dep, :br, :ft,
                 :ib, :bic, :sref, NULL, :nt)'
        );

        // FALL A: Übernahme aus dem Cloud-Adressbuch (contacts.php)
        if ($importSource === 'contacts') {
            $stmtContacts = $pdo->prepare(
                'SELECT first_name, last_name, email, phone, mobile, street, postal_code, city, birthday, organization, role_title, notes
                 FROM contacts
                 WHERE user_id = :uid
                 ORDER BY last_name ASC, first_name ASC'
            );
            $stmtContacts->execute([':uid' => $userId]);
            foreach ($stmtContacts->fetchAll() as $ct) {
                $fn = mb_substr(trim((string)$ct['first_name']), 0, 100);
                $ln = mb_substr(trim((string)$ct['last_name']), 0, 100);
                if ($fn === '' && $ln === '') {
                    continue;
                }
                if ($fn === '') {
                    $fn = '–';
                }
                if ($ln === '') {
                    $ln = '–';
                }
                $em = mb_substr(trim((string)($ct['email'] ?? '')), 0, 190);
                $kName = mb_strtolower($fn . '|' . $ln, 'UTF-8');
                $kMail = $em !== '' ? ('mail:' . mb_strtolower($em, 'UTF-8')) : '';

                if ($skipDuplicates && (isset($existingKeys[$kName]) || ($kMail !== '' && isset($existingKeys[$kMail])))) {
                    $skippedCount++;
                    continue;
                }

                $ph = trim((string)($ct['mobile'] ?? '')) !== '' ? trim((string)$ct['mobile']) : trim((string)($ct['phone'] ?? ''));
                $mno = (string)$nextAutoNo++;

                $stmtInsMem->execute([
                    ':cid'  => $activeClubId,
                    ':mno'  => $mno,
                    ':fn'   => $fn,
                    ':ln'   => $ln,
                    ':em'   => $em,
                    ':ph'   => mb_substr($ph, 0, 80),
                    ':st'   => mb_substr(trim((string)($ct['street'] ?? '')), 0, 190),
                    ':pc'   => mb_substr(trim((string)($ct['postal_code'] ?? '')), 0, 30),
                    ':ct'   => mb_substr(trim((string)($ct['city'] ?? '')), 0, 100),
                    ':bd'   => !empty($ct['birthday']) ? (string)$ct['birthday'] : null,
                    ':jd'   => date('Y-m-d'),
                    ':stat' => 'active',
                    ':dep'  => $defaultDept !== '' ? $defaultDept : mb_substr(trim((string)($ct['organization'] ?? '')), 0, 120),
                    ':br'   => mb_substr(trim((string)($ct['role_title'] ?? '')), 0, 120),
                    ':ft'   => $defaultTierId,
                    ':ib'   => '',
                    ':bic'  => '',
                    ':sref' => '',
                    ':nt'   => mb_substr(trim((string)($ct['notes'] ?? '')), 0, 500),
                ]);
                $existingKeys[$kName] = true;
                if ($kMail !== '') {
                    $existingKeys[$kMail] = true;
                }
                $importedCount++;
            }
        } else {
            // FALL B: CSV-Datei oder Copy-&-Paste aus Excel / Tabelle
            $rawCsv = '';
            if (isset($_FILES['csv_file']) && is_array($_FILES['csv_file']) && (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmpPath = (string)$_FILES['csv_file']['tmp_name'];
                $fSize   = (int)($_FILES['csv_file']['size'] ?? 0);
                if (is_uploaded_file($tmpPath) && $fSize > 0 && $fSize <= 5 * 1024 * 1024) {
                    $rawCsv = (string)file_get_contents($tmpPath);
                }
            }
            if ($rawCsv === '' && !empty($_POST['csv_paste_text'])) {
                $rawCsv = (string)$_POST['csv_paste_text'];
            }

            // UTF-8 BOM entfernen & bei Windows-1252 / ISO-8859-1 aus deutschem Excel nach UTF-8 konvertieren
            $rawCsv = preg_replace('/^\xEF\xBB\xBF/', '', $rawCsv) ?? $rawCsv;
            if ($rawCsv !== '' && !mb_check_encoding($rawCsv, 'UTF-8')) {
                $rawCsv = mb_convert_encoding($rawCsv, 'UTF-8', 'Windows-1252,ISO-8859-1');
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($rawCsv)) ?: [];
            $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));

            if (!empty($lines)) {
                // Trennzeichen automatisch erkennen (Tab bei Excel Copy&Paste, sonst ; oder ,)
                $firstLine = $lines[0];
                $delim = ';';
                $cntTab  = substr_count($firstLine, "\t");
                $cntSemi = substr_count($firstLine, ';');
                $cntCom  = substr_count($firstLine, ',');
                if ($cntTab > $cntSemi && $cntTab >= $cntCom) {
                    $delim = "\t";
                } elseif ($cntCom > $cntSemi) {
                    $delim = ',';
                }

                $firstCells = str_getcsv($firstLine, $delim);
                $colMap = [];
                $hasHeader = false;

                foreach ($firstCells as $idx => $cellRaw) {
                    $cNorm = preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower(trim((string)$cellRaw), 'UTF-8')) ?? '';
                    $mapped = match (true) {
                        in_array($cNorm, ['mitgliedsnr', 'mitgliedsnummer', 'nr', 'mnr', 'memberno', 'id'], true) => 'member_no',
                        in_array($cNorm, ['vorname', 'firstname', 'givenname', 'rufname'], true) => 'first_name',
                        in_array($cNorm, ['nachname', 'familienname', 'lastname', 'surname'], true) => 'last_name',
                        in_array($cNorm, ['name', 'vollständigername', 'fullname'], true) => 'full_name',
                        in_array($cNorm, ['status', 'mitgliedsstatus', 'art'], true) => 'status',
                        in_array($cNorm, ['abteilung', 'sparte', 'mannschaft', 'gruppe', 'department'], true) => 'department',
                        in_array($cNorm, ['amt', 'funktion', 'rolle', 'vorstandsamt', 'boardrole'], true) => 'board_role',
                        in_array($cNorm, ['email', 'emailadresse', 'mail'], true) => 'email',
                        in_array($cNorm, ['telefon', 'mobil', 'handy', 'phone', 'telefonnummer'], true) => 'phone',
                        in_array($cNorm, ['strasse', 'straße', 'adresse', 'anschrift', 'street'], true) => 'street',
                        in_array($cNorm, ['plz', 'postleitzahl', 'zip', 'postalcode'], true) => 'postal_code',
                        in_array($cNorm, ['ort', 'stadt', 'wohnort', 'city'], true) => 'city',
                        in_array($cNorm, ['geburtsdatum', 'geburtstag', 'geboren', 'birthdate', 'birthday'], true) => 'birth_date',
                        in_array($cNorm, ['eintrittsdatum', 'eintritt', 'beitritt', 'mitgliedseit', 'joineddate', 'joindate'], true) => 'joined_date',
                        in_array($cNorm, ['iban', 'kontonummer'], true) => 'iban',
                        in_array($cNorm, ['bic', 'swift'], true) => 'bic',
                        in_array($cNorm, ['sepamandatsreferenz', 'mandatsreferenz', 'separef', 'mandat'], true) => 'sepa_mandate_ref',
                        in_array($cNorm, ['notiz', 'notizen', 'bemerkung', 'hinweis', 'notes'], true) => 'notes',
                        default => '',
                    };
                    if ($mapped !== '') {
                        $colMap[$mapped] = $idx;
                        if (in_array($mapped, ['first_name', 'last_name', 'full_name', 'email', 'member_no'], true)) {
                            $hasHeader = true;
                        }
                    }
                }

                // Falls keine Kopfzeile erkannt wurde: Standard-Reihenfolge (Vorname;Nachname;E-Mail;Telefon;Abteilung;Geburtsdatum)
                $startIdx = $hasHeader ? 1 : 0;
                if (!$hasHeader) {
                    $colMap = [
                        'first_name' => 0,
                        'last_name'  => 1,
                        'email'      => 2,
                        'phone'      => 3,
                        'department' => 4,
                        'birth_date' => 5,
                    ];
                }

                for ($i = $startIdx, $totalLines = count($lines); $i < $totalLines; $i++) {
                    $rowCells = str_getcsv($lines[$i], $delim);
                    if (empty($rowCells)) {
                        continue;
                    }
                    $getVal = static fn(string $key): string => isset($colMap[$key], $rowCells[$colMap[$key]])
                        ? trim((string)$rowCells[$colMap[$key]])
                        : '';

                    $fn = $getVal('first_name');
                    $ln = $getVal('last_name');
                    if ($fn === '' && $ln === '' && isset($colMap['full_name'])) {
                        $full = $getVal('full_name');
                        if (str_contains($full, ',')) {
                            [$lnPart, $fnPart] = array_map('trim', explode(',', $full, 2));
                            $fn = $fnPart;
                            $ln = $lnPart;
                        } else {
                            $parts = preg_split('/\s+/', $full) ?: [];
                            $ln = (string)array_pop($parts);
                            $fn = implode(' ', $parts);
                        }
                    }

                    $fn = mb_substr($fn, 0, 100);
                    $ln = mb_substr($ln, 0, 100);
                    if ($fn === '' && $ln === '') {
                        continue;
                    }
                    if ($fn === '') {
                        $fn = '–';
                    }
                    if ($ln === '') {
                        $ln = '–';
                    }

                    $em    = mb_substr($getVal('email'), 0, 190);
                    $kName = mb_strtolower($fn . '|' . $ln, 'UTF-8');
                    $kMail = $em !== '' ? ('mail:' . mb_strtolower($em, 'UTF-8')) : '';

                    if ($skipDuplicates && (isset($existingKeys[$kName]) || ($kMail !== '' && isset($existingKeys[$kMail])))) {
                        $skippedCount++;
                        continue;
                    }

                    $mno = mb_substr($getVal('member_no'), 0, 40);
                    if ($mno === '') {
                        $mno = (string)$nextAutoNo++;
                    }
                    $dep = $getVal('department');
                    if ($dep === '') {
                        $dep = $defaultDept;
                    }

                    $stmtInsMem->execute([
                        ':cid'  => $activeClubId,
                        ':mno'  => $mno,
                        ':fn'   => $fn,
                        ':ln'   => $ln,
                        ':em'   => $em,
                        ':ph'   => mb_substr($getVal('phone'), 0, 80),
                        ':st'   => mb_substr($getVal('street'), 0, 190),
                        ':pc'   => mb_substr($getVal('postal_code'), 0, 30),
                        ':ct'   => mb_substr($getVal('city'), 0, 100),
                        ':bd'   => $parseFlexibleDate($getVal('birth_date')),
                        ':jd'   => $parseFlexibleDate($getVal('joined_date')) ?? date('Y-m-d'),
                        ':stat' => $normalizeStatus($getVal('status')),
                        ':dep'  => mb_substr($dep, 0, 120),
                        ':br'   => mb_substr($getVal('board_role'), 0, 120),
                        ':ft'   => $defaultTierId,
                        ':ib'   => $canWriteFees ? strtoupper(preg_replace('/\s+/', '', mb_substr($getVal('iban'), 0, 50)) ?? '') : '',
                        ':bic'  => $canWriteFees ? strtoupper(mb_substr($getVal('bic'), 0, 30)) : '',
                        ':sref' => $canWriteFees ? mb_substr($getVal('sepa_mandate_ref'), 0, 80) : '',
                        ':nt'   => mb_substr($getVal('notes'), 0, 500),
                    ]);

                    $existingKeys[$kName] = true;
                    if ($kMail !== '') {
                        $existingKeys[$kMail] = true;
                    }
                    $importedCount++;
                }
            }
        }

        if ($importedCount > 0) {
            setFlash(
                'success',
                sprintf(
                    '✅ %d %s erfolgreich importiert%s.',
                    $importedCount,
                    $importedCount === 1 ? 'Mitglied wurde' : 'Mitglieder wurden',
                    $skippedCount > 0 ? sprintf(' (%d vorhandene Duplikate übersprungen)', $skippedCount) : ''
                )
            );
        } else {
            setFlash(
                'warning',
                $skippedCount > 0
                    ? sprintf('Keine neuen Mitglieder importiert (%d Einträge waren bereits vorhanden).', $skippedCount)
                    : 'Keine gültigen Mitgliedszeilen zum Importieren gefunden.'
            );
        }

        header('Location: club.php?club=' . $activeClubId . '#tab-members');
        exit;
    }
}

// --- Daten für die Anzeige laden ---

// 1. Beitragstarife
$stmtTiers = $pdo->prepare('SELECT * FROM club_fee_tiers WHERE club_id = :cid ORDER BY amount DESC, id ASC');
$stmtTiers->execute([':cid' => $activeClubId]);
$feeTiers = $stmtTiers->fetchAll();
$tiersById = [];
foreach ($feeTiers as $ft) {
    $tiersById[(int)$ft['id']] = $ft;
}

// 2. Mitgliederliste + geleistete Arbeitsstunden im gewählten Jahr
$stmtMembers = $pdo->prepare(
    'SELECT m.*,
            t.title AS tier_title, t.amount AS tier_amount, t.billing_cycle AS tier_cycle,
            COALESCE((SELECT SUM(w.hours) FROM club_work_logs w WHERE w.member_id = m.id AND YEAR(w.work_date) = :yr), 0) AS work_hours_year
     FROM club_members m
     LEFT JOIN club_fee_tiers t ON t.id = m.fee_tier_id
     WHERE m.club_id = :cid
     ORDER BY m.last_name ASC, m.first_name ASC'
);
$stmtMembers->execute([
    ':yr'  => (int)$selectedYear,
    ':cid' => $activeClubId,
]);
$members = $stmtMembers->fetchAll();

// Kennzahlen & umfangreiche Vereinsstatistiken berechnen (Altersverteilung, Abteilungsgrößen, Zugehörigkeit, Jubiläen)
$statsTotalMembers   = 0;
$statsActiveMembers  = 0;
$statsYouthMembers   = 0;
$statsBoardMembers   = 0;
$upcomingBirthdays   = [];

$statStatusCounts = [
    'active'   => 0,
    'youth'    => 0,
    'passive'  => 0,
    'honorary' => 0,
    'left'     => 0,
];
$statAgeBrackets = [
    'u14'     => ['label' => '👶 Unter 14 J. (Kinder)',          'count' => 0, 'color' => 'bg-sky-500'],
    '14_17'   => ['label' => '🌱 14 – 17 J. (Jugend)',           'count' => 0, 'color' => 'bg-cyan-500'],
    '18_29'   => ['label' => '🏃 18 – 29 J. (Junge Erwachsene)', 'count' => 0, 'color' => 'bg-emerald-500'],
    '30_44'   => ['label' => '⚡ 30 – 44 Jahre',                 'count' => 0, 'color' => 'bg-brand-600'],
    '45_59'   => ['label' => '🏅 45 – 59 Jahre',                 'count' => 0, 'color' => 'bg-indigo-500'],
    '60_74'   => ['label' => '🌿 60 – 74 Jahre',                 'count' => 0, 'color' => 'bg-amber-500'],
    '75_plus' => ['label' => '👑 Ab 75 Jahre (Senioren)',        'count' => 0, 'color' => 'bg-purple-500'],
    'unknown' => ['label' => '❓ Ohne Geburtsdatum',             'count' => 0, 'color' => 'bg-slate-400'],
];
$statAgeSum      = 0;
$statAgeKnownCnt = 0;
$statMinAge      = null;
$statMaxAge      = null;

$statDepartments = []; // Name => Anzahl aktive Mitglieder
$statTariffDist  = []; // Tarif-Name => ['count' => int, 'annual_sum' => float]
$statAnnualProjectedFees = 0.0;

$statTenureBrackets = [
    'u2'      => ['label' => '🆕 Neu (< 2 Jahre)',        'count' => 0, 'color' => 'bg-cyan-500'],
    '2_5'     => ['label' => '🌱 2 – 5 Jahre',            'count' => 0, 'color' => 'bg-emerald-500'],
    '6_10'    => ['label' => '🤝 6 – 10 Jahre',           'count' => 0, 'color' => 'bg-brand-600'],
    '11_24'   => ['label' => '🏅 11 – 24 Jahre',          'count' => 0, 'color' => 'bg-indigo-500'],
    '25_plus' => ['label' => '👑 25+ Jahre (Ehrentreue)', 'count' => 0, 'color' => 'bg-amber-500'],
];
$statTenureSum      = 0;
$statTenureKnownCnt = 0;
$clubAnniversaries  = []; // Jubiläen im gewählten Jahr (5, 10, 15, 20, 25, 30, 40, 50, 60, 70 J.)
$statJoinsByYear    = [];
$statLeavesByYear   = [];
for ($y = (int)$selectedYear - 4; $y <= (int)$selectedYear; $y++) {
    $statJoinsByYear[(string)$y]  = 0;
    $statLeavesByYear[(string)$y] = 0;
}
$statWorkFulfilledCount = 0;
$reqWorkHours = max(0.0, (float)$activeClub['default_work_hours']);

foreach ($members as $m) {
    $mStat = (string)$m['status'];
    if (isset($statStatusCounts[$mStat])) {
        $statStatusCounts[$mStat]++;
    }

    // Ein- und Austritte nach Jahr zählen
    if (!empty($m['joined_date'])) {
        $jYear = substr((string)$m['joined_date'], 0, 4);
        if (isset($statJoinsByYear[$jYear])) {
            $statJoinsByYear[$jYear]++;
        }
    }
    if (!empty($m['left_date'])) {
        $lYear = substr((string)$m['left_date'], 0, 4);
        if (isset($statLeavesByYear[$lYear])) {
            $statLeavesByYear[$lYear]++;
        }
    }

    if ($mStat !== 'left') {
        $statsTotalMembers++;
    }
    if ($mStat === 'active') {
        $statsActiveMembers++;
    } elseif ($mStat === 'youth') {
        $statsYouthMembers++;
    }
    if ($mStat !== 'left' && trim((string)$m['board_role']) !== '') {
        $statsBoardMembers++;
    }

    // Für aktive Vereinsmitglieder (nicht ausgetreten): Abteilungen, Alter, Tarif, Zugehörigkeit, Arbeitsstunden
    if ($mStat !== 'left') {
        // 1. Abteilungsgrößen (unterstützt auch Komma-getrennte Mehrfach-Abteilungen wie "Fußball, Tennis")
        $rawDep = trim((string)$m['department']);
        if ($rawDep === '') {
            $depList = ['Hauptverein (Ohne Sparte)'];
        } else {
            $depList = array_filter(array_map('trim', preg_split('/[,;\/]+/', $rawDep) ?: [$rawDep]));
            if (empty($depList)) {
                $depList = [$rawDep];
            }
        }
        foreach ($depList as $dName) {
            $statDepartments[$dName] = ($statDepartments[$dName] ?? 0) + 1;
        }

        // 2. Altersverteilung & Geburtstagsvorschau
        if (!empty($m['birth_date'])) {
            $bParts = explode('-', (string)$m['birth_date']);
            if (count($bParts) === 3) {
                $birthTs = strtotime((string)$m['birth_date']);
                if ($birthTs !== false) {
                    $ageNow = (int)date('Y') - (int)$bParts[0];
                    if (date('m-d') < ($bParts[1] . '-' . $bParts[2])) {
                        $ageNow--;
                    }
                    $ageNow = max(0, $ageNow);
                    $statAgeSum += $ageNow;
                    $statAgeKnownCnt++;
                    if ($statMinAge === null || $ageNow < $statMinAge) {
                        $statMinAge = $ageNow;
                    }
                    if ($statMaxAge === null || $ageNow > $statMaxAge) {
                        $statMaxAge = $ageNow;
                    }

                    if ($ageNow < 14) {
                        $statAgeBrackets['u14']['count']++;
                    } elseif ($ageNow <= 17) {
                        $statAgeBrackets['14_17']['count']++;
                    } elseif ($ageNow <= 29) {
                        $statAgeBrackets['18_29']['count']++;
                    } elseif ($ageNow <= 44) {
                        $statAgeBrackets['30_44']['count']++;
                    } elseif ($ageNow <= 59) {
                        $statAgeBrackets['45_59']['count']++;
                    } elseif ($ageNow <= 74) {
                        $statAgeBrackets['60_74']['count']++;
                    } else {
                        $statAgeBrackets['75_plus']['count']++;
                    }
                }

                $nextBday = date('Y') . '-' . $bParts[1] . '-' . $bParts[2];
                if ($nextBday < date('Y-m-d')) {
                    $nextBday = ((int)date('Y') + 1) . '-' . $bParts[1] . '-' . $bParts[2];
                }
                $daysLeft = (int)round((strtotime($nextBday) - strtotime(date('Y-m-d'))) / 86400);
                if ($daysLeft >= 0 && $daysLeft <= 60) {
                    $turningAge = (int)substr($nextBday, 0, 4) - (int)$bParts[0];
                    $upcomingBirthdays[] = [
                        'name'        => $m['first_name'] . ' ' . $m['last_name'],
                        'date_fmt'    => $bParts[2] . '.' . $bParts[1] . '.',
                        'days_left'   => $daysLeft,
                        'turning_age' => $turningAge,
                        'is_round'    => ($turningAge > 0 && $turningAge % 10 === 0),
                    ];
                }
            } else {
                $statAgeBrackets['unknown']['count']++;
            }
        } else {
            $statAgeBrackets['unknown']['count']++;
        }

        // 3. Beitragsstruktur & Jahresprognose
        $tTitle = !empty($m['tier_title']) ? (string)$m['tier_title'] : 'Beitragsfrei / Kein Tarif';
        $tBase  = (float)($m['tier_amount'] ?? 0.0);
        $tCycle = (string)($m['tier_cycle'] ?? 'yearly');
        $tAnnual = match ($tCycle) {
            'monthly'     => round($tBase * 12, 2),
            'quarterly'   => round($tBase * 4, 2),
            'half_yearly' => round($tBase * 2, 2),
            default       => $tBase,
        };
        if (!isset($statTariffDist[$tTitle])) {
            $statTariffDist[$tTitle] = ['count' => 0, 'annual_sum' => 0.0];
        }
        $statTariffDist[$tTitle]['count']++;
        $statTariffDist[$tTitle]['annual_sum'] += $tAnnual;
        $statAnnualProjectedFees += $tAnnual;

        // 4. Vereinszugehörigkeit & Jubiläen
        if (!empty($m['joined_date'])) {
            $jParts = explode('-', (string)$m['joined_date']);
            if (count($jParts) === 3) {
                $yearsInClub = max(0, (int)$selectedYear - (int)$jParts[0]);
                $statTenureSum += $yearsInClub;
                $statTenureKnownCnt++;

                if ($yearsInClub < 2) {
                    $statTenureBrackets['u2']['count']++;
                } elseif ($yearsInClub <= 5) {
                    $statTenureBrackets['2_5']['count']++;
                } elseif ($yearsInClub <= 10) {
                    $statTenureBrackets['6_10']['count']++;
                } elseif ($yearsInClub <= 24) {
                    $statTenureBrackets['11_24']['count']++;
                } else {
                    $statTenureBrackets['25_plus']['count']++;
                }

                if (in_array($yearsInClub, [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 60, 70, 75], true)) {
                    $clubAnniversaries[] = [
                        'name'        => $m['first_name'] . ' ' . $m['last_name'],
                        'member_no'   => (string)$m['member_no'],
                        'department'  => (string)$m['department'],
                        'joined_year' => (int)$jParts[0],
                        'years'       => $yearsInClub,
                    ];
                }
            }
        }

        // 5. Pflichtstunden erfüllt?
        if ($reqWorkHours > 0.0 && (float)$m['work_hours_year'] >= $reqWorkHours) {
            $statWorkFulfilledCount++;
        }
    }
}
arsort($statDepartments);
uasort($statTariffDist, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);
usort($clubAnniversaries, static fn(array $a, array $b): int => $b['years'] <=> $a['years']);
$statAvgAge    = $statAgeKnownCnt > 0 ? round($statAgeSum / $statAgeKnownCnt, 1) : 0.0;
$statAvgTenure = $statTenureKnownCnt > 0 ? round($statTenureSum / $statTenureKnownCnt, 1) : 0.0;
usort($upcomingBirthdays, static fn(array $a, array $b): int => $a['days_left'] <=> $b['days_left']);

// 3. Beitragszahlungen im gewählten Beitragsjahr
$feePayments = [];
$feeSumPaid  = 0.0;
$feeSumOpen  = 0.0;
if ($canReadFees) {
    $stmtPay = $pdo->prepare(
        'SELECT p.*, m.member_no, m.first_name, m.last_name, m.iban, m.sepa_mandate_ref, m.department
         FROM club_fee_payments p
         INNER JOIN club_members m ON m.id = p.member_id
         WHERE p.club_id = :cid AND p.period_label = :pl
         ORDER BY FIELD(p.status, "overdue", "open", "paid", "waived"), m.last_name ASC'
    );
    $stmtPay->execute([':cid' => $activeClubId, ':pl' => $selectedYear]);
    $feePayments = $stmtPay->fetchAll();
    foreach ($feePayments as $fp) {
        if ($fp['status'] === 'paid') {
            $feeSumPaid += (float)$fp['amount'];
        } elseif ($fp['status'] === 'open' || $fp['status'] === 'overdue') {
            $feeSumOpen += (float)$fp['amount'];
        }
    }
}

// 4. Arbeitsstunden & Inventar
$workLogs = [];
$inventoryItems = [];
$totalWorkHoursYear = 0.0;
if ($canReadWorkInv) {
    $stmtWL = $pdo->prepare(
        'SELECT w.*, m.first_name, m.last_name, m.member_no
         FROM club_work_logs w
         INNER JOIN club_members m ON m.id = w.member_id
         WHERE w.club_id = :cid AND YEAR(w.work_date) = :yr
         ORDER BY w.work_date DESC, w.id DESC'
    );
    $stmtWL->execute([':cid' => $activeClubId, ':yr' => (int)$selectedYear]);
    $workLogs = $stmtWL->fetchAll();
    foreach ($workLogs as $wl) {
        $totalWorkHoursYear += (float)$wl['hours'];
    }

    $stmtInv = $pdo->prepare(
        'SELECT i.*, m.first_name AS loan_first_name, m.last_name AS loan_last_name, m.member_no AS loan_member_no
         FROM club_inventory i
         LEFT JOIN club_members m ON m.id = i.loaned_to_member_id
         WHERE i.club_id = :cid
         ORDER BY i.category ASC, i.item_name ASC'
    );
    $stmtInv->execute([':cid' => $activeClubId]);
    $inventoryItems = $stmtInv->fetchAll();
}

// 5. Vereinsdokumente & Protokolle
$clubDocs = [];
if ($canReadDocs) {
    $stmtDocs = $pdo->prepare(
        'SELECT d.*, f.original_name AS file_name, f.file_size, u.username AS author_name
         FROM club_documents d
         LEFT JOIN files f ON f.id = d.file_id AND f.deleted_at IS NULL
         LEFT JOIN users u ON u.id = d.created_by
         WHERE d.club_id = :cid
         ORDER BY d.doc_date DESC, d.id DESC'
    );
    $stmtDocs->execute([':cid' => $activeClubId]);
    $clubDocs = $stmtDocs->fetchAll();
}

// 6. Vorstands-Rollen & Berechtigungen (Aufgabenteilung im Verein)
$stmtRoles = $pdo->prepare(
    'SELECT cr.*, u.username
     FROM club_roles cr
     INNER JOIN users u ON u.id = cr.user_id
     WHERE cr.club_id = :cid
     ORDER BY cr.id ASC'
);
$stmtRoles->execute([':cid' => $activeClubId]);
$clubRoles = $stmtRoles->fetchAll();

// Sichtbare Cloud-Benutzer für die Rollenvergabe laden
$shareableUsers = [];
if ($isFullClubAdmin) {
    $stmtVisUsers = $pdo->prepare(
        'SELECT id, username FROM users WHERE allow_user_sharing = 1 AND is_locked = 0 AND id != :owner_id ORDER BY username ASC'
    );
    $stmtVisUsers->execute([':owner_id' => (int)$activeClub['user_id']]);
    $shareableUsers = $stmtVisUsers->fetchAll();
}

// Eigene Cloud-Dateien (für Protokoll-/Satzungs-Verknüpfung)
$myCloudFiles = [];
if ($canWriteDocs) {
    $stmtFiles = $pdo->prepare(
        'SELECT id, original_name, folder_path FROM files WHERE user_id = :uid AND deleted_at IS NULL ORDER BY upload_date DESC LIMIT 120'
    );
    $stmtFiles->execute([':uid' => $userId]);
    $myCloudFiles = $stmtFiles->fetchAll();
}

// Verfügbare Haushaltsbücher (für 1-Klick-Kassen-Kopplung)
$myBudgetBooks = [];
if ($isFullClubAdmin) {
    try {
        $stmtBB = $pdo->prepare(
            'SELECT id, title, currency FROM budget_books WHERE user_id = :uid OR FIND_IN_SET(:uid_str, shared_users) > 0 ORDER BY id ASC'
        );
        $stmtBB->execute([':uid' => $userId, ':uid_str' => (string)$userId]);
        $myBudgetBooks = $stmtBB->fetchAll();
    } catch (Throwable) {
        $myBudgetBooks = [];
    }
}

$statusBadges = [
    'active'   => ['label' => '🟢 Aktiv',         'cls' => 'bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300'],
    'youth'    => ['label' => '🌱 Jugend',        'cls' => 'bg-sky-100 dark:bg-sky-950/70 text-sky-800 dark:text-sky-300'],
    'passive'  => ['label' => '⏸️ Passiv/Förder', 'cls' => 'bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300'],
    'honorary' => ['label' => '🏅 Ehrenmitglied', 'cls' => 'bg-purple-100 dark:bg-purple-950/70 text-purple-800 dark:text-purple-300'],
    'left'     => ['label' => '🚪 Ausgetreten',   'cls' => 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400'],
];

$pageTitle = 'Vereinsverwaltung – ' . (string)$activeClub['name'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- KOPFBEREICH & VEREINS-UMSCHALTER -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 flex items-center justify-center text-2xl shrink-0">
                    <?= e((string)($activeClub['icon'] ?: '🏛️')) ?>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                            <?= e((string)$activeClub['name']) ?>
                        </h1>
                        <?php if ((string)$activeClub['short_name'] !== ''): ?>
                            <span class="px-2 py-0.5 rounded-lg text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                <?= e((string)$activeClub['short_name']) ?>
                            </span>
                        <?php endif; ?>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300">
                            🛡️ Ihr Amt: <?= e($myRoleTitle) ?>
                        </span>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                        <?= e((string)($activeClub['description'] ?: 'DSGVO-konforme Vereins-, Beitrags-, Inventar- & Vorstandsverwaltung')) ?>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Vereins-Auswahl -->
                <form action="club.php" method="get" class="inline-flex items-center gap-1.5">
                    <select
                        name="club"
                        onchange="this.form.submit()"
                        class="px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100"
                    >
                        <?php foreach ($allClubs as $cItem): ?>
                            <option value="<?= (int)$cItem['id'] ?>" <?= (int)$cItem['id'] === $activeClubId ? 'selected' : '' ?>>
                                <?= e((string)($cItem['icon'] ?: '🏛️') . ' ' . (string)$cItem['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <!-- Beitrags-/Geschäftsjahr -->
                <form action="club.php" method="get" class="inline-flex items-center gap-1.5">
                    <input type="hidden" name="club" value="<?= $activeClubId ?>">
                    <select
                        name="year"
                        onchange="this.form.submit()"
                        class="px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100"
                        title="Vereins-/Beitragsjahr wählen"
                    >
                        <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 4; $y--): ?>
                            <option value="<?= $y ?>" <?= (string)$y === $selectedYear ? 'selected' : '' ?>>📅 Jahr <?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </form>

                <button
                    type="button"
                    onclick="document.getElementById('newClubModal').classList.remove('hidden')"
                    class="px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    + Neuer Verein
                </button>
            </div>
        </div>

        <!-- 4 KPI-Kacheln -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mt-5 pt-5 border-t border-slate-200/80 dark:border-slate-800">
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">👥 Mitgliederbestand</p>
                <p class="text-xl font-extrabold text-slate-900 dark:text-white mt-0.5"><?= $statsTotalMembers ?></p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                    <?= $statsActiveMembers ?> Aktiv · <?= $statsYouthMembers ?> Jugend · <?= $statsBoardMembers ?> Vorstand
                </p>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">💶 Beiträge <?= e($selectedYear) ?> (Bezahlt)</p>
                <?php if ($canReadFees): ?>
                    <p class="text-xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-0.5"><?= e(formatClubMoney($feeSumPaid, $currency)) ?></p>
                    <p class="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
                        Offen: <?= e(formatClubMoney($feeSumOpen, $currency)) ?>
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-400 mt-1">🔒 Nur Kassenwart</p>
                    <p class="text-[11px] text-slate-400">Kein Finanz-Zugriff</p>
                <?php endif; ?>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">🛠️ Helferstunden <?= e($selectedYear) ?></p>
                <?php if ($canReadWorkInv): ?>
                    <p class="text-xl font-extrabold text-indigo-600 dark:text-indigo-400 mt-0.5"><?= number_format($totalWorkHoursYear, 1, ',', '.') ?> Std.</p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Soll: <?= number_format((float)$activeClub['default_work_hours'], 1, ',', '.') ?> Std./Mitglied · <?= count($inventoryItems) ?> Inventarposten
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-400 mt-1">🔒 Kein Zugriff</p>
                    <p class="text-[11px] text-slate-400">Geräte-/Arbeitsdienst</p>
                <?php endif; ?>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">🎂 Nächste Geburtstage</p>
                <?php if (!empty($upcomingBirthdays)): ?>
                    <?php $nb = $upcomingBirthdays[0]; ?>
                    <p class="text-sm font-bold text-slate-900 dark:text-white mt-1 truncate">
                        <?= e($nb['name']) ?> (<?= $nb['turning_age'] ?> J.)
                    </p>
                    <p class="text-[11px] text-brand-600 dark:text-brand-400 font-semibold">
                        <?= e($nb['date_fmt']) ?> (<?= $nb['days_left'] === 0 ? 'Heute!' : 'in ' . $nb['days_left'] . ' Tagen' ?>)
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-500 dark:text-slate-400 mt-1">Keine in 60 Tagen</p>
                    <p class="text-[11px] text-slate-400"><?= count($clubDocs) ?> Vereinsdokumente</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- REITER-NAVIGATION (TABS JE NACH BERECHTIGUNG DES VORSTANDSMITGLIEDS) -->
    <nav class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-2 shadow-xs flex flex-wrap items-center gap-1.5">
        <?php if ($canReadMembers): ?>
            <button type="button" data-club-tab-btn="tab-members" onclick="switchClubTab('tab-members')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
                <span>👥</span>
                <span>Mitglieder &amp; Abteilungen</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= $statsTotalMembers ?></span>
            </button>
        <?php endif; ?>

        <?php if ($canReadFees): ?>
            <button type="button" data-club-tab-btn="tab-fees" onclick="switchClubTab('tab-fees')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
                <span>💶</span>
                <span>Beiträge, Tarife &amp; Kasse</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= count($feePayments) ?></span>
            </button>
        <?php endif; ?>

        <?php if ($canReadWorkInv): ?>
            <button type="button" data-club-tab-btn="tab-work" onclick="switchClubTab('tab-work')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
                <span>🛠️</span>
                <span>Arbeitsstunden, Inventar &amp; Schlüssel</span>
            </button>
        <?php endif; ?>

        <?php if ($canReadDocs): ?>
            <button type="button" data-club-tab-btn="tab-docs" onclick="switchClubTab('tab-docs')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
                <span>📑</span>
                <span>Protokolle &amp; Dokumente</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= count($clubDocs) ?></span>
            </button>
        <?php endif; ?>

        <?php if ($canReadMembers): ?>
            <button type="button" data-club-tab-btn="tab-stats" onclick="switchClubTab('tab-stats')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
                <span>📊</span>
                <span>Statistik &amp; Auswertungen</span>
            </button>
        <?php endif; ?>

        <button type="button" data-club-tab-btn="tab-roles" onclick="switchClubTab('tab-roles')" class="club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer">
            <span>🛡️</span>
            <span>Vorstand &amp; Rollenverteilung</span>
            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300"><?= count($clubRoles) + 1 ?></span>
        </button>
    </nav>

    <!-- =================================================================== -->
    <!-- TAB 1: MITGLIEDER & ABTEILUNGEN                                     -->
    <!-- =================================================================== -->
    <?php if ($canReadMembers): ?>
    <div id="tab-members" data-club-tab-panel="tab-members" class="space-y-6">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-200 dark:border-slate-800">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">👥 Mitgliederverzeichnis &amp; Abteilungen</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Stammdaten, Mitgliedsnummern, Abteilungen/Sparten, Vorstandsämter und Pflichtstunden-Stand.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        type="search"
                        id="clubMemberSearch"
                        oninput="filterClubMembers(this.value)"
                        placeholder="🔍 Name, Nr., Abteilung, Amt suchen…"
                        class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs w-60"
                    >
                    <form action="club.php?club=<?= $activeClubId ?>" method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="export_csv_to_cloud">
                        <input type="hidden" name="export_type" value="members">
                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer" title="Mitgliederliste als Excel-CSV in den Cloud-Ordner Vereinsverwaltung speichern">
                            📥 CSV in Cloud
                        </button>
                    </form>
                    <?php if ($canWriteMembers): ?>
                        <button
                            type="button"
                            onclick="document.getElementById('importMembersModal').classList.remove('hidden')"
                            class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 text-xs font-semibold cursor-pointer"
                            title="Mitglieder per CSV/Excel-Datei, Copy &amp; Paste oder aus den Cloud-Kontakten importieren"
                        >
                            📤 Importieren
                        </button>
                        <button
                            type="button"
                            onclick="openMemberModal()"
                            class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs cursor-pointer"
                        >
                            + Mitglied aufnehmen
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs sm:text-sm">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-[11px] font-semibold uppercase text-slate-500 dark:text-slate-400">
                            <th class="py-2.5 px-3">Nr. &amp; Name</th>
                            <th class="py-2.5 px-3">Status &amp; Abteilung</th>
                            <th class="py-2.5 px-3">Kontakt &amp; Adresse</th>
                            <th class="py-2.5 px-3">Tarif &amp; Pflichtstunden (<?= e($selectedYear) ?>)</th>
                            <?php if ($canWriteMembers): ?>
                                <th class="py-2.5 px-3 text-right">Aktion</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($members as $m): ?>
                            <?php
                            $stMeta = $statusBadges[$m['status']] ?? $statusBadges['active'];
                            $whDone = (float)$m['work_hours_year'];
                            $whTarget = (float)$activeClub['default_work_hours'];
                            $whPct = $whTarget > 0 ? min(100, (int)round(($whDone / $whTarget) * 100)) : 100;
                            $searchStr = mb_strtolower($m['member_no'] . ' ' . $m['first_name'] . ' ' . $m['last_name'] . ' ' . $m['department'] . ' ' . $m['board_role'] . ' ' . $m['email'] . ' ' . $m['city'], 'UTF-8');
                            ?>
                            <tr data-club-member-row="1" data-search="<?= e($searchStr) ?>" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <td class="py-3 px-3">
                                    <div class="flex items-center gap-2">
                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-[11px] font-mono text-slate-600 dark:text-slate-300">#<?= e((string)$m['member_no']) ?></span>
                                        <span class="font-bold text-slate-900 dark:text-white"><?= e($m['last_name'] . ', ' . $m['first_name']) ?></span>
                                    </div>
                                    <?php if ((string)$m['board_role'] !== ''): ?>
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300">
                                            🏅 <?= e((string)$m['board_role']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <div class="text-[11px] text-slate-400 mt-0.5">
                                        <?php if (!empty($m['birth_date'])): ?>🎂 <?= e(date('d.m.Y', strtotime((string)$m['birth_date']))) ?> · <?php endif; ?>
                                        <?php if (!empty($m['joined_date'])): ?>Eintritt: <?= e(date('d.m.Y', strtotime((string)$m['joined_date']))) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold <?= $stMeta['cls'] ?>">
                                        <?= $stMeta['label'] ?>
                                    </span>
                                    <?php if ((string)$m['department'] !== ''): ?>
                                        <div class="text-xs font-medium text-slate-700 dark:text-slate-300 mt-1">🏷️ <?= e((string)$m['department']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-xs text-slate-600 dark:text-slate-300">
                                    <?php if ((string)$m['email'] !== ''): ?><div>✉️ <?= e((string)$m['email']) ?></div><?php endif; ?>
                                    <?php if ((string)$m['phone'] !== ''): ?><div>📞 <?= e((string)$m['phone']) ?></div><?php endif; ?>
                                    <?php if ((string)$m['city'] !== '' || (string)$m['street'] !== ''): ?>
                                        <div class="text-[11px] text-slate-400"><?= e(trim($m['street'] . ', ' . $m['postal_code'] . ' ' . $m['city'], ', ')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-xs">
                                    <?php if (!empty($m['tier_title'])): ?>
                                        <div class="font-semibold text-slate-800 dark:text-slate-200">
                                            <?= e((string)$m['tier_title']) ?> (<?= e(formatClubMoney((float)$m['tier_amount'], $currency)) ?>)
                                        </div>
                                    <?php else: ?>
                                        <div class="text-slate-400 italic">Kein Tarif zugeordnet</div>
                                    <?php endif; ?>
                                    <?php if ($whTarget > 0 && $m['status'] === 'active'): ?>
                                        <div class="mt-1.5 flex items-center gap-2">
                                            <div class="w-24 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                                <div class="h-full <?= $whDone >= $whTarget ? 'bg-emerald-500' : 'bg-indigo-500' ?>" style="width: <?= $whPct ?>%"></div>
                                            </div>
                                            <span class="text-[11px] text-slate-500"><?= number_format($whDone, 1, ',', '.') ?> / <?= number_format($whTarget, 0, ',', '.') ?> Std.</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canWriteMembers): ?>
                                    <td class="py-3 px-3 text-right whitespace-nowrap">
                                        <button
                                            type="button"
                                            onclick='openMemberModal(<?= json_encode([
                                                "id" => (int)$m["id"],
                                                "member_no" => (string)$m["member_no"],
                                                "first_name" => (string)$m["first_name"],
                                                "last_name" => (string)$m["last_name"],
                                                "email" => (string)$m["email"],
                                                "phone" => (string)$m["phone"],
                                                "street" => (string)$m["street"],
                                                "postal_code" => (string)$m["postal_code"],
                                                "city" => (string)$m["city"],
                                                "birth_date" => (string)($m["birth_date"] ?? ""),
                                                "joined_date" => (string)($m["joined_date"] ?? ""),
                                                "left_date" => (string)($m["left_date"] ?? ""),
                                                "status" => (string)$m["status"],
                                                "department" => (string)$m["department"],
                                                "board_role" => (string)$m["board_role"],
                                                "fee_tier_id" => (int)($m["fee_tier_id"] ?? 0),
                                                "iban" => $canReadFees ? (string)$m["iban"] : "",
                                                "bic" => $canReadFees ? (string)$m["bic"] : "",
                                                "sepa_mandate_ref" => $canReadFees ? (string)$m["sepa_mandate_ref"] : "",
                                                "sepa_signed_date" => $canReadFees ? (string)($m["sepa_signed_date"] ?? "") : "",
                                                "notes" => (string)$m["notes"],
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-medium cursor-pointer"
                                        >
                                            ✏️ Bearbeiten
                                        </button>
                                        <form action="club.php?club=<?= $activeClubId ?>" method="post" class="inline" onsubmit="return confirm('Mitglied wirklich aus dem Verein löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_member">
                                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                            <button type="submit" class="px-2 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-300 text-xs cursor-pointer">🗑️</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- TAB 2: BEITRÄGE, TARIFE & VEREINSKASSE                              -->
    <!-- =================================================================== -->
    <?php if ($canReadFees): ?>
    <div id="tab-fees" data-club-tab-panel="tab-fees" class="hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Beitragstarife -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">🏷️ Beitragsgruppen / Tarife</h2>
                <div class="space-y-2">
                    <?php foreach ($feeTiers as $ft): ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between gap-2">
                            <div>
                                <p class="text-xs font-bold text-slate-900 dark:text-white"><?= e((string)$ft['title']) ?></p>
                                <p class="text-[11px] text-brand-600 dark:text-brand-400 font-semibold">
                                    <?= e(formatClubMoney((float)$ft['amount'], $currency)) ?> /
                                    <?= match ((string)$ft['billing_cycle']) {
                                        'monthly' => 'Monat',
                                        'quarterly' => 'Quartal',
                                        'half_yearly' => 'Halbjahr',
                                        default => 'Jahr',
                                    } ?>
                                </p>
                                <?php if ((string)$ft['description'] !== ''): ?>
                                    <p class="text-[10px] text-slate-400"><?= e((string)$ft['description']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($canWriteFees): ?>
                                <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_fee_tier">
                                    <input type="hidden" name="tier_id" value="<?= (int)$ft['id'] ?>">
                                    <button type="submit" class="text-xs text-red-500 hover:text-red-700 cursor-pointer" title="Tarif löschen">✕</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($canWriteFees): ?>
                    <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="pt-3 border-t border-slate-200 dark:border-slate-800 space-y-2.5">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add_fee_tier">
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-300">+ Neuen Tarif anlegen</p>
                        <input type="text" name="title" required placeholder="z. B. Familienbeitrag" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" name="amount" required placeholder="Betrag (z. B. 60,00)" class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <select name="billing_cycle" class="px-2 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="yearly">Jährlich</option>
                                <option value="half_yearly">Halbjährlich</option>
                                <option value="quarterly">Vierteljährlich</option>
                                <option value="monthly">Monatlich</option>
                            </select>
                        </div>
                        <input type="text" name="description" placeholder="Hinweis (optional)" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <button type="submit" class="w-full py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">Tarif speichern</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Beitragsliste & 1-Klick-Sollstellung -->
            <div class="lg:col-span-2 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h2 class="text-base font-bold text-slate-900 dark:text-white">💶 Mitgliedsbeiträge <?= e($selectedYear) ?></h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Bezahlte Beiträge: <strong class="text-emerald-600"><?= e(formatClubMoney($feeSumPaid, $currency)) ?></strong> · Offen: <strong class="text-amber-600"><?= e(formatClubMoney($feeSumOpen, $currency)) ?></strong>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($canWriteFees): ?>
                            <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="generate_yearly_fees">
                                <input type="hidden" name="period_label" value="<?= e($selectedYear) ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">
                                    ⚡ Jahresbeiträge <?= e($selectedYear) ?> sollstellen
                                </button>
                            </form>
                            <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="sync_fees_to_budget">
                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold cursor-pointer" title="Bezahlte Beiträge als Einnahmen ins verknüpfte Haushaltsbuch übertragen">
                                    💰 In Haushaltsbuch übernehmen
                                </button>
                            </form>
                        <?php endif; ?>
                        <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="export_csv_to_cloud">
                            <input type="hidden" name="export_type" value="fees">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer">
                                📥 SEPA/Beitrags-CSV
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (empty($feePayments)): ?>
                    <p class="text-xs text-slate-500 italic py-6 text-center">
                        Für <?= e($selectedYear) ?> wurden noch keine Beiträge sollgestellt. Klicken Sie oben auf „⚡ Jahresbeiträge <?= e($selectedYear) ?> sollstellen“.
                    </p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-[11px] font-semibold uppercase text-slate-500">
                                    <th class="py-2 px-3">Mitglied</th>
                                    <th class="py-2 px-3">Betrag</th>
                                    <th class="py-2 px-3">SEPA / IBAN</th>
                                    <th class="py-2 px-3">Status</th>
                                    <?php if ($canWriteFees): ?>
                                        <th class="py-2 px-3 text-right">Verbuchen</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($feePayments as $fp): ?>
                                    <tr>
                                        <td class="py-2.5 px-3">
                                            <div class="font-bold text-slate-900 dark:text-white"><?= e($fp['last_name'] . ', ' . $fp['first_name']) ?> <span class="text-slate-400 font-normal">(#<?= e((string)$fp['member_no']) ?>)</span></div>
                                            <div class="text-[11px] text-slate-400"><?= e((string)$fp['note']) ?></div>
                                        </td>
                                        <td class="py-2.5 px-3 font-bold text-slate-900 dark:text-white whitespace-nowrap">
                                            <?= e(formatClubMoney((float)$fp['amount'], $currency)) ?>
                                        </td>
                                        <td class="py-2.5 px-3 text-[11px] text-slate-500 font-mono">
                                            <?= (string)$fp['iban'] !== '' ? e((string)$fp['iban']) : '<span class="font-sans italic text-slate-400">Überweisung/Bar</span>' ?>
                                            <?php if ((string)$fp['sepa_mandate_ref'] !== ''): ?>
                                                <div class="text-[10px] text-slate-400">Mandat: <?= e((string)$fp['sepa_mandate_ref']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <?php if ($fp['status'] === 'paid'): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                                                    ✅ Bezahlt <?= !empty($fp['paid_date']) ? '(' . e(date('d.m.', strtotime((string)$fp['paid_date']))) . ')' : '' ?>
                                                </span>
                                            <?php elseif ($fp['status'] === 'overdue'): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-red-100 dark:bg-red-950 text-red-800 dark:text-red-300">⚠️ Überfällig</span>
                                            <?php elseif ($fp['status'] === 'waived'): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-300">Befreit</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">⏳ Offen</span>
                                            <?php endif; ?>
                                            <?php if ((int)$fp['synced_to_budget'] === 1): ?>
                                                <span class="text-[10px] text-emerald-600 block">✓ Im Haushaltsbuch</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canWriteFees): ?>
                                            <td class="py-2.5 px-3 text-right whitespace-nowrap">
                                                <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="inline-flex items-center gap-1">
                                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                    <input type="hidden" name="action" value="update_fee_payment">
                                                    <input type="hidden" name="payment_id" value="<?= (int)$fp['id'] ?>">
                                                    <select name="status" onchange="this.form.submit()" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                                        <option value="open" <?= $fp['status'] === 'open' ? 'selected' : '' ?>>⏳ Offen</option>
                                                        <option value="paid" <?= $fp['status'] === 'paid' ? 'selected' : '' ?>>✅ Bezahlt</option>
                                                        <option value="overdue" <?= $fp['status'] === 'overdue' ? 'selected' : '' ?>>⚠️ Überfällig</option>
                                                        <option value="waived" <?= $fp['status'] === 'waived' ? 'selected' : '' ?>>Befreit</option>
                                                    </select>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- TAB 3: ARBEITSSTUNDEN, INVENTAR & SCHLÜSSEL                         -->
    <!-- =================================================================== -->
    <?php if ($canReadWorkInv): ?>
    <div id="tab-work" data-club-tab-panel="tab-work" class="hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Helfer- & Arbeitsstunden -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">⏱️ Geleistete Arbeits- &amp; Helferstunden (<?= e($selectedYear) ?>)</h2>
                <?php if ($canWriteWorkInv): ?>
                    <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post" class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add_work_log">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <select name="member_id" required class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="">– Mitglied wählen –</option>
                                <?php foreach ($members as $m): ?>
                                    <?php if ($m['status'] !== 'left'): ?>
                                        <option value="<?= (int)$m['id'] ?>"><?= e($m['last_name'] . ', ' . $m['first_name'] . ' (#' . $m['member_no'] . ')') ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="event_title" required placeholder="Anlass (z. B. Sommerfest, Platzpflege)" class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <input type="date" name="work_date" value="<?= date('Y-m-d') ?>" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <input type="text" name="hours" value="2,0" placeholder="Stunden" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">+ Eintragen</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <?php if (empty($workLogs)): ?>
                        <p class="text-xs text-slate-400 italic py-4 text-center">Noch keine Arbeitsstunden für <?= e($selectedYear) ?> erfasst.</p>
                    <?php else: ?>
                        <?php foreach ($workLogs as $wl): ?>
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-between gap-2 text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-white"><?= e($wl['last_name'] . ', ' . $wl['first_name']) ?></span>
                                    <span class="text-brand-600 dark:text-brand-400 font-bold ml-1.5">+<?= number_format((float)$wl['hours'], 1, ',', '.') ?> Std.</span>
                                    <div class="text-[11px] text-slate-500"><?= e(date('d.m.Y', strtotime((string)$wl['work_date']))) ?> · <?= e((string)$wl['event_title']) ?></div>
                                </div>
                                <?php if ($canWriteWorkInv): ?>
                                    <form action="club.php?club=<?= $activeClubId ?>&year=<?= urlencode($selectedYear) ?>" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_work_log">
                                        <input type="hidden" name="log_id" value="<?= (int)$wl['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs cursor-pointer">✕</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Inventar & Schlüssel-Verleih -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">🔑 Vereins-Inventar, Trikots &amp; Schlüssel-Liste</h2>
                <?php if ($canWriteWorkInv): ?>
                    <form action="club.php?club=<?= $activeClubId ?>" method="post" class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="save_inventory">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <input type="text" name="item_name" required placeholder="Gegenstand / Schlüssel (z. B. Vereinshaus-Schlüssel #3)" class="sm:col-span-2 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <select name="category" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="Schlüssel">🔑 Schlüssel</option>
                                <option value="Sportgerät / Trikots">⚽ Sportgerät / Trikots</option>
                                <option value="Technik / Elektronik">💻 Technik / Elektronik</option>
                                <option value="Mobiliar / Zelt">⛺ Mobiliar / Zelt</option>
                                <option value="Sonstiges">📦 Sonstiges</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <input type="text" name="identifier_code" placeholder="Inventar-/Schlüssel-Nr." class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <select name="loaned_to_member_id" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="">– Im Lager (Nicht verliehen) –</option>
                                <?php foreach ($members as $m): ?>
                                    <?php if ($m['status'] !== 'left'): ?>
                                        <option value="<?= (int)$m['id'] ?>">Ausgegeben an: <?= e($m['last_name'] . ', ' . $m['first_name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">+ Inventar speichern</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="space-y-2 max-h-96 overflow-y-auto">
                    <?php if (empty($inventoryItems)): ?>
                        <p class="text-xs text-slate-400 italic py-4 text-center">Noch keine Schlüssel oder Inventargegenstände eingetragen.</p>
                    <?php else: ?>
                        <?php foreach ($inventoryItems as $inv): ?>
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-between gap-2 text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-white"><?= e((string)$inv['item_name']) ?></span>
                                    <span class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-[10px] ml-1"><?= e((string)$inv['category']) ?></span>
                                    <?php if ((string)$inv['identifier_code'] !== ''): ?>
                                        <span class="text-[11px] font-mono text-slate-500 ml-1">[<?= e((string)$inv['identifier_code']) ?>]</span>
                                    <?php endif; ?>
                                    <div class="text-[11px] mt-0.5">
                                        <?php if (!empty($inv['loaned_to_member_id'])): ?>
                                            <span class="text-amber-600 dark:text-amber-400 font-semibold">
                                                👉 Verliehen an: <?= e($inv['loan_last_name'] . ', ' . $inv['loan_first_name']) ?>
                                                <?= !empty($inv['loaned_since']) ? '(seit ' . e(date('d.m.Y', strtotime((string)$inv['loaned_since']))) . ')' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-emerald-600 dark:text-emerald-400">✓ Verfügbar im Vereinsbestand</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($canWriteWorkInv): ?>
                                    <form action="club.php?club=<?= $activeClubId ?>" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_inventory">
                                        <input type="hidden" name="item_id" value="<?= (int)$inv['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs cursor-pointer">✕</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- TAB 4: PROTOKOLLE & VEREINSDOKUMENTE                                -->
    <!-- =================================================================== -->
    <?php if ($canReadDocs): ?>
    <div id="tab-docs" data-club-tab-panel="tab-docs" class="hidden space-y-6">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">📑 Satzung, Sitzungsprotokolle &amp; Vereinsdokumente</h2>
            <?php if ($canWriteDocs): ?>
                <form action="club.php?club=<?= $activeClubId ?>" method="post" class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="add_document">
                    <div class="md:col-span-3">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Titel des Dokuments / Protokolls</label>
                        <input type="text" name="title" required placeholder="z. B. Protokoll JHV 2026" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Kategorie</label>
                        <select name="doc_type" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="Sitzungsprotokoll">📝 Sitzungsprotokoll</option>
                            <option value="Jahreshauptversammlung">🏛️ Jahreshauptversammlung</option>
                            <option value="Satzung & Ordnungen">📜 Satzung &amp; Ordnungen</option>
                            <option value="Mitgliedsantrag">📄 Mitgliedsantrag</option>
                            <option value="Vertrag / Versicherung">🛡️ Vertrag / Versicherung</option>
                            <option value="Sonstiges">📎 Sonstiges</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Datum</label>
                        <input type="date" name="doc_date" value="<?= date('Y-m-d') ?>" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Cloud-Datei verknüpfen (optional)</label>
                        <select name="file_id" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="">– Ohne Datei / Nur Vermerk –</option>
                            <?php foreach ($myCloudFiles as $cf): ?>
                                <option value="<?= (int)$cf['id'] ?>">📎 <?= e((string)$cf['original_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="w-full py-2 px-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">+ Hinterlegen</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="space-y-2">
                <?php if (empty($clubDocs)): ?>
                    <p class="text-xs text-slate-400 italic py-4 text-center">Noch keine Sitzungsprotokolle oder Vereinsdokumente hinterlegt.</p>
                <?php else: ?>
                    <?php foreach ($clubDocs as $cd): ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700/70 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div>
                                <span class="px-2 py-0.5 rounded bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300 font-bold text-[10px]"><?= e((string)$cd['doc_type']) ?></span>
                                <span class="font-bold text-slate-900 dark:text-white ml-1.5"><?= e((string)$cd['title']) ?></span>
                                <span class="text-slate-400 ml-1.5">(<?= e(date('d.m.Y', strtotime((string)$cd['doc_date']))) ?>)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if (!empty($cd['file_id']) && !empty($cd['file_name'])): ?>
                                    <a href="download.php?id=<?= (int)$cd['file_id'] ?>" class="px-2.5 py-1 rounded-lg bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 font-semibold hover:underline">
                                        📎 <?= e((string)$cd['file_name']) ?> herunterladen
                                    </a>
                                <?php endif; ?>
                                <?php if ($canWriteDocs): ?>
                                    <form action="club.php?club=<?= $activeClubId ?>" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="doc_id" value="<?= (int)$cd['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 cursor-pointer">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- TAB 5: VORSTAND, ROLLENVERTEILUNG & VEREINS-EINSTELLUNGEN           -->
    <!-- =================================================================== -->
    <div id="tab-roles" data-club-tab-panel="tab-roles" class="hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Aufgabenverteilung auf verschiedene Cloud-Benutzer -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🛡️ Vorstands-Rollen &amp; Aufgabenteilung</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Teilen Sie die Aufgaben der Vereinsverwaltung gezielt auf verschiedene Cloud-Benutzer auf (z. B. <em>Kassenwart</em> nur für Beiträge, <em>Schriftführer</em> für Mitglieder &amp; Protokolle, <em>Gerätewart</em> für Inventar &amp; Arbeitsstunden).
                    </p>
                </div>

                <!-- Bestehende Rollen -->
                <div class="space-y-2">
                    <div class="p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-800/70 flex items-center justify-between text-xs">
                        <div>
                            <span class="font-bold text-slate-900 dark:text-white">👑 <?= e((string)($activeClub['owner_name'] ?: 'Eigentümer')) ?></span>
                            <span class="ml-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-600 text-white">Vereins-Gründer / Vollzugriff</span>
                        </div>
                    </div>

                    <?php foreach ($clubRoles as $cr): ?>
                        <?php $crPerms = parseClubPermissions((string)$cr['permissions']); ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white">
                                    👤 <?= e((string)$cr['username']) ?>
                                    <span class="ml-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-600 text-white"><?= e((string)$cr['role_title']) ?></span>
                                </div>
                                <div class="flex flex-wrap gap-1 mt-1.5">
                                    <?php foreach ($crPerms as $pk): ?>
                                        <?php if (isset($availableClubPerms[$pk])): ?>
                                            <span class="px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-[10px] font-semibold text-slate-700 dark:text-slate-200">
                                                <?= $availableClubPerms[$pk]['icon'] ?> <?= e($availableClubPerms[$pk]['label']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($isFullClubAdmin): ?>
                                <form action="club.php?club=<?= $activeClubId ?>" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_club_role">
                                    <input type="hidden" name="role_id" value="<?= (int)$cr['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300 text-xs font-medium cursor-pointer">Entziehen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($isFullClubAdmin): ?>
                    <form action="club.php?club=<?= $activeClubId ?>" method="post" class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="save_club_role">
                        <p class="text-xs font-bold text-slate-800 dark:text-slate-200">+ Cloud-Benutzer für Vereinsbereiche berechtigen:</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            <div>
                                <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1">Benutzer (mit Teilen-Freigabe)</label>
                                <select name="target_user_id" required class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                                    <option value="">– Benutzer auswählen –</option>
                                    <?php foreach ($shareableUsers as $su): ?>
                                        <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1">Amt / Bezeichnung</label>
                                <input type="text" name="role_title" placeholder="z. B. Kassenwart, Schriftführer, Gerätewart" required class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            </div>
                        </div>

                        <div class="space-y-1.5 p-3 rounded-xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-800/80">
                            <p class="text-[11px] font-bold text-indigo-900 dark:text-indigo-200 mb-1">Freigeschaltete Vereinsbereiche für diese Person:</p>
                            <?php foreach ($availableClubPerms as $pKey => $pMeta): ?>
                                <label class="flex items-start gap-2 p-1 rounded-lg hover:bg-indigo-100/50 dark:hover:bg-indigo-900/40 text-xs cursor-pointer">
                                    <input type="checkbox" name="club_perms[]" value="<?= e($pKey) ?>" class="mt-0.5 rounded border-slate-300 text-indigo-600">
                                    <span>
                                        <strong class="text-slate-800 dark:text-slate-100"><?= $pMeta['icon'] ?> <?= e($pMeta['label']) ?></strong>
                                        <span class="block text-[10px] text-slate-500 dark:text-slate-400"><?= e($pMeta['desc']) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <button type="submit" class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-xs cursor-pointer">
                            🛡️ Vereins-Rolle &amp; Teil-Rechte speichern
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Vereins-Stammdaten & Haushaltsbuch-Kopplung -->
            <?php if ($isFullClubAdmin): ?>
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">⚙️ Vereins-Stammdaten &amp; Kassen-Kopplung</h2>
                <form action="club.php?club=<?= $activeClubId ?>" method="post" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update_club">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Vereinsname</label>
                            <input type="text" name="name" value="<?= e((string)$activeClub['name']) ?>" required class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Kürzel</label>
                            <input type="text" name="short_name" value="<?= e((string)$activeClub['short_name']) ?>" placeholder="z. B. TuS 09" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Kurzbeschreibung / Vereinszweck</label>
                        <input type="text" name="description" value="<?= e((string)$activeClub['description']) ?>" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Symbol</label>
                            <input type="text" name="icon" value="<?= e((string)$activeClub['icon']) ?>" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Währung</label>
                            <input type="text" name="currency" value="<?= e((string)$activeClub['currency']) ?>" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Pflichtstunden / Jahr</label>
                            <input type="text" name="default_work_hours" value="<?= e(number_format((float)$activeClub['default_work_hours'], 1, ',', '.')) ?>" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">💰 Verknüpftes Haushaltsbuch für 1-Klick-Beitragsübernahme</label>
                        <select name="budget_book_id" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="">– Kein Haushaltsbuch verknüpft –</option>
                            <?php foreach ($myBudgetBooks as $bb): ?>
                                <option value="<?= (int)$bb['id'] ?>" <?= (int)($activeClub['budget_book_id'] ?? 0) === (int)$bb['id'] ? 'selected' : '' ?>>
                                    💰 <?= e((string)$bb['title']) ?> (<?= e((string)$bb['currency']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold cursor-pointer">
                        Einstellungen speichern
                    </button>
                </form>

                <?php if ($isClubOwner && count($allClubs) > 1): ?>
                    <form action="club.php?club=<?= $activeClubId ?>" method="post" class="pt-3 border-t border-slate-200 dark:border-slate-800" onsubmit="return confirm('Diesen Verein samt allen Mitgliedern und Beiträgen wirklich löschen?');">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_club">
                        <button type="submit" class="text-xs font-semibold text-red-600 hover:underline cursor-pointer">🗑️ Diesen Verein endgültig löschen</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- TAB 6: STATISTIK & AUSWERTUNGEN (ALTER, ABTEILUNGEN, JUBILÄEN)      -->
    <!-- =================================================================== -->
    <?php if ($canReadMembers): ?>
    <div id="tab-stats" data-club-tab-panel="tab-stats" class="hidden space-y-6">
        <!-- Obere Statistik-Kennzahlen -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">🎂 Durchschnittsalter</p>
                <p class="text-2xl font-extrabold text-slate-900 dark:text-white mt-1">
                    <?= $statAgeKnownCnt > 0 ? number_format($statAvgAge, 1, ',', '.') . ' J.' : '–' ?>
                </p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    <?php if ($statMinAge !== null && $statMaxAge !== null): ?>
                        Spanne: <?= $statMinAge ?> bis <?= $statMaxAge ?> Jahre (<?= $statAgeKnownCnt ?> erfasst)
                    <?php else: ?>
                        Noch keine Geburtsdaten hinterlegt
                    <?php endif; ?>
                </p>
            </div>

            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">🏆 Abteilungen / Sparten</p>
                <p class="text-2xl font-extrabold text-indigo-600 dark:text-indigo-400 mt-1">
                    <?= count($statDepartments) ?>
                </p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                    <?php if (!empty($statDepartments)): ?>
                        Größte: <?= e((string)array_key_first($statDepartments)) ?> (<?= (int)reset($statDepartments) ?>)
                    <?php else: ?>
                        Keine aktiven Mitglieder
                    <?php endif; ?>
                </p>
            </div>

            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">⏳ Ø Vereinszugehörigkeit</p>
                <p class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">
                    <?= $statTenureKnownCnt > 0 ? number_format($statAvgTenure, 1, ',', '.') . ' J.' : '–' ?>
                </p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                    <?= count($clubAnniversaries) ?> runde Jubiläen in <?= e($selectedYear) ?>
                </p>
            </div>

            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">💶 Jahresbeitrags-Potenzial</p>
                <?php if ($canReadFees): ?>
                    <p class="text-2xl font-extrabold text-amber-600 dark:text-amber-400 mt-1">
                        <?= e(formatClubMoney($statAnnualProjectedFees, $currency)) ?>
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                        Hochrechnung laut aktuellen Tarifen
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-400 mt-2">🔒 Nur für Kassenwart</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- 1. ALTERSVERTEILUNG -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">🎂 Altersverteilung der Mitglieder</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Altersstruktur aller aktiven Vereinsmitglieder (z. B. für Verband- &amp; Sportbund-Meldungen)
                        </p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                        Ø <?= number_format($statAvgAge, 1, ',', '.') ?> Jahre
                    </span>
                </div>

                <div class="space-y-3">
                    <?php
                    $denomAge = max(1, $statsTotalMembers);
                    foreach ($statAgeBrackets as $bKey => $bMeta):
                        if ($bKey === 'unknown' && $bMeta['count'] === 0) {
                            continue;
                        }
                        $cnt = (int)$bMeta['count'];
                        $pct = round(($cnt / $denomAge) * 100, 1);
                    ?>
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e($bMeta['label']) ?></span>
                                <span class="font-bold text-slate-900 dark:text-white">
                                    <?= $cnt ?> <span class="text-slate-400 font-normal">(<?= number_format($pct, 1, ',', '.') ?> %)</span>
                                </span>
                            </div>
                            <div class="w-full h-2.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full <?= e($bMeta['color']) ?> rounded-full transition-all duration-300" style="width: <?= max($cnt > 0 ? 3 : 0, min(100, $pct)) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. ABTEILUNGS- & SPARTENGRÖSSEN -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">🏆 Abteilungs- &amp; Spartengrößen</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Verteilung der Mitglieder auf die einzelnen Abteilungen, Mannschaften oder Sparten
                        </p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">
                        <?= count($statDepartments) ?> Sparten
                    </span>
                </div>

                <?php if (empty($statDepartments)): ?>
                    <p class="text-xs text-slate-400 py-4 text-center">Noch keine Mitglieder vorhanden.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php
                        $depColors = ['bg-indigo-600', 'bg-brand-600', 'bg-emerald-600', 'bg-cyan-600', 'bg-amber-500', 'bg-purple-600', 'bg-rose-500'];
                        $dIdx = 0;
                        foreach ($statDepartments as $depName => $depCnt):
                            $pctDep = round(($depCnt / max(1, $statsTotalMembers)) * 100, 1);
                            $barClr = $depColors[$dIdx % count($depColors)];
                            $dIdx++;
                        ?>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-slate-800 dark:text-slate-200">🏷️ <?= e((string)$depName) ?></span>
                                    <span class="font-bold text-slate-900 dark:text-white">
                                        <?= (int)$depCnt ?> <?= (int)$depCnt === 1 ? 'Mitglied' : 'Mitglieder' ?>
                                        <span class="text-slate-400 font-normal">(<?= number_format($pctDep, 1, ',', '.') ?> %)</span>
                                    </span>
                                </div>
                                <div class="w-full h-2.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full <?= $barClr ?> rounded-full transition-all duration-300" style="width: <?= max($depCnt > 0 ? 4 : 0, min(100, $pctDep)) ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- 3. MITGLIEDERSTATUS & BEITRAGSTARIF-STRUKTUR -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-5">
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white pb-2 border-b border-slate-200 dark:border-slate-800">
                        🟢 Mitglieder-Status &amp; Beitragsstruktur
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 mt-3">
                        <div class="p-2.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/70 dark:border-emerald-800/60 text-center">
                            <p class="text-[10px] font-bold uppercase text-emerald-700 dark:text-emerald-300">🟢 Aktiv</p>
                            <p class="text-lg font-extrabold text-emerald-800 dark:text-emerald-200"><?= $statStatusCounts['active'] ?></p>
                        </div>
                        <div class="p-2.5 rounded-xl bg-sky-50/70 dark:bg-sky-950/30 border border-sky-200/70 dark:border-sky-800/60 text-center">
                            <p class="text-[10px] font-bold uppercase text-sky-700 dark:text-sky-300">🌱 Jugend</p>
                            <p class="text-lg font-extrabold text-sky-800 dark:text-sky-200"><?= $statStatusCounts['youth'] ?></p>
                        </div>
                        <div class="p-2.5 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800/60 text-center">
                            <p class="text-[10px] font-bold uppercase text-amber-700 dark:text-amber-300">⏸️ Passiv</p>
                            <p class="text-lg font-extrabold text-amber-800 dark:text-amber-200"><?= $statStatusCounts['passive'] ?></p>
                        </div>
                        <div class="p-2.5 rounded-xl bg-purple-50/70 dark:bg-purple-950/30 border border-purple-200/70 dark:border-purple-800/60 text-center">
                            <p class="text-[10px] font-bold uppercase text-purple-700 dark:text-purple-300">🏅 Ehrenm.</p>
                            <p class="text-lg font-extrabold text-purple-800 dark:text-purple-200"><?= $statStatusCounts['honorary'] ?></p>
                        </div>
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-center">
                            <p class="text-[10px] font-bold uppercase text-slate-500">🚪 Ausgetr.</p>
                            <p class="text-lg font-extrabold text-slate-700 dark:text-slate-300"><?= $statStatusCounts['left'] ?></p>
                        </div>
                    </div>
                </div>

                <?php if ($canReadFees && !empty($statTariffDist)): ?>
                <div class="pt-2 border-t border-slate-200 dark:border-slate-800 space-y-2.5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        💶 Verteilung nach Beitragstarifen (Jahres-Soll)
                    </h4>
                    <div class="divide-y divide-slate-200/70 dark:divide-slate-800 text-xs">
                        <?php foreach ($statTariffDist as $tName => $tData): ?>
                            <div class="py-2 flex items-center justify-between gap-2">
                                <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e((string)$tName) ?></span>
                                <div class="text-right">
                                    <span class="font-bold text-slate-900 dark:text-white"><?= (int)$tData['count'] ?> Mitgl.</span>
                                    <span class="ml-2 text-emerald-600 dark:text-emerald-400 font-semibold">
                                        (<?= e(formatClubMoney((float)$tData['annual_sum'], $currency)) ?> / Jahr)
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Ein- & Austritte der letzten 5 Jahre -->
                <div class="pt-2 border-t border-slate-200 dark:border-slate-800 space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        📈 Mitglieder-Entwicklung (Ein- &amp; Austritte)
                    </h4>
                    <div class="grid grid-cols-5 gap-2 text-center text-xs">
                        <?php foreach ($statJoinsByYear as $yrKey => $jCnt): ?>
                            <?php $lCnt = (int)($statLeavesByYear[$yrKey] ?? 0); ?>
                            <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80">
                                <p class="font-bold text-slate-700 dark:text-slate-200"><?= e($yrKey) ?></p>
                                <p class="text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">+<?= (int)$jCnt ?></p>
                                <p class="text-rose-500 text-[11px]">-<?= $lCnt ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 4. VEREINSZUGEHÖRIGKEIT & EHRUNGS-/JUBILÄUMSLISTE -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">🏅 Vereinszugehörigkeit &amp; Ehrungs-Jubiläen <?= e($selectedYear) ?></h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Treuedauer und runde Mitgliedschaftsjubiläen für Ehrungen auf der Jahreshauptversammlung
                        </p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                        Ø <?= number_format($statAvgTenure, 1, ',', '.') ?> J.
                    </span>
                </div>

                <div class="space-y-2.5">
                    <?php foreach ($statTenureBrackets as $tbKey => $tbMeta): ?>
                        <?php
                        $tCnt = (int)$tbMeta['count'];
                        $tPct = round(($tCnt / max(1, $statsTotalMembers)) * 100, 1);
                        ?>
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e($tbMeta['label']) ?></span>
                                <span class="font-bold text-slate-900 dark:text-white">
                                    <?= $tCnt ?> <span class="text-slate-400 font-normal">(<?= number_format($tPct, 1, ',', '.') ?> %)</span>
                                </span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full <?= e($tbMeta['color']) ?> rounded-full" style="width: <?= max($tCnt > 0 ? 3 : 0, min(100, $tPct)) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="pt-3 border-t border-slate-200 dark:border-slate-800 space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">
                        🎖️ Runde Vereinsjubiläen im Jahr <?= e($selectedYear) ?>
                    </h4>
                    <?php if (empty($clubAnniversaries)): ?>
                        <p class="text-xs text-slate-400 italic">
                            Im Jahr <?= e($selectedYear) ?> stehen keine runden Beitrittsjubiläen (5, 10, 15, 20, 25, 30, 40, 50 J.) an.
                        </p>
                    <?php else: ?>
                        <div class="divide-y divide-slate-200/70 dark:divide-slate-800 text-xs max-h-48 overflow-y-auto">
                            <?php foreach ($clubAnniversaries as $ann): ?>
                                <div class="py-2 flex items-center justify-between gap-2">
                                    <div>
                                        <span class="font-bold text-slate-900 dark:text-white"><?= e($ann['name']) ?></span>
                                        <span class="text-slate-400 ml-1">(#<?= e($ann['member_no']) ?> · Eintritt <?= (int)$ann['joined_year'] ?>)</span>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 shrink-0">
                                        🏅 <?= (int)$ann['years'] ?> Jahre
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL: MITGLIED AUFNEHMEN / BEARBEITEN -->
<?php if ($canWriteMembers): ?>
<!-- MODAL: MITGLIEDER IMPORTIEREN (CSV, EXCEL COPY & PASTE, ADRESSBUCH) -->
<div id="importMembersModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-2xl w-full p-5 sm:p-6 space-y-4 my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">📤 Mitglieder importieren</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Per CSV-/Excel-Datei, per Copy &amp; Paste direkt aus Excel oder aus Ihrem Cloud-Adressbuch
                </p>
            </div>
            <button type="button" onclick="document.getElementById('importMembersModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
        </div>

        <!-- 1-Klick Muster-Vorlage herunterladen -->
        <div class="p-3 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-800/70 flex flex-wrap items-center justify-between gap-2 text-xs">
            <div class="text-slate-700 dark:text-slate-200">
                <strong>💡 Tipp:</strong> Laden Sie unsere fertige Excel-/CSV-Musterdatei herunter oder nutzen Sie Ihre eigene Tabelle (Spalten wie <em>Vorname, Nachname, Abteilung, Geburtsdatum, E-Mail, Telefon, Straße, PLZ, Ort, IBAN</em> werden automatisch erkannt!).
            </div>
            <form action="club.php?club=<?= $activeClubId ?>" method="post" class="shrink-0">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="download_member_csv_template">
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold cursor-pointer">
                    📄 Muster-CSV herunterladen
                </button>
            </form>
        </div>

        <form action="club.php?club=<?= $activeClubId ?>" method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="import_members">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-start gap-2.5 p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/60 cursor-pointer">
                    <input type="radio" name="import_source" value="csv" checked onchange="document.getElementById('clubCsvImportFields').classList.remove('hidden')" class="mt-0.5">
                    <span class="text-xs">
                        <strong class="block text-slate-900 dark:text-white">📄 CSV-Datei / Excel Copy &amp; Paste</strong>
                        <span class="text-slate-500 dark:text-slate-400">Trennzeichen (<code>;</code>, <code>,</code> oder Tab) &amp; Datum (<code>TT.MM.JJJJ</code>) werden automatisch erkannt</span>
                    </span>
                </label>

                <label class="flex items-start gap-2.5 p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/60 cursor-pointer">
                    <input type="radio" name="import_source" value="contacts" onchange="document.getElementById('clubCsvImportFields').classList.add('hidden')" class="mt-0.5">
                    <span class="text-xs">
                        <strong class="block text-slate-900 dark:text-white">📇 Aus Cloud-Kontakten übernehmen</strong>
                        <span class="text-slate-500 dark:text-slate-400">Importiert Kontakte aus Ihrem MiniCloud-Adressbuch (<code>contacts.php</code>)</span>
                    </span>
                </label>
            </div>

            <div id="clubCsvImportFields" class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Option A: CSV-Datei auswählen (<code>.csv</code> oder <code>.txt</code>)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        accept=".csv,.txt,text/csv,text/plain"
                        class="w-full text-xs text-slate-700 dark:text-slate-200 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-50 dark:file:bg-brand-950/70 file:text-brand-700 dark:file:text-brand-300 cursor-pointer"
                    >
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Oder Option B: Zeilen direkt aus Excel / LibreOffice Calc hier einfügen (<code>Strg+V</code>)
                    </label>
                    <textarea
                        name="csv_paste_text"
                        rows="4"
                        placeholder="Vorname;Nachname;E-Mail;Telefon;Abteilung;Geburtsdatum&#10;Max;Mustermann;max@example.org;0170-123456;Fußball;15.05.1985"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono"
                    ></textarea>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-200 dark:border-slate-800">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">
                        Standard-Beitragstarif (falls nicht angegeben)
                    </label>
                    <select name="default_fee_tier_id" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <option value="">– Kein Standard-Tarif –</option>
                        <?php foreach ($feeTiers as $ft): ?>
                            <option value="<?= (int)$ft['id'] ?>"><?= e((string)$ft['title']) ?> (<?= e(formatClubMoney((float)$ft['amount'], $currency)) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">
                        Standard-Abteilung (falls Spalte leer)
                    </label>
                    <input type="text" name="default_department" placeholder="z. B. Hauptverein" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>

            <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" name="skip_duplicates" value="1" checked class="rounded border-slate-300 text-brand-600">
                <span>Bereits vorhandene Mitglieder (gleicher Vor- &amp; Nachname oder gleiche E-Mail) automatisch überspringen</span>
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('importMembersModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs cursor-pointer">📤 Jetzt importieren</button>
            </div>
        </form>
    </div>
</div>

<div id="memberModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-2xl w-full p-5 sm:p-6 space-y-4 my-8">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <h3 id="memberModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">👤 Mitglied aufnehmen / bearbeiten</h3>
            <button type="button" onclick="document.getElementById('memberModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg cursor-pointer">✕</button>
        </div>

        <form action="club.php?club=<?= $activeClubId ?>" method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_member">
            <input type="hidden" name="member_id" id="fm_member_id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Mitglieds-Nr.</label>
                    <input type="text" name="member_no" id="fm_member_no" placeholder="Auto (z. B. 1002)" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Vorname *</label>
                    <input type="text" name="first_name" id="fm_first_name" required class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Nachname *</label>
                    <input type="text" name="last_name" id="fm_last_name" required class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Status</label>
                    <select name="status" id="fm_status" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <option value="active">🟢 Aktiv</option>
                        <option value="youth">🌱 Jugend</option>
                        <option value="passive">⏸️ Passiv / Förder</option>
                        <option value="honorary">🏅 Ehrenmitglied</option>
                        <option value="left">🚪 Ausgetreten</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Abteilung / Sparte / Mannschaft</label>
                    <input type="text" name="department" id="fm_department" placeholder="z. B. Fußball, Jugend, Chor" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Amt / Funktion im Verein</label>
                    <input type="text" name="board_role" id="fm_board_role" placeholder="z. B. Kassenwart, Trainer" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Beitragstarif</label>
                    <select name="fee_tier_id" id="fm_fee_tier_id" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <option value="">– Beitragsfrei / Kein Tarif –</option>
                        <?php foreach ($feeTiers as $ft): ?>
                            <option value="<?= (int)$ft['id'] ?>"><?= e((string)$ft['title']) ?> (<?= e(formatClubMoney((float)$ft['amount'], $currency)) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">E-Mail</label>
                    <input type="email" name="email" id="fm_email" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Telefon / Mobil</label>
                    <input type="text" name="phone" id="fm_phone" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Straße &amp; Hausnummer</label>
                    <input type="text" name="street" id="fm_street" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">PLZ</label>
                    <input type="text" name="postal_code" id="fm_postal_code" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Ort</label>
                    <input type="text" name="city" id="fm_city" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Geburtsdatum</label>
                    <input type="date" name="birth_date" id="fm_birth_date" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Eintrittsdatum</label>
                    <input type="date" name="joined_date" id="fm_joined_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Austrittsdatum (optional)</label>
                    <input type="date" name="left_date" id="fm_left_date" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>

            <?php if ($canWriteFees): ?>
            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <div>
                    <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">IBAN (SEPA-Lastschrift)</label>
                    <input type="text" name="iban" id="fm_iban" placeholder="DE00 ..." class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">BIC</label>
                    <input type="text" name="bic" id="fm_bic" class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                </div>
                <div>
                    <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1">SEPA-Mandatsreferenz</label>
                    <input type="text" name="sepa_mandate_ref" id="fm_sepa_mandate_ref" placeholder="z. B. MV-1001" class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Interne Notizen (Ehrungen, Qualifikationen, Hinweise)</label>
                <input type="text" name="notes" id="fm_notes" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('memberModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">Mitglied speichern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: NEUEN VEREIN ANLEGEN -->
<div id="newClubModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-md w-full p-5 sm:p-6 space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">🏛️ Neuen Verein anlegen</h3>
            <button type="button" onclick="document.getElementById('newClubModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
        </div>
        <form action="club.php" method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_club">
            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Vereinsname *</label>
                <input type="text" name="name" required placeholder="z. B. Sportverein Musterstadt e. V." class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Kürzel</label>
                    <input type="text" name="short_name" placeholder="SVM" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Icon</label>
                    <input type="text" name="icon" value="🏛️" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Pflichtstd.</label>
                    <input type="text" name="default_work_hours" value="10" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Beschreibung</label>
                <input type="text" name="description" placeholder="z. B. Hauptverein & Jugendabteilung" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('newClubModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">Verein erstellen</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchClubTab(tabId) {
    const panels = document.querySelectorAll('[data-club-tab-panel]');
    const btns   = document.querySelectorAll('[data-club-tab-btn]');
    panels.forEach(p => p.classList.toggle('hidden', p.getAttribute('data-club-tab-panel') !== tabId));
    btns.forEach(b => {
        const active = b.getAttribute('data-club-tab-btn') === tabId;
        b.className = active
            ? 'club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer bg-brand-600 text-white shadow-xs'
            : 'club-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800';
    });
    if (history.replaceState) {
        history.replaceState(null, '', '#' + tabId);
    }
}

(() => {
    const panels = Array.from(document.querySelectorAll('[data-club-tab-panel]')).map(el => el.getAttribute('data-club-tab-panel'));
    const hash = (window.location.hash || '').replace(/^#/, '');
    const initial = panels.includes(hash) ? hash : (panels[0] || 'tab-roles');
    switchClubTab(initial);
})();

function filterClubMembers(q) {
    const term = String(q || '').trim().toLowerCase();
    document.querySelectorAll('[data-club-member-row]').forEach(row => {
        const hay = row.getAttribute('data-search') || '';
        row.style.display = (term === '' || hay.includes(term)) ? '' : 'none';
    });
}

function openMemberModal(data) {
    const modal = document.getElementById('memberModal');
    if (!modal) return;
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    if (data && data.id) {
        setVal('fm_member_id', data.id);
        setVal('fm_member_no', data.member_no);
        setVal('fm_first_name', data.first_name);
        setVal('fm_last_name', data.last_name);
        setVal('fm_email', data.email);
        setVal('fm_phone', data.phone);
        setVal('fm_street', data.street);
        setVal('fm_postal_code', data.postal_code);
        setVal('fm_city', data.city);
        setVal('fm_birth_date', data.birth_date);
        setVal('fm_joined_date', data.joined_date);
        setVal('fm_left_date', data.left_date);
        setVal('fm_status', data.status || 'active');
        setVal('fm_department', data.department);
        setVal('fm_board_role', data.board_role);
        setVal('fm_fee_tier_id', data.fee_tier_id || '');
        setVal('fm_iban', data.iban || '');
        setVal('fm_bic', data.bic || '');
        setVal('fm_sepa_mandate_ref', data.sepa_mandate_ref || '');
        setVal('fm_notes', data.notes || '');
    } else {
        setVal('fm_member_id', 0);
        setVal('fm_member_no', '');
        setVal('fm_first_name', '');
        setVal('fm_last_name', '');
        setVal('fm_email', '');
        setVal('fm_phone', '');
        setVal('fm_street', '');
        setVal('fm_postal_code', '');
        setVal('fm_city', '');
        setVal('fm_birth_date', '');
        setVal('fm_joined_date', '<?= date('Y-m-d') ?>');
        setVal('fm_left_date', '');
        setVal('fm_status', 'active');
        setVal('fm_department', '');
        setVal('fm_board_role', '');
        setVal('fm_fee_tier_id', '');
        setVal('fm_iban', '');
        setVal('fm_bic', '');
        setVal('fm_sepa_mandate_ref', '');
        setVal('fm_notes', '');
    }
    modal.classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
