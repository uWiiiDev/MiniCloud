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

    if (!in_array($docType, ['docx', 'xlsx', 'csv', 'txt', 'md'], true)) {
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
            'xlsx' => 'Neue_Tabelle_' . date('Y-m-d'),
            'csv'  => 'Neue_Liste_' . date('Y-m-d'),
            'txt'  => 'Neue_Notiz_' . date('Y-m-d'),
            'md'   => ' Neues_Protokoll_' . date('Y-m-d'),
            default => 'Neues_Dokument_' . date('Y-m-d'),
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
    $createdOk = false;

    if ($docType === 'docx') {
        $starterHtml = '<h1>' . htmlspecialchars($docTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p></p>';
        $createdOk = writeHtmlAsDocx($starterHtml, $physicalPath);
    } elseif ($docType === 'xlsx' || $docType === 'csv') {
        $starterRows = array_fill(0, 18, array_fill(0, 8, ''));
        $createdOk = writeSpreadsheetData([['name' => 'Tabelle1', 'rows' => $starterRows]], $physicalPath, $cleanBase);
    } elseif ($docType === 'md') {
        $starterMd = '# ' . $docTitle . "\n\n";
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

    // Optionaler neuer Dateiname (Endung bleibt erhalten)
    $newTitleInput = trim((string)($_POST['doc_title'] ?? ''));
    $updatedOrigName = $origName;
    if ($newTitleInput !== '') {
        $cleanNew = sanitizeOriginalFilename($newTitleInput);
        $newBase  = pathinfo($cleanNew, PATHINFO_FILENAME);
        if ($newBase !== '') {
            $updatedOrigName = $newBase . '.' . $ext;
        }
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

                if (!in_array($dType, ['sheet_cell', 'sheet_full', 'word_block', 'word_full', 'text_full'], true) || $payload === null) {
                    continue;
                }

                // Konfliktprüfung für Einzel-Zelle oder Einzel-Absatz: Ist genau dieses Element gerade von jemand anderem fokussiert?
                if (in_array($dType, ['sheet_cell', 'word_block'], true) && $tKey !== '') {
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
// C) GET: DOKUMENT IM EDITOR ÖFFNEN
// ============================================================================
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$access = $fileId > 0 ? resolveOfficeFileAccess($pdo, $fileId, $userId, $isAdmin) : null;

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
$backUrl = 'index.php' . (!empty($returnQuery) ? '?' . http_build_query($returnQuery) : '');

$storedFilename = basename((string)$file['filename']);
$physicalPath   = UPLOAD_DIR . $storedFilename;
$isVaultLocked  = ((int)($file['is_encrypted'] ?? 0) === 1) || (is_file($physicalPath) && isVaultEncryptedFile($physicalPath));

// Inhalt je nach Editor-Typ laden
$wordHtmlContent = '';
$sheetsData      = [];
$textContent     = '';

if (!$isVaultLocked && is_file($physicalPath)) {
    if ($editorType === 'word') {
        $wordHtmlContent = readDocxAsHtml($physicalPath);
    } elseif ($editorType === 'sheet') {
        $sheetsData = readSpreadsheetData($physicalPath, $origName);
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
            title="Zurück zur Dateiliste"
        >
            &larr; Zurück
        </a>

        <?php
        $typeBadge = match ($editorType) {
            'word'  => ['icon' => '📄', 'label' => 'Word Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800'],
            'sheet' => ['icon' => '📊', 'label' => 'Calc Light (.' . strtoupper($ext) . ')', 'cls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'],
            default => ['icon' => '📝', 'label' => 'Text-Editor (.' . strtoupper($ext) . ')', 'cls' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700'],
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

        <?php if ($canEdit && !$isVaultLocked): ?>
            <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer select-none" title="Speichert Änderungen bei Team-Bearbeitung nach wenigen Sekunden automatisch in die Datei">
                <input type="checkbox" id="chkOfficeAutoSave" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>⚡ Auto-Save</span>
            </label>

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
                : '🛡️ Live-Synchronisation für Text-/Markdown-Zeilen aktiv') ?>
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
                    <button type="button" onclick="insertFormulaTemplate('=MIN(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">Min</button>
                    <button type="button" onclick="insertFormulaTemplate('=MAX(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">Max</button>
                    <button type="button" onclick="insertFormulaTemplate('=ANZAHL(A1:A5)')" class="px-2 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">Anzahl</button>

                    <button type="button" onclick="exportActiveSheetCsv()" class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer ml-auto" title="Aktuelles Tabellenblatt als CSV herunterladen">📥 Als CSV exportieren</button>
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
        // Bereichsfunktionen SUM / SUMME / AVERAGE / MITTELWERT / MIN / MAX / COUNT / ANZAHL
        expr = expr.replace(/\b(SUMME|SUM|MITTELWERT|AVERAGE|AVG|MIN|MAX|ANZAHL|COUNT)\s*\(([^()]+)\)/gi, (_, fnName, inside) => {
            const fn = fnName.toUpperCase();
            const vals = resolveArgsNumbers(inside);
            if (fn === 'ANZAHL' || fn === 'COUNT') return String(vals.length);
            if (vals.length === 0) return '0';
            const sum = vals.reduce((a, b) => a + b, 0);
            if (fn === 'SUMME' || fn === 'SUM') return String(sum);
            if (fn === 'MITTELWERT' || fn === 'AVERAGE' || fn === 'AVG') return String(sum / vals.length);
            if (fn === 'MIN') return String(Math.min(...vals));
            if (fn === 'MAX') return String(Math.max(...vals));
            return '0';
        });

        // Einzelne Zellreferenzen (z. B. A1, B12) durch ihren Zahlenwert ersetzen
        expr = expr.replace(/\b([A-Za-z]+)(\d+)\b/g, (_, colLet, rowNumStr) => {
            const cc = colNameToIdx(colLet);
            const rr = parseInt(rowNumStr, 10) - 1;
            const cellVal = evaluateSheetCell(sheetIdx, rr, cc, { ...visited });
            const num = parseFloat(String(cellVal).replace(',', '.'));
            return isNaN(num) ? '0' : String(num);
        });

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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
