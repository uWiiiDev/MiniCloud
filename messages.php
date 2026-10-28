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

/**
 * Stellt sicher, dass die Tabellen für den 1:1- & Gruppen-Sprachchat (WebRTC-Mesh + Push-to-Talk) existieren.
 * Sitzungs-gecacht für minimale Datenbanklast auf STRATO / Shared Hosting.
 */
function ensureVoiceCallSchema(PDO $pdo): void
{
    if (!empty($_SESSION['mc_voice_schema_v1'])) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `voice_room_peers` (
            `room_key` VARCHAR(64) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `username` VARCHAR(64) NOT NULL,
            `is_muted` TINYINT(1) NOT NULL DEFAULT 0,
            `is_speaking` TINYINT(1) NOT NULL DEFAULT 0,
            `is_sharing_screen` TINYINT(1) NOT NULL DEFAULT 0,
            `mode` VARCHAR(16) NOT NULL DEFAULT 'webrtc',
            `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`room_key`, `user_id`),
            KEY `idx_vrp_seen` (`last_seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `voice_signals` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `room_key` VARCHAR(64) NOT NULL,
            `from_user_id` INT UNSIGNED NOT NULL,
            `from_username` VARCHAR(64) NOT NULL DEFAULT '',
            `to_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `sig_type` VARCHAR(24) NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_vs_room_to` (`room_key`, `to_user_id`, `id`),
            KEY `idx_vs_to_type` (`to_user_id`, `sig_type`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $_SESSION['mc_voice_schema_v1'] = true;
}

/**
 * Entfernt abgelaufene Teilnehmer (> 25 Sek. ohne Heartbeat) und alte Signale (> 60 Sek.).
 */
function cleanupStaleVoicePeersAndSignals(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM `voice_room_peers` WHERE `last_seen` < (NOW() - INTERVAL 25 SECOND)");
        $pdo->exec("DELETE FROM `voice_signals` WHERE `created_at` < (NOW() - INTERVAL 60 SECOND)");
    } catch (Throwable) {
    }
}

/**
 * Liefert alle Gruppen- und Treffpunkt-Sprachräume, auf die der Benutzer Zugriff hat.
 *
 * @return array<string, array{key: string, title: string, subtitle: string, icon: string, category: string}>
 */
function getAvailableVoiceRooms(PDO $pdo, int $userId, bool $isAdmin): array
{
    $rooms = [];

    // 1. Offener Cloud-Treffpunkt (für alle sichtbaren Benutzer & Admins)
    $rooms['lobby_all'] = [
        'key'      => 'lobby_all',
        'title'    => 'Cloud-Treffpunkt',
        'subtitle' => 'Offener Sprachraum für alle Mitglieder',
        'icon'     => '🌐',
        'category' => 'lobby',
    ];

    // 2. Sprachräume für Benutzergruppen
    try {
        if ($isAdmin) {
            $stmtG = $pdo->query('SELECT id, name, description FROM `groups` ORDER BY name ASC');
            $groupRows = $stmtG->fetchAll();
        } else {
            $stmtG = $pdo->prepare(
                'SELECT g.id, g.name, g.description
                 FROM `groups` g
                 INNER JOIN `group_members` gm ON gm.group_id = g.id
                 WHERE gm.user_id = :uid
                 ORDER BY g.name ASC'
            );
            $stmtG->execute([':uid' => $userId]);
            $groupRows = $stmtG->fetchAll();
        }
        foreach ($groupRows as $gr) {
            $gid = (int)$gr['id'];
            $rKey = 'group_' . $gid;
            $rooms[$rKey] = [
                'key'      => $rKey,
                'title'    => 'Gruppe: ' . (string)$gr['name'],
                'subtitle' => trim((string)($gr['description'] ?? '')) !== '' ? (string)$gr['description'] : 'Gruppen-Sprachkanal',
                'icon'     => '👥',
                'category' => 'group',
            ];
        }
    } catch (Throwable) {
    }

    // 3. Sprachräume für Vereinsvorstände (falls club.php genutzt wird)
    try {
        if ($isAdmin) {
            $stmtC = $pdo->query('SELECT id, name FROM `clubs` ORDER BY name ASC');
            $clubRows = $stmtC->fetchAll();
        } else {
            $stmtC = $pdo->prepare(
                'SELECT DISTINCT c.id, c.name
                 FROM `clubs` c
                 LEFT JOIN `club_roles` cr ON cr.club_id = c.id AND cr.user_id = :uid1
                 WHERE c.owner_id = :uid2 OR cr.id IS NOT NULL
                 ORDER BY c.name ASC'
            );
            $stmtC->execute([':uid1' => $userId, ':uid2' => $userId]);
            $clubRows = $stmtC->fetchAll();
        }
        foreach ($clubRows as $cl) {
            $cid = (int)$cl['id'];
            $rKey = 'club_' . $cid;
            $rooms[$rKey] = [
                'key'      => $rKey,
                'title'    => 'Vorstand: ' . (string)$cl['name'],
                'subtitle' => 'Vorstands- & Vereinsbesprechung',
                'icon'     => '🏛️',
                'category' => 'club',
            ];
        }
    } catch (Throwable) {
    }

    return $rooms;
}

/**
 * Prüft streng, ob $userId dem Sprachraum $roomKey beitreten bzw. dort Signale senden darf.
 *
 * @return array{allowed: bool, title: string, partner_id: int}
 */
function canAccessVoiceRoom(PDO $pdo, int $userId, bool $isAdmin, string $roomKey): array
{
    $roomKey = trim($roomKey);
    if ($roomKey === '') {
        return ['allowed' => false, 'title' => '', 'partner_id' => 0];
    }

    // Fall A: 1:1 Direkt-Anruf (dm_<minId>_<maxId>)
    if (preg_match('/^dm_(\d+)_(\d+)$/', $roomKey, $m)) {
        $uA = (int)$m[1];
        $uB = (int)$m[2];
        if ($uA <= 0 || $uB <= 0 || $uA === $uB) {
            return ['allowed' => false, 'title' => '', 'partner_id' => 0];
        }
        if ($userId !== $uA && $userId !== $uB) {
            return ['allowed' => false, 'title' => '', 'partner_id' => 0];
        }
        $otherId = ($userId === $uA) ? $uB : $uA;
        $partner = getAllowedChatPartner($pdo, $userId, $otherId, $isAdmin);
        if (!$partner) {
            return ['allowed' => false, 'title' => '', 'partner_id' => 0];
        }
        return [
            'allowed'    => true,
            'title'      => '1:1 Sprachchat mit ' . (string)$partner['username'],
            'partner_id' => $otherId,
        ];
    }

    // Fall B: Gruppen- / Vorstands- / Lobby-Raum
    $rooms = getAvailableVoiceRooms($pdo, $userId, $isAdmin);
    if (isset($rooms[$roomKey])) {
        return [
            'allowed'    => true,
            'title'      => $rooms[$roomKey]['title'],
            'partner_id' => 0,
        ];
    }

    return ['allowed' => false, 'title' => '', 'partner_id' => 0];
}

ensureVoiceCallSchema($pdo);

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

    // Huckepack-Prüfung für eingehende 1:1-Sprachanrufe (0 zusätzliche Requests auf STRATO!)
    $incomingCall = null;
    try {
        $stmtRing = $pdo->prepare(
            "SELECT s.id, s.room_key, s.from_user_id, s.from_username, s.payload
             FROM `voice_signals` s
             INNER JOIN `voice_room_peers` p ON p.room_key = s.room_key AND p.user_id = s.from_user_id
             WHERE s.to_user_id = :uid
               AND s.sig_type = 'ring'
               AND s.created_at >= (NOW() - INTERVAL 28 SECOND)
               AND p.last_seen >= (NOW() - INTERVAL 25 SECOND)
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $stmtRing->execute([':uid' => $userId]);
        $ringRow = $stmtRing->fetch();
        if ($ringRow) {
            // Prüfen, ob Benutzer nicht schon selbst in diesem Raum ist oder bereits abgelehnt hat
            $stmtSelfInRoom = $pdo->prepare('SELECT 1 FROM `voice_room_peers` WHERE `room_key` = :rk AND `user_id` = :uid LIMIT 1');
            $stmtSelfInRoom->execute([':rk' => (string)$ringRow['room_key'], ':uid' => $userId]);
            $stmtDeclined = $pdo->prepare(
                "SELECT 1 FROM `voice_signals`
                 WHERE `room_key` = :rk AND `from_user_id` = :uid AND `sig_type` = 'hangup' AND `id` > :ring_id
                 LIMIT 1"
            );
            $stmtDeclined->execute([
                ':rk'      => (string)$ringRow['room_key'],
                ':uid'     => $userId,
                ':ring_id' => (int)$ringRow['id'],
            ]);
            if (!$stmtSelfInRoom->fetch() && !$stmtDeclined->fetch()) {
                $incomingCall = [
                    'sig_id'          => (int)$ringRow['id'],
                    'room_key'        => (string)$ringRow['room_key'],
                    'caller_id'       => (int)$ringRow['from_user_id'],
                    'caller_username' => (string)$ringRow['from_username'],
                    'mode'            => ((string)$ringRow['payload'] === 'ptt') ? 'ptt' : 'webrtc',
                ];
            }
        }
    } catch (Throwable) {
    }

    echo json_encode([
        'success'       => true,
        'unread_total'  => getUnreadMessagesCount($pdo, $userId),
        'max_id'        => $maxId,
        'latest_unread' => $latestUnread,
        'incoming_call' => $incomingCall,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 1b. STRATO-optimierte Voice-Chat-Endpunkte (WebRTC-Mesh Signaling + Push-to-Talk Funk-Fallback) ---
if (isset($_GET['ajax']) && in_array((string)$_GET['ajax'], ['voice_join', 'voice_leave', 'voice_signal', 'voice_ptt', 'voice_poll'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $vAction = (string)$_GET['ajax'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!verifyCsrfToken(is_string($csrfHeader) ? $csrfHeader : null)) {
            echo json_encode(['success' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
            exit;
        }
    }

    cleanupStaleVoicePeersAndSignals($pdo);

    $roomKey = trim((string)($_POST['room_key'] ?? $_GET['room_key'] ?? ''));

    // A) Sprachraum beitreten (1:1 oder Gruppen-Sprachraum)
    if ($vAction === 'voice_join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $acc = canAccessVoiceRoom($pdo, $userId, $isAdmin, $roomKey);
        if (!$acc['allowed']) {
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff auf diesen Sprachraum.']);
            exit;
        }

        $mode = ((string)($_POST['mode'] ?? 'webrtc') === 'ptt') ? 'ptt' : 'webrtc';
        $ringPartner = isset($_POST['ring']) && $_POST['ring'] === '1';

        // Aus evtl. anderen Sprachräumen austreten, damit man immer nur in einem aktiven Raum ist
        $pdo->prepare('DELETE FROM `voice_room_peers` WHERE `user_id` = :uid AND `room_key` <> :rk')
            ->execute([':uid' => $userId, ':rk' => $roomKey]);

        $stmtJoin = $pdo->prepare(
            "INSERT INTO `voice_room_peers` (`room_key`, `user_id`, `username`, `is_muted`, `is_speaking`, `is_sharing_screen`, `mode`, `joined_at`, `last_seen`)
             VALUES (:rk, :uid, :uname, 0, 0, 0, :mode, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                `username` = VALUES(`username`),
                `mode` = VALUES(`mode`),
                `last_seen` = NOW()"
        );
        $stmtJoin->execute([
            ':rk'    => $roomKey,
            ':uid'   => $userId,
            ':uname' => $username,
            ':mode'  => $mode,
        ]);

        // Bei 1:1-Anruf Klingel-Signal an den Gesprächspartner senden
        if ($ringPartner && $acc['partner_id'] > 0) {
            $stmtRingIns = $pdo->prepare(
                "INSERT INTO `voice_signals` (`room_key`, `from_user_id`, `from_username`, `to_user_id`, `sig_type`, `payload`)
                 VALUES (:rk, :fuid, :fname, :tuid, 'ring', :mode)"
            );
            $stmtRingIns->execute([
                ':rk'    => $roomKey,
                ':fuid'  => $userId,
                ':fname' => $username,
                ':tuid'  => $acc['partner_id'],
                ':mode'  => $mode,
            ]);
        }

        $maxSigId = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM `voice_signals`')->fetchColumn();
        $stmtPeers = $pdo->prepare(
            'SELECT `user_id`, `username`, `is_muted`, `is_speaking`, `is_sharing_screen`, `mode`
             FROM `voice_room_peers`
             WHERE `room_key` = :rk AND `last_seen` >= (NOW() - INTERVAL 25 SECOND)
             ORDER BY `joined_at` ASC'
        );
        $stmtPeers->execute([':rk' => $roomKey]);

        echo json_encode([
            'success'     => true,
            'room_key'    => $roomKey,
            'room_title'  => $acc['title'],
            'partner_id'  => $acc['partner_id'],
            'last_sig_id' => $maxSigId,
            'peers'       => $stmtPeers->fetchAll(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // B) Sprachraum verlassen / Anruf ablehnen
    if ($vAction === 'voice_leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($roomKey !== '') {
            $pdo->prepare('DELETE FROM `voice_room_peers` WHERE `room_key` = :rk AND `user_id` = :uid')
                ->execute([':rk' => $roomKey, ':uid' => $userId]);
            $stmtHang = $pdo->prepare(
                "INSERT INTO `voice_signals` (`room_key`, `from_user_id`, `from_username`, `to_user_id`, `sig_type`, `payload`)
                 VALUES (:rk, :fuid, :fname, 0, 'hangup', '')"
            );
            $stmtHang->execute([
                ':rk'    => $roomKey,
                ':fuid'  => $userId,
                ':fname' => $username,
            ]);
        } else {
            $pdo->prepare('DELETE FROM `voice_room_peers` WHERE `user_id` = :uid')
                ->execute([':uid' => $userId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // C) WebRTC-Signaling (SDP Offer, SDP Answer, ICE-Kandidat) übermitteln
    if ($vAction === 'voice_signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $acc = canAccessVoiceRoom($pdo, $userId, $isAdmin, $roomKey);
        if (!$acc['allowed']) {
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff.']);
            exit;
        }
        $toUserId = max(0, (int)($_POST['to_user_id'] ?? 0));
        $sigType  = trim((string)($_POST['sig_type'] ?? ''));
        $payload  = (string)($_POST['payload'] ?? '');

        if (!in_array($sigType, ['offer', 'answer', 'ice', 'ring', 'hangup'], true) || strlen($payload) > 65536) {
            echo json_encode(['success' => false, 'error' => 'Ungültiges Signal.']);
            exit;
        }

        $stmtSig = $pdo->prepare(
            'INSERT INTO `voice_signals` (`room_key`, `from_user_id`, `from_username`, `to_user_id`, `sig_type`, `payload`)
             VALUES (:rk, :fuid, :fname, :tuid, :stype, :pay)'
        );
        $stmtSig->execute([
            ':rk'    => $roomKey,
            ':fuid'  => $userId,
            ':fname' => $username,
            ':tuid'  => $toUserId,
            ':stype' => $sigType,
            ':pay'   => $payload,
        ]);

        echo json_encode(['success' => true, 'sig_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    // D) Push-to-Talk (Funkgerät-Modus): Kurzes komprimiertes Sprachpaket an alle im Raum senden
    if ($vAction === 'voice_ptt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $acc = canAccessVoiceRoom($pdo, $userId, $isAdmin, $roomKey);
        if (!$acc['allowed']) {
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff auf diesen Sprachraum.']);
            exit;
        }
        $audioDataUri = trim((string)($_POST['audio_data'] ?? ''));
        if ($audioDataUri === '' || !str_starts_with($audioDataUri, 'data:audio/') || strlen($audioDataUri) > 450000) {
            echo json_encode(['success' => false, 'error' => 'Ungültiges oder zu großes Funk-Sprachpaket (max. ~300 KB).']);
            exit;
        }

        $stmtPtt = $pdo->prepare(
            "INSERT INTO `voice_signals` (`room_key`, `from_user_id`, `from_username`, `to_user_id`, `sig_type`, `payload`)
             VALUES (:rk, :fuid, :fname, 0, 'ptt_audio', :pay)"
        );
        $stmtPtt->execute([
            ':rk'    => $roomKey,
            ':fuid'  => $userId,
            ':fname' => $username,
            ':pay'   => $audioDataUri,
        ]);

        echo json_encode(['success' => true, 'sig_id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    // E) Heartbeat & Signal-Abruf für aktiven Sprachraum + Raum-Teilnehmerzahlen für Seitenleiste
    if ($vAction === 'voice_poll') {
        $sinceSigId = max(0, (int)($_POST['since_sig_id'] ?? $_GET['since_sig_id'] ?? 0));
        $peers      = [];
        $signals    = [];

        if ($roomKey !== '') {
            $acc = canAccessVoiceRoom($pdo, $userId, $isAdmin, $roomKey);
            if ($acc['allowed']) {
                $isMuted   = isset($_POST['is_muted']) && $_POST['is_muted'] === '1' ? 1 : 0;
                $isSpeak   = isset($_POST['is_speaking']) && $_POST['is_speaking'] === '1' ? 1 : 0;
                $isScreen  = isset($_POST['is_sharing_screen']) && $_POST['is_sharing_screen'] === '1' ? 1 : 0;
                $mode      = ((string)($_POST['mode'] ?? 'webrtc') === 'ptt') ? 'ptt' : 'webrtc';

                $stmtHb = $pdo->prepare(
                    'UPDATE `voice_room_peers`
                     SET `is_muted` = :m, `is_speaking` = :sp, `is_sharing_screen` = :sc, `mode` = :md, `last_seen` = NOW()
                     WHERE `room_key` = :rk AND `user_id` = :uid'
                );
                $stmtHb->execute([
                    ':m'   => $isMuted,
                    ':sp'  => $isSpeak,
                    ':sc'  => $isScreen,
                    ':md'  => $mode,
                    ':rk'  => $roomKey,
                    ':uid' => $userId,
                ]);

                $stmtPeers = $pdo->prepare(
                    'SELECT `user_id`, `username`, `is_muted`, `is_speaking`, `is_sharing_screen`, `mode`
                     FROM `voice_room_peers`
                     WHERE `room_key` = :rk AND `last_seen` >= (NOW() - INTERVAL 25 SECOND)
                     ORDER BY `joined_at` ASC'
                );
                $stmtPeers->execute([':rk' => $roomKey]);
                $peers = $stmtPeers->fetchAll();

                $stmtSigs = $pdo->prepare(
                    'SELECT `id`, `from_user_id`, `from_username`, `to_user_id`, `sig_type`, `payload`
                     FROM `voice_signals`
                     WHERE `room_key` = :rk
                       AND `id` > :since_id
                       AND `from_user_id` <> :self_uid
                       AND (`to_user_id` = :to_uid OR `to_user_id` = 0)
                     ORDER BY `id` ASC
                     LIMIT 60'
                );
                $stmtSigs->execute([
                    ':rk'       => $roomKey,
                    ':since_id' => $sinceSigId,
                    ':self_uid' => $userId,
                    ':to_uid'   => $userId,
                ]);
                $signals = $stmtSigs->fetchAll();
            }
        }

        // Aktive Teilnehmerzahlen je Raum für die linke Seitenleiste
        $roomCounts = [];
        try {
            $stmtCnt = $pdo->query(
                'SELECT `room_key`, COUNT(*) AS `cnt`
                 FROM `voice_room_peers`
                 WHERE `last_seen` >= (NOW() - INTERVAL 25 SECOND)
                 GROUP BY `room_key`'
            );
            foreach ($stmtCnt->fetchAll() as $rRow) {
                $roomCounts[(string)$rRow['room_key']] = (int)$rRow['cnt'];
            }
        } catch (Throwable) {
        }

        echo json_encode([
            'success'     => true,
            'peers'       => $peers,
            'signals'     => $signals,
            'room_counts' => $roomCounts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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

cleanupStaleVoicePeersAndSignals($pdo);
$availableVoiceRooms = getAvailableVoiceRooms($pdo, $userId, $isAdmin);
$initialVoiceRoomCounts = [];
try {
    $stmtVCnt = $pdo->query(
        'SELECT `room_key`, COUNT(*) AS `cnt`
         FROM `voice_room_peers`
         WHERE `last_seen` >= (NOW() - INTERVAL 25 SECOND)
         GROUP BY `room_key`'
    );
    foreach ($stmtVCnt->fetchAll() as $vRow) {
        $initialVoiceRoomCounts[(string)$vRow['room_key']] = (int)$vRow['cnt'];
    }
} catch (Throwable) {
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

        <!-- 🎧 Live-Sprachräume & Gruppen-Talk (STRATO-optimiert: WebRTC-Mesh + Push-to-Talk) -->
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 bg-indigo-50/40 dark:bg-indigo-950/20 space-y-1.5">
            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300 flex items-center gap-1">
                    <span>🎧</span>
                    <span>Gruppen-Sprachräume</span>
                </span>
                <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-indigo-100 dark:bg-indigo-900/70 text-indigo-700 dark:text-indigo-200">
                    WebRTC + Funk
                </span>
            </div>
            <div class="space-y-1 max-h-36 overflow-y-auto pr-0.5">
                <?php foreach ($availableVoiceRooms as $vrKey => $vrMeta): ?>
                    <?php $vrCnt = (int)($initialVoiceRoomCounts[$vrKey] ?? 0); ?>
                    <button
                        type="button"
                        onclick="joinVoiceCallRoom(<?= e(json_encode($vrKey)) ?>, <?= e(json_encode($vrMeta['title'])) ?>, 'webrtc', false)"
                        data-voice-room-btn="<?= e($vrKey) ?>"
                        class="w-full flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800/90 hover:bg-indigo-50 dark:hover:bg-indigo-900/40 border border-slate-200/80 dark:border-slate-700/80 text-left transition-colors cursor-pointer group"
                        title="<?= e($vrMeta['subtitle']) ?> – Klicken zum Beitreten"
                    >
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm shrink-0"><?= e($vrMeta['icon']) ?></span>
                            <span class="text-xs font-semibold text-slate-800 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-300 truncate">
                                <?= e($vrMeta['title']) ?>
                            </span>
                        </div>
                        <span
                            data-voice-room-badge="<?= e($vrKey) ?>"
                            class="px-1.5 py-0.5 rounded-full text-[10px] font-bold shrink-0 <?= $vrCnt > 0 ? 'bg-emerald-600 text-white animate-pulse' : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300' ?>"
                        >
                            <?= $vrCnt > 0 ? ('🟢 ' . $vrCnt . ' live') : 'Beitreten' ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
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
        <!-- 🎧 Live-Sprachkonferenz & Gruppen-Talk Cockpit (WebRTC-Mesh + Push-to-Talk Funk-Fallback) -->
        <div id="voiceCallCockpit" class="hidden border-b border-indigo-200 dark:border-indigo-800/80 bg-gradient-to-r from-indigo-50/90 via-slate-50 to-emerald-50/80 dark:from-indigo-950/60 dark:via-slate-900 dark:to-emerald-950/40 p-3 sm:p-4 shrink-0 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="w-8 h-8 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-sm shadow-2xs animate-pulse shrink-0">🎧</span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 id="voiceCallRoomTitle" class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">Sprachraum</h3>
                            <span id="voiceCallModeBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/70 text-emerald-800 dark:text-emerald-200">⚡ WebRTC-Direkt</span>
                            <span id="voiceCallTimerBadge" class="text-[11px] font-mono font-semibold text-slate-500 dark:text-slate-400">00:00</span>
                        </div>
                        <p id="voiceCallStatusSub" class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                            Ende-zu-Ende Audio aktiv – STRATO-schonender Direktmodus
                        </p>
                    </div>
                </div>

                <!-- Steuerleiste oben rechts im Sprach-Cockpit -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        id="btnVoiceMuteToggle"
                        onclick="toggleVoiceMute()"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-100 text-xs font-semibold transition-colors cursor-pointer"
                        title="Mikrofon stummschalten / aktivieren"
                    >
                        <span id="voiceMuteIcon">🎤</span>
                        <span id="voiceMuteLabel" class="hidden sm:inline">Mikro an</span>
                    </button>

                    <button
                        type="button"
                        id="btnVoiceScreenShare"
                        onclick="toggleVoiceScreenShare()"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-100 text-xs font-semibold transition-colors cursor-pointer"
                        title="Bildschirm oder Fenster für die Teilnehmer im Raum freigeben"
                    >
                        <span>🖥️</span>
                        <span id="voiceScreenLabel" class="hidden md:inline">Bildschirm</span>
                    </button>

                    <button
                        type="button"
                        id="btnVoiceSwitchMode"
                        onclick="switchVoiceTransportMode()"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-indigo-100 dark:bg-indigo-900/70 hover:bg-indigo-200 dark:hover:bg-indigo-800 text-indigo-800 dark:text-indigo-200 text-xs font-semibold transition-colors cursor-pointer"
                        title="Zwischen ⚡ WebRTC-Direktverbindung und 📻 Push-to-Talk (Firewall-/Großgruppen-Funk) umschalten"
                    >
                        <span id="voiceSwitchModeIcon">📻</span>
                        <span id="voiceSwitchModeLabel" class="hidden sm:inline">Auf Funk umschalten</span>
                    </button>

                    <button
                        type="button"
                        onclick="leaveVoiceCallRoom()"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-bold shadow-2xs transition-colors cursor-pointer"
                        title="Sprachraum verlassen / Auflegen"
                    >
                        <span>☎️</span>
                        <span>Auflegen</span>
                    </button>
                </div>
            </div>

            <!-- Teilnehmer-Kacheln im Sprachraum -->
            <div id="voicePeersGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2"></div>

            <!-- Push-to-Talk (Funkgerät) Sprechtasten-Leiste -->
            <div id="voicePttControls" class="hidden items-center justify-between gap-3 p-2.5 rounded-xl bg-indigo-950/90 text-white border border-indigo-700">
                <div class="text-xs min-w-0">
                    <span class="font-bold text-indigo-200">📻 Funk-Modus (Push-to-Talk – 100 % Firewall- &amp; STRATO-sicher):</span>
                    <p id="voicePttHint" class="text-[11px] text-slate-300 truncate">
                        Halte den Button rechts (oder die <kbd class="px-1.5 py-0.5 rounded bg-slate-800 text-[10px]">Leertaste</kbd>) gedrückt, während du sprichst.
                    </p>
                </div>
                <button
                    type="button"
                    id="btnPushToTalkHold"
                    onmousedown="startPttTransmission(event)"
                    onmouseup="stopPttTransmission(event)"
                    onmouseleave="stopPttTransmission(event)"
                    ontouchstart="startPttTransmission(event)"
                    ontouchend="stopPttTransmission(event)"
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 active:bg-red-600 text-white text-xs font-bold shadow-md select-none cursor-pointer shrink-0 transition-all"
                >
                    🎙️ Gedrückt halten zum Sprechen
                </button>
            </div>

            <!-- Optionale Bildschirmfreigabe-Vorschau im Sprachraum -->
            <div id="voiceScreenShareWrap" class="hidden relative rounded-xl overflow-hidden bg-slate-950 border border-slate-700">
                <video id="voiceRemoteScreenVideo" autoplay playsinline controls class="w-full max-h-72 object-contain"></video>
            </div>

            <!-- Versteckter Container für eingehende WebRTC-Audio-Streams -->
            <div id="voiceRemoteAudioContainer" class="hidden"></div>
        </div>

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

                <!-- Rechte Aktionsbuttons im Chat-Header (1:1 Sprachanruf, System-Benachrichtigungen & Verlauf leeren) -->
                <div class="flex items-center gap-1.5 shrink-0">
                    <?php if ($activeChannelType === 'user' && $activePartner): ?>
                        <?php
                        $dmMin = min($userId, (int)$activePartner['id']);
                        $dmMax = max($userId, (int)$activePartner['id']);
                        $dmRoomKey = 'dm_' . $dmMin . '_' . $dmMax;
                        $dmTitle = '1:1 Sprachanruf mit ' . (string)$activePartner['username'];
                        ?>
                        <button
                            type="button"
                            onclick="joinVoiceCallRoom(<?= e(json_encode($dmRoomKey)) ?>, <?= e(json_encode($dmTitle)) ?>, 'webrtc', true)"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                            title="Direkten 1:1 Live-Sprachanruf (WebRTC) mit <?= e((string)$activePartner['username']) ?> starten"
                        >
                            <span>📞</span>
                            <span class="hidden sm:inline">Anrufen</span>
                        </button>
                        <button
                            type="button"
                            onclick="joinVoiceCallRoom(<?= e(json_encode($dmRoomKey)) ?>, <?= e(json_encode($dmTitle)) ?>, 'ptt', true)"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/80 text-xs font-semibold transition-colors cursor-pointer"
                            title="100 % Firewall-sicherer Walkie-Talkie / Funk-Modus (Push-to-Talk) mit <?= e((string)$activePartner['username']) ?>"
                        >
                            <span>📻</span>
                            <span class="hidden md:inline">Funk</span>
                        </button>
                    <?php endif; ?>

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

    // =========================================================================
    // 🎧 STRATO-OPTIMIERTER 1:1 & GRUPPEN-VOICE-CHAT (WebRTC-Mesh + Push-to-Talk)
    // =========================================================================
    const csrfToken = <?= json_encode(getCsrfToken()) ?>;
    const selfUserId = <?= (int)$userId ?>;
    const selfUsername = <?= json_encode($username) ?>;

    let activeVoiceRoom = '';
    let activeVoiceTitle = '';
    let voiceTransportMode = 'webrtc'; // 'webrtc' oder 'ptt'
    let voiceLocalStream = null;
    let voiceScreenStream = null;
    let voiceMuted = false;
    let voiceSpeaking = false;
    let voiceLastSigId = 0;
    let voicePollTimer = null;
    let voiceCallClockTimer = null;
    let voiceCallStartedAt = 0;
    let voiceAudioCtx = null;
    let voiceAnalyser = null;
    let voiceLevelTimer = null;

    // PeerConnections pro Remote-User-ID (für WebRTC-Mesh bis ~8 Personen)
    const peerConnections = new Map(); // userId -> RTCPeerConnection
    const pendingIceCandidates = new Map(); // userId -> RTCIceCandidateInit[]

    // Push-to-Talk (Funk-Modus) Recorder
    let pttRecorder = null;
    let pttChunks = [];
    let isPttRecording = false;

    const rtcConfig = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun.cloudflare.com:3478' }
        ]
    };

    function playVoiceTone(freq1 = 587, freq2 = 880, durationMs = 140) {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq1, ctx.currentTime);
            osc.frequency.setValueAtTime(freq2, ctx.currentTime + (durationMs / 2000));
            gain.gain.setValueAtTime(0.08, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + (durationMs / 1000));
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + (durationMs / 1000));
        } catch (_) {}
    }

    function updateSidebarRoomBadges(roomCounts) {
        if (!roomCounts || typeof roomCounts !== 'object') return;
        document.querySelectorAll('[data-voice-room-badge]').forEach((badge) => {
            const rk = badge.getAttribute('data-voice-room-badge') || '';
            const cnt = Number(roomCounts[rk] || 0);
            if (cnt > 0) {
                badge.textContent = `🟢 ${cnt} live`;
                badge.className = 'px-1.5 py-0.5 rounded-full text-[10px] font-bold shrink-0 bg-emerald-600 text-white animate-pulse';
            } else {
                badge.textContent = 'Beitreten';
                badge.className = 'px-1.5 py-0.5 rounded-full text-[10px] font-bold shrink-0 bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300';
            }
        });
    }

    function renderVoicePeers(peers) {
        const grid = document.getElementById('voicePeersGrid');
        if (!grid) return;
        grid.innerHTML = '';

        (peers || []).forEach((p) => {
            const uid = Number(p.user_id);
            const isSelf = (uid === selfUserId);
            const isSpk = isSelf ? voiceSpeaking : (Number(p.is_speaking) === 1);
            const isMut = isSelf ? voiceMuted : (Number(p.is_muted) === 1);
            const isScr = Number(p.is_sharing_screen) === 1;
            const pMode = p.mode === 'ptt' ? '📻 Funk' : '⚡ Live';
            const initial = String(p.username || '?').trim().charAt(0).toUpperCase() || '?';

            const card = document.createElement('div');
            card.className = `flex items-center gap-2.5 p-2 rounded-xl border transition-all ${
                isSpk
                    ? 'bg-emerald-50/90 dark:bg-emerald-950/60 border-emerald-500 ring-2 ring-emerald-500/40'
                    : 'bg-white/90 dark:bg-slate-800/90 border-slate-200 dark:border-slate-700'
            }`;

            card.innerHTML = `
                <div class="w-8 h-8 rounded-xl ${isSpk ? 'bg-emerald-600 ring-2 ring-emerald-300' : 'bg-indigo-600'} text-white flex items-center justify-center text-xs font-bold shrink-0 transition-all">
                    ${escapeHtml(initial)}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1">
                        <span class="text-xs font-bold text-slate-900 dark:text-white truncate">${escapeHtml(p.username)}${isSelf ? ' (Ich)' : ''}</span>
                    </div>
                    <div class="flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400">
                        <span>${isMut ? '🔇 Stumm' : (isSpk ? '🟢 Spricht' : '🎤 Bereit')}</span>
                        <span>&bull;</span>
                        <span>${pMode}</span>
                        ${isScr ? '<span class="text-indigo-600 dark:text-indigo-400 font-bold">🖥️</span>' : ''}
                    </div>
                </div>
            `;
            grid.appendChild(card);
        });
    }

    async function setupLocalAudioAnalysis(stream) {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (voiceAudioCtx) {
                try { await voiceAudioCtx.close(); } catch (_) {}
            }
            voiceAudioCtx = new Ctx();
            const source = voiceAudioCtx.createMediaStreamSource(stream);
            voiceAnalyser = voiceAudioCtx.createAnalyser();
            voiceAnalyser.fftSize = 256;
            source.connect(voiceAnalyser);
            const dataArr = new Uint8Array(voiceAnalyser.frequencyBinCount);

            if (voiceLevelTimer) clearInterval(voiceLevelTimer);
            voiceLevelTimer = setInterval(() => {
                if (!voiceAnalyser || voiceMuted || (voiceTransportMode === 'ptt' && !isPttRecording)) {
                    voiceSpeaking = false;
                    return;
                }
                voiceAnalyser.getByteFrequencyData(dataArr);
                let sum = 0;
                for (let i = 0; i < dataArr.length; i++) sum += dataArr[i];
                const avg = sum / dataArr.length;
                voiceSpeaking = avg > 16;
            }, 250);
        } catch (_) {}
    }

    async function sendVoiceSignal(toUserId, sigType, payloadObjOrStr) {
        if (!activeVoiceRoom) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('room_key', activeVoiceRoom);
        fd.append('to_user_id', String(toUserId));
        fd.append('sig_type', sigType);
        fd.append('payload', typeof payloadObjOrStr === 'string' ? payloadObjOrStr : JSON.stringify(payloadObjOrStr));
        try {
            await fetch('messages.php?ajax=voice_signal', { method: 'POST', body: fd });
        } catch (_) {}
    }

    function closePeerConnection(peerId) {
        const pc = peerConnections.get(peerId);
        if (pc) {
            try { pc.close(); } catch (_) {}
            peerConnections.delete(peerId);
        }
        pendingIceCandidates.delete(peerId);
        const aud = document.getElementById('voiceRemoteAudio_' + peerId);
        if (aud) aud.remove();
    }

    function getOrCreatePeerConnection(peerId) {
        if (peerConnections.has(peerId)) {
            return peerConnections.get(peerId);
        }
        const pc = new RTCPeerConnection(rtcConfig);
        peerConnections.set(peerId, pc);

        if (voiceLocalStream) {
            voiceLocalStream.getTracks().forEach((track) => {
                pc.addTrack(track, voiceLocalStream);
            });
        }
        if (voiceScreenStream) {
            voiceScreenStream.getVideoTracks().forEach((vTrack) => {
                pc.addTrack(vTrack, voiceScreenStream);
            });
        }

        pc.onicecandidate = (ev) => {
            if (ev.candidate) {
                sendVoiceSignal(peerId, 'ice', ev.candidate);
            }
        };

        pc.ontrack = (ev) => {
            const stream = (ev.streams && ev.streams[0]) ? ev.streams[0] : new MediaStream([ev.track]);
            if (ev.track.kind === 'video') {
                const screenWrap = document.getElementById('voiceScreenShareWrap');
                const screenVid = document.getElementById('voiceRemoteScreenVideo');
                if (screenWrap && screenVid) {
                    screenVid.srcObject = stream;
                    screenWrap.classList.remove('hidden');
                    ev.track.onended = () => {
                        screenWrap.classList.add('hidden');
                    };
                }
            } else if (ev.track.kind === 'audio') {
                let aud = document.getElementById('voiceRemoteAudio_' + peerId);
                if (!aud) {
                    aud = document.createElement('audio');
                    aud.id = 'voiceRemoteAudio_' + peerId;
                    aud.autoplay = true;
                    aud.playsInline = true;
                    const containerEl = document.getElementById('voiceRemoteAudioContainer');
                    if (containerEl) containerEl.appendChild(aud);
                }
                aud.srcObject = stream;
                aud.play().catch(() => {});
            }
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                closePeerConnection(peerId);
            }
        };

        return pc;
    }

    async function syncMeshConnectionsWithPeers(peers) {
        if (voiceTransportMode !== 'webrtc') return;
        const activeIds = new Set();

        for (const p of (peers || [])) {
            const pid = Number(p.user_id);
            if (pid === selfUserId) continue;
            activeIds.add(pid);

            // Deterministischer Verbindungsaufbau: Die kleinere User-ID sendet das Offer
            if (!peerConnections.has(pid) && selfUserId < pid) {
                try {
                    const pc = getOrCreatePeerConnection(pid);
                    const offer = await pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true });
                    await pc.setLocalDescription(offer);
                    await sendVoiceSignal(pid, 'offer', offer);
                } catch (_) {}
            }
        }

        // Ausgetretene Teilnehmer bereinigen
        for (const existingPid of Array.from(peerConnections.keys())) {
            if (!activeIds.has(existingPid)) {
                closePeerConnection(existingPid);
            }
        }
    }

    async function handleIncomingVoiceSignal(sig) {
        const fromId = Number(sig.from_user_id);
        const sType = String(sig.sig_type || '');
        if (fromId === selfUserId) return;

        if (sType === 'hangup') {
            closePeerConnection(fromId);
            return;
        }

        if (sType === 'ptt_audio') {
            // 📻 Push-to-Talk Sprachpaket sofort abspielen
            try {
                playVoiceTone(740, 880, 70);
                const audio = new Audio(String(sig.payload || ''));
                const sub = document.getElementById('voiceCallStatusSub');
                if (sub) {
                    sub.textContent = `📻 Funkspruch von ${sig.from_username || 'Teilnehmer'} wird abgespielt...`;
                }
                audio.onended = () => {
                    if (sub) sub.textContent = '📻 Funk-Modus bereit – Sprechtaste gedrückt halten zum Antworten';
                };
                await audio.play();
            } catch (_) {}
            return;
        }

        if (sType === 'offer') {
            try {
                const offerDesc = JSON.parse(sig.payload);
                const pc = getOrCreatePeerConnection(fromId);
                await pc.setRemoteDescription(new RTCSessionDescription(offerDesc));
                const queued = pendingIceCandidates.get(fromId) || [];
                for (const c of queued) {
                    try { await pc.addIceCandidate(new RTCIceCandidate(c)); } catch (_) {}
                }
                pendingIceCandidates.delete(fromId);
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await sendVoiceSignal(fromId, 'answer', answer);
            } catch (_) {}
        } else if (sType === 'answer') {
            try {
                const ansDesc = JSON.parse(sig.payload);
                const pc = peerConnections.get(fromId);
                if (pc && pc.signalingState === 'have-local-offer') {
                    await pc.setRemoteDescription(new RTCSessionDescription(ansDesc));
                    const queued = pendingIceCandidates.get(fromId) || [];
                    for (const c of queued) {
                        try { await pc.addIceCandidate(new RTCIceCandidate(c)); } catch (_) {}
                    }
                    pendingIceCandidates.delete(fromId);
                }
            } catch (_) {}
        } else if (sType === 'ice') {
            try {
                const cand = JSON.parse(sig.payload);
                const pc = peerConnections.get(fromId);
                if (pc && pc.remoteDescription && pc.remoteDescription.type) {
                    await pc.addIceCandidate(new RTCIceCandidate(cand));
                } else {
                    if (!pendingIceCandidates.has(fromId)) pendingIceCandidates.set(fromId, []);
                    pendingIceCandidates.get(fromId).push(cand);
                }
            } catch (_) {}
        }
    }

    async function pollVoiceRoomState() {
        if (!activeVoiceRoom) return;
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('room_key', activeVoiceRoom);
        fd.append('since_sig_id', String(voiceLastSigId));
        fd.append('is_muted', voiceMuted ? '1' : '0');
        fd.append('is_speaking', voiceSpeaking ? '1' : '0');
        fd.append('is_sharing_screen', voiceScreenStream ? '1' : '0');
        fd.append('mode', voiceTransportMode);

        try {
            const res = await fetch('messages.php?ajax=voice_poll', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data || !data.success) return;

            if (data.room_counts) {
                updateSidebarRoomBadges(data.room_counts);
            }
            if (Array.isArray(data.peers)) {
                renderVoicePeers(data.peers);
                await syncMeshConnectionsWithPeers(data.peers);
            }
            if (Array.isArray(data.signals)) {
                for (const s of data.signals) {
                    const sid = Number(s.id || 0);
                    if (sid > voiceLastSigId) voiceLastSigId = sid;
                    await handleIncomingVoiceSignal(s);
                }
            }
        } catch (_) {}
    }

    function updateVoiceModeUI() {
        const modeBadge = document.getElementById('voiceCallModeBadge');
        const pttBar = document.getElementById('voicePttControls');
        const switchIcon = document.getElementById('voiceSwitchModeIcon');
        const switchLbl = document.getElementById('voiceSwitchModeLabel');
        const sub = document.getElementById('voiceCallStatusSub');

        if (voiceTransportMode === 'ptt') {
            if (modeBadge) {
                modeBadge.textContent = '📻 Funk (Push-to-Talk)';
                modeBadge.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-900/70 text-indigo-800 dark:text-indigo-200';
            }
            if (pttBar) {
                pttBar.classList.remove('hidden');
                pttBar.classList.add('flex');
            }
            if (switchIcon) switchIcon.textContent = '⚡';
            if (switchLbl) switchLbl.textContent = 'Auf WebRTC-Live umschalten';
            if (sub) sub.textContent = '📻 Funk-Modus (100 % Firewall- & STRATO-sicher, ideal auch für große Gruppen)';
        } else {
            if (modeBadge) {
                modeBadge.textContent = '⚡ WebRTC-Direkt';
                modeBadge.className = 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/70 text-emerald-800 dark:text-emerald-200';
            }
            if (pttBar) {
                pttBar.classList.add('hidden');
                pttBar.classList.remove('flex');
            }
            if (switchIcon) switchIcon.textContent = '📻';
            if (switchLbl) switchLbl.textContent = 'Auf Funk umschalten';
            if (sub) sub.textContent = '⚡ Direktes Browser-zu-Browser Echtzeit-Audio (null STRATO-Serverlast)';
        }
    }

    window.joinVoiceCallRoom = async function(roomKey, roomTitle, initialMode = 'webrtc', ringPartner = false) {
        if (!roomKey) return;

        // Auf Mobilgeräten die rechte Konversationsspalte sichtbar schalten
        if (convPane && convPane.classList.contains('hidden')) {
            convPane.classList.remove('hidden');
            convPane.classList.add('flex');
            const sidePane = document.getElementById('chatSidebarPane');
            if (sidePane && window.matchMedia('(max-width: 1023px)').matches) {
                sidePane.classList.add('hidden');
            }
        }

        // Mikrofon anfordern (falls verfügbar)
        if (!voiceLocalStream && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            try {
                voiceLocalStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    }
                });
                await setupLocalAudioAnalysis(voiceLocalStream);
            } catch (_) {
                // Falls kein Mikrofon vorhanden ist, kann man im Raum trotzdem zuhören
                voiceMuted = true;
            }
        }

        voiceTransportMode = (initialMode === 'ptt') ? 'ptt' : 'webrtc';
        activeVoiceRoom = roomKey;
        activeVoiceTitle = roomTitle || roomKey;

        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('room_key', roomKey);
        fd.append('mode', voiceTransportMode);
        fd.append('ring', ringPartner ? '1' : '0');

        try {
            const res = await fetch('messages.php?ajax=voice_join', { method: 'POST', body: fd });
            const data = await res.json();
            if (!data || !data.success) {
                alert((data && data.error) ? data.error : 'Sprachraum konnte nicht betreten werden.');
                return;
            }

            voiceLastSigId = Number(data.last_sig_id || 0);
            activeVoiceTitle = data.room_title || activeVoiceTitle;

            const cockpit = document.getElementById('voiceCallCockpit');
            const titleEl = document.getElementById('voiceCallRoomTitle');
            if (titleEl) titleEl.textContent = activeVoiceTitle;
            if (cockpit) cockpit.classList.remove('hidden');

            updateVoiceModeUI();
            renderVoicePeers(data.peers || []);
            await syncMeshConnectionsWithPeers(data.peers || []);
            playVoiceTone(523, 784, 150);

            voiceCallStartedAt = Date.now();
            if (voiceCallClockTimer) clearInterval(voiceCallClockTimer);
            voiceCallClockTimer = setInterval(() => {
                const sec = Math.floor((Date.now() - voiceCallStartedAt) / 1000);
                const badge = document.getElementById('voiceCallTimerBadge');
                if (badge) badge.textContent = formatRecordSeconds(sec);
            }, 1000);

            if (voicePollTimer) clearInterval(voicePollTimer);
            voicePollTimer = setInterval(pollVoiceRoomState, 2200);
        } catch (_) {
            alert('Verbindungsfehler beim Betreten des Sprachraums.');
        }
    };

    window.leaveVoiceCallRoom = async function() {
        const prevRoom = activeVoiceRoom;
        activeVoiceRoom = '';
        if (voicePollTimer) {
            clearInterval(voicePollTimer);
            voicePollTimer = null;
        }
        if (voiceCallClockTimer) {
            clearInterval(voiceCallClockTimer);
            voiceCallClockTimer = null;
        }
        if (voiceLevelTimer) {
            clearInterval(voiceLevelTimer);
            voiceLevelTimer = null;
        }
        for (const pid of Array.from(peerConnections.keys())) {
            closePeerConnection(pid);
        }
        if (voiceLocalStream) {
            voiceLocalStream.getTracks().forEach((t) => t.stop());
            voiceLocalStream = null;
        }
        if (voiceScreenStream) {
            voiceScreenStream.getTracks().forEach((t) => t.stop());
            voiceScreenStream = null;
        }
        const screenWrap = document.getElementById('voiceScreenShareWrap');
        if (screenWrap) screenWrap.classList.add('hidden');
        const cockpit = document.getElementById('voiceCallCockpit');
        if (cockpit) cockpit.classList.add('hidden');

        playVoiceTone(659, 440, 140);

        if (prevRoom) {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('room_key', prevRoom);
            try {
                await fetch('messages.php?ajax=voice_leave', { method: 'POST', body: fd });
            } catch (_) {}
        }
    };

    window.toggleVoiceMute = function() {
        voiceMuted = !voiceMuted;
        if (voiceLocalStream) {
            voiceLocalStream.getAudioTracks().forEach((t) => {
                t.enabled = !voiceMuted;
            });
        }
        const icon = document.getElementById('voiceMuteIcon');
        const lbl = document.getElementById('voiceMuteLabel');
        const btn = document.getElementById('btnVoiceMuteToggle');
        if (icon) icon.textContent = voiceMuted ? '🔇' : '🎤';
        if (lbl) lbl.textContent = voiceMuted ? 'Stumm' : 'Mikro an';
        if (btn) {
            btn.classList.toggle('bg-red-100', voiceMuted);
            btn.classList.toggle('text-red-700', voiceMuted);
        }
        pollVoiceRoomState();
    };

    window.toggleVoiceScreenShare = async function() {
        if (voiceScreenStream) {
            voiceScreenStream.getTracks().forEach((t) => t.stop());
            voiceScreenStream = null;
            const lbl = document.getElementById('voiceScreenLabel');
            if (lbl) lbl.textContent = 'Bildschirm';
            pollVoiceRoomState();
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            alert('Bildschirmfreigabe wird von diesem Browser/Gerät nicht unterstützt.');
            return;
        }
        try {
            voiceScreenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            const vTrack = voiceScreenStream.getVideoTracks()[0];
            if (vTrack) {
                const lbl = document.getElementById('voiceScreenLabel');
                if (lbl) lbl.textContent = 'Teilen beenden';
                for (const [pid, pc] of peerConnections.entries()) {
                    try {
                        pc.addTrack(vTrack, voiceScreenStream);
                        const offer = await pc.createOffer();
                        await pc.setLocalDescription(offer);
                        await sendVoiceSignal(pid, 'offer', offer);
                    } catch (_) {}
                }
                vTrack.onended = () => {
                    voiceScreenStream = null;
                    if (lbl) lbl.textContent = 'Bildschirm';
                    pollVoiceRoomState();
                };
            }
            pollVoiceRoomState();
        } catch (_) {}
    };

    window.switchVoiceTransportMode = function() {
        voiceTransportMode = (voiceTransportMode === 'webrtc') ? 'ptt' : 'webrtc';
        if (voiceTransportMode === 'ptt') {
            for (const pid of Array.from(peerConnections.keys())) {
                closePeerConnection(pid);
            }
        }
        updateVoiceModeUI();
        pollVoiceRoomState();
    };

    // 📻 Push-to-Talk (Funkgerät): Sprechtaste drücken & loslassen
    window.startPttTransmission = async function(ev) {
        if (ev) ev.preventDefault();
        if (!activeVoiceRoom || isPttRecording) return;
        if (!voiceLocalStream && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            try {
                voiceLocalStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (_) {
                alert('Bitte erlauben Sie den Mikrofonzugriff für den Funk-Modus.');
                return;
            }
        }
        if (!voiceLocalStream || typeof MediaRecorder === 'undefined') return;

        isPttRecording = true;
        voiceSpeaking = true;
        pttChunks = [];
        playVoiceTone(880, 1046, 60);

        const btn = document.getElementById('btnPushToTalkHold');
        const hint = document.getElementById('voicePttHint');
        if (btn) {
            btn.textContent = '🔴 Sende Funkspruch... (Loslassen zum Senden)';
            btn.classList.add('ring-4', 'ring-red-400', 'bg-red-600');
        }
        if (hint) hint.textContent = '🎙️ Du sprichst gerade an alle im Raum...';

        const mimeCandidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        let chosenMime = '';
        for (const c of mimeCandidates) {
            if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(c)) {
                chosenMime = c;
                break;
            }
        }

        pttRecorder = chosenMime
            ? new MediaRecorder(voiceLocalStream, { mimeType: chosenMime, audioBitsPerSecond: 24000 })
            : new MediaRecorder(voiceLocalStream);

        pttRecorder.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) pttChunks.push(e.data);
        };

        pttRecorder.onstop = () => {
            isPttRecording = false;
            voiceSpeaking = false;
            if (btn) {
                btn.textContent = '🎙️ Gedrückt halten zum Sprechen';
                btn.classList.remove('ring-4', 'ring-red-400', 'bg-red-600');
            }
            if (hint) hint.textContent = 'Funkspruch wird an alle im Raum übertragen...';

            if (pttChunks.length === 0 || !activeVoiceRoom) return;
            const blob = new Blob(pttChunks, { type: pttRecorder.mimeType || 'audio/webm' });
            pttChunks = [];

            const reader = new FileReader();
            reader.onloadend = async () => {
                const base64Audio = String(reader.result || '');
                if (!base64Audio.startsWith('data:audio/')) return;
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('room_key', activeVoiceRoom);
                fd.append('audio_data', base64Audio);
                try {
                    await fetch('messages.php?ajax=voice_ptt', { method: 'POST', body: fd });
                    if (hint) hint.textContent = '✅ Funkspruch gesendet! Halte die Taste (oder Leertaste) zum Sprechen.';
                } catch (_) {}
            };
            reader.readAsDataURL(blob);
        };

        pttRecorder.start();
    };

    window.stopPttTransmission = function(ev) {
        if (ev) ev.preventDefault();
        if (!isPttRecording || !pttRecorder) return;
        if (pttRecorder.state !== 'inactive') {
            pttRecorder.stop();
        }
    };

    // Leertaste als Push-to-Talk-Taste im Funk-Modus (sofern man nicht gerade im Textfeld tippt)
    window.addEventListener('keydown', (e) => {
        if (e.code === 'Space' && activeVoiceRoom && voiceTransportMode === 'ptt') {
            const tag = (document.activeElement && document.activeElement.tagName) ? document.activeElement.tagName.toLowerCase() : '';
            if (tag !== 'textarea' && tag !== 'input' && tag !== 'select') {
                e.preventDefault();
                if (!isPttRecording) window.startPttTransmission(null);
            }
        }
    });
    window.addEventListener('keyup', (e) => {
        if (e.code === 'Space' && isPttRecording) {
            e.preventDefault();
            window.stopPttTransmission(null);
        }
    });

    // Beim Schließen des Browser-Tabs sauber aus dem Sprachraum abmelden
    window.addEventListener('beforeunload', () => {
        if (activeVoiceRoom && navigator.sendBeacon) {
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('room_key', activeVoiceRoom);
            navigator.sendBeacon('messages.php?ajax=voice_leave', fd);
        }
    });

    // Falls per Klick auf "Anruf annehmen" (?autocall=1&room=...) geöffnet, direkt beitreten!
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autocall') === '1') {
        const autoRoom = urlParams.get('room') || '';
        const autoMode = urlParams.get('mode') || 'webrtc';
        const autoTitle = urlParams.get('title') || 'Sprachanruf';
        if (autoRoom) {
            setTimeout(() => {
                window.joinVoiceCallRoom(autoRoom, autoTitle, autoMode, false);
            }, 300);
        }
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
