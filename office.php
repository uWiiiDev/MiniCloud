<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';

requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

/**
 * Hilfsfunktion: Ermittelt Lese- und Schreibberechtigung eines Benutzers für eine Datei.
 *
 * @return array{file: array<string, mixed>, can_read: bool, can_write: bool}|null
 */
function resolveOfficeFileAccess(PDO $pdo, int $fileId, int $userId, bool $isAdmin): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date
         FROM files
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();

    if (!$file) {
        return null;
    }

    $canRead  = false;
    $canWrite = false;

    if (!empty($file['group_folder_id'])) {
        $gfId = (int)$file['group_folder_id'];
        $canRead  = canUserAccessGroupFolder($pdo, $userId, $gfId, false, $isAdmin);
        $canWrite = canUserAccessGroupFolder($pdo, $userId, $gfId, true, $isAdmin);
    } elseif ((int)$file['user_id'] === $userId) {
        $canRead  = true;
        $canWrite = true;
    } else {
        // 1. Direkte Einzeldatei-Freigabe prüfen
        $stmtShare = $pdo->prepare(
            'SELECT can_write FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :uid LIMIT 1'
        );
        $stmtShare->execute([':fid' => $fileId, ':uid' => $userId]);
        $directRow = $stmtShare->fetch();
        if ($directRow) {
            $canRead  = true;
            $canWrite = ((int)($directRow['can_write'] ?? 0) === 1);
        } else {
            // 2. Freigabe über übergeordneten Ordner prüfen
            $fileFolderPath = (string)$file['folder_path'];
            if ($fileFolderPath !== '') {
                $stmtFolderShare = $pdo->prepare(
                    'SELECT folder_path, can_write FROM user_shares
                     WHERE owner_id = :owner_id AND shared_with_user_id = :uid AND folder_path IS NOT NULL'
                );
                $stmtFolderShare->execute([
                    ':owner_id' => (int)$file['user_id'],
                    ':uid'      => $userId,
                ]);
                foreach ($stmtFolderShare->fetchAll() as $sfRow) {
                    $sharedFp = (string)$sfRow['folder_path'];
                    if ($fileFolderPath === $sharedFp || str_starts_with($fileFolderPath, $sharedFp . '/')) {
                        $canRead  = true;
                        $canWrite = ((int)($sfRow['can_write'] ?? 0) === 1);
                        break;
                    }
                }
            }
        }
    }

    if (!$canRead) {
        return null;
    }

    return [
        'file'      => $file,
        'can_read'  => $canRead,
        'can_write' => $canWrite,
    ];
}

// ============================================================================
// A) POST: NEUES DOKUMENT / NEUE TABELLE ANLEGEN (create_document)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_document') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
        header('Location: index.php');
        exit;
    }

    $docType        = strtolower(trim((string)($_POST['doc_type'] ?? 'docx')));
    $rawDocName     = trim((string)($_POST['doc_name'] ?? ''));
    $targetFolderIn = trim((string)($_POST['target_folder'] ?? ''));
    $groupFolderId  = isset($_POST['group_folder_id']) ? (int)$_POST['group_folder_id'] : 0;
    $sharedFolderId = isset($_POST['shared_folder_id']) ? (int)$_POST['shared_folder_id'] : 0;

    if (!in_array($docType, ['docx', 'xlsx', 'pptx', 'drawio', 'csv', 'txt', 'md'], true)) {
        $docType = 'docx';
    }

    // Berechtigung & Eigentümer für Zielordner prüfen
    $effectiveOwnerId = $userId;
    $effectiveGfId    = null;
    $folderPath       = '';

    if ($sharedFolderId > 0) {
        $stmtSf = $pdo->prepare(
            'SELECT id, owner_id, folder_path, can_write
             FROM user_shares
             WHERE id = :id AND shared_with_user_id = :uid AND folder_path IS NOT NULL
             LIMIT 1'
        );
        $stmtSf->execute([':id' => $sharedFolderId, ':uid' => $userId]);
        $sfRow = $stmtSf->fetch();
        if (!$sfRow || (int)($sfRow['can_write'] ?? 0) !== 1) {
            setFlash('error', 'Sie besitzen keine Schreibberechtigung für diesen freigegebenen Ordner.');
            header('Location: index.php');
            exit;
        }
        $effectiveOwnerId = (int)$sfRow['owner_id'];
        $folderPath       = (string)$sfRow['folder_path'];
    } elseif ($groupFolderId > 0) {
        if (!canUserAccessGroupFolder($pdo, $userId, $groupFolderId, true, $isAdmin)) {
            setFlash('error', 'Sie besitzen keine Schreibberechtigung für diesen Gruppenordner.');
            header('Location: index.php');
            exit;
        }
        $effectiveGfId = $groupFolderId;
        $folderPath    = $targetFolderIn !== '' ? extractSanitizedFolderPath($targetFolderIn . '/placeholder') : '';
    } else {
        $folderPath = $targetFolderIn !== '' ? extractSanitizedFolderPath($targetFolderIn . '/placeholder') : '';
    }

    // Dateiname bereinigen und passende Endung sicherstellen
    if ($rawDocName === '') {
        $rawDocName = match ($docType) {
            'xlsx'   => 'Neue_Tabelle_' . date('Y-m-d'),
            'pptx'   => 'Neue_Praesentation_' . date('Y-m-d'),
            'drawio' => 'Neues_Diagramm_' . date('Y-m-d'),
            'csv'    => 'Neue_Liste_' . date('Y-m-d'),
            'txt'    => 'Neue_Notiz_' . date('Y-m-d'),
            'md'     => 'Neues_Protokoll_' . date('Y-m-d'),
            default  => 'Neues_Dokument_' . date('Y-m-d'),
        };
    }

    $cleanBase = sanitizeOriginalFilename($rawDocName);
    $currentExt = strtolower(pathinfo($cleanBase, PATHINFO_EXTENSION));
    if ($currentExt !== $docType) {
        $cleanBase = pathinfo($cleanBase, PATHINFO_FILENAME) . '.' . $docType;
    }

    $storageStats = getUserStorageStats($pdo, $effectiveOwnerId);
    if ($storageStats['free'] < 65536) {
        setFlash('error', 'Das Speicherkontingent ist erschöpft. Es kann keine neue Datei angelegt werden.');
        header('Location: index.php');
        exit;
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        setFlash('error', 'Upload-Verzeichnis ist nicht verfügbar.');
        header('Location: index.php');
        exit;
    }

    do {
        $storedFilename = generateSafeStoredFilename();
        $physicalPath   = UPLOAD_DIR . $storedFilename;
    } while (file_exists($physicalPath));

    $docTitle = pathinfo($cleanBase, PATHINFO_FILENAME);
    $templateKey = trim((string)($_POST['template_key'] ?? 'blank'));
    $createdOk = false;

    if ($docType === 'docx') {
        $safeTitle = htmlspecialchars($docTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $todayStr  = date('d.m.Y');
        if ($templateKey === 'letter') {
            $starterHtml = '<p><strong>Absender:</strong> ' . htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' · Musterstraße 1 · 12345 Musterstadt</p>'
                . '<p><strong>Empfänger:</strong><br>Firma / Name<br>Straße &amp; Hausnummer<br>PLZ Ort</p>'
                . '<p>Datum: ' . $todayStr . '</p>'
                . '<h1>Betreff: ' . $safeTitle . '</h1>'
                . '<p>Sehr geehrte Damen und Herren,</p>'
                . '<p>hiermit übersende ich Ihnen die gewünschten Unterlagen und Informationen.</p>'
                . '<p>Für Rückfragen stehe ich Ihnen jederzeit gerne zur Verfügung.</p>'
                . '<p>Mit freundlichen Grüßen</p>'
                . '<p><strong>' . htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        } elseif ($templateKey === 'protocol') {
            $starterHtml = '<h1>📋 Besprechungsprotokoll: ' . $safeTitle . '</h1>'
                . '<p><strong>Datum:</strong> ' . $todayStr . ' &nbsp;|&nbsp; <strong>Protokollführung:</strong> ' . htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<h2>1. Teilnehmer</h2><ul><li>Name 1</li><li>Name 2</li></ul>'
                . '<h2>2. Tagesordnung &amp; Besprechungspunkte</h2><p>Wichtigste Themen und Diskussionsergebnisse...</p>'
                . '<h2>3. Beschlüsse &amp; Aufgaben (To-Dos)</h2><ul><li>[ ] Aufgabe 1 – Verantwortlich: ... – Bis: ...</li><li>[ ] Aufgabe 2 – Verantwortlich: ... – Bis: ...</li></ul>';
        } else {
            $starterHtml = '<h1>' . $safeTitle . '</h1><p></p>';
        }
        $createdOk = writeHtmlAsDocx($starterHtml, $physicalPath);
    } elseif ($docType === 'xlsx' || $docType === 'csv') {
        $starterRows = array_fill(0, 18, array_fill(0, 8, ''));
        $sheetTitle  = 'Tabelle1';
        if ($templateKey === 'budget_xlsx') {
            $sheetTitle = 'Budgetplan';
            $starterRows[0] = ['Datum', 'Kategorie', 'Beschreibung', 'Einnahmen (€)', 'Ausgaben (€)', 'Saldo (€)', 'Beleg-Nr.', 'Notiz'];
            $starterRows[1] = [date('d.m.Y'), 'Startbestand', 'Übertrag', '1000', '0', '=D2-E2', '001', ''];
            $starterRows[2] = [date('d.m.Y'), 'Büro & IT', 'Server / Hosting', '0', '49.90', '=D3-E3', '002', ''];
            $starterRows[3] = ['', 'SUMME', 'Gesamtübersicht', '=SUM(D2:D3)', '=SUM(E2:E3)', '=D4-E4', '', ''];
        } elseif ($templateKey === 'timesheet_xlsx') {
            $sheetTitle = 'Zeiterfassung';
            $starterRows[0] = ['Datum', 'Projekt / Kunde', 'Tätigkeit', 'Beginn', 'Ende', 'Stunden', 'Stundensatz (€)', 'Betrag (€)'];
            $starterRows[1] = [date('d.m.Y'), 'Projekt A', 'Konzeption & Umsetzung', '08:30', '12:30', '4.0', '85', '340'];
            $starterRows[2] = [date('d.m.Y'), 'Projekt A', 'Dokumentation & Test', '13:15', '16:15', '3.0', '85', '255'];
            $starterRows[3] = ['', '', 'SUMME STUNDEN / HONORAR', '', '', '=SUM(F2:F3)', '', '=SUM(H2:H3)'];
        }
        $createdOk = writeSpreadsheetData([['name' => $sheetTitle, 'rows' => $starterRows]], $physicalPath, $cleanBase);
    } elseif ($docType === 'pptx') {
        $authorName = (string)($_SESSION['username'] ?? '');
        if ($templateKey === 'pitch_pptx') {
            $starterDeck = [
                'theme'      => 'navy',
                'transition' => 'fade',
                'slides'     => [
                    [
                        'layout'   => 'title',
                        'title'    => $docTitle,
                        'subtitle' => 'Präsentiert von ' . $authorName . ' · ' . date('d.m.Y'),
                        'body'     => '',
                        'col2'     => '',
                        'stat_val' => '',
                        'image_id' => 0,
                        'notes'    => 'Begrüßung der Teilnehmer und kurzer Überblick über das Ziel des Vortrags.',
                    ],
                    [
                        'layout'   => 'bullets',
                        'title'    => '1. Agenda & wichtigste Themen',
                        'subtitle' => 'Überblick über die heutigen Schwerpunkte',
                        'body'     => "Ausgangslage & aktuelle Kennzahlen\nWichtigste Meilensteine & Fortschritte\nOffene Punkte & Entscheidungen\nNächste Schritte & Zeitplan",
                        'col2'     => '',
                        'stat_val' => '',
                        'image_id' => 0,
                        'notes'    => 'Kurz durch die 4 Agenda-Punkte führen.',
                    ],
                    [
                        'layout'   => 'two_col',
                        'title'    => '2. Vergleich & Optionen',
                        'subtitle' => 'Gegenüberstellung der beiden Varianten',
                        'body'     => "Variante A (Schnellstart)\nGeringerer Aufwand\nSofort einsatzbereit\nBewährte Struktur",
                        'col2'     => "Variante B (Ausbau)\nErweiterte Funktionen\nMehr Flexibilität für große Teams\nLangfristige Skalierung",
                        'stat_val' => '',
                        'image_id' => 0,
                        'notes'    => 'Vor- und Nachteile gemeinsam mit dem Team abwägen.',
                    ],
                    [
                        'layout'   => 'big_stat',
                        'title'    => '3. Unser wichtigstes Ziel',
                        'subtitle' => 'Gemeinsam erfolgreich umgesetzt – vielen Dank für eure Aufmerksamkeit!',
                        'body'     => '',
                        'col2'     => '',
                        'stat_val' => '100 %',
                        'image_id' => 0,
                        'notes'    => 'Fragerunde eröffnen.',
                    ],
                ],
            ];
        } else {
            $starterDeck = [
                'theme'      => 'light',
                'transition' => 'fade',
                'slides'     => [
                    [
                        'layout'   => 'title',
                        'title'    => $docTitle,
                        'subtitle' => 'Erstellt am ' . date('d.m.Y') . ($authorName !== '' ? ' · ' . $authorName : ''),
                        'body'     => '',
                        'col2'     => '',
                        'stat_val' => '100 %',
                        'image_id' => 0,
                        'notes'    => '',
                    ],
                    [
                        'layout'   => 'bullets',
                        'title'    => 'Überblick & Kernpunkte',
                        'subtitle' => '',
                        'body'     => "Erster wichtiger Punkt\nZweiter wichtiger Punkt\nDritter wichtiger Punkt",
                        'col2'     => '',
                        'stat_val' => '',
                        'image_id' => 0,
                        'notes'    => '',
                    ],
                ],
            ];
        }
        $createdOk = writePptxData($starterDeck, $physicalPath);
    } elseif ($docType === 'drawio') {
        if ($templateKey === 'orgchart_visio') {
            $starterVisio = [
                'theme' => 'light',
                'grid'  => true,
                'nodes' => [
                    ['id' => 'n1', 'shape' => 'person', 'x' => 380, 'y' => 50,  'w' => 200, 'h' => 76, 'text' => '1. Vorsitzende/r', 'subtext' => 'Gesamtleitung & Vertretung', 'color' => 'blue'],
                    ['id' => 'n2', 'shape' => 'person', 'x' => 110, 'y' => 200, 'w' => 185, 'h' => 72, 'text' => '2. Vorsitzende/r', 'subtext' => 'Stellvertretung', 'color' => 'emerald'],
                    ['id' => 'n3', 'shape' => 'person', 'x' => 388, 'y' => 200, 'w' => 185, 'h' => 72, 'text' => 'Kassenwart/in', 'subtext' => 'Finanzen & Beiträge', 'color' => 'amber'],
                    ['id' => 'n4', 'shape' => 'person', 'x' => 665, 'y' => 200, 'w' => 185, 'h' => 72, 'text' => 'Schriftführer/in', 'subtext' => 'Protokolle & Presse', 'color' => 'violet'],
                    ['id' => 'n5', 'shape' => 'round',  'x' => 240, 'y' => 350, 'w' => 190, 'h' => 68, 'text' => 'Jugend- & Sportwart', 'subtext' => 'Training & Events', 'color' => 'rose'],
                    ['id' => 'n6', 'shape' => 'round',  'x' => 530, 'y' => 350, 'w' => 190, 'h' => 68, 'text' => 'Geräte- & Hauswart', 'subtext' => 'Inventar & Anlage', 'color' => 'slate'],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'label' => '', 'style' => 'solid', 'routing' => 'ortho'],
                    ['id' => 'e2', 'from' => 'n1', 'to' => 'n3', 'label' => '', 'style' => 'solid', 'routing' => 'ortho'],
                    ['id' => 'e3', 'from' => 'n1', 'to' => 'n4', 'label' => '', 'style' => 'solid', 'routing' => 'ortho'],
                    ['id' => 'e4', 'from' => 'n2', 'to' => 'n5', 'label' => '', 'style' => 'solid', 'routing' => 'ortho'],
                    ['id' => 'e5', 'from' => 'n3', 'to' => 'n6', 'label' => '', 'style' => 'solid', 'routing' => 'ortho'],
                ],
            ];
        } elseif ($templateKey === 'flowchart_visio') {
            $starterVisio = [
                'theme' => 'light',
                'grid'  => true,
                'nodes' => [
                    ['id' => 'n1', 'shape' => 'stadium', 'x' => 80,  'y' => 180, 'w' => 150, 'h' => 64, 'text' => 'Start / Eingang', 'subtext' => 'Neue Anfrage', 'color' => 'emerald'],
                    ['id' => 'n2', 'shape' => 'rect',    'x' => 290, 'y' => 176, 'w' => 180, 'h' => 72, 'text' => 'Unterlagen prüfen', 'subtext' => 'Vollständigkeit', 'color' => 'blue'],
                    ['id' => 'n3', 'shape' => 'diamond', 'x' => 535, 'y' => 162, 'w' => 170, 'h' => 100,'text' => 'Freigabe OK?', 'subtext' => 'Prüfergebnis', 'color' => 'amber'],
                    ['id' => 'n4', 'shape' => 'round',   'x' => 775, 'y' => 95,  'w' => 180, 'h' => 70, 'text' => 'Umsetzung starten', 'subtext' => 'Freigegeben', 'color' => 'emerald'],
                    ['id' => 'n5', 'shape' => 'note',    'x' => 775, 'y' => 260, 'w' => 180, 'h' => 70, 'text' => 'Rückfrage senden', 'subtext' => 'Korrektur nötig', 'color' => 'rose'],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'label' => '',     'style' => 'solid',  'routing' => 'curved'],
                    ['id' => 'e2', 'from' => 'n2', 'to' => 'n3', 'label' => '',     'style' => 'solid',  'routing' => 'curved'],
                    ['id' => 'e3', 'from' => 'n3', 'to' => 'n4', 'label' => 'Ja',   'style' => 'solid',  'routing' => 'curved'],
                    ['id' => 'e4', 'from' => 'n3', 'to' => 'n5', 'label' => 'Nein', 'style' => 'dashed', 'routing' => 'curved'],
                ],
            ];
        } else {
            $starterVisio = [
                'theme' => 'light',
                'grid'  => true,
                'nodes' => [
                    ['id' => 'n1', 'shape' => 'stadium', 'x' => 140, 'y' => 180, 'w' => 160, 'h' => 64, 'text' => 'Start', 'subtext' => 'Auslöser', 'color' => 'emerald'],
                    ['id' => 'n2', 'shape' => 'rect',    'x' => 390, 'y' => 176, 'w' => 190, 'h' => 72, 'text' => 'Prozessschritt 1', 'subtext' => 'Beschreibung', 'color' => 'blue'],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'label' => '', 'style' => 'solid', 'routing' => 'curved'],
                ],
            ];
        }
        $createdOk = writeVisioData($starterVisio, $physicalPath);
    } elseif ($docType === 'md') {
        if ($templateKey === 'md_notes') {
            $starterMd = "# 📝 {$docTitle}\n\n_Erstellt am " . date('d.m.Y') . "_\n\n## 🎯 Ziele & Zusammenfassung\n- Punkt 1\n- Punkt 2\n\n## ✅ Checkliste\n- [ ] Erster Schritt\n- [ ] Zweiter Schritt\n\n## 💡 Notizen\nHier Text eingeben...\n";
        } else {
            $starterMd = '# ' . $docTitle . "\n\n";
        }
        $createdOk = (@file_put_contents($physicalPath, $starterMd) !== false);
        @chmod($physicalPath, 0640);
    } else {
        $createdOk = (@file_put_contents($physicalPath, '') !== false);
        @chmod($physicalPath, 0640);
    }

    if (!$createdOk || !is_file($physicalPath)) {
        @unlink($physicalPath);
        setFlash('error', 'Die neue Office-Datei konnte nicht erstellt werden (prüfen Sie, ob PHP ZipArchive aktiv ist).');
        header('Location: index.php');
        exit;
    }

    $fileSize = (int)filesize($physicalPath);
    $mimeType = getOfficeMimeType($cleanBase);

    if ($folderPath !== '') {
        registerFolderHierarchy($pdo, $effectiveOwnerId, $folderPath, $effectiveGfId);
    }

    $stmtIns = $pdo->prepare(
        'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
         VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, 0)'
    );
    $stmtIns->execute([
        ':uid'   => $effectiveOwnerId,
        ':gf_id' => $effectiveGfId,
        ':fn'    => $storedFilename,
        ':orig'  => $cleanBase,
        ':fp'    => $folderPath,
        ':sz'    => $fileSize,
        ':mime'  => $mimeType,
    ]);

    $newFileId = (int)$pdo->lastInsertId();
    $params = ['id' => $newFileId];
    if ($sharedFolderId > 0) {
        $params['return_sf'] = $sharedFolderId;
    } elseif ($groupFolderId > 0) {
        $params['return_gf'] = $groupFolderId;
    }
    if ($folderPath !== '') {
        $params['return_folder'] = $folderPath;
    }

    header('Location: office.php?' . http_build_query($params));
    exit;
}

// ============================================================================
// B) POST: DOKUMENT SPEICHERN (save_document per AJAX)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_document') {
    header('Content-Type: application/json; charset=utf-8');

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.']);
        exit;
    }

    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $access = resolveOfficeFileAccess($pdo, $fileId, $userId, $isAdmin);

    if ($access === null || !$access['can_write']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Sie besitzen keine Schreibberechtigung für diese Datei.']);
        exit;
    }

    $file = $access['file'];
    $storedFilename = basename((string)$file['filename']);
    $physicalPath   = UPLOAD_DIR . $storedFilename;

    if ((int)($file['is_encrypted'] ?? 0) === 1 || (is_file($physicalPath) && isVaultEncryptedFile($physicalPath))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tresor-verschlüsselte Dateien können nicht direkt überschrieben werden.']);
        exit;
    }

    $origName = (string)$file['original_name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $editorType = getOfficeEditorType($origName);

    if ($editorType === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dieser Dateityp wird von Office Light nicht unterstützt.']);
        exit;
    }

    // Optionaler neuer Dateiname (Endung bleibt erhalten bzw. bei Bild-Konvertierung anpassbar)
    $newTitleInput = trim((string)($_POST['doc_title'] ?? ''));
    $targetExt = $ext;
    if ($editorType === 'image') {
        $reqExt = strtolower(trim((string)($_POST['target_ext'] ?? $ext)));
        if (in_array($reqExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $targetExt = ($reqExt === 'jpeg') ? 'jpg' : $reqExt;
        }
    }
    $updatedOrigName = $origName;
    if ($newTitleInput !== '') {
        $cleanNew = sanitizeOriginalFilename($newTitleInput);
        $newBase  = pathinfo($cleanNew, PATHINFO_FILENAME);
        if ($newBase !== '') {
            $updatedOrigName = $newBase . '.' . $targetExt;
        }
    } elseif ($targetExt !== $ext) {
        $updatedOrigName = pathinfo($origName, PATHINFO_FILENAME) . '.' . $targetExt;
    }

    // Temporäre Zieldatei erzeugen, um Größe und Kontingent vor dem Überschreiben zu prüfen
    $tmpSave = tempnam(sys_get_temp_dir(), 'mc_office_save_');
    if ($tmpSave === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Temporäre Datei konnte nicht angelegt werden.']);
        exit;
    }

    $writeOk = false;
    if ($editorType === 'word') {
        $htmlContent = (string)($_POST['html_content'] ?? '<p></p>');
        $writeOk = writeHtmlAsDocx($htmlContent, $tmpSave);
    } elseif ($editorType === 'sheet') {
        $sheetsJson = (string)($_POST['sheets_json'] ?? '[]');
        $decodedSheets = json_decode($sheetsJson, true);
        if (!is_array($decodedSheets)) {
            @unlink($tmpSave);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Tabellendaten übermittelt.']);
            exit;
        }
        $writeOk = writeSpreadsheetData($decodedSheets, $tmpSave, $updatedOrigName);
    } elseif ($editorType === 'slides') {
        $slidesJson = (string)($_POST['slides_json'] ?? '{}');
        $decodedDeck = json_decode($slidesJson, true);
        if (!is_array($decodedDeck)) {
            @unlink($tmpSave);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Präsentationsdaten übermittelt.']);
            exit;
        }
        $writeOk = writePptxData($decodedDeck, $tmpSave);
    } elseif ($editorType === 'visio') {
        $visioJson = (string)($_POST['visio_json'] ?? '{}');
        $decodedVisio = json_decode($visioJson, true);
        if (!is_array($decodedVisio)) {
            @unlink($tmpSave);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Diagrammdaten übermittelt.']);
            exit;
        }
        $writeOk = writeVisioData($decodedVisio, $tmpSave);
    } elseif ($editorType === 'image') {
        if (isset($_FILES['image_blob']) && (int)($_FILES['image_blob']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $writeOk = @move_uploaded_file((string)$_FILES['image_blob']['tmp_name'], $tmpSave)
                || @copy((string)$_FILES['image_blob']['tmp_name'], $tmpSave);
        } elseif (!empty($_POST['image_data_url']) && is_string($_POST['image_data_url'])) {
            if (preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,(.+)$#i', $_POST['image_data_url'], $m)) {
                $bin = base64_decode($m[1], true);
                if ($bin !== false && strlen($bin) > 0) {
                    $writeOk = (@file_put_contents($tmpSave, $bin) !== false);
                }
            }
        }
    } else {
        $textContent = (string)($_POST['text_content'] ?? '');
        $writeOk = (@file_put_contents($tmpSave, $textContent) !== false);
    }

    if (!$writeOk || !is_file($tmpSave)) {
        @unlink($tmpSave);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern der Datei auf dem Server.']);
        exit;
    }

    $newSize = (int)filesize($tmpSave);
    $saveMode = ($editorType === 'image' && ($_POST['save_mode'] ?? 'overwrite') === 'copy') ? 'copy' : 'overwrite';

    if ($saveMode === 'copy') {
        $ownerStats = getUserStorageStats($pdo, (int)$file['user_id']);
        if ($newSize > $ownerStats['free']) {
            @unlink($tmpSave);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nicht genügend freier Cloud-Speicherplatz für die neue Bildkopie.']);
            exit;
        }
        do {
            $newStored = generateSafeStoredFilename();
            $newDest   = UPLOAD_DIR . $newStored;
        } while (file_exists($newDest));

        if (!@rename($tmpSave, $newDest)) {
            if (!@copy($tmpSave, $newDest)) {
                @unlink($tmpSave);
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Kopie konnte nicht gespeichert werden.']);
                exit;
            }
            @unlink($tmpSave);
        }
        @chmod($newDest, 0640);

        $copyBase = pathinfo($updatedOrigName, PATHINFO_FILENAME);
        if (strtolower($copyBase) === strtolower(pathinfo($origName, PATHINFO_FILENAME))) {
            $copyBase .= '_bearbeitet_' . date('His');
        }
        $copyOrigName = $copyBase . '.' . $targetExt;
        $copyMime     = getOfficeMimeType($copyOrigName);

        $stmtCopy = $pdo->prepare(
            'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted)
             VALUES (:uid, :gf_id, :fn, :orig, :fp, :sz, :mime, 0)'
        );
        $stmtCopy->execute([
            ':uid'   => (int)$file['user_id'],
            ':gf_id' => !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null,
            ':fn'    => $newStored,
            ':orig'  => $copyOrigName,
            ':fp'    => (string)$file['folder_path'],
            ':sz'    => $newSize,
            ':mime'  => $copyMime,
        ]);
        $newCopyId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success'   => true,
            'message'   => 'Als Kopie „' . $copyOrigName . '“ gespeichert (' . date('H:i:s') . ' Uhr)',
            'filename'  => $copyOrigName,
            'size'      => formatBytes($newSize),
            'newFileId' => $newCopyId,
            'updatedAt' => date('d.m.Y H:i') . ' Uhr',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $oldSize = (int)$file['file_size'];
    $sizeDelta = $newSize - $oldSize;

    if ($sizeDelta > 0) {
        $ownerStats = getUserStorageStats($pdo, (int)$file['user_id']);
        if ($sizeDelta > $ownerStats['free']) {
            @unlink($tmpSave);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nicht genügend freier Cloud-Speicherplatz zum Speichern der Änderungen.']);
            exit;
        }
    }

    // Vor dem Überschreiben automatisch einen Versions-Snapshot (max. 5 Versionen) anlegen
    createFileVersionSnapshot($pdo, $file, 'Office Light (' . (string)($_SESSION['username'] ?? 'Editor') . ')');

    if (!@rename($tmpSave, $physicalPath)) {
        if (!@copy($tmpSave, $physicalPath)) {
            @unlink($tmpSave);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Zieldatei konnte nicht aktualisiert werden.']);
            exit;
        }
        @unlink($tmpSave);
    }
    @chmod($physicalPath, 0640);

    $newMime = getOfficeMimeType($updatedOrigName);
    $stmtUpd = $pdo->prepare(
        'UPDATE files
         SET original_name = :orig, file_size = :sz, mime_type = :mime, upload_date = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmtUpd->execute([
        ':orig' => $updatedOrigName,
        ':sz'   => $newSize,
        ':mime' => $newMime,
        ':id'   => $fileId,
    ]);

    // Live-Team über den Speichervorgang informieren
    try {
        $stmtSaveDelta = $pdo->prepare(
            'INSERT INTO office_collab_deltas (file_id, user_id, username, delta_type, target_key, payload_json)
             VALUES (:fid, :uid, :uname, :dtype, :tkey, :payload)'
        );
        $stmtSaveDelta->execute([
            ':fid'     => $fileId,
            ':uid'     => $userId,
            ':uname'   => (string)($_SESSION['username'] ?? 'Editor'),
            ':dtype'   => 'file_saved',
            ':tkey'    => 'doc',
            ':payload' => json_encode([
                'filename'  => $updatedOrigName,
                'size'      => formatBytes($newSize),
                'savedAt'   => date('H:i:s'),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable) {}

    echo json_encode([
        'success'   => true,
        'message'   => 'Gespeichert (' . date('H:i:s') . ' Uhr)',
        'filename'  => $updatedOrigName,
        'size'      => formatBytes($newSize),
        'updatedAt' => date('d.m.Y H:i') . ' Uhr',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// B2) POST: LIVE-TEAM CO-EDITING (Stufe 1: Anwesenheit, Absatz-/Zellen-Sperre & Delta-Sync)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'collab_sync') {
    header('Content-Type: application/json; charset=utf-8');

    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token']);
        exit;
    }

    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $access = resolveOfficeFileAccess($pdo, $fileId, $userId, $isAdmin);
    if ($access === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Kein Zugriff auf diese Datei.']);
        exit;
    }

    $username = (string)($_SESSION['username'] ?? ('User#' . $userId));
    $palette  = ['#2563eb', '#059669', '#d97706', '#e11d48', '#7c3aed', '#0891b2', '#ea580c', '#4f46e5'];
    $userColor = $palette[$userId % count($palette)];

    // Abmelden beim Verlassen der Seite (pagehide / beforeunload)
    if (isset($_POST['leave']) && $_POST['leave'] === '1') {
        $pdo->prepare('DELETE FROM office_collab_presence WHERE file_id = :fid AND user_id = :uid')
            ->execute([':fid' => $fileId, ':uid' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Veraltete Anwesenheiten (> 18 Sek.) und alte Deltas (> 10 Min.) bereinigen
    $pdo->exec('DELETE FROM office_collab_presence WHERE last_seen < DATE_SUB(NOW(), INTERVAL 18 SECOND)');
    $pdo->exec('DELETE FROM office_collab_deltas WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

    $focusTarget = mb_substr(trim((string)($_POST['focus_target'] ?? '')), 0, 120);
    $focusLabel  = mb_substr(trim((string)($_POST['focus_label'] ?? '')), 0, 120);
    $sinceId     = max(0, (int)($_POST['since_id'] ?? 0));

    // Eigene Anwesenheit & aktuelle Fokus-Position (Zelle / Absatz / Zeile) aktualisieren
    $stmtPres = $pdo->prepare(
        'INSERT INTO office_collab_presence (file_id, user_id, username, focus_target, focus_label, color_hex, last_seen)
         VALUES (:fid, :uid, :uname, :ft, :fl, :col, NOW())
         ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            focus_target = VALUES(focus_target),
            focus_label = VALUES(focus_label),
            color_hex = VALUES(color_hex),
            last_seen = NOW()'
    );
    $stmtPres->execute([
        ':fid'   => $fileId,
        ':uid'   => $userId,
        ':uname' => $username,
        ':ft'    => $focusTarget,
        ':fl'    => $focusLabel,
        ':col'   => $userColor,
    ]);

    // Falls der Client beim erstmaligen Öffnen noch keine Delta-ID kennt (since_id = -1), aktuelle Max-ID liefern
    if (isset($_POST['init']) && $_POST['init'] === '1') {
        $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM office_collab_deltas WHERE file_id = :fid');
        $stmtMax->execute([':fid' => $fileId]);
        $sinceId = (int)$stmtMax->fetchColumn();
    }

    // Ausgehende Änderungen (Deltas) des Benutzers prüfen & speichern (Stufe 1 Konfliktsperre)
    $lockedConflicts = [];
    if ($access['can_write'] && !empty($_POST['deltas_json'])) {
        $incomingDeltas = json_decode((string)$_POST['deltas_json'], true);
        if (is_array($incomingDeltas)) {
            $stmtCheckLock = $pdo->prepare(
                'SELECT username FROM office_collab_presence
                 WHERE file_id = :fid AND user_id != :uid AND focus_target = :ft
                   AND focus_target != "" AND last_seen >= DATE_SUB(NOW(), INTERVAL 12 SECOND)
                 LIMIT 1'
            );
            $stmtInsDelta = $pdo->prepare(
                'INSERT INTO office_collab_deltas (file_id, user_id, username, delta_type, target_key, payload_json)
                 VALUES (:fid, :uid, :uname, :dtype, :tkey, :payload)'
            );

            foreach (array_slice($incomingDeltas, 0, 40) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $dType   = preg_replace('/[^a-z_]/', '', (string)($d['type'] ?? ''));
                $tKey    = mb_substr(trim((string)($d['target'] ?? '')), 0, 120);
                $payload = $d['payload'] ?? null;

                if (!in_array($dType, ['sheet_cell', 'sheet_full', 'word_block', 'word_full', 'slides_slide', 'slides_full', 'visio_node', 'visio_full', 'text_full'], true) || $payload === null) {
                    continue;
                }

                // Konfliktprüfung für Einzel-Zelle, Einzel-Absatz, Einzel-Folie oder Einzel-Knoten: Ist genau dieses Element gerade von jemand anderem fokussiert?
                if (in_array($dType, ['sheet_cell', 'word_block', 'slides_slide', 'visio_node'], true) && $tKey !== '') {
                    $stmtCheckLock->execute([':fid' => $fileId, ':uid' => $userId, ':ft' => $tKey]);
                    $lockedByUser = $stmtCheckLock->fetchColumn();
                    if ($lockedByUser !== false) {
                        $lockedConflicts[] = [
                            'target' => $tKey,
                            'lockedBy' => (string)$lockedByUser,
                        ];
                        continue;
                    }
                }

                $payloadStr = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
                if (is_string($payloadStr) && strlen($payloadStr) <= 524288) {
                    $stmtInsDelta->execute([
                        ':fid'     => $fileId,
                        ':uid'     => $userId,
                        ':uname'   => $username,
                        ':dtype'   => $dType,
                        ':tkey'    => $tKey,
                        ':payload' => $payloadStr,
                    ]);
                }
            }
        }
    }

    // Aktive Mitarbeiter im Dokument abrufen
    $stmtPeers = $pdo->prepare(
        'SELECT user_id, username, focus_target, focus_label, color_hex
         FROM office_collab_presence
         WHERE file_id = :fid AND last_seen >= DATE_SUB(NOW(), INTERVAL 12 SECOND)
         ORDER BY (user_id = :uid) DESC, username ASC'
    );
    $stmtPeers->execute([':fid' => $fileId, ':uid' => $userId]);
    $collaborators = [];
    foreach ($stmtPeers->fetchAll() as $pRow) {
        $collaborators[] = [
            'userId'      => (int)$pRow['user_id'],
            'username'    => (string)$pRow['username'],
            'isMe'        => ((int)$pRow['user_id'] === $userId),
            'focusTarget' => (string)$pRow['focus_target'],
            'focusLabel'  => (string)$pRow['focus_label'],
            'color'       => (string)$pRow['color_hex'],
        ];
    }

    // Neue Deltas anderer Benutzer seit $sinceId abrufen
    $stmtNewDeltas = $pdo->prepare(
        'SELECT id, user_id, username, delta_type, target_key, payload_json, DATE_FORMAT(created_at, "%H:%i:%s") AS time_str
         FROM office_collab_deltas
         WHERE file_id = :fid AND id > :since
         ORDER BY id ASC
         LIMIT 150'
    );
    $stmtNewDeltas->execute([':fid' => $fileId, ':since' => $sinceId]);
    $deltasOut = [];
    $maxDeltaId = $sinceId;

    foreach ($stmtNewDeltas->fetchAll() as $dRow) {
        $did = (int)$dRow['id'];
        if ($did > $maxDeltaId) {
            $maxDeltaId = $did;
        }
        // Eigene bereits lokal angewendete Deltas nicht doppelt zurückspielen
        if ((int)$dRow['user_id'] === $userId) {
            continue;
        }
        $deltasOut[] = [
            'id'       => $did,
            'userId'   => (int)$dRow['user_id'],
            'username' => (string)$dRow['username'],
            'type'     => (string)$dRow['delta_type'],
            'target'   => (string)$dRow['target_key'],
            'payload'  => json_decode((string)$dRow['payload_json'], true) ?? (string)$dRow['payload_json'],
            'time'     => (string)$dRow['time_str'],
        ];
    }

    echo json_encode([
        'success'       => true,
        'collaborators' => $collaborators,
        'deltas'        => $deltasOut,
        'maxDeltaId'    => $maxDeltaId,
        'conflicts'     => $lockedConflicts,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// C) GET: OFFICE LIGHT START-ZENTRALE (wenn keine Datei-ID übergeben wurde)
//    ODER DOKUMENT IM EDITOR ÖFFNEN (?id=...)
// ============================================================================
$appSettings = getAppSettings($pdo);
if (($appSettings['enable_office'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('warning', 'Das Modul „Office Light“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($fileId <= 0) {
    // ------------------------------------------------------------------------
    // OFFICE LIGHT START-ZENTRALE (Modul-Übersicht, Vorlagen & Dokumentenliste)
    // ------------------------------------------------------------------------
    $officeExts = ['docx', 'xlsx', 'csv', 'md', 'txt', 'html', 'htm', 'json', 'xml', 'yml', 'yaml', 'ini', 'sql', 'log'];

    // 1. Eigene Dateien abrufen
    $stmtOwn = $pdo->prepare(
        'SELECT f.id, f.user_id, f.group_folder_id, f.original_name, f.folder_path, f.file_size, f.mime_type, f.is_encrypted, f.upload_date,
                u.username AS owner_username, gf.name AS group_folder_name
         FROM files f
         INNER JOIN users u ON u.id = f.user_id
         LEFT JOIN group_folders gf ON gf.id = f.group_folder_id
         WHERE f.deleted_at IS NULL AND f.is_encrypted = 0 AND f.user_id = :uid
         ORDER BY f.upload_date DESC
         LIMIT 250'
    );
    $stmtOwn->execute([':uid' => $userId]);
    $rawOwn = $stmtOwn->fetchAll();

    // 2. Direkt mit mir geteilte Einzeldateien abrufen
    $stmtSharedFiles = $pdo->prepare(
        'SELECT f.id, f.user_id, f.group_folder_id, f.original_name, f.folder_path, f.file_size, f.mime_type, f.is_encrypted, f.upload_date,
                u.username AS owner_username, us.can_write
         FROM user_shares us
         INNER JOIN files f ON f.id = us.file_id
         INNER JOIN users u ON u.id = f.user_id
         WHERE us.shared_with_user_id = :uid AND us.file_id IS NOT NULL AND f.deleted_at IS NULL AND f.is_encrypted = 0
         ORDER BY f.upload_date DESC
         LIMIT 150'
    );
    $stmtSharedFiles->execute([':uid' => $userId]);
    $rawShared = $stmtSharedFiles->fetchAll();

    // 3. Aktive Live-Mitarbeiter in Dokumenten zählen
    $livePresenceCounts = [];
    try {
        $stmtLive = $pdo->query(
            'SELECT file_id, COUNT(*) AS cnt
             FROM office_collab_presence
             WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
             GROUP BY file_id'
        );
        foreach ($stmtLive->fetchAll() as $lRow) {
            $livePresenceCounts[(int)$lRow['file_id']] = (int)$lRow['cnt'];
        }
    } catch (Throwable) {}

    $officeDocs = [];
    $seenIds    = [];

    foreach ($rawOwn as $row) {
        $edType = getOfficeEditorType((string)$row['original_name']);
        if ($edType === null) {
            continue;
        }
        $fid = (int)$row['id'];
        $seenIds[$fid] = true;
        $ext = strtolower(pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
        $officeDocs[] = [
            'id'           => $fid,
            'name'         => (string)$row['original_name'],
            'ext'          => $ext,
            'editor_type'  => $edType,
            'folder'       => (string)$row['folder_path'],
            'group_folder' => (string)($row['group_folder_name'] ?? ''),
            'size'         => formatBytes((int)$row['file_size']),
            'date'         => date('d.m.Y H:i', strtotime((string)$row['upload_date']) ?: time()),
            'owner'        => (string)$row['owner_username'],
            'is_shared'    => false,
            'can_write'    => true,
            'live_count'   => $livePresenceCounts[$fid] ?? 0,
        ];
    }

    foreach ($rawShared as $row) {
        $fid = (int)$row['id'];
        if (isset($seenIds[$fid])) {
            continue;
        }
        $edType = getOfficeEditorType((string)$row['original_name']);
        if ($edType === null) {
            continue;
        }
        $seenIds[$fid] = true;
        $ext = strtolower(pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
        $officeDocs[] = [
            'id'           => $fid,
            'name'         => (string)$row['original_name'],
            'ext'          => $ext,
            'editor_type'  => $edType,
            'folder'       => (string)$row['folder_path'],
            'group_folder' => '',
            'size'         => formatBytes((int)$row['file_size']),
            'date'         => date('d.m.Y H:i', strtotime((string)$row['upload_date']) ?: time()),
            'owner'        => (string)$row['owner_username'],
            'is_shared'    => true,
            'can_write'    => ((int)($row['can_write'] ?? 0) === 1),
            'live_count'   => $livePresenceCounts[$fid] ?? 0,
        ];
    }

    // 4. Mind-Maps des Benutzers (und mit ihm geteilte Mind-Maps) direkt in die Office-Zentrale einbinden
    try {
        $stmtMm = $pdo->prepare(
            'SELECT m.id, m.user_id, m.title, m.description, m.map_json, m.updated_at, u.username AS owner_username
             FROM mindmaps m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.user_id = :uid OR FIND_IN_SET(:uid_str, m.shared_users) > 0
             ORDER BY m.updated_at DESC
             LIMIT 100'
        );
        $stmtMm->execute([':uid' => $userId, ':uid_str' => (string)$userId]);
        foreach ($stmtMm->fetchAll() as $mmRow) {
            $mmId = (int)$mmRow['id'];
            $isOwnMm = ((int)$mmRow['user_id'] === $userId);
            $officeDocs[] = [
                'id'           => -$mmId,
                'open_url'     => 'mindmap.php?id=' . $mmId,
                'name'         => (string)$mmRow['title'],
                'ext'          => 'mindmap',
                'editor_type'  => 'mindmap',
                'folder'       => '🧠 Mind-Map-Editor',
                'group_folder' => '',
                'size'         => formatBytes(strlen((string)($mmRow['map_json'] ?? ''))),
                'date'         => date('d.m.Y H:i', strtotime((string)$mmRow['updated_at']) ?: time()),
                'owner'        => (string)($mmRow['owner_username'] ?? ''),
                'is_shared'    => !$isOwnMm,
                'can_write'    => true,
                'live_count'   => 0,
            ];
        }
    } catch (Throwable) {}

    // Eigene Ordner für die Ordner-Vorschlagsliste laden
    $stmtFolders = $pdo->prepare(
        "SELECT DISTINCT folder_path FROM files WHERE user_id = :uid AND deleted_at IS NULL AND folder_path <> '' ORDER BY folder_path ASC"
    );
    $stmtFolders->execute([':uid' => $userId]);
    $myFolders = $stmtFolders->fetchAll(PDO::FETCH_COLUMN);

    $countWord    = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'word'));
    $countSheet   = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'sheet'));
    $countSlides  = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'slides'));
    $countVisio   = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'visio'));
    $countMindmap = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'mindmap'));
    $countImage   = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'image'));
    $countText    = count(array_filter($officeDocs, static fn(array $d): bool => $d['editor_type'] === 'text'));
    $countShared  = count(array_filter($officeDocs, static fn(array $d): bool => $d['is_shared']));

    $pageTitle = '📝 Office Light – Zentrale & Vorlagen';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="space-y-6">
        <!-- Kopfbereich -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 text-2xl shrink-0">📝</span>
                    <span>MiniCloud Office Light &amp; Bild-Studio</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Word (<code>.docx</code>), Excel (<code>.xlsx</code>), PowerPoint (<code>.pptx</code>), Visio (<code>.drawio</code>), Mind-Maps, Foto-/Bildbearbeitung und Markdown direkt im Browser – 100 % lokal auf deinem Server.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="gallery.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-pink-50 dark:bg-pink-950/60 hover:bg-pink-100 text-pink-700 dark:text-pink-300 border border-pink-200/80 dark:border-pink-800 text-xs sm:text-sm font-semibold transition-colors">
                    🖼️ Foto-Galerie
                </a>
                <a href="pdf_tools.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800 text-xs sm:text-sm font-semibold transition-colors">
                    📄 PDF &amp; Scan
                </a>
                <a href="mindmap.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-violet-50 dark:bg-violet-950/60 hover:bg-violet-100 text-violet-700 dark:text-violet-300 border border-violet-200/80 dark:border-violet-800 text-xs sm:text-sm font-semibold transition-colors">
                    🧠 Mind-Map-Editor
                </a>
                <a href="index.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors">
                    📁 Dateien &rarr;
                </a>
            </div>
        </div>

        <!-- 1. Neues Dokument oder Vorlage starten -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">✨ Neues Office-Dokument, Diagramm oder Mind-Map erstellen</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Wähle eine Schnell-Vorlage für Word, Excel, PowerPoint, Visio-Flussdiagramme/Organigramme oder starte eine neue Mind-Map.</p>
                </div>
            </div>

            <!-- 12 Schnell-Vorlagen-Kacheln (Word, Excel, PowerPoint, Visio & Mind-Map) -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2.5">
                <button type="button" onclick="selectOfficePreset('docx', 'blank', 'Neues_Dokument')"
                        class="p-3 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 hover:bg-blue-100 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">📘</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-blue-700 dark:group-hover:text-blue-300">Leeres Word</div>
                    <div class="text-[10px] text-slate-500">.docx Textdokument</div>
                </button>

                <button type="button" onclick="selectOfficePreset('docx', 'letter', 'Anschreiben_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 hover:bg-blue-100 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">✉️</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-blue-700 dark:group-hover:text-blue-300">Geschäftsbrief</div>
                    <div class="text-[10px] text-slate-500">.docx Brief-Vorlage</div>
                </button>

                <button type="button" onclick="selectOfficePreset('docx', 'protocol', 'Protokoll_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 hover:bg-blue-100 dark:hover:bg-blue-900/50 border border-blue-200 dark:border-blue-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">📋</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-blue-700 dark:group-hover:text-blue-300">Protokoll</div>
                    <div class="text-[10px] text-slate-500">.docx Meeting-Notiz</div>
                </button>

                <button type="button" onclick="selectOfficePreset('xlsx', 'blank', 'Neue_Tabelle')"
                        class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">📗</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300">Leere Tabelle</div>
                    <div class="text-[10px] text-slate-500">.xlsx Kalkulation</div>
                </button>

                <button type="button" onclick="selectOfficePreset('xlsx', 'budget_xlsx', 'Budgetplan_' + new Date().getFullYear())"
                        class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">💶</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300">Budget- &amp; Kassenplan</div>
                    <div class="text-[10px] text-slate-500">.xlsx mit Formeln</div>
                </button>

                <button type="button" onclick="selectOfficePreset('xlsx', 'timesheet_xlsx', 'Zeiterfassung_' + new Date().toISOString().slice(0,7))"
                        class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">⏱️</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-emerald-700 dark:group-hover:text-emerald-300">Stundenzettel</div>
                    <div class="text-[10px] text-slate-500">.xlsx Zeiterfassung</div>
                </button>

                <button type="button" onclick="selectOfficePreset('pptx', 'blank', 'Praesentation_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-orange-50/70 dark:bg-orange-950/40 hover:bg-orange-100 dark:hover:bg-orange-900/50 border border-orange-200 dark:border-orange-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">📙</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-orange-700 dark:group-hover:text-orange-300">Präsentation</div>
                    <div class="text-[10px] text-slate-500">.pptx Folien (16:9)</div>
                </button>

                <button type="button" onclick="selectOfficePreset('pptx', 'pitch_pptx', 'Vortrag_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-orange-50/70 dark:bg-orange-950/40 hover:bg-orange-100 dark:hover:bg-orange-900/50 border border-orange-200 dark:border-orange-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">🎤</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-orange-700 dark:group-hover:text-orange-300">Vereins-/Projekt-Pitch</div>
                    <div class="text-[10px] text-slate-500">.pptx 4 Starter-Folien</div>
                </button>

                <button type="button" onclick="selectOfficePreset('drawio', 'blank', 'Diagramm_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-teal-50/70 dark:bg-teal-950/40 hover:bg-teal-100 dark:hover:bg-teal-900/50 border border-teal-200 dark:border-teal-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">📐</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-teal-700 dark:group-hover:text-teal-300">Visio-Diagramm</div>
                    <div class="text-[10px] text-slate-500">.drawio Formen &amp; Pfeile</div>
                </button>

                <button type="button" onclick="selectOfficePreset('drawio', 'orgchart_visio', 'Organigramm_' + new Date().getFullYear())"
                        class="p-3 rounded-xl bg-teal-50/70 dark:bg-teal-950/40 hover:bg-teal-100 dark:hover:bg-teal-900/50 border border-teal-200 dark:border-teal-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">🏛️</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-teal-700 dark:group-hover:text-teal-300">Organigramm</div>
                    <div class="text-[10px] text-slate-500">.drawio Vorstand / Team</div>
                </button>

                <button type="button" onclick="selectOfficePreset('drawio', 'flowchart_visio', 'Ablaufplan_' + new Date().toISOString().slice(0,10))"
                        class="p-3 rounded-xl bg-teal-50/70 dark:bg-teal-950/40 hover:bg-teal-100 dark:hover:bg-teal-900/50 border border-teal-200 dark:border-teal-800/80 text-left transition-all cursor-pointer group">
                    <div class="text-xl mb-1">🔀</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-teal-700 dark:group-hover:text-teal-300">Flussdiagramm</div>
                    <div class="text-[10px] text-slate-500">.drawio Ja/Nein-Prozess</div>
                </button>

                <a href="mindmap.php"
                   class="p-3 rounded-xl bg-violet-50/70 dark:bg-violet-950/40 hover:bg-violet-100 dark:hover:bg-violet-900/50 border border-violet-200 dark:border-violet-800/80 text-left transition-all cursor-pointer group block">
                    <div class="text-xl mb-1">🧠</div>
                    <div class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-violet-700 dark:group-hover:text-violet-300">Mind-Map</div>
                    <div class="text-[10px] text-slate-500">Ideen &amp; Strukturbaum</div>
                </a>
            </div>

            <!-- Erstellungs-Formular -->
            <form action="office.php" method="post" class="grid grid-cols-1 sm:grid-cols-12 gap-3 pt-2 items-end">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_document">
                <input type="hidden" name="template_key" id="officeHubTemplateKey" value="blank">

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dokumenttyp</label>
                    <select name="doc_type" id="officeHubDocType" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="docx">📘 Word-Dokument (.docx)</option>
                        <option value="xlsx">📗 Excel-Tabelle (.xlsx)</option>
                        <option value="pptx">📙 PowerPoint-Präsentation (.pptx)</option>
                        <option value="drawio">📐 Visio-Diagramm / Organigramm (.drawio)</option>
                        <option value="csv">📊 CSV-Tabelle (.csv)</option>
                        <option value="md">📒 Markdown (.md)</option>
                        <option value="txt">📄 Textdatei (.txt)</option>
                    </select>
                </div>

                <div class="sm:col-span-4">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Dateiname</label>
                    <input type="text" name="doc_name" id="officeHubDocName" required placeholder="z.B. Projektkonzept"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>

                <div class="sm:col-span-3">
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Ziel-Ordner (optional)</label>
                    <input type="text" name="folder_path" list="officeHubFoldersList" placeholder="Hauptverzeichnis"
                           class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    <datalist id="officeHubFoldersList">
                        <?php foreach ($myFolders as $mf): ?>
                            <option value="<?= e((string)$mf) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer">
                        ➕ Jetzt anlegen
                    </button>
                </div>
            </form>
        </section>

        <!-- 2. Alle Office-Dokumente (Filter & Live-Suche) -->
        <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" data-office-filter="all" onclick="filterOfficeHub('all')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer">
                        📂 Alle (<?= count($officeDocs) ?>)
                    </button>
                    <button type="button" data-office-filter="word" onclick="filterOfficeHub('word')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        📘 Word (<?= $countWord ?>)
                    </button>
                    <button type="button" data-office-filter="sheet" onclick="filterOfficeHub('sheet')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        📗 Tabellen (<?= $countSheet ?>)
                    </button>
                    <button type="button" data-office-filter="slides" onclick="filterOfficeHub('slides')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        📙 PowerPoint (<?= $countSlides ?>)
                    </button>
                    <button type="button" data-office-filter="visio" onclick="filterOfficeHub('visio')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        📐 Visio (<?= $countVisio ?>)
                    </button>
                    <button type="button" data-office-filter="mindmap" onclick="filterOfficeHub('mindmap')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        🧠 Mind-Maps (<?= $countMindmap ?>)
                    </button>
                    <button type="button" data-office-filter="image" onclick="filterOfficeHub('image')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        🎨 Bilder (<?= $countImage ?>)
                    </button>
                    <button type="button" data-office-filter="text" onclick="filterOfficeHub('text')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        📒 Text &amp; MD (<?= $countText ?>)
                    </button>
                    <?php if ($countShared > 0): ?>
                    <button type="button" data-office-filter="shared" onclick="filterOfficeHub('shared')"
                            class="office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">
                        👥 Mit mir geteilt (<?= $countShared ?>)
                    </button>
                    <?php endif; ?>
                </div>

                <div class="relative w-full md:w-72">
                    <span class="absolute inset-y-0 left-3 flex items-center text-slate-400 pointer-events-none text-xs">🔍</span>
                    <input type="search" id="officeHubSearchInput" oninput="applyOfficeHubFilter()" placeholder="Dokument oder Ordner suchen…"
                           class="w-full pl-8 pr-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                </div>
            </div>

            <?php if (empty($officeDocs)): ?>
                <div class="p-12 text-center space-y-2">
                    <div class="text-3xl">📝</div>
                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Noch keine Office-Dokumente vorhanden</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Wähle oben eine Vorlage aus, um dein erstes Word-, Excel-, PowerPoint-, Visio- oder Mind-Map-Dokument anzulegen.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                                <th class="py-3 px-3 sm:px-5">Dokument</th>
                                <th class="py-3 px-4 hidden md:table-cell">Ordner</th>
                                <th class="py-3 px-4 hidden sm:table-cell">Größe</th>
                                <th class="py-3 px-4 hidden lg:table-cell">Zuletzt geändert</th>
                                <th class="py-3 px-3 sm:px-5 text-right">Aktion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800">
                            <?php foreach ($officeDocs as $doc): ?>
                                <?php
                                if ($doc['editor_type'] === 'mindmap') {
                                    $badge = ['label' => 'MIND', 'bg' => 'bg-violet-100 dark:bg-violet-950/70', 'text' => 'text-violet-700 dark:text-violet-300'];
                                } else {
                                    $badge = getFileTypeBadge($doc['name'], '');
                                }
                                $docHref = $doc['open_url'] ?? ('office.php?id=' . (int)$doc['id']);
                                $searchHay = mb_strtolower($doc['name'] . ' ' . $doc['folder'] . ' ' . $doc['owner'] . ' ' . $doc['ext'], 'UTF-8');
                                ?>
                                <tr data-office-doc-row="1"
                                    data-doc-type="<?= e($doc['editor_type']) ?>"
                                    data-doc-shared="<?= $doc['is_shared'] ? '1' : '0' ?>"
                                    data-doc-search="<?= e($searchHay) ?>"
                                    class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                    <td class="py-3 px-3 sm:px-5 max-w-[165px] sm:max-w-none overflow-hidden">
                                        <div class="flex items-center gap-2.5 sm:gap-3 min-w-0">
                                            <span class="inline-flex items-center justify-center w-9 h-8 rounded-lg text-[10px] font-bold <?= e($badge['bg']) ?> <?= e($badge['text']) ?> shrink-0">
                                                <?= e($badge['label']) ?>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                                    <a href="<?= e($docHref) ?>"
                                                       class="font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-[125px] sm:max-w-xs md:max-w-md block">
                                                        <?= e($doc['name']) ?>
                                                    </a>
                                                    <?php if ($doc['live_count'] > 0): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 animate-pulse">
                                                            🟢 <?= (int)$doc['live_count'] ?> live
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($doc['is_shared']): ?>
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                                                            👤 von <?= e($doc['owner']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-[11px] text-slate-400 sm:hidden truncate max-w-[125px]">
                                                    <?= e($doc['size']) ?> &bull; <?= e($doc['date']) ?>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 hidden md:table-cell text-xs text-slate-600 dark:text-slate-300">
                                        <?php if ($doc['editor_type'] === 'mindmap'): ?>
                                            <a href="mindmap.php" class="hover:text-brand-600 hover:underline">🧠 Mind-Map-Editor</a>
                                        <?php elseif ($doc['group_folder'] !== ''): ?>
                                            👥 <?= e($doc['group_folder']) ?><?= $doc['folder'] !== '' ? ' / ' . e($doc['folder']) : '' ?>
                                        <?php elseif ($doc['folder'] !== ''): ?>
                                            <a href="index.php?folder=<?= rawurlencode($doc['folder']) ?>" class="hover:text-brand-600 hover:underline">📁 <?= e($doc['folder']) ?></a>
                                        <?php else: ?>
                                            🏠 Hauptverzeichnis
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 hidden sm:table-cell text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                        <?= e($doc['size']) ?>
                                    </td>
                                    <td class="py-3 px-4 hidden lg:table-cell text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                        <?= e($doc['date']) ?> Uhr
                                    </td>
                                    <td class="py-3 px-3 sm:px-5 text-right whitespace-nowrap">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <a href="<?= e($docHref) ?>"
                                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-2xs transition-colors">
                                                <span><?= $doc['can_write'] ? '✏️ Bearbeiten' : '👁️ Öffnen' ?></span>
                                            </a>
                                            <?php if ($doc['editor_type'] !== 'mindmap'): ?>
                                                <a href="download.php?id=<?= (int)$doc['id'] ?>"
                                                   class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 text-xs transition-colors"
                                                   title="Herunterladen">
                                                    ⬇️
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
    let currentOfficeFilter = 'all';

    function selectOfficePreset(docType, templateKey, suggestedName) {
        const typeEl = document.getElementById('officeHubDocType');
        const tplEl  = document.getElementById('officeHubTemplateKey');
        const nameEl = document.getElementById('officeHubDocName');
        if (typeEl) typeEl.value = docType;
        if (tplEl) tplEl.value = templateKey;
        if (nameEl) {
            nameEl.value = suggestedName;
            nameEl.focus();
            nameEl.select();
        }
    }

    function filterOfficeHub(mode) {
        currentOfficeFilter = mode;
        document.querySelectorAll('.office-filter-btn').forEach((btn) => {
            const act = btn.getAttribute('data-office-filter') === mode;
            btn.className = act
                ? 'office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 text-white cursor-pointer'
                : 'office-filter-btn px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer';
        });
        applyOfficeHubFilter();
    }

    function applyOfficeHubFilter() {
        const q = (document.getElementById('officeHubSearchInput')?.value || '').trim().toLowerCase();
        document.querySelectorAll('tr[data-office-doc-row="1"]').forEach((tr) => {
            const dType   = tr.getAttribute('data-doc-type') || '';
            const dShared = tr.getAttribute('data-doc-shared') === '1';
            const dSearch = (tr.getAttribute('data-doc-search') || '').toLowerCase();

            let typeMatch = true;
            if (currentOfficeFilter === 'word') typeMatch = (dType === 'word');
            else if (currentOfficeFilter === 'sheet') typeMatch = (dType === 'sheet');
            else if (currentOfficeFilter === 'slides') typeMatch = (dType === 'slides');
            else if (currentOfficeFilter === 'visio') typeMatch = (dType === 'visio');
            else if (currentOfficeFilter === 'mindmap') typeMatch = (dType === 'mindmap');
            else if (currentOfficeFilter === 'image') typeMatch = (dType === 'image');
            else if (currentOfficeFilter === 'text') typeMatch = (dType === 'text');
            else if (currentOfficeFilter === 'shared') typeMatch = dShared;

            const textMatch = q === '' || dSearch.includes(q);
            tr.classList.toggle('hidden', !(typeMatch && textMatch));
        });
    }
    </script>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$access = resolveOfficeFileAccess($pdo, $fileId, $userId, $isAdmin);

if ($access === null) {
    setFlash('error', 'Die gewünschte Datei wurde nicht gefunden oder Sie besitzen keine Leseberechtigung.');
    header('Location: index.php');
    exit;
}

$file     = $access['file'];
$canEdit  = $access['can_write'];
$origName = (string)$file['original_name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$docTitleWithoutExt = pathinfo($origName, PATHINFO_FILENAME);
$editorType = getOfficeEditorType($origName);

if ($editorType === null) {
    setFlash('error', 'Dieser Dateityp (' . $ext . ') wird von MiniCloud Office Light nicht direkt bearbeitet.');
    header('Location: index.php');
    exit;
}

// Rücksprung-URL zum jeweiligen Ordner zusammenbauen
$returnQuery = [];
if (!empty($_GET['return_sf'])) {
    $returnQuery['shared_folder'] = (int)$_GET['return_sf'];
} elseif (!empty($_GET['return_gf'])) {
    $returnQuery['group_folder'] = (int)$_GET['return_gf'];
} elseif (!empty($file['group_folder_id'])) {
    $returnQuery['group_folder'] = (int)$file['group_folder_id'];
}
$returnFolder = isset($_GET['return_folder']) ? trim((string)$_GET['return_folder']) : (string)$file['folder_path'];
if ($returnFolder !== '' && empty($returnQuery['shared_folder'])) {
    $returnQuery['folder'] = $returnFolder;
}
$backFromGallery = !empty($_GET['return_gallery']);
$backUrl = $backFromGallery
    ? ('gallery.php' . (!empty($_GET['return_album']) ? '?album=' . (int)$_GET['return_album'] : ''))
    : ('index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : ''));

$storedFilename = basename((string)$file['filename']);
$physicalPath   = UPLOAD_DIR . $storedFilename;
$isVaultLocked  = ((int)($file['is_encrypted'] ?? 0) === 1) || (is_file($physicalPath) && isVaultEncryptedFile($physicalPath));
$canShareFile   = ((int)$file['user_id'] === $userId)
    || (!empty($file['group_folder_id']) && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], true, $isAdmin));
$shareablePeers = ($canShareFile && !$isVaultLocked) ? getVisibleUsersForUser($pdo, $userId) : [];

// Inhalt je nach Editor-Typ laden
$wordHtmlContent  = '';
$sheetsData       = [];
$slidesDeckData   = ['theme' => 'light', 'transition' => 'fade', 'slides' => []];
$visioDiagramData = ['theme' => 'light', 'grid' => true, 'nodes' => [], 'edges' => []];
$cloudImagesList  = [];
$textContent      = '';

if (!$isVaultLocked && is_file($physicalPath)) {
    if ($editorType === 'word') {
        $wordHtmlContent = readDocxAsHtml($physicalPath);
    } elseif ($editorType === 'sheet') {
        $sheetsData = readSpreadsheetData($physicalPath, $origName);
    } elseif ($editorType === 'slides') {
        $slidesDeckData = readPptxData($physicalPath);
        // Eigene Cloud-Bilder für den Bild-Wähler in PowerPoint Light bereitstellen
        $stmtImgs = $pdo->prepare(
            "SELECT id, original_name, folder_path
             FROM files
             WHERE user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0
               AND (LOWER(original_name) REGEXP '\\\\.(jpg|jpeg|png|gif|webp)$' OR mime_type LIKE 'image/%')
             ORDER BY upload_date DESC LIMIT 100"
        );
        $stmtImgs->execute([':uid' => $userId]);
        $cloudImagesList = $stmtImgs->fetchAll();
    } elseif ($editorType === 'visio') {
        $visioDiagramData = readVisioData($physicalPath);
    } else {
        $textContent = (string)@file_get_contents($physicalPath);
    }
}

$pageTitle = $origName . ' – Office Light';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Top-Leiste: Datei-Info, Titel-Bearbeitung & Speichern -->
<div class="mb-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs">
    <div class="flex flex-wrap items-center gap-2.5 min-w-0 flex-1">
        <a
            href="<?= e($backUrl) ?>"
            id="btnBackToCloud"
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors shrink-0"
            title="<?= $backFromGallery ? 'Zurück zur Foto-Galerie' : 'Zurück zur Dateiliste' ?>"
        >
            &larr; <?= $backFromGallery ? '🖼️ Galerie' : 'Dateien' ?>
        </a>
        <a
            href="office.php"
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 text-xs sm:text-sm font-semibold transition-colors shrink-0"
            title="Zur Office Light Start-Zentrale"
        >
            📝 Office-Zentrale
        </a>

        <?php
        $typeBadge = match ($editorType) {
            'word'   => ['icon' => '📄', 'label' => 'Word Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800'],
            'sheet'  => ['icon' => '📊', 'label' => 'Calc Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'],
            'slides' => ['icon' => '📙', 'label' => 'PowerPoint Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300 border-orange-200 dark:border-orange-800'],
            'visio'  => ['icon' => '📐', 'label' => 'Visio Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 border-teal-200 dark:border-teal-800'],
            'image'  => ['icon' => '🎨', 'label' => 'Foto- & Bild-Studio (.' . strtoupper($ext) . ')', 'cls' => 'bg-pink-100 dark:bg-pink-950 text-pink-700 dark:text-pink-300 border-pink-200 dark:border-pink-800'],
            default  => ['icon' => '📝', 'label' => 'Text-Editor (.' . strtoupper($ext) . ')', 'cls' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700'],
        };
        ?>
        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl text-xs font-bold border <?= $typeBadge['cls'] ?> shrink-0">
            <span><?= $typeBadge['icon'] ?></span>
            <span><?= e($typeBadge['label']) ?></span>
        </span>

        <?php if ($canEdit && !$isVaultLocked): ?>
            <div class="flex items-center gap-1 min-w-0 flex-1 max-w-md">
                <input
                    type="text"
                    id="officeDocTitle"
                    value="<?= e($docTitleWithoutExt) ?>"
                    aria-label="Dateiname"
                    title="Dateiname bearbeiten"
                    class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-sm font-bold focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <span class="text-xs font-mono font-bold text-slate-400 dark:text-slate-500 shrink-0">.<?= e($ext) ?></span>
            </div>
        <?php else: ?>
            <h1 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                <?= e($origName) ?>
            </h1>
            <span class="px-2 py-0.5 rounded-lg bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 text-xs font-semibold">
                👁️ Schreibgeschützt (Nur Lesen)
            </span>
        <?php endif; ?>
    </div>

    <div class="flex flex-wrap items-center justify-between lg:justify-end gap-2.5 shrink-0">
        <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2">
            <span id="officeStatusText" class="font-medium text-emerald-600 dark:text-emerald-400">
                Bereit (<?= e(formatBytes((int)$file['file_size'])) ?>)
            </span>
        </div>

        <a
            href="download.php?id=<?= (int)$file['id'] ?>"
            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 border border-sky-200/70 dark:border-sky-800/60 text-xs sm:text-sm font-semibold transition-colors"
            title="Originaldatei herunterladen"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
            </svg>
            <span class="hidden sm:inline">Herunterladen</span>
        </a>

        <?php if ($canShareFile && !$isVaultLocked): ?>
            <button
                type="button"
                onclick="openOfficeShareModal()"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/60 text-xs sm:text-sm font-bold transition-colors cursor-pointer"
                title="Dokument für andere Cloud-Mitglieder (Live-Team) oder per öffentlichem Link / QR-Code freigeben"
            >
                <span>🔗</span>
                <span>Freigeben</span>
            </button>
        <?php endif; ?>

        <?php if ($canEdit && !$isVaultLocked): ?>
            <?php if ($editorType === 'image'): ?>
                <select id="imgStudioSaveMode" class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200" title="Wähle, ob das Originalbild (mit 🕓 Versionsschutz) aktualisiert oder eine neue Datei im Ordner angelegt wird">
                    <option value="overwrite">💾 Original aktualisieren (mit 🕓 Versionsschutz)</option>
                    <option value="copy">📄 Als neue Kopie speichern</option>
                </select>
            <?php else: ?>
                <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer select-none" title="Speichert Änderungen bei Team-Bearbeitung nach wenigen Sekunden automatisch in die Datei">
                    <input type="checkbox" id="chkOfficeAutoSave" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>⚡ Auto-Save</span>
                </label>
            <?php endif; ?>

            <button
                type="button"
                id="btnSaveOffice"
                onclick="saveOfficeDocument()"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors cursor-pointer"
                title="Änderungen direkt in der Cloud speichern (Strg+S)"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <span>Speichern (Strg+S)</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isVaultLocked): ?>
<!-- Live-Team-Leiste (Stufe 1: Wer ist im Dokument + Konfliktfreie Absatz-/Zellen-Sperre) -->
<div id="collabPresenceBar" class="mb-4 px-3.5 py-2.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xs flex flex-wrap items-center justify-between gap-2 text-xs">
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/70 font-bold">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <span id="collabCountLabel">Live-Team (1 aktiv)</span>
        </span>
        <div id="collabUsersList" class="flex flex-wrap items-center gap-1.5">
            <span class="text-slate-400 dark:text-slate-500">Verbinde Live-Synchronisation...</span>
        </div>
    </div>
    <div id="collabLockHint" class="text-[11px] text-slate-400 dark:text-slate-500">
        <?= $editorType === 'sheet'
            ? '🛡️ Konfliktschutz aktiv: Zellen anderer Bearbeiter werden live markiert &amp; geschützt'
            : ($editorType === 'word'
                ? '🛡️ Konfliktschutz aktiv: Absätze anderer Bearbeiter werden beim Tippen live gesperrt &amp; synchronisiert'
                : ($editorType === 'slides'
                    ? '🛡️ Konfliktschutz aktiv: Folien anderer Bearbeiter werden live markiert &amp; synchronisiert'
                    : '🛡️ Live-Synchronisation für Text-/Markdown-Zeilen aktiv')) ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isVaultLocked): ?>
    <!-- Hinweis bei Tresor-geschützten Dateien -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-300 dark:border-amber-800 p-6 sm:p-8 text-center max-w-xl mx-auto my-8 space-y-4">
        <div class="w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 flex items-center justify-center mx-auto text-2xl">
            🔒
        </div>
        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Datei ist im Tresor verschlüsselt (Zero-Knowledge)</h2>
        <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            Diese Datei ist mit einem persönlichen Tresor-Passwort (AES-256-GCM) geschützt. Damit Ihr Tresor-Passwort niemals dauerhaft auf dem Server zwischengespeichert werden muss, heben Sie zum Online-Bearbeiten bitte zuerst in der Dateiliste über das <strong>🔒 Schloss-Symbol</strong> den Tresor-Schutz auf (und aktivieren Sie ihn nach der Bearbeitung wieder).
        </p>
        <div>
            <a href="<?= e($backUrl) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold">
                &larr; Zurück zur Dateiliste
            </a>
        </div>
    </div>

<?php elseif ($editorType === 'word'): ?>
    <!-- ================================================================== -->
    <!-- 1. WORD LIGHT EDITOR (.DOCX)                                       -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <?php if ($canEdit): ?>
            <!-- Formatierungs-Toolbar -->
            <div class="p-2.5 sm:p-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/60 flex flex-wrap items-center gap-1.5">
                <select
                    id="wordBlockFormat"
                    onchange="applyWordBlock(this.value); this.value='';"
                    aria-label="Absatzformat"
                    class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer"
                >
                    <option value="">Absatzstil...</option>
                    <option value="P">Normaler Absatz</option>
                    <option value="H1">Überschrift 1 (Groß)</option>
                    <option value="H2">Überschrift 2 (Mittel)</option>
                    <option value="H3">Überschrift 3 (Klein)</option>
                    <option value="BLOCKQUOTE">Zitat-Block</option>
                </select>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                <button type="button" onclick="execWordCmd('bold')" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 font-bold text-xs text-slate-800 dark:text-slate-200 cursor-pointer" title="Fett (Strg+B)">B</button>
                <button type="button" onclick="execWordCmd('italic')" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 italic font-serif text-xs text-slate-800 dark:text-slate-200 cursor-pointer" title="Kursiv (Strg+I)">I</button>
                <button type="button" onclick="execWordCmd('underline')" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 underline text-xs text-slate-800 dark:text-slate-200 cursor-pointer" title="Unterstrichen (Strg+U)">U</button>
                <button type="button" onclick="execWordCmd('strikeThrough')" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 line-through text-xs text-slate-800 dark:text-slate-200 cursor-pointer" title="Durchgestrichen">S</button>

                <label class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 cursor-pointer" title="Textfarbe wählen">
                    <input type="color" value="#1e293b" onchange="execWordCmd('foreColor', this.value)" class="w-5 h-5 border-0 p-0 bg-transparent cursor-pointer">
                </label>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                <button type="button" onclick="execWordCmd('justifyLeft')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Linksbündig">Links</button>
                <button type="button" onclick="execWordCmd('justifyCenter')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Zentriert">Mitte</button>
                <button type="button" onclick="execWordCmd('justifyRight')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Rechtsbündig">Rechts</button>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                <button type="button" onclick="execWordCmd('insertUnorderedList')" class="px-2.5 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer" title="Aufzählungsliste">• Liste</button>
                <button type="button" onclick="execWordCmd('insertOrderedList')" class="px-2.5 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer" title="Nummerierte Liste">1. Liste</button>

                <button type="button" onclick="insertWordTable()" class="px-2.5 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer" title="Tabelle einfügen">+ Tabelle</button>
                <button type="button" onclick="execWordCmd('insertHorizontalRule')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Trennlinie einfügen">― Linie</button>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                <button type="button" onclick="execWordCmd('undo')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Rückgängig">↶</button>
                <button type="button" onclick="execWordCmd('redo')" class="px-2 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Wiederholen">↷</button>
                <button type="button" onclick="printWordDocument()" class="px-2.5 h-8 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer ml-auto" title="Drucken oder als PDF speichern">🖨️ Drucken / PDF</button>
            </div>
        <?php endif; ?>

        <!-- DIN-A4-artiger Dokumentenhintergrund -->
        <div class="bg-slate-100 dark:bg-slate-950/70 p-3 sm:p-6 md:p-8 min-h-[65vh]">
            <div
                id="wordEditorSurface"
                contenteditable="<?= $canEdit ? 'true' : 'false' ?>"
                spellcheck="true"
                class="max-w-4xl mx-auto min-h-[60vh] bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 sm:p-10 md:p-12 focus:outline-none focus:ring-2 focus:ring-brand-500/50 leading-relaxed text-sm sm:text-base"
            ><?= $wordHtmlContent ?></div>
        </div>

        <!-- Fußzeile mit Wort- & Zeichenzähler -->
        <div class="px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span id="wordCountStats">0 Wörter &bull; 0 Zeichen</span>
            <span>Format: OpenXML Word (<code>.docx</code>) &bull; Kompatibel mit Microsoft Word &amp; LibreOffice</span>
        </div>
    </div>

    <style>
        #wordEditorSurface h1 { font-size: 1.75rem; font-weight: 700; margin: 0.75rem 0 0.5rem; line-height: 1.25; }
        #wordEditorSurface h2 { font-size: 1.35rem; font-weight: 700; margin: 0.65rem 0 0.4rem; line-height: 1.3; }
        #wordEditorSurface h3 { font-size: 1.15rem; font-weight: 700; margin: 0.5rem 0 0.35rem; }
        #wordEditorSurface p { margin: 0 0 0.65rem; }
        #wordEditorSurface ul { list-style-type: disc; padding-left: 1.75rem; margin: 0 0 0.75rem; }
        #wordEditorSurface ol { list-style-type: decimal; padding-left: 1.75rem; margin: 0 0 0.75rem; }
        #wordEditorSurface blockquote { border-left: 4px solid #3b82f6; padding-left: 1rem; font-style: italic; margin: 0.75rem 0; color: #64748b; }
        #wordEditorSurface table { width: 100%; border-collapse: collapse; margin: 0.85rem 0; }
        #wordEditorSurface th, #wordEditorSurface td { border: 1px solid #cbd5e1; padding: 0.5rem 0.65rem; text-align: left; min-width: 60px; }
        #wordEditorSurface th { background-color: rgba(148, 163, 184, 0.15); font-weight: 700; }
        /* Stufe-1 Live-Absatz-Sperre für fremde Bearbeiter */
        #wordEditorSurface [data-locked-by] {
            position: relative;
            border-left: 3px solid var(--peer-color, #e11d48) !important;
            padding-left: 0.6rem !important;
            background-color: rgba(244, 63, 94, 0.06);
            border-radius: 0.375rem;
            transition: background-color 0.2s;
        }
        #wordEditorSurface [data-locked-by]::after {
            content: "🔒 " attr(data-locked-by) " bearbeitet diesen Absatz";
            position: absolute;
            right: 0.35rem;
            top: -1.15rem;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 9999px;
            background-color: var(--peer-color, #e11d48);
            color: #ffffff;
            pointer-events: none;
            white-space: nowrap;
            box-shadow: 0 1px 2px rgba(0,0,0,0.15);
        }
    </style>

<?php elseif ($editorType === 'sheet'): ?>
    <!-- ================================================================== -->
    <!-- 2. CALC / EXCEL LIGHT EDITOR (.XLSX & .CSV)                        -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <!-- Toolbar & Formelleiste -->
        <div class="p-2.5 sm:p-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/60 space-y-2">
            <?php if ($canEdit): ?>
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" onclick="sheetAddRow()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">+ Zeile</button>
                    <button type="button" onclick="sheetDeleteLastRow()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">- Zeile</button>
                    <button type="button" onclick="sheetAddCol()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">+ Spalte</button>
                    <button type="button" onclick="sheetDeleteLastCol()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">- Spalte</button>

                    <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                    <button type="button" onclick="insertFormulaTemplate('=SUMME(A1:A5)')" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold cursor-pointer" title="Summenformel in aktive Zelle einfügen">Σ Summe</button>
                    <button type="button" onclick="insertFormulaTemplate('=MITTELWERT(A1:A5)')" class="px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold cursor-pointer" title="Mittelwert (Durchschnitt) einfügen">Ø Mittelwert</button>
                    <button type="button" onclick="insertFormulaTemplate('=MIN(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Kleinsten Wert im Bereich finden">Min</button>
                    <button type="button" onclick="insertFormulaTemplate('=MAX(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Größten Wert im Bereich finden">Max</button>
                    <button type="button" onclick="insertFormulaTemplate('=ANZAHL(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Anzahl der Zahlenzellen zählen">Anzahl</button>
                    <button type="button" onclick="insertFormulaTemplate('=RUNDEN(A1;2)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Auf Nachkommastellen runden">Runden</button>
                    <button type="button" onclick="insertFormulaTemplate('=WENN(A1>=0; &quot;OK&quot;; &quot;Prüfen&quot;)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer" title="Wenn-Dann-Sonst Bedingung einfügen">Wenn</button>
                    <button type="button" onclick="document.getElementById('sheetFormulaHelpBox')?.classList.toggle('hidden')" class="px-2.5 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-semibold cursor-pointer" title="Alle verfügbaren Formeln &amp; Rechenbeispiele anzeigen">ƒx Alle Formeln</button>

                    <button type="button" onclick="exportActiveSheetCsv()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer ml-auto" title="Aktuelles Tabellenblatt als CSV herunterladen">📥 Als CSV exportieren</button>
                </div>

                <!-- Aufklappbare Formel-Kurzreferenz -->
                <div id="sheetFormulaHelpBox" class="hidden p-3 rounded-xl bg-white dark:bg-slate-900 border border-indigo-200 dark:border-indigo-800/80 text-xs space-y-2 shadow-2xs">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-indigo-700 dark:text-indigo-300">📊 Verfügbare Formeln in Excel Light (Klick fügt Beispiel in aktive Zelle ein):</span>
                        <button type="button" onclick="document.getElementById('sheetFormulaHelpBox').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 cursor-pointer font-bold">✕</button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5 text-[11px]">
                        <button type="button" onclick="insertFormulaTemplate('=SUMME(A1:A10)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=SUMME(A1:A10)</code> / <code>=SUM(...)</code> · Summe</button>
                        <button type="button" onclick="insertFormulaTemplate('=MITTELWERT(A1:A10)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=MITTELWERT(A1:A10)</code> / <code>=AVG(...)</code> · Durchschnitt</button>
                        <button type="button" onclick="insertFormulaTemplate('=MIN(A1:A10)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=MIN(A1:A10)</code> · Kleinster Wert</button>
                        <button type="button" onclick="insertFormulaTemplate('=MAX(A1:A10)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=MAX(A1:A10)</code> · Größter Wert</button>
                        <button type="button" onclick="insertFormulaTemplate('=ANZAHL(A1:A10)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=ANZAHL(A1:A10)</code> / <code>=COUNT(...)</code> · Anzahl Zahlen</button>
                        <button type="button" onclick="insertFormulaTemplate('=PRODUKT(A1:A5)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=PRODUKT(A1:A5)</code> · Multipliziert Bereich</button>
                        <button type="button" onclick="insertFormulaTemplate('=RUNDEN(A1*1,19; 2)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=RUNDEN(Wert; Stellen)</code> · Auf Dezimalstellen runden</button>
                        <button type="button" onclick="insertFormulaTemplate('=WENN(A1>=100; &quot;Ziel erreicht&quot;; &quot;Offen&quot;)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=WENN(Bed.; Dann; Sonst)</code> · Bedingung (<code>IF</code>)</button>
                        <button type="button" onclick="insertFormulaTemplate('=ABS(A1)')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=ABS(A1)</code> &amp; <code>=WURZEL(A1)</code> · Betrag / Wurzel</button>
                        <button type="button" onclick="insertFormulaTemplate('=HEUTE()')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=HEUTE()</code> / <code>=TODAY()</code> · Aktuelles Datum</button>
                        <button type="button" onclick="insertFormulaTemplate('=(A1+B1)*1,19')" class="text-left p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"><code>=(A1+B1)*1,19</code> · Grundrechenarten <code>+ - * / ( )</code></button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Formelleiste (fx) -->
            <div class="flex items-center gap-2">
                <span id="activeCellRefBadge" class="inline-flex items-center justify-center w-14 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-900 dark:text-white font-mono text-xs font-bold shrink-0">
                    A1
                </span>
                <span class="text-xs font-serif italic font-bold text-slate-500 dark:text-slate-400 px-1">fx</span>
                <input
                    type="text"
                    id="formulaInputBar"
                    <?= $canEdit ? '' : 'readonly' ?>
                    placeholder="Wert oder Formel eingeben (z.B. =SUMME(A1:A10), =MITTELWERT(B1:B5), =A1*B1)..."
                    class="flex-1 px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-mono text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>
        </div>

        <!-- Tabellen-Grid (Scroll-Container) -->
        <div class="overflow-auto max-h-[62vh] bg-slate-100 dark:bg-slate-950">
            <table id="spreadsheetGridTable" class="border-collapse bg-white dark:bg-slate-900 text-xs sm:text-sm select-none">
                <thead id="spreadsheetThead" class="sticky top-0 z-10 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300"></thead>
                <tbody id="spreadsheetTbody"></tbody>
            </table>
        </div>

        <!-- Unten: Tabellenblätter-Reiter (Sheet-Tabs) & Auswahl-Summe -->
        <div class="px-3 py-2 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5" id="sheetTabsBar">
                <!-- Dynamisch per JS gerendert -->
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                <span id="sheetCellSummary">Tipp: Doppelklick auf Reiter zum Umbenennen</span>
            </div>
        </div>
    </div>

<?php elseif ($editorType === 'slides'): ?>
    <!-- ================================================================== -->
    <!-- 2b. POWERPOINT LIGHT EDITOR (.PPTX)                                -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <!-- Obere Werkzeugleiste: Folien-Aktionen, Layout, Farbdesign & Beamer-Start -->
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/60 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <?php if ($canEdit): ?>
                    <button type="button" onclick="pptxAddSlide()"
                            class="px-3 py-1.5 rounded-xl bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold shadow-2xs transition cursor-pointer">
                        ➕ Neue Folie
                    </button>
                    <button type="button" onclick="pptxDuplicateSlide()"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer" title="Aktuelle Folie duplizieren">
                        📄 Duplizieren
                    </button>
                    <button type="button" onclick="pptxMoveSlide(-1)"
                            class="px-2 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer" title="Folie nach oben">
                        ↑
                    </button>
                    <button type="button" onclick="pptxMoveSlide(1)"
                            class="px-2 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer" title="Folie nach unten">
                        ↓
                    </button>
                    <button type="button" onclick="pptxDeleteSlide()"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-red-50 dark:hover:bg-red-950/50 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-red-600 dark:text-red-400 cursor-pointer" title="Aktuelle Folie löschen">
                        🗑️
                    </button>

                    <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                    <label class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">Layout:</label>
                    <select id="pptxSlideLayoutSelect" onchange="pptxChangeLayout(this.value)"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <option value="title">🎯 Titelfolie (Zentriert)</option>
                        <option value="bullets">📋 Titel + Aufzählung / Text</option>
                        <option value="two_col">⚖️ Zwei Spalten (Vergleich)</option>
                        <option value="image_text">🖼️ Cloud-Bild + Text</option>
                        <option value="big_stat">🚀 Große Kennzahl / Zitat</option>
                        <option value="section">🔖 Kapitel-Trennfolie</option>
                    </select>

                    <label class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 ml-1">Design:</label>
                    <select id="pptxDeckThemeSelect" onchange="pptxChangeTheme(this.value)"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <option value="light">☀️ Klassisch Hell</option>
                        <option value="navy">🌌 Business Dunkelblau</option>
                        <option value="emerald">🌲 Natur Smaragd</option>
                        <option value="amber">🏛️ Warm Gold / Verein</option>
                        <option value="rose">🌸 Modern Rosé</option>
                        <option value="slate">🖤 Obsidian Dunkel</option>
                    </select>

                    <select id="pptxTransitionSelect" onchange="pptxChangeTransition(this.value)"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200" title="Folienübergang bei Präsentation">
                        <option value="fade">✨ Überblenden</option>
                        <option value="slide">➡️ Gleiten</option>
                        <option value="zoom">🔍 Zoom</option>
                        <option value="none">⚡ Direkt</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="pptxPrintHandout()"
                        class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                    🖨️ Handout / PDF
                </button>
                <button type="button" onclick="pptxStartPresentation(0)"
                        class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition cursor-pointer flex items-center gap-1.5">
                    ▶️ Präsentation starten
                </button>
            </div>
        </div>

        <!-- Zweispaltiger Hauptbereich: Links Folien-Miniaturen, Rechts 16:9 Folien-Bühne + Sprechernotizen -->
        <div class="grid grid-cols-1 lg:grid-cols-12 min-h-[64vh]">
            <!-- Linke Seitenleiste: Folien-Übersicht -->
            <aside class="lg:col-span-3 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/50 p-3 overflow-y-auto max-h-[68vh] space-y-2" id="pptxThumbnailsList">
                <!-- Dynamisch per JS gerendert -->
            </aside>

            <!-- Rechte Hauptspalte: 16:9 Folien-Editor & Sprechernotizen -->
            <div class="lg:col-span-9 bg-slate-100 dark:bg-slate-950/80 p-4 sm:p-6 flex flex-col justify-between gap-4">
                <!-- 16:9 Live-Vorschau & Eingabefelder -->
                <div id="pptxStageContainer" class="w-full max-w-4xl mx-auto aspect-16/9 rounded-2xl shadow-lg border border-slate-300/80 dark:border-slate-700 p-6 sm:p-10 flex flex-col justify-between transition-colors relative overflow-hidden">
                    <!-- Dynamisch per JS gerendert -->
                </div>

                <!-- Bild-Auswahlleiste (nur sichtbar bei Layout "image_text") -->
                <div id="pptxImagePickerBar" class="hidden max-w-4xl w-full mx-auto p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">🖼️ Cloud-Bild für diese Folie auswählen:</span>
                    <select id="pptxCloudImageSelect" onchange="pptxSelectCloudImage(this.value)"
                            class="flex-1 min-w-[200px] px-3 py-1.5 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs">
                        <option value="0">— Kein Bild ausgewählt —</option>
                        <?php foreach ($cloudImagesList as $cImg): ?>
                            <option value="<?= (int)$cImg['id'] ?>">
                                📷 <?= e((string)$cImg['original_name']) ?><?= !empty($cImg['folder_path']) ? (' (' . e((string)$cImg['folder_path']) . ')') : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sprechernotizen (Speaker Notes) -->
                <div class="max-w-4xl w-full mx-auto bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-3 space-y-1.5">
                    <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                        <span class="font-semibold">🎙️ Sprechernotizen für diese Folie (nur für Vortragende &amp; im Handout):</span>
                        <span id="pptxSlideCounterLabel">Folie 1 / 1</span>
                    </div>
                    <textarea id="pptxSpeakerNotesInput" rows="2" <?= $canEdit ? '' : 'readonly' ?>
                              placeholder="Notizen, Stichworte oder Regieanweisungen für deinen Vortrag eingeben…"
                              class="w-full px-3 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 text-xs sm:text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                </div>
            </div>
        </div>

        <div class="px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span>Format: OpenXML PowerPoint (<code>.pptx</code>, 16:9 Widescreen)</span>
            <span>Kompatibel mit Microsoft PowerPoint, LibreOffice Impress &amp; Apple Keynote</span>
        </div>
    </div>

    <!-- Vollbild-Präsentations-Modus (Beamer-Ansicht mit Laserpointer) -->
    <div id="pptxPresenterModal" class="hidden fixed inset-0 z-50 bg-black flex flex-col justify-between select-none">
        <div class="flex items-center justify-between px-5 py-3 bg-black/60 border-b border-white/10 text-white text-xs">
            <div class="flex items-center gap-3">
                <span class="font-bold text-sm"><?= e($docTitleWithoutExt) ?></span>
                <span id="pptxPresCounter" class="px-2.5 py-0.5 rounded-full bg-white/15 font-semibold">1 / 1</span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="btnToggleLaser" onclick="pptxToggleLaser()"
                        class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 font-semibold transition cursor-pointer">
                    🔴 Laserpointer (Taste L)
                </button>
                <button type="button" onclick="pptxStepPresentation(-1)"
                        class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 font-bold cursor-pointer">
                    ← Zurück
                </button>
                <button type="button" onclick="pptxStepPresentation(1)"
                        class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-500 font-bold cursor-pointer">
                    Weiter →
                </button>
                <button type="button" onclick="pptxStopPresentation()"
                        class="px-3 py-1.5 rounded-xl bg-red-600/80 hover:bg-red-600 font-bold cursor-pointer">
                    ✕ Beenden (Esc)
                </button>
            </div>
        </div>

        <div id="pptxPresViewport" class="relative flex-1 flex items-center justify-center p-4 sm:p-10 overflow-hidden" onmousemove="pptxMoveLaser(event)">
            <div id="pptxPresSlideBox" class="w-full max-w-6xl aspect-16/9 rounded-2xl shadow-2xl p-8 sm:p-14 flex flex-col justify-between transition-all duration-300 relative overflow-hidden"></div>
            <div id="pptxLaserDot" class="hidden fixed w-4 h-4 rounded-full bg-red-600 shadow-[0_0_16px_6px_rgba(239,68,68,0.85)] pointer-events-none z-50 -translate-x-1/2 -translate-y-1/2"></div>
        </div>

        <div class="px-5 py-2.5 bg-black/60 border-t border-white/10 text-xs text-slate-300 flex items-center justify-between gap-4">
            <span id="pptxPresNotesHint" class="truncate text-slate-400">🎙️ Sprechernotiz: –</span>
            <span class="hidden sm:inline text-slate-500 shrink-0">Pfeiltasten / Leertaste = Weiter · L = Laserpointer · Esc = Beenden</span>
        </div>
    </div>

<?php elseif ($editorType === 'visio'): ?>
    <!-- ================================================================== -->
    <!-- 2c. VISIO LIGHT EDITOR (.DRAWIO DIAGRAMME & ORGANIGRAMME)          -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <!-- Obere Formen- & Verbindungs-Werkzeugleiste -->
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/60 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <?php if ($canEdit): ?>
                    <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 mr-0.5">+ Form:</span>
                    <button type="button" onclick="visioAddNode('rect', 'Prozessschritt', 'Beschreibung')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Rechteck (Prozess / Schritt)">
                        ▭ Prozess
                    </button>
                    <button type="button" onclick="visioAddNode('round', 'Teilbereich', 'Abteilung / Aufgabe')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Abgerundetes Kästchen">
                        ▢ Abgerundet
                    </button>
                    <button type="button" onclick="visioAddNode('diamond', 'Entscheidung?', 'Ja / Nein')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Raute (Entscheidung / Weiche)">
                        ◇ Entscheidung
                    </button>
                    <button type="button" onclick="visioAddNode('stadium', 'Start / Ende', '')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Start- oder Endpunkt">
                        ◯ Start/Ende
                    </button>
                    <button type="button" onclick="visioAddNode('person', 'Person / Rolle', 'Funktion im Team')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Personen-Kachel für Organigramme">
                        👤 Person/Rolle
                    </button>
                    <button type="button" onclick="visioAddNode('note', 'Hinweis / Notiz', 'Details')"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 dark:hover:bg-teal-950/60 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer" title="Notiz- oder Dokument-Box">
                        📝 Notiz
                    </button>

                    <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-0.5"></span>

                    <button type="button" id="btnVisioConnectMode" onclick="visioToggleConnectMode()"
                            class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-200 transition cursor-pointer"
                            title="Aktivieren und nacheinander 2 Kästchen anklicken, um einen Verbindungspfeil zu ziehen">
                        🔗 Pfeil verbinden
                    </button>

                    <button type="button" onclick="visioAutoLayout()"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                            title="Elemente automatisch sauber im Raster anordnen">
                        🪄 Auto-Layout
                    </button>

                    <select id="visioThemeSelect" onchange="visioChangeTheme(this.value)"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200">
                        <option value="light">☀️ Hell (Mit Punktraster)</option>
                        <option value="plain">⬜ Reinweiß (Ohne Raster)</option>
                        <option value="blueprint">📐 Blueprint (Dunkelblau)</option>
                        <option value="dark">🌙 Nacht-Modus</option>
                    </select>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-1.5">
                <button type="button" onclick="visioExportSvgFile()"
                        class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                    🖼️ Als .SVG Bild
                </button>
                <button type="button" onclick="visioPrintDiagram()"
                        class="px-3 py-1.5 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold shadow-2xs cursor-pointer">
                    🖨️ Drucken / PDF
                </button>
            </div>
        </div>

        <!-- Eigenschaften-Leiste für das aktuell ausgewählte Kästchen oder den ausgewählten Pfeil -->
        <?php if ($canEdit): ?>
        <div id="visioInspectorBar" class="px-3.5 py-2.5 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex flex-wrap items-center gap-2 text-xs">
            <span id="visioInspectorHint" class="text-slate-400 italic">💡 Klicke ein Kästchen oder einen Pfeil auf der Zeichenfläche an, um Text, Farbe oder Form anzupassen – oder ziehe Kästchen frei mit der Maus.</span>

            <!-- Knoten-Inspektor -->
            <div id="visioNodeInspector" class="hidden flex-wrap items-center gap-2 w-full">
                <span class="font-bold text-teal-700 dark:text-teal-400">▭ Element:</span>
                <input type="text" id="visioInpText" placeholder="Haupttext / Titel…"
                       class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white w-44 sm:w-52">
                <input type="text" id="visioInpSubtext" placeholder="Untertitel / Rolle / Zusatz…"
                       class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 w-44 sm:w-52">
                <select id="visioInpShape" onchange="visioUpdateSelectedNode('shape', this.value)"
                        class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                    <option value="rect">▭ Prozess (Rechteck)</option>
                    <option value="round">▢ Abgerundet</option>
                    <option value="diamond">◇ Entscheidung (Raute)</option>
                    <option value="stadium">◯ Start / Ende</option>
                    <option value="person">👤 Person / Organigramm</option>
                    <option value="note">📝 Notiz / Dokument</option>
                </select>
                <select id="visioInpColor" onchange="visioUpdateSelectedNode('color', this.value)"
                        class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                    <option value="blue">🔵 Blau</option>
                    <option value="emerald">🟢 Smaragd</option>
                    <option value="amber">🟠 Gold / Orange</option>
                    <option value="rose">🔴 Rosé / Rot</option>
                    <option value="violet">🟣 Violett</option>
                    <option value="slate">⚫ Schiefer / Grau</option>
                </select>
                <button type="button" onclick="visioDuplicateSelectedNode()"
                        class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    📄 Duplizieren
                </button>
                <button type="button" onclick="visioDeleteSelected()"
                        class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-400 font-bold cursor-pointer ml-auto">
                    🗑️ Löschen (Entf)
                </button>
            </div>

            <!-- Pfeil-/Verbindungs-Inspektor -->
            <div id="visioEdgeInspector" class="hidden flex-wrap items-center gap-2 w-full">
                <span class="font-bold text-teal-700 dark:text-teal-400">🔗 Verbindungspfeil:</span>
                <input type="text" id="visioInpEdgeLabel" placeholder="Beschriftung (z.B. Ja / Nein)…"
                       class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white w-48">
                <select id="visioInpEdgeStyle" onchange="visioUpdateSelectedEdge('style', this.value)"
                        class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                    <option value="solid">─── Durchgezogen</option>
                    <option value="dashed">- - - Gestrichelt</option>
                </select>
                <select id="visioInpEdgeRouting" onchange="visioUpdateSelectedEdge('routing', this.value)"
                        class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                    <option value="curved">↪️ Geschwungen (Bézier)</option>
                    <option value="ortho">📐 Rechtwinklig (Organigramm)</option>
                    <option value="straight">📏 Gerade Linie</option>
                </select>
                <button type="button" onclick="visioDeleteSelected()"
                        class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-400 font-bold cursor-pointer ml-auto">
                    🗑️ Pfeil löschen
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Interaktive Visio-Zeichenfläche (SVG + Drag & Drop) -->
        <div id="visioCanvasWrap" class="relative w-full h-[64vh] overflow-auto select-none bg-slate-50 dark:bg-slate-950">
            <svg id="visioSvgCanvas" class="w-[1600px] h-[1000px] block" xmlns="http://www.w3.org/2000/svg"></svg>
        </div>

        <div class="px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span id="visioStatsLabel">0 Formen &bull; 0 Verbindungen</span>
            <span>Format: Vektor-Diagramm (<code>.drawio</code> / <code>.svg</code>) &bull; Klicke auf „🔗 Pfeil verbinden“ zum Verknüpfen zweier Formen</span>
        </div>
    </div>

<?php elseif ($editorType === 'image'): ?>
    <!-- ================================================================== -->
    <!-- 2d. FOTO- & BILD-STUDIO (.JPG, .PNG, .WEBP, .GIF, .BMP)            -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <!-- Obere Haupt-Werkzeugleiste: 4 Studio-Bereiche + Undo/Reset -->
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/60 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <button type="button" id="imgTabBtnTransform" onclick="switchImgStudioTab('transform')"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold bg-pink-600 text-white shadow-2xs transition cursor-pointer">
                    ✂️ Zuschneiden &amp; Drehen
                </button>
                <button type="button" id="imgTabBtnResize" onclick="switchImgStudioTab('resize')"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 transition cursor-pointer">
                    📐 Größe &amp; Komprimierung
                </button>
                <button type="button" id="imgTabBtnAdjust" onclick="switchImgStudioTab('adjust')"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 transition cursor-pointer">
                    ☀️ Belichtung &amp; Filter
                </button>
                <button type="button" id="imgTabBtnDraw" onclick="switchImgStudioTab('draw')"
                        class="px-3 py-1.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 transition cursor-pointer">
                    ✏️ Zeichnen, Text &amp; 🔲 DSGVO-Pixel
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <?php if ($canEdit): ?>
                    <button type="button" id="btnImgUndo" onclick="imgStudioUndo()" disabled
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 disabled:opacity-40 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                            title="Letzten Bearbeitungsschritt rückgängig machen (Strg+Z)">
                        ↩️ Rückgängig
                    </button>
                    <button type="button" onclick="imgStudioResetOriginal()"
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                            title="Alle ungespeicherten Änderungen verwerfen und Originalbild neu laden">
                        🔄 Original
                    </button>
                <?php endif; ?>
                <button type="button" onclick="imgStudioDownloadLocal()"
                        class="px-3 py-1.5 rounded-xl bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800 text-xs font-bold cursor-pointer"
                        title="Bearbeitetes Bild direkt auf den PC/Smartphone herunterladen">
                    ⬇️ Als Bild laden
                </button>
            </div>
        </div>

        <?php if ($canEdit): ?>
        <!-- Unter-Werkzeugleisten je nach aktivem Studio-Tab -->
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-xs">
            <!-- TAB 1: ZUSCHNEIDEN, DREHEN & SPIEGELN -->
            <div id="imgPanelTransform" class="flex flex-wrap items-center gap-2">
                <span class="font-bold text-slate-600 dark:text-slate-300">🔄 Drehen &amp; Spiegeln:</span>
                <button type="button" onclick="imgStudioRotate(-90)" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">↶ 90° links</button>
                <button type="button" onclick="imgStudioRotate(90)" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">↷ 90° rechts</button>
                <button type="button" onclick="imgStudioFlip('h')" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">⇄ Horizontal</button>
                <button type="button" onclick="imgStudioFlip('v')" class="px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">⇅ Vertikal</button>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-1"></span>

                <span class="font-bold text-slate-600 dark:text-slate-300">✂️ Zuschnitt:</span>
                <select id="imgCropAspect" onchange="imgStudioStartCrop(this.value)" class="px-2.5 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                    <option value="">— Zuschnitt wählen oder Rahmen mit Maus ziehen —</option>
                    <option value="free">✂️ Frei zuschneiden</option>
                    <option value="1:1">🟦 1:1 Quadrat / Profilbild</option>
                    <option value="16:9">🖥️ 16:9 Präsentation / Banner</option>
                    <option value="4:3">📷 4:3 Klassisches Foto</option>
                    <option value="3:2">🎞️ 3:2 Spiegelreflex</option>
                    <option value="a4">📄 DIN A4 Hochformat</option>
                </select>
                <button type="button" id="btnApplyCrop" onclick="imgStudioApplyCrop()" class="hidden px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">
                    ✓ Zuschnitt anwenden
                </button>
                <button type="button" id="btnCancelCrop" onclick="imgStudioCancelCrop()" class="hidden px-2.5 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    ✕ Aufheben
                </button>
            </div>

            <!-- TAB 2: GRÖSSE ÄNDERN & KOMPRIMIEREN -->
            <div id="imgPanelResize" class="hidden flex-wrap items-center gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="font-bold text-slate-600 dark:text-slate-300">📐 Skalieren:</span>
                    <button type="button" onclick="imgStudioScalePreset(0.75)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">75 %</button>
                    <button type="button" onclick="imgStudioScalePreset(0.5)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">50 %</button>
                    <button type="button" onclick="imgStudioMaxDimPreset(1920)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">Full-HD (1920 px)</button>
                    <button type="button" onclick="imgStudioMaxDimPreset(1280)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">Web (1280 px)</button>
                    <button type="button" onclick="imgStudioMaxDimPreset(800)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">Kompakt (800 px)</button>
                </div>

                <div class="flex items-center gap-1.5">
                    <input type="number" id="imgResizeW" min="16" max="8000" oninput="imgStudioSyncAspect('w')" class="w-20 px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono text-center">
                    <span>×</span>
                    <input type="number" id="imgResizeH" min="16" max="8000" oninput="imgStudioSyncAspect('h')" class="w-20 px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono text-center">
                    <span>px</span>
                    <label class="inline-flex items-center gap-1 text-[11px] cursor-pointer">
                        <input type="checkbox" id="imgResizeLock" checked class="rounded text-pink-600"> 🔒 Proportion
                    </label>
                    <button type="button" onclick="imgStudioApplyResize()" class="px-2.5 py-1 rounded-lg bg-pink-600 hover:bg-pink-700 text-white font-bold cursor-pointer">
                        ✓ Größe ändern
                    </button>
                </div>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700"></span>

                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-bold text-slate-600 dark:text-slate-300">📦 Format &amp; Qualität:</span>
                    <select id="imgExportFormat" onchange="imgStudioUpdateSizeEstimate()" class="px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-semibold">
                        <option value="jpg" <?= in_array($ext, ['jpg', 'jpeg'], true) ? 'selected' : '' ?>>JPEG (.jpg – Foto-Standard)</option>
                        <option value="webp" <?= $ext === 'webp' ? 'selected' : '' ?>>WebP (.webp – Extrem platzsparend)</option>
                        <option value="png" <?= $ext === 'png' ? 'selected' : '' ?>>PNG (.png – Verlustfrei / Grafik)</option>
                    </select>
                    <input type="range" id="imgExportQuality" min="15" max="100" value="88" oninput="document.getElementById('imgQualityVal').textContent=this.value+'%'; imgStudioUpdateSizeEstimate();" class="w-24 accent-pink-600">
                    <span id="imgQualityVal" class="font-mono font-bold w-10">88%</span>
                    <span id="imgSizeEstimateBadge" class="px-2.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 font-bold">
                        Berechne Größe…
                    </span>
                </div>
            </div>

            <!-- TAB 3: BELICHTUNG, FARBEN & FOTO-FILTER -->
            <div id="imgPanelAdjust" class="hidden space-y-2.5">
                <div class="flex flex-wrap items-center gap-4">
                    <label class="flex items-center gap-1.5">
                        <span class="font-semibold w-20">☀️ Helligkeit:</span>
                        <input type="range" id="imgAdjBright" min="-80" max="80" value="0" oninput="imgStudioPreviewAdjust()" class="w-28 accent-pink-600">
                    </label>
                    <label class="flex items-center gap-1.5">
                        <span class="font-semibold w-20">◐ Kontrast:</span>
                        <input type="range" id="imgAdjContrast" min="-80" max="80" value="0" oninput="imgStudioPreviewAdjust()" class="w-28 accent-pink-600">
                    </label>
                    <label class="flex items-center gap-1.5">
                        <span class="font-semibold w-20">🎨 Sättigung:</span>
                        <input type="range" id="imgAdjSat" min="-100" max="100" value="0" oninput="imgStudioPreviewAdjust()" class="w-28 accent-pink-600">
                    </label>
                    <label class="flex items-center gap-1.5">
                        <span class="font-semibold w-16">📜 Sepia:</span>
                        <input type="range" id="imgAdjSepia" min="0" max="100" value="0" oninput="imgStudioPreviewAdjust()" class="w-24 accent-pink-600">
                    </label>
                    <button type="button" onclick="imgStudioCommitAdjust()" class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">
                        ✓ Farbkorrektur übernehmen
                    </button>
                    <button type="button" onclick="imgStudioResetAdjustSliders()" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">
                        Regler 0
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 pt-1 border-t border-slate-100 dark:border-slate-800">
                    <span class="font-bold text-slate-500 mr-1">✨ 1-Klick-Filter:</span>
                    <button type="button" onclick="imgStudioApplyFilterPreset('auto')" class="px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200 font-semibold cursor-pointer">✨ Auto-Brillanz</button>
                    <button type="button" onclick="imgStudioApplyFilterPreset('bw')" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">🖤 Schwarz-Weiß</button>
                    <button type="button" onclick="imgStudioApplyFilterPreset('sepia')" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">📜 Vintage / Sepia</button>
                    <button type="button" onclick="imgStudioApplyFilterPreset('warm')" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">🌅 Warm / Sommer</button>
                    <button type="button" onclick="imgStudioApplyFilterPreset('doc')" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">📄 Dokumenten-Kontrast</button>
                    <button type="button" onclick="imgStudioApplyFilterPreset('invert')" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-semibold cursor-pointer">🔄 Invertieren</button>
                </div>
            </div>

            <!-- TAB 4: ZEICHNEN, PFEILE, TEXT/WASSERZEICHEN & DSGVO-VERPIXELUNG -->
            <div id="imgPanelDraw" class="hidden flex flex-wrap items-center gap-2">
                <span class="font-bold text-slate-600 dark:text-slate-300">Werkzeug:</span>
                <button type="button" data-img-tool="pixelate" onclick="setImgDrawTool('pixelate')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-pink-600 text-white font-bold cursor-pointer" title="Mit gedrückter Maustaste über Gesichter, Kennzeichen oder sensible Daten ziehen, um sie irreversibel zu verpixeln">
                    🔲 DSGVO-Verpixelung
                </button>
                <button type="button" data-img-tool="blur" onclick="setImgDrawTool('blur')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer" title="Bereich mit Weichzeichner unkenntlich machen">
                    💧 Unschärfe-Pinsel
                </button>
                <button type="button" data-img-tool="pen" onclick="setImgDrawTool('pen')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    🖊️ Freihand-Stift
                </button>
                <button type="button" data-img-tool="arrow" onclick="setImgDrawTool('arrow')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    ↗️ Hinweispfeil
                </button>
                <button type="button" data-img-tool="rect" onclick="setImgDrawTool('rect')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    ▭ Rahmen
                </button>
                <button type="button" data-img-tool="text" onclick="setImgDrawTool('text')" class="img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer">
                    🔤 Text / Wasserzeichen
                </button>

                <span class="h-5 w-px bg-slate-300 dark:bg-slate-700 mx-1"></span>

                <label class="flex items-center gap-1">
                    <span>Farbe:</span>
                    <input type="color" id="imgDrawColor" value="#e11d48" class="w-7 h-7 rounded cursor-pointer border-0">
                </label>
                <label class="flex items-center gap-1">
                    <span>Größe:</span>
                    <input type="range" id="imgDrawSize" min="4" max="60" value="22" class="w-20 accent-pink-600">
                </label>
                <input type="text" id="imgWatermarkText" placeholder="Text für Klick ins Bild (z.B. © Verein 2026)…" class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 w-56">
            </div>
        </div>
        <?php endif; ?>

        <!-- Haupt-Arbeitsfläche (Canvas + interaktiver Crop-Overlay) -->
        <div class="relative w-full min-h-[58vh] max-h-[72vh] overflow-auto bg-slate-900/95 flex items-center justify-center p-4 sm:p-6 select-none">
            <div id="imgCanvasContainer" class="relative inline-block shadow-2xl">
                <canvas id="imgStudioCanvas" class="max-w-full max-h-[64vh] w-auto h-auto block rounded bg-[repeating-conic-gradient(#cbd5e1_0%_25%,#f8fafc_0%_50%)] bg-[length:20px_20px]"></canvas>
                <!-- Visueller Zuschnitt-Auswahlrahmen -->
                <div id="imgCropOverlay" class="hidden absolute border-2 border-dashed border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.58)] pointer-events-none"></div>
            </div>
        </div>

        <!-- Untere Statuszeile -->
        <div class="px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span id="imgStudioStatsLabel">Lade Bild…</span>
            <span>🛡️ 100 % lokale HTML5-Canvas-Bildbearbeitung im Browser &bull; Original bleibt über 🕓 Dateiversionen jederzeit wiederherstellbar</span>
        </div>
    </div>

<?php else: ?>
    <!-- ================================================================== -->
    <!-- 3. TEXT- & MARKDOWN-EDITOR (.TXT, .MD, .HTML)                      -->
    <!-- ================================================================== -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8">
        <div class="p-4">
            <textarea
                id="plainTextEditor"
                <?= $canEdit ? '' : 'readonly' ?>
                rows="24"
                placeholder="Text hier eingeben..."
                class="w-full p-4 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-100 font-mono text-xs sm:text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-brand-500"
            ><?= e($textContent) ?></textarea>
        </div>
        <div class="px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span id="plainTextStats">0 Zeilen &bull; 0 Zeichen</span>
            <span>UTF-8 Textdatei (<code>.<?= e($ext) ?></code>)</span>
        </div>
    </div>
<?php endif; ?>

<script>
const OFFICE_FILE_ID     = <?= (int)$file['id'] ?>;
const OFFICE_USER_ID     = <?= (int)$userId ?>;
const OFFICE_EDITOR_TYPE = <?= json_encode($editorType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const OFFICE_CAN_EDIT    = <?= $canEdit && !$isVaultLocked ? 'true' : 'false' ?>;
const OFFICE_CSRF_TOKEN  = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const OFFICE_FILE_EXT    = <?= json_encode($ext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let hasUnsavedChanges = false;
let autoSaveTimer     = null;
let collabPeers       = []; // Aktive andere Benutzer [{userId, username, isMe, focusTarget, focusLabel, color}]
let collabLastDeltaId = 0;
let collabOutQueue    = []; // Ausgehende Delta-Pakete [{type, target, payload}]
let currentFocusTarget = '';
let currentFocusLabel  = '';

function queueCollabDelta(type, target, payload) {
    if (!OFFICE_CAN_EDIT) return;
    // Falls für dasselbe Ziel schon ein ungesendetes Delta in der Queue liegt, ersetzen
    const idx = collabOutQueue.findIndex(d => d.type === type && d.target === target);
    if (idx !== -1) {
        collabOutQueue[idx].payload = payload;
    } else {
        collabOutQueue.push({ type, target, payload });
    }
}

function scheduleAutoSave() {
    if (!OFFICE_CAN_EDIT) return;
    const chk = document.getElementById('chkOfficeAutoSave');
    if (!chk || !chk.checked) return;
    if (autoSaveTimer) clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(() => {
        if (hasUnsavedChanges) {
            saveOfficeDocument(true);
        }
    }, 3500);
}

function markDirty() {
    if (!OFFICE_CAN_EDIT) return;
    hasUnsavedChanges = true;
    const statusEl = document.getElementById('officeStatusText');
    if (statusEl) {
        statusEl.textContent = '● Ungespeicherte Änderungen';
        statusEl.className = 'font-semibold text-amber-600 dark:text-amber-400';
    }
    scheduleAutoSave();
}

window.addEventListener('beforeunload', (e) => {
    if (hasUnsavedChanges) {
        e.preventDefault();
        e.returnValue = '';
    }
});

const titleInputEl = document.getElementById('officeDocTitle');
if (titleInputEl) {
    titleInputEl.addEventListener('input', markDirty);
}

// Globales Tastenkürzel Strg+S / Cmd+S zum direkten Speichern
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (OFFICE_CAN_EDIT) {
            saveOfficeDocument();
        }
    }
});

// ============================================================================
// 1. WORD LIGHT FUNKTIONEN (inkl. Stufe-1 Absatz-Sperre & Live-Absatz-Sync)
// ============================================================================
const wordSurface = document.getElementById('wordEditorSurface');
let wordBlockCounter = 0;
let wordInputDebounce = null;
let lastKnownBlockCount = 0;

function ensureWordBlockIds() {
    if (!wordSurface) return;
    // Falls das Dokument noch gar keine Block-Kinder hat, in <p> einbetten
    if (wordSurface.children.length === 0) {
        const txt = wordSurface.innerHTML.trim();
        wordSurface.innerHTML = `<p data-block-id="b0">${txt || '<br>'}</p>`;
    }
    Array.from(wordSurface.children).forEach((el, idx) => {
        if (!el.getAttribute('data-block-id')) {
            wordBlockCounter++;
            el.setAttribute('data-block-id', 'b_' + OFFICE_USER_ID + '_' + Date.now() + '_' + wordBlockCounter + '_' + idx);
        }
    });
}

function getCleanWordHtml() {
    if (!wordSurface) return '<p></p>';
    const clone = wordSurface.cloneNode(true);
    clone.querySelectorAll('[data-block-id], [data-locked-by], [contenteditable]').forEach(el => {
        el.removeAttribute('data-block-id');
        el.removeAttribute('data-locked-by');
        el.removeAttribute('contenteditable');
        el.style.removeProperty('--peer-color');
    });
    return clone.innerHTML;
}

function getActiveWordBlockElement() {
    if (!wordSurface) return null;
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return null;
    let node = sel.anchorNode;
    if (!node) return null;
    if (node.nodeType === Node.TEXT_NODE) {
        node = node.parentElement;
    }
    while (node && node !== wordSurface && node.parentElement !== wordSurface) {
        node = node.parentElement;
    }
    if (node && node.parentElement === wordSurface) {
        return node;
    }
    return null;
}

function updateWordFocusTarget() {
    if (!wordSurface) return;
    ensureWordBlockIds();
    const blk = getActiveWordBlockElement();
    if (blk) {
        const bid = blk.getAttribute('data-block-id') || '';
        const idx = Array.from(wordSurface.children).indexOf(blk);
        currentFocusTarget = bid ? ('block:' + bid) : '';
        currentFocusLabel  = 'Absatz #' + (idx + 1);
    }
}

function applyWordPeerLocks() {
    if (!wordSurface) return;
    ensureWordBlockIds();
    const lockMap = {};
    collabPeers.forEach(p => {
        if (!p.isMe && p.focusTarget && p.focusTarget.startsWith('block:')) {
            const bid = p.focusTarget.substring(6);
            lockMap[bid] = p;
        }
    });

    Array.from(wordSurface.children).forEach(el => {
        const bid = el.getAttribute('data-block-id') || '';
        const peer = lockMap[bid];
        if (peer) {
            el.setAttribute('data-locked-by', peer.username);
            el.style.setProperty('--peer-color', peer.color || '#e11d48');
            if (OFFICE_CAN_EDIT) {
                el.setAttribute('contenteditable', 'false');
            }
        } else {
            if (el.hasAttribute('data-locked-by')) {
                el.removeAttribute('data-locked-by');
                el.style.removeProperty('--peer-color');
                if (OFFICE_CAN_EDIT) {
                    el.removeAttribute('contenteditable');
                }
            }
        }
    });
}

function broadcastWordChange(forceFull = false) {
    if (!wordSurface || !OFFICE_CAN_EDIT) return;
    ensureWordBlockIds();
    const currentCount = wordSurface.children.length;
    const blk = getActiveWordBlockElement();

    if (forceFull || currentCount !== lastKnownBlockCount || !blk) {
        lastKnownBlockCount = currentCount;
        const blocksPayload = Array.from(wordSurface.children).map(el => ({
            id: el.getAttribute('data-block-id'),
            tag: el.tagName,
            html: el.innerHTML
        }));
        queueCollabDelta('word_full', 'doc', blocksPayload);
    } else {
        const bid = blk.getAttribute('data-block-id');
        if (bid && !blk.hasAttribute('data-locked-by')) {
            queueCollabDelta('word_block', 'block:' + bid, {
                id: bid,
                tag: blk.tagName,
                html: blk.innerHTML
            });
        }
    }
}

if (wordSurface) {
    ensureWordBlockIds();
    lastKnownBlockCount = wordSurface.children.length;

    const updateWordStats = () => {
        const text = (wordSurface.innerText || '').trim();
        const words = text === '' ? 0 : text.split(/\s+/).length;
        const chars = text.length;
        const statsEl = document.getElementById('wordCountStats');
        if (statsEl) {
            statsEl.textContent = `${words} ${words === 1 ? 'Wort' : 'Wörter'} • ${chars} Zeichen`;
        }
    };
    wordSurface.addEventListener('input', () => {
        markDirty();
        updateWordStats();
        updateWordFocusTarget();
        if (wordInputDebounce) clearTimeout(wordInputDebounce);
        wordInputDebounce = setTimeout(() => broadcastWordChange(false), 250);
    });
    wordSurface.addEventListener('keyup', updateWordFocusTarget);
    wordSurface.addEventListener('mouseup', updateWordFocusTarget);
    updateWordStats();
}

function execWordCmd(cmd, val = null) {
    if (!wordSurface) return;
    wordSurface.focus();
    document.execCommand(cmd, false, val);
    markDirty();
    broadcastWordChange(false);
}

function applyWordBlock(tag) {
    if (!tag || !wordSurface) return;
    wordSurface.focus();
    document.execCommand('formatBlock', false, '<' + tag + '>');
    markDirty();
    broadcastWordChange(true);
}

function insertWordTable() {
    if (!wordSurface) return;
    const colsStr = prompt('Wie viele Spalten soll die Tabelle haben?', '3');
    if (!colsStr) return;
    const rowsStr = prompt('Wie viele Zeilen soll die Tabelle haben?', '3');
    if (!rowsStr) return;
    const cols = Math.min(12, Math.max(1, parseInt(colsStr, 10) || 3));
    const rows = Math.min(50, Math.max(1, parseInt(rowsStr, 10) || 3));

    let html = '<table><tbody>';
    for (let r = 0; r < rows; r++) {
        html += '<tr>';
        for (let c = 0; c < cols; c++) {
            if (r === 0) {
                html += `<th>Spalte ${c + 1}</th>`;
            } else {
                html += '<td>&nbsp;</td>';
            }
        }
        html += '</tr>';
    }
    html += '</tbody></table><p><br></p>';
    wordSurface.focus();
    document.execCommand('insertHTML', false, html);
    markDirty();
}

function printWordDocument() {
    if (!wordSurface) return;
    const printWin = window.open('', '_blank', 'width=850,height=900');
    if (!printWin) return;
    const docTitle = titleInputEl ? titleInputEl.value : 'Dokument';
    printWin.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${docTitle}</title>
        <style>
            body { font-family: Calibri, Arial, sans-serif; margin: 2cm; color: #111; line-height: 1.5; }
            table { width: 100%; border-collapse: collapse; margin: 1em 0; }
            th, td { border: 1px solid #999; padding: 6px 10px; text-align: left; }
            th { background: #f1f5f9; }
        </style>
    </head><body>${wordSurface.innerHTML}</body></html>`);
    printWin.document.close();
    printWin.focus();
    printWin.print();
}

// ============================================================================
// 2. CALC / EXCEL LIGHT FUNKTIONEN (.XLSX & .CSV)
// ============================================================================
let sheetsState = <?= $editorType === 'sheet' ? json_encode($sheetsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '[]' ?>;
let activeSheetIdx = 0;
let activeRow = 0;
let activeCol = 0;

function colIdxToName(idx) {
    let name = '';
    let n = idx + 1;
    while (n > 0) {
        const mod = (n - 1) % 26;
        name = String.fromCharCode(65 + mod) + name;
        n = Math.floor((n - mod) / 26);
    }
    return name;
}

function colNameToIdx(letters) {
    const s = letters.toUpperCase().trim();
    let num = 0;
    for (let i = 0; i < s.length; i++) {
        num = num * 26 + (s.charCodeAt(i) - 64);
    }
    return Math.max(0, num - 1);
}

/**
 * Wertet eine Zelle (inkl. Excel-Formeln wie =SUMME(A1:B5), =MITTELWERT(...), =A1+B1*2) live aus.
 */
function evaluateSheetCell(sheetIdx, r, c, visited = {}) {
    const sheet = sheetsState[sheetIdx];
    if (!sheet || !sheet.rows || !sheet.rows[r]) return '';
    const raw = String(sheet.rows[r][c] ?? '').trim();
    if (!raw.startsWith('=') || raw.length === 1) {
        return raw;
    }

    const key = `${r}:${c}`;
    if (visited[key]) return '#ZIRKEL!';
    visited[key] = true;

    let expr = raw.substring(1).trim();

    // Hilfsfunktion: Zellbereich A1:B3 in Zahlen-Array auflösen
    const resolveRangeNumbers = (rangeStr) => {
        const parts = rangeStr.split(':');
        if (parts.length !== 2) return [];
        const m1 = parts[0].trim().match(/^([A-Za-z]+)(\d+)$/);
        const m2 = parts[1].trim().match(/^([A-Za-z]+)(\d+)$/);
        if (!m1 || !m2) return [];
        const c1 = colNameToIdx(m1[1]), r1 = parseInt(m1[2], 10) - 1;
        const c2 = colNameToIdx(m2[1]), r2 = parseInt(m2[2], 10) - 1;
        const nums = [];
        for (let rr = Math.min(r1, r2); rr <= Math.max(r1, r2); rr++) {
            for (let cc = Math.min(c1, c2); cc <= Math.max(c1, c2); cc++) {
                const v = evaluateSheetCell(sheetIdx, rr, cc, { ...visited });
                const num = parseFloat(String(v).replace(',', '.'));
                if (!isNaN(num)) nums.push(num);
            }
        }
        return nums;
    };

    const resolveArgsNumbers = (argsInside) => {
        const items = argsInside.split(/[;,]/);
        let nums = [];
        for (const item of items) {
            const t = item.trim();
            if (t.includes(':')) {
                nums = nums.concat(resolveRangeNumbers(t));
            } else if (/^[A-Za-z]+\d+$/.test(t)) {
                const m = t.match(/^([A-Za-z]+)(\d+)$/);
                const v = evaluateSheetCell(sheetIdx, parseInt(m[2], 10) - 1, colNameToIdx(m[1]), { ...visited });
                const num = parseFloat(String(v).replace(',', '.'));
                if (!isNaN(num)) nums.push(num);
            } else {
                const num = parseFloat(t.replace(',', '.'));
                if (!isNaN(num)) nums.push(num);
            }
        }
        return nums;
    };

    try {
        // 1. Datum-Funktion =HEUTE() / =TODAY()
        if (/^(HEUTE|TODAY)\s*\(\s*\)$/i.test(expr)) {
            const now = new Date();
            const dd = String(now.getDate()).padStart(2, '0');
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            return `${dd}.${mm}.${now.getFullYear()}`;
        }

        // Hilfsfunktion: Einzelnen Teil-Ausdruck (mit Zellen & Grundrechenarten) numerisch auswerten
        const evalSubExpr = (subRaw) => {
            let s = String(subRaw ?? '').trim();
            if (s === '') return 0;
            // Bereichsfunktionen in Teil-Ausdruck auflösen
            s = s.replace(/\b(SUMME|SUM|MITTELWERT|AVERAGE|AVG|MIN|MAX|ANZAHL|COUNT|PRODUKT|PRODUCT)\s*\(([^()]+)\)/gi, (_, fnName, inside) => {
                const fn = fnName.toUpperCase();
                const vals = resolveArgsNumbers(inside);
                if (fn === 'ANZAHL' || fn === 'COUNT') return String(vals.length);
                if (vals.length === 0) return '0';
                if (fn === 'PRODUKT' || fn === 'PRODUCT') return String(vals.reduce((a, b) => a * b, 1));
                const sum = vals.reduce((a, b) => a + b, 0);
                if (fn === 'SUMME' || fn === 'SUM') return String(sum);
                if (fn === 'MITTELWERT' || fn === 'AVERAGE' || fn === 'AVG') return String(sum / vals.length);
                if (fn === 'MIN') return String(Math.min(...vals));
                if (fn === 'MAX') return String(Math.max(...vals));
                return '0';
            });
            // Zellreferenzen (z. B. A1, B12) ersetzen
            s = s.replace(/\b([A-Za-z]+)(\d+)\b/g, (_, colLet, rowNumStr) => {
                const cc = colNameToIdx(colLet);
                const rr = parseInt(rowNumStr, 10) - 1;
                const cellVal = evaluateSheetCell(sheetIdx, rr, cc, { ...visited });
                const num = parseFloat(String(cellVal).replace(',', '.'));
                return isNaN(num) ? '0' : String(num);
            });
            // Deutsche Kommazahlen (z. B. 1,19) in Punktzahlen umwandeln
            s = s.replace(/(\d),(\d)/g, '$1.$2');
            if (!/^[0-9+\-*/().\s]+$/.test(s)) return NaN;
            return Number(Function('"use strict"; return (' + s + ');')());
        };

        // 2. WENN / IF Funktion: =WENN(Bedingung; Dann; Sonst)
        const ifMatch = expr.match(/^(WENN|IF)\s*\((.+)\)$/i);
        if (ifMatch) {
            const parts = ifMatch[2].split(';');
            const args = parts.length >= 2 ? parts : ifMatch[2].split(',');
            if (args.length >= 2) {
                const condStr = args[0].trim();
                const thenRaw = (args[1] ?? '').trim();
                const elseRaw = (args[2] ?? '""').trim();
                const cmpMatch = condStr.match(/^(.+?)(>=|<=|<>|!=|=|>|<)(.+)$/);
                let condOk = false;
                if (cmpMatch) {
                    const leftVal = evalSubExpr(cmpMatch[1]);
                    const op = cmpMatch[2];
                    const rightVal = evalSubExpr(cmpMatch[3]);
                    if (op === '>=') condOk = leftVal >= rightVal;
                    else if (op === '<=') condOk = leftVal <= rightVal;
                    else if (op === '<>' || op === '!=') condOk = leftVal !== rightVal;
                    else if (op === '=') condOk = leftVal === rightVal;
                    else if (op === '>') condOk = leftVal > rightVal;
                    else if (op === '<') condOk = leftVal < rightVal;
                } else {
                    condOk = Boolean(evalSubExpr(condStr));
                }
                const chosen = condOk ? thenRaw : elseRaw;
                if ((chosen.startsWith('"') && chosen.endsWith('"')) || (chosen.startsWith("'") && chosen.endsWith("'"))) {
                    return chosen.slice(1, -1);
                }
                if (/^[A-Za-z]+\d+$/.test(chosen)) {
                    const m = chosen.match(/^([A-Za-z]+)(\d+)$/);
                    return String(evaluateSheetCell(sheetIdx, parseInt(m[2], 10) - 1, colNameToIdx(m[1]), { ...visited }));
                }
                const numRes = evalSubExpr(chosen);
                return !isNaN(numRes) && isFinite(numRes)
                    ? (Number.isInteger(numRes) ? String(numRes) : String(Math.round(numRes * 10000) / 10000))
                    : chosen;
            }
        }

        // 3. Bereichsfunktionen SUMME / SUM / MITTELWERT / AVERAGE / AVG / MIN / MAX / ANZAHL / COUNT / PRODUKT / PRODUCT
        expr = expr.replace(/\b(SUMME|SUM|MITTELWERT|AVERAGE|AVG|MIN|MAX|ANZAHL|COUNT|PRODUKT|PRODUCT)\s*\(([^()]+)\)/gi, (_, fnName, inside) => {
            const fn = fnName.toUpperCase();
            const vals = resolveArgsNumbers(inside);
            if (fn === 'ANZAHL' || fn === 'COUNT') return String(vals.length);
            if (vals.length === 0) return '0';
            if (fn === 'PRODUKT' || fn === 'PRODUCT') return String(vals.reduce((a, b) => a * b, 1));
            const sum = vals.reduce((a, b) => a + b, 0);
            if (fn === 'SUMME' || fn === 'SUM') return String(sum);
            if (fn === 'MITTELWERT' || fn === 'AVERAGE' || fn === 'AVG') return String(sum / vals.length);
            if (fn === 'MIN') return String(Math.min(...vals));
            if (fn === 'MAX') return String(Math.max(...vals));
            return '0';
        });

        // 4. Mathematische Zusatzfunktionen RUNDEN / ROUND, ABS, WURZEL / SQRT
        expr = expr.replace(/\b(RUNDEN|ROUND)\s*\(([^()]+)\)/gi, (_, _fn, inside) => {
            const sep = inside.includes(';') ? ';' : ',';
            const parts = inside.split(sep);
            const val = evalSubExpr(parts[0] ?? '0');
            const dec = parts.length > 1 ? Math.max(0, Math.min(10, parseInt(parts[1].trim(), 10) || 0)) : 0;
            if (isNaN(val)) return '0';
            const factor = Math.pow(10, dec);
            return String(Math.round(val * factor) / factor);
        });
        expr = expr.replace(/\b(ABS|WURZEL|SQRT)\s*\(([^()]+)\)/gi, (_, fnName, inside) => {
            const fn = fnName.toUpperCase();
            const val = evalSubExpr(inside);
            if (isNaN(val)) return '0';
            if (fn === 'ABS') return String(Math.abs(val));
            return val >= 0 ? String(Math.sqrt(val)) : 'NaN';
        });

        // 5. Einzelne Zellreferenzen (z. B. A1, B12) durch ihren Zahlenwert ersetzen
        expr = expr.replace(/\b([A-Za-z]+)(\d+)\b/g, (_, colLet, rowNumStr) => {
            const cc = colNameToIdx(colLet);
            const rr = parseInt(rowNumStr, 10) - 1;
            const cellVal = evaluateSheetCell(sheetIdx, rr, cc, { ...visited });
            const num = parseFloat(String(cellVal).replace(',', '.'));
            return isNaN(num) ? '0' : String(num);
        });

        // Deutsche Kommazahlen (z. B. 1,19) in Punktzahlen umwandeln
        expr = expr.replace(/(\d),(\d)/g, '$1.$2');

        // Nur sichere mathematische Zeichen zulassen
        if (!/^[0-9+\-*/().\s]+$/.test(expr)) {
            return '#FORMEL?';
        }
        const result = Function('"use strict"; return (' + expr + ');')();
        if (typeof result === 'number' && isFinite(result)) {
            return Number.isInteger(result) ? String(result) : String(Math.round(result * 10000) / 10000);
        }
        return '#WERT!';
    } catch (err) {
        return '#FEHLER!';
    }
}

function renderSpreadsheet() {
    const thead = document.getElementById('spreadsheetThead');
    const tbody = document.getElementById('spreadsheetTbody');
    if (!thead || !tbody) return;

    if (!Array.isArray(sheetsState) || sheetsState.length === 0) {
        sheetsState = [{ name: 'Tabelle1', rows: Array.from({ length: 18 }, () => Array(8).fill('')) }];
    }
    if (activeSheetIdx >= sheetsState.length) activeSheetIdx = 0;

    const sheet = sheetsState[activeSheetIdx];
    const rowCount = sheet.rows.length;
    const colCount = sheet.rows[0] ? sheet.rows[0].length : 8;

    // 1. Spaltenköpfe (A, B, C...)
    let headHtml = '<tr><th class="w-10 py-1.5 px-2 border border-slate-300 dark:border-slate-700 bg-slate-200/80 dark:bg-slate-800 text-center text-[11px] font-bold">#</th>';
    for (let c = 0; c < colCount; c++) {
        const isSelectedCol = (c === activeCol);
        headHtml += `<th class="min-w-[105px] py-1.5 px-2 border border-slate-300 dark:border-slate-700 text-center text-xs font-bold ${isSelectedCol ? 'bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300' : ''}">${colIdxToName(c)}</th>`;
    }
    headHtml += '</tr>';
    thead.innerHTML = headHtml;

    // 2. Zeilen & Zellen (inkl. Live-Team-Zellenmarkierung & Sperrschutz)
    const peerCellMap = {};
    collabPeers.forEach(p => {
        if (!p.isMe && p.focusTarget && p.focusTarget.startsWith(`S${activeSheetIdx}:`)) {
            const parts = p.focusTarget.split(':');
            if (parts.length === 3) {
                peerCellMap[`${parts[1]}:${parts[2]}`] = p;
            }
        }
    });

    let bodyHtml = '';
    for (let r = 0; r < rowCount; r++) {
        const isSelectedRow = (r === activeRow);
        bodyHtml += `<tr><th class="py-1 px-2 border border-slate-300 dark:border-slate-700 bg-slate-100 dark:bg-slate-800/80 text-center text-[11px] font-mono font-semibold ${isSelectedRow ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-500'}">${r + 1}</th>`;
        for (let c = 0; c < colCount; c++) {
            const rawVal = String(sheet.rows[r]?.[c] ?? '');
            const displayVal = rawVal.startsWith('=') ? evaluateSheetCell(activeSheetIdx, r, c) : rawVal;
            const isFormula = rawVal.startsWith('=');
            const isNumeric = !isNaN(parseFloat(displayVal)) && isFinite(displayVal);
            const isSelected = (r === activeRow && c === activeCol);
            const isHeaderRow = (r === 0 && rawVal !== '');
            const peerOnCell = peerCellMap[`${r}:${c}`] || null;
            const peerStyle = peerOnCell ? `box-shadow: inset 0 0 0 2px ${peerOnCell.color}; background-color: ${peerOnCell.color}14;` : '';
            const peerBadge = peerOnCell
                ? `<span style="background:${peerOnCell.color};" class="absolute -top-2.5 right-0 px-1.5 py-0.2 rounded text-[9px] font-bold text-white shadow-2xs pointer-events-none z-20">🔒 ${escapeSheetHtml(peerOnCell.username)}</span>`
                : '';

            bodyHtml += `<td
                data-r="${r}"
                data-c="${c}"
                onclick="selectSheetCell(${r}, ${c})"
                ondblclick="startInlineCellEdit(${r}, ${c})"
                style="${peerStyle}"
                class="relative border border-slate-200 dark:border-slate-800 px-2 py-1 h-7 whitespace-nowrap cursor-cell transition-colors ${isSelected ? 'ring-2 ring-brand-500 bg-brand-50/40 dark:bg-brand-950/40 z-10' : ''} ${isHeaderRow ? 'font-bold bg-slate-50/80 dark:bg-slate-800/40' : ''} ${isNumeric ? 'text-right font-mono' : 'text-left'} ${isFormula ? 'text-emerald-700 dark:text-emerald-300 font-semibold' : 'text-slate-800 dark:text-slate-200'}"
                title="${peerOnCell ? ('Wird gerade von ' + escapeSheetHtml(peerOnCell.username) + ' bearbeitet') : (isFormula ? 'Formel: ' + rawVal.replace(/"/g, '&quot;') : '')}"
            >${escapeSheetHtml(displayVal)}${peerBadge}</td>`;
        }
        bodyHtml += '</tr>';
    }
    tbody.innerHTML = bodyHtml;

    renderSheetTabs();
    updateFormulaBar();
}

function escapeSheetHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function getPeerLockingCurrentCell(sheetIdx, r, c) {
    const target = `S${sheetIdx}:${r}:${c}`;
    return collabPeers.find(p => !p.isMe && p.focusTarget === target) || null;
}

function selectSheetCell(r, c) {
    activeRow = r;
    activeCol = c;
    const sheetName = sheetsState[activeSheetIdx]?.name || `Tabelle${activeSheetIdx + 1}`;
    currentFocusTarget = `S${activeSheetIdx}:${r}:${c}`;
    currentFocusLabel  = `${sheetName} · ${colIdxToName(c)}${r + 1}`;
    renderSpreadsheet();
    const formulaInput = document.getElementById('formulaInputBar');
    if (formulaInput && OFFICE_CAN_EDIT && !formulaInput.readOnly) {
        formulaInput.focus();
    }
}

function startInlineCellEdit(r, c) {
    if (!OFFICE_CAN_EDIT) return;
    selectSheetCell(r, c);
    const formulaInput = document.getElementById('formulaInputBar');
    if (formulaInput && !formulaInput.readOnly) {
        formulaInput.focus();
        formulaInput.select();
    }
}

function updateFormulaBar() {
    const badge = document.getElementById('activeCellRefBadge');
    const input = document.getElementById('formulaInputBar');
    if (!badge || !input) return;

    const cellRef = `${colIdxToName(activeCol)}${activeRow + 1}`;
    badge.textContent = cellRef;
    const rawVal = String(sheetsState[activeSheetIdx]?.rows?.[activeRow]?.[activeCol] ?? '');

    const lockingPeer = getPeerLockingCurrentCell(activeSheetIdx, activeRow, activeCol);
    if (lockingPeer) {
        input.readOnly = true;
        input.value = rawVal;
        input.placeholder = `🔒 Zelle ${cellRef} wird gerade von ${lockingPeer.username} bearbeitet...`;
        input.classList.add('bg-rose-50', 'dark:bg-rose-950/40', 'border-rose-400');
    } else {
        input.readOnly = !OFFICE_CAN_EDIT;
        if (document.activeElement !== input) {
            input.value = rawVal;
        }
        input.placeholder = 'Wert oder Formel eingeben (z.B. =SUMME(A1:A10), =MITTELWERT(B1:B5), =A1*B1)...';
        input.classList.remove('bg-rose-50', 'dark:bg-rose-950/40', 'border-rose-400');
    }
}

const formulaBarEl = document.getElementById('formulaInputBar');
if (formulaBarEl) {
    formulaBarEl.addEventListener('input', () => {
        if (!OFFICE_CAN_EDIT || formulaBarEl.readOnly) return;
        if (!sheetsState[activeSheetIdx].rows[activeRow]) return;
        const lockedBy = getPeerLockingCurrentCell(activeSheetIdx, activeRow, activeCol);
        if (lockedBy) {
            updateFormulaBar();
            return;
        }
        const val = formulaBarEl.value;
        sheetsState[activeSheetIdx].rows[activeRow][activeCol] = val;
        markDirty();
        queueCollabDelta('sheet_cell', `S${activeSheetIdx}:${activeRow}:${activeCol}`, {
            sheetIdx: activeSheetIdx,
            r: activeRow,
            c: activeCol,
            val: val
        });
    });
    formulaBarEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const maxR = sheetsState[activeSheetIdx].rows.length - 1;
            if (activeRow < maxR) activeRow++;
            renderSpreadsheet();
        } else if (e.key === 'Tab') {
            e.preventDefault();
            const maxC = (sheetsState[activeSheetIdx].rows[0]?.length || 1) - 1;
            if (activeCol < maxC) activeCol++;
            renderSpreadsheet();
        }
    });
    formulaBarEl.addEventListener('blur', () => {
        renderSpreadsheet();
    });
}

function insertFormulaTemplate(tpl) {
    if (!OFFICE_CAN_EDIT || !formulaBarEl || formulaBarEl.readOnly) return;
    sheetsState[activeSheetIdx].rows[activeRow][activeCol] = tpl;
    formulaBarEl.value = tpl;
    markDirty();
    queueCollabDelta('sheet_cell', `S${activeSheetIdx}:${activeRow}:${activeCol}`, {
        sheetIdx: activeSheetIdx,
        r: activeRow,
        c: activeCol,
        val: tpl
    });
    formulaBarEl.focus();
}

function sheetAddRow() {
    if (!OFFICE_CAN_EDIT) return;
    const sheet = sheetsState[activeSheetIdx];
    const cols = sheet.rows[0] ? sheet.rows[0].length : 8;
    sheet.rows.push(Array(cols).fill(''));
    markDirty();
    queueCollabDelta('sheet_full', 'sheets', sheetsState);
    renderSpreadsheet();
}

function sheetDeleteLastRow() {
    if (!OFFICE_CAN_EDIT) return;
    const sheet = sheetsState[activeSheetIdx];
    if (sheet.rows.length <= 1) return;
    sheet.rows.pop();
    if (activeRow >= sheet.rows.length) activeRow = sheet.rows.length - 1;
    markDirty();
    queueCollabDelta('sheet_full', 'sheets', sheetsState);
    renderSpreadsheet();
}

function sheetAddCol() {
    if (!OFFICE_CAN_EDIT) return;
    const sheet = sheetsState[activeSheetIdx];
    sheet.rows.forEach(row => row.push(''));
    markDirty();
    queueCollabDelta('sheet_full', 'sheets', sheetsState);
    renderSpreadsheet();
}

function sheetDeleteLastCol() {
    if (!OFFICE_CAN_EDIT) return;
    const sheet = sheetsState[activeSheetIdx];
    if (!sheet.rows[0] || sheet.rows[0].length <= 1) return;
    sheet.rows.forEach(row => row.pop());
    if (activeCol >= sheet.rows[0].length) activeCol = sheet.rows[0].length - 1;
    markDirty();
    queueCollabDelta('sheet_full', 'sheets', sheetsState);
    renderSpreadsheet();
}

function renderSheetTabs() {
    const bar = document.getElementById('sheetTabsBar');
    if (!bar) return;
    let html = '';
    sheetsState.forEach((sh, idx) => {
        const isActive = (idx === activeSheetIdx);
        html += `<div class="inline-flex items-center rounded-lg border ${isActive ? 'bg-brand-600 text-white border-brand-600 font-bold' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700'} text-xs overflow-hidden">
            <button type="button" onclick="switchSheetTab(${idx})" ondblclick="renameSheetTab(${idx})" class="px-3 py-1.5 cursor-pointer">${escapeSheetHtml(sh.name || ('Tabelle' + (idx + 1)))}</button>
            ${(OFFICE_CAN_EDIT && sheetsState.length > 1) ? `<button type="button" onclick="deleteSheetTab(${idx})" class="px-1.5 py-1.5 hover:bg-red-500 hover:text-white transition-colors cursor-pointer" title="Tabellenblatt löschen">&times;</button>` : ''}
        </div>`;
    });
    if (OFFICE_CAN_EDIT && OFFICE_FILE_EXT === 'xlsx') {
        html += `<button type="button" onclick="addSheetTab()" class="px-2.5 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-200 text-xs font-bold cursor-pointer" title="Neues Tabellenblatt hinzufügen">+ Blatt</button>`;
    }
    bar.innerHTML = html;
}

function switchSheetTab(idx) {
    activeSheetIdx = idx;
    activeRow = 0;
    activeCol = 0;
    renderSpreadsheet();
}

function addSheetTab() {
    if (!OFFICE_CAN_EDIT) return;
    const newName = `Tabelle${sheetsState.length + 1}`;
    sheetsState.push({
        name: newName,
        rows: Array.from({ length: 18 }, () => Array(8).fill(''))
    });
    activeSheetIdx = sheetsState.length - 1;
    activeRow = 0;
    activeCol = 0;
    markDirty();
    renderSpreadsheet();
}

function renameSheetTab(idx) {
    if (!OFFICE_CAN_EDIT) return;
    const current = sheetsState[idx]?.name || `Tabelle${idx + 1}`;
    const next = prompt('Neuer Name für das Tabellenblatt:', current);
    if (next && next.trim() !== '') {
        sheetsState[idx].name = next.trim().substring(0, 31);
        markDirty();
        renderSheetTabs();
    }
}

function deleteSheetTab(idx) {
    if (!OFFICE_CAN_EDIT || sheetsState.length <= 1) return;
    if (!confirm(`Möchten Sie das Tabellenblatt "${sheetsState[idx].name}" wirklich löschen?`)) return;
    sheetsState.splice(idx, 1);
    if (activeSheetIdx >= sheetsState.length) activeSheetIdx = sheetsState.length - 1;
    markDirty();
    renderSpreadsheet();
}

function exportActiveSheetCsv() {
    const sheet = sheetsState[activeSheetIdx];
    if (!sheet) return;
    const lines = sheet.rows.map((row, rIdx) =>
        row.map((_, cIdx) => {
            const val = String(evaluateSheetCell(activeSheetIdx, rIdx, cIdx) ?? '');
            return `"${val.replace(/"/g, '""')}"`;
        }).join(';')
    );
    const csvBlob = new Blob(["\uFEFF" + lines.join("\r\n")], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(csvBlob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (sheet.name || 'Tabelle') + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

if (OFFICE_EDITOR_TYPE === 'sheet') {
    currentFocusTarget = 'S0:0:0';
    currentFocusLabel  = (sheetsState[0]?.name || 'Tabelle1') + ' · A1';
    renderSpreadsheet();
}

// ============================================================================
// 2b. POWERPOINT LIGHT FUNKTIONEN (.PPTX - 16:9 Folien, Designs, Beamer & Sync)
// ============================================================================
let slidesDeckState = <?= $editorType === 'slides' ? json_encode($slidesDeckData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '{"theme":"light","transition":"fade","slides":[]}' ?>;
let activeSlideIdx  = 0;
let slidesLastTypedAt = 0;
let slidesInputDebounce = null;
let pptxPresActive  = false;
let pptxPresIdx     = 0;
let pptxLaserOn     = false;

const PPTX_THEMES = {
    light:   { bg: 'bg-white text-slate-900',         accent: 'text-orange-600', sub: 'text-slate-500', card: 'bg-slate-50 border-slate-200',       badge: 'bg-orange-100 text-orange-700', hexBg: '#FFFFFF', hexFg: '#0F172A', hexAcc: '#EA580C' },
    navy:    { bg: 'bg-slate-900 text-white',         accent: 'text-sky-400',    sub: 'text-slate-300', card: 'bg-slate-800/90 border-slate-700',   badge: 'bg-sky-500/20 text-sky-300',    hexBg: '#0F172A', hexFg: '#F8FAFC', hexAcc: '#38BDF8' },
    emerald: { bg: 'bg-emerald-950 text-emerald-50',  accent: 'text-emerald-300',sub: 'text-emerald-200/80', card: 'bg-emerald-900/70 border-emerald-800', badge: 'bg-emerald-500/20 text-emerald-200', hexBg: '#064E3B', hexFg: '#ECFDF5', hexAcc: '#34D399' },
    amber:   { bg: 'bg-amber-50 text-stone-900',      accent: 'text-amber-700',  sub: 'text-stone-600', card: 'bg-white/90 border-amber-200',       badge: 'bg-amber-200/70 text-amber-900',hexBg: '#FFFBEB', hexFg: '#1C1917', hexAcc: '#D97706' },
    rose:    { bg: 'bg-rose-950 text-rose-50',        accent: 'text-rose-300',   sub: 'text-rose-200/80', card: 'bg-rose-900/70 border-rose-800',   badge: 'bg-rose-500/20 text-rose-200',  hexBg: '#4C0519', hexFg: '#FFF1F2', hexAcc: '#FB7185' },
    slate:   { bg: 'bg-zinc-950 text-zinc-100',       accent: 'text-indigo-400', sub: 'text-zinc-400',  card: 'bg-zinc-900 border-zinc-800',        badge: 'bg-indigo-500/20 text-indigo-300', hexBg: '#18181B', hexFg: '#F4F4F5', hexAcc: '#818CF8' }
};

function ensureSlidesDeckValid() {
    if (!slidesDeckState || typeof slidesDeckState !== 'object') {
        slidesDeckState = { theme: 'light', transition: 'fade', slides: [] };
    }
    if (!PPTX_THEMES[slidesDeckState.theme]) slidesDeckState.theme = 'light';
    if (!['fade', 'slide', 'zoom', 'none'].includes(slidesDeckState.transition)) slidesDeckState.transition = 'fade';
    if (!Array.isArray(slidesDeckState.slides) || slidesDeckState.slides.length === 0) {
        slidesDeckState.slides = [{
            id: 's_' + Math.random().toString(36).slice(2, 9),
            layout: 'title',
            title: 'Präsentationstitel',
            subtitle: 'Untertitel',
            body: '',
            col2: '',
            imageId: 0,
            notes: ''
        }];
    }
    if (activeSlideIdx < 0 || activeSlideIdx >= slidesDeckState.slides.length) {
        activeSlideIdx = Math.max(0, slidesDeckState.slides.length - 1);
    }
}

function getSlidePeerLock(slideIdx) {
    const targetKey = 'slide:' + slideIdx;
    return collabPeers.find(p => !p.isMe && p.focusTarget === targetKey) || null;
}

function queueActiveSlideDelta() {
    const s = slidesDeckState.slides[activeSlideIdx];
    if (!s) return;
    if (slidesInputDebounce) clearTimeout(slidesInputDebounce);
    slidesInputDebounce = setTimeout(() => {
        queueCollabDelta('slides_slide', 'slide:' + activeSlideIdx, {
            slideIdx: activeSlideIdx,
            slide: s
        });
    }, 220);
}

function queueFullDeckDelta() {
    queueCollabDelta('slides_full', 'deck', slidesDeckState);
}

function renderPptxThumbnails() {
    const listEl = document.getElementById('pptxThumbnailsList');
    if (!listEl) return;
    ensureSlidesDeckValid();
    const themeObj = PPTX_THEMES[slidesDeckState.theme] || PPTX_THEMES.light;

    let html = '';
    slidesDeckState.slides.forEach((sl, idx) => {
        const isActive = idx === activeSlideIdx;
        const peerLock = getSlidePeerLock(idx);
        const ringCls  = isActive
            ? 'ring-2 ring-orange-500 border-orange-500 shadow-sm'
            : 'border-slate-200 dark:border-slate-700 hover:border-orange-300';
        const peerBadge = peerLock
            ? `<span style="background:${peerLock.color};" class="px-1.5 py-0.5 rounded text-[9px] font-bold text-white truncate max-w-[100px]">🔒 ${escapeSheetHtml(peerLock.username)}</span>`
            : '';

        const layoutIcons = { title: '🎯', bullets: '📋', two_col: '⚖️', image_text: '🖼️', big_stat: '🚀', section: '🔖' };
        const icon = layoutIcons[sl.layout] || '📋';

        html += `
        <div onclick="pptxSelectSlide(${idx})"
             class="group cursor-pointer rounded-xl border ${ringCls} bg-white dark:bg-slate-800 p-2 transition select-none">
            <div class="flex items-center justify-between text-[11px] font-bold text-slate-500 dark:text-slate-400 mb-1.5">
                <span>Folie ${idx + 1} · ${icon}</span>
                ${peerBadge}
            </div>
            <div class="aspect-16/9 rounded-lg ${themeObj.bg} p-2.5 flex flex-col justify-between overflow-hidden border border-black/10">
                <div class="text-[11px] font-extrabold leading-tight line-clamp-2">${escapeSheetHtml(sl.title || 'Ohne Titel')}</div>
                <div class="text-[9px] ${themeObj.sub} line-clamp-2">${escapeSheetHtml(sl.subtitle || (sl.body || '').split('\n')[0] || '')}</div>
            </div>
        </div>`;
    });
    listEl.innerHTML = html;
}

function renderPptxEditor() {
    if (OFFICE_EDITOR_TYPE !== 'slides') return;
    ensureSlidesDeckValid();

    const sl = slidesDeckState.slides[activeSlideIdx];
    const themeObj = PPTX_THEMES[slidesDeckState.theme] || PPTX_THEMES.light;

    currentFocusTarget = 'slide:' + activeSlideIdx;
    currentFocusLabel  = 'Folie ' + (activeSlideIdx + 1);

    const layoutSel = document.getElementById('pptxSlideLayoutSelect');
    if (layoutSel) layoutSel.value = sl.layout || 'bullets';

    const themeSel = document.getElementById('pptxDeckThemeSelect');
    if (themeSel) themeSel.value = slidesDeckState.theme || 'light';

    const transSel = document.getElementById('pptxTransitionSelect');
    if (transSel) transSel.value = slidesDeckState.transition || 'fade';

    const counterEl = document.getElementById('pptxSlideCounterLabel');
    if (counterEl) counterEl.textContent = `Folie ${activeSlideIdx + 1} / ${slidesDeckState.slides.length}`;

    const notesEl = document.getElementById('pptxSpeakerNotesInput');
    if (notesEl && document.activeElement !== notesEl) {
        notesEl.value = sl.notes || '';
    }

    const imgBar = document.getElementById('pptxImagePickerBar');
    const imgSel = document.getElementById('pptxCloudImageSelect');
    if (imgBar) {
        if (sl.layout === 'image_text' && OFFICE_CAN_EDIT) {
            imgBar.classList.remove('hidden');
            if (imgSel) imgSel.value = String(sl.imageId || 0);
        } else {
            imgBar.classList.add('hidden');
        }
    }

    renderPptxThumbnails();

    const stage = document.getElementById('pptxStageContainer');
    if (!stage) return;

    stage.className = `w-full max-w-4xl mx-auto aspect-16/9 rounded-2xl shadow-lg border border-slate-300/80 dark:border-slate-700 p-6 sm:p-10 flex flex-col justify-between transition-colors relative overflow-hidden ${themeObj.bg}`;

    const peerLock = getSlidePeerLock(activeSlideIdx);
    const lockBanner = peerLock
        ? `<div style="background:${peerLock.color};" class="absolute top-3 right-3 px-2.5 py-1 rounded-full text-[11px] font-bold text-white shadow-sm z-20">🔒 Wird gerade von ${escapeSheetHtml(peerLock.username)} bearbeitet</div>`
        : '';

    const roAttr = OFFICE_CAN_EDIT ? '' : 'readonly';
    const inputBase = 'w-full bg-transparent border border-transparent hover:border-current/20 focus:border-orange-500 focus:bg-black/5 rounded-xl px-2.5 py-1.5 focus:outline-none transition';

    let innerHtml = lockBanner;

    if (sl.layout === 'title') {
        innerHtml += `
        <div class="my-auto text-center space-y-4 px-4">
            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider ${themeObj.badge}">Präsentation</span>
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Haupttitel eingeben…"
                   class="${inputBase} text-2xl sm:text-4xl font-extrabold text-center tracking-tight">
            <input type="text" id="pptxFieldSubtitle" ${roAttr} value="${escapeSheetHtml(sl.subtitle || '')}"
                   placeholder="Untertitel, Datum oder Referent…"
                   class="${inputBase} text-sm sm:text-lg font-medium text-center ${themeObj.sub}">
        </div>`;
    } else if (sl.layout === 'section') {
        innerHtml += `
        <div class="my-auto space-y-3 border-l-4 border-orange-500 pl-6 py-4">
            <span class="text-xs font-bold uppercase tracking-widest ${themeObj.accent}">Kapitel / Abschnitt</span>
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Kapitelüberschrift…"
                   class="${inputBase} text-2xl sm:text-4xl font-extrabold tracking-tight">
            <input type="text" id="pptxFieldSubtitle" ${roAttr} value="${escapeSheetHtml(sl.subtitle || '')}"
                   placeholder="Kurze Beschreibung des Abschnitts…"
                   class="${inputBase} text-sm sm:text-base ${themeObj.sub}">
        </div>`;
    } else if (sl.layout === 'big_stat') {
        innerHtml += `
        <div class="flex flex-col justify-between h-full">
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Überschrift / Kontext…"
                   class="${inputBase} text-lg sm:text-2xl font-bold">
            <div class="my-auto text-center space-y-2 py-4">
                <input type="text" id="pptxFieldSubtitle" ${roAttr} value="${escapeSheetHtml(sl.subtitle || '')}"
                       placeholder="98 % oder große Kennzahl…"
                       class="${inputBase} text-4xl sm:text-6xl font-black text-center ${themeObj.accent}">
                <textarea id="pptxFieldBody" rows="2" ${roAttr}
                          placeholder="Erklärung oder Zitat unter der Kennzahl…"
                          class="${inputBase} text-center text-xs sm:text-base ${themeObj.sub} resize-none">${escapeSheetHtml(sl.body || '')}</textarea>
            </div>
        </div>`;
    } else if (sl.layout === 'two_col') {
        innerHtml += `
        <div class="flex flex-col h-full gap-3">
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Folientitel…"
                   class="${inputBase} text-xl sm:text-2xl font-extrabold">
            <div class="grid grid-cols-2 gap-4 flex-1 min-h-0">
                <div class="rounded-xl border p-3 flex flex-col ${themeObj.card}">
                    <span class="text-[11px] font-bold uppercase tracking-wider mb-1 ${themeObj.accent}">Linke Spalte</span>
                    <textarea id="pptxFieldBody" ${roAttr}
                              placeholder="Stichpunkte links (1 Punkt pro Zeile)…"
                              class="w-full flex-1 bg-transparent resize-none text-xs sm:text-sm leading-relaxed focus:outline-none">${escapeSheetHtml(sl.body || '')}</textarea>
                </div>
                <div class="rounded-xl border p-3 flex flex-col ${themeObj.card}">
                    <span class="text-[11px] font-bold uppercase tracking-wider mb-1 ${themeObj.accent}">Rechte Spalte</span>
                    <textarea id="pptxFieldCol2" ${roAttr}
                              placeholder="Stichpunkte rechts (1 Punkt pro Zeile)…"
                              class="w-full flex-1 bg-transparent resize-none text-xs sm:text-sm leading-relaxed focus:outline-none">${escapeSheetHtml(sl.col2 || '')}</textarea>
                </div>
            </div>
        </div>`;
    } else if (sl.layout === 'image_text') {
        const imgPreviewHtml = sl.imageId > 0
            ? `<img src="preview.php?id=${Number(sl.imageId)}&raw=1" alt="Folienbild" class="w-full h-full object-cover rounded-xl">`
            : `<div class="text-center p-4 text-xs ${themeObj.sub}">🖼️<br>Wähle unten ein Bild aus deiner Cloud</div>`;
        innerHtml += `
        <div class="flex flex-col h-full gap-3">
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Folientitel…"
                   class="${inputBase} text-xl sm:text-2xl font-extrabold">
            <div class="grid grid-cols-12 gap-4 flex-1 min-h-0">
                <div class="col-span-5 rounded-xl border overflow-hidden flex items-center justify-center ${themeObj.card}">
                    ${imgPreviewHtml}
                </div>
                <div class="col-span-7 flex flex-col">
                    <textarea id="pptxFieldBody" ${roAttr}
                              placeholder="Erläuterungen & Stichpunkte (1 Punkt pro Zeile)…"
                              class="${inputBase} flex-1 resize-none text-xs sm:text-base leading-relaxed">${escapeSheetHtml(sl.body || '')}</textarea>
                </div>
            </div>
        </div>`;
    } else {
        // Standard: bullets
        innerHtml += `
        <div class="flex flex-col h-full gap-2">
            <input type="text" id="pptxFieldTitle" ${roAttr} value="${escapeSheetHtml(sl.title || '')}"
                   placeholder="Folientitel eingeben…"
                   class="${inputBase} text-xl sm:text-3xl font-extrabold">
            <input type="text" id="pptxFieldSubtitle" ${roAttr} value="${escapeSheetHtml(sl.subtitle || '')}"
                   placeholder="Optionaler Untertitel / Kernaussage…"
                   class="${inputBase} text-xs sm:text-sm font-medium ${themeObj.accent}">
            <textarea id="pptxFieldBody" ${roAttr}
                      placeholder="• Stichpunkte hier eingeben (jede Zeile wird ein Aufzählungspunkt)…"
                      class="${inputBase} flex-1 resize-none text-sm sm:text-lg leading-relaxed">${escapeSheetHtml(sl.body || '')}</textarea>
        </div>`;
    }

    innerHtml += `
    <div class="pt-2 border-t border-current/10 flex items-center justify-between text-[10px] ${themeObj.sub}">
        <span>${escapeSheetHtml(titleInputEl ? titleInputEl.value : '')}</span>
        <span>${activeSlideIdx + 1} / ${slidesDeckState.slides.length}</span>
    </div>`;

    stage.innerHTML = innerHtml;

    const bindField = (elId, propName, refreshThumbs = false) => {
        const el = document.getElementById(elId);
        if (!el) return;
        el.addEventListener('input', () => {
            slidesLastTypedAt = Date.now();
            slidesDeckState.slides[activeSlideIdx][propName] = el.value;
            markDirty();
            if (refreshThumbs) renderPptxThumbnails();
            queueActiveSlideDelta();
        });
    };

    bindField('pptxFieldTitle', 'title', true);
    bindField('pptxFieldSubtitle', 'subtitle', true);
    bindField('pptxFieldBody', 'body', true);
    bindField('pptxFieldCol2', 'col2', false);
}

const pptxNotesInputEl = document.getElementById('pptxSpeakerNotesInput');
if (pptxNotesInputEl) {
    pptxNotesInputEl.addEventListener('input', () => {
        ensureSlidesDeckValid();
        slidesLastTypedAt = Date.now();
        slidesDeckState.slides[activeSlideIdx].notes = pptxNotesInputEl.value;
        markDirty();
        queueActiveSlideDelta();
    });
}

function pptxSelectSlide(idx) {
    ensureSlidesDeckValid();
    if (idx < 0 || idx >= slidesDeckState.slides.length) return;
    activeSlideIdx = idx;
    renderPptxEditor();
}

function pptxAddSlide() {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    const newSlide = {
        id: 's_' + Math.random().toString(36).slice(2, 9),
        layout: 'bullets',
        title: 'Neue Folie ' + (slidesDeckState.slides.length + 1),
        subtitle: '',
        body: "Erster Punkt\nZweiter Punkt\nDritter Punkt",
        col2: '',
        imageId: 0,
        notes: ''
    };
    slidesDeckState.slides.splice(activeSlideIdx + 1, 0, newSlide);
    activeSlideIdx++;
    markDirty();
    renderPptxEditor();
    queueFullDeckDelta();
}

function pptxDuplicateSlide() {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    const cur = slidesDeckState.slides[activeSlideIdx];
    const copy = JSON.parse(JSON.stringify(cur));
    copy.id = 's_' + Math.random().toString(36).slice(2, 9);
    copy.title = (copy.title || 'Folie') + ' (Kopie)';
    slidesDeckState.slides.splice(activeSlideIdx + 1, 0, copy);
    activeSlideIdx++;
    markDirty();
    renderPptxEditor();
    queueFullDeckDelta();
}

function pptxMoveSlide(dir) {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    const targetIdx = activeSlideIdx + dir;
    if (targetIdx < 0 || targetIdx >= slidesDeckState.slides.length) return;
    const tmp = slidesDeckState.slides[activeSlideIdx];
    slidesDeckState.slides[activeSlideIdx] = slidesDeckState.slides[targetIdx];
    slidesDeckState.slides[targetIdx] = tmp;
    activeSlideIdx = targetIdx;
    markDirty();
    renderPptxEditor();
    queueFullDeckDelta();
}

function pptxDeleteSlide() {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    if (slidesDeckState.slides.length <= 1) {
        alert('Eine Präsentation muss mindestens eine Folie enthalten.');
        return;
    }
    if (!confirm(`Folie ${activeSlideIdx + 1} wirklich löschen?`)) return;
    slidesDeckState.slides.splice(activeSlideIdx, 1);
    if (activeSlideIdx >= slidesDeckState.slides.length) {
        activeSlideIdx = slidesDeckState.slides.length - 1;
    }
    markDirty();
    renderPptxEditor();
    queueFullDeckDelta();
}

function pptxChangeLayout(newLayout) {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    slidesDeckState.slides[activeSlideIdx].layout = newLayout;
    markDirty();
    renderPptxEditor();
    queueActiveSlideDelta();
}

function pptxChangeTheme(newTheme) {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    slidesDeckState.theme = newTheme;
    markDirty();
    renderPptxEditor();
    queueFullDeckDelta();
}

function pptxChangeTransition(newTrans) {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    slidesDeckState.transition = newTrans;
    markDirty();
    queueFullDeckDelta();
}

function pptxSelectCloudImage(imgIdStr) {
    if (!OFFICE_CAN_EDIT) return;
    ensureSlidesDeckValid();
    slidesDeckState.slides[activeSlideIdx].imageId = parseInt(imgIdStr, 10) || 0;
    markDirty();
    renderPptxEditor();
    queueActiveSlideDelta();
}

function formatBulletsHtml(rawText, accentClass) {
    const lines = String(rawText || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    if (lines.length === 0) return '';
    return '<ul class="space-y-2.5 text-left">' + lines.map(l => {
        const clean = l.replace(/^[•\-*]\s*/, '');
        return `<li class="flex items-start gap-2.5"><span class="${accentClass} font-bold">▸</span><span>${escapeSheetHtml(clean)}</span></li>`;
    }).join('') + '</ul>';
}

function renderPresentationSlideBox(idx) {
    ensureSlidesDeckValid();
    const sl = slidesDeckState.slides[idx] || slidesDeckState.slides[0];
    const themeObj = PPTX_THEMES[slidesDeckState.theme] || PPTX_THEMES.light;
    const box = document.getElementById('pptxPresSlideBox');
    if (!box) return;

    const tr = slidesDeckState.transition || 'fade';
    box.style.opacity = tr === 'none' ? '1' : '0';
    box.style.transform = tr === 'zoom' ? 'scale(0.95)' : (tr === 'slide' ? 'translateX(24px)' : 'none');

    setTimeout(() => {
        box.className = `w-full max-w-6xl aspect-16/9 rounded-2xl shadow-2xl p-8 sm:p-16 flex flex-col justify-between transition-all duration-300 relative overflow-hidden ${themeObj.bg}`;

        let html = '';
        if (sl.layout === 'title') {
            html = `
            <div class="my-auto text-center space-y-5 px-6">
                <span class="inline-block px-4 py-1 rounded-full text-xs sm:text-sm font-bold uppercase tracking-widest ${themeObj.badge}">Präsentation</span>
                <h1 class="text-3xl sm:text-6xl font-extrabold tracking-tight leading-tight">${escapeSheetHtml(sl.title || '')}</h1>
                <p class="text-lg sm:text-2xl ${themeObj.sub}">${escapeSheetHtml(sl.subtitle || '')}</p>
            </div>`;
        } else if (sl.layout === 'section') {
            html = `
            <div class="my-auto border-l-8 border-orange-500 pl-8 py-6 space-y-3">
                <div class="text-sm font-bold uppercase tracking-widest ${themeObj.accent}">Abschnitt</div>
                <h1 class="text-3xl sm:text-6xl font-extrabold tracking-tight">${escapeSheetHtml(sl.title || '')}</h1>
                <p class="text-lg sm:text-2xl ${themeObj.sub}">${escapeSheetHtml(sl.subtitle || '')}</p>
            </div>`;
        } else if (sl.layout === 'big_stat') {
            html = `
            <div class="flex flex-col justify-between h-full">
                <h2 class="text-2xl sm:text-4xl font-extrabold">${escapeSheetHtml(sl.title || '')}</h2>
                <div class="my-auto text-center space-y-4">
                    <div class="text-5xl sm:text-8xl font-black ${themeObj.accent}">${escapeSheetHtml(sl.subtitle || '')}</div>
                    <p class="text-base sm:text-2xl max-w-3xl mx-auto ${themeObj.sub}">${escapeSheetHtml(sl.body || '')}</p>
                </div>
            </div>`;
        } else if (sl.layout === 'two_col') {
            html = `
            <div class="flex flex-col h-full gap-6">
                <h2 class="text-2xl sm:text-4xl font-extrabold">${escapeSheetHtml(sl.title || '')}</h2>
                <div class="grid grid-cols-2 gap-6 flex-1 items-start">
                    <div class="rounded-2xl border p-6 h-full text-base sm:text-xl ${themeObj.card}">
                        ${formatBulletsHtml(sl.body, themeObj.accent)}
                    </div>
                    <div class="rounded-2xl border p-6 h-full text-base sm:text-xl ${themeObj.card}">
                        ${formatBulletsHtml(sl.col2, themeObj.accent)}
                    </div>
                </div>
            </div>`;
        } else if (sl.layout === 'image_text') {
            const imgHtml = sl.imageId > 0
                ? `<img src="preview.php?id=${Number(sl.imageId)}&raw=1" alt="" class="w-full h-full object-cover rounded-2xl">`
                : `<div class="text-sm ${themeObj.sub}">Kein Bild gewählt</div>`;
            html = `
            <div class="flex flex-col h-full gap-6">
                <h2 class="text-2xl sm:text-4xl font-extrabold">${escapeSheetHtml(sl.title || '')}</h2>
                <div class="grid grid-cols-12 gap-6 flex-1 min-h-0">
                    <div class="col-span-5 rounded-2xl border overflow-hidden flex items-center justify-center ${themeObj.card}">${imgHtml}</div>
                    <div class="col-span-7 text-base sm:text-2xl flex items-center">${formatBulletsHtml(sl.body, themeObj.accent)}</div>
                </div>
            </div>`;
        } else {
            html = `
            <div class="flex flex-col h-full gap-4">
                <div>
                    <h2 class="text-2xl sm:text-5xl font-extrabold">${escapeSheetHtml(sl.title || '')}</h2>
                    ${sl.subtitle ? `<p class="text-sm sm:text-xl font-semibold mt-1 ${themeObj.accent}">${escapeSheetHtml(sl.subtitle)}</p>` : ''}
                </div>
                <div class="flex-1 text-base sm:text-2xl pt-2">
                    ${formatBulletsHtml(sl.body, themeObj.accent)}
                </div>
            </div>`;
        }

        html += `
        <div class="pt-3 border-t border-current/10 flex items-center justify-between text-xs ${themeObj.sub}">
            <span>${escapeSheetHtml(titleInputEl ? titleInputEl.value : '')}</span>
            <span>Folie ${idx + 1} / ${slidesDeckState.slides.length}</span>
        </div>`;

        box.innerHTML = html;
        box.style.opacity = '1';
        box.style.transform = 'none';
    }, tr === 'none' ? 0 : 120);

    const cnt = document.getElementById('pptxPresCounter');
    if (cnt) cnt.textContent = `${idx + 1} / ${slidesDeckState.slides.length}`;

    const notesHint = document.getElementById('pptxPresNotesHint');
    if (notesHint) {
        notesHint.textContent = '🎙️ Sprechernotiz: ' + (sl.notes ? sl.notes : '– (keine Notiz hinterlegt)');
    }
}

function pptxStartPresentation(startIdx = 0) {
    ensureSlidesDeckValid();
    pptxPresActive = true;
    pptxPresIdx = (typeof startIdx === 'number' && startIdx >= 0) ? startIdx : activeSlideIdx;
    const modal = document.getElementById('pptxPresenterModal');
    if (modal) {
        modal.classList.remove('hidden');
        if (modal.requestFullscreen) {
            modal.requestFullscreen().catch(() => {});
        }
    }
    renderPresentationSlideBox(pptxPresIdx);
}

function pptxStepPresentation(delta) {
    if (!pptxPresActive) return;
    const next = pptxPresIdx + delta;
    if (next < 0 || next >= slidesDeckState.slides.length) return;
    pptxPresIdx = next;
    renderPresentationSlideBox(pptxPresIdx);
}

function pptxStopPresentation() {
    pptxPresActive = false;
    pptxLaserOn = false;
    const dot = document.getElementById('pptxLaserDot');
    if (dot) dot.classList.add('hidden');
    const modal = document.getElementById('pptxPresenterModal');
    if (modal) modal.classList.add('hidden');
    if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
    }
}

function pptxToggleLaser() {
    pptxLaserOn = !pptxLaserOn;
    const dot = document.getElementById('pptxLaserDot');
    const btn = document.getElementById('btnToggleLaser');
    if (dot) dot.classList.toggle('hidden', !pptxLaserOn);
    if (btn) btn.classList.toggle('bg-red-600', pptxLaserOn);
}

function pptxMoveLaser(e) {
    if (!pptxLaserOn) return;
    const dot = document.getElementById('pptxLaserDot');
    if (!dot) return;
    dot.style.left = e.clientX + 'px';
    dot.style.top  = e.clientY + 'px';
}

document.addEventListener('keydown', (e) => {
    if (!pptxPresActive) return;
    if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') {
        e.preventDefault();
        pptxStepPresentation(1);
    } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
        e.preventDefault();
        pptxStepPresentation(-1);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        pptxStopPresentation();
    } else if (e.key === 'l' || e.key === 'L') {
        e.preventDefault();
        pptxToggleLaser();
    }
});

function pptxPrintHandout() {
    ensureSlidesDeckValid();
    const themeObj = PPTX_THEMES[slidesDeckState.theme] || PPTX_THEMES.light;
    const docName  = titleInputEl ? titleInputEl.value : 'Praesentation';
    const win = window.open('', '_blank', 'width=960,height=750');
    if (!win) {
        alert('Bitte Popups für den Handout-Druck erlauben.');
        return;
    }

    let slidesHtml = '';
    slidesDeckState.slides.forEach((sl, i) => {
        const bullets = String(sl.body || '').split(/\r?\n/).filter(Boolean).map(l => `<li>${escapeSheetHtml(l.replace(/^[•\-*]\s*/, ''))}</li>`).join('');
        const col2    = String(sl.col2 || '').split(/\r?\n/).filter(Boolean).map(l => `<li>${escapeSheetHtml(l.replace(/^[•\-*]\s*/, ''))}</li>`).join('');
        slidesHtml += `
        <div class="slide-wrap">
            <div class="slide-box" style="background:${themeObj.hexBg};color:${themeObj.hexFg};border-left:6px solid ${themeObj.hexAcc};">
                <div class="slide-num">Folie ${i + 1} / ${slidesDeckState.slides.length}</div>
                <h2>${escapeSheetHtml(sl.title || '')}</h2>
                ${sl.subtitle ? `<p class="sub" style="color:${themeObj.hexAcc};">${escapeSheetHtml(sl.subtitle)}</p>` : ''}
                ${bullets ? `<ul>${bullets}</ul>` : ''}
                ${col2 ? `<ul>${col2}</ul>` : ''}
            </div>
            ${sl.notes ? `<div class="notes"><strong>🎙️ Sprechernotiz:</strong> ${escapeSheetHtml(sl.notes)}</div>` : ''}
        </div>`;
    });

    win.document.write(`<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>${escapeSheetHtml(docName)} - Handout</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 24px; color: #0f172a; }
        h1 { font-size: 20px; margin-bottom: 16px; }
        .slide-wrap { page-break-inside: avoid; margin-bottom: 22px; }
        .slide-box { border: 1px solid #cbd5e1; border-radius: 12px; padding: 20px 24px; }
        .slide-num { font-size: 11px; opacity: 0.7; margin-bottom: 6px; font-weight: bold; }
        .slide-box h2 { margin: 0 0 6px 0; font-size: 20px; }
        .slide-box .sub { margin: 0 0 10px 0; font-weight: 600; font-size: 14px; }
        .slide-box ul { margin: 8px 0 0 18px; font-size: 13px; line-height: 1.5; }
        .notes { margin-top: 6px; padding: 8px 12px; background: #f8fafc; border-left: 3px solid #94a3b8; font-size: 12px; color: #334155; }
    </style></head><body>
    <h1>📙 Handout: ${escapeSheetHtml(docName)}</h1>
    ${slidesHtml}
    <script>window.onload = () => { window.print(); };<\/script>
    </body></html>`);
    win.document.close();
}

if (OFFICE_EDITOR_TYPE === 'slides') {
    ensureSlidesDeckValid();
    currentFocusTarget = 'slide:0';
    currentFocusLabel  = 'Folie 1';
    renderPptxEditor();
}

// ============================================================================
// 2c. VISIO LIGHT FUNKTIONEN (.DRAWIO DIAGRAMME, FLUSSDIAGRAMME & ORGANIGRAMME)
// ============================================================================
let visioState = <?= $editorType === 'visio' ? json_encode($visioDiagramData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '{"theme":"light","grid":true,"nodes":[],"edges":[]}' ?>;
let visioSelectedNodeId = null;
let visioSelectedEdgeId = null;
let visioConnectMode    = false;
let visioConnectFromId  = null;
let visioDraggingNode   = null;
let visioDragOffsetX    = 0;
let visioDragOffsetY    = 0;
let visioLastTypedAt    = 0;
let visioInputDebounce  = null;

const VISIO_PALETTES = {
    blue:    { fill: '#EFF6FF', stroke: '#2563EB', text: '#1E3A8A', sub: '#3B82F6' },
    emerald: { fill: '#ECFDF5', stroke: '#059669', text: '#064E3B', sub: '#10B981' },
    amber:   { fill: '#FFFBEB', stroke: '#D97706', text: '#78350F', sub: '#D97706' },
    rose:    { fill: '#FFF1F2', stroke: '#E11D48', text: '#881337', sub: '#F43F5E' },
    violet:  { fill: '#F5F3FF', stroke: '#7C3AED', text: '#4C1D95', sub: '#8B5CF6' },
    slate:   { fill: '#F8FAFC', stroke: '#475569', text: '#0F172A', sub: '#64748B' }
};

function ensureVisioValid() {
    if (!visioState || typeof visioState !== 'object') {
        visioState = { theme: 'light', grid: true, nodes: [], edges: [] };
    }
    if (!Array.isArray(visioState.nodes)) visioState.nodes = [];
    if (!Array.isArray(visioState.edges)) visioState.edges = [];
    if (!['light', 'plain', 'blueprint', 'dark'].includes(visioState.theme)) {
        visioState.theme = 'light';
    }
}

function getVisioPeerLock(nodeId) {
    const targetKey = 'vnode:' + nodeId;
    return collabPeers.find(p => !p.isMe && p.focusTarget === targetKey) || null;
}

function queueVisioDelta() {
    if (visioInputDebounce) clearTimeout(visioInputDebounce);
    visioInputDebounce = setTimeout(() => {
        queueCollabDelta('visio_full', 'diagram', visioState);
    }, 220);
}

function updateVisioInspectorUI() {
    const hintEl = document.getElementById('visioInspectorHint');
    const nodeBar = document.getElementById('visioNodeInspector');
    const edgeBar = document.getElementById('visioEdgeInspector');
    if (!hintEl || !nodeBar || !edgeBar) return;

    if (visioSelectedNodeId) {
        const nd = visioState.nodes.find(n => n.id === visioSelectedNodeId);
        if (nd) {
            hintEl.classList.add('hidden');
            edgeBar.classList.add('hidden');
            edgeBar.classList.remove('flex');
            nodeBar.classList.remove('hidden');
            nodeBar.classList.add('flex');

            const tEl = document.getElementById('visioInpText');
            const sEl = document.getElementById('visioInpSubtext');
            const shEl = document.getElementById('visioInpShape');
            const cEl = document.getElementById('visioInpColor');
            if (tEl && document.activeElement !== tEl) tEl.value = nd.text || '';
            if (sEl && document.activeElement !== sEl) sEl.value = nd.subtext || '';
            if (shEl) shEl.value = nd.shape || 'rect';
            if (cEl) cEl.value = nd.color || 'blue';

            currentFocusTarget = 'vnode:' + nd.id;
            currentFocusLabel  = 'Form „' + (nd.text || nd.id) + '“';
            return;
        }
    }

    if (visioSelectedEdgeId) {
        const ed = visioState.edges.find(e => e.id === visioSelectedEdgeId);
        if (ed) {
            hintEl.classList.add('hidden');
            nodeBar.classList.add('hidden');
            nodeBar.classList.remove('flex');
            edgeBar.classList.remove('hidden');
            edgeBar.classList.add('flex');

            const lEl = document.getElementById('visioInpEdgeLabel');
            const stEl = document.getElementById('visioInpEdgeStyle');
            const rEl = document.getElementById('visioInpEdgeRouting');
            if (lEl && document.activeElement !== lEl) lEl.value = ed.label || '';
            if (stEl) stEl.value = ed.style || 'solid';
            if (rEl) rEl.value = ed.routing || 'curved';

            currentFocusTarget = 'vedge:' + ed.id;
            currentFocusLabel  = 'Verbindungspfeil';
            return;
        }
    }

    hintEl.classList.remove('hidden');
    nodeBar.classList.add('hidden');
    nodeBar.classList.remove('flex');
    edgeBar.classList.add('hidden');
    edgeBar.classList.remove('flex');
    currentFocusTarget = 'diagram';
    currentFocusLabel  = 'Zeichenfläche';
}

function renderVisioCanvas() {
    if (OFFICE_EDITOR_TYPE !== 'visio') return;
    ensureVisioValid();

    const svg = document.getElementById('visioSvgCanvas');
    if (!svg) return;

    const themeSel = document.getElementById('visioThemeSelect');
    if (themeSel) themeSel.value = visioState.theme || 'light';

    const statsEl = document.getElementById('visioStatsLabel');
    if (statsEl) {
        statsEl.textContent = `${visioState.nodes.length} Formen • ${visioState.edges.length} Verbindungen`;
    }

    const isDark = (visioState.theme === 'blueprint' || visioState.theme === 'dark');
    const bgFill = visioState.theme === 'blueprint'
        ? '#0F172A'
        : (visioState.theme === 'dark' ? '#18181B' : (visioState.theme === 'plain' ? '#FFFFFF' : '#F8FAFC'));
    const dotColor = visioState.theme === 'blueprint' ? '#1E3A8A' : (isDark ? '#27272A' : '#CBD5E1');
    const edgeColor = isDark ? '#94A3B8' : '#475569';

    let html = `
    <defs>
        <marker id="v-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">
            <path d="M 0 1 L 10 5 L 0 9 z" fill="${edgeColor}"/>
        </marker>
        <marker id="v-arrow-sel" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M 0 1 L 10 5 L 0 9 z" fill="#0D9488"/>
        </marker>
        <pattern id="v-grid" width="24" height="24" patternUnits="userSpaceOnUse">
            <circle cx="2" cy="2" r="1.2" fill="${dotColor}"/>
        </pattern>
        <filter id="v-shadow" x="-10%" y="-10%" width="120%" height="125%">
            <feDropShadow dx="0" dy="3" stdDeviation="3.5" flood-opacity="0.12"/>
        </filter>
    </defs>
    <rect id="visioBgRect" width="100%" height="100%" fill="${bgFill}"/>`;

    if (visioState.theme !== 'plain') {
        html += `<rect id="visioGridRect" width="100%" height="100%" fill="url(#v-grid)" pointer-events="none"/>`;
    }

    const nodeMap = {};
    visioState.nodes.forEach(n => { nodeMap[n.id] = n; });

    // 1. Verbindungspfeile rendern
    visioState.edges.forEach(ed => {
        const n1 = nodeMap[ed.from];
        const n2 = nodeMap[ed.to];
        if (!n1 || !n2) return;

        const x1 = n1.x + n1.w / 2;
        const y1 = n1.y + n1.h / 2;
        const x2 = n2.x + n2.w / 2;
        const y2 = n2.y + n2.h / 2;

        // Schnittpunkt am Rand des Ziel-Rechtecks berechnen, damit die Pfeilspitze sichtbar am Rand endet
        const dx = x2 - x1;
        const dy = y2 - y1;
        let tx = x2, ty = y2;
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
            const hw = (n2.w / 2) + 6;
            const hh = (n2.h / 2) + 6;
            const scaleX = Math.abs(dx) > 0 ? hw / Math.abs(dx) : 9999;
            const scaleY = Math.abs(dy) > 0 ? hh / Math.abs(dy) : 9999;
            const scale = Math.min(scaleX, scaleY, 1);
            tx = x2 - dx * scale;
            ty = y2 - dy * scale;
        }

        const isSel = (ed.id === visioSelectedEdgeId);
        const strokeCol = isSel ? '#0D9488' : edgeColor;
        const strokeW   = isSel ? 3.2 : 2.2;
        const dashAttr  = ed.style === 'dashed' ? 'stroke-dasharray="7,5"' : '';
        const markerUrl = isSel ? 'url(#v-arrow-sel)' : 'url(#v-arrow)';

        let dPath = '';
        if (ed.routing === 'ortho') {
            const midY = (y1 + ty) / 2;
            dPath = `M ${x1} ${y1} L ${x1} ${midY} L ${tx} ${midY} L ${tx} ${ty}`;
        } else if (ed.routing === 'straight') {
            dPath = `M ${x1} ${y1} L ${tx} ${ty}`;
        } else {
            const cx1 = x1 + (tx - x1) * 0.45;
            const cy1 = y1;
            const cx2 = tx - (tx - x1) * 0.45;
            const cy2 = ty;
            dPath = `M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${tx} ${ty}`;
        }

        const mx = (x1 + tx) / 2;
        const my = (y1 + ty) / 2;

        html += `
        <g class="cursor-pointer" onclick="visioSelectEdge('${escapeSheetHtml(ed.id)}', event)">
            <path d="${dPath}" fill="none" stroke="transparent" stroke-width="14"/>
            <path d="${dPath}" fill="none" stroke="${strokeCol}" stroke-width="${strokeW}" ${dashAttr} marker-end="${markerUrl}"/>
            ${ed.label ? `
                <rect x="${mx - 36}" y="${my - 11}" width="72" height="20" rx="6" fill="${bgFill}" stroke="${strokeCol}" stroke-width="1.2"/>
                <text x="${mx}" y="${my + 3}" text-anchor="middle" font-size="11" font-weight="700" fill="${strokeCol}">${escapeSheetHtml(ed.label)}</text>
            ` : ''}
        </g>`;
    });

    // 2. Formen / Knoten rendern
    visioState.nodes.forEach(nd => {
        const pal = VISIO_PALETTES[nd.color] || VISIO_PALETTES.blue;
        const isSel = (nd.id === visioSelectedNodeId);
        const isConnSource = (visioConnectMode && nd.id === visioConnectFromId);
        const peerLock = getVisioPeerLock(nd.id);

        const x = Number(nd.x) || 100;
        const y = Number(nd.y) || 100;
        const w = Number(nd.w) || 180;
        const h = Number(nd.h) || 72;
        const cx = x + w / 2;
        const cy = y + h / 2;

        const strokeCol = isConnSource ? '#EA580C' : (isSel ? '#0D9488' : (peerLock ? peerLock.color : pal.stroke));
        const strokeW   = (isSel || isConnSource || peerLock) ? 3.2 : 2;

        let shapeSvg = '';
        if (nd.shape === 'diamond') {
            const pts = `${cx},${y} ${x + w},${cy} ${cx},${y + h} ${x},${cy}`;
            shapeSvg = `<polygon points="${pts}" fill="${pal.fill}" stroke="${strokeCol}" stroke-width="${strokeW}" filter="url(#v-shadow)"/>`;
        } else if (nd.shape === 'stadium') {
            shapeSvg = `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${h / 2}" fill="${pal.fill}" stroke="${strokeCol}" stroke-width="${strokeW}" filter="url(#v-shadow)"/>`;
        } else if (nd.shape === 'note') {
            shapeSvg = `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="4" fill="${pal.fill}" stroke="${strokeCol}" stroke-width="${strokeW}" stroke-dasharray="5,3" filter="url(#v-shadow)"/>`;
        } else {
            const rx = (nd.shape === 'round' || nd.shape === 'person') ? 14 : 6;
            shapeSvg = `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${rx}" fill="${pal.fill}" stroke="${strokeCol}" stroke-width="${strokeW}" filter="url(#v-shadow)"/>`;
            if (nd.shape === 'person') {
                shapeSvg += `<rect x="${x}" y="${y}" width="${w}" height="6" rx="3" fill="${pal.stroke}"/>`;
            }
        }

        const titleY = nd.subtext ? (cy - 4) : (cy + 5);
        const lockLabel = peerLock
            ? `<rect x="${x}" y="${y - 20}" width="110" height="16" rx="8" fill="${peerLock.color}"/>
               <text x="${x + 55}" y="${y - 9}" text-anchor="middle" font-size="9" font-weight="bold" fill="#ffffff">🔒 ${escapeSheetHtml(peerLock.username)}</text>`
            : '';

        html += `
        <g data-visio-node="${escapeSheetHtml(nd.id)}"
           onmousedown="visioStartDragNode('${escapeSheetHtml(nd.id)}', event)"
           ondblclick="visioQuickPromptNodeText('${escapeSheetHtml(nd.id)}', event)"
           class="cursor-move">
            ${shapeSvg}
            ${lockLabel}
            <text x="${cx}" y="${titleY}" text-anchor="middle" font-size="13" font-weight="700" fill="${pal.text}" pointer-events="none">${escapeSheetHtml(nd.text || '')}</text>
            ${nd.subtext ? `<text x="${cx}" y="${cy + 14}" text-anchor="middle" font-size="11" fill="${pal.sub}" pointer-events="none">${escapeSheetHtml(nd.subtext)}</text>` : ''}
        </g>`;
    });

    svg.innerHTML = html;
    updateVisioInspectorUI();
}

function visioSelectEdge(edgeId, ev) {
    if (ev) ev.stopPropagation();
    visioSelectedNodeId = null;
    visioSelectedEdgeId = edgeId;
    renderVisioCanvas();
}

function visioStartDragNode(nodeId, ev) {
    if (ev) ev.stopPropagation();
    ensureVisioValid();

    if (visioConnectMode) {
        if (!visioConnectFromId) {
            visioConnectFromId = nodeId;
            visioSelectedNodeId = nodeId;
            renderVisioCanvas();
            return;
        }
        if (visioConnectFromId !== nodeId) {
            visioState.edges.push({
                id: 'e_' + Math.random().toString(36).slice(2, 8),
                from: visioConnectFromId,
                to: nodeId,
                label: '',
                style: 'solid',
                routing: 'curved'
            });
            markDirty();
            queueVisioDelta();
        }
        visioConnectFromId = null;
        visioConnectMode = false;
        const btn = document.getElementById('btnVisioConnectMode');
        if (btn) btn.className = 'px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-200 transition cursor-pointer';
        visioSelectedNodeId = nodeId;
        renderVisioCanvas();
        return;
    }

    visioSelectedEdgeId = null;
    visioSelectedNodeId = nodeId;
    renderVisioCanvas();

    if (!OFFICE_CAN_EDIT) return;
    const nd = visioState.nodes.find(n => n.id === nodeId);
    const svg = document.getElementById('visioSvgCanvas');
    if (!nd || !svg) return;

    const rect = svg.getBoundingClientRect();
    visioDraggingNode = nd;
    visioDragOffsetX = (ev.clientX - rect.left) - nd.x;
    visioDragOffsetY = (ev.clientY - rect.top) - nd.y;
}

window.addEventListener('mousemove', (ev) => {
    if (!visioDraggingNode) return;
    const svg = document.getElementById('visioSvgCanvas');
    if (!svg) return;
    const rect = svg.getBoundingClientRect();
    const rawX = (ev.clientX - rect.left) - visioDragOffsetX;
    const rawY = (ev.clientY - rect.top) - visioDragOffsetY;
    // Sanftes 12px-Raster für saubere Ausrichtung
    visioDraggingNode.x = Math.max(12, Math.round(rawX / 12) * 12);
    visioDraggingNode.y = Math.max(12, Math.round(rawY / 12) * 12);
    slidesLastTypedAt = Date.now();
    visioLastTypedAt  = Date.now();
    markDirty();
    renderVisioCanvas();
});

window.addEventListener('mouseup', () => {
    if (visioDraggingNode) {
        visioDraggingNode = null;
        queueVisioDelta();
    }
});

const visioSvgEl = document.getElementById('visioSvgCanvas');
if (visioSvgEl) {
    visioSvgEl.addEventListener('mousedown', (ev) => {
        if (ev.target.id === 'visioBgRect' || ev.target.id === 'visioGridRect' || ev.target === visioSvgEl) {
            visioSelectedNodeId = null;
            visioSelectedEdgeId = null;
            renderVisioCanvas();
        }
    });
}

function visioQuickPromptNodeText(nodeId, ev) {
    if (ev) ev.stopPropagation();
    if (!OFFICE_CAN_EDIT) return;
    const nd = visioState.nodes.find(n => n.id === nodeId);
    if (!nd) return;
    const inp = document.getElementById('visioInpText');
    if (inp) {
        inp.focus();
        inp.select();
    }
}

function visioAddNode(shape, defaultTitle, defaultSub) {
    if (!OFFICE_CAN_EDIT) return;
    ensureVisioValid();
    const count = visioState.nodes.length;
    const defaultColors = { rect: 'blue', round: 'violet', diamond: 'amber', stadium: 'emerald', person: 'blue', note: 'rose' };
    const newId = 'n_' + Math.random().toString(36).slice(2, 8);
    const newNode = {
        id: newId,
        shape: shape || 'rect',
        x: 140 + ((count * 48) % 520),
        y: 120 + ((count * 36) % 340),
        w: shape === 'diamond' ? 176 : 190,
        h: shape === 'diamond' ? 96 : 72,
        text: defaultTitle || 'Neues Element',
        subtext: defaultSub || '',
        color: defaultColors[shape] || 'blue'
    };
    visioState.nodes.push(newNode);
    if (visioSelectedNodeId) {
        visioState.edges.push({
            id: 'e_' + Math.random().toString(36).slice(2, 8),
            from: visioSelectedNodeId,
            to: newId,
            label: '',
            style: 'solid',
            routing: shape === 'person' ? 'ortho' : 'curved'
        });
    }
    visioSelectedNodeId = newId;
    visioSelectedEdgeId = null;
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

function visioToggleConnectMode() {
    if (!OFFICE_CAN_EDIT) return;
    visioConnectMode = !visioConnectMode;
    visioConnectFromId = visioSelectedNodeId || null;
    const btn = document.getElementById('btnVisioConnectMode');
    if (btn) {
        btn.className = visioConnectMode
            ? 'px-3 py-1.5 rounded-xl bg-orange-600 text-white text-xs font-bold shadow-xs transition cursor-pointer'
            : 'px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-teal-50 border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-200 transition cursor-pointer';
    }
    renderVisioCanvas();
}

function visioUpdateSelectedNode(prop, val) {
    if (!OFFICE_CAN_EDIT || !visioSelectedNodeId) return;
    const nd = visioState.nodes.find(n => n.id === visioSelectedNodeId);
    if (!nd) return;
    nd[prop] = val;
    if (prop === 'shape' && val === 'diamond' && nd.h < 90) {
        nd.h = 96;
    }
    visioLastTypedAt = Date.now();
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

function visioUpdateSelectedEdge(prop, val) {
    if (!OFFICE_CAN_EDIT || !visioSelectedEdgeId) return;
    const ed = visioState.edges.find(e => e.id === visioSelectedEdgeId);
    if (!ed) return;
    ed[prop] = val;
    visioLastTypedAt = Date.now();
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

['visioInpText', 'visioInpSubtext'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => {
        const prop = id === 'visioInpText' ? 'text' : 'subtext';
        visioUpdateSelectedNode(prop, el.value);
    });
});

const visioEdgeLblEl = document.getElementById('visioInpEdgeLabel');
if (visioEdgeLblEl) {
    visioEdgeLblEl.addEventListener('input', () => {
        visioUpdateSelectedEdge('label', visioEdgeLblEl.value);
    });
}

function visioDuplicateSelectedNode() {
    if (!OFFICE_CAN_EDIT || !visioSelectedNodeId) return;
    const nd = visioState.nodes.find(n => n.id === visioSelectedNodeId);
    if (!nd) return;
    const copy = JSON.parse(JSON.stringify(nd));
    copy.id = 'n_' + Math.random().toString(36).slice(2, 8);
    copy.x += 36;
    copy.y += 36;
    visioState.nodes.push(copy);
    visioSelectedNodeId = copy.id;
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

function visioDeleteSelected() {
    if (!OFFICE_CAN_EDIT) return;
    if (visioSelectedNodeId) {
        const delId = visioSelectedNodeId;
        visioState.nodes = visioState.nodes.filter(n => n.id !== delId);
        visioState.edges = visioState.edges.filter(e => e.from !== delId && e.to !== delId);
        visioSelectedNodeId = null;
        markDirty();
        renderVisioCanvas();
        queueVisioDelta();
    } else if (visioSelectedEdgeId) {
        visioState.edges = visioState.edges.filter(e => e.id !== visioSelectedEdgeId);
        visioSelectedEdgeId = null;
        markDirty();
        renderVisioCanvas();
        queueVisioDelta();
    }
}

document.addEventListener('keydown', (ev) => {
    if (OFFICE_EDITOR_TYPE !== 'visio' || !OFFICE_CAN_EDIT) return;
    const tag = (document.activeElement?.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (ev.key === 'Delete' || ev.key === 'Backspace') {
        ev.preventDefault();
        visioDeleteSelected();
    }
});

function visioChangeTheme(newTheme) {
    if (!OFFICE_CAN_EDIT) return;
    visioState.theme = newTheme;
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

function visioAutoLayout() {
    if (!OFFICE_CAN_EDIT) return;
    ensureVisioValid();
    const cols = Math.max(2, Math.ceil(Math.sqrt(visioState.nodes.length)));
    visioState.nodes.forEach((nd, i) => {
        const r = Math.floor(i / cols);
        const c = i % cols;
        nd.x = 100 + c * 250;
        nd.y = 80 + r * 140;
    });
    markDirty();
    renderVisioCanvas();
    queueVisioDelta();
}

function visioExportSvgFile() {
    const svg = document.getElementById('visioSvgCanvas');
    if (!svg) return;
    const raw = '<?xml version="1.0" encoding="UTF-8"?>\n' + svg.outerHTML;
    const blob = new Blob([raw], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = (titleInputEl ? titleInputEl.value : 'Diagramm') + '.svg';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

function visioPrintDiagram() {
    const svg = document.getElementById('visioSvgCanvas');
    if (!svg) return;
    const docName = titleInputEl ? titleInputEl.value : 'Diagramm';
    const win = window.open('', '_blank', 'width=1024,height=768');
    if (!win) return;
    win.document.write(`<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>${escapeSheetHtml(docName)}</title>
    <style>body{font-family:system-ui,sans-serif;margin:20px;}svg{max-width:100%;height:auto;border:1px solid #cbd5e1;border-radius:12px;}</style>
    </head><body><h2 style="margin:0 0 12px 0;">📐 ${escapeSheetHtml(docName)}</h2>${svg.outerHTML}
    <script>window.onload=()=>{window.print();};<\/script></body></html>`);
    win.document.close();
}

if (OFFICE_EDITOR_TYPE === 'visio') {
    ensureVisioValid();
    currentFocusTarget = 'diagram';
    currentFocusLabel  = 'Zeichenfläche';
    renderVisioCanvas();
}

// ============================================================================
// 2d. FOTO- & BILD-STUDIO FUNKTIONEN (.JPG, .PNG, .WEBP, .GIF, .BMP)
// ============================================================================
const imgStudioCanvas = document.getElementById('imgStudioCanvas');
const imgStudioCtx    = imgStudioCanvas ? imgStudioCanvas.getContext('2d', { willReadFrequently: true }) : null;
const imgBaseCanvas   = document.createElement('canvas');
const imgBaseCtx      = imgBaseCanvas.getContext('2d', { willReadFrequently: true });

let imgOriginalImage  = null;
let imgUndoStack      = []; // Array von { width, height, dataUrl } (max. 15 Schritte)
let imgActiveTab      = 'transform';
let imgDrawTool       = 'pixelate';
let imgIsDrawing      = false;
let imgDrawStartX     = 0;
let imgDrawStartY     = 0;
let imgDrawSnapshot   = null; // ImageData vor aktuellem Pfeil/Rahmen-Zug
let imgAdjustDirty    = false;

// Crop-Status
let imgCropActive     = false;
let imgCropDragging   = false;
let imgCropAspectVal  = '';
let imgCropStart      = { x: 0, y: 0 };
let imgCropRect       = null; // { x, y, w, h } in Canvas-Pixelkoordinaten
let imgSizeEstTimer   = null;

function initImageStudio() {
    if (!imgStudioCanvas || !imgStudioCtx) return;
    currentFocusTarget = 'image_canvas';
    currentFocusLabel  = 'Foto-Studio';

    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
        imgOriginalImage = img;
        applyImageSourceToStudio(img, false);
        imgUndoStack = [];
        updateImgUndoBtn();
        imgStudioUpdateStats();
        imgStudioUpdateSizeEstimate();
    };
    img.onerror = () => {
        const lbl = document.getElementById('imgStudioStatsLabel');
        if (lbl) lbl.textContent = '⚠️ Bilddatei konnte nicht geladen werden.';
    };
    img.src = 'download.php?id=' + encodeURIComponent(String(OFFICE_FILE_ID)) + '&inline=1&t=' + Date.now();
}

function applyImageSourceToStudio(source, recordDirty = true) {
    if (!imgStudioCanvas || !imgStudioCtx) return;
    const w = Math.max(1, source.naturalWidth || source.width || 1);
    const h = Math.max(1, source.naturalHeight || source.height || 1);
    imgStudioCanvas.width  = w;
    imgStudioCanvas.height = h;
    imgStudioCtx.clearRect(0, 0, w, h);
    imgStudioCtx.drawImage(source, 0, 0, w, h);

    syncImgBaseFromStudio();
    syncImgResizeInputs();
    imgStudioCancelCrop();
    imgStudioResetAdjustSliders(false);
    imgStudioUpdateStats();
    imgStudioUpdateSizeEstimate();
    if (recordDirty) {
        markDirty();
    }
}

function syncImgBaseFromStudio() {
    if (!imgStudioCanvas) return;
    imgBaseCanvas.width  = imgStudioCanvas.width;
    imgBaseCanvas.height = imgStudioCanvas.height;
    imgBaseCtx.clearRect(0, 0, imgBaseCanvas.width, imgBaseCanvas.height);
    imgBaseCtx.drawImage(imgStudioCanvas, 0, 0);
    imgAdjustDirty = false;
}

function pushImgUndoSnapshot() {
    if (!imgStudioCanvas) return;
    if (imgAdjustDirty) {
        imgStudioCommitAdjust(false);
    }
    try {
        const snap = {
            width: imgStudioCanvas.width,
            height: imgStudioCanvas.height,
            imageData: imgStudioCtx.getImageData(0, 0, imgStudioCanvas.width, imgStudioCanvas.height)
        };
        imgUndoStack.push(snap);
        if (imgUndoStack.length > 15) {
            imgUndoStack.shift();
        }
        updateImgUndoBtn();
    } catch (e) {}
}

function updateImgUndoBtn() {
    const btn = document.getElementById('btnImgUndo');
    if (btn) {
        btn.disabled = imgUndoStack.length === 0;
        btn.textContent = imgUndoStack.length > 0 ? `↩️ Rückgängig (${imgUndoStack.length})` : '↩️ Rückgängig';
    }
}

function imgStudioUndo() {
    if (!imgStudioCanvas || imgUndoStack.length === 0) return;
    const snap = imgUndoStack.pop();
    updateImgUndoBtn();
    imgStudioCanvas.width  = snap.width;
    imgStudioCanvas.height = snap.height;
    imgStudioCtx.putImageData(snap.imageData, 0, 0);
    syncImgBaseFromStudio();
    syncImgResizeInputs();
    imgStudioCancelCrop();
    imgStudioResetAdjustSliders(false);
    imgStudioUpdateStats();
    imgStudioUpdateSizeEstimate();
    markDirty();
}

function imgStudioResetOriginal() {
    if (!imgOriginalImage) return;
    if (hasUnsavedChanges && !confirm('Alle aktuellen Bearbeitungsschritte verwerfen und zum Originalbild zurückkehren?')) {
        return;
    }
    pushImgUndoSnapshot();
    applyImageSourceToStudio(imgOriginalImage, false);
    hasUnsavedChanges = false;
    const statusEl = document.getElementById('officeStatusText');
    if (statusEl) {
        statusEl.textContent = '✓ Original wiederhergestellt';
        statusEl.className = 'font-semibold text-emerald-600 dark:text-emerald-400';
    }
}

function switchImgStudioTab(tab) {
    if (imgAdjustDirty && tab !== 'adjust') {
        imgStudioCommitAdjust(true);
    }
    if (tab !== 'transform') {
        imgStudioCancelCrop();
    }
    imgActiveTab = tab;

    const tabs = {
        transform: { btn: 'imgTabBtnTransform', panel: 'imgPanelTransform' },
        resize:    { btn: 'imgTabBtnResize',    panel: 'imgPanelResize' },
        adjust:    { btn: 'imgTabBtnAdjust',    panel: 'imgPanelAdjust' },
        draw:      { btn: 'imgTabBtnDraw',      panel: 'imgPanelDraw' }
    };

    Object.keys(tabs).forEach(k => {
        const b = document.getElementById(tabs[k].btn);
        const p = document.getElementById(tabs[k].panel);
        if (b) {
            if (k === tab) {
                b.className = 'px-3 py-1.5 rounded-xl text-xs font-bold bg-pink-600 text-white shadow-2xs transition cursor-pointer';
            } else {
                b.className = 'px-3 py-1.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 transition cursor-pointer';
            }
        }
        if (p) {
            if (k === tab) {
                p.classList.remove('hidden');
                if (k === 'resize' || k === 'draw') p.classList.add('flex');
            } else {
                p.classList.add('hidden');
                p.classList.remove('flex');
            }
        }
    });

    if (imgStudioCanvas) {
        imgStudioCanvas.style.cursor = (tab === 'draw' || tab === 'transform') ? 'crosshair' : 'default';
    }
    if (tab === 'resize') {
        syncImgResizeInputs();
        imgStudioUpdateSizeEstimate();
    }
}

function imgStudioRotate(deg) {
    if (!imgStudioCanvas || !OFFICE_CAN_EDIT) return;
    pushImgUndoSnapshot();
    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const tmp = document.createElement('canvas');
    tmp.width  = (Math.abs(deg) === 90) ? h : w;
    tmp.height = (Math.abs(deg) === 90) ? w : h;
    const tctx = tmp.getContext('2d');
    tctx.translate(tmp.width / 2, tmp.height / 2);
    tctx.rotate((deg * Math.PI) / 180);
    tctx.drawImage(imgStudioCanvas, -w / 2, -h / 2);
    applyImageSourceToStudio(tmp, true);
}

function imgStudioFlip(dir) {
    if (!imgStudioCanvas || !OFFICE_CAN_EDIT) return;
    pushImgUndoSnapshot();
    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const tmp = document.createElement('canvas');
    tmp.width  = w;
    tmp.height = h;
    const tctx = tmp.getContext('2d');
    if (dir === 'h') {
        tctx.translate(w, 0);
        tctx.scale(-1, 1);
    } else {
        tctx.translate(0, h);
        tctx.scale(1, -1);
    }
    tctx.drawImage(imgStudioCanvas, 0, 0);
    applyImageSourceToStudio(tmp, true);
}

// --- ZUSCHNEIDEN (CROP) ---
function getAspectNumeric(val) {
    if (val === '1:1') return 1;
    if (val === '16:9') return 16 / 9;
    if (val === '4:3') return 4 / 3;
    if (val === '3:2') return 3 / 2;
    if (val === 'a4') return 210 / 297;
    return 0;
}

function imgStudioStartCrop(aspectVal) {
    if (!imgStudioCanvas || !OFFICE_CAN_EDIT) return;
    imgCropAspectVal = aspectVal || '';
    if (!aspectVal) {
        imgStudioCancelCrop();
        return;
    }
    imgCropActive = true;
    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const ratio = getAspectNumeric(aspectVal);

    let cw = Math.round(w * 0.8);
    let ch = Math.round(h * 0.8);
    if (ratio > 0) {
        if (cw / ch > ratio) {
            cw = Math.round(ch * ratio);
        } else {
            ch = Math.round(cw / ratio);
        }
    }
    imgCropRect = {
        x: Math.round((w - cw) / 2),
        y: Math.round((h - ch) / 2),
        w: Math.max(16, cw),
        h: Math.max(16, ch)
    };
    renderImgCropOverlay();
}

function renderImgCropOverlay() {
    const ov = document.getElementById('imgCropOverlay');
    const applyBtn  = document.getElementById('btnApplyCrop');
    const cancelBtn = document.getElementById('btnCancelCrop');
    if (!ov || !imgStudioCanvas) return;

    if (!imgCropRect || imgCropRect.w < 8 || imgCropRect.h < 8) {
        ov.classList.add('hidden');
        if (applyBtn) applyBtn.classList.add('hidden');
        if (cancelBtn) cancelBtn.classList.add('hidden');
        return;
    }

    const scaleX = imgStudioCanvas.clientWidth / imgStudioCanvas.width;
    const scaleY = imgStudioCanvas.clientHeight / imgStudioCanvas.height;
    ov.style.left   = Math.round(imgCropRect.x * scaleX) + 'px';
    ov.style.top    = Math.round(imgCropRect.y * scaleY) + 'px';
    ov.style.width  = Math.round(imgCropRect.w * scaleX) + 'px';
    ov.style.height = Math.round(imgCropRect.h * scaleY) + 'px';
    ov.classList.remove('hidden');
    if (applyBtn) applyBtn.classList.remove('hidden');
    if (cancelBtn) cancelBtn.classList.remove('hidden');
}

function imgStudioApplyCrop() {
    if (!imgStudioCanvas || !imgCropRect || imgCropRect.w < 8 || imgCropRect.h < 8) return;
    pushImgUndoSnapshot();
    const rx = Math.max(0, Math.min(imgStudioCanvas.width - 1, Math.round(imgCropRect.x)));
    const ry = Math.max(0, Math.min(imgStudioCanvas.height - 1, Math.round(imgCropRect.y)));
    const rw = Math.max(1, Math.min(imgStudioCanvas.width - rx, Math.round(imgCropRect.w)));
    const rh = Math.max(1, Math.min(imgStudioCanvas.height - ry, Math.round(imgCropRect.h)));

    const tmp = document.createElement('canvas');
    tmp.width  = rw;
    tmp.height = rh;
    tmp.getContext('2d').drawImage(imgStudioCanvas, rx, ry, rw, rh, 0, 0, rw, rh);
    applyImageSourceToStudio(tmp, true);
}

function imgStudioCancelCrop() {
    imgCropActive   = false;
    imgCropDragging = false;
    imgCropRect     = null;
    const sel = document.getElementById('imgCropAspect');
    if (sel) sel.value = '';
    renderImgCropOverlay();
}

// --- SKALIEREN & KOMPRIMIEREN ---
function syncImgResizeInputs() {
    if (!imgStudioCanvas) return;
    const wIn = document.getElementById('imgResizeW');
    const hIn = document.getElementById('imgResizeH');
    if (wIn) wIn.value = imgStudioCanvas.width;
    if (hIn) hIn.value = imgStudioCanvas.height;
}

function imgStudioSyncAspect(changedAxis) {
    if (!imgStudioCanvas) return;
    const lock = document.getElementById('imgResizeLock');
    if (!lock || !lock.checked) return;
    const wIn = document.getElementById('imgResizeW');
    const hIn = document.getElementById('imgResizeH');
    if (!wIn || !hIn) return;
    const ratio = imgStudioCanvas.width / Math.max(1, imgStudioCanvas.height);
    if (changedAxis === 'w') {
        const w = Math.max(16, parseInt(wIn.value || '0', 10));
        hIn.value = Math.max(16, Math.round(w / ratio));
    } else {
        const h = Math.max(16, parseInt(hIn.value || '0', 10));
        wIn.value = Math.max(16, Math.round(h * ratio));
    }
}

function imgStudioScalePreset(factor) {
    if (!imgStudioCanvas) return;
    const wIn = document.getElementById('imgResizeW');
    const hIn = document.getElementById('imgResizeH');
    if (wIn && hIn) {
        wIn.value = Math.max(16, Math.round(imgStudioCanvas.width * factor));
        hIn.value = Math.max(16, Math.round(imgStudioCanvas.height * factor));
        imgStudioApplyResize();
    }
}

function imgStudioMaxDimPreset(maxPx) {
    if (!imgStudioCanvas) return;
    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const longest = Math.max(w, h);
    if (longest <= maxPx) {
        alert(`Das Bild ist mit ${w} × ${h} px bereits kleiner oder gleich ${maxPx} px.`);
        return;
    }
    imgStudioScalePreset(maxPx / longest);
}

function imgStudioApplyResize() {
    if (!imgStudioCanvas || !OFFICE_CAN_EDIT) return;
    const wIn = document.getElementById('imgResizeW');
    const hIn = document.getElementById('imgResizeH');
    const nw  = Math.max(16, Math.min(8000, parseInt(wIn ? wIn.value : '0', 10) || imgStudioCanvas.width));
    const nh  = Math.max(16, Math.min(8000, parseInt(hIn ? hIn.value : '0', 10) || imgStudioCanvas.height));
    if (nw === imgStudioCanvas.width && nh === imgStudioCanvas.height) return;

    pushImgUndoSnapshot();
    const tmp = document.createElement('canvas');
    tmp.width  = nw;
    tmp.height = nh;
    const tctx = tmp.getContext('2d');
    tctx.imageSmoothingEnabled = true;
    tctx.imageSmoothingQuality = 'high';
    tctx.drawImage(imgStudioCanvas, 0, 0, nw, nh);
    applyImageSourceToStudio(tmp, true);
}

function getSelectedImgExportMimeAndQuality() {
    const fmtEl = document.getElementById('imgExportFormat');
    const qEl   = document.getElementById('imgExportQuality');
    const fmt   = fmtEl ? fmtEl.value : 'jpg';
    const qVal  = Math.max(0.15, Math.min(1, (parseInt(qEl ? qEl.value : '88', 10) || 88) / 100));
    let mime    = 'image/jpeg';
    if (fmt === 'webp') mime = 'image/webp';
    else if (fmt === 'png') mime = 'image/png';
    return { fmt, mime, quality: qVal };
}

function formatImgBytesJs(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB';
    return (bytes / 1048576).toFixed(2).replace('.', ',') + ' MB';
}

function imgStudioUpdateSizeEstimate() {
    if (!imgStudioCanvas) return;
    if (imgSizeEstTimer) clearTimeout(imgSizeEstTimer);
    imgSizeEstTimer = setTimeout(() => {
        const badge = document.getElementById('imgSizeEstimateBadge');
        const { fmt, mime, quality } = getSelectedImgExportMimeAndQuality();
        imgStudioCanvas.toBlob(blob => {
            if (!blob) return;
            const sizeStr = formatImgBytesJs(blob.size);
            if (badge) {
                badge.textContent = `≈ ${sizeStr} (${fmt.toUpperCase()})`;
            }
            imgStudioUpdateStats(sizeStr);
        }, mime, quality);
    }, 120);
}

function imgStudioUpdateStats(estSizeStr = '') {
    const lbl = document.getElementById('imgStudioStatsLabel');
    if (!lbl || !imgStudioCanvas) return;
    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const mp = ((w * h) / 1000000).toFixed(1).replace('.', ',');
    lbl.textContent = `📐 Auflösung: ${w} × ${h} px (${mp} MP)` + (estSizeStr ? ` • 📦 Exportgröße: ≈ ${estSizeStr}` : '');
}

// --- BELICHTUNG, FARBEN & 1-KLICK-FILTER ---
function imgStudioPreviewAdjust() {
    if (!imgStudioCanvas || !imgStudioCtx || !imgBaseCanvas.width) return;
    const b = parseInt((document.getElementById('imgAdjBright') || {}).value || '0', 10);
    const c = parseInt((document.getElementById('imgAdjContrast') || {}).value || '0', 10);
    const s = parseInt((document.getElementById('imgAdjSat') || {}).value || '0', 10);
    const p = parseInt((document.getElementById('imgAdjSepia') || {}).value || '0', 10);

    imgStudioCtx.save();
    imgStudioCtx.clearRect(0, 0, imgStudioCanvas.width, imgStudioCanvas.height);
    imgStudioCtx.filter = `brightness(${100 + b}%) contrast(${100 + c}%) saturate(${100 + s}%) sepia(${p}%)`;
    imgStudioCtx.drawImage(imgBaseCanvas, 0, 0);
    imgStudioCtx.restore();
    imgAdjustDirty = (b !== 0 || c !== 0 || s !== 0 || p !== 0);
}

function imgStudioCommitAdjust(recordUndo = true) {
    if (!imgAdjustDirty) return;
    if (recordUndo) {
        // Undo-Snapshot vom Zustand VOR der Regler-Vorschau speichern
        try {
            imgUndoStack.push({
                width: imgBaseCanvas.width,
                height: imgBaseCanvas.height,
                imageData: imgBaseCtx.getImageData(0, 0, imgBaseCanvas.width, imgBaseCanvas.height)
            });
            if (imgUndoStack.length > 15) imgUndoStack.shift();
            updateImgUndoBtn();
        } catch (e) {}
    }
    syncImgBaseFromStudio();
    imgStudioResetAdjustSliders(false);
    imgStudioUpdateSizeEstimate();
    markDirty();
}

function imgStudioResetAdjustSliders(restoreBase = true) {
    ['imgAdjBright', 'imgAdjContrast', 'imgAdjSat', 'imgAdjSepia'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '0';
    });
    if (restoreBase && imgAdjustDirty && imgStudioCtx && imgBaseCanvas.width) {
        imgStudioCtx.clearRect(0, 0, imgStudioCanvas.width, imgStudioCanvas.height);
        imgStudioCtx.drawImage(imgBaseCanvas, 0, 0);
    }
    imgAdjustDirty = false;
}

function imgStudioApplyFilterPreset(preset) {
    if (!imgStudioCanvas || !imgStudioCtx || !OFFICE_CAN_EDIT) return;
    if (imgAdjustDirty) imgStudioResetAdjustSliders(true);
    pushImgUndoSnapshot();

    const w = imgStudioCanvas.width;
    const h = imgStudioCanvas.height;
    const tmp = document.createElement('canvas');
    tmp.width  = w;
    tmp.height = h;
    const tctx = tmp.getContext('2d');

    if (preset === 'auto') {
        tctx.filter = 'brightness(106%) contrast(114%) saturate(118%)';
    } else if (preset === 'bw') {
        tctx.filter = 'grayscale(100%) contrast(112%)';
    } else if (preset === 'sepia') {
        tctx.filter = 'sepia(85%) contrast(106%) brightness(98%)';
    } else if (preset === 'warm') {
        tctx.filter = 'saturate(130%) sepia(22%) brightness(104%)';
    } else if (preset === 'doc') {
        tctx.filter = 'grayscale(100%) contrast(165%) brightness(112%)';
    } else if (preset === 'invert') {
        tctx.filter = 'invert(100%)';
    }
    tctx.drawImage(imgStudioCanvas, 0, 0);
    applyImageSourceToStudio(tmp, true);
}

// --- ZEICHNEN, WASSERZEICHEN & DSGVO-VERPIXELUNG ---
function setImgDrawTool(tool) {
    imgDrawTool = tool;
    document.querySelectorAll('.img-draw-tool-btn').forEach(btn => {
        if (btn.getAttribute('data-img-tool') === tool) {
            btn.className = 'img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-pink-600 text-white font-bold cursor-pointer';
        } else {
            btn.className = 'img-draw-tool-btn px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer';
        }
    });
}

function getImgCanvasCoords(evt) {
    const rect = imgStudioCanvas.getBoundingClientRect();
    const clientX = evt.touches && evt.touches[0] ? evt.touches[0].clientX : evt.clientX;
    const clientY = evt.touches && evt.touches[0] ? evt.touches[0].clientY : evt.clientY;
    const scaleX = imgStudioCanvas.width / Math.max(1, rect.width);
    const scaleY = imgStudioCanvas.height / Math.max(1, rect.height);
    return {
        x: Math.max(0, Math.min(imgStudioCanvas.width, (clientX - rect.left) * scaleX)),
        y: Math.max(0, Math.min(imgStudioCanvas.height, (clientY - rect.top) * scaleY))
    };
}

function applyPixelateBrushAt(cx, cy, radius) {
    if (!imgStudioCtx || !imgStudioCanvas) return;
    const blockSize = Math.max(8, Math.round(radius * 0.65));
    const r = Math.max(12, radius * 1.4);
    const startX = Math.max(0, Math.floor((cx - r) / blockSize) * blockSize);
    const startY = Math.max(0, Math.floor((cy - r) / blockSize) * blockSize);
    const endX   = Math.min(imgStudioCanvas.width, Math.ceil((cx + r) / blockSize) * blockSize);
    const endY   = Math.min(imgStudioCanvas.height, Math.ceil((cy + r) / blockSize) * blockSize);

    for (let y = startY; y < endY; y += blockSize) {
        for (let x = startX; x < endX; x += blockSize) {
            const bw = Math.min(blockSize, imgStudioCanvas.width - x);
            const bh = Math.min(blockSize, imgStudioCanvas.height - y);
            const sample = imgStudioCtx.getImageData(x + Math.floor(bw / 2), y + Math.floor(bh / 2), 1, 1).data;
            imgStudioCtx.fillStyle = `rgb(${sample[0]}, ${sample[1]}, ${sample[2]})`;
            imgStudioCtx.fillRect(x, y, bw, bh);
        }
    }
}

function applyBlurBrushAt(cx, cy, radius) {
    if (!imgStudioCtx || !imgStudioCanvas) return;
    const r = Math.max(14, radius * 1.5);
    const sx = Math.max(0, Math.round(cx - r));
    const sy = Math.max(0, Math.round(cy - r));
    const sw = Math.min(imgStudioCanvas.width - sx, Math.round(r * 2));
    const sh = Math.min(imgStudioCanvas.height - sy, Math.round(r * 2));
    if (sw < 4 || sh < 4) return;

    const smallW = Math.max(2, Math.round(sw / 6));
    const smallH = Math.max(2, Math.round(sh / 6));
    const tmp = document.createElement('canvas');
    tmp.width  = smallW;
    tmp.height = smallH;
    const tctx = tmp.getContext('2d');
    tctx.imageSmoothingEnabled = true;
    tctx.drawImage(imgStudioCanvas, sx, sy, sw, sh, 0, 0, smallW, smallH);

    imgStudioCtx.save();
    imgStudioCtx.beginPath();
    imgStudioCtx.arc(cx, cy, r * 0.85, 0, Math.PI * 2);
    imgStudioCtx.clip();
    imgStudioCtx.imageSmoothingEnabled = true;
    imgStudioCtx.drawImage(tmp, 0, 0, smallW, smallH, sx, sy, sw, sh);
    imgStudioCtx.restore();
}

function drawArrowOnImgCtx(ctx, x1, y1, x2, y2, color, lineWidth) {
    const headLen = Math.max(14, lineWidth * 3.2);
    const angle   = Math.atan2(y2 - y1, x2 - x1);
    ctx.save();
    ctx.strokeStyle = color;
    ctx.fillStyle   = color;
    ctx.lineWidth   = lineWidth;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';

    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();

    ctx.beginPath();
    ctx.moveTo(x2, y2);
    ctx.lineTo(x2 - headLen * Math.cos(angle - Math.PI / 6), y2 - headLen * Math.sin(angle - Math.PI / 6));
    ctx.lineTo(x2 - headLen * Math.cos(angle + Math.PI / 6), y2 - headLen * Math.sin(angle + Math.PI / 6));
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

if (imgStudioCanvas && OFFICE_CAN_EDIT) {
    const handlePointerDown = (e) => {
        const pt = getImgCanvasCoords(e);

        // Fall 1: Zuschnitt-Rahmen im Transform-Tab mit der Maus aufziehen
        if (imgActiveTab === 'transform') {
            imgCropActive   = true;
            imgCropDragging = true;
            imgCropStart    = pt;
            imgCropRect     = { x: pt.x, y: pt.y, w: 0, h: 0 };
            renderImgCropOverlay();
            return;
        }

        if (imgActiveTab !== 'draw') return;
        e.preventDefault();

        const color = (document.getElementById('imgDrawColor') || {}).value || '#e11d48';
        const size  = parseInt((document.getElementById('imgDrawSize') || {}).value || '22', 10);

        if (imgDrawTool === 'text') {
            const wmInput = document.getElementById('imgWatermarkText');
            let txt = wmInput && wmInput.value.trim() ? wmInput.value.trim() : prompt('Text / Wasserzeichen eingeben:', '© MiniCloud');
            if (!txt) return;
            pushImgUndoSnapshot();
            const fontSize = Math.max(16, Math.round(size * (imgStudioCanvas.width / 650)));
            imgStudioCtx.save();
            imgStudioCtx.font = `bold ${fontSize}px system-ui, sans-serif`;
            imgStudioCtx.fillStyle = color;
            imgStudioCtx.shadowColor = 'rgba(0,0,0,0.45)';
            imgStudioCtx.shadowBlur  = 4;
            imgStudioCtx.fillText(txt, pt.x, pt.y);
            imgStudioCtx.restore();
            syncImgBaseFromStudio();
            imgStudioUpdateSizeEstimate();
            markDirty();
            return;
        }

        pushImgUndoSnapshot();
        imgIsDrawing  = true;
        imgDrawStartX = pt.x;
        imgDrawStartY = pt.y;
        imgDrawSnapshot = imgStudioCtx.getImageData(0, 0, imgStudioCanvas.width, imgStudioCanvas.height);

        const scaledSize = Math.max(4, Math.round(size * (imgStudioCanvas.width / 800)));
        if (imgDrawTool === 'pixelate') {
            applyPixelateBrushAt(pt.x, pt.y, scaledSize);
        } else if (imgDrawTool === 'blur') {
            applyBlurBrushAt(pt.x, pt.y, scaledSize);
        } else if (imgDrawTool === 'pen') {
            imgStudioCtx.save();
            imgStudioCtx.strokeStyle = color;
            imgStudioCtx.lineWidth   = Math.max(2, scaledSize * 0.35);
            imgStudioCtx.lineCap     = 'round';
            imgStudioCtx.lineJoin    = 'round';
            imgStudioCtx.beginPath();
            imgStudioCtx.moveTo(pt.x, pt.y);
        }
    };

    const handlePointerMove = (e) => {
        if (imgActiveTab === 'transform' && imgCropDragging) {
            const pt = getImgCanvasCoords(e);
            let x = Math.min(imgCropStart.x, pt.x);
            let y = Math.min(imgCropStart.y, pt.y);
            let w = Math.abs(pt.x - imgCropStart.x);
            let h = Math.abs(pt.y - imgCropStart.y);
            const ratio = getAspectNumeric(imgCropAspectVal);
            if (ratio > 0 && w > 0) {
                h = Math.round(w / ratio);
                if (y + h > imgStudioCanvas.height) {
                    h = imgStudioCanvas.height - y;
                    w = Math.round(h * ratio);
                }
            }
            imgCropRect = { x, y, w, h };
            renderImgCropOverlay();
            return;
        }

        if (!imgIsDrawing || imgActiveTab !== 'draw') return;
        e.preventDefault();
        const pt    = getImgCanvasCoords(e);
        const color = (document.getElementById('imgDrawColor') || {}).value || '#e11d48';
        const size  = parseInt((document.getElementById('imgDrawSize') || {}).value || '22', 10);
        const scaledSize = Math.max(4, Math.round(size * (imgStudioCanvas.width / 800)));

        if (imgDrawTool === 'pixelate') {
            applyPixelateBrushAt(pt.x, pt.y, scaledSize);
        } else if (imgDrawTool === 'blur') {
            applyBlurBrushAt(pt.x, pt.y, scaledSize);
        } else if (imgDrawTool === 'pen') {
            imgStudioCtx.lineTo(pt.x, pt.y);
            imgStudioCtx.stroke();
        } else if (imgDrawTool === 'arrow' && imgDrawSnapshot) {
            imgStudioCtx.putImageData(imgDrawSnapshot, 0, 0);
            drawArrowOnImgCtx(imgStudioCtx, imgDrawStartX, imgDrawStartY, pt.x, pt.y, color, Math.max(3, scaledSize * 0.35));
        } else if (imgDrawTool === 'rect' && imgDrawSnapshot) {
            imgStudioCtx.putImageData(imgDrawSnapshot, 0, 0);
            imgStudioCtx.save();
            imgStudioCtx.strokeStyle = color;
            imgStudioCtx.lineWidth   = Math.max(3, scaledSize * 0.35);
            imgStudioCtx.strokeRect(
                Math.min(imgDrawStartX, pt.x),
                Math.min(imgDrawStartY, pt.y),
                Math.abs(pt.x - imgDrawStartX),
                Math.abs(pt.y - imgDrawStartY)
            );
            imgStudioCtx.restore();
        }
    };

    const handlePointerUp = () => {
        if (imgCropDragging) {
            imgCropDragging = false;
            renderImgCropOverlay();
        }
        if (imgIsDrawing) {
            if (imgDrawTool === 'pen') {
                imgStudioCtx.restore();
            }
            imgIsDrawing = false;
            imgDrawSnapshot = null;
            syncImgBaseFromStudio();
            imgStudioUpdateSizeEstimate();
            markDirty();
        }
    };

    imgStudioCanvas.addEventListener('mousedown', handlePointerDown);
    window.addEventListener('mousemove', handlePointerMove);
    window.addEventListener('mouseup', handlePointerUp);

    imgStudioCanvas.addEventListener('touchstart', handlePointerDown, { passive: false });
    window.addEventListener('touchmove', handlePointerMove, { passive: false });
    window.addEventListener('touchend', handlePointerUp);
}

function imgStudioDownloadLocal() {
    if (!imgStudioCanvas) return;
    if (imgAdjustDirty) imgStudioCommitAdjust(true);
    const { fmt, mime, quality } = getSelectedImgExportMimeAndQuality();
    const docName = (titleInputEl && titleInputEl.value ? titleInputEl.value.replace(/\.[^.]+$/, '') : 'bild') + '.' + fmt;
    imgStudioCanvas.toBlob(blob => {
        if (!blob) return;
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href     = url;
        a.download = docName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }, mime, quality);
}

if (OFFICE_EDITOR_TYPE === 'image') {
    initImageStudio();
}

// ============================================================================
// 3. TEXT-EDITOR FUNKTIONEN (inkl. Zeilen-Fokus & Live-Sync)
// ============================================================================
const plainTextEl = document.getElementById('plainTextEditor');
let plainInputDebounce = null;
let plainLastTypedAt   = 0;

function updatePlainFocusLine() {
    if (!plainTextEl) return;
    const pos = plainTextEl.selectionStart || 0;
    const lineNum = (plainTextEl.value.substring(0, pos).split(/\r\n|\r|\n/).length) || 1;
    currentFocusTarget = 'line:' + lineNum;
    currentFocusLabel  = 'Zeile ' + lineNum;
}

if (plainTextEl) {
    const updatePlainStats = () => {
        const val = plainTextEl.value || '';
        const lines = val === '' ? 0 : val.split(/\r\n|\r|\n/).length;
        const stats = document.getElementById('plainTextStats');
        if (stats) stats.textContent = `${lines} Zeilen • ${val.length} Zeichen`;
    };
    plainTextEl.addEventListener('input', () => {
        plainLastTypedAt = Date.now();
        markDirty();
        updatePlainStats();
        updatePlainFocusLine();
        if (plainInputDebounce) clearTimeout(plainInputDebounce);
        plainInputDebounce = setTimeout(() => {
            queueCollabDelta('text_full', 'text', plainTextEl.value);
        }, 300);
    });
    plainTextEl.addEventListener('keyup', updatePlainFocusLine);
    plainTextEl.addEventListener('mouseup', updatePlainFocusLine);
    updatePlainStats();
}

// ============================================================================
// 4. SPEICHERN IN DER CLOUD (AJAX POST)
// ============================================================================
async function saveOfficeDocument(isSilentAutoSave = false) {
    if (!OFFICE_CAN_EDIT) return;
    const btn = document.getElementById('btnSaveOffice');
    const statusEl = document.getElementById('officeStatusText');
    if (btn && !isSilentAutoSave) btn.disabled = true;
    if (statusEl) {
        statusEl.textContent = isSilentAutoSave ? '⚡ Auto-Save...' : '⏳ Wird gespeichert...';
        statusEl.className = 'font-semibold text-brand-600 dark:text-brand-400';
    }

    const formData = new FormData();
    formData.append('action', 'save_document');
    formData.append('csrf_token', OFFICE_CSRF_TOKEN);
    formData.append('file_id', String(OFFICE_FILE_ID));
    if (titleInputEl) {
        formData.append('doc_title', titleInputEl.value.trim());
    }

    if (OFFICE_EDITOR_TYPE === 'word' && wordSurface) {
        formData.append('html_content', getCleanWordHtml());
    } else if (OFFICE_EDITOR_TYPE === 'sheet') {
        formData.append('sheets_json', JSON.stringify(sheetsState));
    } else if (OFFICE_EDITOR_TYPE === 'slides') {
        formData.append('slides_json', JSON.stringify(slidesDeckState));
    } else if (OFFICE_EDITOR_TYPE === 'visio') {
        formData.append('visio_json', JSON.stringify(visioState));
    } else if (OFFICE_EDITOR_TYPE === 'image' && imgStudioCanvas) {
        if (imgAdjustDirty) {
            imgStudioCommitAdjust(true);
        }
        const { fmt, mime, quality } = getSelectedImgExportMimeAndQuality();
        const saveModeEl = document.getElementById('imgStudioSaveMode');
        const saveMode   = saveModeEl ? saveModeEl.value : 'overwrite';
        formData.append('save_mode', saveMode);
        formData.append('target_ext', fmt);
        const imgBlob = await new Promise(resolve => imgStudioCanvas.toBlob(resolve, mime, quality));
        if (imgBlob) {
            formData.append('image_blob', imgBlob, 'edited.' + fmt);
        } else {
            formData.append('image_data_url', imgStudioCanvas.toDataURL(mime, quality));
        }
    } else if (plainTextEl) {
        formData.append('text_content', plainTextEl.value);
    }

    try {
        const resp = await fetch('office.php', {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (data.success) {
            hasUnsavedChanges = false;
            if (data.filename && titleInputEl) {
                titleInputEl.value = data.filename;
            }
            if (statusEl) {
                statusEl.textContent = '✓ ' + data.message + ' • ' + data.size;
                statusEl.className = 'font-semibold text-emerald-600 dark:text-emerald-400';
            }
        } else {
            if (statusEl) {
                statusEl.textContent = '⚠️ ' + (data.message || 'Fehler beim Speichern');
                statusEl.className = 'font-semibold text-red-600 dark:text-red-400';
            }
            if (!isSilentAutoSave) {
                alert(data.message || 'Fehler beim Speichern.');
            }
        }
    } catch (err) {
        if (statusEl) {
            statusEl.textContent = '⚠️ Netzwerkfehler beim Speichern';
            statusEl.className = 'font-semibold text-red-600 dark:text-red-400';
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ============================================================================
// 5. LIVE-TEAM-ENGINE (Stufe 1: Anwesenheit, Absatz-/Zellen-Sperre & Delta-Polling)
// ============================================================================
let collabSyncInFlight = false;
let collabInitialized  = false;

function renderCollabPresenceBar() {
    const countEl = document.getElementById('collabCountLabel');
    const listEl  = document.getElementById('collabUsersList');
    if (!countEl || !listEl) return;

    const total = collabPeers.length || 1;
    countEl.textContent = total === 1 ? 'Live-Team (Du allein im Dokument)' : `Live-Team (${total} Personen gleichzeitig aktiv)`;

    let html = '';
    collabPeers.forEach(p => {
        const posBadge = p.focusLabel ? `<span class="opacity-85 font-normal">· ${escapeSheetHtml(p.focusLabel)}</span>` : '';
        html += `<span style="border-color:${p.color}; background-color:${p.color}15; color:${p.color};" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border text-xs font-bold">
            <span style="background-color:${p.color};" class="w-2 h-2 rounded-full"></span>
            <span>${escapeSheetHtml(p.username)}${p.isMe ? ' (Du)' : ''}</span>
            ${posBadge}
        </span>`;
    });
    listEl.innerHTML = html;
}

function sanitizeWordPeerHtml(rawHtml) {
    const tpl = document.createElement('template');
    tpl.innerHTML = String(rawHtml || '');
    const allowedTags = new Set(['P', 'H1', 'H2', 'H3', 'H4', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'B', 'STRONG', 'I', 'EM', 'U', 'S', 'STRIKE', 'SPAN', 'FONT', 'BR', 'HR', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'SUB', 'SUP']);
    tpl.content.querySelectorAll('*').forEach(el => {
        if (!allowedTags.has(el.tagName)) {
            el.replaceWith(...Array.from(el.childNodes));
            return;
        }
        Array.from(el.attributes).forEach(attr => {
            const name = attr.name.toLowerCase();
            const val  = String(attr.value || '').toLowerCase();
            if (name.startsWith('on') || val.includes('javascript:') || val.includes('expression(') || !['style', 'color', 'align', 'colspan', 'rowspan'].includes(name)) {
                el.removeAttribute(attr.name);
            }
        });
    });
    return tpl.innerHTML;
}

function applyIncomingDeltas(deltas) {
    if (!Array.isArray(deltas) || deltas.length === 0) return;
    let sheetNeedsRender = false;

    deltas.forEach(d => {
        if (d.type === 'file_saved' && d.payload) {
            const statusEl = document.getElementById('officeStatusText');
            if (statusEl && !hasUnsavedChanges) {
                statusEl.textContent = `✓ Gespeichert von ${d.username} (${d.payload.savedAt || d.time} Uhr) • ${d.payload.size || ''}`;
                statusEl.className = 'font-semibold text-emerald-600 dark:text-emerald-400';
            }
            return;
        }

        if (OFFICE_EDITOR_TYPE === 'sheet') {
            if (d.type === 'sheet_cell' && d.payload) {
                const { sheetIdx, r, c, val } = d.payload;
                if (sheetsState[sheetIdx] && sheetsState[sheetIdx].rows && sheetsState[sheetIdx].rows[r]) {
                    sheetsState[sheetIdx].rows[r][c] = val;
                    sheetNeedsRender = true;
                }
            } else if (d.type === 'sheet_full' && Array.isArray(d.payload)) {
                sheetsState = d.payload;
                sheetNeedsRender = true;
            }
        } else if (OFFICE_EDITOR_TYPE === 'word' && wordSurface) {
            ensureWordBlockIds();
            if (d.type === 'word_block' && d.payload && d.payload.id) {
                const targetEl = wordSurface.querySelector(`[data-block-id="${CSS.escape(d.payload.id)}"]`);
                const myActiveBlk = getActiveWordBlockElement();
                // Nur aktualisieren, wenn der eigene Cursor nicht gerade mitten in diesem Absatz tippt
                if (targetEl && targetEl !== myActiveBlk) {
                    targetEl.innerHTML = sanitizeWordPeerHtml(d.payload.html || '');
                }
            } else if (d.type === 'word_full' && Array.isArray(d.payload)) {
                const myActiveBlk = getActiveWordBlockElement();
                const myActiveId  = myActiveBlk ? myActiveBlk.getAttribute('data-block-id') : null;
                const existingMap = {};
                Array.from(wordSurface.children).forEach(el => {
                    const bid = el.getAttribute('data-block-id');
                    if (bid) existingMap[bid] = el;
                });

                d.payload.forEach((bInfo, idx) => {
                    if (!bInfo || !bInfo.id) return;
                    let el = existingMap[bInfo.id];
                    if (!el) {
                        const safeTag = ['P', 'H1', 'H2', 'H3', 'BLOCKQUOTE', 'UL', 'OL', 'TABLE'].includes(String(bInfo.tag).toUpperCase())
                            ? String(bInfo.tag).toUpperCase()
                            : 'P';
                        el = document.createElement(safeTag);
                        el.setAttribute('data-block-id', String(bInfo.id).replace(/[^a-zA-Z0-9_-]/g, ''));
                        el.innerHTML = sanitizeWordPeerHtml(bInfo.html || '');
                        if (idx < wordSurface.children.length) {
                            wordSurface.insertBefore(el, wordSurface.children[idx]);
                        } else {
                            wordSurface.appendChild(el);
                        }
                    } else if (bInfo.id !== myActiveId) {
                        el.innerHTML = sanitizeWordPeerHtml(bInfo.html || '');
                    }
                });
                lastKnownBlockCount = wordSurface.children.length;
            }
        } else if (OFFICE_EDITOR_TYPE === 'slides') {
            if (d.type === 'slides_slide' && d.payload && typeof d.payload.slideIdx === 'number' && d.payload.slide) {
                const idx = d.payload.slideIdx;
                if (slidesDeckState.slides && slidesDeckState.slides[idx]) {
                    slidesDeckState.slides[idx] = d.payload.slide;
                    if (idx !== activeSlideIdx || Date.now() - slidesLastTypedAt > 1200) {
                        renderPptxEditor();
                    } else {
                        renderPptxThumbnails();
                    }
                }
            } else if (d.type === 'slides_full' && d.payload && Array.isArray(d.payload.slides)) {
                if (Date.now() - slidesLastTypedAt > 1200) {
                    slidesDeckState = d.payload;
                    ensureSlidesDeckValid();
                    renderPptxEditor();
                }
            }
        } else if (OFFICE_EDITOR_TYPE === 'visio') {
            if (d.type === 'visio_full' && d.payload && Array.isArray(d.payload.nodes)) {
                if (Date.now() - visioLastTypedAt > 1200 && !visioDraggingNode) {
                    visioState = d.payload;
                    ensureVisioValid();
                    renderVisioCanvas();
                }
            }
        } else if (plainTextEl && d.type === 'text_full' && typeof d.payload === 'string') {
            // Nur übernehmen, wenn man nicht gerade in den letzten 1,2 Sekunden selbst getippt hat
            if (Date.now() - plainLastTypedAt > 1200) {
                const prevStart = plainTextEl.selectionStart;
                const prevEnd   = plainTextEl.selectionEnd;
                plainTextEl.value = d.payload;
                plainTextEl.setSelectionRange(prevStart, prevEnd);
            }
        }
    });

    if (sheetNeedsRender) {
        renderSpreadsheet();
    }
    if (OFFICE_EDITOR_TYPE === 'word') {
        applyWordPeerLocks();
    }
}

async function runCollabSyncTick() {
    if (collabSyncInFlight) return;
    const bar = document.getElementById('collabPresenceBar');
    if (!bar) return;

    collabSyncInFlight = true;
    const batch = collabOutQueue.splice(0, collabOutQueue.length);

    const fd = new FormData();
    fd.append('action', 'collab_sync');
    fd.append('csrf_token', OFFICE_CSRF_TOKEN);
    fd.append('file_id', String(OFFICE_FILE_ID));
    fd.append('focus_target', currentFocusTarget);
    fd.append('focus_label', currentFocusLabel);
    fd.append('since_id', String(collabLastDeltaId));
    if (!collabInitialized) {
        fd.append('init', '1');
    }
    if (batch.length > 0) {
        fd.append('deltas_json', JSON.stringify(batch));
    }

    try {
        const resp = await fetch('office.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data && data.success) {
            collabInitialized = true;
            if (typeof data.maxDeltaId === 'number' && data.maxDeltaId > collabLastDeltaId) {
                collabLastDeltaId = data.maxDeltaId;
            }
            const prevPeersJson = JSON.stringify(collabPeers);
            collabPeers = Array.isArray(data.collaborators) ? data.collaborators : [];
            renderCollabPresenceBar();

            if (Array.isArray(data.deltas) && data.deltas.length > 0) {
                applyIncomingDeltas(data.deltas);
            } else if (JSON.stringify(collabPeers) !== prevPeersJson) {
                if (OFFICE_EDITOR_TYPE === 'sheet') {
                    renderSpreadsheet();
                } else if (OFFICE_EDITOR_TYPE === 'word') {
                    applyWordPeerLocks();
                } else if (OFFICE_EDITOR_TYPE === 'slides') {
                    renderPptxThumbnails();
                } else if (OFFICE_EDITOR_TYPE === 'visio') {
                    renderVisioCanvas();
                }
            }
        }
    } catch (e) {
        // Bei kurzem Verbindungsabbruch ungesendete Deltas wieder vorne einreihen
        if (batch.length > 0) {
            collabOutQueue.unshift(...batch);
        }
    } finally {
        collabSyncInFlight = false;
    }
}

window.addEventListener('pagehide', () => {
    try {
        const fd = new FormData();
        fd.append('action', 'collab_sync');
        fd.append('csrf_token', OFFICE_CSRF_TOKEN);
        fd.append('file_id', String(OFFICE_FILE_ID));
        fd.append('leave', '1');
        navigator.sendBeacon('office.php', fd);
    } catch (e) {}
});

if (document.getElementById('collabPresenceBar')) {
    runCollabSyncTick();
    setInterval(runCollabSyncTick, 1500);
}
</script>

<?php if ($canShareFile && !$isVaultLocked): ?>
<!-- Direktes Freigabe-Modal in Office Light (Benutzer-Live-Team & Öffentlicher Link / QR-Code) -->
<div id="officeShareModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 backdrop-blur-xs p-4">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-xl w-full max-h-[90vh] overflow-y-auto p-5 sm:p-6 shadow-2xl space-y-5">
        <div class="flex items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-lg shrink-0">🔗</span>
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white truncate">
                        Dokument freigeben: <?= e($origName) ?>
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Für Live-Team-Mitarbeit mit Cloud-Mitgliedern teilen oder öffentlichen Link erzeugen
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeOfficeShareModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen">
                ✕
            </button>
        </div>

        <!-- 1. Direkt mit Cloud-Mitgliedern teilen (inkl. Live-Co-Editing) -->
        <div class="space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                👥 Mit Cloud-Benutzern teilen (für Live-Team-Zusammenarbeit)
            </h4>
            <?php if (empty($shareablePeers)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/60 p-3 rounded-xl border border-slate-200 dark:border-slate-700">
                    Aktuell haben keine weiteren Cloud-Benutzer die Direktfreigabe in ihren Einstellungen aktiviert.
                </p>
            <?php else: ?>
                <form onsubmit="submitOfficeUserShare(event)" class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                    <div class="sm:col-span-5">
                        <select id="officeShareTargetUser" required
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-white">
                            <option value="">Benutzer wählen...</option>
                            <?php foreach ($shareablePeers as $peer): ?>
                                <option value="<?= (int)$peer['id'] ?>"><?= e((string)$peer['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <select id="officeSharePerm"
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-white">
                            <option value="1" selected>✏️ Lesen &amp; Mitschreiben (Live)</option>
                            <option value="0">👁️ Nur Lesen</option>
                            <option value="2">🗑️ Lesen, Schreiben &amp; Löschen</option>
                        </select>
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit"
                            class="w-full px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer">
                            + Freigeben
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <div id="officeUserSharesList" class="space-y-1.5 text-xs"></div>
        </div>

        <!-- 2. Öffentlicher Freigabelink & QR-Code -->
        <div class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                🌐 Öffentlicher Freigabelink (optional mit Passwort &amp; Ablaufdatum)
            </h4>
            <form onsubmit="submitOfficePublicLink(event)" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="password" id="officeSharePass" placeholder="Passwort (optional)"
                    class="px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                <select id="officeShareDays"
                    class="px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-white">
                    <option value="0">Unbegrenzt gültig</option>
                    <option value="1">1 Tag gültig</option>
                    <option value="7" selected>7 Tage gültig</option>
                    <option value="30">30 Tage gültig</option>
                </select>
                <button type="submit"
                    class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer">
                    🔗 Link erzeugen
                </button>
            </form>

            <div id="officePublicLinksList" class="space-y-2.5 text-xs"></div>
        </div>
    </div>
</div>

<script>
function openOfficeShareModal() {
    const modal = document.getElementById('officeShareModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    loadOfficeShareDetails();
}

function closeOfficeShareModal() {
    const modal = document.getElementById('officeShareModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function escOfficeShareHtml(str) {
    return String(str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

async function loadOfficeShareDetails() {
    const uList = document.getElementById('officeUserSharesList');
    const lList = document.getElementById('officePublicLinksList');
    if (!uList || !lList) return;

    try {
        const res = await fetch('share_action.php?action=list&file_id=' + encodeURIComponent(OFFICE_FILE_ID));
        const data = await res.json();
        if (!data || !data.success) return;

        // Benutzer-Freigaben rendern
        const users = Array.isArray(data.user_shares) ? data.user_shares : [];
        if (users.length === 0) {
            uList.innerHTML = '<p class="text-slate-400 dark:text-slate-500 italic">Noch für keinen anderen Cloud-Benutzer freigegeben.</p>';
        } else {
            uList.innerHTML = users.map(u => {
                const badge = u.can_delete
                    ? '🗑️ Lesen, Schreiben & Löschen'
                    : (u.can_write ? '✏️ Lesen & Mitschreiben (Live)' : '👁️ Nur Lesen');
                return `
                    <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 flex items-center justify-between gap-2">
                        <div>
                            <span class="font-bold text-slate-900 dark:text-white">👤 ${escOfficeShareHtml(u.username)}</span>
                            <span class="ml-2 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-[11px] font-semibold text-slate-600 dark:text-slate-300">${badge}</span>
                        </div>
                        <button type="button" onclick="revokeOfficeUserShare(${Number(u.id)})"
                            class="px-2 py-1 rounded-lg text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/60 font-bold cursor-pointer">
                            Entfernen
                        </button>
                    </div>
                `;
            }).join('');
        }

        // Öffentliche Links rendern
        const links = Array.isArray(data.links) ? data.links : [];
        if (links.length === 0) {
            lList.innerHTML = '<p class="text-slate-400 dark:text-slate-500 italic">Noch kein öffentlicher Freigabelink aktiv.</p>';
        } else {
            lList.innerHTML = links.map(l => `
                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 space-y-2">
                    <div class="flex items-center gap-2">
                        <input type="text" readonly value="${escOfficeShareHtml(l.url)}"
                            class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 font-mono text-[11px] text-slate-800 dark:text-slate-200">
                        <button type="button" onclick="navigator.clipboard.writeText(${JSON.stringify(l.url).replace(/"/g, '&quot;')})"
                            class="px-2.5 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-bold shrink-0 cursor-pointer">
                            📋 Kopieren
                        </button>
                        <button type="button" onclick="deleteOfficePublicLink(${Number(l.id)})"
                            class="px-2.5 py-1.5 rounded-lg bg-rose-100 hover:bg-rose-200 dark:bg-rose-950/70 text-rose-700 dark:text-rose-300 font-bold shrink-0 cursor-pointer">
                            🗑️
                        </button>
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                        <span>${l.has_password ? '🔒 Passwortgeschützt' : '🌐 Ohne Passwort'} · ${l.expires_at ? 'Gültig bis ' + escOfficeShareHtml(l.expires_at) : 'Unbegrenzt gültig'}</span>
                        <span>Downloads: ${Number(l.download_count || 0)}</span>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) {}
}

async function submitOfficeUserShare(ev) {
    ev.preventDefault();
    const targetEl = document.getElementById('officeShareTargetUser');
    const permEl = document.getElementById('officeSharePerm');
    if (!targetEl || !targetEl.value) return;

    const fd = new FormData();
    fd.append('action', 'share_user');
    fd.append('csrf_token', OFFICE_CSRF_TOKEN);
    fd.append('file_id', String(OFFICE_FILE_ID));
    fd.append('target_user_id', targetEl.value);
    fd.append('can_write', permEl ? permEl.value : '1');

    const res = await fetch('share_action.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data && data.success) {
        targetEl.value = '';
        loadOfficeShareDetails();
    } else {
        alert((data && data.message) ? data.message : 'Fehler beim Freigeben.');
    }
}

async function revokeOfficeUserShare(userShareId) {
    const fd = new FormData();
    fd.append('action', 'unshare_user');
    fd.append('csrf_token', OFFICE_CSRF_TOKEN);
    fd.append('user_share_id', String(userShareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadOfficeShareDetails();
}

async function submitOfficePublicLink(ev) {
    ev.preventDefault();
    const passEl = document.getElementById('officeSharePass');
    const daysEl = document.getElementById('officeShareDays');

    const fd = new FormData();
    fd.append('action', 'create_link');
    fd.append('csrf_token', OFFICE_CSRF_TOKEN);
    fd.append('file_id', String(OFFICE_FILE_ID));
    fd.append('password', passEl ? passEl.value : '');
    fd.append('expires_days', daysEl ? daysEl.value : '0');

    const res = await fetch('share_action.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data && data.success) {
        if (passEl) passEl.value = '';
        loadOfficeShareDetails();
    } else {
        alert((data && data.message) ? data.message : 'Fehler beim Erstellen des Links.');
    }
}

async function deleteOfficePublicLink(shareId) {
    const fd = new FormData();
    fd.append('action', 'delete_link');
    fd.append('csrf_token', OFFICE_CSRF_TOKEN);
    fd.append('share_id', String(shareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadOfficeShareDetails();
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
