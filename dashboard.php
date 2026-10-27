<?php
/**
 * uWiiiMiniCloud – Persönliches Tages-Cockpit („Mein Tag“-Dashboard, dashboard.php)
 * Verbindet alle Cloud-Module auf einen Blick:
 * - Anstehende Termine (nächste 14 Tage)
 * - Anstehende Geburtstage aus dem Adressbuch (nächste 30 Tage)
 * - Offene & fällige Aufgaben (inkl. 1-Klick-Erledigen)
 * - Aktive Umfragen / Terminfinder & Live-Mentimeter
 * - Angepinnte Notizen & ungelesene Chat-Nachrichten
 * - Zuletzt bearbeitete Dateien & Speicherplatz
 * - Individuelle Ein-/Ausblendung aller Cockpit-Kacheln per Sternchen (⭐ / ☆)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');

if (($appSettings['enable_dashboard'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Tages-Cockpit ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

// 1-Klick-Aufgabe erledigen direkt im Cockpit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_task_quick') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = :id AND (user_id = :uid1 OR shared_user_id = :uid2)")
                ->execute([':id' => $taskId, ':uid1' => $userId, ':uid2' => $userId]);
            setFlash('success', 'Aufgabe als erledigt markiert! ✅');
        }
    }
    header('Location: dashboard.php');
    exit;
}

$userGroups = getGroupsForUser($pdo, $userId);
$groupIds   = array_map(static fn($g) => (int)$g['id'], $userGroups);
$storage    = getUserStorageStats($pdo, $userId);
$unreadChat = getUnreadMessagesCount($pdo, $userId);

// 1. Anstehende Termine (nächste 14 Tage)
$upcomingEvents = [];
if (($appSettings['enable_calendar'] ?? '1') === '1') {
    try {
        $allDavEvents = getAccessibleCalendarEventsForDav($pdo, $userId);
        $todayStart   = strtotime('today 00:00:00');
        $windowEnd    = strtotime('+14 days 23:59:59');
        foreach ($allDavEvents as $ev) {
            $st = strtotime((string)$ev['start_at']) ?: 0;
            $et = strtotime((string)$ev['end_at']) ?: $st;
            if ($et >= $todayStart && $st <= $windowEnd) {
                $upcomingEvents[] = $ev;
            }
        }
        usort($upcomingEvents, static fn($a, $b) => strcmp((string)$a['start_at'], (string)$b['start_at']));
        $upcomingEvents = array_slice($upcomingEvents, 0, 8);
    } catch (Throwable) {
        $upcomingEvents = [];
    }
}

// 2. Anstehende Geburtstage aus Kontakten (nächste 30 Tage)
$upcomingBirthdays = [];
if (($appSettings['enable_contacts'] ?? '1') === '1') {
    try {
        $allContacts = getAccessibleContactsForUser($pdo, $userId);
        $todayYmd    = date('Y-m-d');
        $currentYear = (int)date('Y');
        $todayTs     = strtotime($todayYmd . ' 00:00:00');

        foreach ($allContacts as $c) {
            $bday = (string)($c['birthday'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bday, $m)) {
                continue;
            }
            $birthYear = (int)$m[1];
            $monthDay  = $m[2] . '-' . $m[3];
            $nextDate  = $currentYear . '-' . $monthDay;
            if ($nextDate < $todayYmd) {
                $nextDate = ($currentYear + 1) . '-' . $monthDay;
            }
            $nextTs   = strtotime($nextDate . ' 00:00:00');
            $diffDays = (int)round(($nextTs - $todayTs) / 86400);
            if ($diffDays >= 0 && $diffDays <= 30) {
                $fullName = trim($c['first_name'] . ' ' . $c['last_name']);
                if ($fullName === '') {
                    $fullName = (string)$c['organization'];
                }
                $turningAge = $birthYear > 1900 ? ((int)substr($nextDate, 0, 4) - $birthYear) : null;
                $upcomingBirthdays[] = [
                    'name'       => $fullName,
                    'date_label' => date('d.m.', $nextTs),
                    'diff_days'  => $diffDays,
                    'age'        => $turningAge,
                ];
            }
        }
        usort($upcomingBirthdays, static fn($a, $b) => $a['diff_days'] <=> $b['diff_days']);
    } catch (Throwable) {
        $upcomingBirthdays = [];
    }
}

// 3. Offene Aufgaben (todo & in_progress)
$openTasks = [];
if (($appSettings['enable_tasks'] ?? '1') === '1') {
    try {
        $sqlTasks = "SELECT * FROM tasks WHERE status <> 'done' AND (user_id = :uid1 OR shared_user_id = :uid2";
        $tParams  = [':uid1' => $userId, ':uid2' => $userId];
        if (!empty($groupIds)) {
            $phs = [];
            foreach ($groupIds as $i => $gid) {
                $ph = ':tg_' . $i;
                $phs[] = $ph;
                $tParams[$ph] = $gid;
            }
            $sqlTasks .= ' OR group_id IN (' . implode(',', $phs) . ')';
        }
        $sqlTasks .= ") ORDER BY CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC, FIELD(priority, 'high', 'medium', 'low') LIMIT 8";
        $stmtT = $pdo->prepare($sqlTasks);
        $stmtT->execute($tParams);
        $openTasks = $stmtT->fetchAll();
    } catch (Throwable) {
        $openTasks = [];
    }
}

// 4. Angepinnte Notizen
$pinnedNotes = [];
if (($appSettings['enable_notes'] ?? '1') === '1') {
    try {
        $sqlNotes = "SELECT * FROM notes WHERE is_pinned = 1 AND (user_id = :uid1 OR shared_user_id = :uid2";
        $nParams  = [':uid1' => $userId, ':uid2' => $userId];
        if (!empty($groupIds)) {
            $phs = [];
            foreach ($groupIds as $i => $gid) {
                $ph = ':ng_' . $i;
                $phs[] = $ph;
                $nParams[$ph] = $gid;
            }
            $sqlNotes .= ' OR group_id IN (' . implode(',', $phs) . ')';
        }
        $sqlNotes .= ') ORDER BY updated_at DESC LIMIT 4';
        $stmtN = $pdo->prepare($sqlNotes);
        $stmtN->execute($nParams);
        $pinnedNotes = $stmtN->fetchAll();
    } catch (Throwable) {
        $pinnedNotes = [];
    }
}

// 5. Offene Umfragen / Terminfinder
$activePolls = [];
if (($appSettings['enable_polls'] ?? '1') === '1') {
    try {
        $sqlPolls = "SELECT p.*, u.username AS owner_name FROM polls p INNER JOIN users u ON u.id = p.user_id WHERE p.is_closed = 0 AND (p.user_id = :uid1 OR p.shared_user_id = :uid2";
        $pParams  = [':uid1' => $userId, ':uid2' => $userId];
        if (!empty($groupIds)) {
            $phs = [];
            foreach ($groupIds as $i => $gid) {
                $ph = ':pg_' . $i;
                $phs[] = $ph;
                $pParams[$ph] = $gid;
            }
            $sqlPolls .= ' OR p.group_id IN (' . implode(',', $phs) . ')';
        }
        $sqlPolls .= ') ORDER BY p.created_at DESC LIMIT 4';
        $stmtP = $pdo->prepare($sqlPolls);
        $stmtP->execute($pParams);
        $activePolls = $stmtP->fetchAll();
    } catch (Throwable) {
        $activePolls = [];
    }
}

// 6. Eigene Live-Mentimeter-Präsentationen
$myMentiSessions = [];
if (($appSettings['enable_menti'] ?? '1') === '1') {
    try {
        $stmtM = $pdo->prepare("
            SELECT m.*,
                   (SELECT COUNT(*) FROM `menti_slides` s WHERE s.`session_id` = m.`id`) AS `slide_count`,
                   (SELECT COUNT(DISTINCT r.`participant_token`) FROM `menti_responses` r WHERE r.`session_id` = m.`id`) AS `participant_count`
            FROM `menti_sessions` m
            WHERE m.`user_id` = :uid
            ORDER BY m.`created_at` DESC
            LIMIT 4
        ");
        $stmtM->execute([':uid' => $userId]);
        $myMentiSessions = $stmtM->fetchAll();
    } catch (Throwable) {
        $myMentiSessions = [];
    }
}

// 7. Zuletzt hochgeladene Dateien
$recentFiles = [];
try {
    $stmtF = $pdo->prepare(
        'SELECT * FROM files
         WHERE user_id = :uid AND deleted_at IS NULL
         ORDER BY upload_date DESC
         LIMIT 6'
    );
    $stmtF->execute([':uid' => $userId]);
    $recentFiles = $stmtF->fetchAll();
} catch (Throwable) {
    $recentFiles = [];
}

$hour = (int)date('G');
if ($hour < 11) {
    $greeting = '☀️ Guten Morgen';
} elseif ($hour < 18) {
    $greeting = '👋 Guten Tag';
} else {
    $greeting = '🌙 Guten Abend';
}

$weekdaysFull = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$todayFormatted = $weekdaysFull[(int)date('w')] . ', ' . date('d.m.Y');

// Liste der im Cockpit per ⭐ ein-/ausblendbaren Bereiche (abhängig von den global aktivierten Modulen)
$availableDashWidgets = [
    'quicklinks' => ['icon' => '⚡', 'label' => 'Schnellzugriff-Leiste'],
];
if (($appSettings['enable_calendar'] ?? '1') === '1') {
    $availableDashWidgets['events'] = ['icon' => '📅', 'label' => 'Anstehende Termine'];
}
if (($appSettings['enable_contacts'] ?? '1') === '1') {
    $availableDashWidgets['birthdays'] = ['icon' => '🎂', 'label' => 'Nächste Geburtstage'];
}
if (($appSettings['enable_tasks'] ?? '1') === '1') {
    $availableDashWidgets['tasks'] = ['icon' => '✅', 'label' => 'Offene Aufgaben'];
}
if (($appSettings['enable_polls'] ?? '1') === '1') {
    $availableDashWidgets['polls'] = ['icon' => '📊', 'label' => 'Aktive Abstimmungen'];
}
if (($appSettings['enable_menti'] ?? '1') === '1') {
    $availableDashWidgets['menti'] = ['icon' => '🎤', 'label' => 'Live-Mentimeter'];
}
if (($appSettings['enable_notes'] ?? '1') === '1') {
    $availableDashWidgets['notes'] = ['icon' => '📌', 'label' => 'Angepinnte Notizen'];
}
$availableDashWidgets['files'] = ['icon' => '📂', 'label' => 'Zuletzt hochgeladen'];

$pageTitle = 'Mein Tag – Cockpit';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Begrüßung, ⭐ Cockpit-Anpassung & Schnellzugriff-Kacheln -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
            <div>
                <div class="text-xs font-bold uppercase tracking-wider text-brand-600 dark:text-brand-400 mb-1">
                    🏠 Persönliches Tages-Cockpit · <?= e($todayFormatted) ?>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                    <?= $greeting ?>, <?= e($username) ?>!
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Hier sind deine wichtigsten Inhalte auf einen Blick – über <strong>⭐ Inhalte anpassen</strong> kannst du einzelne Bereiche jederzeit ein- oder ausblenden.
                </p>
            </div>

            <!-- ⭐ Button & Popover zum Ein-/Ausblenden der Dashboard-Inhalte per Sternchen -->
            <div class="relative shrink-0 self-start">
                <button
                    type="button"
                    id="dashCustomizeBtn"
                    onclick="document.getElementById('dashCustomizeMenu').classList.toggle('hidden')"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/70 text-xs sm:text-sm font-bold transition-colors cursor-pointer shadow-2xs"
                    title="Cockpit-Bereiche per Sternchen (⭐ / ☆) ein- oder ausblenden"
                >
                    <span>⭐</span>
                    <span>Inhalte anpassen</span>
                </button>

                <div
                    id="dashCustomizeMenu"
                    class="hidden fixed sm:absolute right-2 sm:right-0 top-20 sm:top-full sm:mt-2 w-[calc(100vw-1rem)] max-w-[330px] rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl p-4 z-50"
                >
                    <div class="flex items-center justify-between gap-2 pb-2.5 mb-2.5 border-b border-slate-200/80 dark:border-slate-800">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">🏠 Cockpit-Bereiche</span>
                        <span class="text-[11px] text-slate-400 dark:text-slate-500">⭐ = Sichtbar · ☆ = Ausgeblendet</span>
                    </div>

                    <div class="space-y-1.5">
                        <?php foreach ($availableDashWidgets as $wKey => $wMeta): ?>
                            <button
                                type="button"
                                onclick="toggleDashWidget('<?= e($wKey) ?>', event)"
                                class="w-full flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50/60 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-slate-700/70 transition-colors cursor-pointer text-left"
                            >
                                <span class="flex items-center gap-2 text-xs font-semibold text-slate-800 dark:text-slate-100">
                                    <span class="text-base"><?= $wMeta['icon'] ?></span>
                                    <span><?= e($wMeta['label']) ?></span>
                                </span>
                                <span data-dash-star="<?= e($wKey) ?>" class="text-base px-1 hover:scale-125 transition-transform">⭐</span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3 pt-2.5 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                        <button
                            type="button"
                            onclick="resetDashWidgets()"
                            class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer"
                        >
                            🔄 Alle einblenden (Standard)
                        </button>
                        <button
                            type="button"
                            onclick="document.getElementById('dashCustomizeMenu').classList.add('hidden')"
                            class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer"
                        >
                            Fertig
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schnellzugriff-Kacheln (per ⭐ ein-/ausblendbar) -->
        <div data-dash-widget="quicklinks" class="pt-3 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center gap-2">
            <a href="index.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                📂 Dateien (<?= (int)$storage['file_count'] ?>)
            </a>
            <?php if (($appSettings['enable_calendar'] ?? '1') === '1'): ?>
                <a href="calendar.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    📅 Kalender (<?= count($upcomingEvents) ?>)
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_tasks'] ?? '1') === '1'): ?>
                <a href="tasks.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    ✅ Aufgaben (<?= count($openTasks) ?>)
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_polls'] ?? '1') === '1'): ?>
                <a href="polls.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    📊 Umfragen (<?= count($activePolls) ?>)
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_menti'] ?? '1') === '1'): ?>
                <a href="menti.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    🎤 Mentimeter (<?= count($myMentiSessions) ?>)
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_passwords'] ?? '1') === '1'): ?>
                <a href="passwords.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    🔐 Schlüsselbund
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_pdf_tools'] ?? '1') === '1'): ?>
                <a href="pdf_tools.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-semibold text-slate-800 dark:text-slate-200 transition-colors">
                    📄 PDF &amp; Scan
                </a>
            <?php endif; ?>
            <?php if (($appSettings['enable_chat'] ?? '1') === '1'): ?>
                <a href="messages.php" class="px-3 py-2 rounded-xl <?= $unreadChat > 0 ? 'bg-rose-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200' ?> hover:bg-brand-600 hover:text-white text-xs font-semibold transition-colors">
                    💬 Nachrichten <?= $unreadChat > 0 ? '(' . $unreadChat . ' neu)' : '' ?>
                </a>
            <?php endif; ?>
            <button
                type="button"
                onclick="toggleDashWidget('quicklinks', event)"
                data-dash-star="quicklinks"
                class="ml-auto text-sm px-1.5 py-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                title="Schnellzugriff-Leiste im Cockpit ein-/ausblenden"
            >⭐</button>
        </div>
    </section>

    <!-- Flexibles 3-Spalten-Raster (ausgeblendete Kacheln hinterlassen keine Lücken) -->
    <div id="dashWidgetsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-start">

        <?php if (($appSettings['enable_calendar'] ?? '1') === '1'): ?>
        <!-- KACHEL 1: ANSTEHENDE TERMINE -->
        <section data-dash-widget="events" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <span>📅 Anstehende Termine</span>
                    <span class="text-xs font-normal text-slate-400">(14 Tage)</span>
                </h2>
                <div class="flex items-center gap-2">
                    <a href="calendar.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Kalender →</a>
                    <button type="button" onclick="toggleDashWidget('events', event)" data-dash-star="events" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($upcomingEvents)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-3">Keine Termine in den nächsten 14 Tagen eingetragen.</p>
            <?php else: ?>
                <div class="space-y-2.5">
                    <?php foreach ($upcomingEvents as $ev):
                        $stTs    = strtotime((string)$ev['start_at']) ?: time();
                        $isToday = (date('Y-m-d', $stTs) === date('Y-m-d'));
                        $isTom   = (date('Y-m-d', $stTs) === date('Y-m-d', strtotime('+1 day')));
                    ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 flex items-start justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <?php if ($isToday): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-rose-600 text-white text-[10px] font-bold">HEUTE</span>
                                    <?php elseif ($isTom): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-amber-500 text-white text-[10px] font-bold">MORGEN</span>
                                    <?php endif; ?>
                                    <span class="text-xs font-bold text-slate-900 dark:text-white"><?= e((string)$ev['title']) ?></span>
                                </div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    <?= (int)$ev['all_day'] === 1 ? date('d.m.Y', $stTs) . ' (Ganztägig)' : date('d.m.Y · H:i', $stTs) . ' Uhr' ?>
                                    <?php if (!empty($ev['location'])): ?>
                                        · 📍 <?= e((string)$ev['location']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (($appSettings['enable_tasks'] ?? '1') === '1'): ?>
        <!-- KACHEL 2: OFFENE AUFGABEN -->
        <section data-dash-widget="tasks" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">✅ Offene Aufgaben</h2>
                <div class="flex items-center gap-2">
                    <a href="tasks.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Kanban-Board →</a>
                    <button type="button" onclick="toggleDashWidget('tasks', event)" data-dash-star="tasks" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($openTasks)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-3">Alles erledigt! Aktuell keine offenen Aufgaben.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($openTasks as $t):
                        $dueStr = (string)($t['due_date'] ?? '');
                        $isOverdue = ($dueStr !== '' && $dueStr < date('Y-m-d'));
                        $isDueToday = ($dueStr === date('Y-m-d'));
                    ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-xs font-bold text-slate-900 dark:text-white truncate">
                                    <?= e((string)$t['title']) ?>
                                </div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center gap-2 mt-0.5">
                                    <span><?= $t['status'] === 'in_progress' ? '⏳ In Arbeit' : '📋 Offen' ?></span>
                                    <?php if ($dueStr !== ''): ?>
                                        <span class="<?= $isOverdue ? 'text-rose-600 font-bold' : ($isDueToday ? 'text-amber-600 font-bold' : '') ?>">
                                            · 📅 <?= date('d.m.Y', strtotime($dueStr)) ?>
                                            <?= $isOverdue ? '(Überfällig!)' : ($isDueToday ? '(Heute!)' : '') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form action="dashboard.php" method="post" class="shrink-0">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete_task_quick">
                                <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                                <button
                                    type="submit"
                                    class="px-2.5 py-1 rounded-lg bg-emerald-600/10 hover:bg-emerald-600 text-emerald-700 dark:text-emerald-300 hover:text-white text-xs font-bold transition-colors cursor-pointer"
                                    title="Aufgabe direkt als erledigt abhaken"
                                >✓</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (($appSettings['enable_contacts'] ?? '1') === '1'): ?>
        <!-- KACHEL 3: GEBURTSTAGE -->
        <section data-dash-widget="birthdays" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">🎂 Nächste Geburtstage</h2>
                <div class="flex items-center gap-2">
                    <a href="contacts.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Kontakte →</a>
                    <button type="button" onclick="toggleDashWidget('birthdays', event)" data-dash-star="birthdays" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($upcomingBirthdays)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-2">Keine Geburtstage in den nächsten 30 Tagen.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($upcomingBirthdays as $b): ?>
                        <div class="p-2.5 rounded-xl bg-pink-50/60 dark:bg-pink-950/20 border border-pink-200/70 dark:border-pink-800/50 flex items-center justify-between text-xs">
                            <div>
                                <strong class="text-slate-900 dark:text-white">🎂 <?= e($b['name']) ?></strong>
                                <?php if ($b['age'] !== null && $b['age'] > 0): ?>
                                    <span class="text-slate-500 dark:text-slate-400">(wird <?= (int)$b['age'] ?>)</span>
                                <?php endif; ?>
                            </div>
                            <span class="px-2 py-0.5 rounded-full font-bold text-[11px] <?= $b['diff_days'] === 0 ? 'bg-pink-600 text-white' : 'bg-white dark:bg-slate-800 text-pink-700 dark:text-pink-300' ?>">
                                <?= $b['diff_days'] === 0 ? '🎉 Heute!' : 'am ' . e($b['date_label']) . ' (in ' . (int)$b['diff_days'] . ' T.)' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (($appSettings['enable_polls'] ?? '1') === '1'): ?>
        <!-- KACHEL 4: AKTIVE ABSTIMMUNGEN / TERMINFINDER -->
        <section data-dash-widget="polls" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📊 Aktive Abstimmungen</h2>
                <div class="flex items-center gap-2">
                    <a href="polls.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Alle →</a>
                    <button type="button" onclick="toggleDashWidget('polls', event)" data-dash-star="polls" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($activePolls)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-2">Momentan keine offenen Abstimmungen.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($activePolls as $ap): ?>
                        <a href="polls.php#poll-<?= (int)$ap['id'] ?>" class="block p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 hover:border-brand-500 border border-slate-200/80 dark:border-slate-700/70 transition-colors">
                            <div class="text-xs font-bold text-slate-900 dark:text-white">
                                <?= $ap['poll_type'] === 'date' ? '📅' : '📊' ?> <?= e((string)$ap['title']) ?>
                            </div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                von <?= e((string)$ap['owner_name']) ?> · Jetzt abstimmen →
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (($appSettings['enable_menti'] ?? '1') === '1'): ?>
        <!-- KACHEL 5: LIVE-MENTIMETER PRÄSENTATIONEN -->
        <section data-dash-widget="menti" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">🎤 Live-Mentimeter</h2>
                <div class="flex items-center gap-2">
                    <a href="menti.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Öffnen →</a>
                    <button type="button" onclick="toggleDashWidget('menti', event)" data-dash-star="menti" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($myMentiSessions)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-2">Noch keine interaktive Präsentation angelegt.</p>
                <a href="menti.php" class="inline-flex items-center gap-1.5 mt-1 px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 text-xs font-bold hover:bg-indigo-100">
                    ➕ Wortwolke / Live-Voting starten
                </a>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($myMentiSessions as $ms): ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-xs font-bold text-slate-900 dark:text-white truncate"><?= e((string)$ms['title']) ?></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    PIN: <strong class="font-mono text-indigo-600 dark:text-indigo-400"><?= e((string)$ms['pin_code']) ?></strong>
                                    · 📑 <?= (int)$ms['slide_count'] ?> Folien · 👥 <?= (int)$ms['participant_count'] ?>
                                </div>
                            </div>
                            <a href="menti.php?present=<?= (int)$ms['id'] ?>" class="px-2.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold shrink-0">
                                🖥️ Live
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (($appSettings['enable_notes'] ?? '1') === '1'): ?>
        <!-- KACHEL 6: ANGEPINNTE NOTIZEN -->
        <section data-dash-widget="notes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📌 Angepinnte Notizen</h2>
                <div class="flex items-center gap-2">
                    <a href="notes.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Pinnwand →</a>
                    <button type="button" onclick="toggleDashWidget('notes', event)" data-dash-star="notes" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($pinnedNotes)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-2">Keine Notizen angepinnt.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($pinnedNotes as $n): ?>
                        <a href="notes.php" class="block p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-800/50">
                            <?php if (!empty($n['title'])): ?>
                                <div class="text-xs font-bold text-slate-900 dark:text-white mb-0.5">📌 <?= e((string)$n['title']) ?></div>
                            <?php endif; ?>
                            <div class="text-xs text-slate-700 dark:text-slate-300 line-clamp-3 whitespace-pre-line"><?= e(mb_substr((string)$n['content'], 0, 180)) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- KACHEL 7: ZULETZT HOCHGELADENE DATEIEN -->
        <section data-dash-widget="files" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📂 Zuletzt hochgeladen</h2>
                <div class="flex items-center gap-2">
                    <a href="index.php" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">Dateien →</a>
                    <button type="button" onclick="toggleDashWidget('files', event)" data-dash-star="files" class="text-sm hover:scale-125 transition-transform cursor-pointer" title="Im Cockpit ein-/ausblenden">⭐</button>
                </div>
            </div>

            <?php if (empty($recentFiles)): ?>
                <p class="text-xs text-slate-500 dark:text-slate-400 py-2">Noch keine eigenen Dateien vorhanden.</p>
            <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    <?php foreach ($recentFiles as $rf): ?>
                        <div class="py-2 flex items-center justify-between gap-2">
                            <a href="index.php<?= $rf['folder_path'] !== '' ? '?folder=' . urlencode((string)$rf['folder_path']) : '' ?>" class="font-semibold text-slate-800 dark:text-slate-200 hover:text-brand-600 truncate">
                                📄 <?= e((string)$rf['original_name']) ?>
                            </a>
                            <span class="text-[11px] text-slate-400 shrink-0"><?= e(formatBytes((int)$rf['file_size'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</div>

<script>
(function() {
    const STORAGE_KEY = 'mc_dashboard_widgets_u<?= (int)$userId ?>';
    const DEFAULT_WIDGETS = <?= json_encode(array_keys($availableDashWidgets)) ?>;

    function getVisibleWidgets() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return DEFAULT_WIDGETS.slice();
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) return parsed;
        } catch (e) {}
        return DEFAULT_WIDGETS.slice();
    }

    function saveVisibleWidgets(list) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
        } catch (e) {}
    }

    function applyDashWidgets() {
        const visible = getVisibleWidgets();
        document.querySelectorAll('[data-dash-widget]').forEach(el => {
            const key = el.getAttribute('data-dash-widget');
            if (visible.includes(key)) {
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });
        document.querySelectorAll('[data-dash-star]').forEach(btn => {
            const key = btn.getAttribute('data-dash-star');
            const isPinned = visible.includes(key);
            btn.textContent = isPinned ? '⭐' : '☆';
            btn.style.opacity = isPinned ? '1' : '0.55';
        });
    }

    window.toggleDashWidget = function(key, ev) {
        if (ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }
        const visible = getVisibleWidgets();
        const idx = visible.indexOf(key);
        if (idx >= 0) {
            visible.splice(idx, 1);
        } else {
            visible.push(key);
        }
        saveVisibleWidgets(visible);
        applyDashWidgets();
    };

    window.resetDashWidgets = function() {
        saveVisibleWidgets(DEFAULT_WIDGETS.slice());
        applyDashWidgets();
    };

    document.addEventListener('click', e => {
        const menu = document.getElementById('dashCustomizeMenu');
        const btn = document.getElementById('dashCustomizeBtn');
        if (menu && !menu.classList.contains('hidden')) {
            if (!menu.contains(e.target) && (!btn || !btn.contains(e.target))) {
                menu.classList.add('hidden');
            }
        }
    });

    applyDashWidgets();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
