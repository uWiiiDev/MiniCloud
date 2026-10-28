<?php
/**
 * uWiiiMiniCloud – Terminfinder & Umfragen („Doodle“-Alternative, polls.php)
 * Unterstützt Termin-Abstimmungen und Auswahl-Umfragen:
 * - Intern für Cloud-Benutzer & Gruppen
 * - Öffentlich per Freigabe-Link (polls.php?token=...) auch für Gäste ohne Konto
 * - Mit Live-Auswertung und 1-Klick-Übernahme des Gewinner-Termins in den Kalender
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_polls'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('warning', 'Das Modul „Terminfinder & Umfragen“ ist durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

$publicToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$isGuestMode = ($publicToken !== '' && !isLoggedIn());

if ($publicToken === '' && !isLoggedIn()) {
    requireLogin();
}

$userId   = isLoggedIn() ? currentUserId() : 0;
$username = isLoggedIn() ? (string)($_SESSION['username'] ?? '') : '';

// Hilfsfunktion: Prüft, ob ein eingeloggter Benutzer oder Gast (über Token) Zugriff auf eine Umfrage hat
function canAccessPoll(PDO $pdo, array $poll, int $userId, string $token): bool
{
    if ($token !== '' && hash_equals((string)$poll['share_token'], $token)) {
        return true;
    }
    if ($userId <= 0) {
        return false;
    }
    if ((int)$poll['user_id'] === $userId || (int)($poll['shared_user_id'] ?? 0) === $userId) {
        return true;
    }
    if (!empty($poll['group_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => (int)$poll['group_id'], ':uid' => $userId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }
    return false;
}

// POST-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.');
        header('Location: polls.php' . ($publicToken !== '' ? '?token=' . urlencode($publicToken) : ''));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Stimme abgeben / aktualisieren (auch für Gäste über gültiges Token erlaubt!)
    if ($action === 'submit_vote') {
        $pollId    = (int)($_POST['poll_id'] ?? 0);
        $postToken = trim((string)($_POST['share_token'] ?? $publicToken));
        $voterName = trim((string)($_POST['voter_name'] ?? $username));
        $votesMap  = isset($_POST['votes']) && is_array($_POST['votes']) ? $_POST['votes'] : [];

        $stmtPoll = $pdo->prepare('SELECT * FROM polls WHERE id = :id LIMIT 1');
        $stmtPoll->execute([':id' => $pollId]);
        $pollRow = $stmtPoll->fetch();

        if (!$pollRow || !canAccessPoll($pdo, $pollRow, $userId, $postToken)) {
            setFlash('error', 'Umfrage nicht gefunden oder keine Berechtigung.');
            header('Location: polls.php');
            exit;
        }

        if ((int)$pollRow['is_closed'] === 1) {
            setFlash('warning', 'Diese Abstimmung ist bereits geschlossen.');
            header('Location: polls.php' . ($postToken !== '' ? '?token=' . urlencode($postToken) : ''));
            exit;
        }

        if ($voterName === '') {
            setFlash('error', 'Bitte gib deinen Namen ein.');
            header('Location: polls.php' . ($postToken !== '' ? '?token=' . urlencode($postToken) : ''));
            exit;
        }
        $voterName = mb_substr($voterName, 0, 100);

        $stmtOpts = $pdo->prepare('SELECT id FROM poll_options WHERE poll_id = :pid');
        $stmtOpts->execute([':pid' => $pollId]);
        $validOptIds = array_map('intval', $stmtOpts->fetchAll(PDO::FETCH_COLUMN));

        // Bisherige Stimmen dieses Teilnehmers für diese Umfrage ersetzen
        if ($userId > 0) {
            $voterName = $username;
            $pdo->prepare('DELETE FROM poll_votes WHERE poll_id = :pid AND user_id = :uid')
                ->execute([':pid' => $pollId, ':uid' => $userId]);
        } else {
            $pdo->prepare('DELETE FROM poll_votes WHERE poll_id = :pid AND user_id IS NULL AND LOWER(voter_name) = LOWER(:vname)')
                ->execute([':pid' => $pollId, ':vname' => $voterName]);
        }

        $allowMaybe = (int)$pollRow['allow_maybe'] === 1;
        $stmtInsVote = $pdo->prepare(
            'INSERT INTO poll_votes (poll_id, option_id, user_id, voter_name, vote_value)
             VALUES (:pid, :oid, :uid, :vname, :val)'
        );

        foreach ($validOptIds as $oid) {
            $rawVal = strtolower(trim((string)($votesMap[$oid] ?? 'no')));
            if (!in_array($rawVal, ['yes', 'maybe', 'no'], true)) {
                $rawVal = 'no';
            }
            if ($rawVal === 'maybe' && !$allowMaybe) {
                $rawVal = 'no';
            }
            $stmtInsVote->execute([
                ':pid'   => $pollId,
                ':oid'   => $oid,
                ':uid'   => $userId > 0 ? $userId : null,
                ':vname' => $voterName,
                ':val'   => $rawVal,
            ]);
        }

        setFlash('success', 'Deine Abstimmung wurde gespeichert, ' . $voterName . '!');
        header('Location: polls.php' . ($postToken !== '' ? '?token=' . urlencode($postToken) : '#poll-' . $pollId));
        exit;
    }

    // Alle weiteren Aktionen erfordern Login
    requireLogin();

    // 2. Neue Abstimmung erstellen
    if ($action === 'create_poll') {
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $description = trim((string)($_POST['description'] ?? ''));
        $location    = mb_substr(trim((string)($_POST['location'] ?? '')), 0, 190);
        $pollType    = ($_POST['poll_type'] ?? 'date') === 'choice' ? 'choice' : 'date';
        $allowMaybe  = isset($_POST['allow_maybe']) && (string)$_POST['allow_maybe'] === '1' ? 1 : 0;
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        $sharedUserId = null;
        $groupId      = null;
        if (str_starts_with($shareTarget, 'user:')) {
            $candidateUid = max(0, (int)substr($shareTarget, 5));
            if ($candidateUid > 0) {
                $stmtVis = $pdo->prepare('SELECT id FROM users WHERE id = :id AND allow_user_sharing = 1 LIMIT 1');
                $stmtVis->execute([':id' => $candidateUid]);
                if ($stmtVis->fetchColumn() !== false) {
                    $sharedUserId = $candidateUid;
                }
            }
        } elseif (str_starts_with($shareTarget, 'group:')) {
            $candidateGid = max(0, (int)substr($shareTarget, 6));
            if ($candidateGid > 0) {
                if (isAdmin()) {
                    $groupId = $candidateGid;
                } else {
                    $stmtGm = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
                    $stmtGm->execute([':gid' => $candidateGid, ':uid' => $userId]);
                    if ($stmtGm->fetchColumn() !== false) {
                        $groupId = $candidateGid;
                    }
                }
            }
        }

        $optionsToInsert = [];
        if ($pollType === 'date') {
            $dates      = isset($_POST['opt_date']) && is_array($_POST['opt_date']) ? $_POST['opt_date'] : [];
            $startTimes = isset($_POST['opt_start']) && is_array($_POST['opt_start']) ? $_POST['opt_start'] : [];
            $endTimes   = isset($_POST['opt_end']) && is_array($_POST['opt_end']) ? $_POST['opt_end'] : [];
            $notes      = isset($_POST['opt_note']) && is_array($_POST['opt_note']) ? $_POST['opt_note'] : [];

            $weekdaysDe = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            foreach ($dates as $idx => $dStr) {
                $dStr = trim((string)$dStr);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dStr)) {
                    continue;
                }
                $st = trim((string)($startTimes[$idx] ?? ''));
                $et = trim((string)($endTimes[$idx] ?? ''));
                $nt = trim((string)($notes[$idx] ?? ''));

                $ts = strtotime($dStr);
                $wd = $weekdaysDe[(int)date('w', $ts ?: time())];
                $label = $wd . ', ' . date('d.m.Y', $ts ?: time());

                $startAtDb = $dStr . ' 09:00:00';
                $endAtDb   = $dStr . ' 10:00:00';

                if (preg_match('/^\d{2}:\d{2}$/', $st)) {
                    $label .= ' (' . $st;
                    $startAtDb = $dStr . ' ' . $st . ':00';
                    if (preg_match('/^\d{2}:\d{2}$/', $et)) {
                        $label .= '–' . $et . ' Uhr)';
                        $endAtDb = $dStr . ' ' . $et . ':00';
                    } else {
                        $label .= ' Uhr)';
                        $endAtDb = date('Y-m-d H:i:s', (strtotime($startAtDb) ?: time()) + 3600);
                    }
                } else {
                    $startAtDb = $dStr . ' 00:00:00';
                    $endAtDb   = $dStr . ' 23:59:59';
                }

                if ($nt !== '') {
                    $label .= ' · ' . $nt;
                }

                $optionsToInsert[] = [
                    'label'    => mb_substr($label, 0, 255),
                    'start_at' => $startAtDb,
                    'end_at'   => $endAtDb,
                ];
            }
        } else {
            $choices = isset($_POST['opt_choice']) && is_array($_POST['opt_choice']) ? $_POST['opt_choice'] : [];
            foreach ($choices as $ch) {
                $ch = trim((string)$ch);
                if ($ch !== '') {
                    $optionsToInsert[] = [
                        'label'    => mb_substr($ch, 0, 255),
                        'start_at' => null,
                        'end_at'   => null,
                    ];
                }
            }
        }

        if ($title === '' || count($optionsToInsert) < 1) {
            setFlash('error', 'Bitte gib einen Titel und mindestens eine Termin- oder Auswahl-Option an.');
            header('Location: polls.php');
            exit;
        }

        $shareToken = bin2hex(random_bytes(20));
        $stmtIns = $pdo->prepare(
            'INSERT INTO polls (user_id, title, description, location, poll_type, allow_maybe, share_token, shared_user_id, group_id)
             VALUES (:uid, :title, :descr, :loc, :ptype, :maybe, :token, :suid, :gid)'
        );
        $stmtIns->execute([
            ':uid'   => $userId,
            ':title' => $title,
            ':descr' => $description,
            ':loc'   => $location,
            ':ptype' => $pollType,
            ':maybe' => $allowMaybe,
            ':token' => $shareToken,
            ':suid'  => $sharedUserId,
            ':gid'   => $groupId,
        ]);
        $newPollId = (int)$pdo->lastInsertId();

        $stmtInsOpt = $pdo->prepare(
            'INSERT INTO poll_options (poll_id, option_label, start_at, end_at, sort_order)
             VALUES (:pid, :lbl, :sa, :ea, :ord)'
        );
        foreach ($optionsToInsert as $ord => $opt) {
            $stmtInsOpt->execute([
                ':pid' => $newPollId,
                ':lbl' => $opt['label'],
                ':sa'  => $opt['start_at'],
                ':ea'  => $opt['end_at'],
                ':ord' => $ord,
            ]);
        }

        logAuditEvent($pdo, 'poll_created', 'Umfrage/Terminfinder erstellt: ' . $title, $userId, $username);
        setFlash('success', 'Die Abstimmung „' . $title . '“ wurde erfolgreich angelegt.');
        header('Location: polls.php#poll-' . $newPollId);
        exit;
    }

    // 3. Gewinner-Termin mit 1 Klick in den Kalender übernehmen
    if ($action === 'transfer_to_calendar') {
        $pollId   = (int)($_POST['poll_id'] ?? 0);
        $optionId = (int)($_POST['option_id'] ?? 0);

        $stmtPoll = $pdo->prepare('SELECT * FROM polls WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmtPoll->execute([':id' => $pollId, ':uid' => $userId]);
        $pollRow = $stmtPoll->fetch();

        $stmtOpt = $pdo->prepare('SELECT * FROM poll_options WHERE id = :oid AND poll_id = :pid LIMIT 1');
        $stmtOpt->execute([':oid' => $optionId, ':pid' => $pollId]);
        $optRow = $stmtOpt->fetch();

        if ($pollRow && $optRow && !empty($optRow['start_at'])) {
            $startAt = (string)$optRow['start_at'];
            $endAt   = !empty($optRow['end_at']) ? (string)$optRow['end_at'] : $startAt;
            $allDay  = str_ends_with($startAt, '00:00:00') && str_ends_with($endAt, '23:59:59') ? 1 : 0;

            $stmtCal = $pdo->prepare(
                "INSERT INTO calendar_events
                 (user_id, title, description, location, start_at, end_at, all_day, recurrence, color, group_id, shared_user_id, ical_uid)
                 VALUES (:uid, :title, :descr, :loc, :sa, :ea, :allday, 'none', '#10b981', :gid, :suid, :iuid)"
            );
            $stmtCal->execute([
                ':uid'    => $userId,
                ':title'  => mb_substr((string)$pollRow['title'], 0, 190),
                ':descr'  => trim((string)$pollRow['description'] . "\n\nÜbernommen aus Terminfinder: " . $optRow['option_label']),
                ':loc'    => mb_substr((string)$pollRow['location'], 0, 190),
                ':sa'     => $startAt,
                ':ea'     => $endAt,
                ':allday' => $allDay,
                ':gid'    => $pollRow['group_id'] ?: null,
                ':suid'   => $pollRow['shared_user_id'] ?: null,
                ':iuid'   => 'minicloud-poll-' . $pollId . '-' . $optionId . '@minicloud',
            ]);

            // Abstimmung optional direkt als abgeschlossen markieren
            $pdo->prepare('UPDATE polls SET is_closed = 1 WHERE id = :id')->execute([':id' => $pollId]);

            setFlash('success', 'Der Termin „' . $pollRow['title'] . '“ (' . $optRow['option_label'] . ') wurde direkt in deinen Kalender eingetragen!');
            header('Location: calendar.php');
            exit;
        }

        setFlash('error', 'Termin konnte nicht übernommen werden.');
        header('Location: polls.php');
        exit;
    }

    // 4. Abstimmung schließen / wieder öffnen
    if ($action === 'toggle_close_poll') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $pdo->prepare('UPDATE polls SET is_closed = IF(is_closed = 1, 0, 1) WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $pollId, ':uid' => $userId]);
        setFlash('success', 'Status der Abstimmung wurde aktualisiert.');
        header('Location: polls.php#poll-' . $pollId);
        exit;
    }

    // 5. Abstimmung löschen
    if ($action === 'delete_poll') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $pdo->prepare('DELETE FROM polls WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $pollId, ':uid' => $userId]);
        setFlash('success', 'Die Abstimmung wurde gelöscht.');
        header('Location: polls.php');
        exit;
    }
}

// Umfragen laden
$polls = [];
if ($publicToken !== '') {
    $stmtP = $pdo->prepare(
        'SELECT p.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username
         FROM polls p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         LEFT JOIN users su ON su.id = p.shared_user_id
         WHERE p.share_token = :tok
         LIMIT 1'
    );
    $stmtP->execute([':tok' => $publicToken]);
    $row = $stmtP->fetch();
    if ($row) {
        $polls[] = $row;
    } else {
        http_response_code(404);
        exit('Die angeforderte Abstimmung existiert nicht oder der Link ist ungültig.');
    }
} else {
    $userGroups = getGroupsForUser($pdo, $userId);
    $groupIds   = array_map(static fn($g) => (int)$g['id'], $userGroups);

    $sql = 'SELECT p.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username
            FROM polls p
            INNER JOIN users u ON u.id = p.user_id
            LEFT JOIN `groups` g ON g.id = p.group_id
            LEFT JOIN users su ON su.id = p.shared_user_id
            WHERE p.user_id = :uid1 OR p.shared_user_id = :uid2';
    $params = [':uid1' => $userId, ':uid2' => $userId];

    if (!empty($groupIds)) {
        $phs = [];
        foreach ($groupIds as $idx => $gid) {
            $ph = ':gid_' . $idx;
            $phs[] = $ph;
            $params[$ph] = $gid;
        }
        $sql .= ' OR p.group_id IN (' . implode(',', $phs) . ')';
    }
    $sql .= ' ORDER BY p.is_closed ASC, p.created_at DESC';
    $stmtP = $pdo->prepare($sql);
    $stmtP->execute($params);
    $polls = $stmtP->fetchAll();
}

$visibleUsers = isLoggedIn() ? getVisibleUsersForUser($pdo, $userId) : [];
$userGroups   = isLoggedIn() ? getGroupsForUser($pdo, $userId) : [];

$pageTitle = 'Terminfinder & Umfragen';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">
                    📊
                </span>
                <span>Terminfinder &amp; Umfragen</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                Gemeinsame Termine &amp; Entscheidungen abstimmen – intern mit Cloud-Gruppen oder per Link mit Gästen ohne eigenes Konto.
            </p>
        </div>

        <?php if (isLoggedIn()): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php if ($publicToken !== ''): ?>
                    <a href="polls.php" class="px-3.5 py-2 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                        ← Alle Umfragen
                    </a>
                <?php endif; ?>
                <a
                    href="menti.php"
                    class="px-3.5 py-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 font-semibold text-xs sm:text-sm transition-colors"
                >
                    🎤 Live-Mentimeter (Wortwolke &amp; Quiz)
                </a>
                <button
                    type="button"
                    onclick="document.getElementById('createPollModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                >
                    ➕ Neue Abstimmung erstellen
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($polls)): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center shadow-xs">
            <div class="text-4xl mb-3">📊</div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white mb-1">Noch keine Abstimmungen vorhanden</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto mb-5">
                Erstelle eine Termin-Abstimmung (wie Doodle) oder eine Auswahl-Umfrage und teile den Link mit deinen Kontakten, Gruppen oder Gästen.
            </p>
            <?php if (isLoggedIn()): ?>
                <button
                    type="button"
                    onclick="document.getElementById('createPollModal').classList.remove('hidden')"
                    class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                >
                    ➕ Erste Abstimmung anlegen
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($polls as $poll):
            $pid        = (int)$poll['id'];
            $isOwner    = ($userId > 0 && (int)$poll['user_id'] === $userId);
            $isClosed   = ((int)$poll['is_closed'] === 1);
            $allowMaybe = ((int)$poll['allow_maybe'] === 1);
            $publicLink = getBaseUrl() . '/polls.php?token=' . urlencode((string)$poll['share_token']);

            $stmtOpts = $pdo->prepare('SELECT * FROM poll_options WHERE poll_id = :pid ORDER BY sort_order ASC, id ASC');
            $stmtOpts->execute([':pid' => $pid]);
            $options = $stmtOpts->fetchAll();

            $stmtVotes = $pdo->prepare('SELECT * FROM poll_votes WHERE poll_id = :pid ORDER BY id ASC');
            $stmtVotes->execute([':pid' => $pid]);
            $allVotes = $stmtVotes->fetchAll();

            // Teilnehmer & Stimmen-Matrix aufbauen
            $participants = [];
            $optionTotals = [];
            foreach ($options as $opt) {
                $optionTotals[(int)$opt['id']] = ['yes' => 0, 'maybe' => 0, 'no' => 0, 'score' => 0];
            }

            foreach ($allVotes as $v) {
                $vName = (string)$v['voter_name'];
                $oid   = (int)$v['option_id'];
                $val   = (string)$v['vote_value'];
                if (!isset($participants[$vName])) {
                    $participants[$vName] = [];
                }
                $participants[$vName][$oid] = $val;
                if (isset($optionTotals[$oid])) {
                    if ($val === 'yes') {
                        $optionTotals[$oid]['yes']++;
                        $optionTotals[$oid]['score'] += 2;
                    } elseif ($val === 'maybe') {
                        $optionTotals[$oid]['maybe']++;
                        $optionTotals[$oid]['score'] += 1;
                    } else {
                        $optionTotals[$oid]['no']++;
                    }
                }
            }

            $maxScore = 0;
            foreach ($optionTotals as $tot) {
                if ($tot['score'] > $maxScore) {
                    $maxScore = $tot['score'];
                }
            }

            $myCurrentVotes = ($username !== '' && isset($participants[$username])) ? $participants[$username] : [];
        ?>
            <section id="poll-<?= $pid ?>" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold <?= $poll['poll_type'] === 'date' ? 'bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300' : 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300' ?>">
                                <?= $poll['poll_type'] === 'date' ? '📅 Terminfinder' : '📊 Umfrage' ?>
                            </span>
                            <?php if ($isClosed): ?>
                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                    🔒 Abgeschlossen
                                </span>
                            <?php else: ?>
                                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300">
                                    🟢 Offen für Stimmen
                                </span>
                            <?php endif; ?>
                            <span class="text-xs text-slate-500 dark:text-slate-400">
                                von <strong><?= e((string)$poll['owner_name']) ?></strong>
                                <?php if (!empty($poll['group_name'])): ?>
                                    · 👥 Gruppe: <strong><?= e((string)$poll['group_name']) ?></strong>
                                <?php elseif (!empty($poll['shared_username'])): ?>
                                    · 👤 Geteilt mit: <strong><?= e((string)$poll['shared_username']) ?></strong>
                                <?php endif; ?>
                            </span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white mt-1.5">
                            <?= e((string)$poll['title']) ?>
                        </h2>
                        <?php if (!empty($poll['location'])): ?>
                            <div class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                                📍 Ort: <strong><?= e((string)$poll['location']) ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($poll['description'])): ?>
                            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 mt-1 whitespace-pre-line"><?= e((string)$poll['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText('<?= e($publicLink) ?>'); this.textContent='✅ Gäste-Link kopiert!'; setTimeout(()=>this.textContent='🔗 Abstimmungs-Link kopieren', 2000);"
                            class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 border border-slate-300 dark:border-slate-700 cursor-pointer"
                        >
                            🔗 Abstimmungs-Link kopieren
                        </button>

                        <?php if ($isOwner): ?>
                            <form action="polls.php" method="post" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_close_poll">
                                <input type="hidden" name="poll_id" value="<?= $pid ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 cursor-pointer">
                                    <?= $isClosed ? '🔓 Wieder öffnen' : '🔒 Abschließen' ?>
                                </button>
                            </form>
                            <form action="polls.php" method="post" class="inline" onsubmit="return confirm('Diese Abstimmung samt aller abgegebenen Stimmen wirklich löschen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_poll">
                                <input type="hidden" name="poll_id" value="<?= $pid ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-xl bg-red-600/10 hover:bg-red-600 text-red-600 hover:text-white text-xs font-semibold transition-colors cursor-pointer">
                                    🗑️ Löschen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Abstimmungs-Matrix -->
                <div class="overflow-x-auto border border-slate-200 dark:border-slate-800 rounded-xl">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/70 border-b border-slate-200 dark:border-slate-800">
                                <th class="p-3 font-bold text-slate-700 dark:text-slate-200 min-w-[160px]">
                                    Teilnehmer (<?= count($participants) ?>)
                                </th>
                                <?php foreach ($options as $opt):
                                    $oid    = (int)$opt['id'];
                                    $tot    = $optionTotals[$oid];
                                    $isBest = ($maxScore > 0 && $tot['score'] === $maxScore);
                                ?>
                                    <th class="p-3 text-center min-w-[140px] border-l border-slate-200 dark:border-slate-800 <?= $isBest ? 'bg-emerald-50/80 dark:bg-emerald-950/30' : '' ?>">
                                        <div class="font-bold text-slate-900 dark:text-white">
                                            <?php if ($isBest): ?>
                                                <span title="Favorit mit den meisten Stimmen">🏆</span>
                                            <?php endif; ?>
                                            <?= e((string)$opt['option_label']) ?>
                                        </div>
                                        <div class="text-[11px] font-normal text-slate-500 dark:text-slate-400 mt-1">
                                            ✅ <?= $tot['yes'] ?>
                                            <?php if ($allowMaybe): ?>
                                                · 🤔 <?= $tot['maybe'] ?>
                                            <?php endif; ?>
                                            · ❌ <?= $tot['no'] ?>
                                        </div>
                                        <?php if ($isOwner && $poll['poll_type'] === 'date' && !empty($opt['start_at'])): ?>
                                            <form action="polls.php" method="post" class="mt-1.5">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="transfer_to_calendar">
                                                <input type="hidden" name="poll_id" value="<?= $pid ?>">
                                                <input type="hidden" name="option_id" value="<?= $oid ?>">
                                                <button
                                                    type="submit"
                                                    class="px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-semibold cursor-pointer"
                                                    title="Diesen Termin direkt in den Kalender übernehmen"
                                                >
                                                    📅 In Kalender übernehmen
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php foreach ($participants as $pName => $pVotes): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td class="p-3 font-semibold text-slate-800 dark:text-slate-200">
                                        👤 <?= e($pName) ?>
                                    </td>
                                    <?php foreach ($options as $opt):
                                        $oid  = (int)$opt['id'];
                                        $vVal = $pVotes[$oid] ?? 'no';
                                    ?>
                                        <td class="p-3 text-center border-l border-slate-200 dark:border-slate-800">
                                            <?php if ($vVal === 'yes'): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 font-bold text-xs">✅ Ja</span>
                                            <?php elseif ($vVal === 'maybe'): ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 font-bold text-xs">🤔 Vielleicht</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-rose-100/70 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 text-xs">❌ Nein</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$isClosed): ?>
                                <!-- Eigene Stimme eintragen / ändern -->
                                <tr class="bg-brand-50/40 dark:bg-slate-800/50">
                                    <td colspan="<?= count($options) + 1 ?>" class="p-0">
                                        <form action="polls.php<?= $publicToken !== '' ? '?token=' . urlencode($publicToken) : '' ?>" method="post" class="p-3">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="submit_vote">
                                            <input type="hidden" name="poll_id" value="<?= $pid ?>">
                                            <input type="hidden" name="share_token" value="<?= e((string)$poll['share_token']) ?>">

                                            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-center">
                                                <div class="lg:col-span-3">
                                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Dein Name:</label>
                                                    <input
                                                        type="text"
                                                        name="voter_name"
                                                        required
                                                        maxlength="100"
                                                        value="<?= e($username) ?>"
                                                        placeholder="Vor- / Nachname…"
                                                        class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                                                    >
                                                </div>

                                                <div class="lg:col-span-7 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                                                    <?php foreach ($options as $opt):
                                                        $oid    = (int)$opt['id'];
                                                        $curVal = $myCurrentVotes[$oid] ?? 'yes';
                                                    ?>
                                                        <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                                                            <div class="text-[11px] font-bold text-slate-700 dark:text-slate-200 truncate mb-1" title="<?= e((string)$opt['option_label']) ?>">
                                                                <?= e((string)$opt['option_label']) ?>
                                                            </div>
                                                            <select
                                                                name="votes[<?= $oid ?>]"
                                                                class="w-full px-2 py-1 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-white"
                                                            >
                                                                <option value="yes" <?= $curVal === 'yes' ? 'selected' : '' ?>>✅ Ja</option>
                                                                <?php if ($allowMaybe): ?>
                                                                    <option value="maybe" <?= $curVal === 'maybe' ? 'selected' : '' ?>>🤔 Unter Vorbehalt</option>
                                                                <?php endif; ?>
                                                                <option value="no" <?= $curVal === 'no' ? 'selected' : '' ?>>❌ Nein</option>
                                                            </select>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="lg:col-span-2 flex justify-end">
                                                    <button
                                                        type="submit"
                                                        class="w-full px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs cursor-pointer"
                                                    >
                                                        🗳️ Abstimmen
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (isLoggedIn()): ?>
<!-- Modal: Neue Abstimmung erstellen -->
<div id="createPollModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-2xl w-full p-5 sm:p-6 shadow-2xl my-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">➕ Neue Abstimmung / Terminfinder erstellen</h2>
            <button type="button" onclick="document.getElementById('createPollModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-lg cursor-pointer">✕</button>
        </div>

        <form action="polls.php" method="post" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_poll">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Art der Abstimmung</label>
                    <select
                        name="poll_type"
                        id="pollTypeSelect"
                        onchange="togglePollTypeFields(this.value)"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-900 dark:text-white"
                    >
                        <option value="date">📅 Terminfinder (Datum &amp; Uhrzeit)</option>
                        <option value="choice">📊 Auswahl-Umfrage (Freitext-Optionen)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Intern teilen mit (optional)</label>
                    <select
                        name="share_target"
                        class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white"
                    >
                        <option value="">🔒 Nur ich (+ Gäste mit Abstimmungs-Link)</option>
                        <?php if (!empty($userGroups)): ?>
                            <optgroup label="👥 Benutzergruppen">
                                <?php foreach ($userGroups as $grp): ?>
                                    <option value="group:<?= (int)$grp['id'] ?>">👥 <?= e((string)$grp['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($visibleUsers)): ?>
                            <optgroup label="👤 Einzelne Cloud-Benutzer">
                                <?php foreach ($visibleUsers as $vu): ?>
                                    <option value="user:<?= (int)$vu['id'] ?>">👤 <?= e((string)$vu['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Titel / Anlass *</label>
                    <input
                        type="text"
                        name="title"
                        required
                        maxlength="255"
                        placeholder="z. B. Sommerfest, Projekttreffen, Vereinsabend…"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white"
                    >
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Ort / Treffpunkt (optional)</label>
                    <input
                        type="text"
                        name="location"
                        maxlength="190"
                        placeholder="z. B. Vereinsheim, Konferenzraum, Video-Call…"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white"
                    >
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung / Hinweise (optional)</label>
                <textarea
                    name="description"
                    rows="2"
                    placeholder="Weitere Infos zur Abstimmung…"
                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white"
                ></textarea>
            </div>

            <!-- Termin-Optionen -->
            <div id="dateOptionsBox" class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-slate-700 dark:text-slate-300">📅 Termin-Vorschläge (Datum, Start, Ende, Zusatz):</label>
                    <button type="button" onclick="addPollDateRow()" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-brand-600 dark:text-brand-400 cursor-pointer">
                        + Weiterer Termin
                    </button>
                </div>
                <div id="pollDateRows" class="space-y-2">
                    <?php for ($i = 1; $i <= 3; $i++):
                        $defDate = date('Y-m-d', strtotime("+{$i} days"));
                    ?>
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <input type="date" name="opt_date[]" value="<?= $defDate ?>" class="col-span-4 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                            <input type="time" name="opt_start[]" value="18:00" class="col-span-3 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                            <input type="time" name="opt_end[]" value="20:00" class="col-span-3 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                            <input type="text" name="opt_note[]" placeholder="Notiz" class="col-span-2 px-2 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Freitext-Optionen -->
            <div id="choiceOptionsBox" class="hidden space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-slate-700 dark:text-slate-300">📊 Auswahl-Optionen:</label>
                    <button type="button" onclick="addPollChoiceRow()" class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-semibold text-brand-600 dark:text-brand-400 cursor-pointer">
                        + Weitere Option
                    </button>
                </div>
                <div id="pollChoiceRows" class="space-y-2">
                    <input type="text" name="opt_choice[]" placeholder="Option 1 (z. B. Italienisches Restaurant)" class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    <input type="text" name="opt_choice[]" placeholder="Option 2 (z. B. Grillabend im Garten)" class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    <input type="text" name="opt_choice[]" placeholder="Option 3 (optional)" class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <label class="flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                <input type="checkbox" name="allow_maybe" value="1" checked class="rounded border-slate-300">
                <span>Antwort-Option „🤔 Unter Vorbehalt / Vielleicht“ erlauben</span>
            </label>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('createPollModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs cursor-pointer">
                    📊 Abstimmung starten
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePollTypeFields(type) {
    document.getElementById('dateOptionsBox').classList.toggle('hidden', type !== 'date');
    document.getElementById('choiceOptionsBox').classList.toggle('hidden', type !== 'choice');
}
function addPollDateRow() {
    const container = document.getElementById('pollDateRows');
    const div = document.createElement('div');
    div.className = 'grid grid-cols-12 gap-2 items-center';
    div.innerHTML = `
        <input type="date" name="opt_date[]" class="col-span-4 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
        <input type="time" name="opt_start[]" value="18:00" class="col-span-3 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
        <input type="time" name="opt_end[]" value="20:00" class="col-span-3 px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
        <input type="text" name="opt_note[]" placeholder="Notiz" class="col-span-2 px-2 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
    `;
    container.appendChild(div);
}
function addPollChoiceRow() {
    const container = document.getElementById('pollChoiceRows');
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'opt_choice[]';
    input.placeholder = 'Weitere Auswahl-Option…';
    input.className = 'w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white';
    container.appendChild(input);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
