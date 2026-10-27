<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_tasks'] ?? '1') !== '1') {
    setFlash('warning', 'Das Aufgaben- & Kanban-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

// Prüfen, ob ein externer Teilen-Link (board_token) aufgerufen wurde
$publicToken = trim((string)($_GET['board_token'] ?? $_POST['board_token'] ?? ''));
$isLoggedIn  = !empty($_SESSION['user_id']);
$publicBoard = null;

if ($publicToken !== '' && preg_match('/^[a-f0-9]{32,64}$/', $publicToken)) {
    $stmtPub = $pdo->prepare(
        "SELECT kb.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_user_name
         FROM kanban_boards kb
         INNER JOIN users u ON u.id = kb.user_id
         LEFT JOIN `groups` g ON g.id = kb.group_id
         LEFT JOIN users su ON su.id = kb.shared_user_id
         WHERE kb.share_token = :tok AND kb.share_mode IN ('read', 'write')
         LIMIT 1"
    );
    $stmtPub->execute([':tok' => $publicToken]);
    $publicBoard = $stmtPub->fetch() ?: null;
}

if (!$publicBoard) {
    requireLogin();
}

$userId   = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$username = $isLoggedIn ? (string)$_SESSION['username'] : 'Gast';
$isAdmin  = $isLoggedIn && isAdmin();

/**
 * Prüft, ob der aktuelle Benutzer auf ein Projekt-Board zugreifen darf.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleBoard(PDO $pdo, int $boardId, int $userId, bool $isAdmin, ?array $publicBoard = null): ?array
{
    if ($boardId <= 0) {
        return null;
    }
    if ($publicBoard && (int)$publicBoard['id'] === $boardId) {
        return $publicBoard;
    }
    if ($userId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT kb.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name
         FROM kanban_boards kb
         INNER JOIN users u ON u.id = kb.user_id
         LEFT JOIN users su ON su.id = kb.shared_user_id
         LEFT JOIN `groups` g ON g.id = kb.group_id
         WHERE kb.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $boardId]);
    $board = $stmt->fetch();
    if (!$board) {
        return null;
    }

    if ($isAdmin || (int)$board['user_id'] === $userId || (int)($board['shared_user_id'] ?? 0) === $userId) {
        return $board;
    }

    $gid = (int)($board['group_id'] ?? 0);
    if ($gid > 0) {
        $stmtGm = $pdo->prepare('SELECT id FROM group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmtGm->execute([':gid' => $gid, ':uid' => $userId]);
        if ($stmtGm->fetch()) {
            return $board;
        }
    }

    return null;
}

/**
 * Prüft, ob der aktuelle Benutzer auf eine Aufgabe zugreifen / sie bearbeiten darf.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleTask(PDO $pdo, int $taskId, int $userId, bool $isAdmin, ?array $publicBoard = null): ?array
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

    // Externer Board-Link mit Schreibrecht
    if ($publicBoard && (int)($task['board_id'] ?? 0) === (int)$publicBoard['id']) {
        if (($publicBoard['share_mode'] ?? 'read') === 'write') {
            return $task;
        }
    }

    if ($userId <= 0) {
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

    // Zugriff über das übergeordnete Projekt-Board
    $bid = (int)($task['board_id'] ?? 0);
    if ($bid > 0 && getAccessibleBoard($pdo, $bid, $userId, $isAdmin) !== null) {
        return $task;
    }

    return null;
}

/**
 * Hilfsfunktion zum Auflösen eines Freigabe-Ziels (user:ID oder group:ID).
 *
 * @return array{shared_user_id: int|null, group_id: int|null}
 */
function resolveShareTarget(PDO $pdo, string $shareTarget, int $userId, bool $isAdmin): array
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

$allowedIcons  = ['📋', '🚀', '💼', '🏠', '🎉', '🛠️', '💡', '📚', '🎯', '🛒'];
$allowedColors = ['brand', 'emerald', 'amber', 'rose', 'violet', 'slate'];

// --- POST-Aktionen ---
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

    $action      = (string)($_POST['action'] ?? '');
    $returnBoard = trim((string)($_POST['return_board'] ?? 'all'));
    $redirectUrl = 'tasks.php?board=' . rawurlencode($returnBoard);
    if ($publicBoard) {
        $redirectUrl = 'tasks.php?board_token=' . rawurlencode((string)$publicBoard['share_token']);
    }

    // 1. Projekt-Board neu anlegen oder bearbeiten / teilen
    if ($action === 'save_board' && $userId > 0) {
        $boardId     = (int)($_POST['board_id'] ?? 0);
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
        $icon        = trim((string)($_POST['icon'] ?? '📋'));
        $color       = trim((string)($_POST['color'] ?? 'brand'));
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));
        $shareMode   = trim((string)($_POST['share_mode'] ?? 'disabled'));
        $regenToken  = !empty($_POST['regenerate_token']);

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Namen für das Projekt-Board ein.');
            header('Location: ' . $redirectUrl);
            exit;
        }
        if (!in_array($icon, $allowedIcons, true)) {
            $icon = '📋';
        }
        if (!in_array($color, $allowedColors, true)) {
            $color = 'brand';
        }
        if (!in_array($shareMode, ['disabled', 'read', 'write'], true)) {
            $shareMode = 'disabled';
        }

        $resolved = resolveShareTarget($pdo, $shareTarget, $userId, $isAdmin);

        if ($boardId > 0) {
            $existingBoard = getAccessibleBoard($pdo, $boardId, $userId, $isAdmin);
            if (!$existingBoard || ((int)$existingBoard['user_id'] !== $userId && !$isAdmin)) {
                setFlash('error', 'Nur der Eigentümer kann die Board-Einstellungen und Freigaben ändern.');
                header('Location: ' . $redirectUrl);
                exit;
            }

            $token = $existingBoard['share_token'] ?? null;
            if ($shareMode !== 'disabled' && (empty($token) || $regenToken)) {
                $token = bin2hex(random_bytes(24));
            } elseif ($shareMode === 'disabled' && $regenToken) {
                $token = null;
            }

            $stmtUpd = $pdo->prepare(
                'UPDATE kanban_boards
                 SET title = :t, description = :d, icon = :ic, color = :co,
                     shared_user_id = :su, group_id = :gid, share_token = :tok, share_mode = :sm
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':t'   => $title,
                ':d'   => $description,
                ':ic'  => $icon,
                ':co'  => $color,
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':tok' => $token,
                ':sm'  => $shareMode,
                ':id'  => $boardId,
            ]);
            setFlash('success', sprintf('Projekt-Board „%s“ wurde gespeichert.', $title));
            header('Location: tasks.php?board=' . $boardId);
            exit;
        } else {
            $token = ($shareMode !== 'disabled') ? bin2hex(random_bytes(24)) : null;
            $stmtIns = $pdo->prepare(
                'INSERT INTO kanban_boards (user_id, title, description, icon, color, shared_user_id, group_id, share_token, share_mode)
                 VALUES (:uid, :t, :d, :ic, :co, :su, :gid, :tok, :sm)'
            );
            $stmtIns->execute([
                ':uid' => $userId,
                ':t'   => $title,
                ':d'   => $description,
                ':ic'  => $icon,
                ':co'  => $color,
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':tok' => $token,
                ':sm'  => $shareMode,
            ]);
            $newBoardId = (int)$pdo->lastInsertId();

            // Optional: Alle bisherigen Karten aus dem Standard-Board direkt in dieses neue Projekt-Board übernehmen
            if (!empty($_POST['adopt_default_tasks']) && $newBoardId > 0) {
                $pdo->prepare('UPDATE tasks SET board_id = :bid WHERE user_id = :uid AND board_id IS NULL')
                    ->execute([':bid' => $newBoardId, ':uid' => $userId]);
            }

            setFlash('success', sprintf('Projekt-Board „%s“ wurde angelegt.', $title));
            header('Location: tasks.php?board=' . $newBoardId);
            exit;
        }
    }

    // 2. Projekt-Board löschen
    if ($action === 'delete_board' && $userId > 0) {
        $boardId       = (int)($_POST['board_id'] ?? 0);
        $keepTasksMode = (string)($_POST['keep_tasks'] ?? 'move_default');
        $existingBoard = getAccessibleBoard($pdo, $boardId, $userId, $isAdmin);

        if ($existingBoard && ((int)$existingBoard['user_id'] === $userId || $isAdmin)) {
            if ($keepTasksMode === 'move_default') {
                // Aufgaben behalten und zurück ins Standard-Board verschieben
                $pdo->prepare('UPDATE tasks SET board_id = NULL WHERE board_id = :bid')
                    ->execute([':bid' => $boardId]);
            }
            $pdo->prepare('DELETE FROM kanban_boards WHERE id = :id')->execute([':id' => $boardId]);
            setFlash('success', sprintf('Projekt-Board „%s“ wurde gelöscht.', (string)$existingBoard['title']));
        } else {
            setFlash('error', 'Nur der Eigentümer kann dieses Projekt-Board löschen.');
        }
        header('Location: tasks.php?board=all');
        exit;
    }

    // 3. Alle Karten des Standard-Boards auf einmal teilen oder in ein Projekt-Board verschieben
    if ($action === 'bulk_share_default' && $userId > 0) {
        $targetBoardId = (int)($_POST['target_board_id'] ?? 0);
        $shareTarget   = trim((string)($_POST['share_target'] ?? ''));

        if ($targetBoardId > 0) {
            $accBoard = getAccessibleBoard($pdo, $targetBoardId, $userId, $isAdmin);
            if ($accBoard) {
                $stmtBulk = $pdo->prepare('UPDATE tasks SET board_id = :bid WHERE user_id = :uid AND board_id IS NULL');
                $stmtBulk->execute([':bid' => $targetBoardId, ':uid' => $userId]);
                setFlash('success', sprintf('Alle Karten aus dem Standard-Board wurden in „%s“ verschoben.', (string)$accBoard['title']));
                header('Location: tasks.php?board=' . $targetBoardId);
                exit;
            }
        } else {
            $resolved = resolveShareTarget($pdo, $shareTarget, $userId, $isAdmin);
            $stmtBulk = $pdo->prepare(
                'UPDATE tasks SET shared_user_id = :su, group_id = :gid WHERE user_id = :uid AND board_id IS NULL'
            );
            $stmtBulk->execute([
                ':su'  => $resolved['shared_user_id'],
                ':gid' => $resolved['group_id'],
                ':uid' => $userId,
            ]);
            setFlash('success', 'Die Freigabe für alle Aufgaben im Standard-Board wurde aktualisiert.');
            header('Location: tasks.php?board=default');
            exit;
        }
    }

    // 4. Status per Drag & Drop oder Schnell-Button ändern (AJAX oder Formular)
    if ($action === 'update_status') {
        $taskId    = (int)($_POST['task_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? 'todo');
        if (!in_array($newStatus, ['todo', 'in_progress', 'done'], true)) {
            $newStatus = 'todo';
        }

        $task = getAccessibleTask($pdo, $taskId, $userId, $isAdmin, $publicBoard);
        if (!$task) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Keine Berechtigung.']);
                exit;
            }
            setFlash('error', 'Aufgabe nicht gefunden oder keine Berechtigung.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->prepare('UPDATE tasks SET status = :st WHERE id = :id')
            ->execute([':st' => $newStatus, ':id' => $taskId]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'status' => $newStatus]);
            exit;
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 5. Einzelnen Checklisten-Punkt direkt auf der Karte abhaken (AJAX)
    if ($action === 'toggle_checklist_item') {
        header('Content-Type: application/json; charset=utf-8');
        $taskId  = (int)($_POST['task_id'] ?? 0);
        $itemIdx = (int)($_POST['item_index'] ?? -1);

        $task = getAccessibleTask($pdo, $taskId, $userId, $isAdmin, $publicBoard);
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

    // 6. Aufgabe neu anlegen oder aktualisieren
    if ($action === 'save_task' && $userId > 0) {
        $taskId      = (int)($_POST['task_id'] ?? 0);
        $boardIdRaw  = (int)($_POST['board_id'] ?? 0);
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 5000);
        $status      = (string)($_POST['status'] ?? 'todo');
        $priority    = (string)($_POST['priority'] ?? 'medium');
        $dueDateRaw  = trim((string)($_POST['due_date'] ?? ''));
        $attFileId   = (int)($_POST['attached_file_id'] ?? 0);
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Titel für die Aufgabe ein.');
            header('Location: ' . $redirectUrl);
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

        // Ziel-Board prüfen
        $validBoardId = null;
        if ($boardIdRaw > 0) {
            $targetBoard = getAccessibleBoard($pdo, $boardIdRaw, $userId, $isAdmin);
            if ($targetBoard) {
                $validBoardId = (int)$targetBoard['id'];
            }
        }

        // Checkliste aus mehrzeiligem Text übernehmen
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
        $resolved     = resolveShareTarget($pdo, $shareTarget, $userId, $isAdmin);
        $sharedUserId = $resolved['shared_user_id'];
        $groupId      = $resolved['group_id'];

        if ($taskId > 0) {
            $existing = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
            if (!$existing) {
                setFlash('error', 'Aufgabe nicht gefunden oder keine Berechtigung.');
                header('Location: ' . $redirectUrl);
                exit;
            }
            if ((int)$existing['user_id'] !== $userId && !$isAdmin) {
                $sharedUserId = $existing['shared_user_id'] !== null ? (int)$existing['shared_user_id'] : null;
                $groupId      = $existing['group_id'] !== null ? (int)$existing['group_id'] : null;
            }
            $stmtUpd = $pdo->prepare(
                'UPDATE tasks
                 SET board_id = :bid, title = :t, description = :d, status = :st, priority = :pr, due_date = :dd,
                     checklist_json = :cj, attached_file_id = :af, shared_user_id = :su, group_id = :gid
                 WHERE id = :id'
            );
            $stmtUpd->execute([
                ':bid' => $validBoardId,
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
                'INSERT INTO tasks (user_id, board_id, title, description, status, priority, due_date, checklist_json, attached_file_id, shared_user_id, group_id)
                 VALUES (:uid, :bid, :t, :d, :st, :pr, :dd, :cj, :af, :su, :gid)'
            );
            $stmtIns->execute([
                ':uid' => $userId,
                ':bid' => $validBoardId,
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

        if ($validBoardId !== null && $returnBoard !== 'all') {
            header('Location: tasks.php?board=' . $validBoardId);
        } else {
            header('Location: ' . $redirectUrl);
        }
        exit;
    }

    // 7. Aufgabe löschen
    if ($action === 'delete_task' && $userId > 0) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $task   = getAccessibleTask($pdo, $taskId, $userId, $isAdmin);
        $canDel = false;
        if ($task) {
            if ((int)$task['user_id'] === $userId || $isAdmin) {
                $canDel = true;
            } elseif (!empty($task['board_id'])) {
                $b = getAccessibleBoard($pdo, (int)$task['board_id'], $userId, $isAdmin);
                if ($b && (int)$b['user_id'] === $userId) {
                    $canDel = true;
                }
            }
        }
        if ($canDel) {
            $pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute([':id' => $taskId]);
            setFlash('success', 'Aufgabe wurde gelöscht.');
        } else {
            setFlash('error', 'Nur der Ersteller oder Board-Eigentümer kann diese Aufgabe löschen.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// --- Alle zugänglichen Projekt-Boards des Benutzers laden ---
$accessibleBoards = [];
$boardsById       = [];

if ($publicBoard) {
    $accessibleBoards = [$publicBoard];
    $boardsById[(int)$publicBoard['id']] = $publicBoard;
} elseif ($userId > 0) {
    $stmtBoards = $pdo->prepare(
        'SELECT kb.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name
         FROM kanban_boards kb
         INNER JOIN users u ON u.id = kb.user_id
         LEFT JOIN users su ON su.id = kb.shared_user_id
         LEFT JOIN `groups` g ON g.id = kb.group_id
         WHERE kb.user_id = :u1
            OR kb.shared_user_id = :u2
            OR (kb.group_id IS NOT NULL AND kb.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3))
         ORDER BY kb.title ASC, kb.id ASC'
    );
    $stmtBoards->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId]);
    $accessibleBoards = $stmtBoards->fetchAll();
    foreach ($accessibleBoards as $b) {
        $boardsById[(int)$b['id']] = $b;
    }
}

// Aktiven Board-Filter bestimmen ('all', 'default' oder numerische Board-ID)
$selectedBoardParam = trim((string)($_GET['board'] ?? 'all'));
$activeBoard        = null;

if ($publicBoard) {
    $selectedBoardParam = (string)$publicBoard['id'];
    $activeBoard        = $publicBoard;
} elseif (ctype_digit($selectedBoardParam) && (int)$selectedBoardParam > 0) {
    $reqBid = (int)$selectedBoardParam;
    if (isset($boardsById[$reqBid])) {
        $activeBoard = $boardsById[$reqBid];
    } else {
        $selectedBoardParam = 'all';
    }
} elseif (!in_array($selectedBoardParam, ['all', 'default'], true)) {
    $selectedBoardParam = 'all';
}

// --- Alle zugänglichen Aufgaben laden ---
if ($publicBoard) {
    $stmtTasks = $pdo->prepare(
        'SELECT t.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
                f.original_name AS attached_file_name,
                kb.title AS board_title, kb.icon AS board_icon, kb.color AS board_color
         FROM tasks t
         INNER JOIN users u ON u.id = t.user_id
         LEFT JOIN users su ON su.id = t.shared_user_id
         LEFT JOIN `groups` g ON g.id = t.group_id
         LEFT JOIN files f ON f.id = t.attached_file_id AND f.deleted_at IS NULL
         LEFT JOIN kanban_boards kb ON kb.id = t.board_id
         WHERE t.board_id = :bid
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
    $stmtTasks->execute([':bid' => (int)$publicBoard['id']]);
    $allAccessibleTasks = $stmtTasks->fetchAll();
} else {
    $stmtTasks = $pdo->prepare(
        'SELECT t.*, u.username AS owner_name, su.username AS shared_user_name, g.name AS group_name,
                f.original_name AS attached_file_name,
                kb.title AS board_title, kb.icon AS board_icon, kb.color AS board_color
         FROM tasks t
         INNER JOIN users u ON u.id = t.user_id
         LEFT JOIN users su ON su.id = t.shared_user_id
         LEFT JOIN `groups` g ON g.id = t.group_id
         LEFT JOIN files f ON f.id = t.attached_file_id AND f.deleted_at IS NULL
         LEFT JOIN kanban_boards kb ON kb.id = t.board_id
         WHERE t.user_id = :u1
            OR t.shared_user_id = :u2
            OR (t.group_id IS NOT NULL AND t.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u3))
            OR kb.user_id = :u4
            OR kb.shared_user_id = :u5
            OR (kb.group_id IS NOT NULL AND kb.group_id IN (SELECT group_id FROM group_members WHERE user_id = :u6))
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
    $stmtTasks->execute([
        ':u1' => $userId,
        ':u2' => $userId,
        ':u3' => $userId,
        ':u4' => $userId,
        ':u5' => $userId,
        ':u6' => $userId,
    ]);
    $allAccessibleTasks = $stmtTasks->fetchAll();
}

// Aufgaben-Zähler pro Board berechnen & nach ausgewähltem Board filtern
$boardTaskCounts = [
    'all'     => count($allAccessibleTasks),
    'default' => 0,
];
foreach ($boardsById as $bid => $_b) {
    $boardTaskCounts[$bid] = 0;
}

$filteredTasks = [];
foreach ($allAccessibleTasks as $t) {
    $tBid = !empty($t['board_id']) ? (int)$t['board_id'] : 0;
    if ($tBid === 0) {
        $boardTaskCounts['default']++;
    } elseif (isset($boardTaskCounts[$tBid])) {
        $boardTaskCounts[$tBid]++;
    }

    if ($selectedBoardParam === 'all') {
        $filteredTasks[] = $t;
    } elseif ($selectedBoardParam === 'default' && $tBid === 0) {
        $filteredTasks[] = $t;
    } elseif ($activeBoard && $tBid === (int)$activeBoard['id']) {
        $filteredTasks[] = $t;
    }
}

$tasksByStatus = [
    'todo'        => [],
    'in_progress' => [],
    'done'        => [],
];
foreach ($filteredTasks as $t) {
    $st = (string)$t['status'];
    if (!isset($tasksByStatus[$st])) {
        $st = 'todo';
    }
    $tasksByStatus[$st][] = $t;
}

// Eigene Dateien & Benutzer/Gruppen für Formulare laden (nur wenn eingeloggt)
$myFilesList     = [];
$shareableUsers  = [];
$shareableGroups = [];

if ($userId > 0) {
    $stmtMyFiles = $pdo->prepare(
        'SELECT id, original_name, folder_path FROM files WHERE user_id = :uid AND deleted_at IS NULL ORDER BY original_name ASC LIMIT 200'
    );
    $stmtMyFiles->execute([':uid' => $userId]);
    $myFilesList = $stmtMyFiles->fetchAll();

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
}

$priorityMeta = [
    'urgent' => ['label' => '🔥 Dringend', 'cls' => 'bg-red-100 dark:bg-red-950/80 text-red-800 dark:text-red-300 border-red-200 dark:border-red-800'],
    'high'   => ['label' => '⚡ Hoch',     'cls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border-amber-200 dark:border-amber-800'],
    'medium' => ['label' => '📌 Mittel',   'cls' => 'bg-blue-100 dark:bg-blue-950/80 text-blue-800 dark:text-blue-300 border-blue-200 dark:border-blue-800'],
    'low'    => ['label' => '🌱 Niedrig',  'cls' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'],
];

$boardColorMeta = [
    'brand'   => ['tabActive' => 'bg-brand-600 text-white border-brand-600 shadow-xs', 'badge' => 'bg-brand-50 dark:bg-brand-950/80 text-brand-700 dark:text-brand-300 border-brand-200 dark:border-brand-800', 'banner' => 'border-brand-500 bg-brand-50/50 dark:bg-brand-950/20'],
    'emerald' => ['tabActive' => 'bg-emerald-600 text-white border-emerald-600 shadow-xs', 'badge' => 'bg-emerald-50 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800', 'banner' => 'border-emerald-500 bg-emerald-50/50 dark:bg-emerald-950/20'],
    'amber'   => ['tabActive' => 'bg-amber-600 text-white border-amber-600 shadow-xs', 'badge' => 'bg-amber-50 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800', 'banner' => 'border-amber-500 bg-amber-50/50 dark:bg-amber-950/20'],
    'rose'    => ['tabActive' => 'bg-rose-600 text-white border-rose-600 shadow-xs', 'badge' => 'bg-rose-50 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800', 'banner' => 'border-rose-500 bg-rose-50/50 dark:bg-rose-950/20'],
    'violet'  => ['tabActive' => 'bg-violet-600 text-white border-violet-600 shadow-xs', 'badge' => 'bg-violet-50 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300 border-violet-200 dark:border-violet-800', 'banner' => 'border-violet-500 bg-violet-50/50 dark:bg-violet-950/20'],
    'slate'   => ['tabActive' => 'bg-slate-700 text-white border-slate-700 shadow-xs', 'badge' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-300 dark:border-slate-700', 'banner' => 'border-slate-500 bg-slate-50/50 dark:bg-slate-900'],
];

$columnsMeta = [
    'todo'        => ['title' => '📌 Offen',      'badgeCls' => 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300',   'headerBorder' => 'border-amber-400'],
    'in_progress' => ['title' => '⏳ In Arbeit',  'badgeCls' => 'bg-brand-100 dark:bg-brand-950 text-brand-800 dark:text-brand-300',   'headerBorder' => 'border-brand-500'],
    'done'        => ['title' => '✅ Erledigt',   'badgeCls' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300', 'headerBorder' => 'border-emerald-500'],
];

$canInteractiveEdit = ($userId > 0) || ($publicBoard && ($publicBoard['share_mode'] ?? 'read') === 'write');

$pageTitle = $activeBoard ? ((string)$activeBoard['title'] . ' – Kanban-Board') : 'Aufgaben & Projekt-Boards';
require_once __DIR__ . '/includes/header.php';
?>

<!-- KOPFBEREICH -->
<div class="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 text-lg">
                <?= e($activeBoard ? (string)$activeBoard['icon'] : '📋') ?>
            </span>
            <span>Aufgaben &amp; Projekt-Boards</span>
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
            Eigene Boards für verschiedene Projekte anlegen und komplette Kanban-Boards auf einmal mit Personen, Gruppen oder per Link teilen.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input
            type="search"
            id="taskSearchInput"
            oninput="filterTasks(this.value)"
            placeholder="🔍 Karten filtern..."
            class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
        <?php if ($userId > 0): ?>
            <button
                type="button"
                onclick="openBoardModal()"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
            >
                <span>🗂️ + Neues Projekt-Board</span>
            </button>
            <button
                type="button"
                onclick="openTaskModal(null, 'todo')"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
            >
                <span>+ Neue Aufgabe</span>
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- PROJEKT-BOARD REITER-LEISTE (BOARD SWITCHER) -->
<?php if ($userId > 0 && !$publicBoard): ?>
<div class="mb-4 flex items-center gap-2 overflow-x-auto pb-1.5">
    <a
        href="tasks.php?board=all"
        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold border transition-all shrink-0 <?= $selectedBoardParam === 'all' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 border-slate-900 dark:border-white shadow-xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>"
    >
        <span>🌐 Alle Aufgaben</span>
        <span class="px-1.5 py-0.5 rounded-full text-[11px] <?= $selectedBoardParam === 'all' ? 'bg-white/20 dark:bg-slate-900/20' : 'bg-slate-100 dark:bg-slate-800' ?>">
            <?= (int)$boardTaskCounts['all'] ?>
        </span>
    </a>

    <a
        href="tasks.php?board=default"
        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold border transition-all shrink-0 <?= $selectedBoardParam === 'default' ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900 border-slate-900 dark:border-white shadow-xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>"
    >
        <span>🏠 Standard-Board</span>
        <span class="px-1.5 py-0.5 rounded-full text-[11px] <?= $selectedBoardParam === 'default' ? 'bg-white/20 dark:bg-slate-900/20' : 'bg-slate-100 dark:bg-slate-800' ?>">
            <?= (int)$boardTaskCounts['default'] ?>
        </span>
    </a>

    <?php foreach ($accessibleBoards as $b):
        $bid       = (int)$b['id'];
        $bColor    = (string)($b['color'] ?? 'brand');
        $cMeta     = $boardColorMeta[$bColor] ?? $boardColorMeta['brand'];
        $isSelected = ($activeBoard && (int)$activeBoard['id'] === $bid);
        $isShared  = !empty($b['shared_user_id']) || !empty($b['group_id']) || (($b['share_mode'] ?? 'disabled') !== 'disabled');
    ?>
        <a
            href="tasks.php?board=<?= $bid ?>"
            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold border transition-all shrink-0 <?= $isSelected ? $cMeta['tabActive'] : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>"
        >
            <span><?= e((string)$b['icon']) ?></span>
            <span><?= e((string)$b['title']) ?></span>
            <?php if ($isShared): ?>
                <span title="Geteiltes Projekt-Board" class="text-[11px] opacity-90">🤝</span>
            <?php endif; ?>
            <span class="px-1.5 py-0.5 rounded-full text-[11px] <?= $isSelected ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' ?>">
                <?= (int)($boardTaskCounts[$bid] ?? 0) ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- AKTIVES BOARD-BANNER & GANZES-BOARD-TEILEN-LEISTE -->
<?php if ($activeBoard):
    $bId        = (int)$activeBoard['id'];
    $bColor     = (string)($activeBoard['color'] ?? 'brand');
    $cMeta      = $boardColorMeta[$bColor] ?? $boardColorMeta['brand'];
    $isBoardOwn = ($userId > 0 && ((int)$activeBoard['user_id'] === $userId || $isAdmin));
    $bShareVal  = '';
    if (!empty($activeBoard['shared_user_id'])) {
        $bShareVal = 'user:' . (int)$activeBoard['shared_user_id'];
    } elseif (!empty($activeBoard['group_id'])) {
        $bShareVal = 'group:' . (int)$activeBoard['group_id'];
    }
    $publicShareUrl = '';
    if (!empty($activeBoard['share_token']) && ($activeBoard['share_mode'] ?? 'disabled') !== 'disabled') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/tasks.php')), '/');
        $publicShareUrl = $scheme . '://' . $host . $dir . '/tasks.php?board_token=' . rawurlencode((string)$activeBoard['share_token']);
    }
    $boardJson = [
        'id'           => $bId,
        'title'        => (string)$activeBoard['title'],
        'description'  => (string)($activeBoard['description'] ?? ''),
        'icon'         => (string)($activeBoard['icon'] ?? '📋'),
        'color'        => $bColor,
        'share_target' => $bShareVal,
        'share_mode'   => (string)($activeBoard['share_mode'] ?? 'disabled'),
        'share_url'    => $publicShareUrl,
    ];
?>
<div class="mb-5 p-4 rounded-2xl border-l-4 <?= $cMeta['banner'] ?> border border-slate-200 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-3">
    <div class="space-y-1">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-lg"><?= e((string)$activeBoard['icon']) ?></span>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                <?= e((string)$activeBoard['title']) ?>
            </h2>
            <?php if ((int)$activeBoard['user_id'] !== $userId): ?>
                <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">
                    👤 Board von <?= e((string)$activeBoard['owner_name']) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($activeBoard['shared_user_name'])): ?>
                <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                    🤝 Geteilt mit <?= e((string)$activeBoard['shared_user_name']) ?>
                </span>
            <?php elseif (!empty($activeBoard['group_name'])): ?>
                <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300">
                    👥 Geteilt mit Gruppe „<?= e((string)$activeBoard['group_name']) ?>“
                </span>
            <?php else: ?>
                <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-slate-200/70 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                    🔒 Intern privat
                </span>
            <?php endif; ?>

            <?php if (($activeBoard['share_mode'] ?? 'disabled') !== 'disabled'): ?>
                <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                    🔗 Link aktiv (<?= ($activeBoard['share_mode'] ?? '') === 'write' ? 'Mitarbeit erlaubt' : 'Nur Lesen' ?>)
                </span>
            <?php endif; ?>
        </div>
        <?php if (trim((string)($activeBoard['description'] ?? '')) !== ''): ?>
            <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300">
                <?= e((string)$activeBoard['description']) ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="flex flex-wrap items-center gap-2 shrink-0">
        <?php if ($publicShareUrl !== ''): ?>
            <button
                type="button"
                onclick="copyBoardShareLink(<?= htmlspecialchars((string)json_encode($publicShareUrl), ENT_QUOTES, 'UTF-8') ?>, this)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-brand-700 dark:text-brand-300 border border-brand-300 dark:border-brand-700 text-xs font-semibold transition-colors cursor-pointer"
            >
                <span>🔗 Board-Link kopieren</span>
            </button>
        <?php endif; ?>

        <?php if ($isBoardOwn): ?>
            <button
                type="button"
                onclick='openBoardModal(<?= htmlspecialchars((string)json_encode($boardJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
            >
                <span>🤝 Ganzes Board teilen / Bearbeiten</span>
            </button>
            <button
                type="button"
                onclick="openDeleteBoardModal(<?= $bId ?>, <?= htmlspecialchars((string)json_encode((string)$activeBoard['title']), ENT_QUOTES, 'UTF-8') ?>)"
                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/50 text-slate-500 hover:text-red-600 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                title="Projekt-Board löschen"
            >
                <span>🗑️</span>
            </button>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($selectedBoardParam === 'default' && $userId > 0): ?>
<div class="mb-5 p-3.5 rounded-2xl bg-slate-100/80 dark:bg-slate-900/90 border border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div class="text-xs sm:text-sm text-slate-700 dark:text-slate-300">
        <strong>🏠 Persönliches Standard-Board:</strong> Hier liegen alle Aufgaben, die noch keinem speziellen Projekt-Board zugeordnet sind.
    </div>
    <div class="flex flex-wrap items-center gap-2 shrink-0">
        <button
            type="button"
            onclick="openBulkDefaultModal()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
        >
            <span>🤝 Alle Karten hier teilen / in Projekt verschieben</span>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- 3-SPALTEN KANBAN-BOARD -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
    <?php foreach ($columnsMeta as $colKey => $colInfo): ?>
        <?php $colTasks = $tasksByStatus[$colKey] ?? []; ?>
        <section
            data-kanban-col="<?= e($colKey) ?>"
            <?php if ($canInteractiveEdit): ?>
                ondragover="onKanbanDragOver(event, this)"
                ondragleave="onKanbanDragLeave(event, this)"
                ondrop="onKanbanDrop(event, <?= e((string)json_encode($colKey)) ?>, this)"
            <?php endif; ?>
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
                    <?php if ($userId > 0): ?>
                        <button
                            type="button"
                            onclick="openTaskModal(null, <?= e((string)json_encode($colKey)) ?>)"
                            class="w-7 h-7 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-600 dark:text-slate-300 font-bold text-sm transition-colors cursor-pointer"
                            title="Aufgabe in dieser Spalte anlegen"
                        >
                            +
                        </button>
                    <?php endif; ?>
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
                        $tBid      = !empty($task['board_id']) ? (int)$task['board_id'] : 0;
                        $tPrio     = (string)$task['priority'];
                        $pMeta     = $priorityMeta[$tPrio] ?? $priorityMeta['medium'];
                        $isOwner   = ($userId > 0) && (((int)$task['user_id'] === $userId) || $isAdmin || ($tBid > 0 && isset($boardsById[$tBid]) && (int)$boardsById[$tBid]['user_id'] === $userId));
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
                            'id'               => $tId,
                            'board_id'         => $tBid,
                            'title'            => (string)$task['title'],
                            'description'      => (string)($task['description'] ?? ''),
                            'status'           => $colKey,
                            'priority'         => $tPrio,
                            'due_date'         => $dueStr,
                            'checklist_lines'  => implode("\n", $checklistTextLines),
                            'attached_file_id' => (int)($task['attached_file_id'] ?? 0),
                            'share_target'     => $shareVal,
                        ];
                        $bBadgeMeta = $boardColorMeta[(string)($task['board_color'] ?? 'brand')] ?? $boardColorMeta['brand'];
                        ?>
                        <article
                            <?= $canInteractiveEdit ? 'draggable="true"' : '' ?>
                            data-task-card="<?= $tId ?>"
                            <?php if ($canInteractiveEdit): ?>
                                ondragstart="onTaskDragStart(event, <?= $tId ?>)"
                            <?php endif; ?>
                            class="p-3.5 rounded-xl bg-slate-50/90 dark:bg-slate-800/70 border border-slate-200/90 dark:border-slate-700/80 hover:border-brand-400 dark:hover:border-brand-500 shadow-2xs space-y-2.5 <?= $canInteractiveEdit ? 'cursor-grab active:cursor-grabbing' : '' ?> transition-all"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $pMeta['cls'] ?>">
                                        <?= e($pMeta['label']) ?>
                                    </span>
                                    <?php if ($selectedBoardParam === 'all' && $tBid > 0 && !empty($task['board_title'])): ?>
                                        <a
                                            href="tasks.php?board=<?= $tBid ?>"
                                            class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $bBadgeMeta['badge'] ?> hover:underline"
                                            title="Zu diesem Projekt-Board wechseln"
                                        >
                                            <?= e((string)($task['board_icon'] ?? '📋')) ?> <?= e((string)$task['board_title']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <?php if ($canInteractiveEdit): ?>
                                <div class="flex items-center gap-1 shrink-0">
                                    <?php if ($colKey !== 'todo'): ?>
                                        <form action="tasks.php" method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="return_board" value="<?= e($selectedBoardParam) ?>">
                                            <?php if ($publicBoard): ?>
                                                <input type="hidden" name="board_token" value="<?= e((string)$publicBoard['share_token']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <input type="hidden" name="status" value="<?= $colKey === 'done' ? 'in_progress' : 'todo' ?>">
                                            <button type="submit" class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-700 hover:bg-slate-200 text-[11px] font-bold text-slate-600 dark:text-slate-200 cursor-pointer" title="Spalte nach links">&larr;</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($colKey !== 'done'): ?>
                                        <form action="tasks.php" method="post" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="return_board" value="<?= e($selectedBoardParam) ?>">
                                            <?php if ($publicBoard): ?>
                                                <input type="hidden" name="board_token" value="<?= e((string)$publicBoard['share_token']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <input type="hidden" name="status" value="<?= $colKey === 'todo' ? 'in_progress' : 'done' ?>">
                                            <button type="submit" class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-700 hover:bg-slate-200 text-[11px] font-bold text-slate-600 dark:text-slate-200 cursor-pointer" title="Spalte nach rechts">&rarr;</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($userId > 0): ?>
                                        <button
                                            type="button"
                                            onclick='openTaskModal(<?= htmlspecialchars((string)json_encode($taskJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>)'
                                            class="p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-300 cursor-pointer"
                                            title="Aufgabe bearbeiten"
                                        >
                                            ✏️
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($isOwner): ?>
                                        <form action="tasks.php" method="post" class="inline" onsubmit="return confirm('Diese Aufgabe wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_task">
                                            <input type="hidden" name="return_board" value="<?= e($selectedBoardParam) ?>">
                                            <input type="hidden" name="task_id" value="<?= $tId ?>">
                                            <button type="submit" class="p-1 rounded hover:bg-red-100 dark:hover:bg-red-950/60 text-slate-400 hover:text-red-600 cursor-pointer" title="Aufgabe löschen">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
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
                                        <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 <?= $canInteractiveEdit ? 'cursor-pointer' : '' ?>">
                                            <input
                                                type="checkbox"
                                                <?= $ciDone ? 'checked' : '' ?>
                                                <?= $canInteractiveEdit ? '' : 'disabled' ?>
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
                                    <?php if (!empty($task['attached_file_id']) && !empty($task['attached_file_name']) && $userId > 0): ?>
                                        <a href="download.php?id=<?= (int)$task['attached_file_id'] ?>" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-brand-50 dark:bg-brand-950/70 text-brand-700 dark:text-brand-300 hover:underline font-medium max-w-[180px] truncate">
                                            📎 <?= e((string)$task['attached_file_name']) ?>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-1">
                                    <?php if ((int)$task['user_id'] !== $userId): ?>
                                        <span class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-semibold">
                                            👤 <?= e((string)$task['owner_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($task['shared_user_name'])): ?>
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

<?php if ($userId > 0): ?>
<!-- MODAL 1: PROJEKT-BOARD ERSTELLEN / TEILEN / BEARBEITEN -->
<div
    id="boardModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg max-h-[92vh] rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 id="boardModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                🗂️ Neues Projekt-Board anlegen &amp; teilen
            </h3>
            <button type="button" onclick="closeBoardModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer">✕</button>
        </div>

        <form action="tasks.php" method="post" class="p-5 overflow-y-auto space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_board">
            <input type="hidden" name="return_board" value="<?= e($selectedBoardParam) ?>">
            <input type="hidden" name="board_id" id="boardFormId" value="0">

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Projekt- / Board-Name *</label>
                <input
                    type="text"
                    name="title"
                    id="boardFormTitle"
                    required
                    maxlength="150"
                    placeholder="z. B. Website-Relaunch, Vereinsfest 2026, Einkauf & Haushalt"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Symbol / Icon</label>
                    <select name="icon" id="boardFormIcon" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="📋">📋 Klemmbrett</option>
                        <option value="🚀">🚀 Projekt / Rakete</option>
                        <option value="💼">💼 Arbeit / Büro</option>
                        <option value="🏠">🏠 Zuhause / Familie</option>
                        <option value="🎉">🎉 Event / Feier</option>
                        <option value="🛠️">🛠️ Technik / IT</option>
                        <option value="💡">💡 Ideen &amp; Planung</option>
                        <option value="📚">📚 Schule / Studium</option>
                        <option value="🎯">🎯 Ziele / Meilensteine</option>
                        <option value="🛒">🛒 Besorgungen</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Farbschema</label>
                    <select name="color" id="boardFormColor" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="brand">🔵 Ozeanblau</option>
                        <option value="emerald">🟢 Smaragdgrün</option>
                        <option value="amber">🟠 Sonnengelb / Amber</option>
                        <option value="rose">🔴 Korallenrot / Rose</option>
                        <option value="violet">🟣 Violett / Lila</option>
                        <option value="slate">⚫ Schiefergrau</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Kurzbeschreibung (optional)</label>
                <input
                    type="text"
                    name="description"
                    id="boardFormDescription"
                    maxlength="500"
                    placeholder="Worum geht es in diesem Projekt-Board?"
                    class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="p-3.5 rounded-xl bg-brand-50/60 dark:bg-brand-950/30 border border-brand-200 dark:border-brand-800/80 space-y-3">
                <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                    <span>🤝 Gesamtes Projekt-Board auf einmal teilen</span>
                </div>
                <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed">
                    Alle Aufgaben in diesem Board sind automatisch für den ausgewählten Benutzer oder die ausgewählte Gruppe sichtbar und gemeinsam per Drag &amp; Drop bearbeitbar.
                </p>

                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Intern teilen mit Cloud-Benutzer oder Gruppe</label>
                    <select name="share_target" id="boardFormShareTarget" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="">🔒 Nur für mich privat</option>
                        <?php if (!empty($shareableUsers)): ?>
                            <optgroup label="👤 Cloud-Benutzer">
                                <?php foreach ($shareableUsers as $su): ?>
                                    <option value="user:<?= (int)$su['id'] ?>">👤 <?= e((string)$su['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($shareableGroups)): ?>
                            <optgroup label="👥 Benutzergruppen (ganzes Team)">
                                <?php foreach ($shareableGroups as $sg): ?>
                                    <option value="group:<?= (int)$sg['id'] ?>">👥 <?= e((string)$sg['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🔗 Zusätzlicher externer Teilen-Link (ohne Login)</label>
                    <select name="share_mode" id="boardFormShareMode" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="disabled">⛔ Kein öffentlicher Link</option>
                        <option value="read">👁️ Öffentlicher Link: Nur Lesezugriff (Ansicht)</option>
                        <option value="write">✏️ Öffentlicher Link: Mitarbeit erlaubt (Status verschieben &amp; Checklisten abhaken)</option>
                    </select>
                </div>

                <div id="boardFormShareUrlWrap" class="hidden pt-1">
                    <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-1">Aktueller Teilen-Link:</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="boardFormShareUrlInput" readonly class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-700 dark:text-slate-200">
                        <button type="button" onclick="copyBoardShareLink(document.getElementById('boardFormShareUrlInput').value, this)" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold shrink-0 cursor-pointer">Kopieren</button>
                    </div>
                    <label class="inline-flex items-center gap-2 mt-2 text-[11px] text-slate-600 dark:text-slate-400 cursor-pointer">
                        <input type="checkbox" name="regenerate_token" value="1" class="rounded border-slate-300 text-brand-600">
                        <span>Neuen Link-Token generieren (bisheriger Link wird ungültig)</span>
                    </label>
                </div>
            </div>

            <div id="boardFormAdoptWrap" class="pt-1">
                <label class="inline-flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                    <input type="checkbox" name="adopt_default_tasks" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>Alle meine bestehenden Karten aus dem „🏠 Standard-Board“ direkt in dieses neue Projekt-Board übernehmen</span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeBoardModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold shadow-xs cursor-pointer">💾 Board speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: PROJEKT-BOARD LÖSCHEN -->
<div
    id="deleteBoardModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">🗑️ Projekt-Board löschen</h3>
            <button type="button" onclick="closeDeleteBoardModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>
        <form action="tasks.php" method="post" class="p-5 space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="delete_board">
            <input type="hidden" name="board_id" id="deleteBoardIdInput" value="0">

            <p class="text-slate-700 dark:text-slate-300">
                Möchten Sie das Projekt-Board <strong id="deleteBoardTitleLabel" class="text-slate-900 dark:text-white"></strong> wirklich löschen?
            </p>

            <div class="space-y-2">
                <label class="block font-semibold text-slate-700 dark:text-slate-300">Was soll mit den enthaltenen Aufgaben passieren?</label>
                <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer">
                    <input type="radio" name="keep_tasks" value="move_default" checked class="text-brand-600">
                    <span>🏠 Aufgaben behalten und ins <strong>Standard-Board</strong> verschieben</span>
                </label>
                <label class="flex items-center gap-2 p-2.5 rounded-xl bg-red-50/60 dark:bg-red-950/30 border border-red-200 dark:border-red-900/60 text-red-800 dark:text-red-300 cursor-pointer">
                    <input type="radio" name="keep_tasks" value="delete_all" class="text-red-600">
                    <span>🗑️ Board inklusive aller enthaltenen Aufgaben endgültig löschen</span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeDeleteBoardModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold cursor-pointer">Jetzt löschen</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: STANDARD-BOARD AUF EINMAL TEILEN / IN PROJEKT VERSCHIEBEN -->
<div
    id="bulkDefaultModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">🤝 Alle Standard-Board-Karten teilen / verschieben</h3>
            <button type="button" onclick="closeBulkDefaultModal()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
        </div>
        <form action="tasks.php" method="post" class="p-5 space-y-4 text-xs sm:text-sm">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="bulk_share_default">

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Option A: Alle Karten in ein Projekt-Board verschieben</label>
                <select name="target_board_id" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                    <option value="0">— Im Standard-Board belassen —</option>
                    <?php foreach ($accessibleBoards as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['icon']) ?> <?= e((string)$b['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Option B: Alle Karten im Standard-Board direkt freigeben für</label>
                <select name="share_target" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
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

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="closeBulkDefaultModal()" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold cursor-pointer">Anwenden</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: AUFGABE ERSTELLEN / BEARBEITEN -->
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
            <input type="hidden" name="return_board" value="<?= e($selectedBoardParam) ?>">
            <input type="hidden" name="task_id" id="taskFormId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="sm:col-span-2">
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
                <div>
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🗂️ Projekt-Board</label>
                    <select name="board_id" id="taskFormBoardId" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="0">🏠 Standard-Board</option>
                        <?php foreach ($accessibleBoards as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['icon']) ?> <?= e((string)$b['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <label class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">🤝 Einzel-Freigabe (optional)</label>
                    <select name="share_target" id="taskFormShareTarget" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        <option value="">🗂️ Wie Projekt-Board / Privat</option>
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
<?php endif; ?>

<script>
const csrfToken = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const defaultActiveBoardId = <?= $activeBoard ? (int)$activeBoard['id'] : 0 ?>;
const publicBoardToken = <?= json_encode($publicBoard ? (string)$publicBoard['share_token'] : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let draggedTaskId = 0;

function openBoardModal(boardData = null) {
    const modal = document.getElementById('boardModal');
    const titleEl = document.getElementById('boardModalTitle');
    const adoptWrap = document.getElementById('boardFormAdoptWrap');
    const urlWrap = document.getElementById('boardFormShareUrlWrap');
    const urlInput = document.getElementById('boardFormShareUrlInput');
    if (!modal) return;

    document.getElementById('boardFormId').value = boardData ? boardData.id : 0;
    document.getElementById('boardFormTitle').value = boardData ? boardData.title : '';
    document.getElementById('boardFormDescription').value = boardData ? boardData.description : '';
    document.getElementById('boardFormIcon').value = boardData ? boardData.icon : '📋';
    document.getElementById('boardFormColor').value = boardData ? boardData.color : 'brand';
    document.getElementById('boardFormShareTarget').value = boardData ? boardData.share_target : '';
    document.getElementById('boardFormShareMode').value = boardData ? boardData.share_mode : 'disabled';

    if (adoptWrap) {
        adoptWrap.style.display = boardData ? 'none' : 'block';
    }
    if (urlWrap && urlInput) {
        if (boardData && boardData.share_url) {
            urlInput.value = boardData.share_url;
            urlWrap.classList.remove('hidden');
        } else {
            urlInput.value = '';
            urlWrap.classList.add('hidden');
        }
    }
    if (titleEl) {
        titleEl.textContent = boardData
            ? '🤝 Projekt-Board teilen & bearbeiten'
            : '🗂️ Neues Projekt-Board anlegen & teilen';
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => document.getElementById('boardFormTitle')?.focus(), 60);
}

function closeBoardModal() {
    const modal = document.getElementById('boardModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openDeleteBoardModal(boardId, boardTitle) {
    const modal = document.getElementById('deleteBoardModal');
    if (!modal) return;
    document.getElementById('deleteBoardIdInput').value = boardId;
    document.getElementById('deleteBoardTitleLabel').textContent = '„' + boardTitle + '“';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDeleteBoardModal() {
    const modal = document.getElementById('deleteBoardModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openBulkDefaultModal() {
    const modal = document.getElementById('bulkDefaultModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeBulkDefaultModal() {
    const modal = document.getElementById('bulkDefaultModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function copyBoardShareLink(url, btnEl) {
    if (!url) return;
    navigator.clipboard.writeText(url).then(() => {
        if (btnEl) {
            const orig = btnEl.textContent;
            btnEl.textContent = '✅ Link kopiert!';
            setTimeout(() => { btnEl.textContent = orig; }, 2000);
        }
    });
}

function openTaskModal(taskData = null, defaultStatus = 'todo') {
    const modal = document.getElementById('taskModal');
    const titleEl = document.getElementById('taskModalTitle');
    if (!modal) return;

    document.getElementById('taskFormId').value = taskData ? taskData.id : 0;
    document.getElementById('taskFormBoardId').value = taskData ? (taskData.board_id || 0) : defaultActiveBoardId;
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
    if (publicBoardToken) {
        fd.append('board_token', publicBoardToken);
    }

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
    if (publicBoardToken) {
        fd.append('board_token', publicBoardToken);
    }

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
