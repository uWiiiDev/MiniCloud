<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_chat'] ?? '1') !== '1') {
    setFlash('warning', 'Das Chat-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];
$isAdmin  = isAdmin();

/**
 * Prüft, ob $userId dem Benutzer $targetUserId eine Direktnachricht schreiben darf.
 * Erlaubt, wenn:
 *  - $isAdmin true ist (Admin darf jeden Benutzer anschreiben), ODER
 *  - Zielbenutzer selbst Administrator ist (jeder Nutzer darf den Admin kontaktieren), ODER
 *  - Zielbenutzer allow_user_sharing = 1 aktiviert hat, ODER
 *  - bereits ein Chatverlauf zwischen beiden existiert (damit man auf empfangene Nachrichten antworten kann).
 *
 * @return array<string, mixed>|null
 */
function getAllowedChatPartner(PDO $pdo, int $userId, int $targetUserId, bool $isAdmin): ?array
{
    if ($targetUserId <= 0 || $targetUserId === $userId) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, is_admin, allow_user_sharing FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $targetUserId]);
    $target = $stmt->fetch();
    if (!$target) {
        return null;
    }

    if ($isAdmin || (int)$target['is_admin'] === 1 || (int)($target['allow_user_sharing'] ?? 0) === 1) {
        return $target;
    }

    // Prüfen, ob bereits Nachrichten zwischen beiden ausgetauscht wurden
    $stmtHist = $pdo->prepare(
        'SELECT id FROM messages
         WHERE is_broadcast = 0
           AND ((sender_id = :u1 AND recipient_id = :t1) OR (sender_id = :t2 AND recipient_id = :u2))
         LIMIT 1'
    );
    $stmtHist->execute([
        ':u1' => $userId,
        ':t1' => $targetUserId,
        ':t2' => $targetUserId,
        ':u2' => $userId,
    ]);
    if ($stmtHist->fetch()) {
        return $target;
    }

    return null;
}

/**
 * Markiert alle Rundnachrichten bis zur aktuell höchsten ID als vom Benutzer gelesen.
 */
function markBroadcastsAsRead(PDO $pdo, int $userId): void
{
    $maxBroadId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM messages WHERE is_broadcast = 1')->fetchColumn();
    if ($maxBroadId > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO message_broadcast_reads (user_id, last_read_broadcast_id)
             VALUES (:uid, :max_id)
             ON DUPLICATE KEY UPDATE last_read_broadcast_id = GREATEST(last_read_broadcast_id, :max_id_upd)'
        );
        $stmt->execute([
            ':uid'        => $userId,
            ':max_id'     => $maxBroadId,
            ':max_id_upd' => $maxBroadId,
        ]);
    }
}

// --- 1. AJAX-Endpunkte für Live-Polling & globale Benachrichtigungen ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_unread') {
    header('Content-Type: application/json; charset=utf-8');
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));

    $stmtMax = $pdo->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM messages
         WHERE recipient_id = :u1 OR is_broadcast = 1'
    );
    $stmtMax->execute([':u1' => $userId]);
    $maxId = (int)$stmtMax->fetchColumn();

    $latestUnread = null;
    if ($sinceId > 0 && $maxId > $sinceId) {
        $stmtLatest = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.is_broadcast, m.message_text, u.username AS sender_username
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             WHERE m.id > :since_id
               AND m.sender_id <> :uid1
               AND (
                   (m.is_broadcast = 0 AND m.recipient_id = :uid2 AND m.is_read = 0)
                   OR (m.is_broadcast = 1 AND m.id > COALESCE((SELECT last_read_broadcast_id FROM message_broadcast_reads WHERE user_id = :uid3), 0))
               )
             ORDER BY m.id DESC
             LIMIT 1'
        );
        $stmtLatest->execute([
            ':since_id' => $sinceId,
            ':uid1'     => $userId,
            ':uid2'     => $userId,
            ':uid3'     => $userId,
        ]);
        $rowLatest = $stmtLatest->fetch();
        if ($rowLatest) {
            $isBroad = ((int)$rowLatest['is_broadcast'] === 1);
            $latestUnread = [
                'id'              => (int)$rowLatest['id'],
                'sender_username' => (string)$rowLatest['sender_username'],
                'message_text'    => mb_substr((string)$rowLatest['message_text'], 0, 140),
                'is_broadcast'    => $isBroad,
                'channel'         => $isBroad ? 'broadcast' : (string)(int)$rowLatest['sender_id'],
            ];
        }
    }

    echo json_encode([
        'success'       => true,
        'unread_total'  => getUnreadMessagesCount($pdo, $userId),
        'max_id'        => $maxId,
        'latest_unread' => $latestUnread,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll') {
    header('Content-Type: application/json; charset=utf-8');

    $channel = trim((string)($_GET['channel'] ?? ''));
    $lastId  = max(0, (int)($_GET['last_id'] ?? 0));
    $newMessages = [];

    if ($channel === 'broadcast') {
        markBroadcastsAsRead($pdo, $userId);
        $stmtNew = $pdo->prepare(
            'SELECT m.id, m.sender_id, m.recipient_id, m.is_broadcast, m.message_text, m.attached_file_id,
                    m.is_read, m.created_at, u.username AS sender_username, u.is_admin AS sender_is_admin,
                    af.original_name AS attached_name, af.file_size AS attached_size, af.mime_type AS attached_mime, af.is_encrypted AS attached_encrypted
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN files af ON af.id = m.attached_file_id AND af.deleted_at IS NULL
             WHERE m.is_broadcast = 1 AND m.id > :last_id
             ORDER BY m.id ASC'
        );
        $stmtNew->execute([':last_id' => $lastId]);
        $newMessages = $stmtNew->fetchAll();
    } elseif (ctype_digit($channel) && (int)$channel > 0) {
        $partnerId = (int)$channel;
        $partner = getAllowedChatPartner($pdo, $userId, $partnerId, $isAdmin);
        if ($partner) {
            // Empfangene Nachrichten dieses Partners als gelesen markieren
            $pdo->prepare(
                'UPDATE messages SET is_read = 1
                 WHERE is_broadcast = 0 AND sender_id = :pid AND recipient_id = :uid AND is_read = 0'
            )->execute([':pid' => $partnerId, ':uid' => $userId]);

            $stmtNew = $pdo->prepare(
                'SELECT m.id, m.sender_id, m.recipient_id, m.is_broadcast, m.message_text, m.attached_file_id,
                        m.is_read, m.created_at, u.username AS sender_username, u.is_admin AS sender_is_admin,
                        af.original_name AS attached_name, af.file_size AS attached_size, af.mime_type AS attached_mime, af.is_encrypted AS attached_encrypted
                 FROM messages m
                 INNER JOIN users u ON u.id = m.sender_id
                 LEFT JOIN files af ON af.id = m.attached_file_id AND af.deleted_at IS NULL
                 WHERE m.is_broadcast = 0
                   AND m.id > :last_id
                   AND ((m.sender_id = :u1 AND m.recipient_id = :p1) OR (m.sender_id = :p2 AND m.recipient_id = :u2))
                 ORDER BY m.id ASC'
            );
            $stmtNew->execute([
                ':last_id' => $lastId,
                ':u1'      => $userId,
                ':p1'      => $partnerId,
                ':p2'      => $partnerId,
                ':u2'      => $userId,
            ]);
            $newMessages = $stmtNew->fetchAll();
        }
    }

    $formatted = [];
    foreach ($newMessages as $m) {
        $ts = strtotime((string)$m['created_at']) ?: time();
        $attId   = !empty($m['attached_file_id']) && !empty($m['attached_name']) ? (int)$m['attached_file_id'] : 0;
        $attName = $attId > 0 ? (string)$m['attached_name'] : '';
        $attMime = $attId > 0 ? strtolower(trim((string)($m['attached_mime'] ?? ''))) : '';
        $attEnc  = $attId > 0 && ((int)($m['attached_encrypted'] ?? 0) === 1);
        $attExt  = $attId > 0 ? strtolower(pathinfo($attName, PATHINFO_EXTENSION)) : '';
        $attIsImg = $attId > 0 && !$attEnc && (str_starts_with($attMime, 'image/') || in_array($attExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true));
        $attIsAud = $attId > 0 && !$attEnc && (str_starts_with($attMime, 'audio/') || in_array($attExt, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus'], true) || ($attExt === 'webm' && str_starts_with($attName, 'Sprachnachricht_')));

        $formatted[] = [
            'id'                => (int)$m['id'],
            'sender_id'         => (int)$m['sender_id'],
            'sender_username'   => (string)$m['sender_username'],
            'sender_is_admin'   => ((int)$m['sender_is_admin'] === 1),
            'is_mine'           => ((int)$m['sender_id'] === $userId),
            'is_broadcast'      => ((int)$m['is_broadcast'] === 1),
            'message_text'      => (string)$m['message_text'],
            'attached_file_id'  => $attId,
            'attached_name'     => $attName,
            'attached_size'     => $attId > 0 ? formatBytes((int)($m['attached_size'] ?? 0)) : '',
            'attached_is_image' => $attIsImg,
            'attached_is_audio' => $attIsAud,
            'is_read'           => ((int)$m['is_read'] === 1),
            'time_str'          => date('H:i', $ts),
            'date_str'          => date('d.m.Y', $ts),
            'can_delete'        => ((int)$m['sender_id'] === $userId || ($isAdmin && (int)$m['is_broadcast'] === 1)),
        ];
    }

    echo json_encode([
        'success'      => true,
        'unread_total' => getUnreadMessagesCount($pdo, $userId),
        'messages'     => $formatted,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 2. POST-Aktionen (Nachricht senden / Nachricht löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: messages.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // A) Nachricht senden (Direktnachricht oder Admin-Rundnachricht, optional mit Dateianhang)
    if ($action === 'send_message') {
        $targetChannel = trim((string)($_POST['target_channel'] ?? ''));
        $messageText   = mb_substr(trim((string)($_POST['message_text'] ?? '')), 0, 5000);
        $attachedFileId = null;
        $attachedFileName = '';

        // Option 1: Bestehende eigene Cloud-Datei ausgewählt
        $pickedCloudFileId = (int)($_POST['attached_file_id'] ?? 0);
        if ($pickedCloudFileId > 0) {
            $stmtCf = $pdo->prepare('SELECT id, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmtCf->execute([':id' => $pickedCloudFileId, ':uid' => $userId]);
            $cfRow = $stmtCf->fetch();
            if ($cfRow) {
                $attachedFileId   = (int)$cfRow['id'];
                $attachedFileName = (string)$cfRow['original_name'];
            }
        }

        // Option 2: Neue Datei / Foto direkt im Chat hochgeladen
        if (isset($_FILES['chat_attachment']) && is_array($_FILES['chat_attachment']) && (int)($_FILES['chat_attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath  = (string)$_FILES['chat_attachment']['tmp_name'];
            $origName = sanitizeOriginalFilename((string)$_FILES['chat_attachment']['name']);
            $fileSize = (int)$_FILES['chat_attachment']['size'];

            if (is_uploaded_file($tmpPath) && $fileSize > 0) {
                $stats = getStorageStats($pdo, $userId);
                if ($fileSize > $stats['free']) {
                    setFlash('error', 'Dateianhang zu groß für Ihr freies Speicherkontingent (' . formatBytes($stats['free']) . ' frei).');
                    header('Location: messages.php' . ($targetChannel !== '' ? '?channel=' . urlencode($targetChannel) : ''));
                    exit;
                }

                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($tmpPath) ?: 'application/octet-stream';
                if (str_starts_with($origName, 'Sprachnachricht_') && ($detectedMime === 'video/webm' || $detectedMime === 'application/octet-stream')) {
                    $detectedMime = 'audio/webm';
                }
                do {
                    $storedName = generateSafeStoredFilename();
                    $destPath = UPLOAD_DIR . $storedName;
                } while (file_exists($destPath));

                if (move_uploaded_file($tmpPath, $destPath)) {
                    @chmod($destPath, 0640);
                    $chatFolder = 'Chat-Anhänge';
                    ensureFolderHierarchyExist($pdo, $userId, $chatFolder, null);

                    $stmtInsFile = $pdo->prepare(
                        'INSERT INTO files (user_id, group_folder_id, filename, original_name, folder_path, file_size, mime_type)
                         VALUES (:uid, NULL, :stored, :orig, :fp, :size, :mime)'
                    );
                    $stmtInsFile->execute([
                        ':uid'    => $userId,
                        ':stored' => $storedName,
                        ':orig'   => $origName,
                        ':fp'     => $chatFolder,
                        ':size'   => $fileSize,
                        ':mime'   => $detectedMime,
                    ]);
                    $attachedFileId   = (int)$pdo->lastInsertId();
                    $attachedFileName = $origName;
                }
            }
        }

        if ($messageText === '' && $attachedFileId !== null) {
            $messageText = str_starts_with($attachedFileName, 'Sprachnachricht_')
                ? '🎙️ Sprachnachricht'
                : ('📎 ' . $attachedFileName);
        }

        if ($messageText === '' && $attachedFileId === null) {
            setFlash('error', 'Bitte geben Sie einen Nachrichtentext ein oder wählen Sie einen Dateianhang aus.');
            header('Location: messages.php' . ($targetChannel !== '' ? '?channel=' . urlencode($targetChannel) : ''));
            exit;
        }

        if ($targetChannel === 'broadcast') {
            if (!$isAdmin) {
                setFlash('error', 'Nur Administratoren können Rundnachrichten an alle Benutzer senden.');
                header('Location: messages.php?channel=broadcast');
                exit;
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO messages (sender_id, recipient_id, is_broadcast, message_text, attached_file_id, is_read)
                 VALUES (:sid, NULL, 1, :txt, :afid, 0)'
            );
            $stmtIns->execute([
                ':sid'  => $userId,
                ':txt'  => $messageText,
                ':afid' => $attachedFileId,
            ]);
            markBroadcastsAsRead($pdo, $userId);
            header('Location: messages.php?channel=broadcast');
            exit;
        }

        $recipientId = (int)$targetChannel;
        $partner = getAllowedChatPartner($pdo, $userId, $recipientId, $isAdmin);
        if (!$partner) {
            setFlash('error', 'Dieser Benutzer hat direkte Nachrichten / Freigaben in seinen Datenschutzeinstellungen nicht aktiviert.');
            header('Location: messages.php');
            exit;
        }

        $stmtIns = $pdo->prepare(
            'INSERT INTO messages (sender_id, recipient_id, is_broadcast, message_text, attached_file_id, is_read)
             VALUES (:sid, :rid, 0, :txt, :afid, 0)'
        );
        $stmtIns->execute([
            ':sid'  => $userId,
            ':rid'  => $recipientId,
            ':txt'  => $messageText,
            ':afid' => $attachedFileId,
        ]);

        header('Location: messages.php?channel=' . $recipientId);
        exit;
    }

    // B) Einzelne Nachricht löschen
    if ($action === 'delete_message') {
        $msgId         = (int)($_POST['message_id'] ?? 0);
        $returnChannel = trim((string)($_POST['return_channel'] ?? ''));

        if ($msgId > 0) {
            $stmtMsg = $pdo->prepare('SELECT id, sender_id, is_broadcast FROM messages WHERE id = :id LIMIT 1');
            $stmtMsg->execute([':id' => $msgId]);
            $msgRow = $stmtMsg->fetch();

            if ($msgRow && ((int)$msgRow['sender_id'] === $userId || ($isAdmin && (int)$msgRow['is_broadcast'] === 1))) {
                $pdo->prepare('DELETE FROM messages WHERE id = :id')->execute([':id' => $msgId]);
            }
        }

        header('Location: messages.php' . ($returnChannel !== '' ? '?channel=' . urlencode($returnChannel) : ''));
        exit;
    }

    // C) Gesamten Chatverlauf leeren
    if ($action === 'clear_history') {
        $targetChannel = trim((string)($_POST['target_channel'] ?? ''));

        if ($targetChannel === 'broadcast') {
            if ($isAdmin) {
                $pdo->exec('DELETE FROM messages WHERE is_broadcast = 1');
                setFlash('success', 'Der Ankündigungs-Verlauf wurde vollständig geleert.');
            } else {
                setFlash('error', 'Nur Administratoren können den Ankündigungs-Verlauf leeren.');
            }
            header('Location: messages.php?channel=broadcast');
            exit;
        }

        if (ctype_digit($targetChannel) && (int)$targetChannel > 0) {
            $partnerId = (int)$targetChannel;
            $stmtClr = $pdo->prepare(
                'DELETE FROM messages
                 WHERE is_broadcast = 0
                   AND ((sender_id = :u1 AND recipient_id = :p1) OR (sender_id = :p2 AND recipient_id = :u2))'
            );
            $stmtClr->execute([
                ':u1' => $userId,
                ':p1' => $partnerId,
                ':p2' => $partnerId,
                ':u2' => $userId,
            ]);
            setFlash('success', 'Der Chatverlauf wurde vollständig geleert.');
            header('Location: messages.php?channel=' . $partnerId);
            exit;
        }

        header('Location: messages.php');
        exit;
    }
}

// --- 3. Konversations-Liste & Kontakte laden ---

// 3a. Letzte Rundnachricht & Ungelesen-Zähler für Broadcasts
$stmtLastBroadcast = $pdo->query(
    'SELECT m.id, m.message_text, m.created_at, u.username AS sender_username
     FROM messages m
     INNER JOIN users u ON u.id = m.sender_id
     WHERE m.is_broadcast = 1
     ORDER BY m.id DESC
     LIMIT 1'
);
$lastBroadcast = $stmtLastBroadcast->fetch() ?: null;

$stmtUnreadBroad = $pdo->prepare(
    'SELECT COUNT(*) FROM messages
     WHERE is_broadcast = 1
       AND sender_id <> :uid1
       AND id > COALESCE((SELECT last_read_broadcast_id FROM message_broadcast_reads WHERE user_id = :uid2), 0)'
);
$stmtUnreadBroad->execute([':uid1' => $userId, ':uid2' => $userId]);
$unreadBroadcastCount = (int)$stmtUnreadBroad->fetchColumn();

// 3b. Alle bestehenden 1:1-Konversationen des Benutzers ermitteln
$stmtConvs = $pdo->prepare(
    'SELECT
        CASE WHEN m.sender_id = :uid1 THEN m.recipient_id ELSE m.sender_id END AS partner_id,
        MAX(m.id) AS last_msg_id,
        SUM(CASE WHEN m.recipient_id = :uid2 AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
     FROM messages m
     WHERE m.is_broadcast = 0
       AND (m.sender_id = :uid3 OR m.recipient_id = :uid4)
       AND m.recipient_id IS NOT NULL
     GROUP BY partner_id
     ORDER BY last_msg_id DESC'
);
$stmtConvs->execute([
    ':uid1' => $userId,
    ':uid2' => $userId,
    ':uid3' => $userId,
    ':uid4' => $userId,
]);
$convRows = $stmtConvs->fetchAll();

$conversations = [];
$existingPartnerIds = [];
foreach ($convRows as $cRow) {
    $pid = (int)$cRow['partner_id'];
    if ($pid <= 0) {
        continue;
    }
    $stmtPartner = $pdo->prepare('SELECT id, username, is_admin, allow_user_sharing FROM users WHERE id = :id LIMIT 1');
    $stmtPartner->execute([':id' => $pid]);
    $pUser = $stmtPartner->fetch();
    if (!$pUser) {
        continue;
    }

    $stmtLastMsg = $pdo->prepare('SELECT message_text, created_at, sender_id FROM messages WHERE id = :id LIMIT 1');
    $stmtLastMsg->execute([':id' => (int)$cRow['last_msg_id']]);
    $lMsg = $stmtLastMsg->fetch() ?: ['message_text' => '', 'created_at' => date('Y-m-d H:i:s'), 'sender_id' => 0];

    $conversations[$pid] = [
        'partner_id'         => $pid,
        'username'           => (string)$pUser['username'],
        'is_admin'           => ((int)$pUser['is_admin'] === 1),
        'allow_user_sharing' => ((int)($pUser['allow_user_sharing'] ?? 0) === 1),
        'last_text'          => (string)$lMsg['message_text'],
        'last_time'          => (string)$lMsg['created_at'],
        'last_is_mine'       => ((int)$lMsg['sender_id'] === $userId),
        'unread_count'       => (int)$cRow['unread_count'],
    ];
    $existingPartnerIds[] = $pid;
}

// 3c. Verfügbare Kontakte für "+ Neuer Chat" laden (respektiert allow_user_sharing = 1 für normale Nutzer)
if ($isAdmin) {
    $stmtContacts = $pdo->prepare(
        'SELECT id, username, is_admin, allow_user_sharing
         FROM users
         WHERE id <> :uid
         ORDER BY username ASC'
    );
    $stmtContacts->execute([':uid' => $userId]);
} else {
    $stmtContacts = $pdo->prepare(
        'SELECT id, username, is_admin, allow_user_sharing
         FROM users
         WHERE id <> :uid AND (allow_user_sharing = 1 OR is_admin = 1)
         ORDER BY is_admin DESC, username ASC'
    );
    $stmtContacts->execute([':uid' => $userId]);
}
$availableContacts = $stmtContacts->fetchAll();

// 3d. Aktiven Kanal bestimmen ('broadcast' oder Benutzer-ID)
// Auf Mobilgeräten (< lg) zeigen wir zuerst die Kontaktliste, sofern kein Kanal explizit per ?channel=... gewählt wurde
$rawChannel = trim((string)($_GET['channel'] ?? ''));
$hasExplicitChannel = ($rawChannel !== '');
$mobileShowList = !$hasExplicitChannel || (isset($_GET['view']) && $_GET['view'] === 'list');

if ($rawChannel === '') {
    if ($unreadBroadcastCount > 0) {
        $rawChannel = 'broadcast';
    } elseif (!empty($conversations)) {
        $firstConv = reset($conversations);
        $rawChannel = (string)$firstConv['partner_id'];
    } elseif ($lastBroadcast !== null || $isAdmin) {
        $rawChannel = 'broadcast';
    }
}

$activeChannelType = 'none'; // 'broadcast', 'user', 'none'
$activePartner     = null;
$chatMessages      = [];

if ($rawChannel === 'broadcast') {
    $activeChannelType = 'broadcast';
    markBroadcastsAsRead($pdo, $userId);
    $unreadBroadcastCount = 0;

    $stmtMsgs = $pdo->query(
        'SELECT m.*, u.username AS sender_username, u.is_admin AS sender_is_admin,
                af.original_name AS attached_name, af.file_size AS attached_size, af.mime_type AS attached_mime, af.is_encrypted AS attached_encrypted
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         LEFT JOIN files af ON af.id = m.attached_file_id AND af.deleted_at IS NULL
         WHERE m.is_broadcast = 1
         ORDER BY m.id ASC
         LIMIT 300'
    );
    $chatMessages = $stmtMsgs->fetchAll();
} elseif (ctype_digit($rawChannel) && (int)$rawChannel > 0) {
    $partnerId = (int)$rawChannel;
    $activePartner = getAllowedChatPartner($pdo, $userId, $partnerId, $isAdmin);
    if ($activePartner) {
        $activeChannelType = 'user';

        // Falls Partner noch nicht in der linken Konversationsliste steht, temporär oben einfügen
        if (!isset($conversations[$partnerId])) {
            $conversations = [
                $partnerId => [
                    'partner_id'         => $partnerId,
                    'username'           => (string)$activePartner['username'],
                    'is_admin'           => ((int)$activePartner['is_admin'] === 1),
                    'allow_user_sharing' => ((int)($activePartner['allow_user_sharing'] ?? 0) === 1),
                    'last_text'          => 'Neuer Chat',
                    'last_time'          => date('Y-m-d H:i:s'),
                    'last_is_mine'       => false,
                    'unread_count'       => 0,
                ],
            ] + $conversations;
        } else {
            $conversations[$partnerId]['unread_count'] = 0;
        }

        // Empfangene Nachrichten als gelesen markieren
        $pdo->prepare(
            'UPDATE messages SET is_read = 1
             WHERE is_broadcast = 0 AND sender_id = :pid AND recipient_id = :uid AND is_read = 0'
        )->execute([':pid' => $partnerId, ':uid' => $userId]);

        $stmtMsgs = $pdo->prepare(
            'SELECT m.*, u.username AS sender_username, u.is_admin AS sender_is_admin,
                    af.original_name AS attached_name, af.file_size AS attached_size, af.mime_type AS attached_mime, af.is_encrypted AS attached_encrypted
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN files af ON af.id = m.attached_file_id AND af.deleted_at IS NULL
             WHERE m.is_broadcast = 0
               AND ((m.sender_id = :u1 AND m.recipient_id = :p1) OR (m.sender_id = :p2 AND m.recipient_id = :u2))
             ORDER BY m.id ASC
             LIMIT 300'
        );
        $stmtMsgs->execute([
            ':u1' => $userId,
            ':p1' => $partnerId,
            ':p2' => $partnerId,
            ':u2' => $userId,
        ]);
        $chatMessages = $stmtMsgs->fetchAll();
    } else {
        setFlash('error', 'Dieser Benutzer hat direkte Nachrichten / Freigaben nicht aktiviert.');
        header('Location: messages.php');
        exit;
    }
}

// Eigene Sichtbarkeits-Einstellung abrufen
$stmtSelfVis = $pdo->prepare('SELECT allow_user_sharing FROM users WHERE id = :id LIMIT 1');
$stmtSelfVis->execute([':id' => $userId]);
$mySharingAllowed = ((int)$stmtSelfVis->fetchColumn() === 1);

// Eigene Cloud-Dateien für schnelle Anhang-Auswahl im Chat laden
$stmtMyFiles = $pdo->prepare(
    'SELECT id, original_name, folder_path, file_size
     FROM files
     WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND is_encrypted = 0
     ORDER BY upload_date DESC
     LIMIT 150'
);
$stmtMyFiles->execute([':uid' => $userId]);
$myCloudFilesForChat = $stmtMyFiles->fetchAll();

$lastMessageId = 0;
foreach ($chatMessages as $cm) {
    if ((int)$cm['id'] > $lastMessageId) {
        $lastMessageId = (int)$cm['id'];
    }
}

$pageTitle = 'Chat & Nachrichten';
$hideFooterOnMobile = true;
require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfbereich (auf Mobilgeräten bei geöffnetem Chat ausgeblendet für maximale Schreibfläche) -->
<div id="chatPageTopHeader" class="<?= !$mobileShowList ? 'hidden lg:flex' : 'flex' ?> flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 sm:mb-5">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="w-9 h-9 rounded-xl bg-emerald-600/10 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                💬
            </span>
            <span>Chat &amp; Nachrichten</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            Direktnachrichten mit freigegebenen Cloud-Benutzern und offizielle Admin-Ankündigungen.
        </p>
    </div>

    <?php if ($isAdmin): ?>
        <a
            href="messages.php?channel=broadcast"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors self-start sm:self-auto"
        >
            <span>📢</span>
            <span>Rundnachricht an alle senden</span>
        </a>
    <?php endif; ?>
</div>

<!-- Messenger Hauptcontainer (Mobil: 1-Spalten-Navigation Kontakte <-> Chat | Desktop: 2-Spalten-Ansicht) -->
<div id="messengerMainCard" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[420px] lg:h-[680px]">

    <!-- LINKE SPALTE: Konversationen & Neuer Chat (4 Spalten) -->
    <div id="chatSidebarPane" class="lg:col-span-4 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-800 <?= $mobileShowList ? 'flex' : 'hidden lg:flex' ?> flex-col h-[560px] lg:h-full bg-slate-50/50 dark:bg-slate-900/60">
        <!-- "+ Neuer Chat" Auswahl -->
        <div class="p-3.5 border-b border-slate-200 dark:border-slate-800 space-y-2">
            <label for="newChatUserSelect" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                ➕ Neuen Chat starten
            </label>
            <?php if (empty($availableContacts)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 italic">
                    Derzeit hat kein anderer Benutzer seine Sichtbarkeit in den Einstellungen freigegeben.
                </p>
            <?php else: ?>
                <div class="flex items-center gap-2">
                    <select
                        id="newChatUserSelect"
                        onchange="if (this.value) window.location.href = 'messages.php?channel=' + encodeURIComponent(this.value);"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                    >
                        <option value="">👤 Kontakt auswählen...</option>
                        <?php foreach ($availableContacts as $ct): ?>
                            <?php
                            $ctId = (int)$ct['id'];
                            $ctIsAdmin = ((int)$ct['is_admin'] === 1);
                            $ctVis = ((int)($ct['allow_user_sharing'] ?? 0) === 1);
                            $suffix = $ctIsAdmin ? ' (Admin)' : (!$ctVis && $isAdmin ? ' (🔒 Verborgen)' : '');
                            ?>
                            <option value="<?= $ctId ?>" <?= ($activeChannelType === 'user' && (int)($activePartner['id'] ?? 0) === $ctId) ? 'selected' : '' ?>>
                                <?= e((string)$ct['username'] . $suffix) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <!-- Konversations-Liste -->
        <div class="flex-1 overflow-y-auto divide-y divide-slate-200/70 dark:divide-slate-800/80">
            <!-- 1. Kanal: Rundnachrichten / Ankündigungen an alle Benutzer -->
            <?php $isBroadcastSelected = ($activeChannelType === 'broadcast'); ?>
            <a
                href="messages.php?channel=broadcast"
                class="flex items-center gap-3 p-3.5 transition-colors <?= $isBroadcastSelected ? 'bg-brand-50/90 dark:bg-brand-950/40 border-l-4 border-brand-600' : 'hover:bg-slate-100/80 dark:hover:bg-slate-800/50' ?>"
            >
                <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400 flex items-center justify-center text-base font-bold shrink-0">
                    📢
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">
                            Ankündigungen (Alle)
                        </span>
                        <?php if ($unreadBroadcastCount > 0): ?>
                            <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-red-600 text-white shrink-0">
                                <?= $unreadBroadcastCount ?>
                            </span>
                        <?php elseif ($lastBroadcast !== null): ?>
                            <span class="text-[10px] text-slate-400 shrink-0">
                                <?= e(date('d.m.', strtotime((string)$lastBroadcast['created_at']))) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                        <?php if ($lastBroadcast !== null): ?>
                            <strong><?= e((string)$lastBroadcast['sender_username']) ?>:</strong> <?= e((string)$lastBroadcast['message_text']) ?>
                        <?php else: ?>
                            Offizielle Rundnachrichten des Administrators
                        <?php endif; ?>
                    </p>
                </div>
            </a>

            <!-- 2. Direkte 1:1 Konversationen -->
            <?php if (empty($conversations)): ?>
                <div class="p-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    Noch keine direkten Konversationen vorhanden. Wählen Sie oben einen Kontakt aus, um einen Chat zu starten.
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                    $cPid = (int)$conv['partner_id'];
                    $isSelectedConv = ($activeChannelType === 'user' && (int)($activePartner['id'] ?? 0) === $cPid);
                    $initial = mb_strtoupper(mb_substr((string)$conv['username'], 0, 1, 'UTF-8'), 'UTF-8');
                    $convTimeTs = strtotime((string)$conv['last_time']) ?: time();
                    $convTimeFormatted = date('Y-m-d', $convTimeTs) === date('Y-m-d')
                        ? date('H:i', $convTimeTs)
                        : date('d.m.', $convTimeTs);
                    ?>
                    <a
                        href="messages.php?channel=<?= $cPid ?>"
                        data-chat-conv-item="1"
                        data-chat-conv-search="<?= e(mb_strtolower((string)$conv['username'] . ' ' . (string)$conv['last_text'], 'UTF-8')) ?>"
                        class="flex items-center gap-3 p-3.5 transition-colors <?= $isSelectedConv ? 'bg-brand-50/90 dark:bg-brand-950/40 border-l-4 border-brand-600' : 'hover:bg-slate-100/80 dark:hover:bg-slate-800/50' ?>"
                    >
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-sm font-bold shrink-0 shadow-2xs">
                            <?= e($initial) ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">
                                        <?= e((string)$conv['username']) ?>
                                    </span>
                                    <?php if ($conv['is_admin']): ?>
                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-bold uppercase bg-brand-600 text-white shrink-0">Admin</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <?php if ((int)$conv['unread_count'] > 0): ?>
                                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-red-600 text-white">
                                            <?= (int)$conv['unread_count'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-400">
                                            <?= e($convTimeFormatted) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                <?= $conv['last_is_mine'] ? '<span class="text-slate-400">Ich:</span> ' : '' ?><?= e((string)$conv['last_text']) ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Fußzeile der Seitenleiste: Eigener Datenschutz-Status -->
        <div class="p-3 border-t border-slate-200 dark:border-slate-800 bg-white/60 dark:bg-slate-900/80 flex items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
            <span>Eigene Chat-/Teilen-Sichtbarkeit:</span>
            <a href="settings.php" class="font-semibold hover:underline <?= $mySharingAllowed ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300' ?>">
                <?= $mySharingAllowed ? '👁️ Sichtbar' : '🔒 Verborgen' ?>
            </a>
        </div>
    </div>

    <!-- RECHTE SPALTE: Aktiver Chat-Verlauf & Eingabeleiste (8 Spalten) -->
    <div id="chatConversationPane" class="lg:col-span-8 <?= $mobileShowList ? 'hidden lg:flex' : 'flex' ?> flex-col h-[calc(100dvh-6.2rem)] lg:h-full bg-white dark:bg-slate-900">
        <?php if ($activeChannelType === 'none'): ?>
            <div class="flex-1 flex flex-col items-center justify-center p-8 text-center">
                <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-2xl mb-3">
                    💬
                </div>
                <h2 class="text-base font-bold text-slate-800 dark:text-slate-200">Keine Konversation ausgewählt</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm mt-1">
                    Wählen Sie links eine Konversation oder starten Sie über „➕ Neuen Chat starten“ eine Unterhaltung mit einem anderen Cloud-Benutzer.
                </p>
            </div>
        <?php else: ?>
            <!-- Chat-Kopfzeile -->
            <div class="px-3 sm:px-4 py-2.5 sm:py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2 bg-slate-50/60 dark:bg-slate-800/40 shrink-0">
                <div class="flex items-center gap-2.5 min-w-0">
                    <!-- Mobil-Zurück-Button zur Kontaktliste -->
                    <a
                        href="messages.php?view=list"
                        class="lg:hidden inline-flex items-center justify-center w-8 h-8 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:text-brand-600 shrink-0 shadow-2xs"
                        title="Zurück zur Kontaktliste"
                        aria-label="Zurück zur Kontaktliste"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>

                    <?php if ($activeChannelType === 'broadcast'): ?>
                        <div class="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-600 dark:text-amber-400 flex items-center justify-center text-base font-bold shrink-0">
                            📢
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                                Ankündigungen an alle Benutzer
                            </h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                Offizielle Rundnachrichten der Administration an alle Cloud-Mitglieder
                            </p>
                        </div>
                    <?php else: ?>
                        <?php
                        $pName = (string)$activePartner['username'];
                        $pInit = mb_strtoupper(mb_substr($pName, 0, 1, 'UTF-8'), 'UTF-8');
                        $pIsAdm = ((int)$activePartner['is_admin'] === 1);
                        $pVis = ((int)($activePartner['allow_user_sharing'] ?? 0) === 1);
                        ?>
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-600 text-white flex items-center justify-center text-sm font-bold shrink-0">
                            <?= e($pInit) ?>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                                    <?= e($pName) ?>
                                </h2>
                                <?php if ($pIsAdm): ?>
                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-bold uppercase bg-brand-600 text-white">Admin</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                <?= $pVis ? '👁️ Direktfreigaben &amp; Chat aktiv' : '🔒 In Teilen-Auswahl verborgen' ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Rechte Aktionsbuttons im Chat-Header (System-Benachrichtigungen freigeben & Verlauf leeren) -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <button
                        type="button"
                        id="btnQuickEnablePush"
                        class="hidden items-center gap-1 px-2.5 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 text-xs font-semibold transition-colors cursor-pointer"
                        title="System-Benachrichtigungen für neue Nachrichten auf diesem Gerät aktivieren"
                    >
                        <span>🔔</span>
                        <span class="hidden sm:inline">Meldungen aktivieren</span>
                    </button>

                    <?php if (!empty($chatMessages) && ($activeChannelType === 'user' || ($activeChannelType === 'broadcast' && $isAdmin))): ?>
                        <form
                            action="messages.php"
                            method="post"
                            class="inline"
                            onsubmit="return confirm('Möchten Sie den gesamten Nachrichtenverlauf in diesem Chat wirklich unwiderruflich leeren?');"
                        >
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="clear_history">
                            <input type="hidden" name="target_channel" value="<?= e($rawChannel) ?>">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                                title="Gesamten Chatverlauf leeren"
                            >
                                <span>🗑️</span>
                                <span class="hidden sm:inline">Verlauf leeren</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Scrollbarer Nachrichtenverlauf (Sprechblasen) -->
            <div id="chatMessagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50/30 dark:bg-slate-950/30">
                <?php if (empty($chatMessages)): ?>
                    <div id="emptyChatHint" class="h-full flex flex-col items-center justify-center text-center py-12 text-slate-400 dark:text-slate-500 text-xs">
                        <p>Noch keine Nachrichten in diesem Verlauf.</p>
                        <p class="mt-1">Schreiben Sie unten die erste Nachricht!</p>
                    </div>
                <?php else: ?>
                    <?php
                    $lastRenderedDate = '';
                    foreach ($chatMessages as $msg):
                        $mId          = (int)$msg['id'];
                        $isMine       = ((int)$msg['sender_id'] === $userId);
                        $isBroad      = ((int)$msg['is_broadcast'] === 1);
                        $senderName   = (string)$msg['sender_username'];
                        $senderAdmin  = ((int)$msg['sender_is_admin'] === 1);
                        $ts           = strtotime((string)$msg['created_at']) ?: time();
                        $msgDateStr   = date('d.m.Y', $ts);
                        $msgTimeStr   = date('H:i', $ts);
                        $canDeleteMsg = ($isMine || ($isAdmin && $isBroad));
                        ?>
                        <?php if ($msgDateStr !== $lastRenderedDate): ?>
                            <?php $lastRenderedDate = $msgDateStr; ?>
                            <div class="flex justify-center my-2">
                                <span class="px-3 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200/80 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    <?= $msgDateStr === date('d.m.Y') ? 'Heute' : ($msgDateStr === date('d.m.Y', time() - 86400) ? 'Gestern' : e($msgDateStr)) ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="flex <?= $isMine ? 'justify-end' : 'justify-start' ?>" data-msg-id="<?= $mId ?>">
                            <div class="max-w-[85%] sm:max-w-[72%] rounded-2xl px-3.5 py-2.5 shadow-2xs <?= $isMine ? 'bg-brand-600 text-white rounded-br-xs' : ($isBroad ? 'bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/80 text-slate-900 dark:text-slate-100 rounded-bl-xs' : 'bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 text-slate-900 dark:text-slate-100 rounded-bl-xs') ?>">
                                <?php if (!$isMine || $isBroad): ?>
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <span class="text-[11px] font-bold <?= $isMine ? 'text-white/90' : 'text-brand-600 dark:text-brand-400' ?>">
                                            <?= e($senderName) ?>
                                        </span>
                                        <?php if ($senderAdmin): ?>
                                            <span class="px-1.5 py-0.1 rounded text-[9px] font-bold uppercase <?= $isMine ? 'bg-white/20 text-white' : 'bg-brand-600 text-white' ?>">Admin</span>
                                        <?php endif; ?>
                                        <?php if ($isBroad): ?>
                                            <span class="text-[10px] font-semibold <?= $isMine ? 'text-white/80' : 'text-amber-700 dark:text-amber-300' ?>">📢 Rundnachricht</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="text-xs sm:text-sm whitespace-pre-line break-words leading-relaxed"><?= e((string)$msg['message_text']) ?></div>

                                <?php
                                $attId = !empty($msg['attached_file_id']) && !empty($msg['attached_name']) ? (int)$msg['attached_file_id'] : 0;
                                if ($attId > 0):
                                    $attName = (string)$msg['attached_name'];
                                    $attSize = formatBytes((int)($msg['attached_size'] ?? 0));
                                    $attMime = strtolower(trim((string)($msg['attached_mime'] ?? '')));
                                    $attEnc  = ((int)($msg['attached_encrypted'] ?? 0) === 1);
                                    $attExt  = strtolower(pathinfo($attName, PATHINFO_EXTENSION));
                                    $attIsImg = !$attEnc && (str_starts_with($attMime, 'image/') || in_array($attExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true));
                                    $attIsAud = !$attEnc && (str_starts_with($attMime, 'audio/') || in_array($attExt, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus'], true) || ($attExt === 'webm' && str_starts_with($attName, 'Sprachnachricht_')));
                                ?>
                                    <div class="mt-2 space-y-1.5">
                                        <?php if ($attIsImg): ?>
                                            <a href="download.php?id=<?= $attId ?>" class="block rounded-xl overflow-hidden border <?= $isMine ? 'border-white/25' : 'border-slate-200 dark:border-slate-700' ?> max-w-xs">
                                                <img
                                                    src="download.php?id=<?= $attId ?>&amp;inline=1"
                                                    alt="<?= e($attName) ?>"
                                                    loading="lazy"
                                                    class="max-h-56 w-auto object-cover"
                                                >
                                            </a>
                                        <?php elseif ($attIsAud): ?>
                                            <div class="p-1.5 rounded-xl <?= $isMine ? 'bg-white/15' : 'bg-slate-100 dark:bg-slate-700/70' ?>">
                                                <audio
                                                    controls
                                                    preload="metadata"
                                                    src="download.php?id=<?= $attId ?>&amp;inline=1"
                                                    class="w-full min-w-[210px] max-w-[270px] h-9"
                                                ></audio>
                                            </div>
                                        <?php endif; ?>
                                        <a
                                            href="download.php?id=<?= $attId ?>"
                                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors <?= $isMine ? 'bg-white/15 hover:bg-white/25 text-white' : 'bg-slate-100 dark:bg-slate-700/80 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100' ?>"
                                            title="Dateianhang herunterladen"
                                        >
                                            <span><?= $attIsAud ? '🎙️' : '📎' ?></span>
                                            <span class="truncate max-w-[190px] sm:max-w-[260px]"><?= e($attName) ?></span>
                                            <span class="opacity-75 text-[10px]">(<?= e($attSize) ?>)</span>
                                            <span>⬇️</span>
                                        </a>
                                    </div>
                                <?php endif; ?>

                                <div class="flex items-center justify-end gap-2 mt-1 text-[10px] <?= $isMine ? 'text-white/75' : 'text-slate-400 dark:text-slate-500' ?>">
                                    <span><?= e($msgTimeStr) ?> Uhr</span>
                                    <?php if ($isMine && !$isBroad): ?>
                                        <span title="<?= (int)$msg['is_read'] === 1 ? 'Gelesen' : 'Gesendet' ?>">
                                            <?= (int)$msg['is_read'] === 1 ? '✓✓' : '✓' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($canDeleteMsg): ?>
                                        <form action="messages.php" method="post" class="inline" onsubmit="return confirm('Diese Nachricht wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_message">
                                            <input type="hidden" name="message_id" value="<?= $mId ?>">
                                            <input type="hidden" name="return_channel" value="<?= e($rawChannel) ?>">
                                            <button type="submit" class="hover:underline opacity-80 hover:opacity-100 cursor-pointer" title="Nachricht löschen">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Eingabeleiste unten -->
            <?php if ($activeChannelType === 'broadcast' && !$isAdmin): ?>
                <div class="p-3.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 text-center text-xs text-slate-500 dark:text-slate-400">
                    ℹ️ Im Ankündigungs-Kanal können nur Administratoren Rundnachrichten an alle Benutzer veröffentlichen.
                </div>
            <?php else: ?>
                <form action="messages.php" method="post" enctype="multipart/form-data" id="chatSendForm" class="p-3 sm:p-3.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 shrink-0 space-y-2">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="target_channel" value="<?= e($rawChannel) ?>">

                    <!-- Aufklappbare Dateianhang-Leiste (Geräte-Upload oder Cloud-Datei auswählen) -->
                    <div id="chatAttachPanel" class="hidden p-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 space-y-2 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-slate-700 dark:text-slate-200">📎 Datei oder Bild anhängen</span>
                            <button type="button" onclick="clearChatAttachment()" class="text-slate-400 hover:text-red-500 text-xs font-semibold cursor-pointer">✕ Zurücksetzen</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] text-slate-500 dark:text-slate-400 mb-1">Vom Gerät hochladen:</label>
                                <input
                                    type="file"
                                    name="chat_attachment"
                                    id="chatAttachmentFileInput"
                                    onchange="onChatFileUploadChosen(this)"
                                    class="w-full text-xs text-slate-700 dark:text-slate-200 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-50 dark:file:bg-brand-950/60 file:text-brand-700 dark:file:text-brand-300 cursor-pointer"
                                >
                            </div>
                            <?php if (!empty($myCloudFilesForChat)): ?>
                                <div>
                                    <label class="block text-[11px] text-slate-500 dark:text-slate-400 mb-1">Oder aus meinen Cloud-Dateien:</label>
                                    <select
                                        name="attached_file_id"
                                        id="chatAttachedCloudSelect"
                                        onchange="onChatCloudFileChosen(this)"
                                        class="w-full px-2.5 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs text-slate-800 dark:text-slate-100"
                                    >
                                        <option value="">— Cloud-Datei wählen —</option>
                                        <?php foreach ($myCloudFilesForChat as $mcf): ?>
                                            <?php
                                            $mcfFolder = (string)$mcf['folder_path'];
                                            $mcfLabel = ($mcfFolder !== '' ? $mcfFolder . '/' : '') . (string)$mcf['original_name'] . ' (' . formatBytes((int)$mcf['file_size']) . ')';
                                            ?>
                                            <option value="<?= (int)$mcf['id'] ?>"><?= e($mcfLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Anzeige für gewählten Anhang / eingefügten Screenshot (Strg+V) / aufgenommene Sprachnachricht -->
                    <div id="chatSelectedAttachmentBadge" class="hidden items-center justify-between gap-2 px-3 py-1.5 rounded-xl bg-brand-50 dark:bg-brand-950/60 border border-brand-200 dark:border-brand-800/80 text-xs text-brand-800 dark:text-brand-200">
                        <div class="flex items-center gap-2 min-w-0">
                            <span id="chatSelectedAttachmentIcon">📎</span>
                            <span id="chatSelectedAttachmentName" class="font-semibold truncate"></span>
                        </div>
                        <button type="button" onclick="clearChatAttachment()" class="text-brand-600 dark:text-brand-300 hover:text-red-500 font-bold cursor-pointer shrink-0" title="Anhang entfernen">✕</button>
                    </div>

                    <!-- Sprachnachrichten-Aufnahmeleiste -->
                    <div id="voiceRecordBar" class="hidden items-center justify-between gap-2 px-3 py-2 rounded-xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800/80 text-xs text-red-800 dark:text-red-200">
                        <div class="flex items-center gap-2">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-600 animate-pulse"></span>
                            <span class="font-bold">🎙️ Aufnahme läuft:</span>
                            <span id="voiceRecordTimer" class="font-mono font-bold">00:00</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                onclick="stopVoiceRecording(true)"
                                class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-semibold transition-colors cursor-pointer"
                                title="Aufnahme beenden und direkt absenden"
                            >
                                ⏹️ Senden
                            </button>
                            <button
                                type="button"
                                onclick="stopVoiceRecording(false)"
                                class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-semibold transition-colors cursor-pointer"
                                title="Aufnahme als Anhang anfügen"
                            >
                                📎 Anfügen
                            </button>
                            <button
                                type="button"
                                onclick="cancelVoiceRecording()"
                                class="px-2 py-1 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/50 text-red-700 dark:text-red-300 font-semibold transition-colors cursor-pointer"
                                title="Aufnahme verwerfen"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            id="btnToggleChatAttach"
                            onclick="toggleChatAttachPanel()"
                            title="Datei oder Bild anhängen (Tipp: Screenshots direkt mit Strg+V einfügen)"
                            aria-label="Datei oder Bild anhängen"
                            class="p-3 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer shrink-0 flex items-center justify-center"
                        >
                            📎
                        </button>

                        <button
                            type="button"
                            id="btnVoiceRecord"
                            onclick="startVoiceRecording()"
                            title="Sprachnachricht über das Mikrofon aufnehmen"
                            aria-label="Sprachnachricht aufnehmen"
                            class="p-3 rounded-xl bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/40 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:text-red-600 dark:hover:text-red-400 transition-colors cursor-pointer shrink-0 flex items-center justify-center"
                        >
                            🎙️
                        </button>

                        <textarea
                            name="message_text"
                            id="chatMessageInput"
                            rows="2"
                            maxlength="5000"
                            placeholder="Nachricht schreiben... (Strg+V fügt Screenshots direkt ein)"
                            class="flex-1 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none"
                        ></textarea>

                        <button
                            type="submit"
                            title="<?= $activeChannelType === 'broadcast' ? '📢 An alle' : 'Senden' ?>"
                            aria-label="<?= $activeChannelType === 'broadcast' ? '📢 An alle' : 'Senden' ?>"
                            class="p-3 rounded-xl <?= $activeChannelType === 'broadcast' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-brand-600 hover:bg-brand-700' ?> text-white shadow-xs transition-colors cursor-pointer shrink-0 flex items-center justify-center"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const container = document.getElementById('chatMessagesContainer');
    const convPane = document.getElementById('chatConversationPane');
    const mainCard = document.getElementById('messengerMainCard');
    const appFooter = document.getElementById('appFooter');
    const input = document.getElementById('chatMessageInput');
    const form = document.getElementById('chatSendForm');
    const btnQuickPush = document.getElementById('btnQuickEnablePush');
    const activeChannel = <?= json_encode($rawChannel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const chatNotifyMode = <?= (int)getCurrentChatNotifyMode() ?>;
    let lastMsgId = <?= (int)$lastMessageId ?>;

    // Automatische Mobilgeräte-Erkennung (Viewport, Touch-Sensorik & User-Agent)
    const isMobileDevice =
        window.matchMedia('(max-width: 1023px)').matches ||
        window.matchMedia('(pointer: coarse)').matches ||
        /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');

    document.documentElement.setAttribute('data-device', isMobileDevice ? 'mobile' : 'desktop');

    function scrollToBottom() {
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }
    scrollToBottom();

    // Auf Mobilgeräten Footer ausblenden und Chat-Höhe dynamisch an die Bildschirmtastatur (visualViewport) anpassen
    function updateMobileChatViewport() {
        if (!window.matchMedia('(max-width: 1023px)').matches) {
            if (convPane) convPane.style.height = '';
            if (mainCard) mainCard.style.minHeight = '';
            return;
        }
        if (appFooter) {
            appFooter.style.display = 'none';
        }
        if (convPane && !convPane.classList.contains('hidden')) {
            const vvHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
            const rect = convPane.getBoundingClientRect();
            const topOffset = Math.max(56, rect.top);
            const targetHeight = Math.max(220, Math.floor(vvHeight - topOffset - 10));
            convPane.style.height = targetHeight + 'px';
            if (mainCard) {
                mainCard.style.minHeight = targetHeight + 'px';
            }
            scrollToBottom();
        }
    }

    if (isMobileDevice) {
        updateMobileChatViewport();
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', updateMobileChatViewport);
            window.visualViewport.addEventListener('scroll', updateMobileChatViewport);
        }
        window.addEventListener('resize', updateMobileChatViewport);
    }

    // Schnell-Button zum Aktivieren von System-Benachrichtigungen anzeigen, falls noch nicht freigegeben
    if (btnQuickPush && (chatNotifyMode === 1 || chatNotifyMode === 2) && ('Notification' in window) && Notification.permission === 'default') {
        btnQuickPush.classList.remove('hidden');
        btnQuickPush.classList.add('inline-flex');
        btnQuickPush.addEventListener('click', async () => {
            try {
                const perm = await Notification.requestPermission();
                if (perm !== 'default') {
                    btnQuickPush.classList.add('hidden');
                    btnQuickPush.classList.remove('inline-flex');
                }
            } catch (_) {}
        });
    }

    if (input && form) {
        if (isMobileDevice) {
            // Auf Mobilgeräten kompakt 1-zeilig starten und beim Tippen automatisch mitwachsen lassen
            input.setAttribute('rows', '1');
            input.addEventListener('focus', () => {
                if (appFooter) appFooter.style.display = 'none';
                setTimeout(updateMobileChatViewport, 120);
                setTimeout(scrollToBottom, 180);
            });
            input.addEventListener('blur', () => {
                setTimeout(updateMobileChatViewport, 120);
            });
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 110) + 'px';
                scrollToBottom();
            });
        } else {
            input.focus();
        }

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const fileInp = document.getElementById('chatAttachmentFileInput');
                const cloudSel = document.getElementById('chatAttachedCloudSelect');
                const hasAttachment = (fileInp && fileInp.files && fileInp.files.length > 0) || (cloudSel && cloudSel.value !== '');
                if (input.value.trim() !== '' || hasAttachment) {
                    form.submit();
                }
            }
        });
    }

    function updateAttachmentBadge(label, icon = '📎') {
        const badge = document.getElementById('chatSelectedAttachmentBadge');
        const nameEl = document.getElementById('chatSelectedAttachmentName');
        const iconEl = document.getElementById('chatSelectedAttachmentIcon');
        const btn = document.getElementById('btnToggleChatAttach');
        if (!badge || !nameEl || !iconEl) return;
        if (label) {
            nameEl.textContent = label;
            iconEl.textContent = icon;
            badge.classList.remove('hidden');
            badge.classList.add('flex');
            if (btn) btn.classList.add('ring-2', 'ring-brand-500', 'bg-brand-50');
        } else {
            nameEl.textContent = '';
            badge.classList.add('hidden');
            badge.classList.remove('flex');
            if (btn) btn.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-50');
        }
    }

    window.toggleChatAttachPanel = function() {
        const panel = document.getElementById('chatAttachPanel');
        if (!panel) return;
        panel.classList.toggle('hidden');
    };

    window.clearChatAttachment = function() {
        const fileInp = document.getElementById('chatAttachmentFileInput');
        const cloudSel = document.getElementById('chatAttachedCloudSelect');
        const panel = document.getElementById('chatAttachPanel');
        if (fileInp) fileInp.value = '';
        if (cloudSel) cloudSel.value = '';
        if (panel) panel.classList.add('hidden');
        updateAttachmentBadge('');
    };

    window.onChatFileUploadChosen = function(inp) {
        const cloudSel = document.getElementById('chatAttachedCloudSelect');
        if (inp && inp.files && inp.files.length > 0) {
            if (cloudSel) cloudSel.value = '';
            const f = inp.files[0];
            const isVoice = f.name.startsWith('Sprachnachricht_') || (f.type && f.type.startsWith('audio/'));
            updateAttachmentBadge(f.name, isVoice ? '🎙️' : '📎');
        } else {
            updateAttachmentBadge('');
        }
    };

    window.onChatCloudFileChosen = function(sel) {
        const fileInp = document.getElementById('chatAttachmentFileInput');
        if (sel && sel.value !== '') {
            if (fileInp) fileInp.value = '';
            const optText = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : 'Cloud-Datei';
            updateAttachmentBadge(optText, '☁️');
        } else {
            updateAttachmentBadge('');
        }
    };

    // --- Zwischenablage-Einfügen (Strg+V) für Screenshots & Bilder im Messenger ---
    window.addEventListener('paste', (e) => {
        if (!form) return;
        const cd = e.clipboardData;
        if (!cd || !cd.items) return;

        let pastedFile = null;
        for (let i = 0; i < cd.items.length; i++) {
            const item = cd.items[i];
            if (item.kind === 'file') {
                const rawFile = item.getAsFile();
                if (!rawFile) continue;
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                const ts = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}_${pad(now.getHours())}-${pad(now.getMinutes())}-${pad(now.getSeconds())}`;
                let fileName = rawFile.name || '';
                if (!fileName || /^image\.(png|jpe?g|webp|gif|bmp)$/i.test(fileName) || /^blob$/i.test(fileName)) {
                    const ext = (rawFile.type && rawFile.type.split('/')[1]) ? rawFile.type.split('/')[1].replace(/[^a-z0-9]/gi, '') : 'png';
                    fileName = `Screenshot_${ts}.${ext || 'png'}`;
                }
                pastedFile = new File([rawFile], fileName, { type: rawFile.type || 'image/png' });
                break;
            }
        }

        if (pastedFile) {
            e.preventDefault();
            const fileInp = document.getElementById('chatAttachmentFileInput');
            const cloudSel = document.getElementById('chatAttachedCloudSelect');
            if (fileInp && typeof DataTransfer !== 'undefined') {
                const dt = new DataTransfer();
                dt.items.add(pastedFile);
                fileInp.files = dt.files;
                if (cloudSel) cloudSel.value = '';
                updateAttachmentBadge(`${pastedFile.name} (aus Zwischenablage eingefügt)`, '📋');
                if (input) input.focus();
            }
        }
    });

    // --- Sprachnachrichten über MediaRecorder aufnehmen ---
    let mediaRecorder = null;
    let audioChunks = [];
    let recordTimerInterval = null;
    let recordStartTime = 0;
    let autoSendOnStop = false;
    let discardOnStop = false;

    function formatRecordSeconds(sec) {
        const m = String(Math.floor(sec / 60)).padStart(2, '0');
        const s = String(sec % 60).padStart(2, '0');
        return `${m}:${s}`;
    }

    function stopMicrophoneTracks() {
        if (mediaRecorder && mediaRecorder.stream) {
            mediaRecorder.stream.getTracks().forEach((t) => t.stop());
        }
        if (recordTimerInterval) {
            clearInterval(recordTimerInterval);
            recordTimerInterval = null;
        }
        const bar = document.getElementById('voiceRecordBar');
        const btn = document.getElementById('btnVoiceRecord');
        if (bar) {
            bar.classList.add('hidden');
            bar.classList.remove('flex');
        }
        if (btn) {
            btn.classList.remove('ring-2', 'ring-red-500', 'bg-red-50');
        }
    }

    window.startVoiceRecording = async function() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            window.stopVoiceRecording(false);
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            alert('Ihr Browser unterstützt leider keine direkte Mikrofonaufnahme (MediaRecorder API).');
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioChunks = [];
            autoSendOnStop = false;
            discardOnStop = false;

            const mimeCandidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            let chosenMime = '';
            for (const c of mimeCandidates) {
                if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(c)) {
                    chosenMime = c;
                    break;
                }
            }

            mediaRecorder = chosenMime ? new MediaRecorder(stream, { mimeType: chosenMime }) : new MediaRecorder(stream);

            mediaRecorder.ondataavailable = (ev) => {
                if (ev.data && ev.data.size > 0) {
                    audioChunks.push(ev.data);
                }
            };

            mediaRecorder.onstop = () => {
                const usedMime = (mediaRecorder && mediaRecorder.mimeType) ? mediaRecorder.mimeType : (chosenMime || 'audio/webm');
                stopMicrophoneTracks();
                if (discardOnStop || audioChunks.length === 0) {
                    audioChunks = [];
                    return;
                }
                const blob = new Blob(audioChunks, { type: usedMime });
                audioChunks = [];
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                const ts = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}_${pad(now.getHours())}-${pad(now.getMinutes())}-${pad(now.getSeconds())}`;
                let ext = 'webm';
                if (usedMime.includes('ogg')) ext = 'ogg';
                else if (usedMime.includes('mp4') || usedMime.includes('aac')) ext = 'm4a';
                else if (usedMime.includes('wav')) ext = 'wav';

                const fileName = `Sprachnachricht_${ts}.${ext}`;
                const voiceFile = new File([blob], fileName, { type: usedMime.split(';')[0] || 'audio/webm' });

                const fileInp = document.getElementById('chatAttachmentFileInput');
                const cloudSel = document.getElementById('chatAttachedCloudSelect');
                if (fileInp && typeof DataTransfer !== 'undefined') {
                    const dt = new DataTransfer();
                    dt.items.add(voiceFile);
                    fileInp.files = dt.files;
                    if (cloudSel) cloudSel.value = '';
                    updateAttachmentBadge(`${fileName}`, '🎙️');
                    if (autoSendOnStop && form) {
                        form.submit();
                    }
                }
            };

            mediaRecorder.start();
            recordStartTime = Date.now();
            const timerEl = document.getElementById('voiceRecordTimer');
            const bar = document.getElementById('voiceRecordBar');
            const btn = document.getElementById('btnVoiceRecord');
            if (timerEl) timerEl.textContent = '00:00';
            if (bar) {
                bar.classList.remove('hidden');
                bar.classList.add('flex');
            }
            if (btn) {
                btn.classList.add('ring-2', 'ring-red-500', 'bg-red-50');
            }
            recordTimerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - recordStartTime) / 1000);
                if (timerEl) timerEl.textContent = formatRecordSeconds(elapsed);
            }, 500);
        } catch (err) {
            alert('Mikrofonzugriff konnte nicht gestartet werden. Bitte erlauben Sie den Mikrofonzugriff im Browser.');
        }
    };

    window.stopVoiceRecording = function(sendImmediately = false) {
        autoSendOnStop = Boolean(sendImmediately);
        discardOnStop = false;
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        } else {
            stopMicrophoneTracks();
        }
    };

    window.cancelVoiceRecording = function() {
        discardOnStop = true;
        autoSendOnStop = false;
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        } else {
            stopMicrophoneTracks();
        }
    };

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    // Live-Polling alle 4 Sekunden für neue Nachrichten im geöffneten Chat
    if (container && activeChannel) {
        setInterval(async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(`messages.php?ajax=poll&channel=${encodeURIComponent(activeChannel)}&last_id=${lastMsgId}`);
                const data = await res.json();
                if (!data || !data.success) return;

                const badge = document.getElementById('headerUnreadChatBadge');
                if (badge) {
                    if (data.unread_total > 0) {
                        badge.textContent = String(data.unread_total);
                        badge.classList.remove('hidden');
                        badge.classList.add('inline-flex');
                    } else {
                        badge.classList.add('hidden');
                        badge.classList.remove('inline-flex');
                    }
                }

                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    const emptyHint = document.getElementById('emptyChatHint');
                    if (emptyHint) emptyHint.remove();

                    for (const m of data.messages) {
                        if (m.id <= lastMsgId) continue;
                        lastMsgId = m.id;

                        const wrapper = document.createElement('div');
                        wrapper.className = 'flex ' + (m.is_mine ? 'justify-end' : 'justify-start');
                        wrapper.setAttribute('data-msg-id', String(m.id));

                        const bubbleClass = m.is_mine
                            ? 'bg-brand-600 text-white rounded-br-xs'
                            : (m.is_broadcast
                                ? 'bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/80 text-slate-900 dark:text-slate-100 rounded-bl-xs'
                                : 'bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 text-slate-900 dark:text-slate-100 rounded-bl-xs');

                        let headerHtml = '';
                        if (!m.is_mine || m.is_broadcast) {
                            headerHtml = `
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[11px] font-bold ${m.is_mine ? 'text-white/90' : 'text-brand-600 dark:text-brand-400'}">${escapeHtml(m.sender_username)}</span>
                                    ${m.sender_is_admin ? `<span class="px-1.5 py-0.1 rounded text-[9px] font-bold uppercase ${m.is_mine ? 'bg-white/20 text-white' : 'bg-brand-600 text-white'}">Admin</span>` : ''}
                                    ${m.is_broadcast ? `<span class="text-[10px] font-semibold ${m.is_mine ? 'text-white/80' : 'text-amber-700 dark:text-amber-300'}">📢 Rundnachricht</span>` : ''}
                                </div>
                            `;
                        }

                        let attachmentHtml = '';
                        if (m.attached_file_id && m.attached_file_id > 0) {
                            const dlLinkCls = m.is_mine
                                ? 'bg-white/15 hover:bg-white/25 text-white'
                                : 'bg-slate-100 dark:bg-slate-700/80 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100';
                            const imgHtml = m.attached_is_image
                                ? `<a href="download.php?id=${Number(m.attached_file_id)}" class="block rounded-xl overflow-hidden border ${m.is_mine ? 'border-white/25' : 'border-slate-200 dark:border-slate-700'} max-w-xs"><img src="download.php?id=${Number(m.attached_file_id)}&inline=1" alt="${escapeHtml(m.attached_name)}" loading="lazy" class="max-h-56 w-auto object-cover"></a>`
                                : (m.attached_is_audio
                                    ? `<div class="p-1.5 rounded-xl ${m.is_mine ? 'bg-white/15' : 'bg-slate-100 dark:bg-slate-700/70'}"><audio controls preload="metadata" src="download.php?id=${Number(m.attached_file_id)}&inline=1" class="w-full min-w-[210px] max-w-[270px] h-9"></audio></div>`
                                    : '');
                            attachmentHtml = `
                                <div class="mt-2 space-y-1.5">
                                    ${imgHtml}
                                    <a href="download.php?id=${Number(m.attached_file_id)}" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors ${dlLinkCls}">
                                        <span>${m.attached_is_audio ? '🎙️' : '📎'}</span>
                                        <span class="truncate max-w-[190px] sm:max-w-[260px]">${escapeHtml(m.attached_name)}</span>
                                        <span class="opacity-75 text-[10px]">(${escapeHtml(m.attached_size)})</span>
                                        <span>⬇️</span>
                                    </a>
                                </div>
                            `;
                        }

                        wrapper.innerHTML = `
                            <div class="max-w-[85%] sm:max-w-[72%] rounded-2xl px-3.5 py-2.5 shadow-2xs ${bubbleClass}">
                                ${headerHtml}
                                <div class="text-xs sm:text-sm whitespace-pre-line break-words leading-relaxed">${escapeHtml(m.message_text)}</div>
                                ${attachmentHtml}
                                <div class="flex items-center justify-end gap-2 mt-1 text-[10px] ${m.is_mine ? 'text-white/75' : 'text-slate-400 dark:text-slate-500'}">
                                    <span>${escapeHtml(m.time_str)} Uhr</span>
                                </div>
                            </div>
                        `;
                        container.appendChild(wrapper);
                    }
                    scrollToBottom();
                }
            } catch {
                // Netzwerkfehler beim Hintergrund-Polling ignorieren
            }
        }, 4000);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
