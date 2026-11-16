<?php
declare(strict_types=1);

/**
 * MiniCloud - 🩺 Gesundheit, Digitale Patientenakte & Vital-Manager (health.php)
 * Vollwertige, 100 % private und selbstgehostete Gesundheits- & Patientenakte:
 * - 👤 Familien- & Personen-Profile (Ich, Partner, Kinder, Haustiere)
 * - 🚨 Digitaler Notfallpass (Scheckkarten-Druck & Lebensretter-Infos)
 * - 💊 Bundesmedikationsplan (BMP) & Vorratsampel ("Rezept fällig")
 * - 🗓️ Arzttermine, Vorbereitung (Fragen-Checkliste) & Sprechstunden-Protokoll
 * - 🧪 Laborwerte & Blutbild-Langzeittrends mit Normbereich-Ampel
 * - 📁 Befund-Archiv & 1-Klick-Facharzt-Dossier (PDF-Zusammenstellung)
 * - 💉 STIKO-Impfpass & Zahnarzt-/Vorsorge-Bonusheft
 * - 📈 Vitalwerte (Blutdruck-WHO, Gewicht, Blutzucker) & Schmerztagebuch (Wetter-Korrelation)
 * - 👓 Brillen- & Sehschärfe-Pass
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_health'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Gesundheits-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$csrfToken = getCsrfToken();

// ============================================================================
// 1. DATENBANK-SCHEMA LAZY INITIALISIEREN
// ============================================================================
function ensureHealthSchema(PDO $pdo, int $userId): void
{
    static $done = false;
    if ($done || (!empty($_SESSION['_mc_health_schema_ok']) && $_SESSION['_mc_health_schema_ok'] === 1)) {
        $done = true;
        return;
    }
    $done = true;

    // 1. Profile (Personen / Haustiere)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_profiles` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `relation` VARCHAR(50) NOT NULL DEFAULT 'self',
        `birth_date` DATE NULL,
        `gender` VARCHAR(20) NOT NULL DEFAULT 'unspecified',
        `blood_type` VARCHAR(10) NOT NULL DEFAULT '',
        `rhesus_factor` VARCHAR(10) NOT NULL DEFAULT '',
        `insurance_name` VARCHAR(150) NOT NULL DEFAULT '',
        `insurance_number` VARCHAR(50) NOT NULL DEFAULT '',
        `primary_doctor` VARCHAR(190) NOT NULL DEFAULT '',
        `emergency_contacts_json` TEXT NULL,
        `allergies_json` TEXT NULL,
        `conditions_json` TEXT NULL,
        `implants` VARCHAR(255) NOT NULL DEFAULT '',
        `organ_donor` VARCHAR(50) NOT NULL DEFAULT 'unspecified',
        `has_living_will` TINYINT(1) NOT NULL DEFAULT 0,
        `living_will_location` VARCHAR(255) NOT NULL DEFAULT '',
        `notes` TEXT NULL,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_prof_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Medikationsplan & Einnahme
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_medications` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(190) NOT NULL,
        `active_substance` VARCHAR(190) NOT NULL DEFAULT '',
        `form` VARCHAR(50) NOT NULL DEFAULT 'tablets',
        `strength` VARCHAR(50) NOT NULL DEFAULT '',
        `dosage_morning` VARCHAR(20) NOT NULL DEFAULT '0',
        `dosage_noon` VARCHAR(20) NOT NULL DEFAULT '0',
        `dosage_evening` VARCHAR(20) NOT NULL DEFAULT '0',
        `dosage_night` VARCHAR(20) NOT NULL DEFAULT '0',
        `intake_instructions` VARCHAR(255) NOT NULL DEFAULT '',
        `reason` VARCHAR(255) NOT NULL DEFAULT '',
        `prescribed_by` VARCHAR(150) NOT NULL DEFAULT '',
        `stock_units` INT NOT NULL DEFAULT 0,
        `stock_unit_type` VARCHAR(30) NOT NULL DEFAULT 'Tabletten',
        `daily_consumption` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        `package_refill_threshold` INT NOT NULL DEFAULT 7,
        `file_attachment_id` INT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_meds_prof` (`profile_id`),
        KEY `idx_health_meds_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Arztbesuche & Termine
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_doctor_visits` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `doctor_name` VARCHAR(190) NOT NULL,
        `specialty` VARCHAR(100) NOT NULL DEFAULT 'Hausarzt',
        `visit_date` DATE NOT NULL,
        `visit_time` VARCHAR(10) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'planned',
        `questions_before` TEXT NULL,
        `results_summary` TEXT NULL,
        `diagnosis_icd` VARCHAR(150) NOT NULL DEFAULT '',
        `next_checkup_date` DATE NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_visits_prof` (`profile_id`),
        KEY `idx_health_visits_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 4. Laborwerte & Blutbild-Trends
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_lab_values` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `measured_date` DATE NOT NULL,
        `parameter_key` VARCHAR(50) NOT NULL,
        `parameter_name` VARCHAR(100) NOT NULL,
        `value_numeric` DECIMAL(10,3) NOT NULL,
        `unit` VARCHAR(30) NOT NULL DEFAULT 'mg/dl',
        `norm_min` DECIMAL(10,3) NULL,
        `norm_max` DECIMAL(10,3) NULL,
        `assessment` VARCHAR(20) NOT NULL DEFAULT 'normal',
        `lab_report_file_id` INT NULL,
        `notes` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_lab_prof` (`profile_id`),
        KEY `idx_health_lab_param` (`profile_id`, `parameter_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Befund- & Dokumenten-Archiv
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_documents` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(190) NOT NULL,
        `category` VARCHAR(50) NOT NULL DEFAULT 'doctor_letter',
        `document_date` DATE NOT NULL,
        `issuer` VARCHAR(190) NOT NULL DEFAULT '',
        `summary` TEXT NULL,
        `file_id` INT NOT NULL,
        `include_in_dossier` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_docs_prof` (`profile_id`),
        KEY `idx_health_docs_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 6. Impfungen & Vorsorge-Bonusheft
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_vaccines` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(30) NOT NULL DEFAULT 'vaccine',
        `name` VARCHAR(150) NOT NULL,
        `administered_date` DATE NOT NULL,
        `batch_number` VARCHAR(50) NOT NULL DEFAULT '',
        `doctor_clinic` VARCHAR(150) NOT NULL DEFAULT '',
        `next_due_date` DATE NULL,
        `notes` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_vac_prof` (`profile_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 7. Vitalwerte (Blutdruck, Puls, Gewicht, Blutzucker)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_vitals` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `recorded_at` DATETIME NOT NULL,
        `systolic` INT NULL,
        `diastolic` INT NULL,
        `pulse` INT NULL,
        `weight_kg` DECIMAL(5,2) NULL,
        `blood_sugar` DECIMAL(6,2) NULL,
        `blood_sugar_unit` VARCHAR(20) NOT NULL DEFAULT 'mg/dl',
        `temperature_c` DECIMAL(4,2) NULL,
        `notes` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_vitals_prof` (`profile_id`),
        KEY `idx_health_vitals_date` (`profile_id`, `recorded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 8. Symptom- & Schmerztagebuch
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_symptoms` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `started_at` DATETIME NOT NULL,
        `symptom_name` VARCHAR(150) NOT NULL,
        `intensity` INT NOT NULL DEFAULT 5,
        `duration_hours` DECIMAL(5,2) NULL,
        `triggers` VARCHAR(255) NOT NULL DEFAULT '',
        `treatment` VARCHAR(255) NOT NULL DEFAULT '',
        `weather_condition` VARCHAR(100) NOT NULL DEFAULT '',
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_symptoms_prof` (`profile_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 9. Brillen- & Sehschärfe-Pass
    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_vision_passes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `profile_id` INT UNSIGNED NOT NULL,
        `pass_date` DATE NOT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'glasses',
        `optician_doctor` VARCHAR(150) NOT NULL DEFAULT '',
        `right_sph` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `right_cyl` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `right_axis` INT NOT NULL DEFAULT 0,
        `right_add` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `left_sph` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `left_cyl` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `left_axis` INT NOT NULL DEFAULT 0,
        `left_add` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
        `pupil_distance` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
        `notes` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_health_vis_prof` (`profile_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Initiales Standard-Profil anlegen, falls noch keines existiert
    $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM health_profiles WHERE user_id = :uid');
    $stmtCnt->execute([':uid' => $userId]);
    if ((int)$stmtCnt->fetchColumn() === 0) {
        $uName = trim((string)($_SESSION['username'] ?? 'Hauptprofil'));
        $stmtIns = $pdo->prepare('INSERT INTO health_profiles (user_id, name, relation, is_default) VALUES (:uid, :name, "self", 1)');
        $stmtIns->execute([':uid' => $userId, ':name' => $uName]);
    }

    $_SESSION['_mc_health_schema_ok'] = 1;
}

ensureHealthSchema($pdo, $userId);

// ============================================================================
// 2. AKTIVES PROFIL BESTIMMEN
// ============================================================================
$stmtProfiles = $pdo->prepare('SELECT * FROM health_profiles WHERE user_id = :uid ORDER BY is_default DESC, name ASC');
$stmtProfiles->execute([':uid' => $userId]);
$allProfiles = $stmtProfiles->fetchAll(PDO::FETCH_ASSOC);

$activeProfileId = 0;
if (isset($_GET['profile'])) {
    $reqProf = (int)$_GET['profile'];
    foreach ($allProfiles as $p) {
        if ((int)$p['id'] === $reqProf) {
            $activeProfileId = $reqProf;
            $_SESSION['health_active_profile_id'] = $activeProfileId;
            break;
        }
    }
}
if ($activeProfileId === 0 && !empty($_SESSION['health_active_profile_id'])) {
    $sessProf = (int)$_SESSION['health_active_profile_id'];
    foreach ($allProfiles as $p) {
        if ((int)$p['id'] === $sessProf) {
            $activeProfileId = $sessProf;
            break;
        }
    }
}
if ($activeProfileId === 0 && !empty($allProfiles)) {
    $activeProfileId = (int)$allProfiles[0]['id'];
    $_SESSION['health_active_profile_id'] = $activeProfileId;
}

$activeProfile = null;
foreach ($allProfiles as $p) {
    if ((int)$p['id'] === $activeProfileId) {
        $activeProfile = $p;
        break;
    }
}

// Aktiver Tab
$validTabs = ['emergency', 'meds', 'visits', 'lab', 'docs', 'vaccines', 'vitals', 'vision'];
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs, true) ? $_GET['tab'] : 'emergency';

// ============================================================================
// 3. POST-AKTIONEN (CRUD FÜR ALLE GESUNDHEITSBEREICHE)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($postedToken)) {
        setFlash('error', 'Ungültiges Sicherheitstoken (CSRF). Bitte versuche es erneut.');
        header('Location: health.php?tab=' . urlencode($activeTab));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // ------------------------------------------------------------------------
    // AKTION: Profil speichern / bearbeiten
    // ------------------------------------------------------------------------
    if ($action === 'save_profile') {
        $profId   = (int)($_POST['profile_id'] ?? 0);
        $name     = mb_substr(trim((string)($_POST['name'] ?? 'Profil')), 0, 150);
        $relation = mb_substr(trim((string)($_POST['relation'] ?? 'self')), 0, 50);
        $bDate    = !empty($_POST['birth_date']) ? (string)$_POST['birth_date'] : null;
        $gender   = mb_substr(trim((string)($_POST['gender'] ?? 'unspecified')), 0, 20);
        $blood    = mb_substr(trim((string)($_POST['blood_type'] ?? '')), 0, 10);
        $rhesus   = mb_substr(trim((string)($_POST['rhesus_factor'] ?? '')), 0, 10);
        $insName  = mb_substr(trim((string)($_POST['insurance_name'] ?? '')), 0, 150);
        $insNum   = mb_substr(trim((string)($_POST['insurance_number'] ?? '')), 0, 50);
        $pDoc     = mb_substr(trim((string)($_POST['primary_doctor'] ?? '')), 0, 190);
        $implants = mb_substr(trim((string)($_POST['implants'] ?? '')), 0, 255);
        $organ    = mb_substr(trim((string)($_POST['organ_donor'] ?? 'unspecified')), 0, 50);
        $will     = isset($_POST['has_living_will']) && $_POST['has_living_will'] === '1' ? 1 : 0;
        $willLoc  = mb_substr(trim((string)($_POST['living_will_location'] ?? '')), 0, 255);
        $notes    = trim((string)($_POST['notes'] ?? ''));

        // Allergien & Diagnosen
        $allergiesRaw = trim((string)($_POST['allergies_text'] ?? ''));
        $allergiesArr = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $allergiesRaw))));
        $allergiesJson = !empty($allergiesArr) ? json_encode(array_values($allergiesArr), JSON_UNESCAPED_UNICODE) : null;

        $conditionsRaw = trim((string)($_POST['conditions_text'] ?? ''));
        $conditionsArr = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $conditionsRaw))));
        $conditionsJson = !empty($conditionsArr) ? json_encode(array_values($conditionsArr), JSON_UNESCAPED_UNICODE) : null;

        // Notfallkontakte (ICE)
        $iceName  = mb_substr(trim((string)($_POST['ice_name'] ?? '')), 0, 100);
        $icePhone = mb_substr(trim((string)($_POST['ice_phone'] ?? '')), 0, 50);
        $iceRel   = mb_substr(trim((string)($_POST['ice_rel'] ?? '')), 0, 50);
        $iceJson  = ($iceName !== '' || $icePhone !== '') ? json_encode([['name' => $iceName, 'phone' => $icePhone, 'rel' => $iceRel]], JSON_UNESCAPED_UNICODE) : null;

        if ($profId > 0) {
            $stmtUp = $pdo->prepare('UPDATE health_profiles SET
                name = :name, relation = :rel, birth_date = :bdate, gender = :gender,
                blood_type = :blood, rhesus_factor = :rhesus, insurance_name = :iname, insurance_number = :inum,
                primary_doctor = :pdoc, emergency_contacts_json = :ice, allergies_json = :allergies,
                conditions_json = :cond, implants = :implants, organ_donor = :organ,
                has_living_will = :will, living_will_location = :willloc, notes = :notes
                WHERE id = :id AND user_id = :uid');
            $stmtUp->execute([
                ':name'      => $name,
                ':rel'       => $relation,
                ':bdate'     => $bDate,
                ':gender'    => $gender,
                ':blood'     => $blood,
                ':rhesus'    => $rhesus,
                ':iname'     => $insName,
                ':inum'      => $insNum,
                ':pdoc'      => $pDoc,
                ':ice'       => $iceJson,
                ':allergies' => $allergiesJson,
                ':cond'      => $conditionsJson,
                ':implants'  => $implants,
                ':organ'     => $organ,
                ':will'      => $will,
                ':willloc'   => $willLoc,
                ':notes'     => $notes,
                ':id'        => $profId,
                ':uid'       => $userId,
            ]);
            setFlash('success', 'Gesundheitsprofil „' . $name . '“ erfolgreich aktualisiert.');
        } else {
            $stmtIns = $pdo->prepare('INSERT INTO health_profiles
                (user_id, name, relation, birth_date, gender, blood_type, rhesus_factor, insurance_name, insurance_number,
                 primary_doctor, emergency_contacts_json, allergies_json, conditions_json, implants, organ_donor,
                 has_living_will, living_will_location, notes, is_default)
                VALUES
                (:uid, :name, :rel, :bdate, :gender, :blood, :rhesus, :iname, :inum,
                 :pdoc, :ice, :allergies, :cond, :implants, :organ,
                 :will, :willloc, :notes, 0)');
            $stmtIns->execute([
                ':uid'       => $userId,
                ':name'      => $name,
                ':rel'       => $relation,
                ':bdate'     => $bDate,
                ':gender'    => $gender,
                ':blood'     => $blood,
                ':rhesus'    => $rhesus,
                ':iname'     => $insName,
                ':inum'      => $insNum,
                ':pdoc'      => $pDoc,
                ':ice'       => $iceJson,
                ':allergies' => $allergiesJson,
                ':cond'      => $conditionsJson,
                ':implants'  => $implants,
                ':organ'     => $organ,
                ':will'      => $will,
                ':willloc'   => $willLoc,
                ':notes'     => $notes,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $_SESSION['health_active_profile_id'] = $newId;
            setFlash('success', 'Neues Profil „' . $name . '“ erfolgreich angelegt.');
        }
        header('Location: health.php?tab=' . urlencode($activeTab));
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Profil löschen
    // ------------------------------------------------------------------------
    if ($action === 'delete_profile') {
        $delId = (int)($_POST['profile_id'] ?? 0);
        if ($delId > 0 && count($allProfiles) > 1) {
            $pdo->prepare('DELETE FROM health_profiles WHERE id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_medications WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_doctor_visits WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_lab_values WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_documents WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_vaccines WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_vitals WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_symptoms WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);
            $pdo->prepare('DELETE FROM health_vision_passes WHERE profile_id = :id AND user_id = :uid')->execute([':id' => $delId, ':uid' => $userId]);

            unset($_SESSION['health_active_profile_id']);
            setFlash('success', 'Profil und zugehörige Gesundheitsdaten gelöscht.');
        } else {
            setFlash('error', 'Das letzte verbleibende Profil kann nicht gelöscht werden.');
        }
        header('Location: health.php');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Medikament speichern
    // ------------------------------------------------------------------------
    if ($action === 'save_medication') {
        $medId    = (int)($_POST['med_id'] ?? 0);
        $name     = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 190);
        $active   = mb_substr(trim((string)($_POST['active_substance'] ?? '')), 0, 190);
        $form     = mb_substr(trim((string)($_POST['form'] ?? 'tablets')), 0, 50);
        $strength = mb_substr(trim((string)($_POST['strength'] ?? '')), 0, 50);
        $morn     = mb_substr(trim((string)($_POST['dosage_morning'] ?? '0')), 0, 20);
        $noon     = mb_substr(trim((string)($_POST['dosage_noon'] ?? '0')), 0, 20);
        $eve      = mb_substr(trim((string)($_POST['dosage_evening'] ?? '0')), 0, 20);
        $night    = mb_substr(trim((string)($_POST['dosage_night'] ?? '0')), 0, 20);
        $instr    = mb_substr(trim((string)($_POST['intake_instructions'] ?? '')), 0, 255);
        $reason   = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
        $doc      = mb_substr(trim((string)($_POST['prescribed_by'] ?? '')), 0, 150);
        $stock    = max(0, (int)($_POST['stock_units'] ?? 0));
        $sType    = mb_substr(trim((string)($_POST['stock_unit_type'] ?? 'Tabletten')), 0, 30);
        $consump  = max(0.1, (float)($_POST['daily_consumption'] ?? 1.0));
        $thresh   = max(1, (int)($_POST['package_refill_threshold'] ?? 7));
        $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '0' ? 0 : 1;

        if ($name !== '' && $activeProfileId > 0) {
            if ($medId > 0) {
                $stmt = $pdo->prepare('UPDATE health_medications SET
                    name = :name, active_substance = :act, form = :form, strength = :str,
                    dosage_morning = :m, dosage_noon = :n, dosage_evening = :e, dosage_night = :ni,
                    intake_instructions = :instr, reason = :reason, prescribed_by = :doc,
                    stock_units = :stock, stock_unit_type = :stype, daily_consumption = :dc,
                    package_refill_threshold = :th, is_active = :actv
                    WHERE id = :id AND user_id = :uid AND profile_id = :pid');
                $stmt->execute([
                    ':name' => $name, ':act' => $active, ':form' => $form, ':str' => $strength,
                    ':m' => $morn, ':n' => $noon, ':e' => $eve, ':ni' => $night,
                    ':instr' => $instr, ':reason' => $reason, ':doc' => $doc,
                    ':stock' => $stock, ':stype' => $sType, ':dc' => $consump, ':th' => $thresh,
                    ':actv' => $isActive, ':id' => $medId, ':uid' => $userId, ':pid' => $activeProfileId
                ]);
                setFlash('success', 'Medikament „' . $name . '“ aktualisiert.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO health_medications
                    (user_id, profile_id, name, active_substance, form, strength,
                     dosage_morning, dosage_noon, dosage_evening, dosage_night,
                     intake_instructions, reason, prescribed_by, stock_units, stock_unit_type,
                     daily_consumption, package_refill_threshold, is_active)
                    VALUES
                    (:uid, :pid, :name, :act, :form, :str, :m, :n, :e, :ni, :instr, :reason, :doc, :stock, :stype, :dc, :th, :actv)');
                $stmt->execute([
                    ':uid' => $userId, ':pid' => $activeProfileId, ':name' => $name, ':act' => $active, ':form' => $form, ':str' => $strength,
                    ':m' => $morn, ':n' => $noon, ':e' => $eve, ':ni' => $night,
                    ':instr' => $instr, ':reason' => $reason, ':doc' => $doc,
                    ':stock' => $stock, ':stype' => $sType, ':dc' => $consump, ':th' => $thresh, ':actv' => $isActive
                ]);
                setFlash('success', 'Medikament „' . $name . '“ in Medikationsplan eingetragen.');
            }
        }
        header('Location: health.php?tab=meds');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Medikamenten-Vorrat schnell anpassen (+ / -)
    // ------------------------------------------------------------------------
    if ($action === 'adjust_med_stock') {
        $medId = (int)($_POST['med_id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        if ($medId > 0 && $delta !== 0) {
            $pdo->prepare('UPDATE health_medications SET stock_units = GREATEST(0, stock_units + :delta) WHERE id = :id AND user_id = :uid AND profile_id = :pid')
                ->execute([':delta' => $delta, ':id' => $medId, ':uid' => $userId, ':pid' => $activeProfileId]);
            setFlash('success', 'Vorrat aktualisiert (' . ($delta > 0 ? ('+' . $delta) : $delta) . ').');
        }
        header('Location: health.php?tab=meds');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Medikament löschen
    // ------------------------------------------------------------------------
    if ($action === 'delete_medication') {
        $medId = (int)($_POST['med_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_medications WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $medId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Medikament entfernt.');
        header('Location: health.php?tab=meds');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Arzttermin speichern
    // ------------------------------------------------------------------------
    if ($action === 'save_doctor_visit') {
        $vId     = (int)($_POST['visit_id'] ?? 0);
        $docName = mb_substr(trim((string)($_POST['doctor_name'] ?? '')), 0, 190);
        $spec    = mb_substr(trim((string)($_POST['specialty'] ?? 'Hausarzt')), 0, 100);
        $vDate   = !empty($_POST['visit_date']) ? (string)$_POST['visit_date'] : date('Y-m-d');
        $vTime   = mb_substr(trim((string)($_POST['visit_time'] ?? '')), 0, 10);
        $status  = mb_substr(trim((string)($_POST['status'] ?? 'planned')), 0, 20);
        $quest   = trim((string)($_POST['questions_before'] ?? ''));
        $resSum  = trim((string)($_POST['results_summary'] ?? ''));
        $icd     = mb_substr(trim((string)($_POST['diagnosis_icd'] ?? '')), 0, 150);
        $nextDate= !empty($_POST['next_checkup_date']) ? (string)$_POST['next_checkup_date'] : null;

        if ($docName !== '' && $activeProfileId > 0) {
            if ($vId > 0) {
                $stmt = $pdo->prepare('UPDATE health_doctor_visits SET
                    doctor_name = :doc, specialty = :spec, visit_date = :vdate, visit_time = :vtime,
                    status = :status, questions_before = :quest, results_summary = :res, diagnosis_icd = :icd, next_checkup_date = :next
                    WHERE id = :id AND user_id = :uid AND profile_id = :pid');
                $stmt->execute([
                    ':doc' => $docName, ':spec' => $spec, ':vdate' => $vDate, ':vtime' => $vTime,
                    ':status' => $status, ':quest' => $quest, ':res' => $resSum, ':icd' => $icd, ':next' => $nextDate,
                    ':id' => $vId, ':uid' => $userId, ':pid' => $activeProfileId
                ]);
                setFlash('success', 'Arzttermin aktualisiert.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO health_doctor_visits
                    (user_id, profile_id, doctor_name, specialty, visit_date, visit_time, status, questions_before, results_summary, diagnosis_icd, next_checkup_date)
                    VALUES (:uid, :pid, :doc, :spec, :vdate, :vtime, :status, :quest, :res, :icd, :next)');
                $stmt->execute([
                    ':uid' => $userId, ':pid' => $activeProfileId, ':doc' => $docName, ':spec' => $spec,
                    ':vdate' => $vDate, ':vtime' => $vTime, ':status' => $status, ':quest' => $quest,
                    ':res' => $resSum, ':icd' => $icd, ':next' => $nextDate
                ]);
                setFlash('success', 'Arzttermin erfolgreich angelegt.');
            }
        }
        header('Location: health.php?tab=visits');
        exit;
    }

    if ($action === 'delete_doctor_visit') {
        $vId = (int)($_POST['visit_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_doctor_visits WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $vId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Arzttermin gelöscht.');
        header('Location: health.php?tab=visits');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Laborwert eintragen
    // ------------------------------------------------------------------------
    if ($action === 'save_lab_value') {
        $pKey   = mb_substr(trim((string)($_POST['parameter_key'] ?? 'custom')), 0, 50);
        $pName  = mb_substr(trim((string)($_POST['parameter_name'] ?? '')), 0, 100);
        $mDate  = !empty($_POST['measured_date']) ? (string)$_POST['measured_date'] : date('Y-m-d');
        $valNum = (float)str_replace(',', '.', (string)($_POST['value_numeric'] ?? '0'));
        $unit   = mb_substr(trim((string)($_POST['unit'] ?? 'mg/dl')), 0, 30);
        $nMin   = isset($_POST['norm_min']) && trim((string)$_POST['norm_min']) !== '' ? (float)str_replace(',', '.', (string)$_POST['norm_min']) : null;
        $nMax   = isset($_POST['norm_max']) && trim((string)$_POST['norm_max']) !== '' ? (float)str_replace(',', '.', (string)$_POST['norm_max']) : null;
        $notes  = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        // Automatische Bewertung
        $assess = 'normal';
        if ($nMin !== null && $valNum < $nMin) {
            $assess = 'low';
        } elseif ($nMax !== null && $valNum > $nMax) {
            $assess = 'high';
        }

        if ($pName !== '' && $activeProfileId > 0) {
            $stmt = $pdo->prepare('INSERT INTO health_lab_values
                (user_id, profile_id, measured_date, parameter_key, parameter_name, value_numeric, unit, norm_min, norm_max, assessment, notes)
                VALUES (:uid, :pid, :mdate, :pkey, :pname, :val, :unit, :nmin, :nmax, :assess, :notes)');
            $stmt->execute([
                ':uid' => $userId, ':pid' => $activeProfileId, ':mdate' => $mDate, ':pkey' => $pKey,
                ':pname' => $pName, ':val' => $valNum, ':unit' => $unit, ':nmin' => $nMin, ':nmax' => $nMax,
                ':assess' => $assess, ':notes' => $notes
            ]);
            setFlash('success', 'Laborwert „' . $pName . '“ eingetragen.');
        }
        header('Location: health.php?tab=lab');
        exit;
    }

    if ($action === 'delete_lab_value') {
        $labId = (int)($_POST['lab_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_lab_values WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $labId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Laborwert gelöscht.');
        header('Location: health.php?tab=lab');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Medizinisches Dokument / Befund hochladen
    // ------------------------------------------------------------------------
    if ($action === 'upload_document') {
        $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 190);
        $cat    = mb_substr(trim((string)($_POST['category'] ?? 'doctor_letter')), 0, 50);
        $docDate= !empty($_POST['document_date']) ? (string)$_POST['document_date'] : date('Y-m-d');
        $issuer = mb_substr(trim((string)($_POST['issuer'] ?? '')), 0, 190);
        $summary= trim((string)($_POST['summary'] ?? ''));

        if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK && $activeProfileId > 0) {
            $tmpPath  = (string)$_FILES['doc_file']['tmp_name'];
            $origName = sanitizeFileName(basename((string)$_FILES['doc_file']['name']));
            $fileSize = (int)$_FILES['doc_file']['size'];
            $ext      = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));

            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'txt', 'docx', 'odt'];
            if (!in_array($ext, $allowedExts, true)) {
                setFlash('error', 'Nur PDF-, Bild- und Textdokumente sind erlaubt (.pdf, .jpg, .png, .docx).');
                header('Location: health.php?tab=docs');
                exit;
            }

            $safeFolder = 'Gesundheit/' . ($activeProfile ? sanitizeFileName($activeProfile['name']) : 'Patientenakte');
            registerFolderHierarchy($pdo, $userId, $safeFolder);

            $storedName = generateStoredFileName($ext);
            $destPath   = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $storedName;

            if (move_uploaded_file($tmpPath, $destPath)) {
                @chmod($destPath, 0640);
                $mimeType = mime_content_type($destPath) ?: 'application/octet-stream';

                $stmtInsFile = $pdo->prepare('INSERT INTO files
                    (user_id, folder_path, filename, original_name, file_size, mime_type, is_encrypted)
                    VALUES (:uid, :fp, :fn, :on, :fs, :mt, 0)');
                $stmtInsFile->execute([
                    ':uid' => $userId, ':fp' => $safeFolder, ':fn' => $storedName,
                    ':on' => $origName, ':fs' => $fileSize, ':mt' => $mimeType
                ]);
                $newFileId = (int)$pdo->lastInsertId();

                $stmtInsDoc = $pdo->prepare('INSERT INTO health_documents
                    (user_id, profile_id, title, category, document_date, issuer, summary, file_id, include_in_dossier)
                    VALUES (:uid, :pid, :title, :cat, :ddate, :issuer, :sum, :fid, 1)');
                $stmtInsDoc->execute([
                    ':uid' => $userId, ':pid' => $activeProfileId,
                    ':title' => $title !== '' ? $title : pathinfo($origName, PATHINFO_FILENAME),
                    ':cat' => $cat, ':ddate' => $docDate, ':issuer' => $issuer,
                    ':sum' => $summary, ':fid' => $newFileId
                ]);

                setFlash('success', 'Befund/Dokument erfolgreich archiviert.');
            }
        }
        header('Location: health.php?tab=docs');
        exit;
    }

    if ($action === 'delete_document') {
        $docId = (int)($_POST['document_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_documents WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $docId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Dokument aus Patientenakte entfernt.');
        header('Location: health.php?tab=docs');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Patientenverfügung aus Vorlage in Archiv speichern & aktivieren
    // ------------------------------------------------------------------------
    if ($action === 'save_living_will_doc') {
        $willText   = trim((string)($_POST['living_will_text'] ?? ''));
        $willLoc    = mb_substr(trim((string)($_POST['living_will_location'] ?? 'MiniCloud Patientenakte (ausgedruckt bei Unterlagen)')), 0, 255);
        $markActive = isset($_POST['mark_active']) && $_POST['mark_active'] === '1';

        if ($willText !== '' && $activeProfileId > 0) {
            $pNameSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($activeProfile['name'] ?? 'Patient'));
            $docFileName = 'Patientenverfuegung_' . $pNameSafe . '_' . date('Y-m-d') . '.txt';
            $uploadBaseDir = __DIR__ . '/uploads';
            $safeFolder = 'Gesundheit/' . $pNameSafe;
            $targetDir = $uploadBaseDir . '/' . $safeFolder;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $storedName = 'will_' . bin2hex(random_bytes(8)) . '.txt';
            $targetFile = $targetDir . '/' . $storedName;
            file_put_contents($targetFile, $willText);
            $fileSize = strlen($willText);

            $stmtInsFile = $pdo->prepare('INSERT INTO files
                (user_id, folder_path, filename, original_name, file_size, mime_type, is_encrypted)
                VALUES (:uid, :fp, :fn, :on, :fs, :mt, 0)');
            $stmtInsFile->execute([
                ':uid' => $userId, ':fp' => $safeFolder, ':fn' => $storedName,
                ':on' => $docFileName, ':fs' => $fileSize, ':mt' => 'text/plain'
            ]);
            $newFileId = (int)$pdo->lastInsertId();

            $stmtInsDoc = $pdo->prepare('INSERT INTO health_documents
                (user_id, profile_id, title, category, document_date, issuer, summary, file_id, include_in_dossier)
                VALUES (:uid, :pid, :title, :cat, :ddate, :issuer, :sum, :fid, 1)');
            $stmtInsDoc->execute([
                ':uid' => $userId, ':pid' => $activeProfileId,
                ':title' => 'Patientenverfügung nach BMJ (§ 1901a BGB)',
                ':cat' => 'living_will',
                ':ddate' => date('Y-m-d'),
                ':issuer' => (string)($activeProfile['name'] ?? 'Verfügender'),
                ':sum' => 'Formgerechte Patientenverfügung mit Festlegungen zu lebenserhaltenden Maßnahmen, Palliative Care, Beatmung, künstlicher Ernährung und Vorsorgebevollmächtigten.',
                ':fid' => $newFileId
            ]);

            if ($markActive) {
                $pdo->prepare('UPDATE health_profiles SET has_living_will = 1, living_will_location = :loc WHERE id = :pid AND user_id = :uid')
                    ->execute([':loc' => $willLoc, ':pid' => $activeProfileId, ':uid' => $userId]);
            }

            setFlash('success', 'Patientenverfügung erfolgreich generiert, im Befund-Archiv hinterlegt und im Notfallpass aktiviert.');
        }
        header('Location: health.php?tab=emergency' . ($activeProfileId > 0 ? '&profile=' . $activeProfileId : ''));
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Impfung / Vorsorge eintragen
    // ------------------------------------------------------------------------
    if ($action === 'save_vaccine') {
        $type    = mb_substr(trim((string)($_POST['type'] ?? 'vaccine')), 0, 30);
        $name    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
        $admDate = !empty($_POST['administered_date']) ? (string)$_POST['administered_date'] : date('Y-m-d');
        $batch   = mb_substr(trim((string)($_POST['batch_number'] ?? '')), 0, 50);
        $clinic  = mb_substr(trim((string)($_POST['doctor_clinic'] ?? '')), 0, 150);
        $notes   = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
        $nextDate= !empty($_POST['next_due_date']) ? (string)$_POST['next_due_date'] : null;

        // Automatische STIKO-Fälligkeit berechnen, falls leer
        if ($nextDate === null && $type === 'vaccine') {
            $lowerName = strtolower($name);
            if (str_contains($lowerName, 'tetanus') || str_contains($lowerName, 'diphtherie') || str_contains($lowerName, 'polio')) {
                $nextDate = date('Y-m-d', strtotime($admDate . ' + 10 years'));
            } elseif (str_contains($lowerName, 'fsme') || str_contains($lowerName, 'zecke')) {
                $nextDate = date('Y-m-d', strtotime($admDate . ' + 5 years'));
            } elseif (str_contains($lowerName, 'grippe') || str_contains($lowerName, 'influenza')) {
                $nextDate = date('Y-m-d', strtotime($admDate . ' + 1 year'));
            }
        } elseif ($nextDate === null && $type === 'dental_checkup') {
            $nextDate = date('Y-m-d', strtotime($admDate . ' + 1 year'));
        }

        if ($name !== '' && $activeProfileId > 0) {
            $stmt = $pdo->prepare('INSERT INTO health_vaccines
                (user_id, profile_id, type, name, administered_date, batch_number, doctor_clinic, next_due_date, notes)
                VALUES (:uid, :pid, :type, :name, :adate, :batch, :clinic, :next, :notes)');
            $stmt->execute([
                ':uid' => $userId, ':pid' => $activeProfileId, ':type' => $type, ':name' => $name,
                ':adate' => $admDate, ':batch' => $batch, ':clinic' => $clinic, ':next' => $nextDate, ':notes' => $notes
            ]);
            setFlash('success', 'Eintrag („' . $name . '“) im Impf- & Vorsorgeheft erfasst.');
        }
        header('Location: health.php?tab=vaccines');
        exit;
    }

    if ($action === 'delete_vaccine') {
        $vacId = (int)($_POST['vaccine_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_vaccines WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $vacId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Eintrag entfernt.');
        header('Location: health.php?tab=vaccines');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Vitalwert (Blutdruck, Puls, Gewicht) erfassen
    // ------------------------------------------------------------------------
    if ($action === 'save_vital') {
        $recAt   = !empty($_POST['recorded_at']) ? str_replace('T', ' ', (string)$_POST['recorded_at']) : date('Y-m-d H:i:s');
        $systolic= isset($_POST['systolic']) && trim((string)$_POST['systolic']) !== '' ? (int)$_POST['systolic'] : null;
        $diast   = isset($_POST['diastolic']) && trim((string)$_POST['diastolic']) !== '' ? (int)$_POST['diastolic'] : null;
        $pulse   = isset($_POST['pulse']) && trim((string)$_POST['pulse']) !== '' ? (int)$_POST['pulse'] : null;
        $weight  = isset($_POST['weight_kg']) && trim((string)$_POST['weight_kg']) !== '' ? (float)str_replace(',', '.', (string)$_POST['weight_kg']) : null;
        $sugar   = isset($_POST['blood_sugar']) && trim((string)$_POST['blood_sugar']) !== '' ? (float)str_replace(',', '.', (string)$_POST['blood_sugar']) : null;
        $temp    = isset($_POST['temperature_c']) && trim((string)$_POST['temperature_c']) !== '' ? (float)str_replace(',', '.', (string)$_POST['temperature_c']) : null;
        $notes   = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        if ($activeProfileId > 0 && ($systolic !== null || $weight !== null || $pulse !== null || $sugar !== null || $temp !== null)) {
            $stmt = $pdo->prepare('INSERT INTO health_vitals
                (user_id, profile_id, recorded_at, systolic, diastolic, pulse, weight_kg, blood_sugar, temperature_c, notes)
                VALUES (:uid, :pid, :rec, :sys, :dia, :pul, :wgt, :sug, :tmp, :notes)');
            $stmt->execute([
                ':uid' => $userId, ':pid' => $activeProfileId, ':rec' => $recAt,
                ':sys' => $systolic, ':dia' => $diast, ':pul' => $pulse,
                ':wgt' => $weight, ':sug' => $sugar, ':tmp' => $temp, ':notes' => $notes
            ]);
            setFlash('success', 'Vitalwert erfolgreich gemessen & gespeichert.');
        }
        header('Location: health.php?tab=vitals');
        exit;
    }

    if ($action === 'delete_vital') {
        $vId = (int)($_POST['vital_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_vitals WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $vId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Vitalwert gelöscht.');
        header('Location: health.php?tab=vitals');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Symptom- & Schmerztagebuch
    // ------------------------------------------------------------------------
    if ($action === 'save_symptom') {
        $sName   = mb_substr(trim((string)($_POST['symptom_name'] ?? '')), 0, 150);
        $startAt = !empty($_POST['started_at']) ? str_replace('T', ' ', (string)$_POST['started_at']) : date('Y-m-d H:i:s');
        $intens  = max(1, min(10, (int)($_POST['intensity'] ?? 5)));
        $dur     = isset($_POST['duration_hours']) && trim((string)$_POST['duration_hours']) !== '' ? (float)str_replace(',', '.', (string)$_POST['duration_hours']) : null;
        $trig    = mb_substr(trim((string)($_POST['triggers'] ?? '')), 0, 255);
        $treat   = mb_substr(trim((string)($_POST['treatment'] ?? '')), 0, 255);
        $weather = mb_substr(trim((string)($_POST['weather_condition'] ?? '')), 0, 100);
        $notes   = trim((string)($_POST['notes'] ?? ''));

        if ($sName !== '' && $activeProfileId > 0) {
            $stmt = $pdo->prepare('INSERT INTO health_symptoms
                (user_id, profile_id, started_at, symptom_name, intensity, duration_hours, triggers, treatment, weather_condition, notes)
                VALUES (:uid, :pid, :start, :name, :intens, :dur, :trig, :treat, :weather, :notes)');
            $stmt->execute([
                ':uid' => $userId, ':pid' => $activeProfileId, ':start' => $startAt, ':name' => $sName,
                ':intens' => $intens, ':dur' => $dur, ':trig' => $trig, ':treat' => $treat,
                ':weather' => $weather, ':notes' => $notes
            ]);
            setFlash('success', 'Symptom-Eintrag im Tagebuch gespeichert.');
        }
        header('Location: health.php?tab=vitals');
        exit;
    }

    if ($action === 'delete_symptom') {
        $sId = (int)($_POST['symptom_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_symptoms WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $sId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Symptom-Eintrag gelöscht.');
        header('Location: health.php?tab=vitals');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Brillenpass speichern
    // ------------------------------------------------------------------------
    if ($action === 'save_vision_pass') {
        $pDate  = !empty($_POST['pass_date']) ? (string)$_POST['pass_date'] : date('Y-m-d');
        $type   = mb_substr(trim((string)($_POST['type'] ?? 'glasses')), 0, 50);
        $opti   = mb_substr(trim((string)($_POST['optician_doctor'] ?? '')), 0, 150);
        $rSph   = (float)str_replace(',', '.', (string)($_POST['right_sph'] ?? '0'));
        $rCyl   = (float)str_replace(',', '.', (string)($_POST['right_cyl'] ?? '0'));
        $rAxis  = (int)($_POST['right_axis'] ?? 0);
        $rAdd   = (float)str_replace(',', '.', (string)($_POST['right_add'] ?? '0'));
        $lSph   = (float)str_replace(',', '.', (string)($_POST['left_sph'] ?? '0'));
        $lCyl   = (float)str_replace(',', '.', (string)($_POST['left_cyl'] ?? '0'));
        $lAxis  = (int)($_POST['left_axis'] ?? 0);
        $lAdd   = (float)str_replace(',', '.', (string)($_POST['left_add'] ?? '0'));
        $pd     = (float)str_replace(',', '.', (string)($_POST['pupil_distance'] ?? '0'));
        $notes  = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        if ($activeProfileId > 0) {
            $stmt = $pdo->prepare('INSERT INTO health_vision_passes
                (user_id, profile_id, pass_date, type, optician_doctor,
                 right_sph, right_cyl, right_axis, right_add,
                 left_sph, left_cyl, left_axis, left_add, pupil_distance, notes)
                VALUES
                (:uid, :pid, :pdate, :type, :opti,
                 :rsph, :rcyl, :raxis, :radd,
                 :lsph, :lcyl, :laxis, :ladd, :pd, :notes)');
            $stmt->execute([
                ':uid' => $userId, ':pid' => $activeProfileId, ':pdate' => $pDate, ':type' => $type, ':opti' => $opti,
                ':rsph' => $rSph, ':rcyl' => $rCyl, ':raxis' => $rAxis, ':radd' => $rAdd,
                ':lsph' => $lSph, ':lcyl' => $lCyl, ':laxis' => $lAxis, ':ladd' => $lAdd,
                ':pd' => $pd, ':notes' => $notes
            ]);
            setFlash('success', 'Neuer Brillen- & Sehschärfe-Pass gespeichert.');
        }
        header('Location: health.php?tab=vision');
        exit;
    }

    if ($action === 'delete_vision_pass') {
        $vpId = (int)($_POST['pass_id'] ?? 0);
        $pdo->prepare('DELETE FROM health_vision_passes WHERE id = :id AND user_id = :uid AND profile_id = :pid')
            ->execute([':id' => $vpId, ':uid' => $userId, ':pid' => $activeProfileId]);
        setFlash('success', 'Brillenpass gelöscht.');
        header('Location: health.php?tab=vision');
        exit;
    }
}

// ============================================================================
// 4. DATEN FÜR DAS AKTIVE PROFIL LADEN
// ============================================================================
$medications = [];
$doctorVisits = [];
$labValues = [];
$documents = [];
$vaccines = [];
$checkups = [];
$vitals = [];
$symptoms = [];
$visionPasses = [];

if ($activeProfileId > 0) {
    // Medikamente
    $stmtM = $pdo->prepare('SELECT * FROM health_medications WHERE profile_id = :pid AND user_id = :uid ORDER BY is_active DESC, name ASC');
    $stmtM->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $medications = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    // Arzttermine
    $stmtV = $pdo->prepare('SELECT * FROM health_doctor_visits WHERE profile_id = :pid AND user_id = :uid ORDER BY visit_date DESC, visit_time DESC');
    $stmtV->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $doctorVisits = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    // Laborwerte
    $stmtL = $pdo->prepare('SELECT * FROM health_lab_values WHERE profile_id = :pid AND user_id = :uid ORDER BY measured_date DESC, parameter_name ASC');
    $stmtL->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $labValues = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    // Dokumente
    $stmtD = $pdo->prepare('SELECT d.*, f.original_name, f.filename, f.file_size, f.mime_type
        FROM health_documents d
        JOIN files f ON d.file_id = f.id
        WHERE d.profile_id = :pid AND d.user_id = :uid
        ORDER BY d.document_date DESC');
    $stmtD->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $documents = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    // Impfungen & Vorsorge
    $stmtVac = $pdo->prepare('SELECT * FROM health_vaccines WHERE profile_id = :pid AND user_id = :uid ORDER BY administered_date DESC');
    $stmtVac->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $allVac = $stmtVac->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allVac as $vItem) {
        if (($vItem['type'] ?? '') === 'vaccine') {
            $vaccines[] = $vItem;
        } else {
            $checkups[] = $vItem;
        }
    }

    // Vitalwerte
    $stmtVit = $pdo->prepare('SELECT * FROM health_vitals WHERE profile_id = :pid AND user_id = :uid ORDER BY recorded_at DESC LIMIT 60');
    $stmtVit->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $vitals = $stmtVit->fetchAll(PDO::FETCH_ASSOC);

    // Symptome
    $stmtSym = $pdo->prepare('SELECT * FROM health_symptoms WHERE profile_id = :pid AND user_id = :uid ORDER BY started_at DESC LIMIT 40');
    $stmtSym->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $symptoms = $stmtSym->fetchAll(PDO::FETCH_ASSOC);

    // Brillenpass
    $stmtVis = $pdo->prepare('SELECT * FROM health_vision_passes WHERE profile_id = :pid AND user_id = :uid ORDER BY pass_date DESC');
    $stmtVis->execute([':pid' => $activeProfileId, ':uid' => $userId]);
    $visionPasses = $stmtVis->fetchAll(PDO::FETCH_ASSOC);
}

// JSON-Felder des aktiven Profils dekodieren
$allergiesList   = !empty($activeProfile['allergies_json']) ? (json_decode($activeProfile['allergies_json'], true) ?: []) : [];
$conditionsList  = !empty($activeProfile['conditions_json']) ? (json_decode($activeProfile['conditions_json'], true) ?: []) : [];
$emergencyList   = !empty($activeProfile['emergency_contacts_json']) ? (json_decode($activeProfile['emergency_contacts_json'], true) ?: []) : [];

// Berechnungen für Vorrats-Ampel (Medikamente)
$medRefillWarnings = 0;
foreach ($medications as &$med) {
    $cons = (float)($med['daily_consumption'] ?? 1.0);
    $stk  = (int)($med['stock_units'] ?? 0);
    $daysLeft = $cons > 0 ? (int)floor($stk / $cons) : 999;
    $med['days_left'] = $daysLeft;
    if ((int)$med['is_active'] === 1 && $daysLeft <= (int)$med['package_refill_threshold']) {
        $medRefillWarnings++;
    }
}
unset($med);

// Standard-Parameter-Katalog für Laborwerte
$COMMON_LAB_PARAMS = [
    'cholesterol_total' => ['name' => 'Gesamt-Cholesterin', 'unit' => 'mg/dl', 'min' => 0, 'max' => 200],
    'cholesterol_ldl'   => ['name' => 'LDL-Cholesterin',   'unit' => 'mg/dl', 'min' => 0, 'max' => 115],
    'cholesterol_hdl'   => ['name' => 'HDL-Cholesterin',   'unit' => 'mg/dl', 'min' => 45, 'max' => 999],
    'triglycerides'     => ['name' => 'Triglyceride',      'unit' => 'mg/dl', 'min' => 0, 'max' => 150],
    'glucose_fasting'   => ['name' => 'Nüchtern-Glukose',  'unit' => 'mg/dl', 'min' => 70, 'max' => 99],
    'hba1c'             => ['name' => 'HbA1c (Langzeitzucker)', 'unit' => '%', 'min' => 4.0, 'max' => 5.7],
    'tsh'               => ['name' => 'TSH (Schilddrüse)', 'unit' => 'µIU/ml', 'min' => 0.4, 'max' => 4.0],
    'ferritin'          => ['name' => 'Ferritin (Eisenspeicher)', 'unit' => 'µg/l', 'min' => 30, 'max' => 400],
    'vitamin_d'         => ['name' => 'Vitamin D (25-OH)', 'unit' => 'ng/ml', 'min' => 30, 'max' => 70],
    'creatinine'        => ['name' => 'Kreatinin (Niere)', 'unit' => 'mg/dl', 'min' => 0.7, 'max' => 1.2],
    'alt_gpt'           => ['name' => 'ALT / GPT (Leber)', 'unit' => 'U/l',   'min' => 0, 'max' => 50],
    'uric_acid'         => ['name' => 'Harnsäure',         'unit' => 'mg/dl', 'min' => 3.5, 'max' => 7.0],
];

// Neueste Vitalwerte
$latestVital = !empty($vitals) ? $vitals[0] : null;

$pageTitle = '🩺 Gesundheit & Digitale Patientenakte';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ====================================================================== -->
    <!-- HEADER: TITEL & PROFIL-WECHSLER (FAMILIE / HAUSTIERE)                  -->
    <!-- ====================================================================== -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-emerald-900 via-teal-950 to-slate-900 text-white p-5 rounded-3xl shadow-xl border border-emerald-500/30">
        <div class="flex items-center gap-3.5 min-w-0">
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 border border-emerald-400/40 flex items-center justify-center text-2xl shrink-0 shadow-inner">
                🩺
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight truncate">Gesundheit &amp; Patientenakte</h1>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500 text-slate-950">100% Privat</span>
                </div>
                <p class="text-xs sm:text-sm text-emerald-200/90 truncate mt-0.5">
                    Notfallpass, Medikationsplan, Labor-Trends, STIKO-Impfungen &amp; Befunde sicher auf deinem Server
                </p>
            </div>
        </div>

        <!-- Profil-Wähler & Schnellaktionen -->
        <div class="flex items-center gap-2 flex-wrap shrink-0">
            <!-- Profil-Dropdown -->
            <div class="relative inline-block text-left">
                <form method="get" class="inline">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                    <select name="profile" onchange="this.form.submit()"
                            class="bg-white/10 hover:bg-white/20 text-white text-xs sm:text-sm font-semibold px-3 py-2 rounded-xl border border-white/20 cursor-pointer focus:outline-hidden">
                        <?php foreach ($allProfiles as $prof): ?>
                            <option value="<?= (int)$prof['id'] ?>" class="text-slate-900 bg-white" <?= (int)$prof['id'] === $activeProfileId ? 'selected' : '' ?>>
                                👤 <?= e($prof['name']) ?> (<?= e(match ($prof['relation']) {
                                    'self' => 'Ich',
                                    'partner' => 'Partner/in',
                                    'child' => 'Kind',
                                    'parent' => 'Eltern',
                                    'pet' => 'Haustier',
                                    default => 'Angehörige/r'
                                }) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- Profil bearbeiten -->
            <button type="button" onclick="openProfileModal(<?= (int)$activeProfileId ?>)"
                    class="px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-semibold border border-white/20 transition cursor-pointer flex items-center gap-1.5"
                    title="Aktuelles Profil bearbeiten">
                ✏️ <span class="hidden sm:inline">Profil</span>
            </button>

            <!-- Neues Profil anlegen -->
            <button type="button" onclick="openProfileModal(0)"
                    class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition cursor-pointer flex items-center gap-1.5"
                    title="Weiteres Familienmitglied anlegen">
                ➕ <span class="hidden sm:inline">Neues Profil</span>
            </button>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- MODUL-NAVIGATION (8 REITER FÜR ALLE GESUNDHEITSBEREICHE)                -->
    <!-- ====================================================================== -->
    <div class="bg-slate-100/90 dark:bg-slate-800/80 p-1.5 rounded-2xl border border-slate-200/80 dark:border-slate-700/80 shadow-2xs">
        <nav class="grid grid-cols-4 lg:grid-cols-8 gap-1 sm:gap-1.5" aria-label="Gesundheits-Bereiche">
            <?php
            $tabsMeta = [
                'emergency' => ['label' => 'Notfallpass',       'short' => 'Notfall',      'icon' => '🚨', 'full' => 'Scheckkarten-Notfallpass & Notfallkontakte', 'badge' => ''],
                'meds'      => ['label' => 'Medikamente',       'short' => 'Medikamente',  'icon' => '💊', 'full' => 'Bundesmedikationsplan (BMP) & Vorratsampel', 'badge' => $medRefillWarnings > 0 ? ('⚠️ ' . $medRefillWarnings) : (count($medications) > 0 ? (string)count($medications) : '')],
                'visits'    => ['label' => 'Arzttermine',       'short' => 'Termine',      'icon' => '🗓️', 'full' => 'Arzttermine & Sprechstunden-Protokoll',      'badge' => count($doctorVisits) > 0 ? (string)count($doctorVisits) : ''],
                'lab'       => ['label' => 'Labor-Trends',      'short' => 'Labor',        'icon' => '🧪', 'full' => 'Laborwerte & Blutbild-Trends',               'badge' => count($labValues) > 0 ? (string)count($labValues) : ''],
                'docs'      => ['label' => 'Befund-Archiv',     'short' => 'Befunde',      'icon' => '📁', 'full' => 'Befunde, Arztbriefe & 1-Klick-Dossier',       'badge' => count($documents) > 0 ? (string)count($documents) : ''],
                'vaccines'  => ['label' => 'Impfen & Vorsorge', 'short' => 'Impfen',       'icon' => '💉', 'full' => 'STIKO-Impfpass & Vorsorge-Bonusheft',        'badge' => (count($vaccines) + count($checkups)) > 0 ? (string)(count($vaccines) + count($checkups)) : ''],
                'vitals'    => ['label' => 'Vital & Schmerz',   'short' => 'Vitalwerte',   'icon' => '📈', 'full' => 'Vitalwerte (Blutdruck/BMI) & Schmerztagebuch','badge' => count($vitals) > 0 ? (string)count($vitals) : ''],
                'vision'    => ['label' => 'Brillenpass',       'short' => 'Brille',       'icon' => '👓', 'full' => 'Brillen- & Sehschärfe-Pass',                 'badge' => count($visionPasses) > 0 ? (string)count($visionPasses) : ''],
            ];
            foreach ($tabsMeta as $tKey => $tData):
                $isActive = $activeTab === $tKey;
            ?>
                <a href="health.php?tab=<?= urlencode($tKey) ?><?= $activeProfileId > 0 ? '&amp;profile=' . $activeProfileId : '' ?>"
                   title="<?= e($tData['full']) ?>"
                   class="relative flex flex-col sm:flex-row items-center justify-center gap-0.5 sm:gap-1.5 px-1.5 sm:px-2 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs transition-all text-center <?= $isActive ? 'bg-white dark:bg-slate-900 text-emerald-600 dark:text-emerald-400 font-bold shadow-xs ring-1 ring-slate-200/80 dark:ring-slate-700' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-white/60 dark:hover:bg-slate-700/50 font-medium' ?>">
                    <span class="text-base sm:text-sm leading-none shrink-0"><?= $tData['icon'] ?></span>
                    <span class="truncate hidden xl:inline"><?= e($tData['label']) ?></span>
                    <span class="truncate inline xl:hidden"><?= e($tData['short']) ?></span>
                    <?php if ($tData['badge'] !== ''): ?>
                        <span class="absolute -top-1.5 -right-1 px-1.5 py-0.2 rounded-full text-[9px] font-mono font-bold leading-tight shadow-2xs <?= $isActive ? 'bg-emerald-600 text-white' : ($tKey === 'meds' && $medRefillWarnings > 0 ? 'bg-rose-500 text-white animate-pulse' : 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300') ?>">
                            <?= e($tData['badge']) ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- ====================================================================== -->
    <!-- TAB 1: NOTFALLPASS & PROFIL                                            -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'emergency'): ?>
        <div class="space-y-6">
            <!-- SCHECKKARTEN-NOTFALLPASS (ZWEISPALTIG / SCHUTZANSICHT) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="printableEmergencyCardArea">

                <!-- KARTE 1: STAMMDATEN, ALLERGIEN & NOTFALL-KONTAKTE -->
                <div class="mc-card p-6 bg-gradient-to-br from-white via-rose-50/30 to-white dark:from-slate-900 dark:via-rose-950/20 dark:to-slate-900 border-2 border-rose-500/40 rounded-3xl shadow-lg relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-rose-600 text-white text-[10px] font-extrabold px-3 py-1 rounded-bl-xl tracking-wider uppercase">
                        🚨 Notfallpass
                    </div>

                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-12 h-12 rounded-full bg-rose-600 text-white flex items-center justify-center text-xl font-bold shrink-0 shadow-md">
                            <?= !empty($activeProfile['blood_type']) ? e($activeProfile['blood_type']) : '🩸' ?>
                        </div>
                        <div>
                            <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white"><?= e($activeProfile['name'] ?? 'Patient') ?></h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Geboren: <?= !empty($activeProfile['birth_date']) ? date('d.m.Y', strtotime($activeProfile['birth_date'])) : 'Nicht hinterlegt' ?>
                                &bull; Blutgruppe: <span class="font-bold text-rose-600 dark:text-rose-400"><?= e($activeProfile['blood_type'] ?: 'Unbekannt') ?> <?= e($activeProfile['rhesus_factor']) ?></span>
                            </p>
                        </div>
                    </div>

                    <!-- ALLERGIEN (LEBENSRETTEND) -->
                    <div class="mb-4 p-3 rounded-2xl bg-rose-100/80 dark:bg-rose-950/50 border border-rose-300 dark:border-rose-800">
                        <h3 class="text-xs font-bold text-rose-900 dark:text-rose-200 flex items-center gap-1.5 mb-1.5">
                            ⚠️ Allergien &amp; Unverträglichkeiten:
                        </h3>
                        <?php if (!empty($allergiesList)): ?>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($allergiesList as $alg): ?>
                                    <span class="px-2.5 py-0.5 rounded-lg bg-rose-600 text-white font-bold text-xs shadow-xs">
                                        <?= e($alg) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-rose-800 dark:text-rose-300 italic">Keine bekannten Allergien hinterlegt.</p>
                        <?php endif; ?>
                    </div>

                    <!-- CHRONISCHE VORERKRANKUNGEN & IMPLANTATE -->
                    <div class="space-y-2 mb-4 text-xs">
                        <div>
                            <span class="font-bold text-slate-700 dark:text-slate-300">Chronische Erkrankungen:</span>
                            <?php if (!empty($conditionsList)): ?>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    <?php foreach ($conditionsList as $cnd): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs">
                                            <?= e($cnd) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-slate-500 italic ml-1">Keine erfasst</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($activeProfile['implants'])): ?>
                            <div>
                                <span class="font-bold text-slate-700 dark:text-slate-300">Implantate / Schrittmacher:</span>
                                <span class="text-slate-800 dark:text-slate-200 ml-1 font-semibold"><?= e($activeProfile['implants']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- NOTFALLKONTAKTE (ICE) -->
                    <div class="pt-3 border-t border-slate-200 dark:border-slate-800">
                        <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">📞 Notfallkontakte (ICE - In Case of Emergency):</h3>
                        <?php if (!empty($emergencyList)): ?>
                            <div class="space-y-1.5 text-xs">
                                <?php foreach ($emergencyList as $ice): ?>
                                    <div class="flex items-center justify-between p-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                        <div>
                                            <span class="font-bold text-slate-900 dark:text-white"><?= e($ice['name'] ?? 'Kontakt') ?></span>
                                            <?php if (!empty($ice['rel'])): ?>
                                                <span class="text-[11px] text-slate-500">(<?= e($ice['rel']) ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <a href="tel:<?= e($ice['phone'] ?? '') ?>" class="font-mono font-bold text-emerald-600 dark:text-emerald-400 hover:underline">
                                            <?= e($ice['phone'] ?? '') ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-slate-400 italic">Noch keine Notfallkontakte hinterlegt.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KARTE 2: DAUERMEDIKATION, VORSORGEVOLLMACHT & KRANKENKASSE -->
                <div class="mc-card p-6 bg-gradient-to-br from-white via-teal-50/20 to-white dark:from-slate-900 dark:via-teal-950/20 dark:to-slate-900 border-2 border-teal-500/40 rounded-3xl shadow-lg flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-extrabold uppercase tracking-wider text-teal-800 dark:text-teal-300 flex items-center gap-1.5">
                                💊 Aktuelle Dauermedikation
                            </h3>
                            <a href="health.php?tab=meds" class="text-xs text-emerald-600 dark:text-emerald-400 font-bold hover:underline">
                                Zum Einnahmeplan &rarr;
                            </a>
                        </div>

                        <?php
                        $activeMeds = array_filter($medications, fn($m) => (int)($m['is_active'] ?? 0) === 1);
                        if (!empty($activeMeds)):
                        ?>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                                <?php foreach (array_slice($activeMeds, 0, 5) as $am): ?>
                                    <div class="py-2 flex items-center justify-between">
                                        <div>
                                            <span class="font-bold text-slate-900 dark:text-white"><?= e($am['name']) ?></span>
                                            <span class="text-slate-500 ml-1">(<?= e($am['strength']) ?>)</span>
                                            <?php if (!empty($am['reason'])): ?>
                                                <div class="text-[11px] text-slate-400 italic"><?= e($am['reason']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-right font-mono font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/60 px-2 py-0.5 rounded-md border border-emerald-500/20">
                                            <?= e($am['dosage_morning']) ?> - <?= e($am['dosage_noon']) ?> - <?= e($am['dosage_evening']) ?> - <?= e($am['dosage_night']) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-xs text-slate-400 italic mb-4">Keine Dauermedikation erfasst.</p>
                        <?php endif; ?>

                        <!-- RECHTLICHE VORSORGE & VERFÜGUNGEN -->
                        <div class="mt-6 pt-4 border-t border-slate-200 dark:border-slate-800 space-y-2 text-xs">
                            <h3 class="font-extrabold text-slate-800 dark:text-slate-200 mb-1">📜 Patientenverfügung &amp; Vorsorge:</h3>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-300">Patientenverfügung vorhanden:</span>
                                <span class="font-bold <?= (int)($activeProfile['has_living_will'] ?? 0) === 1 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' ?>">
                                    <?= (int)($activeProfile['has_living_will'] ?? 0) === 1 ? '✅ Ja' : '❌ Nein' ?>
                                </span>
                            </div>
                            <?php if (!empty($activeProfile['living_will_location'])): ?>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800 p-2 rounded-xl">
                                    📁 Hinterlegt bei / Ort: <span class="font-semibold text-slate-700 dark:text-slate-200"><?= e($activeProfile['living_will_location']) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-600 dark:text-slate-300">Organspendestatus:</span>
                                <span class="font-bold text-slate-800 dark:text-slate-200">
                                    <?= e(match ($activeProfile['organ_donor'] ?? '') {
                                        'yes' => '✅ Ja (Spenderausweis liegt vor)',
                                        'no' => '❌ Nein',
                                        'decision_card' => '📝 Siehe Organspendeausweis',
                                        default => 'Nicht hinterlegt'
                                    }) ?>
                                </span>
                            </div>
                            <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">Rechtssichere BMJ-Vorlage (§ 1901a BGB):</span>
                                <button type="button" onclick="openLivingWillModal()" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                    📜 Vorlage öffnen &rarr;
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- VERSICHERUNG & HAUSARZT -->
                    <div class="mt-6 pt-4 border-t border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                        <div>
                            <span>Krankenkasse:</span>
                            <span class="font-bold text-slate-700 dark:text-slate-200 ml-1"><?= e($activeProfile['insurance_name'] ?: 'Nicht angegeben') ?></span>
                            <?php if (!empty($activeProfile['insurance_number'])): ?>
                                <span class="font-mono text-[11px] text-slate-400 ml-1">(<?= e($activeProfile['insurance_number']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($activeProfile['primary_doctor'])): ?>
                            <div>
                                <span>Hausarzt:</span>
                                <span class="font-bold text-slate-700 dark:text-slate-200 ml-1"><?= e($activeProfile['primary_doctor']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- BUTTONS: AUSDRUCKEN & BEARBEITEN -->
            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                <button type="button" onclick="openLivingWillModal()"
                        class="px-4 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer">
                    📜 Patientenverfügung (Vorlage &amp; Generator)
                </button>
                <button type="button" onclick="window.print()"
                        class="px-4 py-2.5 rounded-2xl bg-slate-900 hover:bg-slate-800 dark:bg-slate-100 dark:hover:bg-white text-white dark:text-slate-950 font-bold text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer">
                    🖨️ Notfallausweis drucken (Scheckkarten-Format)
                </button>
                <button type="button" onclick="openProfileModal(<?= (int)$activeProfileId ?>)"
                        class="px-4 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer">
                    ✏️ Notfallpass-Daten bearbeiten
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 2: MEDIKAMENTE & BUNDESMEDIKATIONSPLAN (BMP)                       -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'meds'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        💊 Bundeseinheitlicher Medikationsplan (BMP)
                    </h2>
                    <p class="text-xs text-slate-500">
                        Einnahmeschema, Indikationen &amp; automatische Vorratsampel für rechtzeitige Folgerezepte
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="window.print()"
                            class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer">
                        🖨️ Plan drucken
                    </button>
                    <button type="button" onclick="openMedModal(0)"
                            class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        ➕ Medikament hinzufügen
                    </button>
                </div>
            </div>

            <?php if ($medRefillWarnings > 0): ?>
                <div class="p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-300 dark:border-rose-800 text-rose-900 dark:text-rose-200 flex items-center gap-3">
                    <span class="text-2xl shrink-0">🚨</span>
                    <div class="text-xs">
                        <span class="font-bold">Rezept-Nachbestellung erforderlich:</span>
                        Bei <?= $medRefillWarnings ?> Medikament(en) reicht der Vorrat für weniger als eine Woche! Bitte rechtzeitig ein Folgerezept in der Arztpraxis anfordern.
                    </div>
                </div>
            <?php endif; ?>

            <!-- MEDIKAMENTEN-TABELLE -->
            <div class="mc-card overflow-hidden rounded-3xl shadow-sm border border-slate-200 dark:border-slate-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/80 text-slate-700 dark:text-slate-300 border-b border-slate-200 dark:border-slate-700 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="p-3.5">Status</th>
                                <th class="p-3.5">Präparat &amp; Wirkstoff</th>
                                <th class="p-3.5">Stärke &amp; Form</th>
                                <th class="p-3.5 text-center">Morgens</th>
                                <th class="p-3.5 text-center">Mittags</th>
                                <th class="p-3.5 text-center">Abends</th>
                                <th class="p-3.5 text-center">Nachts</th>
                                <th class="p-3.5">Hinweise / Grund</th>
                                <th class="p-3.5 text-right">Vorrat</th>
                                <th class="p-3.5 text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-sans">
                            <?php if (empty($medications)): ?>
                                <tr>
                                    <td colspan="10" class="p-8 text-center text-slate-400 italic">
                                        Noch keine Medikamente für dieses Profil erfasst. Klicke auf „➕ Medikament hinzufügen“.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($medications as $m):
                                    $days = $m['days_left'] ?? 999;
                                    $isWarn = (int)$m['is_active'] === 1 && $days <= (int)$m['package_refill_threshold'];
                                    $isCrit = (int)$m['is_active'] === 1 && $days <= 3;
                                ?>
                                    <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition <?= (int)$m['is_active'] === 0 ? 'opacity-60 bg-slate-50/40 dark:bg-slate-900/40' : '' ?>">
                                        <td class="p-3.5 whitespace-nowrap">
                                            <?php if ((int)$m['is_active'] === 1): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Aktiv</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300">Abgesetzt</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3.5">
                                            <div class="font-extrabold text-slate-900 dark:text-white"><?= e($m['name']) ?></div>
                                            <?php if (!empty($m['active_substance'])): ?>
                                                <div class="text-[11px] text-slate-500 font-medium"><?= e($m['active_substance']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3.5 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                            <?= e($m['strength']) ?> &bull; <?= e(match ($m['form']) {
                                                'tablets' => 'Tabletten',
                                                'capsules' => 'Kapseln',
                                                'drops' => 'Tropfen',
                                                'spray' => 'Spray',
                                                'ointment' => 'Salbe',
                                                'injection' => 'Spritze',
                                                default => $m['form']
                                            }) ?>
                                        </td>
                                        <!-- DOSIERUNG -->
                                        <td class="p-3.5 text-center font-mono font-bold text-slate-900 dark:text-white bg-slate-50/50 dark:bg-slate-800/30"><?= e($m['dosage_morning']) ?></td>
                                        <td class="p-3.5 text-center font-mono font-bold text-slate-900 dark:text-white"><?= e($m['dosage_noon']) ?></td>
                                        <td class="p-3.5 text-center font-mono font-bold text-slate-900 dark:text-white bg-slate-50/50 dark:bg-slate-800/30"><?= e($m['dosage_evening']) ?></td>
                                        <td class="p-3.5 text-center font-mono font-bold text-slate-900 dark:text-white"><?= e($m['dosage_night']) ?></td>

                                        <td class="p-3.5">
                                            <?php if (!empty($m['intake_instructions'])): ?>
                                                <div class="text-slate-800 dark:text-slate-200"><?= e($m['intake_instructions']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($m['reason'])): ?>
                                                <div class="text-[11px] text-slate-400 italic">Grund: <?= e($m['reason']) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- VORRAT & AMPEL -->
                                        <td class="p-3.5 text-right whitespace-nowrap">
                                            <div class="font-mono font-extrabold <?= $isCrit ? 'text-rose-600 dark:text-rose-400 animate-pulse' : ($isWarn ? 'text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-300') ?>">
                                                <?= (int)$m['stock_units'] ?> <?= e($m['stock_unit_type']) ?>
                                            </div>
                                            <?php if ((int)$m['is_active'] === 1 && $days < 365): ?>
                                                <div class="text-[10px] <?= $isWarn ? 'font-bold text-rose-600 dark:text-rose-400' : 'text-slate-400' ?>">
                                                    reicht ca. <?= $days ?> Tag(e)
                                                </div>
                                            <?php endif; ?>
                                            <!-- Schnell-Klick (+ / -) -->
                                            <div class="flex items-center justify-end gap-1 mt-1">
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="adjust_med_stock">
                                                    <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="delta" value="-1">
                                                    <button type="submit" title="-1 Stück" class="w-5 h-5 rounded bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 text-[10px] font-bold cursor-pointer">-</button>
                                                </form>
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="adjust_med_stock">
                                                    <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="delta" value="30">
                                                    <button type="submit" title="+30 Stück (z.B. neue Packung)" class="px-1.5 h-5 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-bold cursor-pointer">+30</button>
                                                </form>
                                            </div>
                                        </td>

                                        <td class="p-3.5 text-right whitespace-nowrap">
                                            <button type="button" onclick="editMed(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)"
                                                    class="p-1.5 rounded-lg text-slate-500 hover:text-emerald-600 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                                    title="Bearbeiten">
                                                ✏️
                                            </button>
                                            <form method="post" class="inline" onsubmit="return confirm('Medikament wirklich aus dem Plan entfernen?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_medication">
                                                <input type="hidden" name="med_id" value="<?= (int)$m['id'] ?>">
                                                <button type="submit" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer" title="Löschen">
                                                    🗑️
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 3: ARZTBESUCHE & PROTOKOLLE                                        -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'visits'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        🗓️ Arzttermine &amp; Sprechstunden-Protokolle
                    </h2>
                    <p class="text-xs text-slate-500">
                        Vorbereitung (Fragen-Checkliste vor dem Termin) &bull; Besprechungsergebnisse &bull; Diagnosen (ICD-10)
                    </p>
                </div>
                <button type="button" onclick="openVisitModal(0)"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                    ➕ Neuen Termin / Besuch erfassen
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if (empty($doctorVisits)): ?>
                    <div class="col-span-full mc-card p-8 text-center text-slate-400 italic">
                        Noch keine Arzttermine dokumentiert. Klicke auf „➕ Neuen Termin erfassen“.
                    </div>
                <?php else: ?>
                    <?php foreach ($doctorVisits as $v):
                        $isPast = strtotime($v['visit_date']) < strtotime(date('Y-m-d'));
                    ?>
                        <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-3 relative hover:shadow-md transition">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300">
                                        <?= e($v['specialty']) ?>
                                    </span>
                                    <h3 class="text-base font-extrabold text-slate-900 dark:text-white mt-1"><?= e($v['doctor_name']) ?></h3>
                                    <div class="text-xs text-slate-500 font-medium">
                                        📅 <?= date('d.m.Y', strtotime($v['visit_date'])) ?> <?= !empty($v['visit_time']) ? ('• 🕒 ' . e($v['visit_time']) . ' Uhr') : '' ?>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $v['status'] === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : ($isPast ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 animate-pulse') ?>">
                                    <?= e($v['status'] === 'completed' ? 'Erledigt' : ($isPast ? 'Vergangen' : 'Geplant')) ?>
                                </span>
                            </div>

                            <!-- VORBEREITUNG (FRAGEN VOR DEM TERMIN) -->
                            <?php if (!empty($v['questions_before'])): ?>
                                <div class="p-3 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-xs">
                                    <div class="font-bold text-amber-900 dark:text-amber-200 flex items-center gap-1 mb-1">
                                        📝 Fragen an den Arzt (Vorbereitung):
                                    </div>
                                    <div class="text-slate-700 dark:text-slate-300 whitespace-pre-line"><?= e($v['questions_before']) ?></div>
                                </div>
                            <?php endif; ?>

                            <!-- BESPRECHUNGSERGEBNIS & DIAGNOSE -->
                            <?php if (!empty($v['results_summary']) || !empty($v['diagnosis_icd'])): ?>
                                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 text-xs space-y-1">
                                    <?php if (!empty($v['diagnosis_icd'])): ?>
                                        <div class="font-bold text-slate-900 dark:text-white">
                                            🩺 Diagnose: <span class="text-teal-600 dark:text-teal-400 font-mono"><?= e($v['diagnosis_icd']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($v['results_summary'])): ?>
                                        <div class="text-slate-600 dark:text-slate-300 whitespace-pre-line"><?= e($v['results_summary']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($v['next_checkup_date'])): ?>
                                <div class="text-[11px] text-slate-500 flex items-center gap-1">
                                    🔁 Nächste empfohlene Kontrolle: <span class="font-bold text-slate-700 dark:text-slate-300"><?= date('d.m.Y', strtotime($v['next_checkup_date'])) ?></span>
                                </div>
                            <?php endif; ?>

                            <!-- AKTIONEN -->
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                <button type="button" onclick="editVisit(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)"
                                        class="text-xs text-slate-500 hover:text-emerald-600 font-semibold cursor-pointer">
                                    ✏️ Bearbeiten
                                </button>
                                <form method="post" class="inline" onsubmit="return confirm('Termin wirklich löschen?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_doctor_visit">
                                    <input type="hidden" name="visit_id" value="<?= (int)$v['id'] ?>">
                                    <button type="submit" class="text-xs text-slate-500 hover:text-rose-600 font-semibold cursor-pointer">
                                        🗑️ Löschen
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 4: LABORWERTE & BLUTBILD-TRENDS                                    -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'lab'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        🧪 Laborwerte &amp; Blutbild-Langzeittrends
                    </h2>
                    <p class="text-xs text-slate-500">
                        Erfasse Werte wie Cholesterin, HbA1c, TSH oder Vitamin D mit automatischer Ampel &bull; Werte über Jahre vergleichen
                    </p>
                </div>
                <button type="button" onclick="openLabModal()"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                    ➕ Laborwert eintragen
                </button>
            </div>

            <!-- SCHNELL-ÜBERSICHT GÄNGIGER PARAMETER -->
            <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-4">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Kürzlich gemessene Laborwerte:</h3>
                <?php if (empty($labValues)): ?>
                    <p class="text-xs text-slate-400 italic">Noch keine Laborwerte eingetragen. Wähle „➕ Laborwert eintragen“.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach (array_slice($labValues, 0, 9) as $lv):
                            $assessClass = match ($lv['assessment']) {
                                'high' => 'bg-rose-50 border-rose-300 text-rose-800 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-200',
                                'low'  => 'bg-amber-50 border-amber-300 text-amber-800 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200',
                                default => 'bg-emerald-50 border-emerald-300 text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-200',
                            };
                            $assessBadge = match ($lv['assessment']) {
                                'high' => '⚠️ Erhöht',
                                'low'  => '⚠️ Erniedrigt',
                                default => '✓ Normal',
                            };
                        ?>
                            <div class="p-4 rounded-2xl border <?= $assessClass ?> relative flex flex-col justify-between">
                                <div>
                                    <div class="flex items-center justify-between gap-1 mb-1">
                                        <span class="text-xs font-extrabold truncate"><?= e($lv['parameter_name']) ?></span>
                                        <span class="text-[10px] font-bold px-1.5 py-0.2 rounded-md bg-white/60 dark:bg-black/40"><?= $assessBadge ?></span>
                                    </div>
                                    <div class="text-xl font-mono font-black my-1">
                                        <?= (float)$lv['value_numeric'] ?> <span class="text-xs font-normal"><?= e($lv['unit']) ?></span>
                                    </div>
                                    <?php if ($lv['norm_min'] !== null || $lv['norm_max'] !== null): ?>
                                        <div class="text-[10px] opacity-80 font-mono">
                                            Normbereich: <?= $lv['norm_min'] !== null ? (float)$lv['norm_min'] : '0' ?> &ndash; <?= $lv['norm_max'] !== null ? (float)$lv['norm_max'] : '&infin;' ?> <?= e($lv['unit']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between text-[10px] opacity-75 mt-3 pt-2 border-t border-black/10 dark:border-white/10">
                                    <span>📅 <?= date('d.m.Y', strtotime($lv['measured_date'])) ?></span>
                                    <form method="post" class="inline" onsubmit="return confirm('Messwert löschen?')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_lab_value">
                                        <input type="hidden" name="lab_id" value="<?= (int)$lv['id'] ?>">
                                        <button type="submit" class="hover:text-rose-600 font-bold cursor-pointer">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 5: BEFUNDE, ARZTBRIEFE & FACHARZT-DOSSIER                          -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'docs'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        📁 Befund- &amp; Dokumenten-Archiv
                    </h2>
                    <p class="text-xs text-slate-500">
                        Arztbriefe, OP-Berichte, Röntgen- &amp; Laborbefunde &bull; Erstelle mit 1 Klick ein zusammenfassendes Facharzt-Dossier
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openLivingWillModal()"
                            class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        📜 Patientenverfügung
                    </button>
                    <button type="button" onclick="openDossierModal()"
                            class="px-3.5 py-2 rounded-xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        📄 1-Klick-Facharzt-Dossier
                    </button>
                    <button type="button" onclick="openDocUploadModal()"
                            class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        📤 Befund hochladen
                    </button>
                </div>
            </div>

            <!-- DOKUMENTEN-LISTE -->
            <div class="mc-card overflow-hidden rounded-3xl border border-slate-200 dark:border-slate-800">
                <div class="p-4 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between text-xs text-slate-500">
                    <span>Archivierte Dokumente für <?= e($activeProfile['name'] ?? 'Patient') ?> (<?= count($documents) ?>)</span>
                    <span class="text-[11px]">Dateien werden sicher im Cloud-Ordner <code>Gesundheit/</code> gespeichert</span>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    <?php if (empty($documents)): ?>
                        <div class="p-8 text-center text-slate-400 italic">
                            Noch keine medizinischen Dokumente abgelegt. Klicke auf „📤 Befund hochladen“.
                        </div>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <div class="p-4 flex items-center justify-between gap-3 hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-2xl shrink-0">
                                        <?= e(match ($doc['category']) {
                                            'doctor_letter' => '✉️',
                                            'hospital' => '🏥',
                                            'surgery' => '🩹',
                                            'lab_report' => '🧪',
                                            'radiology' => '🩻',
                                            'dental' => '🦷',
                                            'living_will' => '📜',
                                            default => '📄'
                                        }) ?>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="font-bold text-slate-900 dark:text-white truncate text-sm">
                                            <?= e($doc['title']) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-500 flex items-center gap-2 mt-0.5">
                                            <span>📅 <?= date('d.m.Y', strtotime($doc['document_date'])) ?></span>
                                            <?php if (!empty($doc['issuer'])): ?>
                                                <span>&bull; 🏥 <?= e($doc['issuer']) ?></span>
                                            <?php endif; ?>
                                            <span>&bull; 📎 <?= e($doc['original_name']) ?> (<?= formatFileSize((int)$doc['file_size']) ?>)</span>
                                        </div>
                                        <?php if (!empty($doc['summary'])): ?>
                                            <p class="text-[11px] text-slate-600 dark:text-slate-300 mt-1 italic"><?= e($doc['summary']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <a href="download.php?id=<?= (int)$doc['file_id'] ?>" target="_blank"
                                       class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1 transition">
                                        👁️ Öffnen
                                    </a>
                                    <form method="post" class="inline" onsubmit="return confirm('Befund wirklich aus der Akte löschen?')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                        <button type="submit" class="p-1.5 rounded-xl text-slate-400 hover:text-rose-600 cursor-pointer" title="Löschen">
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 6: IMPFPASS & VORSORGE-BONUSHEFT                                   -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'vaccines'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        💉 Digitaler Impfpass &amp; Vorsorge-Bonusheft
                    </h2>
                    <p class="text-xs text-slate-500">
                        Automatische STIKO-Auffrischungsfristen (Tetanus, FSME, Grippe) &bull; Zahnarzt-Bonusheft für Zahnersatz-Zuschüsse
                    </p>
                </div>
                <button type="button" onclick="openVaccineModal()"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                    ➕ Impfung oder Vorsorge eintragen
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- SPALTE 1: IMPFPASS (STIKO) -->
                <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2">
                        <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-1.5">
                            💉 Impfungen &amp; Schutzstatus
                        </h3>
                        <span class="text-xs text-slate-400"><?= count($vaccines) ?> Impfungen</span>
                    </div>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($vaccines)): ?>
                            <p class="py-6 text-center text-slate-400 italic">Noch keine Impfungen erfasst.</p>
                        <?php else: ?>
                            <?php foreach ($vaccines as $vac):
                                $isDue = !empty($vac['next_due_date']) && strtotime($vac['next_due_date']) <= (time() + 86400 * 90);
                            ?>
                                <div class="py-3 flex items-center justify-between gap-2">
                                    <div>
                                        <div class="font-extrabold text-slate-900 dark:text-white"><?= e($vac['name']) ?></div>
                                        <div class="text-[11px] text-slate-500">
                                            Geimpft am: <?= date('d.m.Y', strtotime($vac['administered_date'])) ?>
                                            <?php if (!empty($vac['batch_number'])): ?>
                                                &bull; Ch.-B.: <span class="font-mono"><?= e($vac['batch_number']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <?php if (!empty($vac['next_due_date'])): ?>
                                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $isDue ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 font-bold' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' ?>">
                                                <?= $isDue ? '⚠️ Auffrischung: ' : 'Fällig: ' ?><?= date('d.m.Y', strtotime($vac['next_due_date'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SPALTE 2: ZAHNARZT- & KREBSVORSORGE-BONUSHEFT -->
                <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2">
                        <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-1.5">
                            🦷 Zahnarzt- &amp; Vorsorge-Bonusheft
                        </h3>
                        <span class="text-xs text-slate-400"><?= count($checkups) ?> Nachweise</span>
                    </div>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($checkups)): ?>
                            <p class="py-6 text-center text-slate-400 italic">Noch keine Vorsorgenachweise eingetragen.</p>
                        <?php else: ?>
                            <?php foreach ($checkups as $chk): ?>
                                <div class="py-3 flex items-center justify-between gap-2">
                                    <div>
                                        <div class="font-extrabold text-slate-900 dark:text-white">
                                            <?= e($chk['name']) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-500">
                                            Untersuchungsdatum: <?= date('d.m.Y', strtotime($chk['administered_date'])) ?>
                                            <?php if (!empty($chk['doctor_clinic'])): ?>
                                                &bull; Praxis: <?= e($chk['doctor_clinic']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 font-bold text-[10px] flex items-center gap-1">
                                        ✓ Stempel erfasst
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 7: VITALWERTE & SCHMERZTAGEBUCH (WETTER-KORRELATION)                -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'vitals'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        📈 Vitalwerte &amp; Schmerztagebuch
                    </h2>
                    <p class="text-xs text-slate-500">
                        Blutdruck (WHO-Klassifikation), Ruhepuls, Blutzucker &bull; Symptom- &amp; Schmerztagebuch mit Wetter-Korrelation
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openSymptomModal()"
                            class="px-3.5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        ⚡ Symptom / Schmerz erfassen
                    </button>
                    <button type="button" onclick="openVitalModal()"
                            class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                        ➕ Vitalwert messen
                    </button>
                </div>
            </div>

            <!-- VITAL-KACHELN (LETZTE MESSUNG) -->
            <?php if ($latestVital): ?>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <!-- Blutdruck -->
                    <div class="mc-card p-4 rounded-2xl border border-slate-200 dark:border-slate-800 text-center">
                        <span class="text-xs text-slate-500 font-semibold">Blutdruck</span>
                        <div class="text-xl font-mono font-black text-slate-900 dark:text-white my-1">
                            <?= $latestVital['systolic'] ? (int)$latestVital['systolic'] : '--' ?> / <?= $latestVital['diastolic'] ? (int)$latestVital['diastolic'] : '--' ?>
                        </div>
                        <span class="text-[10px] text-slate-400">mmHg</span>
                    </div>

                    <!-- Puls -->
                    <div class="mc-card p-4 rounded-2xl border border-slate-200 dark:border-slate-800 text-center">
                        <span class="text-xs text-slate-500 font-semibold">Ruhepuls</span>
                        <div class="text-xl font-mono font-black text-rose-600 dark:text-rose-400 my-1">
                            <?= $latestVital['pulse'] ? (int)$latestVital['pulse'] : '--' ?> <span class="text-xs font-normal">bpm</span>
                        </div>
                        <span class="text-[10px] text-slate-400">Schläge / Min.</span>
                    </div>

                    <!-- Gewicht -->
                    <div class="mc-card p-4 rounded-2xl border border-slate-200 dark:border-slate-800 text-center">
                        <span class="text-xs text-slate-500 font-semibold">Gewicht</span>
                        <div class="text-xl font-mono font-black text-slate-900 dark:text-white my-1">
                            <?= $latestVital['weight_kg'] ? (float)$latestVital['weight_kg'] : '--' ?> <span class="text-xs font-normal">kg</span>
                        </div>
                        <span class="text-[10px] text-slate-400">Körpergewicht</span>
                    </div>

                    <!-- Blutzucker / Temp -->
                    <div class="mc-card p-4 rounded-2xl border border-slate-200 dark:border-slate-800 text-center">
                        <span class="text-xs text-slate-500 font-semibold">Blutzucker / Temp.</span>
                        <div class="text-xl font-mono font-black text-teal-600 dark:text-teal-400 my-1">
                            <?= $latestVital['blood_sugar'] ? ((float)$latestVital['blood_sugar'] . ' mg') : ($latestVital['temperature_c'] ? ((float)$latestVital['temperature_c'] . ' °C') : '--') ?>
                        </div>
                        <span class="text-[10px] text-slate-400">Glukose / Fieber</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- HISTORIE DER VITALWERTE & SYMPTOME -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- TABELLE DER VITALWERTE -->
                <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-3">
                    <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">Blutdruck- &amp; Vital-Historie</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-400 text-[10px] uppercase border-b border-slate-100 dark:border-slate-800">
                                <tr>
                                    <th class="py-2">Datum &amp; Zeit</th>
                                    <th class="py-2 text-center">Blutdruck</th>
                                    <th class="py-2 text-center">Puls</th>
                                    <th class="py-2 text-right">Gewicht</th>
                                    <th class="py-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800 font-mono">
                                <?php if (empty($vitals)): ?>
                                    <tr><td colspan="5" class="py-6 text-center text-slate-400 italic">Noch keine Messungen erfasst.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($vitals, 0, 10) as $vit):
                                        $sys = (int)($vit['systolic'] ?? 0);
                                        $whoCls = $sys >= 140 ? 'text-rose-600 font-bold' : ($sys >= 130 ? 'text-amber-600' : 'text-emerald-600');
                                    ?>
                                        <tr>
                                            <td class="py-2 text-slate-600 dark:text-slate-400 font-sans text-[11px]">
                                                <?= date('d.m. H:i', strtotime($vit['recorded_at'])) ?>
                                            </td>
                                            <td class="py-2 text-center <?= $whoCls ?>">
                                                <?= $vit['systolic'] ?: '--' ?> / <?= $vit['diastolic'] ?: '--' ?>
                                            </td>
                                            <td class="py-2 text-center text-slate-700 dark:text-slate-300">
                                                <?= $vit['pulse'] ?: '--' ?>
                                            </td>
                                            <td class="py-2 text-right text-slate-700 dark:text-slate-300">
                                                <?= $vit['weight_kg'] ? ((float)$vit['weight_kg'] . ' kg') : '--' ?>
                                            </td>
                                            <td class="py-2 text-right font-sans">
                                                <form method="post" class="inline" onsubmit="return confirm('Messung löschen?')">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete_vital">
                                                    <input type="hidden" name="vital_id" value="<?= (int)$vit['id'] ?>">
                                                    <button type="submit" class="text-slate-400 hover:text-rose-600 cursor-pointer">🗑️</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SYMPTOM- & SCHMERZTAGEBUCH MIT WETTER-KORRELATION -->
                <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-3">
                    <h3 class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-1.5">
                        ⚡ Symptom-Tagebuch (mit Wetter-Korrelation)
                    </h3>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($symptoms)): ?>
                            <p class="py-6 text-center text-slate-400 italic">Keine Symptome dokumentiert.</p>
                        <?php else: ?>
                            <?php foreach ($symptoms as $sym): ?>
                                <div class="py-3 space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="font-extrabold text-slate-900 dark:text-white text-sm"><?= e($sym['symptom_name']) ?></span>
                                        <span class="px-2 py-0.5 rounded-full font-mono text-[10px] font-bold <?= (int)$sym['intensity'] >= 7 ? 'bg-rose-600 text-white' : ((int)$sym['intensity'] >= 4 ? 'bg-amber-500 text-slate-950' : 'bg-emerald-500 text-slate-950') ?>">
                                            Stärke: <?= (int)$sym['intensity'] ?>/10
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 flex items-center gap-2">
                                        <span>📅 <?= date('d.m.Y H:i', strtotime($sym['started_at'])) ?></span>
                                        <?php if (!empty($sym['duration_hours'])): ?>
                                            <span>&bull; Dauer: <?= (float)$sym['duration_hours'] ?>h</span>
                                        <?php endif; ?>
                                        <?php if (!empty($sym['weather_condition'])): ?>
                                            <span class="text-teal-600 dark:text-teal-400 font-medium">&bull; ⛅ <?= e($sym['weather_condition']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($sym['triggers'])): ?>
                                        <div class="text-[11px] text-slate-600 dark:text-slate-300">
                                            <span class="font-bold">Auslöser:</span> <?= e($sym['triggers']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($sym['treatment'])): ?>
                                        <div class="text-[11px] text-emerald-700 dark:text-emerald-400">
                                            <span class="font-bold">Behandlung/Linderung:</span> <?= e($sym['treatment']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- TAB 8: BRILLEN- & SEHSCHÄRFE-PASS                                      -->
    <!-- ====================================================================== -->
    <?php if ($activeTab === 'vision'): ?>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        👓 Brillen- &amp; Sehschärfe-Pass
                    </h2>
                    <p class="text-xs text-slate-500">
                        Dokumentation deiner Dioptrien (Sphäre, Zylinder, Achse, Addition) &bull; Sehstärkenentwicklung im Überblick
                    </p>
                </div>
                <button type="button" onclick="openVisionModal()"
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md transition flex items-center gap-1.5 cursor-pointer">
                    ➕ Neuen Brillenpass eintragen
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if (empty($visionPasses)): ?>
                    <div class="col-span-full mc-card p-8 text-center text-slate-400 italic">
                        Noch kein Brillen- oder Kontaktlinsenpass hinterlegt. Klicke auf „➕ Neuen Brillenpass eintragen“.
                    </div>
                <?php else: ?>
                    <?php foreach ($visionPasses as $vp): ?>
                        <div class="mc-card p-5 rounded-3xl border border-slate-200 dark:border-slate-800 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                        <?= e(match ($vp['type']) {
                                            'glasses' => '👓 Fern-/Alltagsbrille',
                                            'reading' => '📖 Lesebrille',
                                            'contacts' => '👁️ Kontaktlinsen',
                                            'sunglasses' => '🕶️ Sonnenbrille mit Stärke',
                                            default => $vp['type']
                                        }) ?>
                                    </span>
                                    <div class="text-xs text-slate-500 mt-1">
                                        Ausgestellt am <?= date('d.m.Y', strtotime($vp['pass_date'])) ?>
                                        <?= !empty($vp['optician_doctor']) ? ('• ' . e($vp['optician_doctor'])) : '' ?>
                                    </div>
                                </div>
                                <form method="post" onsubmit="return confirm('Brillenpass löschen?')">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_vision_pass">
                                    <input type="hidden" name="pass_id" value="<?= (int)$vp['id'] ?>">
                                    <button type="submit" class="text-slate-400 hover:text-rose-600 text-xs cursor-pointer">🗑️</button>
                                </form>
                            </div>

                            <!-- WERTE-TABELLE: RECHTS & LINKS -->
                            <div class="grid grid-cols-2 gap-2 text-center text-xs font-mono">
                                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                                    <div class="font-sans font-bold text-slate-800 dark:text-slate-200 mb-1">Rechtes Auge (R)</div>
                                    <div class="text-sm font-extrabold text-indigo-600 dark:text-indigo-400">Sph: <?= (float)$vp['right_sph'] > 0 ? ('+' . (float)$vp['right_sph']) : (float)$vp['right_sph'] ?> dpt</div>
                                    <div class="text-[11px] text-slate-500">Cyl: <?= (float)$vp['right_cyl'] ?> &bull; A: <?= (int)$vp['right_axis'] ?>°</div>
                                    <?php if ((float)$vp['right_add'] > 0): ?>
                                        <div class="text-[10px] text-slate-400 mt-0.5">Add: +<?= (float)$vp['right_add'] ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                                    <div class="font-sans font-bold text-slate-800 dark:text-slate-200 mb-1">Linkes Auge (L)</div>
                                    <div class="text-sm font-extrabold text-indigo-600 dark:text-indigo-400">Sph: <?= (float)$vp['left_sph'] > 0 ? ('+' . (float)$vp['left_sph']) : (float)$vp['left_sph'] ?> dpt</div>
                                    <div class="text-[11px] text-slate-500">Cyl: <?= (float)$vp['left_cyl'] ?> &bull; A: <?= (int)$vp['left_axis'] ?>°</div>
                                    <?php if ((float)$vp['left_add'] > 0): ?>
                                        <div class="text-[10px] text-slate-400 mt-0.5">Add: +<?= (float)$vp['left_add'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ((float)$vp['pupil_distance'] > 0): ?>
                                <div class="text-center text-xs text-slate-500 font-mono">
                                    Pupillendistanz (PD): <span class="font-bold text-slate-800 dark:text-slate-200"><?= (float)$vp['pupil_distance'] ?> mm</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ========================================================================== -->
<!-- MODAL: PROFIL BEARBEITEN / NEUES PROFIL ANLEGEN                            -->
<!-- ========================================================================== -->
<div id="modalHealthProfile" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 id="profileModalTitle" class="text-lg font-bold text-slate-900 dark:text-white">👤 Gesundheitsprofil bearbeiten</h3>
            <button type="button" onclick="closeProfileModal()" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="profile_id" id="profInputId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Name / Vorname *</label>
                    <input type="text" name="name" id="profInputName" required class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Rolle / Beziehung</label>
                    <select name="relation" id="profInputRelation" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="self">Ich (Hauptnutzer)</option>
                        <option value="partner">Partner / Ehepartner/in</option>
                        <option value="child">Kind</option>
                        <option value="parent">Elternteil</option>
                        <option value="pet">Haustier (Hund/Katze)</option>
                        <option value="other">Angehörige/r / Sonstiges</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Geburtsdatum</label>
                    <input type="date" name="birth_date" id="profInputBirth" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Blutgruppe &amp; Rhesusfaktor</label>
                    <div class="flex gap-2">
                        <select name="blood_type" id="profInputBlood" class="w-1/2 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                            <option value="">Unbekannt</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="AB">AB</option>
                            <option value="0">0</option>
                        </select>
                        <select name="rhesus_factor" id="profInputRhesus" class="w-1/2 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                            <option value="">Unbekannt</option>
                            <option value="+">Positiv (+)</option>
                            <option value="-">Negativ (-)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Krankenkasse</label>
                    <input type="text" name="insurance_name" id="profInputInsName" placeholder="z. B. Techniker Krankenkasse" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Versichertennummer</label>
                    <input type="text" name="insurance_number" id="profInputInsNum" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div class="sm:col-span-2">
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Hausarzt / Behandelnde Praxis</label>
                    <input type="text" name="primary_doctor" id="profInputDoc" placeholder="Dr. Med. Muster, Tel. 0123/45678" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <!-- ALLERGIEN & CHRONISCHES -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs pt-2 border-t border-slate-200 dark:border-slate-700">
                <div>
                    <label class="block font-semibold mb-1 text-rose-700 dark:text-rose-400">Allergien (1 pro Zeile)</label>
                    <textarea name="allergies_text" id="profInputAllergies" rows="3" placeholder="Penicillin&#10;Wespengift&#10;Erdnüsse" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800"></textarea>
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Chronische Diagnosen (1 pro Zeile)</label>
                    <textarea name="conditions_text" id="profInputConditions" rows="3" placeholder="Asthma bronchiale&#10;Bluthochdruck&#10;Diabetes Typ 2" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800"></textarea>
                </div>
            </div>

            <!-- NOTFALLKONTAKT (ICE) -->
            <div class="text-xs pt-2 border-t border-slate-200 dark:border-slate-700 space-y-2">
                <span class="block font-semibold text-slate-700 dark:text-slate-300">📞 Notfallkontakt (ICE):</span>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input type="text" name="ice_name" id="profInputIceName" placeholder="Name (z.B. Anna Mustermann)" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <input type="text" name="ice_phone" id="profInputIcePhone" placeholder="Telefonnummer" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <input type="text" name="ice_rel" id="profInputIceRel" placeholder="Beziehung (z.B. Ehefrau)" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <!-- RECHTLICHES / VORSORGE -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs pt-2 border-t border-slate-200 dark:border-slate-700">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Implantate</label>
                    <input type="text" name="implants" id="profInputImplants" placeholder="z.B. Herzschrittmacher" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Organspendestatus</label>
                    <select name="organ_donor" id="profInputOrgan" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="unspecified">Nicht hinterlegt</option>
                        <option value="yes">Ja (Spender)</option>
                        <option value="no">Nein (Keine Spende)</option>
                        <option value="decision_card">Siehe Spenderausweis</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Patientenverfügung</label>
                    <div class="flex items-center gap-2 mt-2">
                        <input type="checkbox" name="has_living_will" id="profInputWill" value="1" class="w-4 h-4 rounded text-emerald-600">
                        <label for="profInputWill" class="text-xs">Liegt vor</label>
                    </div>
                </div>
                <div class="sm:col-span-3">
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-300">Aufbewahrungsort der Verfügung</label>
                    <input type="text" name="living_will_location" id="profInputWillLoc" placeholder="z. B. Ordner Vorsorge im Arbeitszimmer Regal 2" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" id="btnDeleteProfile" onclick="submitDeleteProfile()" class="hidden px-3 py-2 rounded-xl bg-rose-100 text-rose-700 hover:bg-rose-200 text-xs font-bold cursor-pointer">
                    🗑️ Profil löschen
                </button>
                <div class="flex gap-2 ml-auto">
                    <button type="button" onclick="closeProfileModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-semibold">
                        Abbrechen
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md cursor-pointer">
                        💾 Profil speichern
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<form id="formDeleteProfile" method="post" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <input type="hidden" name="action" value="delete_profile">
    <input type="hidden" name="profile_id" id="deleteProfileId" value="0">
</form>

<!-- ========================================================================== -->
<!-- MODAL: MEDIKAMENT HINZUFÜGEN / BEARBEITEN                                  -->
<!-- ========================================================================== -->
<div id="modalMedication" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 id="medModalTitle" class="text-base font-bold text-slate-900 dark:text-white">💊 Medikament eintragen</h3>
            <button type="button" onclick="document.getElementById('modalMedication').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_medication">
            <input type="hidden" name="med_id" id="medInputId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block font-semibold mb-1">Medikamenten-Name *</label>
                    <input type="text" name="name" id="medInputName" required placeholder="z. B. Ramipril 5mg" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Wirkstoff</label>
                    <input type="text" name="active_substance" id="medInputActive" placeholder="z. B. Ramipril" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Darreichungsform</label>
                    <select name="form" id="medInputForm" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="tablets">Tabletten</option>
                        <option value="capsules">Kapseln</option>
                        <option value="drops">Tropfen</option>
                        <option value="spray">Spray</option>
                        <option value="ointment">Salbe / Creme</option>
                        <option value="injection">Injektion / Spritze</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1">Stärke</label>
                    <input type="text" name="strength" id="medInputStrength" placeholder="z. B. 5 mg" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Grund / Diagnose</label>
                    <input type="text" name="reason" id="medInputReason" placeholder="z. B. Bluthochdruck" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <!-- Einnahmeschema -->
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-700">
                <span class="block font-semibold mb-2">Einnahmeschema (Dosierung):</span>
                <div class="grid grid-cols-4 gap-2 text-center">
                    <div>
                        <label class="text-[10px] text-slate-500 font-bold block mb-1">Morgens</label>
                        <input type="text" name="dosage_morning" id="medInputMorn" value="1" class="w-full text-center py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-bold block mb-1">Mittags</label>
                        <input type="text" name="dosage_noon" id="medInputNoon" value="0" class="w-full text-center py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-bold block mb-1">Abends</label>
                        <input type="text" name="dosage_evening" id="medInputEve" value="0" class="w-full text-center py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-bold block mb-1">Zur Nacht</label>
                        <input type="text" name="dosage_night" id="medInputNight" value="0" class="w-full text-center py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold">
                    </div>
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1">Einnahmehinweis</label>
                <input type="text" name="intake_instructions" id="medInputInstr" placeholder="z. B. vor dem Essen mit reichlich Wasser" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
            </div>

            <!-- Vorrat & Verordnender Arzt -->
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="block font-semibold mb-1">Vorrat (Stück)</label>
                    <input type="number" name="stock_units" id="medInputStock" value="50" min="0" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Tägl. Dosis</label>
                    <input type="number" step="0.1" name="daily_consumption" id="medInputConsump" value="1.0" min="0.1" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Warnung bei (Tage)</label>
                    <input type="number" name="package_refill_threshold" id="medInputThresh" value="7" min="1" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalMedication').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: ARZTTERMIN ANLEGEN / PROTOKOLLIEREN                                 -->
<!-- ========================================================================== -->
<div id="modalDoctorVisit" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 id="visitModalTitle" class="text-base font-bold text-slate-900 dark:text-white">🗓️ Arzttermin erfassen</h3>
            <button type="button" onclick="document.getElementById('modalDoctorVisit').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_doctor_visit">
            <input type="hidden" name="visit_id" id="visitInputId" value="0">

            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Arzt / Praxisname *</label>
                    <input type="text" name="doctor_name" id="visitInputDoc" required placeholder="z. B. Dr. Schmidt / Kardiologische Praxis" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Fachrichtung</label>
                    <input type="text" name="specialty" id="visitInputSpec" placeholder="z.B. Hausarzt, Kardiologie, Orthopädie" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Status</label>
                    <select name="status" id="visitInputStatus" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="planned">Geplant / Bevorstehend</option>
                        <option value="completed">Abgeschlossen / Protokolliert</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1">Datum *</label>
                    <input type="date" name="visit_date" id="visitInputDate" required value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Uhrzeit</label>
                    <input type="time" name="visit_time" id="visitInputTime" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1 text-amber-700 dark:text-amber-400">📝 Fragen an den Arzt (Vorbereitung für die Praxis):</label>
                <textarea name="questions_before" id="visitInputQuestions" rows="3" placeholder="Welche Fragen möchte ich stellen? Welche Beschwerden habe ich beobachtet?" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800"></textarea>
            </div>

            <div>
                <label class="block font-semibold mb-1">Besprechungsergebnis &amp; ärztliche Anweisungen:</label>
                <textarea name="results_summary" id="visitInputResults" rows="3" placeholder="Was hat der Arzt empfohlen? Neue Medikamente? Schonung?" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Diagnose / ICD-10 Code</label>
                    <input type="text" name="diagnosis_icd" id="visitInputIcd" placeholder="z. B. I10 Essentielle Hypertonie" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Nächste empfohlene Kontrolle</label>
                    <input type="date" name="next_checkup_date" id="visitInputNext" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalDoctorVisit').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: LABORWERT EINTRAGEN                                                 -->
<!-- ========================================================================== -->
<div id="modalLabValue" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">🧪 Laborwert erfassen</h3>
            <button type="button" onclick="document.getElementById('modalLabValue').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_lab_value">

            <div>
                <label class="block font-semibold mb-1">Labor-Parameter Vorlage</label>
                <select id="labPresetSelect" onchange="applyLabPreset(this.value)" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <option value="">-- Eigener Messwert --</option>
                    <?php foreach ($COMMON_LAB_PARAMS as $k => $info): ?>
                        <option value="<?= e($k) ?>" data-name="<?= e($info['name']) ?>" data-unit="<?= e($info['unit']) ?>" data-min="<?= $info['min'] ?>" data-max="<?= $info['max'] ?>">
                            <?= e($info['name']) ?> (<?= e($info['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Parameter-Name *</label>
                    <input type="text" name="parameter_name" id="labInputName" required placeholder="z.B. LDL-Cholesterin" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <input type="hidden" name="parameter_key" id="labInputKey" value="custom">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Messwert *</label>
                    <input type="text" name="value_numeric" id="labInputVal" required placeholder="z. B. 125.5" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono font-bold">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Einheit</label>
                    <input type="text" name="unit" id="labInputUnit" value="mg/dl" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Normbereich Min</label>
                    <input type="text" name="norm_min" id="labInputMin" placeholder="z. B. 70" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Normbereich Max</label>
                    <input type="text" name="norm_max" id="labInputMax" placeholder="z. B. 200" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Messdatum</label>
                    <input type="date" name="measured_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalLabValue').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: BEFUND / DOKUMENT HOCHLADEN                                         -->
<!-- ========================================================================== -->
<div id="modalDocUpload" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">📤 Befund oder Arztbrief hochladen</h3>
            <button type="button" onclick="document.getElementById('modalDocUpload').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="upload_document">

            <div>
                <label class="block font-semibold mb-1">Datei auswählen (PDF, Bild, Scan) *</label>
                <input type="file" name="doc_file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.odt"
                       class="w-full text-slate-700 dark:text-slate-200 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-emerald-600 file:text-white hover:file:bg-emerald-700">
            </div>

            <div>
                <label class="block font-semibold mb-1">Dokumenten-Titel</label>
                <input type="text" name="title" placeholder="z. B. MRT Knie links Befund" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Kategorie</label>
                    <select name="category" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="doctor_letter">Arztbrief</option>
                        <option value="hospital">Krankenhaus / Entlassung</option>
                        <option value="surgery">OP-Bericht</option>
                        <option value="lab_report">Labor- / Blutbefund</option>
                        <option value="radiology">Röntgen / MRT / CT</option>
                        <option value="dental">Zahnarzt</option>
                        <option value="living_will">Patientenverfügung / Vorsorge</option>
                        <option value="other">Sonstiges Dokument</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1">Dokumentendatum</label>
                    <input type="date" name="document_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Aussteller / Klinik</label>
                    <input type="text" name="issuer" placeholder="z. B. Radiologie Zentrum Stadtmitte" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1">Kurze Zusammenfassung / Kernaussage</label>
                <textarea name="summary" rows="2" placeholder="Wichtige Kernaussagen des Befunds für schnelles Wiederfinden..." class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalDocUpload').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">📤 Jetzt hochladen</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: IMPFUNG ODER VORSORGE EINTRAGEN                                     -->
<!-- ========================================================================== -->
<div id="modalVaccine" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">💉 Impfung oder Vorsorge eintragen</h3>
            <button type="button" onclick="document.getElementById('modalVaccine').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_vaccine">

            <div>
                <label class="block font-semibold mb-1">Art des Eintrags</label>
                <select name="type" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <option value="vaccine">💉 Schutzimpfung (STIKO)</option>
                    <option value="dental_checkup">🦷 Zahnärztliche Kontrolluntersuchung (Bonusheft)</option>
                    <option value="cancer_screening">🩺 Krebsfrüherkennung / Hautkrebs-Screening</option>
                    <option value="checkup_35">🩺 Check-up 35 / Gesundheitsuntersuchung</option>
                    <option value="eye_exam">👁️ Augenärztliche Vorsorge</option>
                </select>
            </div>

            <div>
                <label class="block font-semibold mb-1">Bezeichnung *</label>
                <input type="text" name="name" required placeholder="z. B. Tetanus-Diphtherie-Pertussis-Polio oder Zahn-Check 2025" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Datum *</label>
                    <input type="date" name="administered_date" required value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Chargennummer (Charge)</label>
                    <input type="text" name="batch_number" placeholder="z. B. AB12345" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Praxis / Arzt</label>
                    <input type="text" name="doctor_clinic" placeholder="Praxis Dr. Schmidt" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Nächste Fälligkeit</label>
                    <input type="date" name="next_due_date" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalVaccine').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: VITALWERT (BLUTDRUCK, PULS, GEWICHT)                                -->
<!-- ========================================================================== -->
<div id="modalVital" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">📈 Vitalwert messen</h3>
            <button type="button" onclick="document.getElementById('modalVital').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_vital">

            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-700">
                <span class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">Blutdruck &amp; Puls:</span>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="text-[10px] text-slate-500 block mb-1">Systolisch (oberer)</label>
                        <input type="number" name="systolic" placeholder="120" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 block mb-1">Diastolisch (unterer)</label>
                        <input type="number" name="diastolic" placeholder="80" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 block mb-1">Puls (bpm)</label>
                        <input type="number" name="pulse" placeholder="70" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 font-mono font-bold text-center text-rose-600">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Gewicht (kg)</label>
                    <input type="text" name="weight_kg" placeholder="z. B. 75.5" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Blutzucker (mg/dl)</label>
                    <input type="text" name="blood_sugar" placeholder="z. B. 95" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Messzeitpunkt</label>
                    <input type="datetime-local" name="recorded_at" value="<?= date('Y-m-d\TH:i') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalVital').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: SYMPTOM- & SCHMERZTAGEBUCH (MIT WETTER-KORRELATION)                 -->
<!-- ========================================================================== -->
<div id="modalSymptom" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-md p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">⚡ Symptom / Schmerz erfassen</h3>
            <button type="button" onclick="document.getElementById('modalSymptom').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_symptom">

            <div>
                <label class="block font-semibold mb-1">Symptom / Beschwerde *</label>
                <input type="text" name="symptom_name" required placeholder="z. B. Migräne, LWS-Rückenschmerzen, Übelkeit" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="font-semibold">Schmerz- / Belastungsskala (1-10):</label>
                    <span id="symptomIntensityLabel" class="font-mono font-bold text-amber-600">5 / 10</span>
                </div>
                <input type="range" name="intensity" min="1" max="10" value="5" oninput="document.getElementById('symptomIntensityLabel').textContent = this.value + ' / 10'" class="w-full accent-amber-500 cursor-pointer">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Beginn / Zeitpunkt</label>
                    <input type="datetime-local" name="started_at" value="<?= date('Y-m-d\TH:i') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div>
                    <label class="block font-semibold mb-1">Dauer (in Stunden)</label>
                    <input type="number" step="0.5" name="duration_hours" placeholder="z. B. 3.5" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Vermutliche Auslöser (Trigger)</label>
                    <input type="text" name="triggers" placeholder="z. B. Schlafmangel, Wetterwechsel, Bildschirmarbeit, Stress" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Wetterlage zum Zeitpunkt (Wetter-Korrelation)</label>
                    <input type="text" name="weather_condition" placeholder="z. B. Luftdruckabfall, Föhn, Regen, Schwül" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Behandlung / Gegenmaßnahme</label>
                    <input type="text" name="treatment" placeholder="z. B. Ibuprofen 400mg, Spaziergang an frischer Luft" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalSymptom').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: BRILLENPASS EINTRAGEN                                               -->
<!-- ========================================================================== -->
<div id="modalVisionPass" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">👓 Brillenpass erfassen</h3>
            <button type="button" onclick="document.getElementById('modalVisionPass').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>
        <form method="post" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_vision_pass">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1">Brillen- / Linsentyp</label>
                    <select name="type" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <option value="glasses">Fern- / Alltagsbrille</option>
                        <option value="reading">Lesebrille</option>
                        <option value="contacts">Kontaktlinsen</option>
                        <option value="sunglasses">Sonnenbrille mit Stärke</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold mb-1">Ausstellungsdatum</label>
                    <input type="date" name="pass_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
                <div class="col-span-2">
                    <label class="block font-semibold mb-1">Optiker / Augenarzt</label>
                    <input type="text" name="optician_doctor" placeholder="z. B. Fielmann Filiale Musterstadt" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800">
                </div>
            </div>

            <!-- RECHTES AUGE -->
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-700">
                <span class="block font-bold mb-1.5 text-indigo-700 dark:text-indigo-400">Rechtes Auge (R):</span>
                <div class="grid grid-cols-4 gap-2 font-mono">
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Sphäre (Sph)</label>
                        <input type="text" name="right_sph" placeholder="-1.50" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Zylinder (Cyl)</label>
                        <input type="text" name="right_cyl" placeholder="-0.50" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Achse (A)</label>
                        <input type="number" name="right_axis" placeholder="90" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Addition (Add)</label>
                        <input type="text" name="right_add" placeholder="+1.50" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                </div>
            </div>

            <!-- LINKES AUGE -->
            <div class="p-3 bg-slate-50 dark:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-700">
                <span class="block font-bold mb-1.5 text-indigo-700 dark:text-indigo-400">Linkes Auge (L):</span>
                <div class="grid grid-cols-4 gap-2 font-mono">
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Sphäre (Sph)</label>
                        <input type="text" name="left_sph" placeholder="-1.75" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Zylinder (Cyl)</label>
                        <input type="text" name="left_cyl" placeholder="-0.50" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Achse (A)</label>
                        <input type="number" name="left_axis" placeholder="85" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                    <div>
                        <label class="text-[10px] text-slate-500 font-sans block mb-1">Addition (Add)</label>
                        <input type="text" name="left_add" placeholder="+1.50" class="w-full px-2 py-1.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-center">
                    </div>
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1">Pupillendistanz (PD in mm)</label>
                <input type="text" name="pupil_distance" placeholder="z. B. 62.5" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono">
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalVisionPass').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: FACHARZT-DOSSIER GENERATOR                                          -->
<!-- ========================================================================== -->
<div id="modalDossier" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-xl p-6 space-y-4 max-h-[90vh] overflow-y-auto rounded-3xl shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                📄 1-Klick-Facharzt-Dossier (Druck &amp; Überweisung)
            </h3>
            <button type="button" onclick="document.getElementById('modalDossier').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <p class="text-xs text-slate-600 dark:text-slate-300">
            Wähle aus, welche Diagnosen, Medikationspläne und Befunde in die Druckzusammenstellung für deinen behandelnden Facharzt aufgenommen werden sollen:
        </p>

        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 space-y-3 text-xs">
            <label class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" id="dossierIncProfile" checked class="w-4 h-4 rounded text-teal-600">
                Stammdaten &amp; Allergien / Vorerkrankungen Deckblatt
            </label>
            <label class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" id="dossierIncMeds" checked class="w-4 h-4 rounded text-teal-600">
                Aktueller Bundesmedikationsplan (BMP)
            </label>
            <label class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" id="dossierIncVisits" checked class="w-4 h-4 rounded text-teal-600">
                Bisherige Diagnosen &amp; ICD-10 Codes der letzten Termine
            </label>
            <label class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" id="dossierIncLab" checked class="w-4 h-4 rounded text-teal-600">
                Neueste Labor- &amp; Blutwerte mit Normbereich-Vergleich
            </label>
        </div>

        <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
            <button type="button" onclick="document.getElementById('modalDossier').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">Abbrechen</button>
            <button type="button" onclick="generateDossierPrint()" class="px-4 py-2 rounded-xl bg-teal-600 hover:bg-teal-500 text-white font-bold text-xs shadow-md cursor-pointer flex items-center gap-1.5">
                🖨️ Dossier öffnen &amp; drucken
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: PATIENTENVERFÜGUNGS-GENERATOR & BMJ-VORLAGE (§ 1901a BGB)           -->
<!-- ========================================================================== -->
<div id="modalLivingWill" class="hidden fixed inset-0 z-50 bg-black/70 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
    <div class="mc-card w-full max-w-6xl p-5 sm:p-6 space-y-4 max-h-[95vh] overflow-y-auto rounded-3xl shadow-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <div>
                <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    📜 Patientenverfügung nach BMJ-Muster (§ 1901a BGB)
                </h3>
                <p class="text-xs text-slate-500">
                    Rechtssichere Vorsorgeerklärung mit individuellen Behandlungsfestlegungen, Druckansicht &amp; Cloud-Archivierung
                </p>
            </div>
            <button type="button" onclick="document.getElementById('modalLivingWill').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl font-bold p-1">✕</button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 text-xs">
            <!-- LINKE SPALTE: EINSTELLUNGEN & WEICHENSTELLUNGEN -->
            <div class="lg:col-span-5 space-y-4 max-h-[70vh] overflow-y-auto pr-1">
                <!-- 1. PERSONALIEN -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                    <p class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span>👤</span> 1. Angaben zum/zur Verfügenden
                    </p>
                    <div>
                        <label class="block font-semibold mb-0.5 text-slate-700 dark:text-slate-300">Vollständiger Name *</label>
                        <input type="text" id="lwInputName" oninput="updateLivingWillPreview()" value="<?= e($activeProfile['name'] ?? '') ?>" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 font-bold">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block font-semibold mb-0.5 text-slate-700 dark:text-slate-300">Geburtsdatum</label>
                            <input type="text" id="lwInputBirthDate" oninput="updateLivingWillPreview()" value="<?= !empty($activeProfile['birth_date']) ? date('d.m.Y', strtotime($activeProfile['birth_date'])) : '' ?>" placeholder="TT.MM.JJJJ" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900">
                        </div>
                        <div>
                            <label class="block font-semibold mb-0.5 text-slate-700 dark:text-slate-300">Wohnort / Anschrift</label>
                            <input type="text" id="lwInputAddress" oninput="updateLivingWillPreview()" placeholder="Musterstadt" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900">
                        </div>
                    </div>
                    <div>
                        <label class="block font-semibold mb-0.5 text-slate-700 dark:text-slate-300">Hausarzt / Behandelnder Arzt</label>
                        <input type="text" id="lwInputDoc" oninput="updateLivingWillPreview()" value="<?= e($activeProfile['primary_doctor'] ?? '') ?>" placeholder="z. B. Dr. med. Schmidt" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900">
                    </div>
                </div>

                <!-- 2. GELTUNGSBEREICH (SITUATIONEN) -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <p class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span>⚡</span> 2. Situationen, in denen die Verfügung gilt
                    </p>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptSterben" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Unmittelbarer Sterbeprozess:</strong> Wenn ich mich unabwendbar im unmittelbaren Sterben befinde.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptEndstadium" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Endstadium unheilbarer Krankheit:</strong> Tödlicher Verlauf, selbst wenn Tod erst in Wochen/Monaten eintritt.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptHirn" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Schwerster Dauerschaden des Gehirns:</strong> Dauerhafter Bewusstseinsverlust / Wachkoma (apallisches Syndrom).</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptDemenz" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Fortgeschrittener Hirnabbau:</strong> Schwere Demenz mit Unfähigkeit zur natürlichen Nahrungsaufnahme.</span>
                    </label>
                </div>

                <!-- 3. BEHANDLUNGSWÜNSCHE & ANORDNUNGEN -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <p class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span>🏥</span> 3. Medizinische Festlegungen &amp; Anordnungen
                    </p>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptLifeSupport" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Lebenserhaltende Maßnahmen:</strong> Keine künstliche Verlängerung des Sterbeprozesses.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptPalliative" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Palliative Schmerztherapie:</strong> Volle Schmerzlinderung &amp; Sedierung, auch wenn als Nebenwirkung die Lebenszeit verkürzt wird.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptBeatmung" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Künstliche Beatmung:</strong> Ablehnung invasiver Dauerbeatmung (Intubation); Beendigung begonnener Beatmung.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptErnaehrung" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Künstliche Ernährung / PEG:</strong> Keine Magensonde; Flüssigkeitsgabe nur zur Linderung von Mundtrockenheit &amp; Durst.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptDnr" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Wiederbelebung (DNR):</strong> Verzicht auf Reanimationsversuche bei Herz- oder Atemstillstand.</span>
                    </label>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="lwOptHospiz" onchange="updateLivingWillPreview()" checked class="mt-0.5 rounded text-indigo-600">
                        <span><strong>Sterbeort:</strong> Wenn möglich Sterben in vertrauter häuslicher Umgebung oder Hospiz statt Intensivstation.</span>
                    </label>
                </div>

                <!-- 4. ORGANSPENDE -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <p class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span>🫀</span> 4. Verhältnis zur Organspende
                    </p>
                    <div class="space-y-1.5">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="lwOrganChoice" value="card" onchange="updateLivingWillPreview()" checked class="text-indigo-600">
                            <span>Es gelten die Festlegungen in meinem gesonderten Organspendeausweis.</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="lwOrganChoice" value="yes" onchange="updateLivingWillPreview()" class="text-indigo-600">
                            <span>Organspende befürwortet (kurzzeitige Organerhaltung bis Hirntod gestattet).</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="lwOrganChoice" value="no" onchange="updateLivingWillPreview()" class="text-indigo-600">
                            <span>Organspende ausdrücklich abgelehnt.</span>
                        </label>
                    </div>
                </div>

                <!-- 5. BEVOLLMÄCHTIGTE VERTRAUENSPERSONEN -->
                <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                    <p class="font-bold text-slate-900 dark:text-white uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                        <span>🤝</span> 5. Bevollmächtigte / Vertrauensperson(en)
                    </p>
                    <textarea id="lwInputProxy" oninput="updateLivingWillPreview()" rows="2" class="w-full px-3 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs" placeholder="Name, Telefonnummer, Anschrift der bevollmächtigten Vertrauensperson"><?php
                    if (!empty($emergencyList)) {
                        $pLines = [];
                        foreach ($emergencyList as $c) {
                            $pLines[] = trim(($c['name'] ?? '') . (!empty($c['rel']) ? ' (' . $c['rel'] . ')' : '') . (!empty($c['phone']) ? ', Tel: ' . $c['phone'] : ''));
                        }
                        echo e(implode("\n", $pLines));
                    }
                    ?></textarea>
                </div>
            </div>

            <!-- RECHTE SPALTE: LIVE-VORSCHAU DES DOKUMENTS -->
            <div class="lg:col-span-7 flex flex-col justify-between space-y-3">
                <div class="flex items-center justify-between">
                    <p class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                        <span>📜</span> Dokument-Vorschau (DIN-A4 Druckansicht)
                    </p>
                    <span class="text-[11px] text-slate-500">§ 1901a BGB konform</span>
                </div>

                <div id="livingWillPreviewBox" class="p-6 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 shadow-inner font-serif text-[11px] sm:text-xs leading-relaxed max-h-[60vh] overflow-y-auto space-y-3 select-text text-slate-800 dark:text-slate-200">
                    <!-- Wird dynamisch durch updateLivingWillPreview() befüllt -->
                </div>

                <!-- AKTIONEN -->
                <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-200 dark:border-slate-700">
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="copyLivingWillText()" id="btnCopyLw" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-800 dark:text-slate-200 font-semibold text-xs transition flex items-center gap-1.5 cursor-pointer">
                            📋 In Zwischenablage
                        </button>
                        <button type="button" onclick="saveLivingWillToArchive()" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer">
                            📁 In Patientenakte ablegen
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="document.getElementById('modalLivingWill').classList.add('hidden')" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">
                            Schließen
                        </button>
                        <button type="button" onclick="printLivingWillDoc()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer">
                            🖨️ DIN-A4 Drucken / PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Verstecktes Formular zum automatischen Archivieren der Patientenverfügung -->
<form method="post" id="formSaveLivingWill" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
    <input type="hidden" name="action" value="save_living_will_doc">
    <input type="hidden" name="living_will_text" id="inputLivingWillText" value="">
    <input type="hidden" name="living_will_location" id="inputLivingWillLocation" value="Gespeichert in MiniCloud-Patientenakte (ausgedruckt bei Unterlagen)">
    <input type="hidden" name="mark_active" value="1">
</form>

<!-- Druck-Container für sauberes DIN-A4 Drucken -->
<div id="printableLivingWillDoc" class="hidden"></div>

<!-- ========================================================================== -->
<!-- JAVASCRIPT-LOGIK FÜR MODALS, EDITING & PRESETS                             -->
<!-- ========================================================================== -->
<script>
const ACTIVE_PROFILE_DATA = <?= json_encode($activeProfile, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function openProfileModal(profId) {
    const modal = document.getElementById('modalHealthProfile');
    const title = document.getElementById('profileModalTitle');
    const delBtn = document.getElementById('btnDeleteProfile');
    if (!modal) return;

    if (profId > 0 && ACTIVE_PROFILE_DATA && ACTIVE_PROFILE_DATA.id == profId) {
        title.textContent = '👤 Profil „' + (ACTIVE_PROFILE_DATA.name || '') + '“ bearbeiten';
        document.getElementById('profInputId').value        = ACTIVE_PROFILE_DATA.id;
        document.getElementById('profInputName').value      = ACTIVE_PROFILE_DATA.name || '';
        document.getElementById('profInputRelation').value  = ACTIVE_PROFILE_DATA.relation || 'self';
        document.getElementById('profInputBirth').value     = ACTIVE_PROFILE_DATA.birth_date || '';
        document.getElementById('profInputBlood').value     = ACTIVE_PROFILE_DATA.blood_type || '';
        document.getElementById('profInputRhesus').value    = ACTIVE_PROFILE_DATA.rhesus_factor || '';
        document.getElementById('profInputInsName').value   = ACTIVE_PROFILE_DATA.insurance_name || '';
        document.getElementById('profInputInsNum').value    = ACTIVE_PROFILE_DATA.insurance_number || '';
        document.getElementById('profInputDoc').value       = ACTIVE_PROFILE_DATA.primary_doctor || '';
        document.getElementById('profInputImplants').value  = ACTIVE_PROFILE_DATA.implants || '';
        document.getElementById('profInputOrgan').value     = ACTIVE_PROFILE_DATA.organ_donor || 'unspecified';
        document.getElementById('profInputWill').checked    = ACTIVE_PROFILE_DATA.has_living_will == 1;
        document.getElementById('profInputWillLoc').value   = ACTIVE_PROFILE_DATA.living_will_location || '';

        try {
            const alg = JSON.parse(ACTIVE_PROFILE_DATA.allergies_json || '[]');
            document.getElementById('profInputAllergies').value = Array.isArray(alg) ? alg.join("\n") : '';
        } catch(e) { document.getElementById('profInputAllergies').value = ''; }

        try {
            const cnd = JSON.parse(ACTIVE_PROFILE_DATA.conditions_json || '[]');
            document.getElementById('profInputConditions').value = Array.isArray(cnd) ? cnd.join("\n") : '';
        } catch(e) { document.getElementById('profInputConditions').value = ''; }

        try {
            const ice = JSON.parse(ACTIVE_PROFILE_DATA.emergency_contacts_json || '[]');
            if (Array.isArray(ice) && ice.length > 0) {
                document.getElementById('profInputIceName').value  = ice[0].name || '';
                document.getElementById('profInputIcePhone').value = ice[0].phone || '';
                document.getElementById('profInputIceRel').value   = ice[0].rel || '';
            }
        } catch(e) {}

        if (delBtn) delBtn.classList.remove('hidden');
    } else {
        title.textContent = '➕ Neues Familienprofil anlegen';
        document.getElementById('profInputId').value = '0';
        document.getElementById('profInputName').value = '';
        document.getElementById('profInputRelation').value = 'child';
        document.getElementById('profInputBirth').value = '';
        document.getElementById('profInputBlood').value = '';
        document.getElementById('profInputRhesus').value = '';
        document.getElementById('profInputAllergies').value = '';
        document.getElementById('profInputConditions').value = '';
        document.getElementById('profInputIceName').value = '';
        document.getElementById('profInputIcePhone').value = '';
        document.getElementById('profInputIceRel').value = '';
        if (delBtn) delBtn.classList.add('hidden');
    }
    modal.classList.remove('hidden');
}

function closeProfileModal() {
    const modal = document.getElementById('modalHealthProfile');
    if (modal) modal.classList.add('hidden');
}

function submitDeleteProfile() {
    if (!confirm('Dieses Profil und ALLE zugehörigen Einträge (Medikamente, Befunde, Vitalwerte) unwiderruflich löschen?')) return;
    document.getElementById('deleteProfileId').value = document.getElementById('profInputId').value;
    document.getElementById('formDeleteProfile').submit();
}

function openMedModal(medId) {
    document.getElementById('medInputId').value = '0';
    document.getElementById('medModalTitle').textContent = '💊 Medikament hinzufügen';
    document.getElementById('medInputName').value = '';
    document.getElementById('medInputActive').value = '';
    document.getElementById('medInputStrength').value = '';
    document.getElementById('medInputReason').value = '';
    document.getElementById('medInputInstr').value = '';
    document.getElementById('modalMedication').classList.remove('hidden');
}

function editMed(med) {
    if (!med) return;
    document.getElementById('medInputId').value = med.id;
    document.getElementById('medModalTitle').textContent = '💊 Medikament bearbeiten';
    document.getElementById('medInputName').value = med.name || '';
    document.getElementById('medInputActive').value = med.active_substance || '';
    document.getElementById('medInputForm').value = med.form || 'tablets';
    document.getElementById('medInputStrength').value = med.strength || '';
    document.getElementById('medInputMorn').value = med.dosage_morning || '0';
    document.getElementById('medInputNoon').value = med.dosage_noon || '0';
    document.getElementById('medInputEve').value = med.dosage_evening || '0';
    document.getElementById('medInputNight').value = med.dosage_night || '0';
    document.getElementById('medInputInstr').value = med.intake_instructions || '';
    document.getElementById('medInputReason').value = med.reason || '';
    document.getElementById('medInputStock').value = med.stock_units || 0;
    document.getElementById('medInputConsump').value = med.daily_consumption || 1.0;
    document.getElementById('medInputThresh').value = med.package_refill_threshold || 7;
    document.getElementById('modalMedication').classList.remove('hidden');
}

function openVisitModal(visitId) {
    document.getElementById('visitInputId').value = '0';
    document.getElementById('visitModalTitle').textContent = '🗓️ Arzttermin erfassen';
    document.getElementById('visitInputDoc').value = '';
    document.getElementById('visitInputSpec').value = 'Hausarzt';
    document.getElementById('visitInputQuestions').value = '';
    document.getElementById('visitInputResults').value = '';
    document.getElementById('visitInputIcd').value = '';
    document.getElementById('modalDoctorVisit').classList.remove('hidden');
}

function editVisit(visit) {
    if (!visit) return;
    document.getElementById('visitInputId').value = visit.id;
    document.getElementById('visitModalTitle').textContent = '🗓️ Arzttermin bearbeiten';
    document.getElementById('visitInputDoc').value = visit.doctor_name || '';
    document.getElementById('visitInputSpec').value = visit.specialty || '';
    document.getElementById('visitInputDate').value = visit.visit_date || '';
    document.getElementById('visitInputTime').value = visit.visit_time || '';
    document.getElementById('visitInputStatus').value = visit.status || 'planned';
    document.getElementById('visitInputQuestions').value = visit.questions_before || '';
    document.getElementById('visitInputResults').value = visit.results_summary || '';
    document.getElementById('visitInputIcd').value = visit.diagnosis_icd || '';
    document.getElementById('visitInputNext').value = visit.next_checkup_date || '';
    document.getElementById('modalDoctorVisit').classList.remove('hidden');
}

function openLabModal() {
    document.getElementById('modalLabValue').classList.remove('hidden');
}

function applyLabPreset(paramKey) {
    const sel = document.getElementById('labPresetSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !paramKey) return;
    document.getElementById('labInputKey').value  = paramKey;
    document.getElementById('labInputName').value = opt.getAttribute('data-name') || '';
    document.getElementById('labInputUnit').value = opt.getAttribute('data-unit') || '';
    document.getElementById('labInputMin').value  = opt.getAttribute('data-min') || '';
    document.getElementById('labInputMax').value  = opt.getAttribute('data-max') || '';
}

function openDocUploadModal() {
    document.getElementById('modalDocUpload').classList.remove('hidden');
}

function openVaccineModal() {
    document.getElementById('modalVaccine').classList.remove('hidden');
}

function openVitalModal() {
    document.getElementById('modalVital').classList.remove('hidden');
}

function openSymptomModal() {
    document.getElementById('modalSymptom').classList.remove('hidden');
}

function openVisionModal() {
    document.getElementById('modalVisionPass').classList.remove('hidden');
}

function openDossierModal() {
    document.getElementById('modalDossier').classList.remove('hidden');
}

function generateDossierPrint() {
    document.getElementById('modalDossier').classList.add('hidden');
    window.print();
}

// =============================================================================
// PATIENTENVERFÜGUNG (VORLAGE, GENERATOR, DIN-A4 DRUCK & ARCHIVIERUNG)
// =============================================================================
function openLivingWillModal() {
    updateLivingWillPreview();
    document.getElementById('modalLivingWill').classList.remove('hidden');
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getLivingWillGeneratedData() {
    const name    = (document.getElementById('lwInputName')?.value || '').trim() || 'Vorname Nachname';
    const bdate   = (document.getElementById('lwInputBirthDate')?.value || '').trim();
    const address = (document.getElementById('lwInputAddress')?.value || '').trim();
    const doc     = (document.getElementById('lwInputDoc')?.value || '').trim();
    const proxy   = (document.getElementById('lwInputProxy')?.value || '').trim();

    const optSterben     = document.getElementById('lwOptSterben')?.checked ?? true;
    const optEndstadium  = document.getElementById('lwOptEndstadium')?.checked ?? true;
    const optHirn        = document.getElementById('lwOptHirn')?.checked ?? true;
    const optDemenz      = document.getElementById('lwOptDemenz')?.checked ?? true;

    const optLifeSupport = document.getElementById('lwOptLifeSupport')?.checked ?? true;
    const optPalliative  = document.getElementById('lwOptPalliative')?.checked ?? true;
    const optBeatmung    = document.getElementById('lwOptBeatmung')?.checked ?? true;
    const optErnaehrung  = document.getElementById('lwOptErnaehrung')?.checked ?? true;
    const optDnr         = document.getElementById('lwOptDnr')?.checked ?? true;
    const optHospiz      = document.getElementById('lwOptHospiz')?.checked ?? true;

    const organChoiceEl = document.querySelector('input[name="lwOrganChoice"]:checked');
    const organChoice   = organChoiceEl ? organChoiceEl.value : 'card';

    // Generiere reinen Text für Zwischenablage und Dateiablage
    let txt = `PATIENTENVERFÜGUNG\ngemäß § 1901a Abs. 1 BGB\n\n`;
    txt += `Ich, ${name}`;
    if (bdate) txt += `, geboren am ${bdate}`;
    if (address) txt += `, wohnhaft in ${address}`;
    txt += `,\n`;
    txt += `treffe hiermit für den Fall, dass ich meinen Willen infolge von Bewusstlosigkeit, Hirnschädigung, Krankheit oder Schwäche nicht mehr bilden oder äußern kann, folgende unmittelbar verbindliche Anordnungen:\n\n`;

    txt += `1. SITUATIONEN, FÜR DIE DIESE VERFÜGUNG GILT:\n`;
    let sitCount = 0;
    if (optSterben) {
        sitCount++;
        txt += `(${sitCount}) Wenn ich mich aller Wahrscheinlichkeit nach unabwendbar im unmittelbaren Sterbeprozess befinde.\n`;
    }
    if (optEndstadium) {
        sitCount++;
        txt += `(${sitCount}) Wenn ich mich im Endstadium einer unheilbaren, tödlich verlaufenden Krankheit befinde, selbst wenn der Eintritt des Todes noch Wochen oder Monate dauern sollte.\n`;
    }
    if (optHirn) {
        sitCount++;
        txt += `(${sitCount}) Wenn infolge einer schwersten Gehirnschädigung (z. B. nach Reanimation, Schlaganfall, Hirnblutung oder Schädel-Hirn-Trauma) meine Fähigkeit, Einsichten zu gewinnen und Entscheidungen zu treffen, aller Voraussicht nach dauerhaft erloschen ist (Wachkoma / apallisches Syndrom).\n`;
    }
    if (optDemenz) {
        sitCount++;
        txt += `(${sitCount}) Wenn ich infolge eines fortgeschrittenen Hirnabbauprozesses (z. B. bei schwerer Demenzerkrankung) nicht mehr in der Lage bin, Nahrung und Flüssigkeit auf natürlichem Wege zu mir zu nehmen.\n`;
    }
    if (sitCount === 0) {
        txt += `(Für alle medizinisch aussichtslosen Situationen mit irreversiblem Bewusstseinsverlust).\n`;
    }
    txt += `\n`;

    txt += `2. KONKRETE BEHANDLUNGSENTSCHEIDUNGEN:\n`;
    txt += `In den vorstehend beschriebenen Situationen verfüge ich verbindlich:\n`;
    if (optLifeSupport) {
        txt += `• Lebenserhaltende Maßnahmen: Ich wünsche keine künstliche Verlängerung meines Lebens, wenn dadurch lediglich der Sterbeprozess qualvoll verlängert wird. Medizinische Maßnahmen sind auf die Linderung von Schmerzen, Atemnot, Übelkeit und Angst zu beschränken.\n`;
    }
    if (optPalliative) {
        txt += `• Palliative Schmerz- und Symptombehandlung: Ich verlange eine fachgerechte, lindernde Schmerztherapie. Treten Schmerzen, Erstickungsangst oder schwere Unruhe auf, fordere ich die Gabe hochwirksamer Schmerzmittel und ggf. eine palliative Sedierung – selbst wenn dadurch als unvermeidbare Nebenwirkung mein Todeseintritt beschleunigt werden sollte.\n`;
    }
    if (optBeatmung) {
        txt += `• Künstliche Beatmung: Ich lehne eine invasive künstliche Dauerbeatmung (Intubation, Tracheostoma) ab. Eine bereits eingeleitete Beatmung ist unter den genannten Voraussetzungen schrittweise und schonend zu beenden.\n`;
    }
    if (optErnaehrung) {
        txt += `• Künstliche Ernährung & Flüssigkeitszufuhr: Ich lehne die künstliche Zufuhr von Nahrung und Flüssigkeit über Magensonden (PEG) oder Venenkatheter ab. Flüssigkeit darf nur in dem Umfang verabreicht werden, wie es zur Linderung von Durstgefühl und Mundtrockenheit erforderlich ist.\n`;
    }
    if (optDnr) {
        txt += `• Wiederbelebung (DNR): Kommt es zum Herz- oder Atemstillstand, lehne ich Wiederbelebungsmaßnahmen (Reanimation, Herzdruckmassage, Defibrillation) ausdrücklich ab.\n`;
    }
    if (optHospiz) {
        txt += `• Sterbebegleitung & Ort: Ich wünsche, wenn möglich, in vertrauter häuslicher Umgebung oder in einem Hospiz im Beisein mir nahestehender Menschen zu sterben.\n`;
    }
    txt += `\n`;

    txt += `3. VERHÄLTNIS ZUR ORGANSPENDE:\n`;
    if (organChoice === 'yes') {
        txt += `Ich befürworte eine Organspende. Kurzzeitige intensivmedizinische Maßnahmen, die ausschließlich der Feststellung des Hirntodes und der Erhaltung transplantierbarer Organe dienen, sind ausdrücklich gestattet.\n\n`;
    } else if (organChoice === 'no') {
        txt += `Ich lehne eine Entnahme von Organen oder Geweben nach meinem Tod ausdrücklich ab.\n\n`;
    } else {
        txt += `Hinsichtlich einer Organspende gelten die Festlegungen in meinem gesondert mitgeführten Organspendeausweis.\n\n`;
    }

    if (proxy) {
        txt += `4. DURCHSETZUNG MEINES WILLENS (BEVOLLMÄCHTIGTE):\n`;
        txt += `Zur Durchsetzung meiner Wünsche benenne ich folgende bevollmächtigte Person(en) meines Vertrauens:\n${proxy}\n`;
        txt += `Meine behandelnden Ärzte werden insoweit gegenüber meinen Bevollmächtigten von der ärztlichen Schweigepflicht entbunden (§ 1901a BGB).\n\n`;
    }

    if (doc) {
        txt += `Hausarzt / behandelnde Praxis:\n${doc}\n\n`;
    }

    txt += `5. VERBINDLICHKEIT & RECHTLICHE HINWEISE:\n`;
    txt += `Ich habe diese Patientenverfügung nach reiflicher Überlegung im Vollbesitz meiner geistigen Kräfte und frei von äußerem Zwang verfasst. Sie ist für Ärzte und Pflegekräfte unmittelbar verbindlich (§ 1901a BGB). Sie gilt zeitlich unbegrenzt, solange ich sie nicht widerrufe.\n\n`;

    txt += `Ort, Datum: ________________________\n\n`;
    txt += `Unterschrift: ___________________________________________________\n(${name})\n\n`;
    txt += `(Optional: Bestätigung durch Zeugen oder Arzt)\n`;
    txt += `Ich bestätige, dass der/die Verfügende die Patientenverfügung eigenhändig und im Vollbesitz der geistigen Kräfte unterzeichnet hat:\n\n`;
    txt += `Ort, Datum: ________________________  Unterschrift: _______________________________\n`;

    return {
        text: txt,
        name: name,
        bdate: bdate,
        address: address,
        doc: doc,
        proxy: proxy,
        optSterben, optEndstadium, optHirn, optDemenz,
        optLifeSupport, optPalliative, optBeatmung, optErnaehrung, optDnr, optHospiz,
        organChoice
    };
}

function updateLivingWillPreview() {
    const data = getLivingWillGeneratedData();
    const box = document.getElementById('livingWillPreviewBox');
    if (!box) return;

    box.innerHTML = `
        <div class="text-center border-b border-slate-300 dark:border-slate-700 pb-3 mb-4">
            <h2 class="text-base sm:text-lg font-bold tracking-wider uppercase text-slate-900 dark:text-white">Patientenverfügung</h2>
            <p class="text-[11px] text-slate-500 font-sans">gemäß § 1901a Abs. 1 Bürgerliches Gesetzbuch (BGB)</p>
        </div>

        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-[11px] space-y-0.5">
            <div><strong>Verfügende Person:</strong> ${escapeHtml(data.name)}</div>
            ${data.bdate ? `<div><strong>Geburtsdatum:</strong> ${escapeHtml(data.bdate)}</div>` : ''}
            ${data.address ? `<div><strong>Wohnanschrift:</strong> ${escapeHtml(data.address)}</div>` : ''}
            ${data.doc ? `<div><strong>Hausarzt / Praxis:</strong> ${escapeHtml(data.doc)}</div>` : ''}
        </div>

        <p class="pt-2 text-justify">
            Ich treffe hiermit für den Fall, dass ich meinen Willen infolge von Bewusstlosigkeit, Hirnschädigung, schwerer Krankheit oder Schwäche nicht mehr bilden oder äußern kann, folgende <strong>unmittelbar verbindliche Anordnungen</strong>:
        </p>

        <h3 class="font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-700 pb-1 pt-2">
            1. Situationen, für die diese Verfügung gilt:
        </h3>
        <ul class="list-disc pl-5 space-y-1.5 text-slate-700 dark:text-slate-300">
            ${data.optSterben ? `<li><strong>Unmittelbarer Sterbeprozess:</strong> Wenn ich mich aller Wahrscheinlichkeit nach unabwendbar im unmittelbaren Sterbeprozess befinde.</li>` : ''}
            ${data.optEndstadium ? `<li><strong>Endstadium unheilbarer Krankheit:</strong> Wenn ich mich im Endstadium einer unheilbaren, tödlich verlaufenden Krankheit befinde, selbst wenn der Todeseintritt noch Wochen oder Monate dauern sollte.</li>` : ''}
            ${data.optHirn ? `<li><strong>Schwerste Gehirnschädigung / Wachkoma:</strong> Wenn infolge einer Hirnschädigung meine Fähigkeit, Einsichten zu gewinnen und Entscheidungen zu treffen, aller Voraussicht nach dauerhaft erloschen ist (Wachkoma / apallisches Syndrom).</li>` : ''}
            ${data.optDemenz ? `<li><strong>Fortgeschrittener Hirnabbau:</strong> Wenn ich infolge eines weit fortgeschrittenen Hirnabbauprozesses (z. B. schwerer Demenzerkrankung) nicht mehr in der Lage bin, Nahrung und Flüssigkeit auf natürlichem Wege zu mir zu nehmen.</li>` : ''}
        </ul>

        <h3 class="font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-700 pb-1 pt-2">
            2. Konkrete Behandlungsentscheidungen:
        </h3>
        <p class="italic text-slate-500">In den vorstehend beschriebenen Situationen verfüge ich verbindlich:</p>
        <ul class="list-disc pl-5 space-y-1.5 text-slate-700 dark:text-slate-300">
            ${data.optLifeSupport ? `<li><strong>Lebenserhaltende Maßnahmen:</strong> Ich wünsche keine künstliche Verlängerung meines Lebens, wenn dadurch lediglich das Sterben qualvoll verlängert wird.</li>` : ''}
            ${data.optPalliative ? `<li><strong>Palliative Schmerztherapie:</strong> Ich fordere eine fachgerechte lindernde Schmerztherapie und palliative Sedierung bei Atemnot, Schmerzen oder Angst – auch wenn dadurch als unvermeidbare Nebenwirkung die Lebenszeit verkürzt werden sollte.</li>` : ''}
            ${data.optBeatmung ? `<li><strong>Künstliche Beatmung:</strong> Ich lehne eine invasive Dauerbeatmung ab; eine bereits eingeleitete Beatmung ist unter diesen Voraussetzungen zu beenden.</li>` : ''}
            ${data.optErnaehrung ? `<li><strong>Künstliche Ernährung / PEG:</strong> Ich lehne künstliche Ernährung über Magensonden (PEG) ab. Flüssigkeit darf nur zur Linderung von Mundtrockenheit und Durst verabreicht werden.</li>` : ''}
            ${data.optDnr ? `<li><strong>Wiederbelebung (DNR):</strong> Bei Herz- oder Atemstillstand lehne ich Wiederbelebungsmaßnahmen ausdrücklich ab.</li>` : ''}
            ${data.optHospiz ? `<li><strong>Sterbeort:</strong> Ich wünsche, wenn möglich, in vertrauter häuslicher Umgebung oder im Hospiz im Beisein mir nahestehender Menschen zu sterben.</li>` : ''}
        </ul>

        <h3 class="font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-700 pb-1 pt-2">
            3. Verhältnis zur Organspende:
        </h3>
        <p class="text-slate-700 dark:text-slate-300">
            ${data.organChoice === 'yes'
                ? 'Ich befürworte eine Organspende. Kurzzeitige intensivmedizinische Maßnahmen, die ausschließlich der Organerhaltung und Hirntoddiagnostik dienen, sind gestattet.'
                : (data.organChoice === 'no'
                    ? 'Ich lehne eine Entnahme von Organen und Geweben nach meinem Tod ausdrücklich ab.'
                    : 'Hinsichtlich einer Organspende gelten die Festlegungen in meinem gesondert mitgeführten Organspendeausweis.')}
        </p>

        ${data.proxy ? `
            <h3 class="font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-700 pb-1 pt-2">
                4. Durchsetzung meines Willens (Bevollmächtigte):
            </h3>
            <p class="text-slate-700 dark:text-slate-300">Zur Durchsetzung meiner Wünsche benenne ich folgende bevollmächtigte Person(en):</p>
            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-[11px] whitespace-pre-line font-sans">${escapeHtml(data.proxy)}</div>
            <p class="text-[10px] text-slate-400">Meine behandelnden Ärzte werden insoweit gegenüber meinen Bevollmächtigten von der Schweigepflicht entbunden (§ 1901a BGB).</p>
        ` : ''}

        <h3 class="font-bold text-slate-900 dark:text-white border-b border-slate-200 dark:border-slate-700 pb-1 pt-2">
            5. Verbindlichkeit &amp; Unterschrift:
        </h3>
        <p class="text-slate-700 dark:text-slate-300 text-justify">
            Ich habe diese Verfügung nach reiflicher Überlegung im Vollbesitz meiner geistigen Kräfte verfasst. Sie ist für Ärzte und Betreuer unmittelbar bindend (§ 1901a BGB) und gilt zeitlich unbegrenzt, solange sie nicht widerrufen wird.
        </p>

        <div class="pt-6 grid grid-cols-2 gap-6 text-[10px] font-sans">
            <div class="border-t border-slate-400 dark:border-slate-600 pt-1">
                Ort, Datum
            </div>
            <div class="border-t border-slate-400 dark:border-slate-600 pt-1">
                Unterschrift des/der Verfügenden (${escapeHtml(data.name)})
            </div>
        </div>
    `;
}

function printLivingWillDoc() {
    const data = getLivingWillGeneratedData();
    const printBox = document.getElementById('printableLivingWillDoc');
    if (!printBox) return;

    printBox.innerHTML = `
        <div style="font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.5; color: #000; padding: 10mm 15mm;">
            <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px;">
                <h1 style="font-size: 20pt; font-weight: bold; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: 0.5px;">Patientenverfügung</h1>
                <p style="font-size: 11pt; font-weight: bold; margin: 0; color: #333;">gemäß § 1901a Abs. 1 Bürgerliches Gesetzbuch (BGB)</p>
            </div>

            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 10.5pt;">
                <strong>Verfügende Person:</strong> ${escapeHtml(data.name)}
                ${data.bdate ? `<br><strong>Geburtsdatum:</strong> ${escapeHtml(data.bdate)}` : ''}
                ${data.address ? `<br><strong>Wohnanschrift:</strong> ${escapeHtml(data.address)}` : ''}
                ${data.doc ? `<br><strong>Hausarzt:</strong> ${escapeHtml(data.doc)}` : ''}
            </div>

            <p style="margin-bottom: 16px; font-size: 10.5pt;">
                Ich treffe hiermit für den Fall, dass ich meinen Willen infolge von Bewusstlosigkeit, schwerster Hirnschädigung, Krankheit oder Schwäche nicht mehr bilden oder äußern kann, folgende <strong>unmittelbar verbindliche Anordnungen</strong>:
            </p>

            <h2 style="font-size: 12pt; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 20px; margin-bottom: 8px;">1. Situationen, für die diese Verfügung gilt</h2>
            <ul style="margin: 0 0 16px 20px; padding: 0;">
                ${data.optSterben ? `<li style="margin-bottom: 6px;"><strong>Unmittelbarer Sterbeprozess:</strong> Wenn ich mich aller Wahrscheinlichkeit nach unabwendbar im unmittelbaren Sterbeprozess befinde.</li>` : ''}
                ${data.optEndstadium ? `<li style="margin-bottom: 6px;"><strong>Endstadium unheilbarer Krankheit:</strong> Wenn ich mich im Endstadium einer unheilbaren, tödlich verlaufenden Krankheit befinde, selbst wenn der Eintritt des Todes noch Wochen oder Monate dauern sollte.</li>` : ''}
                ${data.optHirn ? `<li style="margin-bottom: 6px;"><strong>Schwerste Gehirnschädigung / Wachkoma:</strong> Wenn infolge einer Hirnschädigung meine Fähigkeit, Einsichten zu gewinnen und Entscheidungen zu treffen, aller Voraussicht nach dauerhaft erloschen ist (Wachkoma / apallisches Syndrom).</li>` : ''}
                ${data.optDemenz ? `<li style="margin-bottom: 6px;"><strong>Fortgeschrittener Hirnabbau:</strong> Wenn ich infolge eines weit fortgeschrittenen Hirnabbauprozesses (z. B. schwerer Demenz) nicht mehr in der Lage bin, Nahrung und Flüssigkeit auf natürlichem Wege zu mir zu nehmen.</li>` : ''}
            </ul>

            <h2 style="font-size: 12pt; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 20px; margin-bottom: 8px;">2. Konkrete Behandlungsentscheidungen</h2>
            <p style="margin-bottom: 8px;">In den vorstehend beschriebenen Situationen verfüge ich verbindlich:</p>
            <ul style="margin: 0 0 16px 20px; padding: 0;">
                ${data.optLifeSupport ? `<li style="margin-bottom: 6px;"><strong>Lebenserhaltende Maßnahmen:</strong> Ich wünsche keine künstliche Lebensverlängerung, wenn dadurch lediglich das Sterben qualvoll verlängert wird.</li>` : ''}
                ${data.optPalliative ? `<li style="margin-bottom: 6px;"><strong>Palliative Schmerztherapie:</strong> Ich fordere eine fachgerechte lindernde Schmerztherapie und ggf. palliative Sedierung bei Schmerzen, Atemnot oder Erstickungsangst – auch wenn dadurch als unvermeidbare Nebenwirkung mein Todeseintritt beschleunigt werden sollte.</li>` : ''}
                ${data.optBeatmung ? `<li style="margin-bottom: 6px;"><strong>Künstliche Beatmung:</strong> Ich lehne eine invasive Dauerbeatmung (Intubation) ab. Eine bereits eingeleitete Beatmung ist unter diesen Voraussetzungen zu beenden.</li>` : ''}
                ${data.optErnaehrung ? `<li style="margin-bottom: 6px;"><strong>Künstliche Ernährung / PEG:</strong> Ich lehne künstliche Ernährung über Magensonden (PEG) ab. Flüssigkeit darf nur zur Linderung von Mundtrockenheit und Durstgefühl verabreicht werden.</li>` : ''}
                ${data.optDnr ? `<li style="margin-bottom: 6px;"><strong>Wiederbelebung (DNR):</strong> Bei Herz- oder Atemstillstand lehne ich Wiederbelebungsmaßnahmen ausdrücklich ab.</li>` : ''}
                ${data.optHospiz ? `<li style="margin-bottom: 6px;"><strong>Sterbeort:</strong> Ich wünsche, wenn möglich, in häuslicher Umgebung oder im Hospiz im Beisein mir nahestehender Menschen zu sterben.</li>` : ''}
            </ul>

            <h2 style="font-size: 12pt; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 20px; margin-bottom: 8px;">3. Verhältnis zur Organspende</h2>
            <p style="margin-bottom: 16px;">
                ${data.organChoice === 'yes'
                    ? 'Ich befürworte eine Organspende. Kurzzeitige intensivmedizinische Maßnahmen, die ausschließlich der Organerhaltung und Hirntoddiagnostik dienen, sind gestattet.'
                    : (data.organChoice === 'no'
                        ? 'Ich lehne eine Entnahme von Organen und Geweben nach meinem Tod ausdrücklich ab.'
                        : 'Hinsichtlich einer Organspende gelten die Festlegungen in meinem gesondert mitgeführten Organspendeausweis.')}
            </p>

            ${data.proxy ? `
                <h2 style="font-size: 12pt; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 20px; margin-bottom: 8px;">4. Durchsetzung meines Willens (Bevollmächtigte)</h2>
                <p style="margin-bottom: 6px;">Zur Durchsetzung meiner Wünsche benenne ich folgende bevollmächtigte Person(en):</p>
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; margin-bottom: 16px; white-space: pre-line;">${escapeHtml(data.proxy)}</div>
                <p style="font-size: 10pt; color: #444; margin-bottom: 16px;">Meine behandelnden Ärzte werden insoweit gegenüber meinen Bevollmächtigten von der ärztlichen Schweigepflicht entbunden (§ 1901a BGB).</p>
            ` : ''}

            <h2 style="font-size: 12pt; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 20px; margin-bottom: 8px;">5. Verbindlichkeit &amp; Bestätigung</h2>
            <p style="margin-bottom: 30px;">
                Ich habe diese Patientenverfügung nach reiflicher Überlegung im Vollbesitz meiner geistigen Kräfte verfasst. Sie ist für Ärzte und Betreuer unmittelbar bindend (§ 1901a BGB) und gilt zeitlich unbegrenzt, solange sie nicht widerrufen wird.
            </p>

            <div style="display: flex; justify-content: space-between; gap: 40px; margin-top: 40px;">
                <div style="flex: 1; border-top: 1px solid #000; padding-top: 6px;">
                    <div style="font-size: 9.5pt; color: #555;">Ort, Datum</div>
                </div>
                <div style="flex: 2; border-top: 1px solid #000; padding-top: 6px;">
                    <div style="font-size: 9.5pt; color: #555;">Unterschrift des/der Verfügenden (${escapeHtml(data.name)})</div>
                </div>
            </div>

            <div style="margin-top: 35px; padding-top: 15px; border-top: 1px dashed #999; font-size: 9.5pt; color: #444;">
                <p style="margin-bottom: 25px;">
                    <strong>Bestätigung durch Zeugen oder Arzt (optional):</strong><br>
                    Ich bestätige, dass der/die oben genannte Verfügende die Patientenverfügung eigenhändig und im Vollbesitz der geistigen Kräfte unterzeichnet hat.
                </p>
                <div style="display: flex; justify-content: space-between; gap: 40px;">
                    <div style="flex: 1; border-top: 1px solid #666; padding-top: 4px;">
                        <div style="font-size: 9pt; color: #666;">Ort, Datum</div>
                    </div>
                    <div style="flex: 2; border-top: 1px solid #666; padding-top: 4px;">
                        <div style="font-size: 9pt; color: #666;">Unterschrift des Zeugen / Arztes</div>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.classList.add('printing-living-will');
    window.print();
    setTimeout(() => {
        document.body.classList.remove('printing-living-will');
    }, 1200);
}

function copyLivingWillText() {
    const data = getLivingWillGeneratedData();
    navigator.clipboard.writeText(data.text).then(() => {
        const btn = document.getElementById('btnCopyLw');
        if (btn) {
            const old = btn.textContent;
            btn.textContent = '✅ Kopiert!';
            setTimeout(() => btn.textContent = old, 2000);
        }
    }).catch(() => {
        alert('Kopieren nicht unterstützt. Bitte den Text manuell aus der Vorschau markieren.');
    });
}

function saveLivingWillToArchive() {
    const data = getLivingWillGeneratedData();
    document.getElementById('inputLivingWillText').value = data.text;
    document.getElementById('formSaveLivingWill').submit();
}
</script>

<style>
@media print {
    /* Wenn die Patientenverfügung gedruckt wird: nur das saubere DIN-A4 Dokument ausgeben */
    body.printing-living-will > *:not(#printableLivingWillDoc) {
        display: none !important;
    }
    body.printing-living-will #printableLivingWillDoc {
        display: block !important;
        position: static !important;
        background: white !important;
        color: #000 !important;
        box-shadow: none !important;
        border: none !important;
        margin: 0 !important;
    }

    /* Normaler Druck: Navigationsleisten, Kopf- und Fußzeilen ausblenden */
    header, footer, nav, #mcPersistentRadioMiniBar, #mcPersistentMusicMiniBar,
    .overflow-x-auto, button, a[href*="download.php"], #btnDeleteProfile {
        display: none !important;
    }
    body, main, .mc-card {
        background: white !important;
        color: black !important;
        border: 1px solid #ccc !important;
        box-shadow: none !important;
    }
    #printableEmergencyCardArea {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 15px !important;
        page-break-inside: avoid !important;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
