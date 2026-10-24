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

// --- 1. AJAX-Endpunkt für Live-Polling neuer Nachrichten ---
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
                    f.original_name AS file_name, f.file_size
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN files f ON f.id = m.attached_file_id AND f.deleted_at IS NULL
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
                        f.original_name AS file_name, f.file_size
                 FROM messages m
                 INNER JOIN users u ON u.id = m.sender_id
                 LEFT JOIN files f ON f.id = m.attached_file_id AND f.deleted_at IS NULL
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
        $formatted[] = [
            'id'              => (int)$m['id'],
            'sender_id'       => (int)$m['sender_id'],
            'sender_username' => (string)$m['sender_username'],
            'sender_is_admin' => ((int)$m['sender_is_admin'] === 1),
            'is_mine'         => ((int)$m['sender_id'] === $userId),
            'is_broadcast'    => ((int)$m['is_broadcast'] === 1),
            'message_text'    => (string)$m['message_text'],
            'file_id'         => $m['attached_file_id'] !== null && $m['file_name'] !== null ? (int)$m['attached_file_id'] : null,
            'file_name'       => $m['file_name'] !== null ? (string)$m['file_name'] : null,
            'file_size_fmt'   => $m['file_size'] !== null ? formatBytes((int)$m['file_size']) : null,
            'is_read'         => ((int)$m['is_read'] === 1),
            'time_str'        => date('H:i', $ts),
            'date_str'        => date('d.m.Y', $ts),
            'can_delete'      => ((int)$m['sender_id'] === $userId || ($isAdmin && (int)$m['is_broadcast'] === 1)),
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

    // A) Nachricht senden (Direktnachricht oder Admin-Rundnachricht)
    if ($action === 'send_message') {
        $targetChannel  = trim((string)($_POST['target_channel'] ?? ''));
        $messageText    = mb_substr(trim((string)($_POST['message_text'] ?? '')), 0, 5000);
        $attachedFileId = (int)($_POST['attached_file_id'] ?? 0);

        // Prüfen, ob eine gültige eigene Cloud-Datei angehängt wurde
        $validFileId = null;
        if ($attachedFileId > 0) {
            $stmtFile = $pdo->prepare('SELECT id, original_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmtFile->execute([':id' => $attachedFileId, ':uid' => $userId]);
            $fileRow = $stmtFile->fetch();
            if ($fileRow) {
                $validFileId = (int)$fileRow['id'];
                if ($messageText === '') {
                    $messageText = '📎 Datei: ' . (string)$fileRow['original_name'];
                }
            }
        }

        if ($messageText === '') {
            setFlash('error', 'Bitte geben Sie einen Nachrichtentext ein oder hängen Sie eine Datei an.');
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
                 VALUES (:sid, NULL, 1, :txt, :fid, 0)'
            );
            $stmtIns->execute([
                ':sid' => $userId,
                ':txt' => $messageText,
                ':fid' => $validFileId,
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
             VALUES (:sid, :rid, 0, :txt, :fid, 0)'
        );
        $stmtIns->execute([
            ':sid' => $userId,
            ':rid' => $recipientId,
            ':txt' => $messageText,
            ':fid' => $validFileId,
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
$rawChannel = trim((string)($_GET['channel'] ?? ''));
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
                f.original_name AS file_name, f.file_size
         FROM messages m
         INNER JOIN users u ON u.id = m.sender_id
         LEFT JOIN files f ON f.id = m.attached_file_id AND f.deleted_at IS NULL
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
                    f.original_name AS file_name, f.file_size
             FROM messages m
             INNER JOIN users u ON u.id = m.sender_id
             LEFT JOIN files f ON f.id = m.attached_file_id AND f.deleted_at IS NULL
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

// Eigene Dateien für das Datei-Anhängen im Chat laden
$stmtMyFiles = $pdo->prepare(
    'SELECT id, original_name, file_size
     FROM files
     WHERE user_id = :uid AND deleted_at IS NULL
     ORDER BY original_name ASC
     LIMIT 250'
);
$stmtMyFiles->execute([':uid' => $userId]);
$myAttachableFiles = $stmtMyFiles->fetchAll();

// Eigene Sichtbarkeits-Einstellung abrufen
$stmtSelfVis = $pdo->prepare('SELECT allow_user_sharing FROM users WHERE id = :id LIMIT 1');
$stmtSelfVis->execute([':id' => $userId]);
$mySharingAllowed = ((int)$stmtSelfVis->fetchColumn() === 1);

$lastMessageId = 0;
foreach ($chatMessages as $cm) {
    if ((int)$cm['id'] > $lastMessageId) {
        $lastMessageId = (int)$cm['id'];
    }
}

$pageTitle = 'Chat & Nachrichten';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfbereich -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
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

<!-- Messenger 2-Spalten-Hauptcontainer -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[560px] lg:h-[680px]">

    <!-- LINKE SPALTE: Konversationen & Neuer Chat (4 Spalten) -->
    <div class="lg:col-span-4 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-800 flex flex-col h-full bg-slate-50/50 dark:bg-slate-900/60">
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
    <div class="lg:col-span-8 flex flex-col h-[520px] lg:h-full bg-white dark:bg-slate-900">
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
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3 bg-slate-50/60 dark:bg-slate-800/40 shrink-0">
                <div class="flex items-center gap-3 min-w-0">
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
                        $hasFile      = ($msg['attached_file_id'] !== null && $msg['file_name'] !== null);
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

                                <?php if ($hasFile): ?>
                                    <div class="mt-2 pt-2 border-t <?= $isMine ? 'border-white/20' : 'border-slate-200 dark:border-slate-700' ?>">
                                        <a
                                            href="download.php?id=<?= (int)$msg['attached_file_id'] ?>"
                                            class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-semibold <?= $isMine ? 'bg-white/15 hover:bg-white/25 text-white' : 'bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-brand-600 dark:text-brand-300' ?> transition-colors"
                                        >
                                            <span>📎</span>
                                            <span class="truncate max-w-[200px]"><?= e((string)$msg['file_name']) ?></span>
                                            <span class="text-[10px] opacity-75">(<?= e(formatBytes((int)$msg['file_size'])) ?>)</span>
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
                <form action="messages.php" method="post" id="chatSendForm" class="p-3 sm:p-3.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 space-y-2 shrink-0">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="target_channel" value="<?= e($rawChannel) ?>">

                    <div class="flex items-end gap-2">
                        <textarea
                            name="message_text"
                            id="chatMessageInput"
                            rows="2"
                            maxlength="5000"
                            placeholder="<?= $activeChannelType === 'broadcast' ? '📢 Rundnachricht an alle Cloud-Benutzer eingeben (Enter zum Senden)...' : 'Nachricht schreiben (Enter zum Senden, Shift+Enter für Zeilenumbruch)...' ?>"
                            class="flex-1 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none"
                        ></textarea>

                        <button
                            type="submit"
                            class="px-4 py-2.5 rounded-xl <?= $activeChannelType === 'broadcast' ? 'bg-amber-500 hover:bg-amber-600' : 'bg-brand-600 hover:bg-brand-700' ?> text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer shrink-0 flex items-center gap-1.5"
                        >
                            <span><?= $activeChannelType === 'broadcast' ? '📢 An alle' : 'Senden' ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>

                    <?php if (!empty($myAttachableFiles)): ?>
                        <div class="flex items-center gap-2 pt-0.5">
                            <label for="chatAttachFileSelect" class="text-[11px] font-medium text-slate-500 dark:text-slate-400 shrink-0">
                                📎 Cloud-Datei anhängen:
                            </label>
                            <select
                                name="attached_file_id"
                                id="chatAttachFileSelect"
                                class="flex-1 max-w-xs px-2.5 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-800 dark:text-slate-200"
                            >
                                <option value="0">– Keine Datei –</option>
                                <?php foreach ($myAttachableFiles as $af): ?>
                                    <option value="<?= (int)$af['id'] ?>">
                                        <?= e((string)$af['original_name']) ?> (<?= e(formatBytes((int)$af['file_size'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const container = document.getElementById('chatMessagesContainer');
    const input = document.getElementById('chatMessageInput');
    const form = document.getElementById('chatSendForm');
    const activeChannel = <?= json_encode($rawChannel) ?>;
    let lastMsgId = <?= (int)$lastMessageId ?>;

    function scrollToBottom() {
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }
    scrollToBottom();

    if (input && form) {
        input.focus();
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (input.value.trim() !== '' || (document.getElementById('chatAttachFileSelect') && document.getElementById('chatAttachFileSelect').value !== '0')) {
                    form.submit();
                }
            }
        });
    }

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

                        let fileHtml = '';
                        if (m.file_id && m.file_name) {
                            fileHtml = `
                                <div class="mt-2 pt-2 border-t ${m.is_mine ? 'border-white/20' : 'border-slate-200 dark:border-slate-700'}">
                                    <a href="download.php?id=${Number(m.file_id)}" class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl text-xs font-semibold ${m.is_mine ? 'bg-white/15 hover:bg-white/25 text-white' : 'bg-slate-100 dark:bg-slate-700 text-brand-600 dark:text-brand-300'}">
                                        <span>📎</span>
                                        <span class="truncate max-w-[200px]">${escapeHtml(m.file_name)}</span>
                                        <span class="text-[10px] opacity-75">(${escapeHtml(m.file_size_fmt || '')})</span>
                                    </a>
                                </div>
                            `;
                        }

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

                        wrapper.innerHTML = `
                            <div class="max-w-[85%] sm:max-w-[72%] rounded-2xl px-3.5 py-2.5 shadow-2xs ${bubbleClass}">
                                ${headerHtml}
                                <div class="text-xs sm:text-sm whitespace-pre-line break-words leading-relaxed">${escapeHtml(m.message_text)}</div>
                                ${fileHtml}
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
