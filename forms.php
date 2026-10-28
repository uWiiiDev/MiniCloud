<?php
/**
 * MiniCloud – Online-Formulare & Veranstaltungs-Anmeldung (forms.php)
 * DSGVO-konformer, selbstgehosteter Formular-Baukasten („Google-Forms / Microsoft-Forms“-Alternative):
 * - 1-Klick-Vorlagen: Veranstaltungs-Anmeldung, Helfer- & Mitbringliste, Online-Mitgliedsantrag, Feedback-Bogen
 * - Eigene Formularfelder (Text, Auswahl/Dropdown, Checkbox, Zahl, Datum, E-Mail, Freitext)
 * - Kapazitätslimit (Max. Plätze mit Wartelisten-Stopp) & Anmeldeschluss-Datum
 * - Öffentlicher Teilnahme-Link (forms.php?token=...) & lokaler SVG-QR-Code für Gäste ohne Cloud-Konto
 * - Live-Auswertungstabelle, CSV-Download, 1-Klick-Speicherung in der Cloud (Formulare/) für Office Light
 *   sowie 1-Klick-Übernahme von Online-Mitgliedsanträgen in die Vereinsverwaltung (club.php)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_forms'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('warning', 'Das Modul „Online-Formulare & Anmeldung“ ist durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// Tabellen selbstheilend sicherstellen
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

$publicToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$isGuestMode = ($publicToken !== '' && !isLoggedIn());

if ($publicToken === '' && !isLoggedIn()) {
    requireLogin();
}

$userId   = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
$username = isLoggedIn() ? (string)($_SESSION['username'] ?? '') : '';

/**
 * Prüft Lese-/Teilnahmezugriff auf ein Formular.
 */
function canAccessForm(PDO $pdo, array $form, int $userId, string $token): bool
{
    if ($token !== '' && hash_equals((string)$form['share_token'], $token)) {
        if ($userId <= 0 && (int)($form['allow_guests'] ?? 1) === 0) {
            return false;
        }
        return true;
    }
    if ($userId <= 0) {
        return false;
    }
    if ((int)$form['user_id'] === $userId || (int)($form['shared_user_id'] ?? 0) === $userId) {
        return true;
    }
    if (!empty($form['group_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => (int)$form['group_id'], ':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

/**
 * Prüft Verwaltungszugriff (Einsendungen einsehen, CSV exportieren, Bearbeiten).
 */
function canManageForm(PDO $pdo, array $form, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if ((int)$form['user_id'] === $userId || (int)($form['shared_user_id'] ?? 0) === $userId) {
        return true;
    }
    if (!empty($form['group_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => (int)$form['group_id'], ':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

/**
 * Dekodiert die Felddefinitionen eines Formulars sicher als Array.
 */
function decodeFormFields(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $clean = [];
    foreach ($decoded as $idx => $f) {
        if (!is_array($f) || empty($f['label'])) {
            continue;
        }
        $clean[] = [
            'key'      => (string)($f['key'] ?? ('f_' . $idx)),
            'label'    => trim((string)$f['label']),
            'type'     => in_array(($f['type'] ?? 'text'), ['text', 'textarea', 'select', 'checkbox', 'number', 'date', 'email'], true) ? $f['type'] : 'text',
            'required' => !empty($f['required']),
            'options'  => isset($f['options']) && is_array($f['options']) ? array_values(array_filter(array_map('trim', $f['options']), static fn($v) => $v !== '')) : [],
        ];
    }
    return $clean;
}

// Direkt-CSV-Download einer Formular-Auswertung
if (isset($_GET['export_csv']) && $userId > 0) {
    $exportFormId = (int)$_GET['export_csv'];
    $stmtF = $pdo->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
    $stmtF->execute([':id' => $exportFormId]);
    $formRow = $stmtF->fetch();

    if ($formRow && canManageForm($pdo, $formRow, $userId)) {
        $fields = decodeFormFields((string)$formRow['fields_json']);
        $stmtS  = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = :fid ORDER BY id ASC');
        $stmtS->execute([':fid' => $exportFormId]);
        $subs = $stmtS->fetchAll();

        $safeFile = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', (string)$formRow['title']) . '_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeFile . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM für Excel

        $out = fopen('php://output', 'wb');
        $headers = ['Nr.', 'Eingereicht am', 'Name', 'E-Mail'];
        foreach ($fields as $fl) {
            $headers[] = $fl['label'];
        }
        fputcsv($out, $headers, ';');

        foreach ($subs as $i => $sub) {
            $ans = json_decode((string)$sub['answers_json'], true);
            if (!is_array($ans)) {
                $ans = [];
            }
            $row = [
                $i + 1,
                date('d.m.Y H:i', strtotime((string)$sub['created_at'])),
                (string)$sub['submitter_name'],
                (string)$sub['submitter_email'],
            ];
            foreach ($fields as $fl) {
                $val = $ans[$fl['key']] ?? ($ans[$fl['label']] ?? '');
                $row[] = is_array($val) ? implode(', ', $val) : (string)$val;
            }
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
}

// POST-Aktionen verarbeiten
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: forms.php' . ($publicToken !== '' ? '?token=' . urlencode($publicToken) : ''));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Formular-Antwort absenden (für Gäste über Token ODER eingeloggte Mitglieder)
    if ($action === 'submit_form') {
        $formId         = (int)($_POST['form_id'] ?? 0);
        $tok            = trim((string)($_POST['share_token'] ?? $publicToken));
        $submitterName  = trim((string)($_POST['submitter_name'] ?? $username));
        $submitterEmail = trim((string)($_POST['submitter_email'] ?? ''));
        $rawAnswers     = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];

        $stmtF = $pdo->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmtF->execute([':id' => $formId]);
        $formRow = $stmtF->fetch();

        $redirectUrl = 'forms.php' . ($tok !== '' && $isGuestMode ? '?token=' . urlencode($tok) : '?form=' . $formId);

        if (!$formRow || !canAccessForm($pdo, $formRow, $userId, $tok)) {
            setFlash('error', 'Formular nicht gefunden oder keine Teilnahmeberechtigung.');
            header('Location: forms.php');
            exit;
        }

        // Prüfen, ob geschlossen oder Frist/Kapazität erreicht
        if ((int)$formRow['is_closed'] === 1) {
            setFlash('warning', 'Dieses Formular ist bereits geschlossen.');
            header('Location: ' . $redirectUrl);
            exit;
        }
        if (!empty($formRow['closes_at']) && (string)$formRow['closes_at'] < date('Y-m-d')) {
            setFlash('warning', 'Die Anmeldefrist für dieses Formular ist abgelaufen.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ((int)$formRow['max_submissions'] > 0) {
            $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM form_submissions WHERE form_id = :fid');
            $stmtCnt->execute([':fid' => $formId]);
            $currentCount = (int)$stmtCnt->fetchColumn();
            if ($currentCount >= (int)$formRow['max_submissions']) {
                setFlash('warning', 'Alle Plätze sind bereits belegt (Maximale Teilnehmerzahl erreicht).');
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        if ($submitterName === '') {
            setFlash('error', 'Bitte gib deinen Namen an.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $fields = decodeFormFields((string)$formRow['fields_json']);
        $cleanAnswers = [];
        foreach ($fields as $fl) {
            $key = $fl['key'];
            $val = $rawAnswers[$key] ?? '';
            if (is_array($val)) {
                $val = implode(', ', array_map('trim', $val));
            } else {
                $val = trim((string)$val);
            }
            if ($fl['type'] === 'checkbox' && $val === '') {
                $val = 'Nein';
            }
            if ($fl['required'] && ($val === '' || ($fl['type'] === 'checkbox' && $val === 'Nein'))) {
                setFlash('error', 'Bitte fülle das Pflichtfeld „' . $fl['label'] . '“ aus.');
                header('Location: ' . $redirectUrl);
                exit;
            }
            $cleanAnswers[$key] = mb_substr($val, 0, 2000);
        }

        $stmtIns = $pdo->prepare(
            'INSERT INTO form_submissions (form_id, user_id, submitter_name, submitter_email, answers_json)
             VALUES (:fid, :uid, :name, :email, :ans)'
        );
        $stmtIns->execute([
            ':fid'   => $formId,
            ':uid'   => $userId > 0 ? $userId : null,
            ':name'  => mb_substr($submitterName, 0, 120),
            ':email' => mb_substr($submitterEmail, 0, 190),
            ':ans'   => json_encode($cleanAnswers, JSON_UNESCAPED_UNICODE),
        ]);

        setFlash('success', 'Vielen Dank! Deine Rückmeldung für „' . $formRow['title'] . '“ wurde erfolgreich gespeichert.');
        header('Location: ' . $redirectUrl . '&submitted=1');
        exit;
    }

    // Alle Verwaltungs-Aktionen erfordern ein eingeloggtes Mitglied
    requireLogin();

    // 2. Neues Formular anlegen
    if ($action === 'create_form') {
        $title          = trim((string)($_POST['title'] ?? ''));
        $description    = trim((string)($_POST['description'] ?? ''));
        $category       = trim((string)($_POST['category'] ?? 'event'));
        $allowGuests    = !empty($_POST['allow_guests']) ? 1 : 0;
        $maxSubmissions = max(0, (int)($_POST['max_submissions'] ?? 0));
        $closesAt       = trim((string)($_POST['closes_at'] ?? ''));
        $groupId        = (int)($_POST['group_id'] ?? 0) ?: null;
        $sharedUserId   = (int)($_POST['shared_user_id'] ?? 0) ?: null;

        $labels   = isset($_POST['field_label']) && is_array($_POST['field_label']) ? $_POST['field_label'] : [];
        $types    = isset($_POST['field_type']) && is_array($_POST['field_type']) ? $_POST['field_type'] : [];
        $reqs     = isset($_POST['field_required']) && is_array($_POST['field_required']) ? $_POST['field_required'] : [];
        $optsRaw  = isset($_POST['field_options']) && is_array($_POST['field_options']) ? $_POST['field_options'] : [];

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Titel für das Formular ein.');
            header('Location: forms.php');
            exit;
        }

        $fields = [];
        foreach ($labels as $idx => $lbl) {
            $lbl = trim((string)$lbl);
            if ($lbl === '') {
                continue;
            }
            $type = (string)($types[$idx] ?? 'text');
            if (!in_array($type, ['text', 'textarea', 'select', 'checkbox', 'number', 'date', 'email'], true)) {
                $type = 'text';
            }
            $opts = [];
            if ($type === 'select' && !empty($optsRaw[$idx])) {
                $opts = array_values(array_filter(array_map('trim', explode(',', (string)$optsRaw[$idx])), static fn($v) => $v !== ''));
            }
            $fields[] = [
                'key'      => 'f_' . ($idx + 1),
                'label'    => mb_substr($lbl, 0, 140),
                'type'     => $type,
                'required' => !empty($reqs[$idx]),
                'options'  => $opts,
            ];
        }

        if (empty($fields)) {
            setFlash('error', 'Bitte lege mindestens ein Formularfeld an.');
            header('Location: forms.php');
            exit;
        }

        $cleanCloses = ($closesAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $closesAt)) ? $closesAt : null;
        $shareToken  = bin2hex(random_bytes(20));

        $stmtIns = $pdo->prepare(
            'INSERT INTO forms
             (user_id, title, description, category, fields_json, share_token, allow_guests, max_submissions, closes_at, shared_user_id, group_id)
             VALUES (:uid, :t, :d, :cat, :fj, :tok, :ag, :maxs, :ca, :su, :gid)'
        );
        $stmtIns->execute([
            ':uid'  => $userId,
            ':t'    => mb_substr($title, 0, 200),
            ':d'    => $description !== '' ? $description : null,
            ':cat'  => in_array($category, ['event', 'helper', 'club_application', 'feedback', 'custom'], true) ? $category : 'event',
            ':fj'   => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':tok'  => $shareToken,
            ':ag'   => $allowGuests,
            ':maxs' => $maxSubmissions,
            ':ca'   => $cleanCloses,
            ':su'   => $sharedUserId,
            ':gid'  => $groupId,
        ]);
        $newFormId = (int)$pdo->lastInsertId();
        setFlash('success', 'Formular „' . $title . '“ wurde erstellt und ist sofort per Link oder QR-Code teilbar!');
        header('Location: forms.php?form=' . $newFormId);
        exit;
    }

    // 3. Formular-Auswertung als CSV direkt in der Cloud (Ordner Formulare/) speichern
    if ($action === 'save_csv_to_cloud') {
        $formId = (int)($_POST['form_id'] ?? 0);
        $stmtF  = $pdo->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmtF->execute([':id' => $formId]);
        $formRow = $stmtF->fetch();

        if ($formRow && canManageForm($pdo, $formRow, $userId)) {
            $fields = decodeFormFields((string)$formRow['fields_json']);
            $stmtS  = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = :fid ORDER BY id ASC');
            $stmtS->execute([':fid' => $formId]);
            $subs = $stmtS->fetchAll();

            $mem = fopen('php://temp', 'r+b');
            fwrite($mem, "\xEF\xBB\xBF");
            $headers = ['Nr.', 'Datum', 'Name', 'E-Mail'];
            foreach ($fields as $fl) {
                $headers[] = $fl['label'];
            }
            fputcsv($mem, $headers, ';');
            foreach ($subs as $i => $sub) {
                $ans = json_decode((string)$sub['answers_json'], true) ?: [];
                $row = [
                    $i + 1,
                    date('d.m.Y H:i', strtotime((string)$sub['created_at'])),
                    (string)$sub['submitter_name'],
                    (string)$sub['submitter_email'],
                ];
                foreach ($fields as $fl) {
                    $val = $ans[$fl['key']] ?? '';
                    $row[] = is_array($val) ? implode(', ', $val) : (string)$val;
                }
                fputcsv($mem, $row, ';');
            }
            rewind($mem);
            $csvContent = (string)stream_get_contents($mem);
            fclose($mem);

            $storedName = bin2hex(random_bytes(16)) . '.bin';
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0750, true);
            }
            file_put_contents(UPLOAD_DIR . $storedName, $csvContent);

            $origName = 'Auswertung_' . preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', (string)$formRow['title']) . '_' . date('Y-m-d') . '.csv';
            $stmtChkF = $pdo->prepare('SELECT id FROM user_folders WHERE user_id = :uid AND folder_path = :fp AND group_folder_id IS NULL LIMIT 1');
            $stmtChkF->execute([':uid' => $userId, ':fp' => 'Formulare']);
            if (!$stmtChkF->fetchColumn()) {
                $pdo->prepare('INSERT INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)')
                    ->execute([':uid' => $userId, ':fp' => 'Formulare']);
            }

            $pdo->prepare(
                'INSERT INTO files (user_id, folder_path, filename, original_name, file_size, mime_type)
                 VALUES (:uid, :fp, :fn, :on, :fs, :mt)'
            )->execute([
                ':uid' => $userId,
                ':fp'  => 'Formulare',
                ':fn'  => $storedName,
                ':on'  => $origName,
                ':fs'  => strlen($csvContent),
                ':mt'  => 'text/csv',
            ]);

            setFlash('success', 'CSV-Tabelle „' . $origName . '“ wurde im Cloud-Ordner „Formulare“ gespeichert (direkt in Office Light bearbeitbar).');
        }
        header('Location: forms.php?form=' . $formId);
        exit;
    }

    // 4. 1-Klick-Übernahme einer Einsendung (z. B. Online-Mitgliedsantrag) in die Vereinsverwaltung (club.php)
    if ($action === 'import_submission_to_club') {
        $subId  = (int)($_POST['submission_id'] ?? 0);
        $clubId = (int)($_POST['club_id'] ?? 0);

        $stmtSub = $pdo->prepare(
            'SELECT fs.*, f.user_id AS form_owner_id, f.shared_user_id, f.group_id, f.fields_json, f.id AS parent_form_id
             FROM form_submissions fs
             INNER JOIN forms f ON f.id = fs.form_id
             WHERE fs.id = :id LIMIT 1'
        );
        $stmtSub->execute([':id' => $subId]);
        $subRow = $stmtSub->fetch();

        if ($subRow && $clubId > 0) {
            // Prüfen, ob Verein zugänglich ist
            $stmtClub = $pdo->prepare('SELECT * FROM clubs WHERE id = :cid AND (user_id = :uid1 OR shared_user_id = :uid2) LIMIT 1');
            $stmtClub->execute([':cid' => $clubId, ':uid1' => $userId, ':uid2' => $userId]);
            $clubRow = $stmtClub->fetch();

            if ($clubRow) {
                $fullName = trim((string)$subRow['submitter_name']);
                $parts    = preg_split('/\s+/', $fullName, 2);
                $firstName = $parts[0] ?? $fullName;
                $lastName  = $parts[1] ?? '';

                $fields = decodeFormFields((string)$subRow['fields_json']);
                $ans    = json_decode((string)$subRow['answers_json'], true) ?: [];

                $phone = '';
                $dept  = '';
                $addr  = '';
                $bday  = null;
                $notesParts = [];

                foreach ($fields as $fl) {
                    $val = trim((string)($ans[$fl['key']] ?? ''));
                    if ($val === '') {
                        continue;
                    }
                    $lblLower = mb_strtolower($fl['label']);
                    if (str_contains($lblLower, 'vorname')) {
                        $firstName = $val;
                    } elseif (str_contains($lblLower, 'nachname') || str_contains($lblLower, 'familienname')) {
                        $lastName = $val;
                    } elseif (str_contains($lblLower, 'telefon') || str_contains($lblLower, 'mobil') || str_contains($lblLower, 'handy')) {
                        $phone = $val;
                    } elseif (str_contains($lblLower, 'abteilung') || str_contains($lblLower, 'sparte') || str_contains($lblLower, 'mannschaft')) {
                        $dept = $val;
                    } elseif (str_contains($lblLower, 'adresse') || str_contains($lblLower, 'straße') || str_contains($lblLower, 'wohnort')) {
                        $addr = $val;
                    } elseif ($fl['type'] === 'date' || str_contains($lblLower, 'geburt')) {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                            $bday = $val;
                        }
                    } else {
                        $notesParts[] = $fl['label'] . ': ' . $val;
                    }
                }

                // Nächste Mitgliedsnummer ermitteln
                $stmtCntM = $pdo->prepare('SELECT COUNT(*) FROM club_members WHERE club_id = :cid');
                $stmtCntM->execute([':cid' => $clubId]);
                $nextNo = 'M-' . str_pad((string)((int)$stmtCntM->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);

                $stmtInsM = $pdo->prepare(
                    'INSERT INTO club_members
                     (club_id, member_no, first_name, last_name, email, phone, birth_date, joined_date, role, department, status, fee_amount, fee_interval, address, notes)
                     VALUES (:cid, :mno, :fn, :ln, :em, :ph, :bd, :jd, :rl, :dp, :st, :fee, :fi, :ad, :nt)'
                );
                $stmtInsM->execute([
                    ':cid' => $clubId,
                    ':mno' => $nextNo,
                    ':fn'  => mb_substr($firstName, 0, 100),
                    ':ln'  => mb_substr($lastName, 0, 100),
                    ':em'  => mb_substr((string)$subRow['submitter_email'], 0, 150),
                    ':ph'  => mb_substr($phone, 0, 60),
                    ':bd'  => $bday,
                    ':jd'  => date('Y-m-d'),
                    ':rl'  => 'Mitglied',
                    ':dp'  => mb_substr($dept, 0, 80),
                    ':st'  => 'active',
                    ':fee' => (float)($clubRow['default_fee'] ?? 0),
                    ':fi'  => (string)($clubRow['default_fee_interval'] ?? 'yearly'),
                    ':ad'  => mb_substr($addr, 0, 250),
                    ':nt'  => implode(' | ', $notesParts),
                ]);

                $pdo->prepare('UPDATE form_submissions SET imported_to_club = 1 WHERE id = :id')
                    ->execute([':id' => $subId]);

                setFlash('success', '„' . trim($firstName . ' ' . $lastName) . '“ wurde als neues Mitglied (' . $nextNo . ') in den Verein „' . $clubRow['name'] . '“ übernommen!');
            }
            header('Location: forms.php?form=' . (int)$subRow['parent_form_id']);
            exit;
        }
    }

    // 5. Formular öffnen / schließen
    if ($action === 'toggle_form_status') {
        $formId = (int)($_POST['form_id'] ?? 0);
        $pdo->prepare('UPDATE forms SET is_closed = IF(is_closed = 1, 0, 1) WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $formId, ':uid' => $userId]);
        setFlash('success', 'Der Status des Formulars wurde aktualisiert.');
        header('Location: forms.php?form=' . $formId);
        exit;
    }

    // 6. Einzelne Rückmeldung löschen
    if ($action === 'delete_submission') {
        $subId  = (int)($_POST['submission_id'] ?? 0);
        $formId = (int)($_POST['form_id'] ?? 0);
        $stmtF  = $pdo->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmtF->execute([':id' => $formId]);
        $fRow = $stmtF->fetch();
        if ($fRow && canManageForm($pdo, $fRow, $userId)) {
            $pdo->prepare('DELETE FROM form_submissions WHERE id = :sid AND form_id = :fid')
                ->execute([':sid' => $subId, ':fid' => $formId]);
            setFlash('success', 'Der Eintrag wurde gelöscht.');
        }
        header('Location: forms.php?form=' . $formId);
        exit;
    }

    // 7. Komplettes Formular löschen
    if ($action === 'delete_form') {
        $formId = (int)($_POST['form_id'] ?? 0);
        $pdo->prepare('DELETE FROM forms WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $formId, ':uid' => $userId]);
        setFlash('success', 'Das Formular und alle zugehörigen Rückmeldungen wurden gelöscht.');
        header('Location: forms.php');
        exit;
    }
}

// Formulare & aktives Formular laden
$forms      = [];
$activeForm = null;
$activeFormId = isset($_GET['form']) ? (int)$_GET['form'] : 0;

if ($publicToken !== '') {
    $stmtF = $pdo->prepare(
        'SELECT f.*, u.username AS owner_name, g.name AS group_name,
                (SELECT COUNT(*) FROM form_submissions fs WHERE fs.form_id = f.id) AS submission_count
         FROM forms f
         INNER JOIN users u ON u.id = f.user_id
         LEFT JOIN `groups` g ON g.id = f.group_id
         WHERE f.share_token = :tok
         LIMIT 1'
    );
    $stmtF->execute([':tok' => $publicToken]);
    $activeForm = $stmtF->fetch();
    if (!$activeForm || ($isGuestMode && (int)$activeForm['allow_guests'] === 0)) {
        http_response_code(404);
        exit('Dieses Formular existiert nicht oder erfordert eine Anmeldung in der Cloud.');
    }
    $activeFormId = (int)$activeForm['id'];
} else {
    $userGroups = getGroupsForUser($pdo, $userId);
    $groupIds   = array_map(static fn($g) => (int)$g['id'], $userGroups);

    $sql = 'SELECT f.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username,
                   (SELECT COUNT(*) FROM form_submissions fs WHERE fs.form_id = f.id) AS submission_count
            FROM forms f
            INNER JOIN users u ON u.id = f.user_id
            LEFT JOIN `groups` g ON g.id = f.group_id
            LEFT JOIN users su ON su.id = f.shared_user_id
            WHERE f.user_id = :uid1 OR f.shared_user_id = :uid2';
    $params = [':uid1' => $userId, ':uid2' => $userId];
    if (!empty($groupIds)) {
        $phs = [];
        foreach ($groupIds as $i => $gid) {
            $k = ':gid' . $i;
            $phs[] = $k;
            $params[$k] = $gid;
        }
        $sql .= ' OR f.group_id IN (' . implode(',', $phs) . ')';
    }
    $sql .= ' ORDER BY f.id DESC';
    $stmtF = $pdo->prepare($sql);
    $stmtF->execute($params);
    $forms = $stmtF->fetchAll();

    if ($activeFormId > 0) {
        foreach ($forms as $fItem) {
            if ((int)$fItem['id'] === $activeFormId) {
                $activeForm = $fItem;
                break;
            }
        }
    } elseif (!empty($forms)) {
        $activeForm   = $forms[0];
        $activeFormId = (int)$activeForm['id'];
    }
}

$activeFields = $activeForm ? decodeFormFields((string)$activeForm['fields_json']) : [];
$submissions  = [];
if ($activeForm && !$isGuestMode && canManageForm($pdo, $activeForm, $userId)) {
    $stmtSubs = $pdo->prepare('SELECT * FROM form_submissions WHERE form_id = :fid ORDER BY id DESC');
    $stmtSubs->execute([':fid' => (int)$activeForm['id']]);
    $submissions = $stmtSubs->fetchAll();
}

// Eigene Vereine laden (für 1-Klick-Übernahme von Online-Mitgliedsanträgen in club.php)
$userClubs = [];
if ($userId > 0) {
    try {
        $stmtClubs = $pdo->prepare('SELECT id, name, icon FROM clubs WHERE user_id = :uid1 OR shared_user_id = :uid2 ORDER BY name ASC');
        $stmtClubs->execute([':uid1' => $userId, ':uid2' => $userId]);
        $userClubs = $stmtClubs->fetchAll();
    } catch (Throwable $e) {
        $userClubs = [];
    }
}

$shareableUsers = $userId > 0 ? getVisibleUsersForUser($pdo, $userId) : [];
$userGroupsList = $userId > 0 ? getGroupsForUser($pdo, $userId) : [];

$categoryBadges = [
    'event'            => ['🎉 Veranstaltungs-Anmeldung', 'bg-brand-100 text-brand-700 dark:bg-brand-900/50 dark:text-brand-300'],
    'helper'           => ['🤝 Helfer- & Mitbringliste', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'],
    'club_application' => ['🏛️ Online-Mitgliedsantrag', 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300'],
    'feedback'         => ['💬 Feedback-Bogen', 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300'],
    'custom'           => ['📋 Individuelles Formular', 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'],
];

$pageTitle = $activeForm ? ('Formular: ' . $activeForm['title']) : 'Online-Formulare & Veranstaltungs-Anmeldung';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- Modul-Kopfzeile im einheitlichen .mc-card Design -->
    <div class="mc-card p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0 shadow-xs border border-brand-100 dark:border-brand-800/60">
                📝
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                        <?= e($isGuestMode && $activeForm ? $activeForm['title'] : 'Online-Formulare & Veranstaltungs-Anmeldung') ?>
                    </h1>
                    <?php if (!$isGuestMode): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-brand-100 dark:bg-brand-900/50 text-brand-700 dark:text-brand-300">
                            <?= count($forms) ?> Formulare
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    <?php if ($isGuestMode && $activeForm): ?>
                        Eingeladen von <strong><?= e((string)$activeForm['owner_name']) ?></strong> · 100 % selbstgehostet & DSGVO-konform
                    <?php else: ?>
                        Eigene Anmeldeformulare für Vereinsfeste, Helfer-/Mitbringlisten, Online-Mitgliedsanträge & Feedback mit Gäste-QR-Code und CSV-/Vereins-Übernahme.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if (!$isGuestMode): ?>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="openFormBuilderWithTemplate('event')"
                        class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition flex items-center gap-1.5">
                    ➕ Neues Formular / Anmeldung
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isGuestMode): ?>
        <!-- Schnellstart-Vorlagenleiste -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <button type="button" onclick="openFormBuilderWithTemplate('event')"
                    class="mc-card p-4 text-left hover:border-brand-400 dark:hover:border-brand-500 transition flex items-start gap-3">
                <span class="text-2xl">🎉</span>
                <div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">Vereinsfest / Event-Anmeldung</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Personenzahl, Essenswunsch & Salat-/Kuchenspende abfragen.</p>
                </div>
            </button>
            <button type="button" onclick="openFormBuilderWithTemplate('helper')"
                    class="mc-card p-4 text-left hover:border-brand-400 dark:hover:border-brand-500 transition flex items-start gap-3">
                <span class="text-2xl">🤝</span>
                <div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">Helfer- & Schichtliste</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Aufbau, Theke, Grill oder Abbau-Schichten bequem verteilen.</p>
                </div>
            </button>
            <button type="button" onclick="openFormBuilderWithTemplate('club_application')"
                    class="mc-card p-4 text-left hover:border-brand-400 dark:hover:border-brand-500 transition flex items-start gap-3">
                <span class="text-2xl">🏛️</span>
                <div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">Online-Mitgliedsantrag</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Direkt mit 1-Klick-Übernahme in die Vereinsverwaltung.</p>
                </div>
            </button>
            <button type="button" onclick="openFormBuilderWithTemplate('feedback')"
                    class="mc-card p-4 text-left hover:border-brand-400 dark:hover:border-brand-500 transition flex items-start gap-3">
                <span class="text-2xl">💬</span>
                <div>
                    <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">Feedback & Ideen-Bogen</h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Bewertung, Lob & Verbesserungsvorschläge einsammeln.</p>
                </div>
            </button>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 <?= !$isGuestMode ? 'lg:grid-cols-12' : '' ?> gap-6 items-start">

        <?php if (!$isGuestMode): ?>
            <!-- Linke Spalte: Liste aller Formulare -->
            <aside class="lg:col-span-4 space-y-3">
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 px-1">
                    📋 Deine Formulare (<?= count($forms) ?>)
                </h2>
                <?php if (empty($forms)): ?>
                    <div class="mc-card p-6 text-center space-y-2">
                        <div class="text-3xl">📝</div>
                        <p class="text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200">Noch kein Formular angelegt</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Klicke oben auf eine der 4 Schnellstart-Vorlagen, um sofort loszulegen.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-2.5">
                        <?php foreach ($forms as $fItem):
                            $isSelected = $activeForm && (int)$activeForm['id'] === (int)$fItem['id'];
                            $catMeta = $categoryBadges[$fItem['category']] ?? $categoryBadges['custom'];
                        ?>
                            <a href="forms.php?form=<?= (int)$fItem['id'] ?>"
                               class="mc-card block p-4 transition <?= $isSelected ? 'ring-2 ring-brand-500 border-brand-500' : 'hover:border-brand-300 dark:hover:border-brand-700' ?>">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-bold text-sm text-slate-900 dark:text-white truncate">
                                        <?= e((string)$fItem['title']) ?>
                                    </h3>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 shrink-0">
                                        <?= (int)$fItem['submission_count'] ?><?= (int)$fItem['max_submissions'] > 0 ? (' / ' . (int)$fItem['max_submissions']) : '' ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold <?= $catMeta[1] ?>">
                                        <?= e($catMeta[0]) ?>
                                    </span>
                                    <?php if ((int)$fItem['is_closed'] === 1): ?>
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">
                                            🔒 Geschlossen
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>
        <?php endif; ?>

        <!-- Hauptbereich: Aktives Formular (Teilnahme-Maske + Auswertung für Besitzer) -->
        <section class="<?= !$isGuestMode ? 'lg:col-span-8' : 'max-w-2xl mx-auto w-full' ?> space-y-6">
            <?php if (!$activeForm): ?>
                <div class="mc-card p-10 text-center space-y-3">
                    <div class="text-5xl">📋</div>
                    <h2 class="text-lg font-bold text-slate-800 dark:text-slate-100">Wähle eine Vorlage für dein erstes Online-Formular</h2>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                        Ob Sommerfest-Anmeldung, Helfer-Schichtplan oder digitaler Vereins-Mitgliedsantrag – mit einem Klick erstellst du einen öffentlichen Link samt QR-Code für alle Teilnehmer.
                    </p>
                </div>
            <?php else:
                $subCount = (int)($activeForm['submission_count'] ?? count($submissions));
                $maxSubs  = (int)$activeForm['max_submissions'];
                $isFull   = ($maxSubs > 0 && $subCount >= $maxSubs);
                $isExpired = (!empty($activeForm['closes_at']) && (string)$activeForm['closes_at'] < date('Y-m-d'));
                $formClosed = ((int)$activeForm['is_closed'] === 1 || $isFull || $isExpired);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                $publicFormUrl = $scheme . '://' . $host . ($base !== '' ? $base : '') . '/forms.php?token=' . urlencode((string)$activeForm['share_token']);
            ?>

                <!-- Formular-Details & Teilen-Leiste -->
                <div class="mc-card p-5 sm:p-6 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">
                                    <?= e((string)$activeForm['title']) ?>
                                </h2>
                                <?php if ($formClosed): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">
                                        🔒 Anmeldung beendet
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                        🟢 Offen für Einträge
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($activeForm['description'])): ?>
                                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 mt-1">
                                    <?= nl2br(e((string)$activeForm['description'])) ?>
                                </p>
                            <?php endif; ?>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400 mt-2">
                                <span>📊 <strong><?= $subCount ?></strong><?= $maxSubs > 0 ? (' von max. ' . $maxSubs) : '' ?> Rückmeldungen</span>
                                <?php if (!empty($activeForm['closes_at'])): ?>
                                    <span>⏳ Frist bis: <strong><?= e(date('d.m.Y', strtotime((string)$activeForm['closes_at']))) ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isGuestMode): ?>
                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <button type="button" onclick="document.getElementById('modalFormQr').classList.remove('hidden')"
                                        class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold transition">
                                    📱 Link & QR-Code
                                </button>
                                <?php if ((int)$activeForm['user_id'] === $userId): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="toggle_form_status">
                                        <input type="hidden" name="form_id" value="<?= (int)$activeForm['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-slate-700 dark:text-slate-200">
                                            <?= (int)$activeForm['is_closed'] === 1 ? '🔓 Wieder öffnen' : '🔒 Schließen' ?>
                                        </button>
                                    </form>
                                    <form method="post" class="inline" onsubmit="return confirm('Formular samt allen Rückmeldungen wirklich löschen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_form">
                                        <input type="hidden" name="form_id" value="<?= (int)$activeForm['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-red-50 dark:bg-red-950/50 text-red-600 dark:text-red-400 text-xs font-semibold">
                                            🗑️
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($maxSubs > 0):
                        $pct = min(100, (int)round(($subCount / $maxSubs) * 100));
                    ?>
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs font-medium text-slate-600 dark:text-slate-300">
                                <span>Belegte Plätze</span>
                                <span><?= $subCount ?> / <?= $maxSubs ?> (<?= $pct ?> %)</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full <?= $pct >= 100 ? 'bg-red-500' : 'bg-brand-600' ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Ausfüll-Formular -->
                    <?php if ($formClosed): ?>
                        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-xs sm:text-sm text-amber-800 dark:text-amber-200">
                            ℹ️ <strong>Hinweis:</strong> Für dieses Formular können aktuell keine weiteren Einträge mehr abgesendet werden<?= $isFull ? ' (alle Plätze sind belegt)' : '' ?>.
                        </div>
                    <?php else: ?>
                        <form method="post" class="space-y-4 pt-1">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="submit_form">
                            <input type="hidden" name="form_id" value="<?= (int)$activeForm['id'] ?>">
                            <input type="hidden" name="share_token" value="<?= e((string)$activeForm['share_token']) ?>">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/70">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dein Vor- & Nachname *</label>
                                    <input type="text" name="submitter_name" required value="<?= e($username) ?>" placeholder="z. B. Anna Beispiel"
                                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">E-Mail-Adresse (optional für Rückfragen)</label>
                                    <input type="email" name="submitter_email" placeholder="name@beispiel.de"
                                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                </div>
                            </div>

                            <div class="space-y-3.5">
                                <?php foreach ($activeFields as $fl):
                                    $fKey = $fl['key'];
                                    $reqAttr = $fl['required'] ? 'required' : '';
                                ?>
                                    <div>
                                        <label class="block text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200 mb-1">
                                            <?= e($fl['label']) ?>
                                            <?php if ($fl['required']): ?>
                                                <span class="text-red-500">*</span>
                                            <?php endif; ?>
                                        </label>

                                        <?php if ($fl['type'] === 'textarea'): ?>
                                            <textarea name="answers[<?= e($fKey) ?>]" rows="3" <?= $reqAttr ?>
                                                      class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"></textarea>
                                        <?php elseif ($fl['type'] === 'select'): ?>
                                            <select name="answers[<?= e($fKey) ?>]" <?= $reqAttr ?>
                                                    class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                                <option value="">— Bitte auswählen —</option>
                                                <?php foreach ($fl['options'] as $opt): ?>
                                                    <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($fl['type'] === 'checkbox'): ?>
                                            <label class="inline-flex items-center gap-2.5 text-sm text-slate-700 dark:text-slate-300 cursor-pointer mt-0.5">
                                                <input type="checkbox" name="answers[<?= e($fKey) ?>]" value="Ja" <?= $reqAttr ?> class="rounded text-brand-600">
                                                <span>Ja, trifft zu / bestätigt</span>
                                            </label>
                                        <?php elseif ($fl['type'] === 'number'): ?>
                                            <input type="number" name="answers[<?= e($fKey) ?>]" <?= $reqAttr ?>
                                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                        <?php elseif ($fl['type'] === 'date'): ?>
                                            <input type="date" name="answers[<?= e($fKey) ?>]" <?= $reqAttr ?>
                                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                        <?php elseif ($fl['type'] === 'email'): ?>
                                            <input type="email" name="answers[<?= e($fKey) ?>]" <?= $reqAttr ?>
                                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                        <?php else: ?>
                                            <input type="text" name="answers[<?= e($fKey) ?>]" <?= $reqAttr ?>
                                                   class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="pt-2">
                                <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition">
                                    ✅ Verbindlich absenden
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Live-Auswertung & Rückmeldungen (nur für Formular-Ersteller / Berechtigte) -->
                <?php if (!$isGuestMode && canManageForm($pdo, $activeForm, $userId)): ?>
                    <div class="mc-card p-5 sm:p-6 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">
                                    📊 Eingegangene Rückmeldungen (<?= count($submissions) ?>)
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Exportiere die Liste als CSV oder übernimm Anträge direkt mit 1 Klick in deine Vereinsverwaltung.
                                </p>
                            </div>
                            <?php if (!empty($submissions)): ?>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="forms.php?export_csv=<?= (int)$activeForm['id'] ?>"
                                       class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-slate-700 dark:text-slate-200 transition">
                                        ⬇️ CSV herunterladen
                                    </a>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="save_csv_to_cloud">
                                        <input type="hidden" name="form_id" value="<?= (int)$activeForm['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition">
                                            ☁️ In Cloud („Formulare/“) speichern
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($submissions)): ?>
                            <div class="p-6 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-center text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                                Noch keine Rückmeldungen eingegangen. Teile den Link oder QR-Code, um Teilnehmer einzuladen!
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs sm:text-sm">
                                    <thead>
                                        <tr class="border-b border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400">
                                            <th class="py-2.5 px-2 font-semibold">Datum</th>
                                            <th class="py-2.5 px-2 font-semibold">Name / Kontakt</th>
                                            <?php foreach ($activeFields as $fl): ?>
                                                <th class="py-2.5 px-2 font-semibold"><?= e($fl['label']) ?></th>
                                            <?php endforeach; ?>
                                            <th class="py-2.5 px-2 font-semibold text-right">Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        <?php foreach ($submissions as $sub):
                                            $ans = json_decode((string)$sub['answers_json'], true) ?: [];
                                        ?>
                                            <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                                <td class="py-2.5 px-2 whitespace-nowrap text-xs text-slate-400">
                                                    <?= e(date('d.m.Y H:i', strtotime((string)$sub['created_at']))) ?>
                                                </td>
                                                <td class="py-2.5 px-2">
                                                    <div class="font-semibold text-slate-900 dark:text-white"><?= e((string)$sub['submitter_name']) ?></div>
                                                    <?php if (!empty($sub['submitter_email'])): ?>
                                                        <div class="text-[11px] text-slate-400"><?= e((string)$sub['submitter_email']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php foreach ($activeFields as $fl): ?>
                                                    <td class="py-2.5 px-2 text-slate-700 dark:text-slate-300">
                                                        <?= e((string)($ans[$fl['key']] ?? '–')) ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="py-2.5 px-2 text-right whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-1.5">
                                                        <?php if (!empty($userClubs)): ?>
                                                            <?php if ((int)$sub['imported_to_club'] === 1): ?>
                                                                <span class="px-2 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 text-[11px] font-semibold">
                                                                    ✓ Im Verein
                                                                </span>
                                                            <?php else: ?>
                                                                <form method="post" class="inline-flex items-center gap-1">
                                                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                                    <input type="hidden" name="action" value="import_submission_to_club">
                                                                    <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                                    <select name="club_id" class="px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-[11px]">
                                                                        <?php foreach ($userClubs as $cl): ?>
                                                                            <option value="<?= (int)$cl['id'] ?>"><?= e($cl['icon'] . ' ' . $cl['name']) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <button type="submit" title="Als Mitglied in die Vereinsverwaltung übernehmen"
                                                                            class="px-2 py-1 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-[11px] font-semibold">
                                                                        🏛️ +Mitglied
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <form method="post" class="inline" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');">
                                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                            <input type="hidden" name="action" value="delete_submission">
                                                            <input type="hidden" name="form_id" value="<?= (int)$activeForm['id'] ?>">
                                                            <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                            <button type="submit" class="p-1 rounded-lg text-slate-400 hover:text-red-500">✕</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Modal: Öffentlicher Teilnahme-Link & QR-Code -->
                <div id="modalFormQr" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
                    <div class="mc-card w-full max-w-md p-6 text-center space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">📱 Teilnahme-Link & QR-Code</h3>
                            <button type="button" onclick="document.getElementById('modalFormQr').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
                        </div>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                            Teilnehmer können diesen QR-Code auf Einladungen oder Plakaten scannen und das Formular <strong><?= e((string)$activeForm['title']) ?></strong> direkt auf dem Handy ausfüllen.
                        </p>
                        <div class="flex justify-center py-2">
                            <?= renderQrCodeSvg($publicFormUrl, 200) ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" id="publicFormUrlInput" readonly value="<?= e($publicFormUrl) ?>"
                                   class="flex-1 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs text-slate-700 dark:text-slate-200">
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('publicFormUrlInput').value); this.textContent='✓ Kopiert';"
                                    class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shrink-0">
                                📋 Kopieren
                            </button>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </section>
    </div>
</main>

<?php if (!$isGuestMode): ?>
<!-- Modal: Formular-Baukasten mit 4 Vorlagen -->
<div id="modalCreateForm" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="mc-card w-full max-w-2xl p-6 space-y-4 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">➕ Neues Online-Formular erstellen</h3>
            <button type="button" onclick="document.getElementById('modalCreateForm').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_form">
            <input type="hidden" name="category" id="fbCategory" value="event">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Formular-Titel *</label>
                    <input type="text" name="title" id="fbTitle" required placeholder="z. B. Anmeldung zum Sommerfest 2026"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Vorlage wechseln</label>
                    <select onchange="applyFormTemplate(this.value)" id="fbTemplateSelect"
                            class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs sm:text-sm">
                        <option value="event">🎉 Event-Anmeldung</option>
                        <option value="helper">🤝 Helfer- & Schichtliste</option>
                        <option value="club_application">🏛️ Online-Mitgliedsantrag</option>
                        <option value="feedback">💬 Feedback-Bogen</option>
                        <option value="custom">📋 Leer / Individuell</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Einleitungstext / Hinweise (optional)</label>
                <textarea name="description" id="fbDesc" rows="2"
                          class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Max. Plätze (0 = unbegrenzt)</label>
                    <input type="number" name="max_submissions" min="0" value="0"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Anmeldeschluss (optional)</label>
                    <input type="date" name="closes_at"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm">
                    <input type="hidden" name="group_id" value="0">
                </div>
            </div>

            <label class="flex items-center gap-2 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200 cursor-pointer">
                <input type="checkbox" name="allow_guests" value="1" checked class="rounded text-brand-600">
                🔗 Öffentliche Gäste-Teilnahme per Link & QR-Code (ohne Cloud-Konto) erlauben
            </label>

            <!-- Dynamische Formularfelder -->
            <div class="space-y-2.5 pt-2 border-t border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100">🧩 Formularfelder</h4>
                    <button type="button" onclick="addFormFieldRow('', 'text', false, '')"
                            class="px-3 py-1 rounded-lg bg-brand-50 dark:bg-brand-900/40 text-brand-600 dark:text-brand-300 text-xs font-semibold hover:bg-brand-100">
                        + Feld hinzufügen
                    </button>
                </div>
                <div id="fbFieldsContainer" class="space-y-2"></div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('modalCreateForm').classList.add('hidden')"
                        class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold">
                    Abbrechen
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                    💾 Formular veröffentlichen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const FORM_TEMPLATES = {
    event: {
        title: 'Anmeldung zum Sommer-/Vereinsfest',
        desc: 'Bitte melde dich kurz an, damit wir Essen und Getränke optimal planen können.',
        fields: [
            { label: 'Anzahl Personen (inkl. dir)', type: 'number', required: true, options: '' },
            { label: 'Essenswunsch', type: 'select', required: true, options: 'Klassisch (Grill), Vegetarisch, Vegan' },
            { label: 'Ich bringe einen Salat oder Kuchen mit', type: 'text', required: false, options: '' },
            { label: 'Bemerkungen / Allergien', type: 'textarea', required: false, options: '' }
        ]
    },
    helper: {
        title: 'Helfer- & Schichtliste',
        desc: 'Trage dich hier für eine Schicht oder Aufgabe ein. Vielen Dank für deine Unterstützung!',
        fields: [
            { label: 'Gewünschte Schicht / Aufgabe', type: 'select', required: true, options: 'Aufbau (14–16 Uhr), Getränkestand (16–19 Uhr), Grillstand (16–19 Uhr), Abbau (ab 21 Uhr)' },
            { label: 'Mobilnummer für Rückfragen', type: 'text', required: true, options: '' },
            { label: 'Was kannst du optional mitbringen?', type: 'text', required: false, options: '' }
        ]
    },
    club_application: {
        title: 'Online-Mitgliedsantrag für den Verein',
        desc: 'Fülle diesen Antrag digital aus. Der Vorstand kann deine Daten mit 1 Klick in die Vereinsverwaltung übernehmen.',
        fields: [
            { label: 'Geburtsdatum', type: 'date', required: true, options: '' },
            { label: 'Straße, PLZ & Wohnort', type: 'text', required: true, options: '' },
            { label: 'Telefon / Mobilnummer', type: 'text', required: true, options: '' },
            { label: 'Gewünschte Abteilung / Sparte', type: 'select', required: true, options: 'Hauptverein, Jugendabteilung, Fördermitglied, Familie' },
            { label: 'Ich akzeptiere die Vereinssatzung', type: 'checkbox', required: true, options: '' }
        ]
    },
    feedback: {
        title: 'Feedback & Ideen-Bogen',
        desc: 'Wie hat es dir gefallen? Wir freuen uns über deine Rückmeldung!',
        fields: [
            { label: 'Gesamtbewertung', type: 'select', required: true, options: '⭐⭐⭐⭐⭐ Hervorragend, ⭐⭐⭐⭐ Gut, ⭐⭐⭐ Befriedigend, ⭐⭐ Ausbaufähig' },
            { label: 'Was hat dir besonders gut gefallen?', type: 'textarea', required: false, options: '' },
            { label: 'Welche Wünsche oder Ideen hast du für das nächste Mal?', type: 'textarea', required: false, options: '' }
        ]
    },
    custom: {
        title: '',
        desc: '',
        fields: [
            { label: 'Deine Auswahl / Antwort', type: 'text', required: true, options: '' }
        ]
    }
};

function openFormBuilderWithTemplate(tplKey) {
    document.getElementById('modalCreateForm').classList.remove('hidden');
    document.getElementById('fbTemplateSelect').value = tplKey;
    applyFormTemplate(tplKey);
}

function applyFormTemplate(tplKey) {
    const tpl = FORM_TEMPLATES[tplKey] || FORM_TEMPLATES.custom;
    document.getElementById('fbCategory').value = tplKey;
    document.getElementById('fbTitle').value = tpl.title;
    document.getElementById('fbDesc').value = tpl.desc;
    const container = document.getElementById('fbFieldsContainer');
    container.innerHTML = '';
    tpl.fields.forEach(f => addFormFieldRow(f.label, f.type, f.required, f.options));
}

let fbRowCounter = 0;
function addFormFieldRow(label = '', type = 'text', required = false, options = '') {
    const idx = fbRowCounter++;
    const container = document.getElementById('fbFieldsContainer');
    const row = document.createElement('div');
    row.className = 'p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-12 gap-2 items-center';
    row.innerHTML = `
        <div class="sm:col-span-5">
            <input type="text" name="field_label[${idx}]" required placeholder="Feldname (z. B. Essenswunsch)"
                   class="w-full px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
        </div>
        <div class="sm:col-span-3">
            <select name="field_type[${idx}]"
                    class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                <option value="text">Textfeld</option>
                <option value="textarea">Mehrzeiliger Text</option>
                <option value="select">Auswahl (Kommagetrennt)</option>
                <option value="checkbox">Ja/Nein-Haken</option>
                <option value="number">Zahl</option>
                <option value="date">Datum</option>
                <option value="email">E-Mail</option>
            </select>
        </div>
        <div class="sm:col-span-3">
            <input type="text" name="field_options[${idx}]" placeholder="Optionen (A, B, C)"
                   class="w-full px-2.5 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
        </div>
        <div class="sm:col-span-1 flex items-center justify-end gap-1.5">
            <label title="Pflichtfeld" class="text-[11px] flex items-center gap-0.5 cursor-pointer">
                <input type="checkbox" name="field_required[${idx}]" value="1"> *
            </label>
            <button type="button" onclick="this.closest('div.grid').remove()" class="text-slate-400 hover:text-red-500 text-xs font-bold">✕</button>
        </div>
    `;
    row.querySelector(`input[name="field_label[${idx}]"]`).value = label;
    row.querySelector(`select[name="field_type[${idx}]"]`).value = type;
    row.querySelector(`input[name="field_options[${idx}]"]`).value = options;
    row.querySelector(`input[name="field_required[${idx}]"]`).checked = !!required;
    container.appendChild(row);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
