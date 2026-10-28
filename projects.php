<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_projects'] ?? '1') !== '1') {
    setFlash('warning', 'Das Projektmanagement-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

// Prüfen, ob ein öffentlicher Projekt-Link (project_token) aufgerufen wurde
$publicToken   = trim((string)($_GET['project_token'] ?? $_POST['project_token'] ?? ''));
$isLoggedIn    = !empty($_SESSION['user_id']);
$publicProject = null;

if ($publicToken !== '' && preg_match('/^[a-f0-9]{32,64}$/', $publicToken)) {
    $stmtPub = $pdo->prepare(
        "SELECT p.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
                kb.title AS board_title, gf.name AS group_folder_name
         FROM projects p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN users su ON su.id = p.shared_user_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         LEFT JOIN kanban_boards kb ON kb.id = p.kanban_board_id
         LEFT JOIN group_folders gf ON gf.id = p.group_folder_id
         WHERE p.share_token = :tok AND p.share_mode IN ('read', 'write')
         LIMIT 1"
    );
    $stmtPub->execute([':tok' => $publicToken]);
    $publicProject = $stmtPub->fetch() ?: null;
}

if (!$publicProject) {
    requireLogin();
}

$userId   = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$username = $isLoggedIn ? (string)$_SESSION['username'] : 'Gast';
$isAdmin  = $isLoggedIn && isAdmin();

/**
 * Prüft, ob der aktuelle Benutzer auf ein Projekt zugreifen darf.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleProject(PDO $pdo, int $projectId, int $userId, bool $isAdmin, ?array $publicProject = null): ?array
{
    if ($projectId <= 0) {
        return null;
    }
    if ($publicProject && (int)$publicProject['id'] === $projectId) {
        return $publicProject;
    }
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT p.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
                kb.title AS board_title, gf.name AS group_folder_name
         FROM projects p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN users su ON su.id = p.shared_user_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         LEFT JOIN kanban_boards kb ON kb.id = p.kanban_board_id
         LEFT JOIN group_folders gf ON gf.id = p.group_folder_id
         WHERE p.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $projectId]);
    $proj = $stmt->fetch();
    if (!$proj) {
        return null;
    }

    if ($isAdmin || (int)$proj['user_id'] === $userId || (int)($proj['shared_user_id'] ?? 0) === $userId) {
        return $proj;
    }

    $gid = (int)($proj['group_id'] ?? 0);
    if ($gid > 0) {
        $stmtGm = $pdo->prepare('SELECT id FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmtGm->execute([':gid' => $gid, ':uid' => $userId]);
        if ($stmtGm->fetch()) {
            return $proj;
        }
    }

    return null;
}

/**
 * Löst ein Teilen-Ziel (user:ID oder group:ID) auf.
 *
 * @return array{shared_user_id: int|null, group_id: int|null}
 */
function resolveProjectShareTarget(PDO $pdo, string $shareTarget, int $userId, bool $isAdmin): array
{
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
        if ($isAdmin) {
            $stmtG = $pdo->prepare('SELECT id FROM `groups` WHERE id = :id LIMIT 1');
            $stmtG->execute([':id' => $candGid]);
        } else {
            $stmtG = $pdo->prepare(
                'SELECT g.id FROM `groups` g
                 INNER JOIN group_members gm ON gm.group_id = g.id
                 WHERE g.id = :id AND gm.user_id = :uid LIMIT 1'
            );
            $stmtG->execute([':id' => $candGid, ':uid' => $userId]);
        }
        if ($stmtG->fetch()) {
            $groupId = $candGid;
        }
    }

    return [
        'shared_user_id' => $sharedUserId,
        'group_id'       => $groupId,
    ];
}

$allowedIcons    = ['🚀', '💼', '🛠️', '🏗️', '🎯', '💡', '🎉', '🌐', '📚', '🏠'];
$allowedColors   = ['brand', 'emerald', 'amber', 'rose', 'violet', 'slate'];
$allowedStatuses = ['planning', 'active', 'on_hold', 'completed', 'archived'];

// --- POST-Aktionen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: projects.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Projekt anlegen / bearbeiten / teilen
    if ($action === 'save_project' && $userId > 0) {
        $projectId     = (int)($_POST['project_id'] ?? 0);
        $code          = mb_strtoupper(mb_substr(trim((string)($_POST['code'] ?? '')), 0, 20));
        $title         = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
        $description   = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000);
        $icon          = trim((string)($_POST['icon'] ?? '🚀'));
        $color         = trim((string)($_POST['color'] ?? 'brand'));
        $status        = trim((string)($_POST['status'] ?? 'active'));
        $startDateRaw  = trim((string)($_POST['start_date'] ?? ''));
        $endDateRaw    = trim((string)($_POST['end_date'] ?? ''));
        $budgetHours   = max(0.0, (float)str_replace(',', '.', (string)($_POST['budget_hours'] ?? '0')));
        $budgetAmount  = max(0.0, (float)str_replace(',', '.', (string)($_POST['budget_amount'] ?? '0')));
        $kanbanBoardId = (int)($_POST['kanban_board_id'] ?? 0);
        $createBoard   = !empty($_POST['auto_create_board']);
        $groupFolderId = (int)($_POST['group_folder_id'] ?? 0);
        $shareTarget   = trim((string)($_POST['share_target'] ?? ''));
        $shareMode     = trim((string)($_POST['share_mode'] ?? 'disabled'));
        $regenToken    = !empty($_POST['regenerate_token']);

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Projektnamen ein.');
            header('Location: projects.php');
            exit;
        }
        if (!in_array($icon, $allowedIcons, true)) {
            $icon = '🚀';
        }
        if (!in_array($color, $allowedColors, true)) {
            $color = 'brand';
        }
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'active';
        }
        if (!in_array($shareMode, ['disabled', 'read', 'write'], true)) {
            $shareMode = 'disabled';
        }

        $startDate = ($startDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateRaw)) ? $startDateRaw : null;
        $endDate   = ($endDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateRaw)) ? $endDateRaw : null;

        $resolved = resolveProjectShareTarget($pdo, $shareTarget, $userId, $isAdmin);

        // Optional automatisch ein verknüpftes Kanban-Board miterstellen
        if ($createBoard && $kanbanBoardId <= 0) {
            $stmtKb = $pdo->prepare(
                'INSERT INTO kanban_boards (user_id, title, description, icon, color, shared_user_id, group_id)
                 VALUES (:uid, :t, :d, :ic, :co, :su, :gid)'
            );
            $stmtKb->execute([
                ':uid' => $userId,
                ':t'   => $title,
                ':d'   => mb_substr($description, 0, 500),
                ':ic'  => in_array($icon, ['📋', '🚀', '💼', '🏠', '🎉', '🛠️', '💡', '📚', '🎯', '🛒'], true) ? $icon : '🚀',
                ':co'  => $color,
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
            ]);
            $kanbanBoardId = (int)$pdo->lastInsertId();
        } elseif ($kanbanBoardId > 0) {
            // Freigabe des verknüpften Kanban-Boards synchron halten, falls der Benutzer Eigentümer des Boards ist
            $pdo->prepare(
                'UPDATE kanban_boards SET shared_user_id = :su, group_id = :gid WHERE id = :bid AND user_id = :uid'
            )->execute([
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':bid' => $kanbanBoardId,
                ':uid' => $userId,
            ]);
        }

        $validBoardId  = $kanbanBoardId > 0 ? $kanbanBoardId : null;
        $validFolderId = $groupFolderId > 0 ? $groupFolderId : null;

        if ($projectId > 0) {
            $existing = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
            if (!$existing || ((int)$existing['user_id'] !== $userId && !$isAdmin)) {
                setFlash('error', 'Nur der Projekteigentümer kann die Projekteinstellungen ändern.');
                header('Location: projects.php?id=' . $projectId);
                exit;
            }

            $token = $existing['share_token'] ?? null;
            if ($shareMode !== 'disabled' && (empty($token) || $regenToken)) {
                $token = bin2hex(random_bytes(24));
            } elseif ($shareMode === 'disabled' && $regenToken) {
                $token = null;
            }

            $stmtUpd = $pdo->prepare(
                'UPDATE projects
                 SET code = :cd, title = :t, description = :d, icon = :ic, color = :co, status = :st,
                     start_date = :sd, end_date = :ed, budget_hours = :bh, budget_amount = :ba,
                     kanban_board_id = :kb, group_folder_id = :gf,
                     shared_user_id = :su, group_id = :gid, share_token = :tok, share_mode = :sm
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':cd'  => $code,
                ':t'   => $title,
                ':d'   => $description !== '' ? $description : null,
                ':ic'  => $icon,
                ':co'  => $color,
                ':st'  => $status,
                ':sd'  => $startDate,
                ':ed'  => $endDate,
                ':bh'  => $budgetHours,
                ':ba'  => $budgetAmount,
                ':kb'  => $validBoardId,
                ':gf'  => $validFolderId,
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':tok' => $token,
                ':sm'  => $shareMode,
                ':id'  => $projectId,
            ]);
            setFlash('success', sprintf('Projekt „%s“ wurde aktualisiert.', $title));
            header('Location: projects.php?id=' . $projectId);
            exit;
        } else {
            if ($code === '') {
                $code = 'PRJ-' . str_pad((string)random_int(1, 99), 2, '0', STR_PAD_LEFT);
            }
            $token = ($shareMode !== 'disabled') ? bin2hex(random_bytes(24)) : null;

            $stmtIns = $pdo->prepare(
                'INSERT INTO projects (
                    user_id, code, title, description, icon, color, status,
                    start_date, end_date, budget_hours, budget_amount,
                    kanban_board_id, group_folder_id, shared_user_id, group_id, share_token, share_mode
                 ) VALUES (
                    :uid, :cd, :t, :d, :ic, :co, :st,
                    :sd, :ed, :bh, :ba,
                    :kb, :gf, :su, :gid, :tok, :sm
                 )'
            );
            $stmtIns->execute([
                ':uid' => $userId,
                ':cd'  => $code,
                ':t'   => $title,
                ':d'   => $description !== '' ? $description : null,
                ':ic'  => $icon,
                ':co'  => $color,
                ':st'  => $status,
                ':sd'  => $startDate,
                ':ed'  => $endDate,
                ':bh'  => $budgetHours,
                ':ba'  => $budgetAmount,
                ':kb'  => $validBoardId,
                ':gf'  => $validFolderId,
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':tok' => $token,
                ':sm'  => $shareMode,
            ]);
            $newId = (int)$pdo->lastInsertId();
            setFlash('success', sprintf('Projekt „%s“ wurde angelegt.', $title));
            header('Location: projects.php?id=' . $newId);
            exit;
        }
    }

    // 2. Projekt löschen
    if ($action === 'delete_project' && $userId > 0) {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $existing  = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($existing && ((int)$existing['user_id'] === $userId || $isAdmin)) {
            $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $projectId]);
            setFlash('success', sprintf('Projekt „%s“ wurde gelöscht.', (string)$existing['title']));
        } else {
            setFlash('error', 'Nur der Eigentümer kann dieses Projekt löschen.');
        }
        header('Location: projects.php');
        exit;
    }

    // 3. Nachträglich ein Kanban-Board für das Projekt erzeugen & verknüpfen
    if ($action === 'create_linked_board' && $userId > 0) {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $proj      = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($proj && empty($proj['kanban_board_id'])) {
            $stmtKb = $pdo->prepare(
                'INSERT INTO kanban_boards (user_id, title, description, icon, color, shared_user_id, group_id)
                 VALUES (:uid, :t, :d, :ic, :co, :su, :gid)'
            );
            $stmtKb->execute([
                ':uid' => (int)$proj['user_id'],
                ':t'   => (string)$proj['title'],
                ':d'   => mb_substr((string)($proj['description'] ?? ''), 0, 500),
                ':ic'  => '🚀',
                ':co'  => (string)($proj['color'] ?? 'brand'),
                ':su'  => $proj['shared_user_id'] !== null ? (int)$proj['shared_user_id'] : null,
                ':gid' => $proj['group_id'] !== null ? (int)$proj['group_id'] : null,
            ]);
            $newBid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE projects SET kanban_board_id = :bid WHERE id = :pid')
                ->execute([':bid' => $newBid, ':pid' => $projectId]);
            setFlash('success', 'Kanban-Board für dieses Projekt wurde erstellt und verknüpft!');
        }
        header('Location: projects.php?id=' . $projectId . '&tab=tasks');
        exit;
    }

    // 4. Meilenstein anlegen oder bearbeiten
    if ($action === 'save_milestone') {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $proj        = getAccessibleProject($pdo, $projectId, $userId, $isAdmin, $publicProject);
        $canWrite    = ($userId > 0 && $proj) || ($publicProject && ($publicProject['share_mode'] ?? 'read') === 'write');

        if ($proj && $canWrite) {
            $title      = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
            $desc       = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
            $startRaw   = trim((string)($_POST['start_date'] ?? ''));
            $dueRaw     = trim((string)($_POST['due_date'] ?? ''));
            $status     = trim((string)($_POST['status'] ?? 'open'));
            $assignedId = (int)($_POST['assigned_user_id'] ?? 0);

            if (!in_array($status, ['open', 'in_progress', 'done'], true)) {
                $status = 'open';
            }
            $startDate = ($startRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw)) ? $startRaw : null;
            $dueDate   = ($dueRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) ? $dueRaw : null;
            $validAssigned = $assignedId > 0 ? $assignedId : null;

            if ($title !== '') {
                if ($milestoneId > 0) {
                    $pdo->prepare(
                        'UPDATE project_milestones
                         SET title = :t, description = :d, start_date = :sd, due_date = :dd, status = :st, assigned_user_id = :au
                         WHERE id = :id AND project_id = :pid'
                    )->execute([
                        ':t'   => $title,
                        ':d'   => $desc,
                        ':sd'  => $startDate,
                        ':dd'  => $dueDate,
                        ':st'  => $status,
                        ':au'  => $validAssigned,
                        ':id'  => $milestoneId,
                        ':pid' => $projectId,
                    ]);
                    setFlash('success', 'Meilenstein wurde aktualisiert.');
                } else {
                    $pdo->prepare(
                        'INSERT INTO project_milestones (project_id, title, description, start_date, due_date, status, assigned_user_id)
                         VALUES (:pid, :t, :d, :sd, :dd, :st, :au)'
                    )->execute([
                        ':pid' => $projectId,
                        ':t'   => $title,
                        ':d'   => $desc,
                        ':sd'  => $startDate,
                        ':dd'  => $dueDate,
                        ':st'  => $status,
                        ':au'  => $validAssigned,
                    ]);
                    setFlash('success', 'Meilenstein wurde hinzugefügt.');
                }
            }
        }
        $redir = $publicProject
            ? 'projects.php?project_token=' . rawurlencode((string)$publicProject['share_token']) . '&tab=milestones'
            : 'projects.php?id=' . $projectId . '&tab=milestones';
        header('Location: ' . $redir);
        exit;
    }

    // 5. Meilenstein-Status per 1-Klick umschalten
    if ($action === 'cycle_milestone_status') {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $newStatus   = (string)($_POST['status'] ?? 'done');
        $proj        = getAccessibleProject($pdo, $projectId, $userId, $isAdmin, $publicProject);
        $canWrite    = ($userId > 0 && $proj) || ($publicProject && ($publicProject['share_mode'] ?? 'read') === 'write');

        if ($proj && $canWrite && in_array($newStatus, ['open', 'in_progress', 'done'], true)) {
            $pdo->prepare('UPDATE project_milestones SET status = :st WHERE id = :id AND project_id = :pid')
                ->execute([':st' => $newStatus, ':id' => $milestoneId, ':pid' => $projectId]);
        }
        $redir = $publicProject
            ? 'projects.php?project_token=' . rawurlencode((string)$publicProject['share_token']) . '&tab=milestones'
            : 'projects.php?id=' . $projectId . '&tab=milestones';
        header('Location: ' . $redir);
        exit;
    }

    // 6. Meilenstein löschen
    if ($action === 'delete_milestone' && $userId > 0) {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $proj        = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($proj) {
            $pdo->prepare('DELETE FROM project_milestones WHERE id = :id AND project_id = :pid')
                ->execute([':id' => $milestoneId, ':pid' => $projectId]);
            setFlash('success', 'Meilenstein gelöscht.');
        }
        header('Location: projects.php?id=' . $projectId . '&tab=milestones');
        exit;
    }

    // 7. Zeiterfassung, Kostenbuchung oder Projekt-Tagebuch-Eintrag speichern
    if ($action === 'save_entry' && $userId > 0) {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $proj        = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($proj) {
            $entryType   = trim((string)($_POST['entry_type'] ?? 'time'));
            $entryDate   = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
            $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
            $notes       = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 5000);
            $hours       = max(0.0, (float)str_replace(',', '.', (string)($_POST['hours'] ?? '0')));
            $costAmount  = max(0.0, (float)str_replace(',', '.', (string)($_POST['cost_amount'] ?? '0')));
            $milestoneId = (int)($_POST['milestone_id'] ?? 0);

            if (!in_array($entryType, ['time', 'expense', 'log'], true)) {
                $entryType = 'time';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
                $entryDate = date('Y-m-d');
            }

            if ($title !== '') {
                $pdo->prepare(
                    'INSERT INTO project_entries (project_id, user_id, entry_type, entry_date, title, notes, hours, cost_amount, milestone_id)
                     VALUES (:pid, :uid, :et, :ed, :t, :nt, :hr, :ca, :mid)'
                )->execute([
                    ':pid' => $projectId,
                    ':uid' => $userId,
                    ':et'  => $entryType,
                    ':ed'  => $entryDate,
                    ':t'   => $title,
                    ':nt'  => $notes !== '' ? $notes : null,
                    ':hr'  => $hours,
                    ':ca'  => $costAmount,
                    ':mid' => $milestoneId > 0 ? $milestoneId : null,
                ]);
                setFlash('success', $entryType === 'log' ? 'Tagebuch-Eintrag wurde gespeichert.' : 'Projektbuchung wurde gespeichert.');
            }
        }
        $targetTab = (($_POST['entry_type'] ?? '') === 'log') ? 'logbook' : 'time';
        header('Location: projects.php?id=' . $projectId . '&tab=' . $targetTab);
        exit;
    }

    // 8. Projekt-Buchung / Tagebuch-Eintrag löschen
    if ($action === 'delete_entry' && $userId > 0) {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $entryId   = (int)($_POST['entry_id'] ?? 0);
        $returnTab = trim((string)($_POST['return_tab'] ?? 'time'));
        $proj      = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($proj) {
            $pdo->prepare('DELETE FROM project_entries WHERE id = :id AND project_id = :pid')
                ->execute([':id' => $entryId, ':pid' => $projectId]);
            setFlash('success', 'Eintrag wurde gelöscht.');
        }
        header('Location: projects.php?id=' . $projectId . '&tab=' . rawurlencode($returnTab));
        exit;
    }

    // 9. Schnell-Aufgabe direkt aus dem Projekt ins verknüpfte Kanban-Board eintragen
    if ($action === 'quick_add_project_task' && $userId > 0) {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $proj      = getAccessibleProject($pdo, $projectId, $userId, $isAdmin);
        if ($proj && !empty($proj['kanban_board_id'])) {
            $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
            $priority = trim((string)($_POST['priority'] ?? 'medium'));
            $dueRaw   = trim((string)($_POST['due_date'] ?? ''));
            $dueDate  = ($dueRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueRaw)) ? $dueRaw : null;
            if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
                $priority = 'medium';
            }
            if ($title !== '') {
                $pdo->prepare(
                    'INSERT INTO tasks (user_id, board_id, title, status, priority, due_date)
                     VALUES (:uid, :bid, :t, \'todo\', :pr, :dd)'
                )->execute([
                    ':uid' => $userId,
                    ':bid' => (int)$proj['kanban_board_id'],
                    ':t'   => $title,
                    ':pr'  => $priority,
                    ':dd'  => $dueDate,
                ]);
                setFlash('success', sprintf('Aufgabe „%s“ zum Projekt-Kanban hinzugefügt.', $title));
            }
        }
        header('Location: projects.php?id=' . $projectId . '&tab=tasks');
        exit;
    }

    // 10. Status einer verknüpften Kanban-Aufgabe direkt im Projekt umschalten
    if ($action === 'quick_task_status') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $taskId    = (int)($_POST['task_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? 'todo'));
        $proj      = getAccessibleProject($pdo, $projectId, $userId, $isAdmin, $publicProject);
        $canWrite  = ($userId > 0 && $proj) || ($publicProject && ($publicProject['share_mode'] ?? 'read') === 'write');

        if ($proj && $canWrite && !empty($proj['kanban_board_id']) && in_array($newStatus, ['todo', 'in_progress', 'done'], true)) {
            $pdo->prepare('UPDATE tasks SET status = :st WHERE id = :tid AND board_id = :bid')
                ->execute([
                    ':st'  => $newStatus,
                    ':tid' => $taskId,
                    ':bid' => (int)$proj['kanban_board_id'],
                ]);
        }
        $redir = $publicProject
            ? 'projects.php?project_token=' . rawurlencode((string)$publicProject['share_token']) . '&tab=tasks'
            : 'projects.php?id=' . $projectId . '&tab=tasks';
        header('Location: ' . $redir);
        exit;
    }
}

// --- Hilfsdaten für Formulare (Kanban-Boards, Gruppenordner, Benutzer, Gruppen) laden ---
$myKanbanBoards  = [];
$myGroupFolders  = [];
$shareableUsers  = [];
$shareableGroups = [];

if ($userId > 0) {
    $stmtKb = $pdo->prepare(
        'SELECT id, title, icon FROM kanban_boards
         WHERE user_id = :u1
            OR shared_user_id = :u2
            OR (group_id IS NOT NULL AND group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3))
         ORDER BY title ASC'
    );
    $stmtKb->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId]);
    $myKanbanBoards = $stmtKb->fetchAll();

    $myGroupFolders = [];

    $stmtSu = $pdo->prepare(
        'SELECT id, username FROM users WHERE id <> :uid AND (allow_user_sharing = 1 OR :adm = 1) ORDER BY username ASC'
    );
    $stmtSu->execute([':uid' => $userId, ':adm' => $isAdmin ? 1 : 0]);
    $shareableUsers = $stmtSu->fetchAll();

    $stmtSg = $pdo->prepare(
        $isAdmin
            ? 'SELECT id, name FROM `groups` ORDER BY name ASC'
            : 'SELECT g.id, g.name FROM `groups` g INNER JOIN group_members gm ON gm.group_id = g.id WHERE gm.user_id = :uid ORDER BY g.name ASC'
    );
    $stmtSg->execute($isAdmin ? [] : [':uid' => $userId]);
    $shareableGroups = $stmtSg->fetchAll();
}

$statusMeta = [
    'planning'  => ['label' => '📝 In Planung',    'cls' => 'bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300 border-blue-200 dark:border-blue-800'],
    'active'    => ['label' => '🚀 Aktiv',         'cls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'],
    'on_hold'   => ['label' => '⏸️ Pausiert',      'cls' => 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800'],
    'completed' => ['label' => '✅ Abgeschlossen', 'cls' => 'bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800'],
    'archived'  => ['label' => '📦 Archiviert',    'cls' => 'bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700'],
];

$colorMeta = [
    'brand'   => ['bar' => 'bg-brand-600',   'border' => 'border-brand-500',   'soft' => 'bg-brand-50 dark:bg-brand-950/30 text-brand-700 dark:text-brand-300'],
    'emerald' => ['bar' => 'bg-emerald-600', 'border' => 'border-emerald-500', 'soft' => 'bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-300'],
    'amber'   => ['bar' => 'bg-amber-500',   'border' => 'border-amber-500',   'soft' => 'bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-300'],
    'rose'    => ['bar' => 'bg-rose-600',    'border' => 'border-rose-500',    'soft' => 'bg-rose-50 dark:bg-rose-950/30 text-rose-700 dark:text-rose-300'],
    'violet'  => ['bar' => 'bg-violet-600',  'border' => 'border-violet-500',  'soft' => 'bg-violet-50 dark:bg-violet-950/30 text-violet-700 dark:text-violet-300'],
    'slate'   => ['bar' => 'bg-slate-600',   'border' => 'border-slate-500',   'soft' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
];

// Prüfen, ob ein einzelnes Projekt geöffnet ist
$requestedProjectId = $publicProject ? (int)$publicProject['id'] : (int)($_GET['id'] ?? 0);
$activeProject      = $requestedProjectId > 0 ? getAccessibleProject($pdo, $requestedProjectId, $userId, $isAdmin, $publicProject) : null;
$activeTab          = trim((string)($_GET['tab'] ?? 'milestones'));
if (!in_array($activeTab, ['milestones', 'tasks', 'time', 'logbook'], true)) {
    $activeTab = 'milestones';
}

// Wenn kein Einzelprojekt aktiv ist -> Alle zugänglichen Projekte für die Portfolio-Übersicht laden
$allProjects = [];
if (!$activeProject && $userId > 0) {
    $stmtAll = $pdo->prepare(
        "SELECT p.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
                kb.title AS board_title, gf.name AS group_folder_name,
                (SELECT COUNT(*) FROM project_milestones pm WHERE pm.project_id = p.id) AS ms_total,
                (SELECT COUNT(*) FROM project_milestones pm WHERE pm.project_id = p.id AND pm.status = 'done') AS ms_done,
                (SELECT COALESCE(SUM(pe.hours), 0) FROM project_entries pe WHERE pe.project_id = p.id) AS logged_hours,
                (SELECT COALESCE(SUM(pe.cost_amount), 0) FROM project_entries pe WHERE pe.project_id = p.id) AS logged_cost,
                (SELECT COUNT(*) FROM tasks t WHERE p.kanban_board_id IS NOT NULL AND t.board_id = p.kanban_board_id) AS tasks_total,
                (SELECT COUNT(*) FROM tasks t WHERE p.kanban_board_id IS NOT NULL AND t.board_id = p.kanban_board_id AND t.status = 'done') AS tasks_done
         FROM projects p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN users su ON su.id = p.shared_user_id
         LEFT JOIN `groups` g ON g.id = p.group_id
         LEFT JOIN kanban_boards kb ON kb.id = p.kanban_board_id
         LEFT JOIN group_folders gf ON gf.id = p.group_folder_id
         WHERE p.user_id = :u1
            OR p.shared_user_id = :u2
            OR (p.group_id IS NOT NULL AND p.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3))
         ORDER BY
            FIELD(p.status, 'active', 'planning', 'on_hold', 'completed', 'archived'),
            (p.end_date IS NULL) ASC,
            p.end_date ASC,
            p.id DESC"
    );
    $stmtAll->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId]);
    $allProjects = $stmtAll->fetchAll();
}

// Wenn ein Einzelprojekt aktiv ist -> Meilensteine, verknüpfte Kanban-Aufgaben & Zeiterfassung/Logbuch laden
$projectMilestones = [];
$projectTasks      = [];
$projectEntries    = [];
$projectStats      = [
    'ms_total'     => 0,
    'ms_done'      => 0,
    'tasks_total'  => 0,
    'tasks_done'   => 0,
    'progress_pct' => 0,
    'logged_hours' => 0.0,
    'logged_cost'  => 0.0,
];

if ($activeProject) {
    $pid = (int)$activeProject['id'];

    $stmtMs = $pdo->prepare(
        'SELECT pm.*, u.username AS assigned_username
         FROM project_milestones pm
         LEFT JOIN users u ON u.id = pm.assigned_user_id
         WHERE pm.project_id = :pid
         ORDER BY (pm.due_date IS NULL) ASC, pm.due_date ASC, pm.sort_order ASC, pm.id ASC'
    );
    $stmtMs->execute([':pid' => $pid]);
    $projectMilestones = $stmtMs->fetchAll();

    foreach ($projectMilestones as $ms) {
        $projectStats['ms_total']++;
        if (($ms['status'] ?? '') === 'done') {
            $projectStats['ms_done']++;
        }
    }

    if (!empty($activeProject['kanban_board_id'])) {
        $stmtTk = $pdo->prepare(
            'SELECT t.*, u.username AS owner_name
             FROM tasks t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.board_id = :bid
             ORDER BY FIELD(t.status, \'todo\', \'in_progress\', \'done\'), FIELD(t.priority, \'urgent\', \'high\', \'medium\', \'low\'), t.id DESC'
        );
        $stmtTk->execute([':bid' => (int)$activeProject['kanban_board_id']]);
        $projectTasks = $stmtTk->fetchAll();
        foreach ($projectTasks as $tk) {
            $projectStats['tasks_total']++;
            if (($tk['status'] ?? '') === 'done') {
                $projectStats['tasks_done']++;
            }
        }
    }

    $totalUnits = $projectStats['ms_total'] + $projectStats['tasks_total'];
    $doneUnits  = $projectStats['ms_done'] + $projectStats['tasks_done'];
    if ($totalUnits > 0) {
        $projectStats['progress_pct'] = (int)round(($doneUnits / $totalUnits) * 100);
    } elseif (($activeProject['status'] ?? '') === 'completed') {
        $projectStats['progress_pct'] = 100;
    }

    $stmtPe = $pdo->prepare(
        'SELECT pe.*, u.username, pm.title AS milestone_title
         FROM project_entries pe
         INNER JOIN users u ON u.id = pe.user_id
         LEFT JOIN project_milestones pm ON pm.id = pe.milestone_id
         WHERE pe.project_id = :pid
         ORDER BY pe.entry_date DESC, pe.id DESC'
    );
    $stmtPe->execute([':pid' => $pid]);
    $projectEntries = $stmtPe->fetchAll();

    foreach ($projectEntries as $pe) {
        $projectStats['logged_hours'] += (float)$pe['hours'];
        $projectStats['logged_cost']  += (float)$pe['cost_amount'];
    }
}

$pageTitle = $activeProject ? ((string)$activeProject['title'] . ' – Projektmanagement') : 'Projektmanagement';
require_once __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    header, footer, .no-print { display: none !important; }
    body { background: #ffffff !important; color: #000000 !important; }
    .mc-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
}
</style>

<?php if (!$activeProject): ?>
<!-- =========================================================================
     ANSICHT 1: PROJEKT-PORTFOLIO (ALLE PROJEKTE)
     ========================================================================= -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 text-2xl shrink-0">🚀</span>
            <span>Projektmanagement &amp; Projekt-Zentrale</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            Meilensteine, visueller Gantt-Zeitstrahl, Live-Zeiterfassung, Budgetkontrolle und verknüpfte Kanban-Boards in einem leichtgewichtigen Modul.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input
            type="search"
            oninput="filterProjectsList(this.value)"
            placeholder="🔍 Projekte durchsuchen..."
            class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
        <button
            type="button"
            onclick="openProjectModal()"
            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
        >
            <span>🚀 + Neues Projekt</span>
        </button>
    </div>
</div>

<!-- Status-Filter-Leiste -->
<div class="mb-5 flex items-center gap-2 overflow-x-auto pb-1">
    <button type="button" onclick="filterProjectStatus('all', this)" data-status-btn="all" class="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-slate-900 dark:bg-white text-white dark:text-slate-900 cursor-pointer shrink-0">
        🌐 Alle (<?= count($allProjects) ?>)
    </button>
    <?php foreach ($statusMeta as $stKey => $stInfo): ?>
        <button type="button" onclick="filterProjectStatus('<?= e($stKey) ?>', this)" data-status-btn="<?= e($stKey) ?>" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:border-brand-400 cursor-pointer shrink-0">
            <?= e($stInfo['label']) ?>
        </button>
    <?php endforeach; ?>
</div>

<?php if (empty($allProjects)): ?>
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-3">
        <div class="text-4xl">🚀</div>
        <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Noch keine Projekte angelegt</h2>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
            Starte dein erstes Projekt mit Meilensteinen, Gantt-Zeitstrahl, Zeiterfassung und automatischer Verknüpfung zum Kanban-Board.
        </p>
        <button
            type="button"
            onclick="openProjectModal()"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs cursor-pointer"
        >
            <span>🚀 Erstes Projekt starten</span>
        </button>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        <?php foreach ($allProjects as $p):
            $pId        = (int)$p['id'];
            $pColor     = (string)($p['color'] ?? 'brand');
            $cMeta      = $colorMeta[$pColor] ?? $colorMeta['brand'];
            $stMeta     = $statusMeta[(string)$p['status']] ?? $statusMeta['active'];
            $msTot      = (int)$p['ms_total'];
            $msDone     = (int)$p['ms_done'];
            $tkTot      = (int)$p['tasks_total'];
            $tkDone     = (int)$p['tasks_done'];
            $totUnits   = $msTot + $tkTot;
            $doneUnits  = $msDone + $tkDone;
            $pct        = $totUnits > 0 ? (int)round(($doneUnits / $totUnits) * 100) : (($p['status'] ?? '') === 'completed' ? 100 : 0);
            $endStr     = (string)($p['end_date'] ?? '');
            $isOverdue  = ($endStr !== '' && !in_array($p['status'], ['completed', 'archived'], true) && strtotime($endStr) < strtotime(date('Y-m-d')));
        ?>
            <article
                data-project-card="<?= $pId ?>"
                data-project-status="<?= e((string)$p['status']) ?>"
                class="mc-card bg-white dark:bg-slate-900 rounded-2xl border-t-4 <?= $cMeta['border'] ?> border-x border-b border-slate-200 dark:border-slate-800 p-5 shadow-xs hover:shadow-md transition-all flex flex-col justify-between space-y-4"
            >
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-2xl"><?= e((string)$p['icon']) ?></span>
                            <div>
                                <?php if (trim((string)$p['code']) !== ''): ?>
                                    <span class="text-[10px] font-mono font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                        <?= e((string)$p['code']) ?>
                                    </span>
                                <?php endif; ?>
                                <h2 class="text-base font-bold text-slate-900 dark:text-white leading-snug mt-0.5">
                                    <a href="projects.php?id=<?= $pId ?>" class="hover:text-brand-600 dark:hover:text-brand-400">
                                        <?= e((string)$p['title']) ?>
                                    </a>
                                </h2>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border shrink-0 <?= $stMeta['cls'] ?>">
                            <?= e($stMeta['label']) ?>
                        </span>
                    </div>

                    <?php if (trim((string)($p['description'] ?? '')) !== ''): ?>
                        <p class="text-xs text-slate-600 dark:text-slate-300 line-clamp-2 leading-relaxed">
                            <?= e((string)$p['description']) ?>
                        </p>
                    <?php endif; ?>

                    <!-- Fortschrittsbalken -->
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold mb-1">
                            <span class="text-slate-600 dark:text-slate-300">Projekt-Fortschritt</span>
                            <span class="font-bold text-slate-900 dark:text-white"><?= $pct ?>%</span>
                        </div>
                        <div class="w-full h-2.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full <?= $cMeta['bar'] ?> rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                        </div>
                    </div>

                    <!-- Kennzahlen-Grid -->
                    <div class="grid grid-cols-3 gap-2 pt-1 text-center">
                        <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70">
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">🏁 Meilensteine</div>
                            <div class="text-xs font-bold text-slate-900 dark:text-white mt-0.5"><?= $msDone ?> / <?= $msTot ?></div>
                        </div>
                        <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70">
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">📋 Aufgaben</div>
                            <div class="text-xs font-bold text-slate-900 dark:text-white mt-0.5"><?= $tkDone ?> / <?= $tkTot ?></div>
                        </div>
                        <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70">
                            <div class="text-[10px] text-slate-500 dark:text-slate-400">⏱️ Stunden</div>
                            <div class="text-xs font-bold text-slate-900 dark:text-white mt-0.5">
                                <?= number_format((float)$p['logged_hours'], 1, ',', '.') ?> h
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between gap-2 text-xs">
                    <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                        <?php if ($endStr !== ''): ?>
                            <span class="px-2 py-0.5 rounded-md font-semibold <?= $isOverdue ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300' ?>">
                                📅 <?= date('d.m.Y', strtotime($endStr)) ?><?= $isOverdue ? ' (Überfällig)' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($p['group_name'])): ?>
                            <span class="px-2 py-0.5 rounded-md bg-purple-50 dark:bg-purple-950 text-purple-700 dark:text-purple-300 font-semibold">
                                👥 <?= e((string)$p['group_name']) ?>
                            </span>
                        <?php elseif (!empty($p['shared_user_name'])): ?>
                            <span class="px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-semibold">
                                🤝 <?= e((string)$p['shared_user_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <a
                        href="projects.php?id=<?= $pId ?>"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shrink-0 transition-colors"
                    >
                        <span>Öffnen &rarr;</span>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php else: ?>
<!-- =========================================================================
     ANSICHT 2: PROJEKT-DETAIL-COCKPIT (MEILENSTEINE, GANTT, ZEIT & KANBAN)
     ========================================================================= -->
<?php
$pId         = (int)$activeProject['id'];
$pColor      = (string)($activeProject['color'] ?? 'brand');
$cMeta       = $colorMeta[$pColor] ?? $colorMeta['brand'];
$stMeta      = $statusMeta[(string)$activeProject['status']] ?? $statusMeta['active'];
$isProjOwner = ($userId > 0 && ((int)$activeProject['user_id'] === $userId || $isAdmin));
$canEditProj = ($userId > 0) || ($publicProject && ($publicProject['share_mode'] ?? 'read') === 'write');

$pShareVal = '';
if (!empty($activeProject['shared_user_id'])) {
    $pShareVal = 'user:' . (int)$activeProject['shared_user_id'];
} elseif (!empty($activeProject['group_id'])) {
    $pShareVal = 'group:' . (int)$activeProject['group_id'];
}

$publicProjectUrl = '';
if (!empty($activeProject['share_token']) && ($activeProject['share_mode'] ?? 'disabled') !== 'disabled') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/projects.php')), '/');
    $publicProjectUrl = $scheme . '://' . $host . $dir . '/projects.php?project_token=' . rawurlencode((string)$activeProject['share_token']);
}

$projEditJson = [
    'id'              => $pId,
    'code'            => (string)($activeProject['code'] ?? ''),
    'title'           => (string)$activeProject['title'],
    'description'     => (string)($activeProject['description'] ?? ''),
    'icon'            => (string)($activeProject['icon'] ?? '🚀'),
    'color'           => $pColor,
    'status'          => (string)$activeProject['status'],
    'start_date'      => (string)($activeProject['start_date'] ?? ''),
    'end_date'        => (string)($activeProject['end_date'] ?? ''),
    'budget_hours'    => (float)$activeProject['budget_hours'],
    'budget_amount'   => (float)$activeProject['budget_amount'],
    'kanban_board_id' => (int)($activeProject['kanban_board_id'] ?? 0),
    'group_folder_id' => (int)($activeProject['group_folder_id'] ?? 0),
    'share_target'    => $pShareVal,
    'share_mode'      => (string)($activeProject['share_mode'] ?? 'disabled'),
    'share_url'       => $publicProjectUrl,
];

$budgetHours  = (float)$activeProject['budget_hours'];
$budgetAmount = (float)$activeProject['budget_amount'];
$hoursPct     = $budgetHours > 0 ? min(100, (int)round(($projectStats['logged_hours'] / $budgetHours) * 100)) : 0;
$costPct      = $budgetAmount > 0 ? min(100, (int)round(($projectStats['logged_cost'] / $budgetAmount) * 100)) : 0;
?>

<!-- Zurück-Leiste & Projekt-Aktionen -->
<div class="mb-4 flex flex-wrap items-center justify-between gap-2 no-print">
    <?php if (!$publicProject): ?>
        <a href="projects.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 text-xs sm:text-sm font-semibold transition-colors">
            <span>&larr; Alle Projekte</span>
        </a>
    <?php else: ?>
        <span class="text-xs font-semibold text-slate-500">🔗 Geteilte Projektansicht</span>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-2">
        <?php if (!empty($activeProject['kanban_board_id']) && $userId > 0): ?>
            <a href="tasks.php?board=<?= (int)$activeProject['kanban_board_id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/70 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-semibold">
                <span>📋 Zum Projekt-Kanban-Board</span>
            </a>
        <?php endif; ?>

        <?php if (!empty($activeProject['group_folder_id']) && $userId > 0): ?>
            <a href="index.php?group_folder=<?= (int)$activeProject['group_folder_id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-semibold">
                <span>📂 Projekt-Ordner (<?= e((string)$activeProject['group_folder_name']) ?>)</span>
            </a>
        <?php endif; ?>

        <?php if ($publicProjectUrl !== ''): ?>
            <button
                type="button"
                onclick="copyProjectLink(<?= htmlspecialchars((string)json_encode($publicProjectUrl), ENT_QUOTES, 'UTF-8') ?>, this)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-brand-700 dark:text-brand-300 border border-brand-300 dark:border-brand-700 text-xs font-semibold cursor-pointer"
            >
                <span>🔗 Projekt-Link kopieren</span>
            </button>
        <?php endif; ?>

        <button
            type="button"
            onclick="window.print()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-semibold cursor-pointer"
            title="Sauberen Projektstatus-Bericht drucken oder als PDF speichern"
        >
            <span>🖨️ Statusbericht drucken</span>
        </button>

        <?php if ($isProjOwner): ?>
            <button
                type="button"
                onclick='openProjectModal(<?= htmlspecialchars((string)json_encode($projEditJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-2xs cursor-pointer"
            >
                <span>🤝 Projekt teilen / Bearbeiten</span>
            </button>
            <form action="projects.php" method="post" class="inline" onsubmit="return confirm('Dieses Projekt inklusive Meilensteinen und Zeiterfassung wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="project_id" value="<?= $pId ?>">
                <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-red-50 text-slate-500 hover:text-red-600 border border-slate-200 dark:border-slate-700 text-xs font-semibold cursor-pointer" title="Projekt löschen">🗑️</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Projekt-Kopfbanner -->
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border-l-4 <?= $cMeta['border'] ?> border border-slate-200 dark:border-slate-800 p-5 shadow-xs mb-5">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div class="space-y-1.5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-2xl"><?= e((string)$activeProject['icon']) ?></span>
                <?php if (trim((string)$activeProject['code']) !== ''): ?>
                    <span class="px-2 py-0.5 rounded text-xs font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                        <?= e((string)$activeProject['code']) ?>
                    </span>
                <?php endif; ?>
                <h1 class="text-lg sm:text-2xl font-bold text-slate-900 dark:text-white">
                    <?= e((string)$activeProject['title']) ?>
                </h1>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border <?= $stMeta['cls'] ?>">
                    <?= e($stMeta['label']) ?>
                </span>
                <?php if (!empty($activeProject['group_name'])): ?>
                    <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300">
                        👥 Team: <?= e((string)$activeProject['group_name']) ?>
                    </span>
                <?php elseif (!empty($activeProject['shared_user_name'])): ?>
                    <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                        🤝 Geteilt mit <?= e((string)$activeProject['shared_user_name']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if (trim((string)($activeProject['description'] ?? '')) !== ''): ?>
                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 whitespace-pre-line leading-relaxed">
                    <?= e((string)$activeProject['description']) ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap items-center gap-3 text-xs text-slate-600 dark:text-slate-300 shrink-0">
            <?php if (!empty($activeProject['start_date'])): ?>
                <div class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-slate-400 block text-[10px]">Projektstart</span>
                    <strong>📅 <?= date('d.m.Y', strtotime((string)$activeProject['start_date'])) ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($activeProject['end_date'])): ?>
                <div class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    <span class="text-slate-400 block text-[10px]">Zieldatum / Deadline</span>
                    <strong>🏁 <?= date('d.m.Y', strtotime((string)$activeProject['end_date'])) ?></strong>
                </div>
            <?php endif; ?>
            <div class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                <span class="text-slate-400 block text-[10px]">Projektleitung</span>
                <strong>👤 <?= e((string)$activeProject['owner_name']) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- 4 KPI-KACHELN -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 mb-1">
            <span>📈 Gesamt-Fortschritt</span>
            <span class="text-sm font-bold text-slate-900 dark:text-white"><?= $projectStats['progress_pct'] ?>%</span>
        </div>
        <div class="w-full h-2.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden mb-1.5">
            <div class="h-full <?= $cMeta['bar'] ?> rounded-full transition-all" style="width: <?= $projectStats['progress_pct'] ?>%"></div>
        </div>
        <div class="text-[11px] text-slate-500 dark:text-slate-400">
            Kombinierter Wert aus Meilensteinen &amp; Kanban
        </div>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
        <div class="text-xs text-slate-500 dark:text-slate-400 mb-1">🏁 Meilensteine &amp; Aufgaben</div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
            <?= $projectStats['ms_done'] ?>/<?= $projectStats['ms_total'] ?> Meilensteine
        </div>
        <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
            📋 <?= $projectStats['tasks_done'] ?>/<?= $projectStats['tasks_total'] ?> Kanban-Aufgaben erledigt
        </div>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 mb-1">
            <span>⏱️ Zeiterfassung / Aufwand</span>
            <?php if ($budgetHours > 0): ?>
                <span class="font-bold"><?= $hoursPct ?>%</span>
            <?php endif; ?>
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
            <?= number_format($projectStats['logged_hours'], 2, ',', '.') ?> Std.
            <?php if ($budgetHours > 0): ?>
                <span class="text-xs font-normal text-slate-400">/ <?= number_format($budgetHours, 1, ',', '.') ?> Std.</span>
            <?php endif; ?>
        </div>
        <?php if ($budgetHours > 0): ?>
            <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden mt-1.5">
                <div class="h-full <?= $hoursPct >= 100 ? 'bg-red-600' : 'bg-emerald-600' ?> rounded-full" style="width: <?= $hoursPct ?>%"></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
        <div class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 mb-1">
            <span>💶 Projektkosten / Budget</span>
            <?php if ($budgetAmount > 0): ?>
                <span class="font-bold"><?= $costPct ?>%</span>
            <?php endif; ?>
        </div>
        <div class="text-base font-bold text-slate-900 dark:text-white">
            <?= number_format($projectStats['logged_cost'], 2, ',', '.') ?> €
            <?php if ($budgetAmount > 0): ?>
                <span class="text-xs font-normal text-slate-400">/ <?= number_format($budgetAmount, 2, ',', '.') ?> €</span>
            <?php endif; ?>
        </div>
        <?php if ($budgetAmount > 0): ?>
            <div class="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden mt-1.5">
                <div class="h-full <?= $costPct >= 100 ? 'bg-red-600' : 'bg-brand-600' ?> rounded-full" style="width: <?= $costPct ?>%"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- REITER-NAVIGATION INNERHALB DES PROJEKTS -->
<div class="mb-5 flex items-center gap-2 overflow-x-auto pb-1 no-print">
    <?php
    $baseTabUrl = $publicProject
        ? 'projects.php?project_token=' . rawurlencode((string)$publicProject['share_token'])
        : 'projects.php?id=' . $pId;
    $tabs = [
        'milestones' => '🏁 1. Meilensteine & Gantt-Zeitstrahl (' . $projectStats['ms_total'] . ')',
        'tasks'      => '📋 2. Projektaufgaben / Kanban (' . $projectStats['tasks_total'] . ')',
        'time'       => '⏱️ 3. Zeiterfassung, Stoppuhr & Kosten',
        'logbook'    => '📓 4. Projekt-Tagebuch & Entscheidungen',
    ];
    foreach ($tabs as $tKey => $tLabel):
        $isAct = ($activeTab === $tKey);
    ?>
        <a
            href="<?= e($baseTabUrl . '&tab=' . $tKey) ?>"
            class="px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold border transition-all shrink-0 <?= $isAct ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>"
        >
            <?= e($tLabel) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- =========================================================================
     TAB 1: MEILENSTEINE & VISUELLER GANTT-ZEITSTRAHL
     ========================================================================= -->
<?php if ($activeTab === 'milestones'): ?>
<div class="space-y-5">
    <?php
    // Leichtgewichtigen Gantt-Zeitstrahl berechnen, sobald Datumsangaben vorhanden sind
    $ganttStartTs = !empty($activeProject['start_date']) ? strtotime((string)$activeProject['start_date']) : null;
    $ganttEndTs   = !empty($activeProject['end_date']) ? strtotime((string)$activeProject['end_date']) : null;

    foreach ($projectMilestones as $ms) {
        if (!empty($ms['start_date'])) {
            $ts = strtotime((string)$ms['start_date']);
            if ($ganttStartTs === null || $ts < $ganttStartTs) $ganttStartTs = $ts;
        }
        if (!empty($ms['due_date'])) {
            $ts = strtotime((string)$ms['due_date']);
            if ($ganttStartTs === null || $ts < $ganttStartTs) $ganttStartTs = $ts - 7 * 86400;
            if ($ganttEndTs === null || $ts > $ganttEndTs) $ganttEndTs = $ts;
        }
    }
    if ($ganttStartTs !== null && ($ganttEndTs === null || $ganttEndTs <= $ganttStartTs)) {
        $ganttEndTs = $ganttStartTs + 30 * 86400;
    }
    ?>

    <?php if ($ganttStartTs !== null && $ganttEndTs !== null && !empty($projectMilestones)):
        $totalSpanSec = max(86400, $ganttEndTs - $ganttStartTs);
        $todayTs      = strtotime(date('Y-m-d'));
        $todayPosPct  = ($todayTs >= $ganttStartTs && $todayTs <= $ganttEndTs)
            ? round((($todayTs - $ganttStartTs) / $totalSpanSec) * 100, 1)
            : null;
    ?>
    <!-- VISUELLER GANTT-ZEITSTRAHL -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📅 Visueller Projekt-Zeitstrahl (Gantt-Übersicht)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Zeitraum: <?= date('d.m.Y', $ganttStartTs) ?> bis <?= date('d.m.Y', $ganttEndTs) ?>
                    <?= $todayPosPct !== null ? ' · 🔴 Rote Linie = Heute (' . date('d.m.') . ')' : '' ?>
                </p>
            </div>
            <div class="flex items-center gap-3 text-[11px]">
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Erreicht</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span> In Arbeit</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Offen</span>
            </div>
        </div>

        <div class="relative space-y-2.5 pt-2 pb-1">
            <?php if ($todayPosPct !== null): ?>
                <div
                    class="absolute top-0 bottom-0 w-0.5 bg-red-500/80 z-10 pointer-events-none"
                    style="left: <?= $todayPosPct ?>%;"
                    title="Heute (<?= date('d.m.Y') ?>)"
                ></div>
            <?php endif; ?>

            <?php foreach ($projectMilestones as $ms):
                $msStart = !empty($ms['start_date']) ? strtotime((string)$ms['start_date']) : (!empty($ms['due_date']) ? strtotime((string)$ms['due_date']) - 5 * 86400 : $ganttStartTs);
                $msEnd   = !empty($ms['due_date']) ? strtotime((string)$ms['due_date']) : ($msStart + 5 * 86400);
                if ($msEnd < $msStart) $msEnd = $msStart + 86400;

                $leftPct  = max(0, min(96, round((($msStart - $ganttStartTs) / $totalSpanSec) * 100, 1)));
                $widthPct = max(4, min(100 - $leftPct, round((($msEnd - $msStart) / $totalSpanSec) * 100, 1)));

                $barCls = 'bg-amber-500';
                if (($ms['status'] ?? '') === 'done') {
                    $barCls = 'bg-emerald-500';
                } elseif (($ms['status'] ?? '') === 'in_progress') {
                    $barCls = 'bg-brand-500';
                }
            ?>
                <div class="grid grid-cols-1 sm:grid-cols-12 items-center gap-2 text-xs">
                    <div class="sm:col-span-4 truncate font-semibold text-slate-800 dark:text-slate-200 pr-2">
                        <?= ($ms['status'] ?? '') === 'done' ? '✅' : (($ms['status'] ?? '') === 'in_progress' ? '⏳' : '📌') ?>
                        <?= e((string)$ms['title']) ?>
                    </div>
                    <div class="sm:col-span-8 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 relative overflow-hidden">
                        <div
                            class="h-full <?= $barCls ?> rounded-lg flex items-center px-2 text-[10px] font-bold text-white truncate shadow-2xs"
                            style="margin-left: <?= $leftPct ?>%; width: <?= $widthPct ?>%;"
                            title="<?= e((string)$ms['title']) ?> (<?= !empty($ms['due_date']) ? 'Fällig: ' . date('d.m.Y', strtotime((string)$ms['due_date'])) : '' ?>)"
                        >
                            <?= !empty($ms['due_date']) ? date('d.m.', strtotime((string)$ms['due_date'])) : '' ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MEILENSTEIN-LISTE & + NEUER MEILENSTEIN -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
        <div class="flex items-center justify-between gap-2 mb-4">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">🏁 Projekt-Meilensteine &amp; Etappenziele</h2>
            <?php if ($canEditProj): ?>
                <button
                    type="button"
                    onclick="openMilestoneModal()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer no-print"
                >
                    <span>+ Meilenstein hinzufügen</span>
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($projectMilestones)): ?>
            <p class="text-xs text-slate-500 dark:text-slate-400 py-6 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                Noch keine Meilensteine definiert. Lege Meilensteine mit Start- und Zieldatum an, um den Gantt-Zeitstrahl zu aktivieren!
            </p>
        <?php else: ?>
            <div class="space-y-2.5">
                <?php foreach ($projectMilestones as $ms):
                    $msId   = (int)$ms['id'];
                    $msStat = (string)($ms['status'] ?? 'open');
                    $nextSt = $msStat === 'open' ? 'in_progress' : ($msStat === 'in_progress' ? 'done' : 'open');
                    $dueStr = (string)($ms['due_date'] ?? '');
                    $isOver = ($dueStr !== '' && $msStat !== 'done' && strtotime($dueStr) < strtotime(date('Y-m-d')));
                    $msJson = [
                        'id'               => $msId,
                        'title'            => (string)$ms['title'],
                        'description'      => (string)($ms['description'] ?? ''),
                        'start_date'       => (string)($ms['start_date'] ?? ''),
                        'due_date'         => $dueStr,
                        'status'           => $msStat,
                        'assigned_user_id' => (int)($ms['assigned_user_id'] ?? 0),
                    ];
                ?>
                    <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <?php if ($canEditProj): ?>
                                <form action="projects.php" method="post" class="shrink-0 mt-0.5 no-print">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="cycle_milestone_status">
                                    <input type="hidden" name="project_id" value="<?= $pId ?>">
                                    <input type="hidden" name="milestone_id" value="<?= $msId ?>">
                                    <input type="hidden" name="status" value="<?= e($nextSt) ?>">
                                    <?php if ($publicProject): ?>
                                        <input type="hidden" name="project_token" value="<?= e((string)$publicProject['share_token']) ?>">
                                    <?php endif; ?>
                                    <button
                                        type="submit"
                                        class="px-2.5 py-1 rounded-lg text-xs font-bold border cursor-pointer <?= $msStat === 'done' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border-emerald-300' : ($msStat === 'in_progress' ? 'bg-brand-100 dark:bg-brand-950 text-brand-800 dark:text-brand-300 border-brand-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border-amber-300') ?>"
                                        title="Klicken zum Wechseln des Status (Offen → In Arbeit → Erreicht)"
                                    >
                                        <?= $msStat === 'done' ? '✅ Erreicht' : ($msStat === 'in_progress' ? '⏳ In Arbeit' : '📌 Offen') ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-white <?= $msStat === 'done' ? 'line-through opacity-70' : '' ?>">
                                    <?= e((string)$ms['title']) ?>
                                </h3>
                                <?php if (trim((string)($ms['description'] ?? '')) !== ''): ?>
                                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5"><?= e((string)$ms['description']) ?></p>
                                <?php endif; ?>
                                <div class="flex flex-wrap items-center gap-2 mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    <?php if (!empty($ms['start_date'])): ?>
                                        <span>▶️ Start: <?= date('d.m.Y', strtotime((string)$ms['start_date'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($dueStr !== ''): ?>
                                        <span class="font-semibold <?= $isOver ? 'text-red-600 dark:text-red-400' : '' ?>">
                                            🏁 Ziel: <?= date('d.m.Y', strtotime($dueStr)) ?><?= $isOver ? ' (Überfällig!)' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($ms['assigned_username'])): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-semibold">
                                            👤 <?= e((string)$ms['assigned_username']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($userId > 0): ?>
                            <div class="flex items-center gap-1 shrink-0 no-print">
                                <button
                                    type="button"
                                    onclick='openMilestoneModal(<?= htmlspecialchars((string)json_encode($msJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                                    class="p-1.5 rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 cursor-pointer"
                                    title="Meilenstein bearbeiten"
                                >✏️</button>
                                <form action="projects.php" method="post" class="inline" onsubmit="return confirm('Meilenstein löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_milestone">
                                    <input type="hidden" name="project_id" value="<?= $pId ?>">
                                    <input type="hidden" name="milestone_id" value="<?= $msId ?>">
                                    <button type="submit" class="p-1.5 rounded-lg hover:bg-red-100 text-slate-400 hover:text-red-600 cursor-pointer" title="Löschen">🗑️</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- =========================================================================
     TAB 2: VERKNÜPFTE PROJEKTAUFGABEN (KANBAN-INTEGRATION)
     ========================================================================= -->
<?php elseif ($activeTab === 'tasks'): ?>
<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
    <?php if (empty($activeProject['kanban_board_id'])): ?>
        <div class="py-8 text-center space-y-3">
            <div class="text-3xl">📋</div>
            <h3 class="text-base font-bold text-slate-900 dark:text-white">Noch kein Kanban-Board mit diesem Projekt verknüpft</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                Erzeuge mit 1 Klick ein passendes Kanban-Board für dieses Projekt – alle Aufgaben und Erledigungen fließen dann automatisch in den Projektfortschritt ein.
            </p>
            <?php if ($userId > 0): ?>
                <form action="projects.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="create_linked_board">
                    <input type="hidden" name="project_id" value="<?= $pId ?>">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold cursor-pointer">
                        🗂️ Jetzt passendes Kanban-Board erzeugen &amp; verknüpfen
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    📋 Verknüpftes Kanban-Board: „<?= e((string)($activeProject['board_title'] ?? $activeProject['title'])) ?>“
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Aufgaben hier oder direkt im vollen <a href="tasks.php?board=<?= (int)$activeProject['kanban_board_id'] ?>" class="text-brand-600 hover:underline font-semibold">Kanban-Board</a> bearbeiten.
                </p>
            </div>

            <?php if ($userId > 0): ?>
                <form action="projects.php" method="post" class="flex flex-wrap items-center gap-2 no-print">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="quick_add_project_task">
                    <input type="hidden" name="project_id" value="<?= $pId ?>">
                    <input
                        type="text"
                        name="title"
                        required
                        placeholder="+ Schnelle Projektaufgabe..."
                        class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                    >
                    <select name="priority" class="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <option value="medium">📌 Mittel</option>
                        <option value="high">⚡ Hoch</option>
                        <option value="urgent">🔥 Dringend</option>
                        <option value="low">🌱 Niedrig</option>
                    </select>
                    <input type="date" name="due_date" class="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                    <button type="submit" class="px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">Hinzufügen</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($projectTasks)): ?>
            <p class="text-xs text-slate-500 py-6 text-center">Noch keine Aufgaben im verknüpften Kanban-Board vorhanden.</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($projectTasks as $tk):
                    $tkId   = (int)$tk['id'];
                    $tkSt   = (string)$tk['status'];
                    $nextTk = $tkSt === 'todo' ? 'in_progress' : ($tkSt === 'in_progress' ? 'done' : 'todo');
                ?>
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 flex items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <?php if ($canEditProj): ?>
                                <form action="projects.php" method="post" class="shrink-0 no-print">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="quick_task_status">
                                    <input type="hidden" name="project_id" value="<?= $pId ?>">
                                    <input type="hidden" name="task_id" value="<?= $tkId ?>">
                                    <input type="hidden" name="status" value="<?= e($nextTk) ?>">
                                    <?php if ($publicProject): ?>
                                        <input type="hidden" name="project_token" value="<?= e((string)$publicProject['share_token']) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="px-2 py-1 rounded-lg font-bold border cursor-pointer <?= $tkSt === 'done' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : ($tkSt === 'in_progress' ? 'bg-brand-100 text-brand-800 border-brand-300' : 'bg-amber-100 text-amber-800 border-amber-300') ?>">
                                        <?= $tkSt === 'done' ? '✅ Erledigt' : ($tkSt === 'in_progress' ? '⏳ In Arbeit' : '📌 Offen') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <span class="font-bold text-slate-900 dark:text-white truncate <?= $tkSt === 'done' ? 'line-through opacity-70' : '' ?>">
                                <?= e((string)$tk['title']) ?>
                            </span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 text-[11px] text-slate-500">
                            <?php if (!empty($tk['due_date'])): ?>
                                <span>📅 <?= date('d.m.Y', strtotime((string)$tk['due_date'])) ?></span>
                            <?php endif; ?>
                            <span>👤 <?= e((string)$tk['owner_name']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- =========================================================================
     TAB 3: ZEITERFASSUNG, LIVE-STOPPUHR & PROJEKTKOSTEN
     ========================================================================= -->
<?php elseif ($activeTab === 'time'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
    <?php if ($userId > 0): ?>
    <!-- Linke Spalte: Live-Stoppuhr & Buchungsformular -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4 no-print">
        <div class="p-3.5 rounded-xl bg-brand-50/70 dark:bg-brand-950/30 border border-brand-200 dark:border-brand-800 text-center space-y-2">
            <div class="text-xs font-bold uppercase tracking-wider text-brand-700 dark:text-brand-300">⏱️ Live-Projekt-Stoppuhr</div>
            <div id="stopwatchDisplay" class="text-2xl font-mono font-bold text-slate-900 dark:text-white">00:00:00</div>
            <div class="flex items-center justify-center gap-2">
                <button type="button" id="swStartBtn" onclick="toggleStopwatch()" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold cursor-pointer">
                    ▶️ Starten
                </button>
                <button type="button" onclick="applyStopwatchToForm()" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                    ⬇️ In Formular übernehmen
                </button>
                <button type="button" onclick="resetStopwatch()" class="px-2.5 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-semibold cursor-pointer">
                    ↺
                </button>
            </div>
        </div>

        <form action="projects.php" method="post" class="space-y-3 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" name="project_id" value="<?= $pId ?>">

            <h3 class="font-bold text-slate-900 dark:text-white">➕ Zeit oder Ausgabe buchen</h3>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Buchungstyp</label>
                    <select name="entry_type" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="time">⏱️ Arbeitszeit</option>
                        <option value="expense">💶 Ausgabe / Kosten</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Datum</label>
                    <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Tätigkeit / Bezeichnung *</label>
                <input type="text" name="title" required placeholder="z. B. Konzeptabstimmung, Server-Lizenz..." class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">⏱️ Stunden (Std.)</label>
                    <input type="number" step="0.01" min="0" name="hours" id="entryHoursInput" value="0.00" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">💶 Kosten (€)</label>
                    <input type="number" step="0.01" min="0" name="cost_amount" value="0.00" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🏁 Zuordnung zu Meilenstein</label>
                <select name="milestone_id" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                    <option value="0">— Allgemein zum Projekt —</option>
                    <?php foreach ($projectMilestones as $ms): ?>
                        <option value="<?= (int)$ms['id'] ?>">🏁 <?= e((string)$ms['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Details / Bemerkung</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700"></textarea>
            </div>

            <button type="submit" class="w-full py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shadow-xs cursor-pointer">
                💾 Buchung speichern
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Rechte Spalte: Buchungsübersicht -->
    <div class="lg:col-span-2 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs">
        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white mb-3">
            📊 Gebuchte Zeiten &amp; Projektkosten
        </h3>
        <?php
        $timeAndExpenseEntries = array_filter($projectEntries, static fn($e) => in_array($e['entry_type'], ['time', 'expense'], true));
        ?>
        <?php if (empty($timeAndExpenseEntries)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">Noch keine Zeiten oder Ausgaben auf dieses Projekt gebucht.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-500">
                            <th class="py-2 pr-3">Datum</th>
                            <th class="py-2 pr-3">Tätigkeit / Position</th>
                            <th class="py-2 pr-3">Person</th>
                            <th class="py-2 pr-3 text-right">Stunden</th>
                            <th class="py-2 pr-3 text-right">Betrag (€)</th>
                            <th class="py-2 text-right no-print"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($timeAndExpenseEntries as $pe): ?>
                            <tr>
                                <td class="py-2.5 pr-3 whitespace-nowrap font-medium"><?= date('d.m.Y', strtotime((string)$pe['entry_date'])) ?></td>
                                <td class="py-2.5 pr-3">
                                    <div class="font-bold text-slate-900 dark:text-white">
                                        <?= $pe['entry_type'] === 'expense' ? '💶' : '⏱️' ?> <?= e((string)$pe['title']) ?>
                                    </div>
                                    <?php if (!empty($pe['milestone_title'])): ?>
                                        <span class="text-[10px] text-brand-600 dark:text-brand-400">🏁 <?= e((string)$pe['milestone_title']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($pe['notes'])): ?>
                                        <div class="text-[11px] text-slate-500 mt-0.5"><?= e((string)$pe['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 pr-3 whitespace-nowrap">👤 <?= e((string)$pe['username']) ?></td>
                                <td class="py-2.5 pr-3 text-right font-mono font-semibold">
                                    <?= (float)$pe['hours'] > 0 ? number_format((float)$pe['hours'], 2, ',', '.') . ' h' : '—' ?>
                                </td>
                                <td class="py-2.5 pr-3 text-right font-mono font-semibold">
                                    <?= (float)$pe['cost_amount'] > 0 ? number_format((float)$pe['cost_amount'], 2, ',', '.') . ' €' : '—' ?>
                                </td>
                                <td class="py-2.5 text-right no-print">
                                    <?php if ($userId > 0 && ((int)$pe['user_id'] === $userId || $isProjOwner)): ?>
                                        <form action="projects.php" method="post" class="inline" onsubmit="return confirm('Buchung löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_entry">
                                            <input type="hidden" name="project_id" value="<?= $pId ?>">
                                            <input type="hidden" name="entry_id" value="<?= (int)$pe['id'] ?>">
                                            <input type="hidden" name="return_tab" value="time">
                                            <button type="submit" class="text-slate-400 hover:text-red-600 cursor-pointer">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold text-slate-900 dark:text-white">
                            <td colspan="3" class="py-2.5 pr-3">Gesamtsumme Projekt</td>
                            <td class="py-2.5 pr-3 text-right font-mono"><?= number_format($projectStats['logged_hours'], 2, ',', '.') ?> h</td>
                            <td class="py-2.5 pr-3 text-right font-mono"><?= number_format($projectStats['logged_cost'], 2, ',', '.') ?> €</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- =========================================================================
     TAB 4: PROJEKT-TAGEBUCH & ENTSCHEIDUNGS-PROTOKOLL
     ========================================================================= -->
<?php elseif ($activeTab === 'logbook'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
    <?php if ($userId > 0): ?>
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-3 no-print">
        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📓 Neuer Tagebuch- / Protokoll-Eintrag</h3>
        <form action="projects.php" method="post" class="space-y-3 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" name="project_id" value="<?= $pId ?>">
            <input type="hidden" name="entry_type" value="log">

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Datum</label>
                <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Betreff / Entscheidung / Meilenstein-Update *</label>
                <input type="text" name="title" required placeholder="z. B. Kundenfreigabe Design erhalten" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Protokoll / Details</label>
                <textarea name="notes" rows="4" placeholder="Wichtige Beschlüsse, Risiken oder nächste Schritte..." class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700"></textarea>
            </div>

            <button type="submit" class="w-full py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold cursor-pointer">
                💾 Ins Projekt-Tagebuch eintragen
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="lg:col-span-2 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-3">
        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">📓 Chronologisches Projekt-Tagebuch</h3>
        <?php
        $logEntries = array_filter($projectEntries, static fn($e) => ($e['entry_type'] ?? '') === 'log');
        ?>
        <?php if (empty($logEntries)): ?>
            <p class="text-xs text-slate-500 py-8 text-center">Noch keine Einträge im Projekt-Tagebuch vorhanden.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($logEntries as $le): ?>
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/70 space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-900 dark:text-white">📌 <?= e((string)$le['title']) ?></span>
                            <div class="flex items-center gap-2 text-[11px] text-slate-500">
                                <span>📅 <?= date('d.m.Y', strtotime((string)$le['entry_date'])) ?> · 👤 <?= e((string)$le['username']) ?></span>
                                <?php if ($userId > 0 && ((int)$le['user_id'] === $userId || $isProjOwner)): ?>
                                    <form action="projects.php" method="post" class="inline no-print" onsubmit="return confirm('Eintrag löschen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="project_id" value="<?= $pId ?>">
                                        <input type="hidden" name="entry_id" value="<?= (int)$le['id'] ?>">
                                        <input type="hidden" name="return_tab" value="logbook">
                                        <button type="submit" class="text-slate-400 hover:text-red-600 cursor-pointer">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($le['notes'])): ?>
                            <p class="text-xs text-slate-600 dark:text-slate-300 whitespace-pre-line leading-relaxed"><?= e((string)$le['notes']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- MODAL: MEILENSTEIN ANLEGEN / BEARBEITEN -->
<?php if ($canEditProj): ?>
<div
    id="milestoneModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs no-print"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 id="milestoneModalTitle" class="text-base font-bold text-slate-900 dark:text-white">🏁 Meilenstein anlegen</h3>
            <button type="button" onclick="closeMilestoneModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>
        <form action="projects.php" method="post" class="p-5 space-y-3.5 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_milestone">
            <input type="hidden" name="project_id" value="<?= $pId ?>">
            <input type="hidden" name="milestone_id" id="msFormId" value="0">
            <?php if ($publicProject): ?>
                <input type="hidden" name="project_token" value="<?= e((string)$publicProject['share_token']) ?>">
            <?php endif; ?>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Meilenstein-Titel *</label>
                <input type="text" name="title" id="msFormTitle" required maxlength="200" placeholder="z. B. Prototyp fertiggestellt" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Startdatum (für Gantt)</label>
                    <input type="date" name="start_date" id="msFormStart" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Zieldatum / Fällig</label>
                    <input type="date" name="due_date" id="msFormDue" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                    <select name="status" id="msFormStatus" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="open">📌 Offen</option>
                        <option value="in_progress">⏳ In Arbeit</option>
                        <option value="done">✅ Erreicht</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Verantwortlich</label>
                    <select name="assigned_user_id" id="msFormAssigned" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="0">— Offen —</option>
                        <?php if ($userId > 0): ?>
                            <option value="<?= $userId ?>">👤 <?= e($username) ?> (Ich)</option>
                        <?php endif; ?>
                        <?php foreach ($shareableUsers as $su): ?>
                            <option value="<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Kurzbeschreibung</label>
                <input type="text" name="description" id="msFormDesc" maxlength="500" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeMilestoneModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold cursor-pointer">💾 Speichern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($userId > 0): ?>
<!-- MODAL: PROJEKT ANLEGEN / BEARBEITEN / TEILEN -->
<div
    id="projectModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs no-print"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-xl max-h-[92vh] rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 id="projectModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                🚀 Neues Projekt anlegen
            </h3>
            <button type="button" onclick="closeProjectModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>

        <form action="projects.php" method="post" class="p-5 overflow-y-auto space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_project">
            <input type="hidden" name="project_id" id="projFormId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div class="sm:col-span-3">
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Projektname *</label>
                    <input type="text" name="title" id="projFormTitle" required maxlength="180" placeholder="z. B. Website-Relaunch 2026" class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Kürzel</label>
                    <input type="text" name="code" id="projFormCode" maxlength="20" placeholder="PRJ-01" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Status</label>
                    <select name="status" id="projFormStatus" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="planning">📝 In Planung</option>
                        <option value="active" selected>🚀 Aktiv</option>
                        <option value="on_hold">⏸️ Pausiert</option>
                        <option value="completed">✅ Abgeschlossen</option>
                        <option value="archived">📦 Archiviert</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Symbol</label>
                    <select name="icon" id="projFormIcon" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <?php foreach ($allowedIcons as $ic): ?>
                            <option value="<?= e($ic) ?>"><?= e($ic) ?> Symbol</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Farbe</label>
                    <select name="color" id="projFormColor" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                        <option value="brand">🔵 Ozeanblau</option>
                        <option value="emerald">🟢 Smaragdgrün</option>
                        <option value="amber">🟠 Amber / Gold</option>
                        <option value="rose">🔴 Korallenrot</option>
                        <option value="violet">🟣 Violett</option>
                        <option value="slate">⚫ Schiefergrau</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">📅 Projektstart</label>
                    <input type="date" name="start_date" id="projFormStart" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🏁 Projektende / Deadline</label>
                    <input type="date" name="end_date" id="projFormEnd" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">⏱️ Geplantes Zeitbudget (Std.)</label>
                    <input type="number" step="0.5" min="0" name="budget_hours" id="projFormBudgetHours" value="0" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">💶 Geplantes Kostenbudget (€)</label>
                    <input type="number" step="1" min="0" name="budget_amount" id="projFormBudgetAmount" value="0" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Projektziel / Beschreibung</label>
                <textarea name="description" id="projFormDesc" rows="2" placeholder="Ziele, Rahmenbedingungen, Ansprechpartner..." class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700"></textarea>
            </div>

            <!-- Verknüpfung mit Kanban-Board -->
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-3">
                <div class="font-bold text-slate-900 dark:text-white">🔗 Verknüpfung mit MiniCloud-Modulen</div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">📋 Kanban-Board verknüpfen</label>
                    <select name="kanban_board_id" id="projFormBoardId" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700">
                        <option value="0">— Kein bestehendes Board —</option>
                        <?php foreach ($myKanbanBoards as $kb): ?>
                            <option value="<?= (int)$kb['id'] ?>"><?= e((string)$kb['icon']) ?> <?= e((string)$kb['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="group_folder_id" id="projFormFolderId" value="0">
                </div>
                <div id="projAutoCreateBoardWrap">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" name="auto_create_board" id="projFormAutoBoard" value="1" checked class="rounded border-slate-300 text-brand-600">
                        <span><strong>Automatisch ein passendes Kanban-Board für dieses Projekt miterstellen</strong> (falls oben keines gewählt wurde)</span>
                    </label>
                </div>
            </div>

            <!-- Teilen mit Benutzer / Gruppe / Link -->
            <div class="p-3.5 rounded-xl bg-brand-50/60 dark:bg-brand-950/30 border border-brand-200 dark:border-brand-800 space-y-3">
                <div class="font-bold text-slate-900 dark:text-white">🤝 Ganzes Projekt teilen (Team &amp; Auftraggeber)</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Intern teilen mit</label>
                        <select name="share_target" id="projFormShareTarget" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                            <option value="">🔒 Nur für mich privat</option>
                            <?php if (!empty($shareableUsers)): ?>
                                <optgroup label="👤 Cloud-Benutzer">
                                    <?php foreach ($shareableUsers as $su): ?>
                                        <option value="user:<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($shareableGroups)): ?>
                                <optgroup label="👥 Benutzergruppen (Team)">
                                    <?php foreach ($shareableGroups as $sg): ?>
                                        <option value="group:<?= (int)$sg['id'] ?>">👥 <?= e((string)$sg['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🔗 Externer Projektstatus-Link</label>
                        <select name="share_mode" id="projFormShareMode" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700">
                            <option value="disabled">⛔ Kein externer Link</option>
                            <option value="read">👁️ Nur Lesen (Live-Status für Kunden)</option>
                            <option value="write">✏️ Mitarbeit erlaubt (Meilensteine &amp; Aufgaben)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeProjectModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shadow-xs cursor-pointer">💾 Projekt speichern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openProjectModal(proj = null) {
    const modal = document.getElementById('projectModal');
    const titleEl = document.getElementById('projectModalTitle');
    const autoWrap = document.getElementById('projAutoCreateBoardWrap');
    if (!modal) return;

    document.getElementById('projFormId').value = proj ? proj.id : 0;
    document.getElementById('projFormCode').value = proj ? proj.code : '';
    document.getElementById('projFormTitle').value = proj ? proj.title : '';
    document.getElementById('projFormDesc').value = proj ? proj.description : '';
    document.getElementById('projFormIcon').value = proj ? proj.icon : '🚀';
    document.getElementById('projFormColor').value = proj ? proj.color : 'brand';
    document.getElementById('projFormStatus').value = proj ? proj.status : 'active';
    document.getElementById('projFormStart').value = proj ? proj.start_date : '<?= date('Y-m-d') ?>';
    document.getElementById('projFormEnd').value = proj ? proj.end_date : '';
    document.getElementById('projFormBudgetHours').value = proj ? proj.budget_hours : 0;
    document.getElementById('projFormBudgetAmount').value = proj ? proj.budget_amount : 0;
    document.getElementById('projFormBoardId').value = proj ? proj.kanban_board_id : 0;
    document.getElementById('projFormFolderId').value = proj ? proj.group_folder_id : 0;
    document.getElementById('projFormShareTarget').value = proj ? proj.share_target : '';
    document.getElementById('projFormShareMode').value = proj ? proj.share_mode : 'disabled';

    if (autoWrap) {
        autoWrap.style.display = proj ? 'none' : 'block';
    }
    if (titleEl) {
        titleEl.textContent = proj ? '🤝 Projekt bearbeiten & teilen' : '🚀 Neues Projekt anlegen';
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('projFormTitle')?.focus(), 60);
}

function closeProjectModal() {
    const modal = document.getElementById('projectModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openMilestoneModal(ms = null) {
    const modal = document.getElementById('milestoneModal');
    const titleEl = document.getElementById('milestoneModalTitle');
    if (!modal) return;

    document.getElementById('msFormId').value = ms ? ms.id : 0;
    document.getElementById('msFormTitle').value = ms ? ms.title : '';
    document.getElementById('msFormDesc').value = ms ? ms.description : '';
    document.getElementById('msFormStart').value = ms ? ms.start_date : '';
    document.getElementById('msFormDue').value = ms ? ms.due_date : '';
    document.getElementById('msFormStatus').value = ms ? ms.status : 'open';
    document.getElementById('msFormAssigned').value = ms ? ms.assigned_user_id : 0;

    if (titleEl) {
        titleEl.textContent = ms ? '✏️ Meilenstein bearbeiten' : '🏁 Meilenstein anlegen';
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('msFormTitle')?.focus(), 60);
}

function closeMilestoneModal() {
    const modal = document.getElementById('milestoneModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function copyProjectLink(url, btnEl) {
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
        if (btnEl) {
            const orig = btnEl.textContent;
            btnEl.textContent = '✅ Link kopiert!';
            setTimeout(() => { btnEl.textContent = orig; }, 2000);
        }
    });
}

function filterProjectsList(query) {
    const q = String(query || '').trim().toLowerCase();
    document.querySelectorAll('[data-project-card]').forEach(card => {
        const txt = (card.textContent || '').toLowerCase();
        card.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
    });
}

function filterProjectStatus(status, btnEl) {
    document.querySelectorAll('[data-project-card]').forEach(card => {
        const st = card.getAttribute('data-project-status');
        card.style.display = (status === 'all' || st === status) ? '' : 'none';
    });
    document.querySelectorAll('[data-status-btn]').forEach(b => {
        b.className = 'px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:border-brand-400 cursor-pointer shrink-0';
    });
    if (btnEl) {
        btnEl.className = 'px-3.5 py-1.5 rounded-xl text-xs font-bold bg-slate-900 dark:bg-white text-white dark:text-slate-900 cursor-pointer shrink-0';
    }
}

// --- Live-Projekt-Stoppuhr (mit localStorage-Persistenz) ---
const swStorageKey = 'mc_proj_stopwatch_<?= (int)$requestedProjectId ?>';
let swInterval = null;

function getSwState() {
    try {
        const raw = localStorage.getItem(swStorageKey);
        if (raw) return JSON.parse(raw);
    } catch (e) {}
    return { running: false, startTs: 0, elapsedMs: 0 };
}

function saveSwState(st) {
    try {
        localStorage.setItem(swStorageKey, JSON.stringify(st));
    } catch (e) {}
}

function formatSwTime(ms) {
    const totalSec = Math.max(0, Math.floor(ms / 1000));
    const h = String(Math.floor(totalSec / 3600)).padStart(2, '0');
    const m = String(Math.floor((totalSec % 3600) / 60)).padStart(2, '0');
    const s = String(totalSec % 60).padStart(2, '0');
    return `${h}:${m}:${s}`;
}

function renderStopwatch() {
    const disp = document.getElementById('stopwatchDisplay');
    const btn = document.getElementById('swStartBtn');
    if (!disp) return;
    const st = getSwState();
    const totalMs = st.elapsedMs + (st.running ? (Date.now() - st.startTs) : 0);
    disp.textContent = formatSwTime(totalMs);
    if (btn) {
        btn.textContent = st.running ? '⏸️ Pause' : '▶️ Starten';
    }
}

function toggleStopwatch() {
    const st = getSwState();
    if (st.running) {
        st.elapsedMs += (Date.now() - st.startTs);
        st.running = false;
        st.startTs = 0;
    } else {
        st.running = true;
        st.startTs = Date.now();
    }
    saveSwState(st);
    renderStopwatch();
}

function resetStopwatch() {
    saveSwState({ running: false, startTs: 0, elapsedMs: 0 });
    renderStopwatch();
}

function applyStopwatchToForm() {
    const st = getSwState();
    const totalMs = st.elapsedMs + (st.running ? (Date.now() - st.startTs) : 0);
    const hours = Math.max(0.01, Math.round((totalMs / 3600000) * 100) / 100);
    const inp = document.getElementById('entryHoursInput');
    if (inp) {
        inp.value = hours.toFixed(2);
        inp.focus();
    }
}

if (document.getElementById('stopwatchDisplay')) {
    renderStopwatch();
    swInterval = setInterval(renderStopwatch, 1000);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
