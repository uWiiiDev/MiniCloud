<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

$action = (string)($_REQUEST['action'] ?? '');

/**
 * Prüft, ob der aktuelle Benutzer die Datei teilen/verwalten darf.
 *
 * @return array<string, mixed>|null
 */
function getManageableFile(PDO $pdo, int $userId, int $fileId, bool $isAdmin): ?array
{
    if ($fileId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        return null;
    }

    if ((int)$file['user_id'] === $userId) {
        return $file;
    }

    if (!empty($file['group_folder_id']) && canUserAccessGroupFolder($pdo, $userId, (int)$file['group_folder_id'], true, $isAdmin)) {
        return $file;
    }

    return null;
}

if ($action === 'list') {
    $fileId     = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
    $folderPath = isset($_GET['folder_path']) ? sanitizeFolderPathInput((string)$_GET['folder_path']) : '';
    $baseUrl    = getBaseUrl();

    if ($folderPath !== '') {
        // Ordner-Freigaben laden
        $stmtLinks = $pdo->prepare(
            'SELECT id, share_token, share_mode, (password_hash IS NOT NULL) AS has_password, expires_at, max_downloads, download_count, created_at
             FROM shares
             WHERE user_id = :uid AND folder_path = :fp
             ORDER BY created_at DESC'
        );
        $stmtLinks->execute([':uid' => $userId, ':fp' => $folderPath]);
        $links = [];
        foreach ($stmtLinks->fetchAll() as $row) {
            $linkUrl = $baseUrl . '/share.php?token=' . rawurlencode((string)$row['share_token']);
            $links[] = [
                'id'             => (int)$row['id'],
                'token'          => (string)$row['share_token'],
                'share_mode'     => (string)($row['share_mode'] ?? 'read'),
                'url'            => $linkUrl,
                'qr_svg'         => renderQrCodeSvg($linkUrl, 130),
                'has_password'   => (bool)$row['has_password'],
                'max_downloads'  => $row['max_downloads'] !== null ? (int)$row['max_downloads'] : null,
                'download_count' => (int)($row['download_count'] ?? 0),
                'expires_at'     => $row['expires_at'] ? date('d.m.Y H:i', strtotime((string)$row['expires_at'])) : null,
                'created_at'     => date('d.m.Y H:i', strtotime((string)$row['created_at'])),
            ];
        }

        $stmtUsers = $pdo->prepare(
            'SELECT us.id, us.shared_with_user_id, us.can_write, us.can_delete, u.username, us.created_at
             FROM user_shares us
             INNER JOIN users u ON u.id = us.shared_with_user_id
             WHERE us.owner_id = :uid AND us.folder_path = :fp
             ORDER BY u.username ASC'
        );
        $stmtUsers->execute([':uid' => $userId, ':fp' => $folderPath]);
        $userShares = [];
        foreach ($stmtUsers->fetchAll() as $uRow) {
            $userShares[] = [
                'id'         => (int)$uRow['id'],
                'user_id'    => (int)$uRow['shared_with_user_id'],
                'username'   => (string)$uRow['username'],
                'can_write'  => ((int)($uRow['can_write'] ?? 0) === 1),
                'can_delete' => ((int)($uRow['can_delete'] ?? 0) === 1),
                'created_at' => date('d.m.Y H:i', strtotime((string)$uRow['created_at'])),
            ];
        }

        echo json_encode([
            'success'     => true,
            'folder_path' => $folderPath,
            'links'       => $links,
            'user_shares' => $userShares,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = getManageableFile($pdo, $userId, $fileId, $isAdmin);
    if (!$file) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für diese Datei.']);
        exit;
    }

    $stmtLinks = $pdo->prepare(
        'SELECT id, share_token, share_mode, (password_hash IS NOT NULL) AS has_password, expires_at, max_downloads, download_count, created_at
         FROM shares
         WHERE file_id = :file_id
         ORDER BY created_at DESC'
    );
    $stmtLinks->execute([':file_id' => $fileId]);
    $links = [];
    foreach ($stmtLinks->fetchAll() as $row) {
        $linkUrl = $baseUrl . '/share.php?token=' . rawurlencode((string)$row['share_token']);
        $links[] = [
            'id'             => (int)$row['id'],
            'token'          => (string)$row['share_token'],
            'share_mode'     => (string)($row['share_mode'] ?? 'read'),
            'url'            => $linkUrl,
            'qr_svg'         => renderQrCodeSvg($linkUrl, 130),
            'has_password'   => (bool)$row['has_password'],
            'max_downloads'  => $row['max_downloads'] !== null ? (int)$row['max_downloads'] : null,
            'download_count' => (int)($row['download_count'] ?? 0),
            'expires_at'     => $row['expires_at'] ? date('d.m.Y H:i', strtotime((string)$row['expires_at'])) : null,
            'created_at'     => date('d.m.Y H:i', strtotime((string)$row['created_at'])),
        ];
    }

    $stmtUsers = $pdo->prepare(
        'SELECT us.id, us.shared_with_user_id, us.can_write, us.can_delete, u.username, us.created_at
         FROM user_shares us
         INNER JOIN users u ON u.id = us.shared_with_user_id
         WHERE us.file_id = :file_id
         ORDER BY u.username ASC'
    );
    $stmtUsers->execute([':file_id' => $fileId]);
    $userShares = [];
    foreach ($stmtUsers->fetchAll() as $uRow) {
        $userShares[] = [
            'id'         => (int)$uRow['id'],
            'user_id'    => (int)$uRow['shared_with_user_id'],
            'username'   => (string)$uRow['username'],
            'can_write'  => ((int)($uRow['can_write'] ?? 0) === 1),
            'can_delete' => ((int)($uRow['can_delete'] ?? 0) === 1),
            'created_at' => date('d.m.Y H:i', strtotime((string)$uRow['created_at'])),
        ];
    }

    echo json_encode([
        'success'     => true,
        'file_id'     => $fileId,
        'file_name'   => (string)$file['original_name'],
        'links'       => $links,
        'user_shares' => $userShares,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage-Methode.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token. Bitte laden Sie die Seite neu.']);
    exit;
}

// 1. Öffentlichen Freigabelink generieren (für Datei ODER Ordner)
if ($action === 'create_link') {
    $fileId       = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $folderPath   = isset($_POST['folder_path']) ? sanitizeFolderPathInput((string)$_POST['folder_path']) : '';
    $password     = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
    $expiresDays  = isset($_POST['expires_days']) ? (int)$_POST['expires_days'] : 0;
    $maxDownloads = isset($_POST['max_downloads']) && (int)$_POST['max_downloads'] > 0
        ? min(10000, (int)$_POST['max_downloads'])
        : null;
    $rawMode      = isset($_POST['share_mode']) ? trim((string)$_POST['share_mode']) : 'read';
    $shareMode    = ($folderPath !== '' && in_array($rawMode, ['read', 'upload_only', 'read_write'], true))
        ? $rawMode
        : 'read';

    $targetNameForLog = $folderPath;
    if ($folderPath === '') {
        $file = getManageableFile($pdo, $userId, $fileId, $isAdmin);
        if (!$file) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Zugriff verweigert: Sie können diese Datei nicht teilen.']);
            exit;
        }
        $targetNameForLog = (string)$file['original_name'];
    }

    // Eindeutigen, nicht erratbaren Token erzeugen per bin2hex(random_bytes(16))
    do {
        $token = bin2hex(random_bytes(16));
        $stmtCheck = $pdo->prepare('SELECT id FROM shares WHERE share_token = :token LIMIT 1');
        $stmtCheck->execute([':token' => $token]);
    } while ($stmtCheck->fetch());

    $passwordHash = null;
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    $expiresAt = null;
    if ($expiresDays > 0 && $expiresDays <= 3650) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresDays * 86400));
    } elseif (!empty($_POST['expires_at']) && is_string($_POST['expires_at'])) {
        $parsedTs = strtotime(trim($_POST['expires_at']));
        if ($parsedTs !== false && $parsedTs > time()) {
            $expiresAt = date('Y-m-d H:i:s', $parsedTs);
        }
    }

    $stmtInsert = $pdo->prepare(
        'INSERT INTO shares (file_id, folder_path, user_id, share_token, share_mode, password_hash, expires_at, max_downloads, download_count)
         VALUES (:file_id, :folder_path, :user_id, :share_token, :share_mode, :password_hash, :expires_at, :max_downloads, 0)'
    );
    $stmtInsert->execute([
        ':file_id'       => $folderPath === '' ? $fileId : null,
        ':folder_path'   => $folderPath !== '' ? $folderPath : null,
        ':user_id'       => $userId,
        ':share_token'   => $token,
        ':share_mode'    => $shareMode,
        ':password_hash' => $passwordHash,
        ':expires_at'    => $expiresAt,
        ':max_downloads' => $maxDownloads,
    ]);

    $shareUrl = getBaseUrl() . '/share.php?token=' . rawurlencode($token);
    logAuditEvent(
        $pdo,
        'share_create',
        sprintf(
            'Öffentlicher Link für %s "%s" erstellt (Modus: %s%s)',
            $folderPath !== '' ? 'Ordner' : 'Datei',
            $targetNameForLog,
            $shareMode,
            $maxDownloads !== null ? ', Max. Downloads: ' . $maxDownloads : ''
        )
    );

    echo json_encode([
        'success'        => true,
        'message'        => 'Freigabelink erfolgreich generiert!',
        'share_id'       => (int)$pdo->lastInsertId(),
        'share_token'    => $token,
        'share_mode'     => $shareMode,
        'share_url'      => $shareUrl,
        'qr_svg'         => renderQrCodeSvg($shareUrl, 140),
        'has_password'   => ($passwordHash !== null),
        'max_downloads'  => $maxDownloads,
        'download_count' => 0,
        'expires_at'     => $expiresAt ? date('d.m.Y H:i', strtotime($expiresAt)) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Öffentlichen Freigabelink löschen / widerrufen
if ($action === 'delete_link') {
    $shareId = isset($_POST['share_id']) ? (int)$_POST['share_id'] : 0;
    $stmt = $pdo->prepare('SELECT id, file_id, folder_path, user_id FROM shares WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $shareId]);
    $shareRow = $stmt->fetch();

    $canDeleteLink = false;
    if ($shareRow) {
        if ((int)$shareRow['user_id'] === $userId) {
            $canDeleteLink = true;
        } elseif (!empty($shareRow['file_id']) && getManageableFile($pdo, $userId, (int)$shareRow['file_id'], $isAdmin)) {
            $canDeleteLink = true;
        }
    }

    if (!$canDeleteLink) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Freigabelink nicht gefunden oder keine Berechtigung.']);
        exit;
    }

    $pdo->prepare('DELETE FROM shares WHERE id = :id')->execute([':id' => $shareId]);
    logAuditEvent($pdo, 'share_delete', sprintf('Öffentlicher Freigabelink #%d widerrufen', $shareId));
    echo json_encode(['success' => true, 'message' => 'Freigabelink wurde widerrufen.']);
    exit;
}

// 3. Datei ODER Ordner direkt mit einem anderen Cloud-Benutzer teilen
if ($action === 'share_user') {
    $fileId       = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $folderPath   = isset($_POST['folder_path']) ? sanitizeFolderPathInput((string)$_POST['folder_path']) : '';
    $targetUserId = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
    $permRaw      = (string)($_POST['can_write'] ?? '0');
    $canWrite     = ($permRaw === '1' || $permRaw === '2') ? 1 : 0;
    $canDelete    = ($permRaw === '2' || (isset($_POST['can_delete']) && (string)$_POST['can_delete'] === '1')) ? 1 : 0;
    if ($canDelete === 1) {
        $canWrite = 1;
    }

    if ($folderPath === '') {
        $file = getManageableFile($pdo, $userId, $fileId, $isAdmin);
        if (!$file) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für diese Datei.']);
            exit;
        }
    }

    if ($targetUserId <= 0 || $targetUserId === $userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bitte wählen Sie einen gültigen anderen Cloud-Benutzer aus.']);
        exit;
    }

    $stmtTarget = $pdo->prepare('SELECT id, username, allow_user_sharing FROM users WHERE id = :id LIMIT 1');
    $stmtTarget->execute([':id' => $targetUserId]);
    $targetUser = $stmtTarget->fetch();
    if (!$targetUser || (int)($targetUser['allow_user_sharing'] ?? 0) !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Dieser Benutzer hat Direktfreigaben in seinen Einstellungen nicht aktiviert.']);
        exit;
    }

    if ($folderPath !== '') {
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM user_shares WHERE owner_id = :oid AND folder_path = :fp AND shared_with_user_id = :tid LIMIT 1'
        );
        $stmtCheck->execute([':oid' => $userId, ':fp' => $folderPath, ':tid' => $targetUserId]);
        $existingRow = $stmtCheck->fetch();
        if ($existingRow) {
            $pdo->prepare('UPDATE user_shares SET can_write = :cw, can_delete = :cd WHERE id = :id')
                ->execute([':cw' => $canWrite, ':cd' => $canDelete, ':id' => (int)$existingRow['id']]);
        } else {
            $stmtIns = $pdo->prepare(
                'INSERT INTO user_shares (file_id, folder_path, owner_id, shared_with_user_id, can_write, can_delete)
                 VALUES (NULL, :fp, :oid, :tid, :cw, :cd)'
            );
            $stmtIns->execute([':fp' => $folderPath, ':oid' => $userId, ':tid' => $targetUserId, ':cw' => $canWrite, ':cd' => $canDelete]);
        }
    } else {
        $stmtCheck = $pdo->prepare(
            'SELECT id FROM user_shares WHERE file_id = :fid AND shared_with_user_id = :tid LIMIT 1'
        );
        $stmtCheck->execute([':fid' => $fileId, ':tid' => $targetUserId]);
        $existingRow = $stmtCheck->fetch();
        if ($existingRow) {
            $pdo->prepare('UPDATE user_shares SET can_write = :cw, can_delete = :cd WHERE id = :id')
                ->execute([':cw' => $canWrite, ':cd' => $canDelete, ':id' => (int)$existingRow['id']]);
        } else {
            $stmtInsert = $pdo->prepare(
                'INSERT INTO user_shares (file_id, folder_path, owner_id, shared_with_user_id, can_write, can_delete)
                 VALUES (:file_id, NULL, :owner_id, :shared_with, :cw, :cd)'
            );
            $stmtInsert->execute([
                ':file_id'     => $fileId,
                ':owner_id'    => $userId,
                ':shared_with' => $targetUserId,
                ':cw'          => $canWrite,
                ':cd'          => $canDelete,
            ]);
        }
    }

    echo json_encode([
        'success'    => true,
        'message'    => sprintf('Wurde für "%s" freigegeben und erscheint automatisch in dessen Dashboard.', (string)$targetUser['username']),
        'username'   => (string)$targetUser['username'],
        'can_write'  => ($canWrite === 1),
        'can_delete' => ($canDelete === 1),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. Interne Benutzer-Freigabe aufheben
if ($action === 'unshare_user') {
    $userShareId = isset($_POST['user_share_id']) ? (int)$_POST['user_share_id'] : 0;
    $stmt = $pdo->prepare('SELECT id, file_id, owner_id FROM user_shares WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userShareId]);
    $usRow = $stmt->fetch();

    $canRevoke = false;
    if ($usRow) {
        if ((int)$usRow['owner_id'] === $userId) {
            $canRevoke = true;
        } elseif (!empty($usRow['file_id']) && getManageableFile($pdo, $userId, (int)$usRow['file_id'], $isAdmin)) {
            $canRevoke = true;
        }
    }

    if (!$canRevoke) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Freigabe nicht gefunden oder keine Berechtigung.']);
        exit;
    }

    $pdo->prepare('DELETE FROM user_shares WHERE id = :id')->execute([':id' => $userShareId]);
    echo json_encode(['success' => true, 'message' => 'Benutzer-Freigabe wurde entfernt.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion.']);
