<?php
/**
 * uWiiiMiniCloud – Notizwand / Pinboard (notes.php)
 * Digitale Haftnotizen mit Farbcodierung, Anpinnen, #Tags, Live-Suche,
 * Teilen mit Benutzern/Gruppen und 1-Klick-Export als Markdown-Datei in die Cloud.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo    = getDB();
$userId = currentUserId();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_notes'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Notizwand“ ist durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$visiblePeers = getVisibleUsersForUser($pdo, $userId);
$visiblePeerIds = array_map(static fn(array $u): int => (int)$u['id'], $visiblePeers);
$myGroups = getGroupsForUser($pdo, $userId);
$myGroupIds = array_map(static fn(array $g): int => (int)$g['id'], $myGroups);

$allowedColors = [
    'amber'   => ['label' => 'Gelb',    'bg' => 'rgba(245, 158, 11, 0.14)', 'border' => 'rgba(245, 158, 11, 0.45)', 'dot' => '#f59e0b'],
    'blue'    => ['label' => 'Blau',    'bg' => 'rgba(59, 130, 246, 0.14)', 'border' => 'rgba(59, 130, 246, 0.45)', 'dot' => '#3b82f6'],
    'emerald' => ['label' => 'Grün',    'bg' => 'rgba(16, 185, 129, 0.14)', 'border' => 'rgba(16, 185, 129, 0.45)', 'dot' => '#10b981'],
    'rose'    => ['label' => 'Rosa',    'bg' => 'rgba(244, 63, 94, 0.14)',  'border' => 'rgba(244, 63, 94, 0.45)',  'dot' => '#f43f5e'],
    'violet'  => ['label' => 'Violett', 'bg' => 'rgba(139, 92, 246, 0.14)', 'border' => 'rgba(139, 92, 246, 0.45)', 'dot' => '#8b5cf6'],
    'slate'   => ['label' => 'Neutral', 'bg' => 'rgba(100, 116, 139, 0.14)', 'border' => 'rgba(100, 116, 139, 0.40)', 'dot' => '#64748b'],
];

/**
 * Prüft, ob der aktuelle Benutzer Zugriff auf eine Notiz hat.
 */
function canAccessNote(array $note, int $userId, array $myGroupIds): bool
{
    if ((int)$note['user_id'] === $userId) {
        return true;
    }
    if (!empty($note['shared_user_id']) && (int)$note['shared_user_id'] === $userId) {
        return true;
    }
    if (!empty($note['group_id']) && in_array((int)$note['group_id'], $myGroupIds, true)) {
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: notes.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_note') {
        $noteId  = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
        $title   = trim((string)($_POST['title'] ?? ''));
        $content = mb_substr(trim((string)($_POST['content'] ?? '')), 0, 50000);
        $color   = (string)($_POST['color'] ?? 'amber');
        $tagsRaw = trim((string)($_POST['tags'] ?? ''));
        $pinned  = !empty($_POST['is_pinned']) ? 1 : 0;
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        if ($title === '' && $content === '') {
            setFlash('error', 'Bitte gib einen Titel oder Notiztext ein.');
            header('Location: notes.php');
            exit;
        }
        if (!isset($allowedColors[$color])) {
            $color = 'amber';
        }

        // Tags säubern (Komma/Leerzeichen getrennt, ohne führendes # speichern)
        $tagParts = preg_split('/[\s,]+/', $tagsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleanTags = [];
        foreach ($tagParts as $tp) {
            $tp = ltrim(trim($tp), '#');
            if ($tp !== '') {
                $cleanTags[] = mb_substr($tp, 0, 32);
            }
        }
        $tagsStr = implode(', ', array_unique($cleanTags));

        $sharedUserId = null;
        $groupId      = null;
        if (str_starts_with($shareTarget, 'user:')) {
            $candUser = (int)substr($shareTarget, 5);
            if (in_array($candUser, $visiblePeerIds, true)) {
                $sharedUserId = $candUser;
            }
        } elseif (str_starts_with($shareTarget, 'group:')) {
            $candGrp = (int)substr($shareTarget, 6);
            if (in_array($candGrp, $myGroupIds, true)) {
                $groupId = $candGrp;
            }
        }

        if ($noteId > 0) {
            $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
            $stmtChk->execute([':id' => $noteId]);
            $existing = $stmtChk->fetch();
            if (!$existing || !canAccessNote($existing, $userId, $myGroupIds)) {
                setFlash('error', 'Notiz nicht gefunden oder keine Berechtigung.');
                header('Location: notes.php');
                exit;
            }

            if ((int)$existing['user_id'] !== $userId) {
                $sharedUserId = $existing['shared_user_id'] ? (int)$existing['shared_user_id'] : null;
                $groupId      = $existing['group_id'] ? (int)$existing['group_id'] : null;
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE `notes`
                 SET `title` = :t, `content` = :c, `color` = :col, `tags` = :tags,
                     `is_pinned` = :pin, `shared_user_id` = :su, `group_id` = :gid
                 WHERE `id` = :id"
            );
            $stmtUpd->execute([
                ':t'    => mb_substr($title, 0, 190),
                ':c'    => $content,
                ':col'  => $color,
                ':tags' => mb_substr($tagsStr, 0, 255),
                ':pin'  => $pinned,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
                ':id'   => $noteId,
            ]);
            setFlash('success', 'Notiz aktualisiert.');
        } else {
            $stmtIns = $pdo->prepare(
                "INSERT INTO `notes` (`user_id`, `title`, `content`, `color`, `tags`, `is_pinned`, `shared_user_id`, `group_id`)
                 VALUES (:uid, :t, :c, :col, :tags, :pin, :su, :gid)"
            );
            $stmtIns->execute([
                ':uid'  => $userId,
                ':t'    => mb_substr($title, 0, 190),
                ':c'    => $content,
                ':col'  => $color,
                ':tags' => mb_substr($tagsStr, 0, 255),
                ':pin'  => $pinned,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
            ]);
            setFlash('success', 'Neue Haftnotiz angeheftet.');
        }

        header('Location: notes.php');
        exit;
    }

    if ($action === 'toggle_pin') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if ($note && canAccessNote($note, $userId, $myGroupIds)) {
            $newPin = (int)$note['is_pinned'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE `notes` SET `is_pinned` = :p WHERE `id` = :id")
                ->execute([':p' => $newPin, ':id' => $noteId]);
        }
        header('Location: notes.php');
        exit;
    }

    if ($action === 'delete_note') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if ($note && (int)$note['user_id'] === $userId) {
            $pdo->prepare("DELETE FROM `notes` WHERE `id` = :id")->execute([':id' => $noteId]);
            setFlash('success', 'Notiz gelöscht.');
        } else {
            setFlash('error', 'Nur der Ersteller kann diese Notiz löschen.');
        }
        header('Location: notes.php');
        exit;
    }

    if ($action === 'export_to_cloud') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `notes` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $noteId]);
        $note = $stmtChk->fetch();

        if (!$note || !canAccessNote($note, $userId, $myGroupIds)) {
            setFlash('error', 'Notiz nicht gefunden.');
            header('Location: notes.php');
            exit;
        }

        $noteTitle = trim((string)$note['title']) !== '' ? trim((string)$note['title']) : 'Notiz_' . (int)$note['id'];
        $mdContent = '# ' . $noteTitle . "\n\n";
        if (trim((string)$note['tags']) !== '') {
            $mdContent .= '**Tags:** ' . trim((string)$note['tags']) . "\n\n";
        }
        $mdContent .= (string)$note['content'] . "\n";
        $bytes = strlen($mdContent);

        $stats = getUserStorageStats($pdo, $userId);
        if (($stats['used'] + $bytes) > $stats['quota']) {
            setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für den Export.');
            header('Location: notes.php');
            exit;
        }

        ensureUploadDirReady(UPLOAD_DIR);

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $noteTitle);
        $safeBase = trim((string)$safeBase, '_');
        if ($safeBase === '') {
            $safeBase = 'Notiz';
        }
        $originalName = sanitizeFileName(mb_substr($safeBase, 0, 80) . '.md');
        $storedName   = generateStoredFileName('md');
        $destPath     = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedName;

        if (file_put_contents($destPath, $mdContent) !== false) {
            @chmod($destPath, 0640);
            $folderPath = 'Notizen';
            registerFolderHierarchy($pdo, $userId, $folderPath);

            $stmtFile = $pdo->prepare(
                "INSERT INTO `files` (`user_id`, `folder_path`, `original_name`, `filename`, `mime_type`, `file_size`, `is_encrypted`)
                 VALUES (:uid, :fp, :orig, :stored, 'text/markdown', :size, 0)"
            );
            $stmtFile->execute([
                ':uid'    => $userId,
                ':fp'     => $folderPath,
                ':orig'   => $originalName,
                ':stored' => $storedName,
                ':size'   => $bytes,
            ]);
            setFlash('success', 'Notiz als „' . $originalName . '“ im Cloud-Ordner „Notizen“ gespeichert!');
        } else {
            setFlash('error', 'Fehler beim Schreiben der Markdown-Datei.');
        }

        header('Location: notes.php');
        exit;
    }
}

// Notizen abrufen (eigene + geteilte)
$sql = "SELECT n.*, u.`username` AS `owner_name`, su.`username` AS `shared_username`, g.`name` AS `group_name`
        FROM `notes` n
        INNER JOIN `users` u ON u.`id` = n.`user_id`
        LEFT JOIN `users` su ON su.`id` = n.`shared_user_id`
        LEFT JOIN `groups` g ON g.`id` = n.`group_id`
        WHERE n.`user_id` = :uid OR n.`shared_user_id` = :suid";

$params = [
    ':uid'  => $userId,
    ':suid' => $userId,
];

if (!empty($myGroupIds)) {
    $placeholders = [];
    foreach ($myGroupIds as $idx => $gid) {
        $ph = ':gid_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $gid;
    }
    $sql .= " OR n.`group_id` IN (" . implode(',', $placeholders) . ")";
}

$sql .= " ORDER BY n.`is_pinned` DESC, n.`updated_at` DESC";

$stmtNotes = $pdo->prepare($sql);
$stmtNotes->execute($params);
$allNotes = $stmtNotes->fetchAll();

// Alle vorkommenden Tags sammeln
$allTags = [];
foreach ($allNotes as $n) {
    if (trim((string)$n['tags']) !== '') {
        foreach (explode(',', (string)$n['tags']) as $t) {
            $t = trim($t);
            if ($t !== '') {
                $allTags[$t] = ($allTags[$t] ?? 0) + 1;
            }
        }
    }
}
ksort($allTags);

$pageTitle = 'Notizwand';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.1rem;
    align-items: start;
}
.note-card {
    border-radius: var(--radius);
    padding: 1.15rem;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    position: relative;
}
.note-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}
.note-content {
    font-size: 0.9rem;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
    color: var(--text);
    max-height: 260px;
    overflow-y: auto;
}
.color-picker-row {
    display: flex;
    gap: 0.55rem;
    align-items: center;
    flex-wrap: wrap;
}
.color-swatch-label {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.6rem;
    border-radius: 999px;
    border: 1px solid var(--border);
    cursor: pointer;
    font-size: 0.8rem;
}
</style>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">
    <div>
        <h1 class="page-title" style="margin:0;">📌 Notizwand &amp; Pinboard</h1>
        <p class="page-subtitle" style="margin:0.25rem 0 0 0;">Schnelle Ideen, Check-Notizen &amp; geteilte Pinnwand-Zettel – auf Wunsch mit 1 Klick als Markdown-Datei in deine Cloud exportierbar</p>
    </div>
    <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
        <input type="search" id="noteSearchInput" class="form-control" placeholder="🔍 Notizen oder #Tags durchsuchen..." style="max-width:260px;" oninput="filterNotesBoard()">
        <button type="button" class="btn btn-primary" onclick="openNoteModal()">➕ Neue Notiz</button>
    </div>
</div>

<?php if (!empty($allTags)): ?>
<div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem;">
    <span class="text-muted" style="font-size:0.8rem; font-weight:600;">🏷️ Tags:</span>
    <button type="button" class="btn btn-xs btn-outline note-tag-btn active" data-tag="" onclick="filterByTag('')">Alle (<?= count($allNotes) ?>)</button>
    <?php foreach ($allTags as $tagName => $cnt): ?>
        <button type="button" class="btn btn-xs btn-outline note-tag-btn" data-tag="<?= e(mb_strtolower($tagName)) ?>" onclick="filterByTag('<?= e( addslashes(mb_strtolower($tagName)) ) ?>')">
            #<?= e($tagName) ?> <span class="text-muted">(<?= $cnt ?>)</span>
        </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($allNotes)): ?>
    <div class="card" style="text-align:center; padding:3rem 1.5rem;">
        <div style="font-size:3rem; margin-bottom:0.6rem;">📌</div>
        <h3 style="margin:0 0 0.4rem 0;">Deine Notizwand ist noch leer</h3>
        <p class="text-muted" style="margin:0 0 1.2rem 0;">Hefte deine erste Idee, Einkaufsliste oder Team-Notiz an die Pinnwand.</p>
        <button type="button" class="btn btn-primary" onclick="openNoteModal()">➕ Erste Notiz erstellen</button>
    </div>
<?php else: ?>
    <div class="notes-grid" id="notesGrid">
        <?php foreach ($allNotes as $note):
            $nid = (int)$note['id'];
            $colKey = isset($allowedColors[$note['color']]) ? $note['color'] : 'amber';
            $colDef = $allowedColors[$colKey];
            $isPinned = (int)$note['is_pinned'] === 1;
            $isOwner  = (int)$note['user_id'] === $userId;
            $noteTags = array_filter(array_map('trim', explode(',', (string)$note['tags'])));
            $searchBlob = mb_strtolower(($note['title'] ?? '') . ' ' . ($note['content'] ?? '') . ' ' . ($note['tags'] ?? ''));
            $tagsLower  = implode('|', array_map('mb_strtolower', $noteTags));
            $noteJson   = htmlspecialchars(json_encode([
                'id'            => $nid,
                'title'         => (string)$note['title'],
                'content'       => (string)$note['content'],
                'color'         => $colKey,
                'tags'          => (string)$note['tags'],
                'is_pinned'     => $isPinned ? 1 : 0,
                'is_owner'      => $isOwner,
                'share_target'  => !empty($note['shared_user_id'])
                    ? 'user:' . (int)$note['shared_user_id']
                    : (!empty($note['group_id']) ? 'group:' . (int)$note['group_id'] : ''),
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
            <div class="note-card"
                 data-search="<?= e($searchBlob) ?>"
                 data-tags="<?= e($tagsLower) ?>"
                 style="background: <?= $colDef['bg'] ?>; border-color: <?= $colDef['border'] ?>;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem;">
                    <div style="font-weight:700; font-size:1rem; line-height:1.35; word-break:break-word;">
                        <?php if ($isPinned): ?>
                            <span title="Angepinnt">📌</span>
                        <?php endif; ?>
                        <?= $note['title'] !== '' ? e($note['title']) : '<span class="text-muted">Ohne Titel</span>' ?>
                    </div>
                    <form method="post" action="notes.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_pin">
                        <input type="hidden" name="note_id" value="<?= $nid ?>">
                        <button type="submit" class="btn btn-xs btn-outline" title="<?= $isPinned ? 'Lösen' : 'Oben anpinnen' ?>" style="padding:0.15rem 0.4rem;">
                            <?= $isPinned ? '📍' : '📌' ?>
                        </button>
                    </form>
                </div>

                <?php if (trim((string)$note['content']) !== ''): ?>
                    <div class="note-content"><?= e((string)$note['content']) ?></div>
                <?php endif; ?>

                <?php if (!empty($noteTags)): ?>
                    <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                        <?php foreach ($noteTags as $tg): ?>
                            <span class="badge" style="background:var(--bg-card); border:1px solid var(--border); font-size:0.72rem; cursor:pointer;"
                                  onclick="filterByTag('<?= e(addslashes(mb_strtolower($tg))) ?>')">#<?= e($tg) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem; margin-top:0.35rem; padding-top:0.55rem; border-top:1px dashed <?= $colDef['border'] ?>; font-size:0.75rem;">
                    <div class="text-muted">
                        🕓 <?= e(formatDate((string)$note['updated_at'])) ?>
                        <?php if (!$isOwner): ?>
                            · 👤 <?= e((string)$note['owner_name']) ?>
                        <?php elseif (!empty($note['shared_username'])): ?>
                            · 🤝 <?= e((string)$note['shared_username']) ?>
                        <?php elseif (!empty($note['group_name'])): ?>
                            · 👥 <?= e((string)$note['group_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:0.3rem; align-items:center;">
                        <form method="post" action="notes.php" style="margin:0;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="export_to_cloud">
                            <input type="hidden" name="note_id" value="<?= $nid ?>">
                            <button type="submit" class="btn btn-xs btn-outline" title="Als Markdown-Datei (.md) im Cloud-Ordner 'Notizen' speichern">☁️ .md</button>
                        </form>
                        <button type="button" class="btn btn-xs btn-outline" onclick='openNoteModal(<?= $noteJson ?>)' title="Bearbeiten">✏️</button>
                        <?php if ($isOwner): ?>
                            <form method="post" action="notes.php" style="margin:0;" onsubmit="return confirm('Notiz wirklich löschen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_note">
                                <input type="hidden" name="note_id" value="<?= $nid ?>">
                                <button type="submit" class="btn btn-xs btn-danger" title="Löschen">🗑️</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal: Notiz erstellen / bearbeiten -->
<div class="modal-overlay" id="noteModal" style="display:none;" onclick="if(event.target===this) closeNoteModal();">
    <div class="modal-box" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="noteModalTitle" style="margin:0;">📌 Neue Haftnotiz</h3>
            <button type="button" class="modal-close" onclick="closeNoteModal()">✕</button>
        </div>
        <form method="post" action="notes.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="note_id" id="noteFormId" value="0">

            <div class="form-group">
                <label class="form-label" for="noteFormTitle">Titel</label>
                <input type="text" name="title" id="noteFormTitle" class="form-control" maxlength="190" placeholder="Kurze Überschrift...">
            </div>

            <div class="form-group">
                <label class="form-label" for="noteFormContent">Notiz-Inhalt *</label>
                <textarea name="content" id="noteFormContent" class="form-control" rows="6" placeholder="Schreibe deine Gedanken, Links oder Stichpunkte auf..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Zettel-Farbe</label>
                <div class="color-picker-row">
                    <?php foreach ($allowedColors as $cKey => $cDef): ?>
                        <label class="color-swatch-label" style="background: <?= $cDef['bg'] ?>; border-color: <?= $cDef['border'] ?>;">
                            <input type="radio" name="color" value="<?= e($cKey) ?>" id="noteColor_<?= e($cKey) ?>" <?= $cKey === 'amber' ? 'checked' : '' ?>>
                            <span style="width:10px; height:10px; border-radius:50%; background:<?= $cDef['dot'] ?>; display:inline-block;"></span>
                            <?= e($cDef['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="noteFormTags">🏷️ Schlagworte (Tags, kommagetrennt)</label>
                    <input type="text" name="tags" id="noteFormTags" class="form-control" placeholder="z. B. projekt, ideen, privat">
                </div>
                <div class="form-group" id="noteShareWrapper">
                    <label class="form-label" for="noteFormShare">🤝 Teilen mit (optional)</label>
                    <select name="share_target" id="noteFormShare" class="form-control">
                        <option value="">🔒 Nur für mich privat</option>
                        <?php if (!empty($visiblePeers)): ?>
                            <optgroup label="👤 Benutzer">
                                <?php foreach ($visiblePeers as $peer): ?>
                                    <option value="user:<?= (int)$peer['id'] ?>">👤 <?= e($peer['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($myGroups)): ?>
                            <optgroup label="👥 Meine Gruppen">
                                <?php foreach ($myGroups as $grp): ?>
                                    <option value="group:<?= (int)$grp['id'] ?>">👥 <?= e($grp['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display:inline-flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" name="is_pinned" id="noteFormPinned" value="1">
                    📌 Oben an der Notizwand anpinnen
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeNoteModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">💾 Notiz speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
let activeNoteTag = '';

function filterNotesBoard() {
    const q = (document.getElementById('noteSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.note-card').forEach(card => {
        const searchStr = card.getAttribute('data-search') || '';
        const tagsStr = (card.getAttribute('data-tags') || '').split('|').filter(Boolean);
        const matchesQuery = (q === '' || searchStr.indexOf(q) !== -1);
        const matchesTag = (activeNoteTag === '' || tagsStr.includes(activeNoteTag));
        card.style.display = (matchesQuery && matchesTag) ? '' : 'none';
    });
}

function filterByTag(tag) {
    activeNoteTag = tag;
    document.querySelectorAll('.note-tag-btn').forEach(btn => {
        btn.classList.toggle('active', (btn.getAttribute('data-tag') || '') === tag);
    });
    filterNotesBoard();
}

function openNoteModal(note = null) {
    const modal = document.getElementById('noteModal');
    document.getElementById('noteModalTitle').textContent = note ? '✏️ Notiz bearbeiten' : '📌 Neue Haftnotiz';
    document.getElementById('noteFormId').value = note ? note.id : 0;
    document.getElementById('noteFormTitle').value = note ? note.title : '';
    document.getElementById('noteFormContent').value = note ? note.content : '';
    document.getElementById('noteFormTags').value = note ? note.tags : '';
    document.getElementById('noteFormPinned').checked = note ? (Number(note.is_pinned) === 1) : false;
    document.getElementById('noteFormShare').value = note ? (note.share_target || '') : '';
    document.getElementById('noteShareWrapper').style.display = (!note || note.is_owner) ? '' : 'none';

    const col = (note && note.color) ? note.color : 'amber';
    const radio = document.getElementById('noteColor_' + col);
    if (radio) radio.checked = true;

    modal.style.display = 'flex';
}

function closeNoteModal() {
    document.getElementById('noteModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
