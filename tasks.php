<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_tasks'] ?? '1') !== '1') {
    setFlash('warning', 'Das Aufgaben- & Kanban-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];
$isAdmin  = isAdmin();

/**
 * Prüft, ob der aktuelle Benutzer auf eine Aufgabe zugreifen / sie bearbeiten darf.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleTask(PDO $pdo, int $taskId, int $userId, bool $isAdmin): ?array
{
    if ($taskId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.*, u.username AS owner_name
         FROM tasks t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        return null;
    }

    if ($isAdmin || (int)$task['user_id'] === $userId || (int)($task['shared_user_id'] ?? 0) === $userId) {
        return $task;
    }

    $gid = (int)($task['group_id'] ?? 0);
    if ($gid > 0) {
        $stmtGm = $pdo->prepare('SELECT id FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmtGm->execute([':gid' => $gid, ':uid' => $userId]);
        if ($stmtGm->fetch()) {
            return $task;
        }
    }

    return null;
}

// --- POST-Aktionen (Aufgabe anlegen, bearbeiten, Status per Drag & Drop ändern, Checkliste abhaken, löschen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    $isAjax    = isset($_POST['ajax']) && $_POST['ajax'] === '1';

    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Ungültiges CSRF-Token.']);
            exit;
        }
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: tasks.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Status per Drag & Drop oder Schnell-Button ändern (AJAX oder Formular)
    if ($action === 'update_status') {
        $taskId    = (int)($_POST['task_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? 'todo');
        if (!in_array($newStatus, ['todo', 'in_progress', 'done'], true)) {
            $newStatus = 'todo';
        }

        $task = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
        if (!$task) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
                exit;
            }
            setFlash('error', 'Aufgabe nicht gefunden oder keine Berechtigung.');
            header('Location: tasks.php');
            exit;
        }

        $pdo->prepare('UPDATE tasks SET status = :st WHERE id = :id')
            ->execute([':st' => $newStatus, ':id' => $taskId]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'status' => $newStatus]);
            exit;
        }
        header('Location: tasks.php');
        exit;
    }

    // 2. Einzelnen Checklisten-Punkt direkt auf der Karte abhaken (AJAX)
    if ($action === 'toggle_checklist_item') {
        header('Content-Type: application/json; charset=utf-8');
        $taskId  = (int)($_POST['task_id'] ?? 0);
        $itemIdx = (int)($_POST['item_index'] ?? -1);

        $task = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
            exit;
        }

        $items = json_decode((string)($task['checklist_json'] ?? '[]'), true);
        if (!is_array($items) || !isset($items[$itemIdx])) {
            echo json_encode(['success' => false, 'message' => 'Eintrag nicht gefunden.']);
            exit;
        }

        $items[$itemIdx]['done'] = empty($items[$itemIdx]['done']);
        $newJson = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);

        $pdo->prepare('UPDATE tasks SET checklist_json = :cj WHERE id = :id')
            ->execute([':cj' => $newJson, ':id' => $taskId]);

        echo json_encode(['success' => true, 'done' => $items[$itemIdx]['done']]);
        exit;
    }

    // 3. Aufgabe neu anlegen oder aktualisieren
    if ($action === 'save_task') {
        $taskId      = (int)($_POST['task_id'] ?? 0);
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000);
        $status      = (string)($_POST['status'] ?? 'todo');
        $priority    = (string)($_POST['priority'] ?? 'medium');
        $dueDateRaw  = trim((string)($_POST['due_date'] ?? ''));
        $attFileId   = (int)($_POST['attached_file_id'] ?? 0);
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Titel für die Aufgabe ein.');
            header('Location: tasks.php');
            exit;
        }

        if (!in_array($status, ['todo', 'in_progress', 'done'], true)) {
            $status = 'todo';
        }
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $priority = 'medium';
        }

        $dueDate = null;
        if ($dueDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateRaw)) {
            $dueDate = $dueDateRaw;
        }

        // Checkliste aus mehrzeiligem Text oder JSON übernehmen
        $checklistRawLines = (string)($_POST['checklist_lines'] ?? '');
        $existingChecklist = [];
        if ($taskId > 0) {
            $existingTask = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
            if ($existingTask && !empty($existingTask['checklist_json'])) {
                $decoded = json_decode((string)$existingTask['checklist_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $oldItem) {
                        if (isset($oldItem['text'])) {
                            $existingChecklist[trim((string)$oldItem['text'])] = !empty($oldItem['done']);
                        }
                    }
                }
            }
        }

        $checklistItems = [];
        foreach (preg_split('/\r\n|\r|\n/', $checklistRawLines) ?: [] as $line) {
            $cleanLine = trim($line);
            if ($cleanLine === '') {
                continue;
            }
            $isDone = false;
            if (str_starts_with($cleanLine, '[x] ') || str_starts_with($cleanLine, '[X] ')) {
                $isDone = true;
                $cleanLine = trim(substr($cleanLine, 4));
            } elseif (str_starts_with($cleanLine, '[ ] ')) {
                $cleanLine = trim(substr($cleanLine, 4));
            } elseif (isset($existingChecklist[$cleanLine])) {
                $isDone = $existingChecklist[$cleanLine];
            }
            if ($cleanLine !== '') {
                $checklistItems[] = [
                    'text' => mb_substr($cleanLine, 0, 200),
                    'done' => $isDone,
                ];
            }
        }
        $checklistJson = !empty($checklistItems) ? json_encode($checklistItems, JSON_UNESCAPED_UNICODE) : null;

        // Datei-Anhang prüfen
        $validFileId = null;
        if ($attFileId > 0) {
            $authFile = getAuthorizedFileForDownload($pdo, $userId, $attFileId, $isAdmin);
            if ($authFile) {
                $validFileId = (int)$authFile['id'];
            }
        }

        // Teilen-Ziel (user:ID oder group:ID) auflösen
        $sharedUserId = null;
        $groupId      = null;
        if (preg_match('/^user:(\d+)$/', $shareTarget, $mu)) {
            $candUid = (int)$mu[1];
            $stmtVis = $pdo->prepare('SELECT id FROM users WHERE id = :id AND (allow_user_sharing = 1 OR is_admin = 1 OR :adm = 1) LIMIT 1');
            $stmtVis->execute([':id' => $candUid, ':adm' => $isAdmin ? 1 : 0]);
            if ($candUid !== $userId && $stmtVis->fetch()) {
                $sharedUserId = $candUid;
            }
        } elseif (preg_match('/^group:(\d+)$/', $shareTarget, $mg)) {
            $candGid = (int)$mg[1];
            $stmtG = $pdo->prepare('SELECT id FROM `groups` WHERE id = :id LIMIT 1');
            $stmtG->execute([':id' => $candGid]);
            if ($stmtG->fetch()) {
                $groupId = $candGid;
            }
        }

        if ($taskId > 0) {
            $existing = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
            if (!$existing) {
                setFlash('error', 'Aufgabe nicht gefunden oder keine Berechtigung.');
                header('Location: tasks.php');
                exit;
            }
            // Nur Eigentümer oder Admin dürfen die Freigabe ändern
            if ((int)$existing['user_id'] !== $userId && !$isAdmin) {
                $sharedUserId = $existing['shared_user_id'] !== null ? (int)$existing['shared_user_id'] : null;
                $groupId      = $existing['group_id'] !== null ? (int)$existing['group_id'] : null;
            }
            $stmtUpd = $pdo->prepare(
                'UPDATE tasks
                 SET title = :t, description = :d, status = :st, priority = :pr, due_date = :dd,
                     checklist_json = :cj, attached_file_id = :af, shared_user_id = :su, group_id = :gid
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':t'   => $title,
                ':d'   => $description !== '' ? $description : null,
                ':st'  => $status,
                ':pr'  => $priority,
                ':dd'  => $dueDate,
                ':cj'  => $checklistJson,
                ':af'  => $validFileId,
                ':su'  => $sharedUserId,
                ':gid' => $groupId,
                ':id'  => $taskId,
            ]);
            setFlash('success', sprintf('Aufgabe „%s“ wurde aktualisiert.', $title));
        } else {
            $stmtIns = $pdo->prepare(
                'INSERT INTO tasks (user_id, title, description, status, priority, due_date, checklist_json, attached_file_id, shared_user_id, group_id)
                 VALUES (:uid, :t, :d, :st, :pr, :dd, :cj, :af, :su, :gid)'
            );
            $stmtIns->execute([
                ':uid' => $userId,
                ':t'   => $title,
                ':d'   => $description !== '' ? $description : null,
                ':st'  => $status,
                ':pr'  => $priority,
                ':dd'  => $dueDate,
                ':cj'  => $checklistJson,
                ':af'  => $validFileId,
                ':su'  => $sharedUserId,
                ':gid' => $groupId,
            ]);
            setFlash('success', sprintf('Aufgabe „%s“ wurde angelegt.', $title));
        }

        header('Location: tasks.php');
        exit;
    }

    // 4. Aufgabe löschen
    if ($action === 'delete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $task   = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
        if ($task && ((int)$task['user_id'] === $userId || $isAdmin)) {
            $pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute([':id' => $taskId]);
            setFlash('success', 'Aufgabe wurde gelöscht.');
        } else {
            setFlash('error', 'Nur der Ersteller kann diese Aufgabe löschen.');
        }
        header('Location: tasks.php');
        exit;
    }
}

// --- Daten für das Kanban-Board & die Listenansicht laden ---
$stmtTasks = $pdo->prepare(
    'SELECT t.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
            f.original_name AS attached_file_name
     FROM tasks t
     INNER JOIN users u ON u.id = t.user_id
     LEFT JOIN users su ON su.id = t.shared_user_id
     LEFT JOIN `groups` g ON g.id = t.group_id
     LEFT JOIN files f ON f.id = t.attached_file_id AND f.deleted_at IS NULL
     WHERE t.user_id = :u1
        OR t.shared_user_id = :u2
        OR (t.group_id IS NOT NULL AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3))
     ORDER BY
        CASE t.priority
            WHEN \'urgent\' THEN 1
            WHEN \'high\'   THEN 2
            WHEN \'medium\' THEN 3
            ELSE 4
        END ASC,
        (t.due_date IS NULL) ASC,
        t.due_date ASC,
        t.id DESC'
);
$stmtTasks->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId]);
$allTasks = $stmtTasks->fetchAll();

$tasksByStatus = [
    'todo'        => [],
    'in_progress' => [],
    'done'        => [],
];
foreach ($allTasks as $t) {
    $st = (string)$t['status'];
    if (!isset($tasksByStatus[$st])) {
        $st = 'todo';
    }
    $tasksByStatus[$st][] = $t;
}

// Eigene Dateien für die Verknüpfungs-Auswahl
$stmtMyFiles = $pdo->prepare(
    'SELECT id, original_name, folder_path FROM files WHERE user_id = :uid AND deleted_at IS NULL ORDER BY original_name ASC LIMIT 200'
);
$stmtMyFiles->execute([':uid' => $userId]);
$myFilesList = $stmtMyFiles->fetchAll();

// Sichtbare Benutzer & Gruppen für Aufgaben-Freigabe
$stmtShareUsers = $pdo->prepare(
    'SELECT id, username FROM users WHERE id <> :uid AND (allow_user_sharing = 1 OR :adm = 1) ORDER BY username ASC'
);
$stmtShareUsers->execute([':uid' => $userId, ':adm' => $isAdmin ? 1 : 0]);
$shareableUsers = $stmtShareUsers->fetchAll();

$stmtGroups = $pdo->prepare(
    $isAdmin
        ? 'SELECT id, name FROM `groups` ORDER BY name ASC'
        : 'SELECT g.id, g.name FROM `groups` g INNER JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = :uid ORDER BY g.name ASC'
);
$stmtGroups->execute($isAdmin ? [] : [':uid' => $userId]);
$shareableGroups = $stmtGroups->fetchAll();

$priorityMeta = [
    'urgent' => ['label' => '🔥 Dringend', 'cls' => 'bg-red-100 dark:bg-red-950/80 text-red-800 dark:text-red-300 border-red-200 dark:border-red-800'],
    'high'   => ['label' => '⚡ Hoch',     'cls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800'],
    'medium' => ['label' => '📌 Mittel',   'cls' => 'bg-blue-100 dark:bg-blue-950/80 text-blue-800 dark:text-blue-300 border-blue-200 dark:border-blue-800'],
    'low'    => ['label' => '🌱 Niedrig',  'cls' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'],
];

$columnsMeta = [
    'todo'        => ['title' => '📌 Offen',      'badgeCls' => 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300',   'headerBorder' => 'border-amber-400'],
    'in_progress' => ['title' => '⏳ In Arbeit',  'badgeCls' => 'bg-brand-100 dark:bg-brand-950 text-brand-800 dark:text-brand-300',   'headerBorder' => 'border-brand-500'],
    'done'        => ['title' => '✅ Erledigt',   'badgeCls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300', 'headerBorder' => 'border-emerald-500'],
];

$pageTitle = 'Aufgaben & Kanban';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 text-lg">📋</span>
            <span>Aufgaben &amp; Kanban-Board</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            Persönliche und geteilte To-Do-Listen mit Unteraufgaben, Fälligkeiten und Drag-&amp;-Drop-Kanban-Spalten.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input
            type="search"
            id="taskSearchInput"
            oninput="filterTasks(this.value)"
            placeholder="🔍 Aufgaben filtern..."
            class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
        <button
            type="button"
            onclick="openTaskModal()"
            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
        >
            <span>+ Neue Aufgabe</span>
        </button>
    </div>
</div>

<!-- 3-Spalten Kanban-Board -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
    <?php foreach ($columnsMeta as $colKey => $colInfo): ?>
        <?php $colTasks = $tasksByStatus[$colKey] ?? []; ?>
        <section
            data-kanban-col="<?= e($colKey) ?>"
            ondragover="onKanbanDragOver(event, this)"
            ondragleave="onKanbanDragLeave(event, this)"
            ondrop="onKanbanDrop(event, <?= e((string)json_encode($colKey)) ?>, this)"
            class="mc-card bg-white dark:bg-slate-900 rounded-2xl border-t-4 <?= $colInfo['headerBorder'] ?> border-x border-b border-slate-200 dark:border-slate-800 p-4 shadow-xs min-h-[420px] flex flex-col transition-all"
        >
            <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <span><?= e($colInfo['title']) ?></span>
                </h2>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold <?= $colInfo['badgeCls'] ?>">
                        <?= count($colTasks) ?>
                    </span>
                    <button
                        type="button"
                        onclick="openTaskModal(null, <?= e((string)json_encode($colKey)) ?>)"
                        class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-600 dark:text-slate-300 font-bold text-sm transition-colors cursor-pointer"
                        title="Aufgabe in dieser Spalte anlegen"
                    >
                        +
                    </button>
                </div>
            </div>

            <div class="space-y-3 flex-1">
                <?php if (empty($colTasks)): ?>
                    <div class="py-10 text-center text-xs text-slate-400 dark:text-slate-500 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                        Keine Aufgaben in „<?= e($colInfo['title']) ?>“.<br>
                        <span class="text-[11px]">Karten einfach per Drag &amp; Drop hierher ziehen.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($colTasks as $task): ?>
                        <?php
                        $tId       = (int)$task['id'];
                        $tPrio     = (string)$task['priority'];
                        $pMeta     = $priorityMeta[$tPrio] ?? $priorityMeta['medium'];
                        $isOwner   = ((int)$task['user_id'] === $userId) || $isAdmin;
                        $dueStr    = (string)($task['due_date'] ?? '');
                        $isOverdue = ($dueStr !== '' && $colKey !== 'done' && strtotime($dueStr) < strtotime(date('Y-m-d')));
                        $checklist = !empty($task['checklist_json']) ? (json_decode((string)$task['checklist_json'], true) ?: []) : [];
                        $checklistTextLines = [];
                        foreach ($checklist as $ci) {
                            $checklistTextLines[] = (!empty($ci['done']) ? '[x] ' : '[ ] ') . (string)($ci['text'] ?? '');
                        }
                        $shareVal = '';
                        if (!empty($task['shared_user_id'])) {
                            $shareVal = 'user:' . (int)$task['shared_user_id'];
                        } elseif (!empty($task['group_id'])) {
                            $shareVal = 'group:' . (int)$task['group_id'];
                        }
                        $taskJson = [
                            'id'              => $tId,
                            'title'           => (string)$task['title'],
                            'description'     => (string)($task['description'] ?? ''),
                            'status'          => $colKey,
                            'priority'        => $tPrio,
                            'due_date'        => $dueStr,
                            'checklist_lines' => implode("\n", $checklistTextLines),
                            'attached_file_id'=> (int)($task['attached_file_id'] ?? 0),
                            'share_target'    => $shareVal,
                        ];
                        ?>
                        <article
                            draggable="true"
                            data-task-card="<?= $tId ?>"
                            ondragstart="onTaskDragStart(event, <?= $tId ?>)"
                            class="p-3.5 rounded-xl bg-slate-50/90 dark:bg-slate-800/70 border border-slate-200/90 dark:border-slate-700/80 hover:border-brand-400 dark:hover:border-brand-500 shadow-2xs space-y-2.5 cursor-grab active:cursor-grabbing transition-all"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $pMeta['cls'] ?>">
                                    <?= e($pMeta['label']) ?>
                                </span>
                                <div class="flex items-center gap-1 shrink-0">
                                    <?php if ($colKey !== 'todo'): ?>
                                        <form action="tasks.php" method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <input type="hidden" name="status" value="<?= $colKey === 'done' ? 'in_progress' : 'todo' ?>">
                                            <button type="submit" class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-700 hover:bg-slate-200 text-[11px] font-bold text-slate-600 dark:text-slate-200 cursor-pointer" title="Spalte nach links">&larr;</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($colKey !== 'done'): ?>
                                        <form action="tasks.php" method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <input type="hidden" name="status" value="<?= $colKey === 'todo' ? 'in_progress' : 'done' ?>">
                                            <button type="submit" class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-700 hover:bg-slate-200 text-[11px] font-bold text-slate-600 dark:text-slate-200 cursor-pointer" title="Spalte nach rechts">&rarr;</button>
                                        </form>
                                    <?php endif; ?>
                                    <button
                                        type="button"
                                        onclick='openTaskModal(<?= htmlspecialchars((string)json_encode($taskJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                                        class="p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-300 cursor-pointer"
                                        title="Aufgabe bearbeiten"
                                    >
                                        ✏️
                                    </button>
                                    <?php if ($isOwner): ?>
                                        <form action="tasks.php" method="post" class="inline" onsubmit="return confirm('Diese Aufgabe wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_task">
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <button type="submit" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-950/60 text-slate-400 hover:text-red-600 cursor-pointer" title="Aufgabe löschen">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <h3 class="text-sm font-bold text-slate-900 dark:text-white leading-snug <?= $colKey === 'done' ? 'line-through opacity-70' : '' ?>">
                                <?= e((string)$task['title']) ?>
                            </h3>

                            <?php if (trim((string)($task['description'] ?? '')) !== ''): ?>
                                <p class="text-xs text-slate-600 dark:text-slate-300 whitespace-pre-line leading-relaxed">
                                    <?= e((string)$task['description']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($checklist)): ?>
                                <div class="space-y-1 pt-1 border-t border-slate-200/70 dark:border-slate-700/70">
                                    <?php foreach ($checklist as $idx => $ci): ?>
                                        <?php $ciDone = !empty($ci['done']); ?>
                                        <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                <?= $ciDone ? 'checked' : '' ?>
                                                onchange="toggleTaskChecklistItem(<?= $tId ?>, <?= (int)$idx ?>, this)"
                                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 cursor-pointer"
                                            >
                                            <span class="<?= $ciDone ? 'line-through text-slate-400' : '' ?>"><?= e((string)($ci['text'] ?? '')) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex flex-wrap items-center justify-between gap-1.5 pt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?php if ($dueStr !== ''): ?>
                                        <span class="px-2 py-0.5 rounded-md font-semibold <?= $isOverdue ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-slate-200/70 dark:bg-slate-700 text-slate-700 dark:text-slate-200' ?>">
                                            📅 <?= e(date('d.m.Y', strtotime($dueStr))) ?><?= $isOverdue ? ' (Überfällig)' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($task['attached_file_id']) && !empty($task['attached_file_name'])): ?>
                                        <a href="download.php?id=<?= (int)$task['attached_file_id'] ?>" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-brand-50 dark:bg-brand-950/70 text-brand-700 dark:text-brand-300 hover:underline font-medium max-w-[180px] truncate">
                                            📎 <?= e((string)$task['attached_file_name']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-1">
                                    <?php if ((int)$task['user_id'] !== $userId): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-semibold">
                                            👤 von <?= e((string)$task['owner_name']) ?>
                                        </span>
                                    <?php elseif (!empty($task['shared_user_name'])): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-semibold">
                                            🤝 <?= e((string)$task['shared_user_name']) ?>
                                        </span>
                                    <?php elseif (!empty($task['group_name'])): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-purple-50 dark:bg-purple-950 text-purple-700 dark:text-purple-300 font-semibold">
                                            👥 <?= e((string)$task['group_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<!-- MODAL: AUFGABE ERSTELLEN / BEARBEITEN -->
<div
    id="taskModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg max-h-[92vh] rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 id="taskModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                📋 Neue Aufgabe anlegen
            </h3>
            <button type="button" onclick="closeTaskModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer">✕</button>
        </div>

        <form action="tasks.php" method="post" class="p-5 overflow-y-auto space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_task">
            <input type="hidden" name="task_id" id="taskFormId" value="0">

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Titel der Aufgabe *</label>
                <input
                    type="text"
                    name="title"
                    id="taskFormTitle"
                    required
                    maxlength="255"
                    placeholder="z. B. Steuerunterlagen zusammenstellen"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Spalte / Status</label>
                    <select name="status" id="taskFormStatus" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="todo">📌 Offen</option>
                        <option value="in_progress">⏳ In Arbeit</option>
                        <option value="done">✅ Erledigt</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Priorität</label>
                    <select name="priority" id="taskFormPriority" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="low">🌱 Niedrig</option>
                        <option value="medium" selected>📌 Mittel</option>
                        <option value="high">⚡ Hoch</option>
                        <option value="urgent">🔥 Dringend</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Fällig bis</label>
                    <input type="date" name="due_date" id="taskFormDueDate" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Beschreibung / Details</label>
                <textarea
                    name="description"
                    id="taskFormDescription"
                    rows="2"
                    placeholder="Optionale Notizen zur Aufgabe..."
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                ></textarea>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                    ☑️ Unteraufgaben / Checkliste <span class="font-normal text-slate-400">(eine Zeile pro Punkt)</span>
                </label>
                <textarea
                    name="checklist_lines"
                    id="taskFormChecklist"
                    rows="3"
                    placeholder="Rechnungen prüfen&#10;PDF exportieren&#10;Freigabelink senden"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:ring-2 focus:ring-brand-500"
                ></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">📎 Cloud-Datei verknüpfen</label>
                    <select name="attached_file_id" id="taskFormFileId" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="0">— Keine Datei —</option>
                        <?php foreach ($myFilesList as $mf): ?>
                            <?php $fLabel = ($mf['folder_path'] !== '' ? $mf['folder_path'] . '/' : '') . $mf['original_name']; ?>
                            <option value="<?= (int)$mf['id'] ?>"><?= e($fLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🤝 Teilen mit Benutzer / Gruppe</label>
                    <select name="share_target" id="taskFormShareTarget" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="">🔒 Nur für mich privat</option>
                        <?php if (!empty($shareableUsers)): ?>
                            <optgroup label="👤 Cloud-Benutzer">
                                <?php foreach ($shareableUsers as $su): ?>
                                    <option value="user:<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($shareableGroups)): ?>
                            <optgroup label="👥 Benutzergruppen">
                                <?php foreach ($shareableGroups as $sg): ?>
                                    <option value="group:<?= (int)$sg['id'] ?>">👥 <?= e((string)$sg['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeTaskModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shadow-xs cursor-pointer">💾 Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let draggedTaskId = 0;

function openTaskModal(taskData = null, defaultStatus = 'todo') {
    const modal = document.getElementById('taskModal');
    const titleEl = document.getElementById('taskModalTitle');
    if (!modal) return;

    document.getElementById('taskFormId').value = taskData ? taskData.id : 0;
    document.getElementById('taskFormTitle').value = taskData ? taskData.title : '';
    document.getElementById('taskFormDescription').value = taskData ? taskData.description : '';
    document.getElementById('taskFormStatus').value = taskData ? taskData.status : defaultStatus;
    document.getElementById('taskFormPriority').value = taskData ? taskData.priority : 'medium';
    document.getElementById('taskFormDueDate').value = taskData ? taskData.due_date : '';
    document.getElementById('taskFormChecklist').value = taskData ? taskData.checklist_lines : '';
    document.getElementById('taskFormFileId').value = taskData ? taskData.attached_file_id : 0;
    document.getElementById('taskFormShareTarget').value = taskData ? taskData.share_target : '';

    if (titleEl) {
        titleEl.textContent = taskData ? '✏️ Aufgabe bearbeiten' : '📋 Neue Aufgabe anlegen';
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('taskFormTitle')?.focus(), 60);
}

function closeTaskModal() {
    const modal = document.getElementById('taskModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function onTaskDragStart(e, taskId) {
    draggedTaskId = Number(taskId) || 0;
    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(taskId));
    }
}

function onKanbanDragOver(e, colEl) {
    e.preventDefault();
    colEl.classList.add('ring-2', 'ring-brand-500');
}

function onKanbanDragLeave(e, colEl) {
    if (!colEl.contains(e.relatedTarget)) {
        colEl.classList.remove('ring-2', 'ring-brand-500');
    }
}

async function onKanbanDrop(e, targetStatus, colEl) {
    e.preventDefault();
    colEl.classList.remove('ring-2', 'ring-brand-500');
    const tid = draggedTaskId || Number(e.dataTransfer?.getData('text/plain') || 0);
    if (!tid) return;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('ajax', '1');
    fd.append('action', 'update_status');
    fd.append('task_id', String(tid));
    fd.append('status', targetStatus);

    const res = await fetch('tasks.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d && d.success) {
        window.location.reload();
    }
}

async function toggleTaskChecklistItem(taskId, itemIdx, cb) {
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('ajax', '1');
    fd.append('action', 'toggle_checklist_item');
    fd.append('task_id', String(taskId));
    fd.append('item_index', String(itemIdx));

    const res = await fetch('tasks.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d && d.success && cb.nextElementSibling) {
        if (d.done) {
            cb.nextElementSibling.classList.add('line-through', 'text-slate-400');
        } else {
            cb.nextElementSibling.classList.remove('line-through', 'text-slate-400');
        }
    }
}

function filterTasks(query) {
    const q = String(query || '').trim().toLowerCase();
    document.querySelectorAll('[data-task-card]').forEach((card) => {
        const txt = (card.textContent || '').toLowerCase();
        card.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
