<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_calendar'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('warning', 'Das Kalender-Modul ist derzeit durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

/**
 * Hilfsfunktion: Escaping für iCalendar (.ics) Textfelder nach RFC 5545.
 */
function icsEscape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    return $text;
}

/**
 * Hilfsfunktion: Erzeugt eine RFC-5545-konforme .ics-Ausgabe aus einer Liste von Termin-Datensätzen.
 *
 * @param array<int, array<string, mixed>> $events
 */
function buildIcsCalendarContent(array $events, string $calName = 'MiniCloud Kalender'): string
{
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//MiniCloud//Calendar 1.0//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . icsEscape($calName),
    ];

    $nowUtc = gmdate('Ymd\THis\Z');

    foreach ($events as $ev) {
        $id = (int)($ev['id'] ?? 0);
        $uid = 'minicloud-event-' . $id . '-' . md5((string)($ev['created_at'] ?? '') . '-' . $id) . '@minicloud';
        $startTs = strtotime((string)$ev['start_at']) ?: time();
        $endTs   = strtotime((string)$ev['end_at']) ?: ($startTs + 3600);
        $allDay  = (int)($ev['all_day'] ?? 0) === 1;

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $uid;
        $lines[] = 'DTSTAMP:' . $nowUtc;

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', $startTs);
            // Bei ganztägigen ICS-Terminen ist DTEND exklusiv (+1 Tag)
            $endDayTs = max($startTs, $endTs) + 86400;
            $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', $endDayTs);
        } else {
            $lines[] = 'DTSTART:' . date('Ymd\THis', $startTs);
            $lines[] = 'DTEND:' . date('Ymd\THis', max($startTs, $endTs));
        }

        $rec = (string)($ev['recurrence'] ?? 'none');
        if ($rec === 'daily') {
            $lines[] = 'RRULE:FREQ=DAILY';
        } elseif ($rec === 'weekly') {
            $lines[] = 'RRULE:FREQ=WEEKLY';
        } elseif ($rec === 'monthly') {
            $lines[] = 'RRULE:FREQ=MONTHLY';
        } elseif ($rec === 'yearly') {
            $lines[] = 'RRULE:FREQ=YEARLY';
        }

        $lines[] = 'SUMMARY:' . icsEscape((string)($ev['title'] ?? 'Termin'));
        if (trim((string)($ev['location'] ?? '')) !== '') {
            $lines[] = 'LOCATION:' . icsEscape((string)$ev['location']);
        }
        if (trim((string)($ev['description'] ?? '')) !== '') {
            $lines[] = 'DESCRIPTION:' . icsEscape((string)$ev['description']);
        }
        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Lädt alle Gruppen-IDs, in denen ein Benutzer Mitglied ist.
 *
 * @return int[]
 */
function getUserGroupIds(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare('SELECT group_id FROM group_members WHERE user_id = :uid');
    $stmt->execute([':uid' => $uid]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Lädt alle für einen Benutzer sichtbaren Basis-Termine aus der Datenbank.
 *
 * @return array<int, array<string, mixed>>
 */
function getAccessibleEventsForUser(PDO $pdo, int $uid): array
{
    $stmt = $pdo->prepare(
        "SELECT
            ce.*,
            u.username AS owner_username,
            g.name AS group_name,
            su.username AS shared_username,
            f.original_name AS attached_file_name,
            f.is_encrypted AS attached_file_encrypted,
            CASE
                WHEN ce.user_id = :uid_self THEN 1
                WHEN ce.group_id IS NOT NULL AND ce.group_can_edit = 1 AND EXISTS (
                    SELECT 1 FROM group_members gm WHERE gm.group_id = ce.group_id AND gm.user_id = :uid_gm_edit
                ) THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM calendar_shares cs
                    WHERE cs.owner_user_id = ce.user_id
                      AND cs.can_edit = 1
                      AND (
                          cs.target_user_id = :uid_cs_edit
                          OR (cs.target_group_id IS NOT NULL AND EXISTS (
                              SELECT 1 FROM group_members gm2 WHERE gm2.group_id = cs.target_group_id AND gm2.user_id = :uid_cs_grp_edit
                          ))
                      )
                ) THEN 1
                ELSE 0
            END AS can_edit_event,
            CASE
                WHEN ce.user_id = :uid_self_del THEN 1
                WHEN EXISTS (
                    SELECT 1 FROM calendar_shares csd
                    WHERE csd.owner_user_id = ce.user_id
                      AND csd.can_delete = 1
                      AND (
                          csd.target_user_id = :uid_cs_del
                          OR (csd.target_group_id IS NOT NULL AND EXISTS (
                              SELECT 1 FROM group_members gm_del WHERE gm_del.group_id = csd.target_group_id AND gm_del.user_id = :uid_cs_grp_del
                          ))
                      )
                ) THEN 1
                ELSE 0
            END AS can_delete_event
         FROM calendar_events ce
         JOIN users u ON u.id = ce.user_id
         LEFT JOIN `groups` g ON g.id = ce.group_id
         LEFT JOIN users su ON su.id = ce.shared_user_id
         LEFT JOIN files f ON f.id = ce.attached_file_id AND f.deleted_at IS NULL
         WHERE
            ce.user_id = :uid_own
            OR (ce.shared_user_id = :uid_shared)
            OR (ce.group_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM group_members gm WHERE gm.group_id = ce.group_id AND gm.user_id = :uid_gm
            ))
            OR (
                ce.is_private = 0
                AND EXISTS (
                    SELECT 1 FROM calendar_shares cs
                    WHERE cs.owner_user_id = ce.user_id
                      AND (
                          cs.target_user_id = :uid_cs
                          OR (cs.target_group_id IS NOT NULL AND EXISTS (
                              SELECT 1 FROM group_members gm3 WHERE gm3.group_id = cs.target_group_id AND gm3.user_id = :uid_cs_grp
                          ))
                      )
                )
            )
         ORDER BY ce.start_at ASC"
    );
    $stmt->execute([
        ':uid_self'        => $uid,
        ':uid_gm_edit'     => $uid,
        ':uid_cs_edit'     => $uid,
        ':uid_cs_grp_edit' => $uid,
        ':uid_self_del'    => $uid,
        ':uid_cs_del'      => $uid,
        ':uid_cs_grp_del'  => $uid,
        ':uid_own'         => $uid,
        ':uid_shared'      => $uid,
        ':uid_gm'          => $uid,
        ':uid_cs'          => $uid,
        ':uid_cs_grp'      => $uid,
    ]);
    $events = $stmt->fetchAll();

    // Geburtstage aus dem Adressbuch (contacts) automatisch als jährliche Ganztagstermine ergänzen
    try {
        $stmtBdays = $pdo->prepare(
            "SELECT c.*, u.username AS owner_username, g.name AS group_name, su.username AS shared_username
             FROM `contacts` c
             JOIN `users` u ON u.`id` = c.`user_id`
             LEFT JOIN `groups` g ON g.`id` = c.`group_id`
             LEFT JOIN `users` su ON su.`id` = c.`shared_user_id`
             WHERE c.`birthday` IS NOT NULL
               AND (
                   c.`user_id` = :b_uid
                   OR c.`shared_user_id` = :b_suid
                   OR (c.`group_id` IS NOT NULL AND EXISTS (
                       SELECT 1 FROM `group_members` gm WHERE gm.`group_id` = c.`group_id` AND gm.`user_id` = :b_gmuid
                   ))
               )"
        );
        $stmtBdays->execute([
            ':b_uid'   => $uid,
            ':b_suid'  => $uid,
            ':b_gmuid' => $uid,
        ]);
        foreach ($stmtBdays->fetchAll() as $contact) {
            $bdayStr = (string)$contact['birthday'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdayStr)) {
                continue;
            }
            $cName = trim($contact['first_name'] . ' ' . $contact['last_name']);
            if ($cName === '') {
                $cName = (string)$contact['organization'];
            }
            $birthYear = (int)substr($bdayStr, 0, 4);
            $events[] = [
                'id'                      => -100000 - (int)$contact['id'],
                'user_id'                 => (int)$contact['user_id'],
                'title'                   => '🎂 Geburtstag: ' . $cName . ($birthYear > 1900 ? ' (*' . $birthYear . ')' : ''),
                'description'             => 'Automatischer Geburtstagseintrag aus dem Adressbuch (' . date('d.m.Y', strtotime($bdayStr)) . ').',
                'location'                => '',
                'start_at'                => $bdayStr . ' 00:00:00',
                'end_at'                  => $bdayStr . ' 23:59:59',
                'all_day'                 => 1,
                'recurrence'              => 'yearly',
                'color'                   => '#ec4899',
                'is_private'              => 0,
                'shared_user_id'          => $contact['shared_user_id'],
                'group_id'                => $contact['group_id'],
                'group_can_edit'          => 0,
                'attached_file_id'        => null,
                'created_at'              => $contact['created_at'],
                'owner_username'          => $contact['owner_username'],
                'group_name'              => $contact['group_name'],
                'shared_username'         => $contact['shared_username'],
                'attached_file_name'      => null,
                'attached_file_encrypted' => 0,
                'can_edit_event'          => 0,
                'can_delete_event'        => 0,
            ];
        }
    } catch (Throwable $e) {
        // Falls Tabelle contacts noch nicht initialisiert ist, ignorieren
    }

    return $events;
}

// --- 1. Öffentlicher .ics-Abo-Feed über persönliches Token (z.B. für Smartphone / Thunderbird) ---
if (isset($_GET['ics_token']) && is_string($_GET['ics_token'])) {
    $token = trim($_GET['ics_token']);
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
        http_response_code(403);
        exit('Ungültiges Kalender-Token.');
    }

    ensureUserSecurityColumns($pdo);
    $stmtTokenUser = $pdo->prepare('SELECT id, username, is_locked, calendar_token FROM users WHERE calendar_token = :t LIMIT 1');
    $stmtTokenUser->execute([':t' => $token]);
    $tokenUser = $stmtTokenUser->fetch();

    if (!$tokenUser || (int)($tokenUser['is_locked'] ?? 0) === 1 || !hash_equals((string)$tokenUser['calendar_token'], $token)) {
        http_response_code(404);
        exit('Kalender-Feed nicht gefunden.');
    }

    $feedEvents = getAccessibleEventsForUser($pdo, (int)$tokenUser['id']);
    $icsContent = buildIcsCalendarContent($feedEvents, $appSettings['app_title'] . ' – ' . (string)$tokenUser['username']);

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="minicloud-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$tokenUser['username']) . '.ics"');
    header('Cache-Control: no-cache, must-revalidate');
    echo $icsContent;
    exit;
}

// Alle weiteren Funktionen erfordern eine Anmeldung
requireLogin();
$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];

$allowedColors = [
    '#2563eb' => 'Blau',
    '#10b981' => 'Smaragdgrün',
    '#f59e0b' => 'Bernstein / Orange',
    '#ef4444' => 'Rot',
    '#8b5cf6' => 'Violett',
    '#06b6d4' => 'Türkis',
    '#ec4899' => 'Pink',
];

$allowedRecurrences = [
    'none'    => 'Einmalig (keine Wiederholung)',
    'daily'   => 'Täglich',
    'weekly'  => 'Wöchentlich',
    'monthly' => 'Monatlich',
    'yearly'  => 'Jährlich (z.B. Geburtstag / Jahrestag)',
];

// Kalender anderer Benutzer laden, in die der aktuelle Benutzer Termine eintragen darf (can_edit = 1)
$stmtWritableCalendars = $pdo->prepare(
    "SELECT DISTINCT u.id, u.username
     FROM calendar_shares cs
     JOIN users u ON u.id = cs.owner_user_id
     WHERE cs.owner_user_id <> :uid1
       AND cs.can_edit = 1
       AND (
           cs.target_user_id = :uid2
           OR (cs.target_group_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM group_members gm WHERE gm.group_id = cs.target_group_id AND gm.user_id = :uid3
           ))
       )
     ORDER BY u.username ASC"
);
$stmtWritableCalendars->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
$writableCalendars = $stmtWritableCalendars->fetchAll();
$writableCalendarIds = array_map(static fn(array $r): int => (int)$r['id'], $writableCalendars);

// --- 2. Direkter .ics-Download (Einzeltermin oder gesamter Kalender) ---
if (isset($_GET['export_ics'])) {
    $allAccessible = getAccessibleEventsForUser($pdo, $userId);
    $singleId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

    if ($singleId > 0) {
        $filtered = array_values(array_filter($allAccessible, static fn(array $e): bool => (int)$e['id'] === $singleId));
        if (empty($filtered)) {
            setFlash('error', 'Termin nicht gefunden oder keine Berechtigung.');
            header('Location: calendar.php');
            exit;
        }
        $ics = buildIcsCalendarContent($filtered, (string)$filtered[0]['title']);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$filtered[0]['title']) ?: 'termin';
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '.ics"');
        echo $ics;
        exit;
    }

    $ics = buildIcsCalendarContent($allAccessible, $appSettings['app_title'] . ' – ' . $username);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="minicloud-kalender.ics"');
    echo $ics;
    exit;
}

// --- 3. POST-Aktionen verarbeiten ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: calendar.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $redirectYm = trim((string)($_POST['redirect_ym'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $redirectYm)) {
        $redirectYm = date('Y-m');
    }
    $redirectView = in_array($_POST['redirect_view'] ?? 'month', ['month', 'list'], true) ? (string)$_POST['redirect_view'] : 'month';
    $redirectUrl = 'calendar.php?ym=' . urlencode($redirectYm) . '&view=' . urlencode($redirectView);

    // A) Termin anlegen oder bearbeiten
    if ($action === 'save_event') {
        $eventId        = (int)($_POST['event_id'] ?? 0);
        $title          = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 190);
        $location       = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 190);
        $description    = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000);
        $startDate      = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
        $startTime      = trim((string)($_POST['start_time'] ?? '09:00'));
        $endDate        = trim((string)($_POST['end_date'] ?? $startDate));
        $endTime        = trim((string)($_POST['end_time'] ?? '10:00'));
        $allDay         = isset($_POST['all_day']) && $_POST['all_day'] === '1' ? 1 : 0;
        $recurrence     = (string)($_POST['recurrence'] ?? 'none');
        $color          = (string)($_POST['color'] ?? '#2563eb');
        $groupId        = (int)($_POST['group_id'] ?? 0);
        $groupCanEdit   = isset($_POST['group_can_edit']) && $_POST['group_can_edit'] === '1' ? 1 : 0;
        $sharedUserId   = (int)($_POST['shared_user_id'] ?? 0);
        $attachedFileId = (int)($_POST['attached_file_id'] ?? 0);
        $isPrivate      = isset($_POST['is_private']) && $_POST['is_private'] === '1' ? 1 : 0;
        $calendarOwner  = (int)($_POST['calendar_owner_id'] ?? $userId);

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Titel für den Termin ein.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        if (!array_key_exists($color, $allowedColors)) {
            $color = '#2563eb';
        }
        if (!array_key_exists($recurrence, $allowedRecurrences)) {
            $recurrence = 'none';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = $startDate;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            $startTime = '09:00';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $endTime = '10:00';
        }

        if ($allDay === 1) {
            $startAt = $startDate . ' 00:00:00';
            $endAt   = ($endDate < $startDate ? $startDate : $endDate) . ' 23:59:59';
        } else {
            $startAt = $startDate . ' ' . $startTime . ':00';
            $endAt   = $endDate . ' ' . $endTime . ':00';
            if (strtotime($endAt) < strtotime($startAt)) {
                $endAt = date('Y-m-d H:i:s', strtotime($startAt) + 3600);
            }
        }

        // Prüfen, ob die gewählte Gruppe zulässig ist
        $validGroupId = null;
        if ($groupId > 0) {
            if (isAdmin()) {
                $stmtGrp = $pdo->prepare('SELECT id FROM `groups` WHERE id = :gid LIMIT 1');
                $stmtGrp->execute([':gid' => $groupId]);
            } else {
                $stmtGrp = $pdo->prepare('SELECT group_id FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
                $stmtGrp->execute([':gid' => $groupId, ':uid' => $userId]);
            }
            if ($stmtGrp->fetch()) {
                $validGroupId = $groupId;
            }
        }

        // Prüfen, ob der direkt ausgewählte Benutzer das Teilen erlaubt (allow_user_sharing = 1)
        $validSharedUserId = null;
        if ($sharedUserId > 0 && $sharedUserId !== $userId) {
            $stmtSu = $pdo->prepare('SELECT id, allow_user_sharing FROM users WHERE id = :id LIMIT 1');
            $stmtSu->execute([':id' => $sharedUserId]);
            $suRow = $stmtSu->fetch();
            if ($suRow && (int)$suRow['allow_user_sharing'] === 1) {
                $validSharedUserId = $sharedUserId;
            } else {
                setFlash('error', 'Der ausgewählte Benutzer hat direkte Freigaben in seinen Datenschutzeinstellungen deaktiviert.');
                header('Location: ' . $redirectUrl);
                exit;
            }
        }

        // Prüfen, ob die angehängte Datei existiert und dem Benutzer zugänglich ist
        $validFileId = null;
        if ($attachedFileId > 0) {
            $stmtFile = $pdo->prepare('SELECT id, user_id, group_folder_id FROM files WHERE id = :fid AND deleted_at IS NULL LIMIT 1');
            $stmtFile->execute([':fid' => $attachedFileId]);
            $fRow = $stmtFile->fetch();
            if ($fRow) {
                if ((int)$fRow['user_id'] === $userId) {
                    $validFileId = $attachedFileId;
                } elseif ($fRow['group_folder_id'] !== null && canUserAccessGroupFolder($pdo, $userId, (int)$fRow['group_folder_id']) !== null) {
                    $validFileId = $attachedFileId;
                }
            }
        }

        if ($eventId > 0) {
            // Bestehenden Termin bearbeiten -> Berechtigung prüfen
            $accessible = getAccessibleEventsForUser($pdo, $userId);
            $existing = null;
            foreach ($accessible as $ev) {
                if ((int)$ev['id'] === $eventId) {
                    $existing = $ev;
                    break;
                }
            }

            if (!$existing || (int)($existing['can_edit_event'] ?? 0) !== 1) {
                setFlash('error', 'Sie haben keine Berechtigung, diesen Termin zu bearbeiten.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            // Wenn ein bestehender Termin eines anderen Benutzers bearbeitet wird, bleibt dessen user_id erhalten
            $stmtUpd = $pdo->prepare(
                'UPDATE calendar_events
                 SET title = :title,
                     description = :descr,
                     location = :loc,
                     start_at = :start_at,
                     end_at = :end_at,
                     all_day = :all_day,
                     recurrence = :rec,
                     color = :color,
                     group_id = :gid,
                     group_can_edit = :gce,
                     shared_user_id = :suid,
                     attached_file_id = :fid,
                     is_private = :priv
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':title'    => $title,
                ':descr'    => $description !== '' ? $description : null,
                ':loc'      => $location !== '' ? $location : null,
                ':start_at' => $startAt,
                ':end_at'   => $endAt,
                ':all_day'  => $allDay,
                ':rec'      => $recurrence,
                ':color'    => $color,
                ':gid'      => $validGroupId,
                ':gce'      => $validGroupId !== null ? $groupCanEdit : 0,
                ':suid'     => $validSharedUserId,
                ':fid'      => $validFileId,
                ':priv'     => $isPrivate,
                ':id'       => $eventId,
            ]);
            setFlash('success', sprintf('Termin „%s“ wurde aktualisiert.', $title));
        } else {
            // Neuen Termin anlegen (entweder im eigenen Kalender oder in einem freigegebenen Kalender mit Schreibrecht)
            $ownerIdToUse = $userId;
            if ($calendarOwner !== $userId && in_array($calendarOwner, $writableCalendarIds, true)) {
                $ownerIdToUse = $calendarOwner;
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO calendar_events
                    (user_id, title, description, location, start_at, end_at, all_day, recurrence, color, group_id, group_can_edit, shared_user_id, attached_file_id, is_private)
                 VALUES
                    (:uid, :title, :descr, :loc, :start_at, :end_at, :all_day, :rec, :color, :gid, :gce, :suid, :fid, :priv)'
            );
            $stmtIns->execute([
                ':uid'      => $ownerIdToUse,
                ':title'    => $title,
                ':descr'    => $description !== '' ? $description : null,
                ':loc'      => $location !== '' ? $location : null,
                ':start_at' => $startAt,
                ':end_at'   => $endAt,
                ':all_day'  => $allDay,
                ':rec'      => $recurrence,
                ':color'    => $color,
                ':gid'      => $validGroupId,
                ':gce'      => $validGroupId !== null ? $groupCanEdit : 0,
                ':suid'     => $validSharedUserId,
                ':fid'      => $validFileId,
                ':priv'     => $isPrivate,
            ]);
            setFlash('success', sprintf('Termin „%s“ wurde eingetragen.', $title));
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    // B) Termin löschen
    if ($action === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            $accessible = getAccessibleEventsForUser($pdo, $userId);
            $existing = null;
            foreach ($accessible as $ev) {
                if ((int)$ev['id'] === $eventId) {
                    $existing = $ev;
                    break;
                }
            }
            if ($existing && (int)($existing['can_delete_event'] ?? 0) === 1) {
                $pdo->prepare('DELETE FROM calendar_events WHERE id = :id')->execute([':id' => $eventId]);
                setFlash('success', sprintf('Termin „%s“ wurde gelöscht.', (string)$existing['title']));
            } else {
                setFlash('error', 'Sie haben keine Berechtigung, diesen Termin zu löschen.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // C) Eigenen Kalender mit einem anderen Benutzer (nur wenn allow_user_sharing = 1!) oder einer Gruppe teilen
    if ($action === 'share_calendar') {
        $targetType = (string)($_POST['target_type'] ?? 'user');
        $permRaw    = (string)($_POST['can_edit'] ?? '0');
        $canEdit    = ($permRaw === '1' || $permRaw === '2') ? 1 : 0;
        $canDelete  = ($permRaw === '2' || (isset($_POST['can_delete']) && (string)$_POST['can_delete'] === '1')) ? 1 : 0;
        if ($canDelete === 1) {
            $canEdit = 1;
        }

        if ($targetType === 'group') {
            $targetGroupId = (int)($_POST['target_group_id'] ?? 0);
            if ($targetGroupId <= 0) {
                setFlash('error', 'Bitte wählen Sie eine gültige Benutzergruppe aus.');
                header('Location: ' . $redirectUrl);
                exit;
            }
            $stmtGrp = $pdo->prepare('SELECT id, name FROM `groups` WHERE id = :id LIMIT 1');
            $stmtGrp->execute([':id' => $targetGroupId]);
            $grp = $stmtGrp->fetch();
            if (!$grp) {
                setFlash('error', 'Die gewählte Benutzergruppe existiert nicht.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $pdo->prepare('DELETE FROM calendar_shares WHERE owner_user_id = :uid AND target_group_id = :gid')
                ->execute([':uid' => $userId, ':gid' => $targetGroupId]);
            $pdo->prepare('INSERT INTO calendar_shares (owner_user_id, target_group_id, can_edit, can_delete) VALUES (:uid, :gid, :ce, :cd)')
                ->execute([':uid' => $userId, ':gid' => $targetGroupId, ':ce' => $canEdit, ':cd' => $canDelete]);

            setFlash('success', sprintf('Ihr Kalender wurde für die Gruppe „%s“ freigegeben.', (string)$grp['name']));
        } else {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0 || $targetUserId === $userId) {
                setFlash('error', 'Bitte wählen Sie einen gültigen Cloud-Benutzer aus.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            // Datenschutz-Prüfung: Nur Benutzer mit allow_user_sharing = 1 dürfen ausgewählt werden!
            $stmtTu = $pdo->prepare('SELECT id, username, allow_user_sharing FROM users WHERE id = :id LIMIT 1');
            $stmtTu->execute([':id' => $targetUserId]);
            $tu = $stmtTu->fetch();

            if (!$tu || (int)($tu['allow_user_sharing'] ?? 0) !== 1) {
                setFlash('error', 'Dieser Benutzer hat direkte Freigaben in seinen Datenschutzeinstellungen deaktiviert.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $pdo->prepare('DELETE FROM calendar_shares WHERE owner_user_id = :uid AND target_user_id = :tuid')
                ->execute([':uid' => $userId, ':tuid' => $targetUserId]);
            $pdo->prepare('INSERT INTO calendar_shares (owner_user_id, target_user_id, can_edit, can_delete) VALUES (:uid, :tuid, :ce, :cd)')
                ->execute([':uid' => $userId, ':tuid' => $targetUserId, ':ce' => $canEdit, ':cd' => $canDelete]);

            setFlash('success', sprintf('Ihr Kalender wurde für „%s“ freigegeben.', (string)$tu['username']));
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    // D) Kalender-Freigabe entziehen
    if ($action === 'revoke_calendar_share') {
        $shareId = (int)($_POST['share_id'] ?? 0);
        if ($shareId > 0) {
            $pdo->prepare('DELETE FROM calendar_shares WHERE id = :id AND owner_user_id = :uid')
                ->execute([':id' => $shareId, ':uid' => $userId]);
            setFlash('success', 'Kalender-Freigabe wurde beendet.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // E) Persönlichen .ics-Abo-Link generieren / erneuern / deaktivieren
    if ($action === 'generate_ics_token') {
        $newToken = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE users SET calendar_token = :t WHERE id = :uid')
            ->execute([':t' => $newToken, ':uid' => $userId]);
        setFlash('success', 'Persönlicher .ics-Kalender-Abo-Link wurde erstellt.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'revoke_ics_token') {
        $pdo->prepare('UPDATE users SET calendar_token = NULL WHERE id = :uid')
            ->execute([':uid' => $userId]);
        setFlash('success', 'Der .ics-Kalender-Abo-Link wurde deaktiviert.');
        header('Location: ' . $redirectUrl);
        exit;
    }

    // F) .ics-Datei importieren
    if ($action === 'import_ics') {
        if (!isset($_FILES['ics_file']) || !is_array($_FILES['ics_file']) || (int)($_FILES['ics_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            setFlash('error', 'Bitte wählen Sie eine gültige .ics-Kalenderdatei aus.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $rawContent = (string)@file_get_contents((string)$_FILES['ics_file']['tmp_name']);
        if ($rawContent === '' || strlen($rawContent) > 5 * 1024 * 1024) {
            setFlash('error', 'Die .ics-Datei ist leer oder überschreitet die maximale Größe von 5 MB.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Zeile-Faltungen (Line Folding nach RFC 5545) auflösen
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $rawContent) ?? $rawContent;
        $importedCount = 0;

        if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/si', $unfolded, $matches)) {
            $stmtIns = $pdo->prepare(
                'INSERT INTO calendar_events (user_id, title, description, location, start_at, end_at, all_day, recurrence, color)
                 VALUES (:uid, :title, :descr, :loc, :start_at, :end_at, :all_day, :rec, :color)'
            );

            foreach ($matches[1] as $block) {
                $summary = '';
                $descr   = '';
                $loc     = '';
                $dtStartRaw = '';
                $dtEndRaw   = '';
                $allDay     = 0;
                $rec        = 'none';

                foreach (preg_split("/\r?\n/", trim($block)) ?: [] as $line) {
                    if (!str_contains($line, ':')) {
                        continue;
                    }
                    [$propPart, $valPart] = explode(':', $line, 2);
                    $propUpper = strtoupper(trim($propPart));
                    $valUnescaped = str_replace(['\n', '\N', '\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], trim($valPart));

                    if ($propUpper === 'SUMMARY' || str_starts_with($propUpper, 'SUMMARY;')) {
                        $summary = mb_substr($valUnescaped, 0, 190);
                    } elseif ($propUpper === 'DESCRIPTION' || str_starts_with($propUpper, 'DESCRIPTION;')) {
                        $descr = mb_substr($valUnescaped, 0, 5000);
                    } elseif ($propUpper === 'LOCATION' || str_starts_with($propUpper, 'LOCATION;')) {
                        $loc = mb_substr($valUnescaped, 0, 190);
                    } elseif ($propUpper === 'DTSTART' || str_starts_with($propUpper, 'DTSTART;')) {
                        $dtStartRaw = trim($valPart);
                        if (str_contains($propUpper, 'VALUE=DATE') || preg_match('/^\d{8}$/', $dtStartRaw)) {
                            $allDay = 1;
                        }
                    } elseif ($propUpper === 'DTEND' || str_starts_with($propUpper, 'DTEND;')) {
                        $dtEndRaw = trim($valPart);
                    } elseif ($propUpper === 'RRULE' || str_starts_with($propUpper, 'RRULE;')) {
                        $rruleUpper = strtoupper($valPart);
                        if (str_contains($rruleUpper, 'FREQ=YEARLY')) {
                            $rec = 'yearly';
                        } elseif (str_contains($rruleUpper, 'FREQ=MONTHLY')) {
                            $rec = 'monthly';
                        } elseif (str_contains($rruleUpper, 'FREQ=WEEKLY')) {
                            $rec = 'weekly';
                        } elseif (str_contains($rruleUpper, 'FREQ=DAILY')) {
                            $rec = 'daily';
                        }
                    }
                }

                if ($summary === '' || $dtStartRaw === '') {
                    continue;
                }

                $startTs = strtotime($dtStartRaw);
                if ($startTs === false) {
                    continue;
                }

                if ($allDay === 1) {
                    $startAt = date('Y-m-d 00:00:00', $startTs);
                    $endTs   = $dtEndRaw !== '' ? strtotime($dtEndRaw) : false;
                    // Exklusives DTEND bei ganztägigen ICS-Terminen korrigieren (-1 Sekunde)
                    if ($endTs !== false && $endTs > $startTs) {
                        $endAt = date('Y-m-d 23:59:59', $endTs - 86400);
                    } else {
                        $endAt = date('Y-m-d 23:59:59', $startTs);
                    }
                } else {
                    $startAt = date('Y-m-d H:i:s', $startTs);
                    $endTs   = $dtEndRaw !== '' ? strtotime($dtEndRaw) : false;
                    $endAt   = ($endTs !== false && $endTs >= $startTs) ? date('Y-m-d H:i:s', $endTs) : date('Y-m-d H:i:s', $startTs + 3600);
                }

                $stmtIns->execute([
                    ':uid'      => $userId,
                    ':title'    => $summary,
                    ':descr'    => $descr !== '' ? $descr : null,
                    ':loc'      => $loc !== '' ? $loc : null,
                    ':start_at' => $startAt,
                    ':end_at'   => $endAt,
                    ':all_day'  => $allDay,
                    ':rec'      => $rec,
                    ':color'    => '#2563eb',
                ]);
                $importedCount++;
            }
        }

        if ($importedCount > 0) {
            setFlash('success', sprintf('%d Termin(e) erfolgreich aus der .ics-Datei importiert.', $importedCount));
        } else {
            setFlash('warning', 'Es wurden keine gültigen Termine (VEVENT) in der .ics-Datei gefunden.');
        }

        header('Location: ' . $redirectUrl);
        exit;
    }
}

// --- 4. Daten für die Kalenderansicht vorbereiten ---
$ymParam = trim((string)($_GET['ym'] ?? date('Y-m')));
if (!preg_match('/^(\d{4})-(\d{2})$/', $ymParam, $mYm)) {
    $ymParam = date('Y-m');
    $year  = (int)date('Y');
    $month = (int)date('n');
} else {
    $year  = max(1970, min(2100, (int)$mYm[1]));
    $month = max(1, min(12, (int)$mYm[2]));
    $ymParam = sprintf('%04d-%02d', $year, $month);
}

$viewMode = in_array($_GET['view'] ?? 'month', ['month', 'list'], true) ? (string)$_GET['view'] : 'month';
$calFilter = (string)($_GET['filter'] ?? 'all'); // 'all', 'mine', 'groups', 'shared'

$firstDayOfMonthTs = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth       = (int)date('t', $firstDayOfMonthTs);
// ISO-8601 Wochentag des 1. Tags im Monat (1 = Montag ... 7 = Sonntag)
$firstWeekdayIso   = (int)date('N', $firstDayOfMonthTs);

$prevMonthTs = mktime(0, 0, 0, $month - 1, 1, $year);
$nextMonthTs = mktime(0, 0, 0, $month + 1, 1, $year);
$prevYm      = date('Y-m', $prevMonthTs);
$nextYm      = date('Y-m', $nextMonthTs);

$monthNamesDe = [
    1  => 'Januar',
    2  => 'Februar',
    3  => 'März',
    4  => 'April',
    5  => 'Mai',
    6  => 'Juni',
    7  => 'Juli',
    8  => 'August',
    9  => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Dezember',
];
$monthTitle = ($monthNamesDe[$month] ?? '') . ' ' . $year;

// Alle zugänglichen Basis-Termine abrufen
$rawEvents = getAccessibleEventsForUser($pdo, $userId);

// Filter anwenden
$filteredBaseEvents = array_values(array_filter($rawEvents, static function (array $ev) use ($calFilter, $userId): bool {
    if ($calFilter === 'mine') {
        return (int)$ev['user_id'] === $userId;
    }
    if ($calFilter === 'groups') {
        return $ev['group_id'] !== null;
    }
    if ($calFilter === 'shared') {
        return (int)$ev['user_id'] !== $userId;
    }
    return true;
}));

/**
 * Prüft, ob ein Termin (inkl. Wiederholungsregel) an einem bestimmten Kalendertag ($dayDate = 'YYYY-MM-DD') stattfindet.
 *
 * @param array<string, mixed> $ev
 */
function doesEventOccurOnDate(array $ev, string $dayDate): bool
{
    $dayStartTs = strtotime($dayDate . ' 00:00:00');
    $dayEndTs   = strtotime($dayDate . ' 23:59:59');
    $evStartTs  = strtotime((string)$ev['start_at']);
    $evEndTs    = strtotime((string)$ev['end_at']);

    if ($dayStartTs === false || $evStartTs === false || $evEndTs === false) {
        return false;
    }

    $evStartDate = date('Y-m-d', $evStartTs);
    $evEndDate   = date('Y-m-d', $evEndTs);

    // 1. Direkter Zeitraum des Termins
    if ($dayDate >= $evStartDate && $dayDate <= $evEndDate) {
        return true;
    }

    // 2. Wiederholungsregeln (nur für Tage ab dem ursprünglichen Startdatum)
    if ($dayDate < $evStartDate) {
        return false;
    }

    $rec = (string)($ev['recurrence'] ?? 'none');
    if ($rec === 'none') {
        return false;
    }

    if ($rec === 'daily') {
        return true;
    }
    if ($rec === 'weekly') {
        return (int)date('N', $dayStartTs) === (int)date('N', $evStartTs);
    }
    if ($rec === 'monthly') {
        return (int)date('j', $dayStartTs) === (int)date('j', $evStartTs);
    }
    if ($rec === 'yearly') {
        return date('m-d', $dayStartTs) === date('m-d', $evStartTs);
    }

    return false;
}

// Termine pro Tag des aktuellen Monats aufbereiten
$eventsByDay = [];
$monthOccurrencesList = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $eventsByDay[$dateStr] = [];
    foreach ($filteredBaseEvents as $ev) {
        if (doesEventOccurOnDate($ev, $dateStr)) {
            $eventsByDay[$dateStr][] = $ev;
            $occ = $ev;
            $occ['occurrence_date'] = $dateStr;
            $monthOccurrencesList[] = $occ;
        }
    }
}

// Anstehende Termine der nächsten 14 Tage (ab heute) für die Seitenleiste
$upcomingOccurrences = [];
$todayTs = strtotime('today');
for ($offset = 0; $offset < 14; $offset++) {
    $checkDate = date('Y-m-d', $todayTs + ($offset * 86400));
    foreach ($rawEvents as $ev) {
        if (doesEventOccurOnDate($ev, $checkDate)) {
            $occ = $ev;
            $occ['occurrence_date'] = $checkDate;
            $upcomingOccurrences[] = $occ;
        }
    }
}

// Benutzergruppen für das Formular laden
if (isAdmin()) {
    $userGroups = $pdo->query('SELECT id, name FROM `groups` ORDER BY name ASC')->fetchAll();
} else {
    $stmtUg = $pdo->prepare(
        'SELECT g.id, g.name
         FROM `groups` g
         JOIN group_members gm ON gm.group_id = g.id
         WHERE gm.user_id = :uid
         ORDER BY g.name ASC'
    );
    $stmtUg->execute([':uid' => $userId]);
    $userGroups = $stmtUg->fetchAll();
}

// Andere Cloud-Benutzer laden, die in ihren Datenschutzeinstellungen allow_user_sharing = 1 aktiviert haben!
$stmtShareableUsers = $pdo->prepare(
    'SELECT id, username FROM users WHERE id <> :uid AND allow_user_sharing = 1 ORDER BY username ASC'
);
$stmtShareableUsers->execute([':uid' => $userId]);
$shareableUsers = $stmtShareableUsers->fetchAll();

// Eigene Dateien (und Dateien aus zugänglichen Gruppenordnern) für die Datei-Verknüpfung laden
$stmtAttachableFiles = $pdo->prepare(
    'SELECT id, original_name, folder_path, group_folder_id
     FROM files
     WHERE user_id = :uid AND deleted_at IS NULL
     ORDER BY original_name ASC
     LIMIT 300'
);
$stmtAttachableFiles->execute([':uid' => $userId]);
$attachableFiles = $stmtAttachableFiles->fetchAll();

// Meine aktiven Kalender-Freigaben laden
$stmtMyCalendarShares = $pdo->prepare(
    'SELECT cs.*, u.username AS target_username, g.name AS target_group_name
     FROM calendar_shares cs
     LEFT JOIN users u ON u.id = cs.target_user_id
     LEFT JOIN `groups` g ON g.id = cs.target_group_id
     WHERE cs.owner_user_id = :uid
     ORDER BY cs.created_at DESC'
);
$stmtMyCalendarShares->execute([':uid' => $userId]);
$myCalendarShares = $stmtMyCalendarShares->fetchAll();

// Kalender anderer Benutzer, die mit mir (oder einer meiner Gruppen) geteilt wurden
$stmtSharedWithMeCalendars = $pdo->prepare(
    'SELECT DISTINCT u.id AS owner_id, u.username AS owner_username, MAX(cs.can_edit) AS can_edit, MAX(cs.can_delete) AS can_delete
     FROM calendar_shares cs
     JOIN users u ON u.id = cs.owner_user_id
     WHERE cs.owner_user_id <> :uid1
       AND (
           cs.target_user_id = :uid2
           OR (cs.target_group_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM group_members gm WHERE gm.group_id = cs.target_group_id AND gm.user_id = :uid3
           ))
       )
     GROUP BY u.id, u.username
     ORDER BY u.username ASC'
);
$stmtSharedWithMeCalendars->execute([':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
$sharedWithMeCalendars = $stmtSharedWithMeCalendars->fetchAll();

// Persönliches .ics-Token des aktuellen Benutzers abrufen
$stmtUserToken = $pdo->prepare('SELECT calendar_token, allow_user_sharing FROM users WHERE id = :uid LIMIT 1');
$stmtUserToken->execute([':uid' => $userId]);
$currentUserRow = $stmtUserToken->fetch() ?: [];
$calendarToken = (string)($currentUserRow['calendar_token'] ?? '');
$mySharingAllowed = (int)($currentUserRow['allow_user_sharing'] ?? 0) === 1;
$icsFeedUrl = $calendarToken !== '' ? (getBaseUrl() . '/calendar.php?ics_token=' . urlencode($calendarToken)) : '';

$pageTitle = 'Kalender';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Kopfbereich & Aktionsleiste -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="w-9 h-9 rounded-xl bg-indigo-600/10 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                📅
            </span>
            <span>Kalender &amp; Termine</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
            Persönliche Termine und mit anderen Cloud-Benutzern geteilte Kalender an einem Ort.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button
            type="button"
            onclick="openEventModal()"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            <span>Neuer Termin</span>
        </button>

        <button
            type="button"
            onclick="document.getElementById('calendarShareModal').classList.remove('hidden')"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs sm:text-sm font-semibold shadow-2xs transition-colors cursor-pointer"
        >
            <span>👥</span>
            <span>Kalender teilen &amp; .ics</span>
            <?php if (!empty($myCalendarShares)): ?>
                <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">
                    <?= count($myCalendarShares) ?>
                </span>
            <?php endif; ?>
        </button>
    </div>
</div>

<!-- Monatsnavigation, Ansichten & Filter -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
    <!-- Monats-Wechsler -->
    <div class="flex items-center justify-between sm:justify-start gap-2">
        <div class="inline-flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700/70">
            <a
                href="calendar.php?ym=<?= e($prevYm) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=<?= e($calFilter) ?>"
                class="p-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-colors"
                title="Vorheriger Monat"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <a
                href="calendar.php?ym=<?= e(date('Y-m')) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=<?= e($calFilter) ?>"
                class="px-2.5 py-1 text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-white dark:hover:bg-slate-700 rounded-lg transition-colors"
            >
                Heute
            </a>
            <a
                href="calendar.php?ym=<?= e($nextYm) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=<?= e($calFilter) ?>"
                class="p-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-colors"
                title="Nächster Monat"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>

        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white ml-1">
            <?= e($monthTitle) ?>
        </h2>
    </div>

    <!-- Filter & Ansichtsumschalter -->
    <div class="flex flex-wrap items-center justify-between sm:justify-end gap-2">
        <!-- Kalender-Filter -->
        <div class="inline-flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700/70 text-xs">
            <a
                href="calendar.php?ym=<?= e($ymParam) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=all"
                class="px-2.5 py-1 rounded-lg font-medium transition-colors <?= $calFilter === 'all' ? 'bg-white dark:bg-slate-700 text-brand-600 dark:text-brand-400 shadow-2xs font-semibold' : 'text-slate-600 dark:text-slate-300' ?>"
            >Alle</a>
            <a
                href="calendar.php?ym=<?= e($ymParam) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=mine"
                class="px-2.5 py-1 rounded-lg font-medium transition-colors <?= $calFilter === 'mine' ? 'bg-white dark:bg-slate-700 text-brand-600 dark:text-brand-400 shadow-2xs font-semibold' : 'text-slate-600 dark:text-slate-300' ?>"
            >Mein Kalender</a>
            <a
                href="calendar.php?ym=<?= e($ymParam) ?>&amp;view=<?= e($viewMode) ?>&amp;filter=shared"
                class="px-2.5 py-1 rounded-lg font-medium transition-colors <?= $calFilter === 'shared' ? 'bg-white dark:bg-slate-700 text-brand-600 dark:text-brand-400 shadow-2xs font-semibold' : 'text-slate-600 dark:text-slate-300' ?>"
            >Geteilt</a>
        </div>

        <!-- Ansicht: Monat vs. Liste -->
        <div class="inline-flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700/70 text-xs">
            <a
                href="calendar.php?ym=<?= e($ymParam) ?>&amp;view=month&amp;filter=<?= e($calFilter) ?>"
                class="px-2.5 py-1 rounded-lg font-medium transition-colors <?= $viewMode === 'month' ? 'bg-white dark:bg-slate-700 text-brand-600 dark:text-brand-400 shadow-2xs font-semibold' : 'text-slate-600 dark:text-slate-300' ?>"
            >📅 Monat</a>
            <a
                href="calendar.php?ym=<?= e($ymParam) ?>&amp;view=list&amp;filter=<?= e($calFilter) ?>"
                class="px-2.5 py-1 rounded-lg font-medium transition-colors <?= $viewMode === 'list' ? 'bg-white dark:bg-slate-700 text-brand-600 dark:text-brand-400 shadow-2xs font-semibold' : 'text-slate-600 dark:text-slate-300' ?>"
            >📋 Liste</a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Hauptbereich: Monatsgitter oder Listenansicht (9 Spalten) -->
    <div class="lg:col-span-9">
        <?php if ($viewMode === 'month'): ?>
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden">
                <!-- Wochentags-Kopfzeile (Mo - So) -->
                <div class="grid grid-cols-7 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60 text-center text-[11px] sm:text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 py-2.5">
                    <div>Mo</div>
                    <div>Di</div>
                    <div>Mi</div>
                    <div>Do</div>
                    <div>Fr</div>
                    <div class="text-indigo-600 dark:text-indigo-400">Sa</div>
                    <div class="text-indigo-600 dark:text-indigo-400">So</div>
                </div>

                <!-- Kalendergitter -->
                <div class="grid grid-cols-7 divide-x divide-y divide-slate-200/70 dark:divide-slate-800">
                    <?php
                    // Leere Zellen vor dem 1. des Monats
                    for ($blank = 1; $blank < $firstWeekdayIso; $blank++):
                    ?>
                        <div class="min-h-[88px] sm:min-h-[115px] bg-slate-50/40 dark:bg-slate-900/40 p-1.5"></div>
                    <?php endfor; ?>

                    <?php
                    $todayDateStr = date('Y-m-d');
                    for ($day = 1; $day <= $daysInMonth; $day++):
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $isToday = ($dateStr === $todayDateStr);
                        $dayEvents = $eventsByDay[$dateStr] ?? [];
                    ?>
                        <div
                            class="min-h-[88px] sm:min-h-[115px] p-1.5 sm:p-2 transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-800/40 flex flex-col justify-between group cursor-pointer <?= $isToday ? 'bg-brand-50/30 dark:bg-brand-950/20' : '' ?>"
                            onclick="if (event.target === this || event.target.classList.contains('day-cell-header')) openEventModal('<?= e($dateStr) ?>')"
                            title="Klicken, um am <?= e(sprintf('%02d.%02d.%04d', $day, $month, $year)) ?> einen Termin einzutragen"
                        >
                            <div>
                                <div class="day-cell-header flex items-center justify-between mb-1">
                                    <span class="day-cell-header inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold <?= $isToday ? 'bg-brand-600 text-white shadow-2xs' : 'text-slate-700 dark:text-slate-300' ?>">
                                        <?= $day ?>
                                    </span>
                                    <button
                                        type="button"
                                        onclick="event.stopPropagation(); openEventModal('<?= e($dateStr) ?>')"
                                        class="opacity-0 group-hover:opacity-100 text-xs font-bold text-slate-400 hover:text-brand-600 dark:hover:text-brand-400 px-1 rounded transition-opacity cursor-pointer"
                                        title="Termin am <?= e(sprintf('%02d.%02d.%04d', $day, $month, $year)) ?> hinzufügen"
                                    >+</button>
                                </div>

                                <div class="space-y-1">
                                    <?php foreach ($dayEvents as $ev): ?>
                                        <?php
                                        $evJson = htmlspecialchars(json_encode($ev, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                        $evTimeLabel = (int)$ev['all_day'] === 1 ? 'Ganztägig' : date('H:i', strtotime((string)$ev['start_at']));
                                        ?>
                                        <button
                                            type="button"
                                            onclick="event.stopPropagation(); openEventDetailModal(<?= $evJson ?>)"
                                            style="background-color: <?= e((string)$ev['color']) ?>18; border-left: 3px solid <?= e((string)$ev['color']) ?>;"
                                            class="w-full text-left px-1.5 py-0.5 rounded text-[11px] leading-tight truncate hover:opacity-85 transition-opacity block cursor-pointer text-slate-800 dark:text-slate-100"
                                            title="<?= e($evTimeLabel . ' – ' . (string)$ev['title']) ?>"
                                        >
                                            <?php if ((int)$ev['all_day'] === 0): ?>
                                                <span class="font-semibold opacity-75"><?= e(date('H:i', strtotime((string)$ev['start_at']))) ?></span>
                                            <?php endif; ?>
                                            <span class="font-medium"><?= e((string)$ev['title']) ?></span>
                                            <?php if ($ev['group_id'] !== null): ?>
                                                <span title="Gruppen-Termin">👥</span>
                                            <?php elseif ((int)$ev['user_id'] !== $userId): ?>
                                                <span title="Geteilter Termin von <?= e((string)$ev['owner_username']) ?>">🔗</span>
                                            <?php endif; ?>
                                            <?php if ($ev['attached_file_id'] !== null): ?>
                                                <span title="Cloud-Datei angehängt">📎</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <?php
                    // Restliche Zellen der letzten Woche auffüllen
                    $totalCellsUsed = ($firstWeekdayIso - 1) + $daysInMonth;
                    $trailingCells  = (7 - ($totalCellsUsed % 7)) % 7;
                    for ($t = 0; $t < $trailingCells; $t++):
                    ?>
                        <div class="min-h-[88px] sm:min-h-[115px] bg-slate-50/40 dark:bg-slate-900/40 p-1.5"></div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Listenansicht des gewählten Monats -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 sm:p-5 shadow-xs">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-4">
                    📋 Alle Termine im <?= e($monthTitle) ?> (<?= count($monthOccurrencesList) ?>)
                </h3>

                <?php if (empty($monthOccurrencesList)): ?>
                    <div class="text-center py-10 text-slate-500 dark:text-slate-400 text-sm">
                        Keine Termine in diesem Monat vorhanden.
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        <?php foreach ($monthOccurrencesList as $occ): ?>
                            <?php
                            $evJson = htmlspecialchars(json_encode($occ, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            $occTs  = strtotime((string)$occ['occurrence_date']);
                            ?>
                            <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-slate-50/70 dark:hover:bg-slate-800/40 px-2 rounded-xl transition-colors">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div
                                        class="w-3 h-10 rounded-full shrink-0 mt-0.5"
                                        style="background-color: <?= e((string)$occ['color']) ?>;"
                                    ></div>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-bold text-brand-600 dark:text-brand-400">
                                                <?= e(date('d.m.Y', $occTs)) ?>
                                            </span>
                                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                                <?= (int)$occ['all_day'] === 1 ? 'Ganztägig' : e(date('H:i', strtotime((string)$occ['start_at'])) . ' – ' . date('H:i', strtotime((string)$occ['end_at'])) . ' Uhr') ?>
                                            </span>
                                            <?php if ($occ['group_name'] !== null): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-50 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                                    👥 <?= e((string)$occ['group_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((int)$occ['user_id'] !== $userId): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                                    🔗 Von <?= e((string)$occ['owner_username']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white truncate mt-0.5">
                                            <?= e((string)$occ['title']) ?>
                                        </p>
                                        <?php if (trim((string)($occ['location'] ?? '')) !== ''): ?>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">📍 <?= e((string)$occ['location']) ?></p>
                                        <?php endif; ?>
                                        <?php if ($occ['attached_file_id'] !== null && $occ['attached_file_name'] !== null): ?>
                                            <a
                                                href="download.php?id=<?= (int)$occ['attached_file_id'] ?>"
                                                class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline mt-1"
                                            >
                                                📎 <?= e((string)$occ['attached_file_name']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1.5 shrink-0 self-end sm:self-center">
                                    <button
                                        type="button"
                                        onclick="openEventDetailModal(<?= $evJson ?>)"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer"
                                    >Details</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rechte Seitenleiste: Anstehende Termine & Freigegebene Kalender (3 Spalten) -->
    <div class="lg:col-span-3 space-y-6">
        <!-- Nächste 14 Tage -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3 flex items-center justify-between">
                <span>🔔 Nächste 14 Tage</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-brand-50 dark:bg-brand-950 text-brand-700 dark:text-brand-300"><?= count($upcomingOccurrences) ?></span>
            </h3>

            <?php if (empty($upcomingOccurrences)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-3">
                    Keine anstehenden Termine in den nächsten 14 Tagen.
                </p>
            <?php else: ?>
                <div class="space-y-2.5 max-h-[360px] overflow-y-auto pr-1">
                    <?php foreach (array_slice($upcomingOccurrences, 0, 12) as $up): ?>
                        <?php
                        $upJson = htmlspecialchars(json_encode($up, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $upDateTs = strtotime((string)$up['occurrence_date']);
                        ?>
                        <button
                            type="button"
                            onclick="openEventDetailModal(<?= $upJson ?>)"
                            class="w-full text-left p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 transition-colors block cursor-pointer"
                        >
                            <div class="flex items-center justify-between gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                                <span class="font-bold text-slate-700 dark:text-slate-200">
                                    <?= e(date('d.m.Y', $upDateTs)) ?>
                                </span>
                                <span>
                                    <?= (int)$up['all_day'] === 1 ? 'Ganztägig' : e(date('H:i', strtotime((string)$up['start_at'])) . ' Uhr') ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="w-2 h-2 rounded-full shrink-0" style="background-color: <?= e((string)$up['color']) ?>;"></span>
                                <span class="text-xs font-semibold text-slate-900 dark:text-white truncate"><?= e((string)$up['title']) ?></span>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Mit mir geteilte Kalender -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2.5">
                🤝 Empfangene Kalender
            </h3>
            <?php if (empty($sharedWithMeCalendars)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Derzeit hat noch kein anderer Benutzer seinen Kalender für Sie freigegeben.
                </p>
            <?php else: ?>
                <ul class="space-y-1.5 text-xs">
                    <?php foreach ($sharedWithMeCalendars as $sc): ?>
                        <?php
                        $scCanEdit   = ((int)($sc['can_edit'] ?? 0) === 1);
                        $scCanDelete = ((int)($sc['can_delete'] ?? 0) === 1);
                        $scPermTitle = $scCanDelete ? 'Lesen, Bearbeiten & Löschen' : ($scCanEdit ? 'Lesen & Bearbeiten' : 'Nur Lesen');
                        $scPermIcon  = $scCanDelete ? '✏️🗑️' : ($scCanEdit ? '✏️' : '👁️');
                        ?>
                        <li class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800/60">
                            <span class="font-medium text-slate-800 dark:text-slate-200 truncate">
                                👤 <?= e((string)$sc['owner_username']) ?>
                            </span>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 shrink-0" title="<?= e($scPermTitle) ?>">
                                <?= $scPermIcon ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="mt-3 pt-3 border-t border-slate-200/70 dark:border-slate-800 text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between gap-2">
                <span>Teilen-Sichtbarkeit:</span>
                <a href="settings.php" class="font-semibold hover:underline <?= $mySharingAllowed ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300' ?>">
                    <?= $mySharingAllowed ? '👁️ Sichtbar' : '🔒 Verborgen' ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 1: Termin erstellen / bearbeiten -->
<div id="eventFormModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-3 overflow-y-auto">
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl p-5 my-8 max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800 mb-4">
            <h3 id="eventModalTitle" class="text-base font-bold text-slate-900 dark:text-white">
                📅 Neuer Termin
            </h3>
            <button type="button" onclick="closeEventModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 cursor-pointer">✕</button>
        </div>

        <form action="calendar.php" method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_event">
            <input type="hidden" name="event_id" id="evFormId" value="0">
            <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
            <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Titel des Termins *</label>
                <input
                    type="text"
                    name="title"
                    id="evFormTitle"
                    required
                    maxlength="190"
                    placeholder="z.B. Team-Besprechung, Zahnarzt, Vereinsabend..."
                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Startdatum</label>
                    <input
                        type="date"
                        name="start_date"
                        id="evFormStartDate"
                        required
                        value="<?= e(date('Y-m-d')) ?>"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                    >
                </div>
                <div id="evStartTimeWrap">
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Startzeit</label>
                    <input
                        type="time"
                        name="start_time"
                        id="evFormStartTime"
                        value="09:00"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                    >
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Enddatum</label>
                    <input
                        type="date"
                        name="end_date"
                        id="evFormEndDate"
                        required
                        value="<?= e(date('Y-m-d')) ?>"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                    >
                </div>
                <div id="evEndTimeWrap">
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Endzeit</label>
                    <input
                        type="time"
                        name="end_time"
                        id="evFormEndTime"
                        value="10:00"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                    >
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-200 cursor-pointer">
                <input
                    type="checkbox"
                    name="all_day"
                    id="evFormAllDay"
                    value="1"
                    onchange="toggleAllDayTimeInputs()"
                    class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                >
                <span>Ganztägiger Termin</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Wiederholung</label>
                    <select
                        name="recurrence"
                        id="evFormRecurrence"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <?php foreach ($allowedRecurrences as $rKey => $rLabel): ?>
                            <option value="<?= e($rKey) ?>"><?= e($rLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Farbe</label>
                    <select
                        name="color"
                        id="evFormColor"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <?php foreach ($allowedColors as $hex => $cName): ?>
                            <option value="<?= e($hex) ?>"><?= e($cName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Ort (optional)</label>
                    <input
                        type="text"
                        name="location"
                        id="evFormLocation"
                        maxlength="190"
                        placeholder="z.B. Konferenzraum / Online"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">📎 Cloud-Datei anhängen</label>
                    <select
                        name="attached_file_id"
                        id="evFormFileId"
                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <option value="0">– Keine Datei verknüpft –</option>
                        <?php foreach ($attachableFiles as $af): ?>
                            <option value="<?= (int)$af['id'] ?>">
                                <?= e((string)$af['original_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Beschreibung / Notizen (optional)</label>
                <textarea
                    name="description"
                    id="evFormDescription"
                    rows="2"
                    placeholder="Weitere Details zum Termin..."
                    class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                ></textarea>
            </div>

            <!-- Teilen & Sichtbarkeit des Termins -->
            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5">
                <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">
                    👥 Termin-Freigabe &amp; Kalender-Zuordnung
                </p>

                <?php if (!empty($writableCalendars)): ?>
                    <div id="evCalendarOwnerWrap">
                        <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1">In welchen Kalender eintragen?</label>
                        <select
                            name="calendar_owner_id"
                            id="evFormCalendarOwner"
                            class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                        >
                            <option value="<?= $userId ?>">Mein eigener Kalender (<?= e($username) ?>)</option>
                            <?php foreach ($writableCalendars as $wc): ?>
                                <option value="<?= (int)$wc['id'] ?>">Kalender von <?= e((string)$wc['username']) ?> (Schreibzugriff)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="group_id" id="evFormGroupId" value="0">
                <input type="hidden" name="group_can_edit" id="evFormGroupCanEdit" value="0">

                <div>
                    <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1">Einzeltermin mit Cloud-Benutzer teilen (optional)</label>
                    <select
                        name="shared_user_id"
                        id="evFormSharedUserId"
                        class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <option value="0">– Kein einzelner Benutzer –</option>
                        <?php foreach ($shareableUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pt-1">
                    <label class="inline-flex items-center gap-2 text-[11px] text-slate-600 dark:text-slate-300 cursor-pointer" title="Verbirgt diesen Termin auch dann vor anderen, wenn Sie Ihren gesamten Kalender geteilt haben.">
                        <input type="checkbox" name="is_private" id="evFormIsPrivate" value="1" class="rounded border-slate-300 text-brand-600">
                        <span>🔒 Privat (in geteiltem Kalender verbergen)</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeEventModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                >Abbrechen</button>
                <button
                    type="submit"
                    class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-xs font-semibold text-white shadow-xs cursor-pointer"
                >Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Termin-Details anzeigen -->
<div id="eventDetailModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-3">
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl p-5">
        <div class="flex items-start justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800 mb-3">
            <div class="flex items-center gap-2.5 min-w-0">
                <span id="detailColorDot" class="w-3.5 h-3.5 rounded-full shrink-0 bg-brand-600"></span>
                <h3 id="detailTitle" class="text-base font-bold text-slate-900 dark:text-white break-words"></h3>
            </div>
            <button type="button" onclick="document.getElementById('eventDetailModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 cursor-pointer">✕</button>
        </div>

        <div class="space-y-2.5 text-xs sm:text-sm text-slate-700 dark:text-slate-200">
            <p id="detailTime" class="font-medium text-brand-600 dark:text-brand-400"></p>
            <p id="detailRecurrence" class="hidden text-xs text-slate-500 dark:text-slate-400"></p>
            <p id="detailLocation" class="hidden text-xs text-slate-600 dark:text-slate-300"></p>
            <p id="detailMeta" class="text-xs text-slate-500 dark:text-slate-400"></p>
            <div id="detailDescription" class="hidden p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 text-xs whitespace-pre-line"></div>
            <div id="detailFileWrap" class="hidden pt-1">
                <a id="detailFileLink" href="#" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 text-xs font-semibold hover:underline">
                    📎 <span id="detailFileName"></span>
                </a>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 pt-4 mt-4 border-t border-slate-200 dark:border-slate-800">
            <a
                id="detailIcsLink"
                href="#"
                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-medium text-slate-700 dark:text-slate-200"
                title="Einzeltermin als .ics-Datei herunterladen"
            >📥 .ics</a>

            <div id="detailEditActions" class="flex items-center gap-2">
                <button
                    type="button"
                    id="detailEditBtn"
                    class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer"
                >✏️ Bearbeiten</button>

                <form id="detailDeleteForm" action="calendar.php" method="post" class="inline" onsubmit="return confirm('Diesen Termin wirklich löschen?');">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" id="detailDeleteId" value="0">
                    <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                    <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                    <button
                        type="submit"
                        class="px-3 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-semibold cursor-pointer"
                        title="Termin löschen"
                    >🗑️</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 3: Kalender mit anderen Benutzern / Gruppen teilen & .ics Export/Import -->
<div id="calendarShareModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-3 overflow-y-auto">
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-xl rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xl p-5 my-8 max-h-[92vh] overflow-y-auto space-y-5">
        <div class="flex items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">
                👥 Meinen Kalender teilen &amp; .ics-Synchronisation
            </h3>
            <button type="button" onclick="document.getElementById('calendarShareModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 cursor-pointer">✕</button>
        </div>

        <!-- 1. Kalender mit Cloud-Benutzer teilen (respektiert allow_user_sharing = 1) -->
        <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                1. Kalender für Cloud-Benutzer freigeben
            </h4>
            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                Aus Datenschutzgründen erscheinen hier nur Benutzer, die in ihren Einstellungen die Sichtbarkeit für die Teilen-Auswahl freigegeben haben.
            </p>

            <?php if (empty($shareableUsers)): ?>
                <p class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/80 rounded-lg px-3 py-2">
                    Derzeit hat kein anderer Benutzer die Sichtbarkeit für Direktfreigaben in seinen Einstellungen aktiviert.
                </p>
            <?php else: ?>
                <form action="calendar.php" method="post" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="share_calendar">
                    <input type="hidden" name="target_type" value="user">
                    <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                    <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">

                    <select
                        name="target_user_id"
                        required
                        class="flex-1 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <option value="">– Benutzer auswählen –</option>
                        <?php foreach ($shareableUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select
                        name="can_edit"
                        class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                        <option value="0">👁️ Nur Lesen</option>
                        <option value="1">✏️ Lesen &amp; Bearbeiten</option>
                        <option value="2">🗑️ Lesen, Bearbeiten &amp; Löschen</option>
                    </select>

                    <button
                        type="submit"
                        class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer shrink-0"
                    >Freigeben</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Aktive Kalender-Freigaben verwalten -->
        <?php if (!empty($myCalendarShares)): ?>
            <div class="space-y-2">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    Aktive Freigaben meines Kalenders
                </h4>
                <div class="divide-y divide-slate-200/70 dark:divide-slate-800 border border-slate-200/80 dark:border-slate-700/80 rounded-xl overflow-hidden">
                    <?php foreach ($myCalendarShares as $cs): ?>
                        <?php
                        $csCanEdit   = ((int)($cs['can_edit'] ?? 0) === 1);
                        $csCanDelete = ((int)($cs['can_delete'] ?? 0) === 1);
                        $csPermTitle = $csCanDelete ? 'Lesen, Bearbeiten & Löschen' : ($csCanEdit ? 'Lesen & Bearbeiten' : 'Nur Lesen');
                        $csPermIcon  = $csCanDelete ? '✏️🗑️' : ($csCanEdit ? '✏️' : '👁️');
                        ?>
                        <div class="px-3 py-2 bg-white dark:bg-slate-900 flex items-center justify-between gap-2 text-xs">
                            <div class="flex items-center gap-2 min-w-0">
                                <?php if ($cs['target_user_id'] !== null): ?>
                                    <span class="font-semibold text-slate-800 dark:text-slate-200 truncate">👤 <?= e((string)$cs['target_username']) ?></span>
                                <?php else: ?>
                                    <span class="font-semibold text-indigo-700 dark:text-indigo-300 truncate">👥 <?= e((string)$cs['target_group_name']) ?></span>
                                <?php endif; ?>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400" title="<?= e($csPermTitle) ?>">
                                    <?= $csPermIcon ?>
                                </span>
                            </div>

                            <form action="calendar.php" method="post" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="revoke_calendar_share">
                                <input type="hidden" name="share_id" value="<?= (int)$cs['id'] ?>">
                                <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                                <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                                <button type="submit" class="text-red-600 hover:text-red-700 font-bold px-1.5 py-0.5 cursor-pointer" title="Freigabe beenden">✕</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. .ics Export, Import & Smartphone-Abo-Link -->
        <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200">
                2. .ics Export, Import &amp; Smartphone-Abo
            </h4>

            <div class="flex flex-wrap items-center gap-2">
                <a
                    href="calendar.php?export_ics=1"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200"
                >📥 Kalender als .ics herunterladen</a>

                <form action="calendar.php" method="post" enctype="multipart/form-data" class="inline-flex items-center gap-1.5">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="import_ics">
                    <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                    <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 cursor-pointer">
                        <span>📤 .ics importieren</span>
                        <input type="file" name="ics_file" accept=".ics,text/calendar" class="hidden" onchange="this.form.submit()">
                    </label>
                </form>
            </div>

            <div class="pt-2 border-t border-slate-200/70 dark:border-slate-700/70 space-y-2">
                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                    <strong>Privater .ics-Abo-Link (für Smartphone / Thunderbird / Outlook):</strong>
                </p>
                <?php if ($icsFeedUrl !== ''): ?>
                    <div class="flex items-center gap-1.5">
                        <input
                            type="text"
                            readonly
                            id="icsFeedUrlInput"
                            value="<?= e($icsFeedUrl) ?>"
                            class="flex-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[11px] text-slate-800 dark:text-slate-200"
                        >
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('icsFeedUrlInput').value); this.textContent='✓';"
                            class="px-2.5 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold cursor-pointer"
                            title="Abo-Link kopieren"
                        >📋</button>
                    </div>
                    <div class="flex items-center gap-3">
                        <form action="calendar.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="generate_ics_token">
                            <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                            <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                            <button type="submit" class="text-[11px] text-brand-600 dark:text-brand-400 hover:underline cursor-pointer">🔄 Link neu generieren</button>
                        </form>
                        <form action="calendar.php" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="revoke_ics_token">
                            <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                            <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                            <button type="submit" class="text-[11px] text-red-600 dark:text-red-400 hover:underline cursor-pointer">🗑️ Link deaktivieren</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form action="calendar.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="generate_ics_token">
                        <input type="hidden" name="redirect_ym" value="<?= e($ymParam) ?>">
                        <input type="hidden" name="redirect_view" value="<?= e($viewMode) ?>">
                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-xs font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                            🔗 Persönlichen .ics-Abo-Link aktivieren
                        </button>
                    </form>
                <?php endif; ?>

                <?php
                $caldavServerUrl = getBaseUrl() . '/caldav.php/';
                $caldavDirectUrl = getBaseUrl() . '/caldav.php/calendars/' . rawurlencode((string)($_SESSION['username'] ?? '')) . '/default/';
                ?>
                <div class="pt-3 mt-2 border-t border-slate-200/70 dark:border-slate-700/70 space-y-1.5">
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        <strong>🔄 2-Wege-CalDAV-Synchronisation (Termine auf iPhone/macOS/Thunderbird/Android anlegen &amp; bearbeiten):</strong>
                    </p>
                    <div class="flex items-center gap-1.5">
                        <input
                            type="text"
                            readonly
                            id="caldavServerUrlInput"
                            value="<?= e($caldavServerUrl) ?>"
                            class="flex-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-[11px] font-mono text-slate-800 dark:text-slate-200"
                        >
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('caldavServerUrlInput').value); this.textContent='✓';"
                            class="px-2.5 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold cursor-pointer"
                            title="CalDAV-Server-URL kopieren"
                        >📋</button>
                    </div>
                    <p class="text-[10px] text-slate-500 dark:text-slate-400">
                        Direkte Kalender-URL (für Thunderbird / GNOME Kalender): <code class="select-all"><?= e($caldavDirectUrl) ?></code><br>
                        Anmeldung mit Benutzername <code><?= e((string)($_SESSION['username'] ?? '')) ?></code> &amp; deinem Passwort (oder einem <a href="settings.php#app-passwords" class="text-brand-600 dark:text-brand-400 underline">App-Passwort</a>).
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const recurrenceLabels = {
    none: '',
    daily: '🔁 Wiederholt sich täglich',
    weekly: '🔁 Wiederholt sich wöchentlich',
    monthly: '🔁 Wiederholt sich monatlich',
    yearly: '🔁 Wiederholt sich jährlich'
};

function toggleAllDayTimeInputs() {
    const allDay = document.getElementById('evFormAllDay').checked;
    document.getElementById('evStartTimeWrap').classList.toggle('opacity-40', allDay);
    document.getElementById('evEndTimeWrap').classList.toggle('opacity-40', allDay);
}

function openEventModal(defaultDate = null, eventData = null) {
    const modal = document.getElementById('eventFormModal');
    const titleEl = document.getElementById('eventModalTitle');

    if (eventData) {
        titleEl.textContent = '✏️ Termin bearbeiten';
        document.getElementById('evFormId').value = eventData.id || 0;
        document.getElementById('evFormTitle').value = eventData.title || '';
        document.getElementById('evFormLocation').value = eventData.location || '';
        document.getElementById('evFormDescription').value = eventData.description || '';

        const startStr = (eventData.start_at || '').split(' ');
        const endStr = (eventData.end_at || '').split(' ');
        document.getElementById('evFormStartDate').value = startStr[0] || '<?= e(date('Y-m-d')) ?>';
        document.getElementById('evFormStartTime').value = (startStr[1] || '09:00:00').substring(0, 5);
        document.getElementById('evFormEndDate').value = endStr[0] || startStr[0] || '<?= e(date('Y-m-d')) ?>';
        document.getElementById('evFormEndTime').value = (endStr[1] || '10:00:00').substring(0, 5);

        document.getElementById('evFormAllDay').checked = parseInt(eventData.all_day || 0, 10) === 1;
        document.getElementById('evFormRecurrence').value = eventData.recurrence || 'none';
        document.getElementById('evFormColor').value = eventData.color || '#2563eb';
        document.getElementById('evFormGroupId').value = eventData.group_id || 0;
        document.getElementById('evFormSharedUserId').value = eventData.shared_user_id || 0;
        document.getElementById('evFormFileId').value = eventData.attached_file_id || 0;
        document.getElementById('evFormGroupCanEdit').checked = parseInt(eventData.group_can_edit || 0, 10) === 1;
        document.getElementById('evFormIsPrivate').checked = parseInt(eventData.is_private || 0, 10) === 1;
    } else {
        titleEl.textContent = '📅 Neuer Termin';
        document.getElementById('evFormId').value = 0;
        document.getElementById('evFormTitle').value = '';
        document.getElementById('evFormLocation').value = '';
        document.getElementById('evFormDescription').value = '';

        const dateVal = defaultDate || '<?= e(date('Y-m-d')) ?>';
        document.getElementById('evFormStartDate').value = dateVal;
        document.getElementById('evFormEndDate').value = dateVal;
        document.getElementById('evFormStartTime').value = '09:00';
        document.getElementById('evFormEndTime').value = '10:00';
        document.getElementById('evFormAllDay').checked = false;
        document.getElementById('evFormRecurrence').value = 'none';
        document.getElementById('evFormColor').value = '#2563eb';
        document.getElementById('evFormGroupId').value = 0;
        document.getElementById('evFormSharedUserId').value = 0;
        document.getElementById('evFormFileId').value = 0;
        document.getElementById('evFormGroupCanEdit').checked = false;
        document.getElementById('evFormIsPrivate').checked = false;
    }

    toggleAllDayTimeInputs();
    modal.classList.remove('hidden');
}

function closeEventModal() {
    document.getElementById('eventFormModal').classList.add('hidden');
}

function openEventDetailModal(ev) {
    document.getElementById('detailTitle').textContent = ev.title || 'Termin';
    document.getElementById('detailColorDot').style.backgroundColor = ev.color || '#2563eb';

    const startParts = (ev.start_at || '').split(' ');
    const endParts = (ev.end_at || '').split(' ');
    const fmtDate = (d) => {
        if (!d) return '';
        const p = d.split('-');
        return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : d;
    };

    const sDate = fmtDate(startParts[0]);
    const eDate = fmtDate(endParts[0]);
    if (parseInt(ev.all_day || 0, 10) === 1) {
        document.getElementById('detailTime').textContent = sDate === eDate ? `${sDate} (Ganztägig)` : `${sDate} – ${eDate} (Ganztägig)`;
    } else {
        const sTime = (startParts[1] || '').substring(0, 5);
        const eTime = (endParts[1] || '').substring(0, 5);
        document.getElementById('detailTime').textContent = sDate === eDate ? `${sDate}, ${sTime} – ${eTime} Uhr` : `${sDate} ${sTime} – ${eDate} ${eTime} Uhr`;
    }

    const recEl = document.getElementById('detailRecurrence');
    const recText = recurrenceLabels[ev.recurrence || 'none'] || '';
    if (recText !== '') {
        recEl.textContent = recText;
        recEl.classList.remove('hidden');
    } else {
        recEl.classList.add('hidden');
    }

    const locEl = document.getElementById('detailLocation');
    if (ev.location && ev.location.trim() !== '') {
        locEl.textContent = '📍 Ort: ' + ev.location;
        locEl.classList.remove('hidden');
    } else {
        locEl.classList.add('hidden');
    }

    const metaParts = [];
    if (ev.owner_username) {
        metaParts.push('👤 Kalender: ' + ev.owner_username);
    }
    if (ev.group_name) {
        metaParts.push('👥 Gruppe: ' + ev.group_name);
    }
    if (ev.shared_username) {
        metaParts.push('🤝 Geteilt mit: ' + ev.shared_username);
    }
    document.getElementById('detailMeta').textContent = metaParts.join(' · ');

    const descEl = document.getElementById('detailDescription');
    if (ev.description && ev.description.trim() !== '') {
        descEl.textContent = ev.description;
        descEl.classList.remove('hidden');
    } else {
        descEl.classList.add('hidden');
    }

    const fileWrap = document.getElementById('detailFileWrap');
    if (ev.attached_file_id && ev.attached_file_name) {
        document.getElementById('detailFileLink').href = 'download.php?id=' + parseInt(ev.attached_file_id, 10);
        document.getElementById('detailFileName').textContent = ev.attached_file_name;
        fileWrap.classList.remove('hidden');
    } else {
        fileWrap.classList.add('hidden');
    }

    document.getElementById('detailIcsLink').href = 'calendar.php?export_ics=1&event_id=' + parseInt(ev.id, 10);

    const editActions = document.getElementById('detailEditActions');
    const editBtn = document.getElementById('detailEditBtn');
    const deleteForm = document.getElementById('detailDeleteForm');
    const canEdit = parseInt(ev.can_edit_event || 0, 10) === 1;
    const canDelete = parseInt(ev.can_delete_event || 0, 10) === 1;

    if (canEdit || canDelete) {
        editActions.classList.remove('hidden');
        if (canEdit) {
            editBtn.classList.remove('hidden');
            editBtn.onclick = function () {
                document.getElementById('eventDetailModal').classList.add('hidden');
                openEventModal(null, ev);
            };
        } else {
            editBtn.classList.add('hidden');
        }

        if (canDelete && deleteForm) {
            deleteForm.classList.remove('hidden');
            document.getElementById('detailDeleteId').value = ev.id;
        } else if (deleteForm) {
            deleteForm.classList.add('hidden');
        }
    } else {
        editActions.classList.add('hidden');
    }

    document.getElementById('eventDetailModal').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
