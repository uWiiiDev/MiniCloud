<?php
/**
 * uWiiiMiniCloud – Passwort-Manager & Schlüsselbund (passwords.php)
 * AES-256-GCM verschlüsselter Passwort-Tresor für Webseiten-Logins, WLAN-Daten,
 * Server-Zugänge, Software-Lizenzen und PINs – inkl. Passwort-Generator & Gruppen-Sharing.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_passwords'] ?? '1') !== '1') {
    setFlash('warning', 'Das Modul „Passwort-Manager“ ist durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId   = currentUserId();
$username = (string)($_SESSION['username'] ?? '');

$categories = [
    'Webseite'           => '🌐 Webseite / Online-Konto',
    'Server / IT'        => '🖥️ Server / IT / SSH',
    'WLAN'               => '📶 WLAN / Netzwerk',
    'Banking / Finanzen' => '💳 Banking / Finanzen',
    'Lizenz / PIN'       => '🔑 Lizenzschlüssel / PIN',
    'Allgemein'          => '📁 Allgemein',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: passwords.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_password') {
        $entryId      = (int)($_POST['entry_id'] ?? 0);
        $title        = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 190);
        $category     = trim((string)($_POST['category'] ?? 'Allgemein'));
        if (!array_key_exists($category, $categories)) {
            $category = 'Allgemein';
        }
        $loginUser    = mb_substr(trim((string)($_POST['username_login'] ?? '')), 0, 190);
        $plainPass    = (string)($_POST['password_plain'] ?? '');
        $websiteUrl   = mb_substr(trim((string)($_POST['website_url'] ?? '')), 0, 255);
        $plainNotes   = trim((string)($_POST['notes_plain'] ?? ''));
        $isFavorite   = isset($_POST['is_favorite']) && (string)$_POST['is_favorite'] === '1' ? 1 : 0;
        $shareTarget  = trim((string)($_POST['share_target'] ?? ''));

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

        if ($title === '' || $plainPass === '') {
            setFlash('error', 'Bitte gib eine Bezeichnung und ein Passwort / einen Schlüssel ein.');
            header('Location: passwords.php');
            exit;
        }

        $encPass  = encryptPasswordEntry($plainPass);
        $encNotes = $plainNotes !== '' ? encryptPasswordEntry($plainNotes) : '';

        if ($entryId > 0) {
            $stmtChk = $pdo->prepare('SELECT * FROM passwords WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtChk->execute([':id' => $entryId, ':uid' => $userId]);
            $existing = $stmtChk->fetch();
            if (!$existing) {
                setFlash('error', 'Eintrag nicht gefunden oder keine Schreibberechtigung.');
                header('Location: passwords.php');
                exit;
            }

            $oldPlain = decryptPasswordEntry((string)$existing['password_encrypted']);
            $pwChangedSql = ($oldPlain !== $plainPass) ? ', password_changed_at = NOW()' : '';

            $stmtUpd = $pdo->prepare(
                "UPDATE passwords
                 SET title = :title,
                     category = :cat,
                     username_login = :ulogin,
                     password_encrypted = :pw,
                     website_url = :url,
                     notes_encrypted = :notes,
                     is_favorite = :fav,
                     shared_user_id = :suid,
                     group_id = :gid
                     {$pwChangedSql}
                 WHERE id = :id AND user_id = :uid"
            );
            $stmtUpd->execute([
                ':title'  => $title,
                ':cat'    => $category,
                ':ulogin' => $loginUser,
                ':pw'     => $encPass,
                ':url'    => $websiteUrl,
                ':notes'  => $encNotes,
                ':fav'    => $isFavorite,
                ':suid'   => $sharedUserId,
                ':gid'    => $groupId,
                ':id'     => $entryId,
                ':uid'    => $userId,
            ]);
            setFlash('success', 'Eintrag „' . $title . '“ wurde aktualisiert.');
        } else {
            $stmtIns = $pdo->prepare(
                'INSERT INTO passwords
                 (user_id, title, category, username_login, password_encrypted, website_url, notes_encrypted, is_favorite, shared_user_id, group_id)
                 VALUES (:uid, :title, :cat, :ulogin, :pw, :url, :notes, :fav, :suid, :gid)'
            );
            $stmtIns->execute([
                ':uid'    => $userId,
                ':title'  => $title,
                ':cat'    => $category,
                ':ulogin' => $loginUser,
                ':pw'     => $encPass,
                ':url'    => $websiteUrl,
                ':notes'  => $encNotes,
                ':fav'    => $isFavorite,
                ':suid'   => $sharedUserId,
                ':gid'    => $groupId,
            ]);
            logAuditEvent($pdo, 'password_entry_created', 'Schlüsselbund-Eintrag angelegt: ' . $title, $userId, $username);
            setFlash('success', 'Eintrag „' . $title . '“ wurde verschlüsselt im Schlüsselbund gespeichert.');
        }

        header('Location: passwords.php');
        exit;
    }

    if ($action === 'toggle_favorite') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pdo->prepare('UPDATE passwords SET is_favorite = IF(is_favorite = 1, 0, 1) WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $entryId, ':uid' => $userId]);
        header('Location: passwords.php');
        exit;
    }

    if ($action === 'delete_password') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pdo->prepare('DELETE FROM passwords WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $entryId, ':uid' => $userId]);
        setFlash('success', 'Eintrag wurde aus dem Schlüsselbund gelöscht.');
        header('Location: passwords.php');
        exit;
    }
}

$userGroups   = getGroupsForUser($pdo, $userId);
$visibleUsers = getVisibleUsersForUser($pdo, $userId);
$groupIds     = array_map(static fn($g) => (int)$g['id'], $userGroups);

$sql = 'SELECT pw.*, u.username AS owner_name, g.name AS group_name, su.username AS shared_username
        FROM passwords pw
        INNER JOIN users u ON u.id = pw.user_id
        LEFT JOIN `groups` g ON g.id = pw.group_id
        LEFT JOIN users su ON su.id = pw.shared_user_id
        WHERE pw.user_id = :uid1 OR pw.shared_user_id = :uid2';
$params = [':uid1' => $userId, ':uid2' => $userId];

if (!empty($groupIds)) {
    $phs = [];
    foreach ($groupIds as $idx => $gid) {
        $ph = ':gid_' . $idx;
        $phs[] = $ph;
        $params[$ph] = $gid;
    }
    $sql .= ' OR pw.group_id IN (' . implode(',', $phs) . ')';
}
$sql .= ' ORDER BY pw.is_favorite DESC, pw.title ASC';

$stmtPw = $pdo->prepare($sql);
$stmtPw->execute($params);
$entries = $stmtPw->fetchAll();

$pageTitle = 'Passwort-Manager';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <!-- Kopfbereich -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-amber-500/10 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                    🔐
                </span>
                <span>Passwort-Manager &amp; Schlüsselbund</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                AES-256-GCM verschlüsselter Cloud-Tresor für Zugangsdaten, WLAN-Schlüssel, Server-Logins, Lizenzen und PINs.
            </p>
        </div>

        <button
            type="button"
            onclick="openPasswordModal()"
            class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer shrink-0"
        >
            ➕ Neuer Zugang / Schlüssel
        </button>
    </div>

    <!-- Such- und Kategorie-Filter -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div class="flex-1">
            <input
                type="search"
                id="pwSearchInput"
                oninput="filterPasswords()"
                placeholder="🔍 Nach Webseite, Titel, Benutzername oder Notiz filtern…"
                class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
            >
        </div>
        <div>
            <select
                id="pwCategoryFilter"
                onchange="filterPasswords()"
                class="w-full sm:w-auto px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200"
            >
                <option value="">Alle Kategorien (<?= count($entries) ?>)</option>
                <?php foreach ($categories as $catKey => $catLabel): ?>
                    <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($entries)): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center shadow-xs">
            <div class="text-4xl mb-3">🔐</div>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white mb-1">Dein Schlüsselbund ist noch leer</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto mb-5">
                Speichere Passwörter, WLAN-Zugänge oder Lizenzschlüssel AES-256-GCM-verschlüsselt und teile ausgewählte Logins auf Wunsch mit deiner Familie oder Gruppe.
            </p>
            <button
                type="button"
                onclick="openPasswordModal()"
                class="px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
            >
                ➕ Ersten Zugang speichern
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="pwGrid">
            <?php foreach ($entries as $item):
                $id          = (int)$item['id'];
                $isOwner     = ((int)$item['user_id'] === $userId);
                $isFav       = ((int)$item['is_favorite'] === 1);
                $plainPass   = decryptPasswordEntry((string)$item['password_encrypted']);
                $plainNotes  = !empty($item['notes_encrypted']) ? decryptPasswordEntry((string)$item['notes_encrypted']) : '';
                $catKey      = (string)$item['category'];
                $catDisplay  = $categories[$catKey] ?? ('📁 ' . $catKey);
                $changedTs   = strtotime((string)($item['password_changed_at'] ?? $item['created_at'])) ?: time();
                $ageDays     = (int)floor((time() - $changedTs) / 86400);
                $isOldPw     = ($ageDays > 180);

                $searchBlob = mb_strtolower($item['title'] . ' ' . $item['username_login'] . ' ' . $item['website_url'] . ' ' . $catKey . ' ' . $plainNotes);

                $itemJson = htmlspecialchars(json_encode([
                    'id'             => $id,
                    'title'          => (string)$item['title'],
                    'category'       => $catKey,
                    'username_login' => (string)$item['username_login'],
                    'password_plain' => $plainPass,
                    'website_url'    => (string)$item['website_url'],
                    'notes_plain'    => $plainNotes,
                    'is_favorite'    => $isFav ? 1 : 0,
                    'share_target'   => !empty($item['shared_user_id'])
                        ? 'user:' . (int)$item['shared_user_id']
                        : (!empty($item['group_id']) ? 'group:' . (int)$item['group_id'] : ''),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
                <div
                    class="pw-card mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5 shadow-xs flex flex-col justify-between gap-3"
                    data-search="<?= e($searchBlob) ?>"
                    data-category="<?= e($catKey) ?>"
                >
                    <div class="space-y-2.5">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        <?= e($catDisplay) ?>
                                    </span>
                                    <?php if (!empty($item['group_name'])): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300">
                                            👥 <?= e((string)$item['group_name']) ?>
                                        </span>
                                    <?php elseif (!empty($item['shared_username'])): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300">
                                            👤 <?= e((string)$item['shared_username']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($isOldPw): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300" title="Dieses Passwort ist älter als 6 Monate (<?= $ageDays ?> Tage)">
                                            ⚠️ <?= $ageDays ?> Tage alt
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h2 class="text-base font-bold text-slate-900 dark:text-white mt-1">
                                    <?= e((string)$item['title']) ?>
                                </h2>
                                <?php if (!empty($item['website_url'])):
                                    $safeHref = preg_match('/^https?:\/\//i', (string)$item['website_url'])
                                        ? (string)$item['website_url']
                                        : 'https://' . (string)$item['website_url'];
                                ?>
                                    <a href="<?= e($safeHref) ?>" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-600 dark:text-brand-400 hover:underline break-all">
                                        🔗 <?= e((string)$item['website_url']) ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($isOwner): ?>
                                <form action="passwords.php" method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_favorite">
                                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                                    <button type="submit" class="text-lg cursor-pointer" title="Als Favorit markieren">
                                        <?= $isFav ? '⭐' : '☆' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <?php if ($item['username_login'] !== ''): ?>
                            <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/70 text-xs">
                                <div class="truncate">
                                    <span class="text-slate-400">Benutzer:</span>
                                    <strong class="text-slate-800 dark:text-slate-100 ml-1 font-mono"><?= e((string)$item['username_login']) ?></strong>
                                </div>
                                <button
                                    type="button"
                                    data-copy="<?= e((string)$item['username_login']) ?>"
                                    onclick="navigator.clipboard.writeText(this.dataset.copy); this.textContent='✅'; setTimeout(()=>this.textContent='📋', 1500);"
                                    class="px-2 py-1 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 text-xs font-semibold cursor-pointer shrink-0"
                                    title="Benutzername kopieren"
                                >📋</button>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/70 text-xs">
                            <div class="truncate flex-1">
                                <span class="text-slate-400">Passwort:</span>
                                <input
                                    type="password"
                                    id="pwField-<?= $id ?>"
                                    readonly
                                    value="<?= e($plainPass) ?>"
                                    class="bg-transparent border-0 font-mono font-bold text-slate-900 dark:text-white ml-1 w-40 focus:outline-none"
                                >
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    onclick="const f=document.getElementById('pwField-<?= $id ?>'); f.type=(f.type==='password'?'text':'password');"
                                    class="px-2 py-1 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 text-xs cursor-pointer"
                                    title="Passwort ein-/ausblenden"
                                >👁️</button>
                                <button
                                    type="button"
                                    data-copy="<?= e($plainPass) ?>"
                                    onclick="navigator.clipboard.writeText(this.dataset.copy); this.textContent='✅ Kopiert'; setTimeout(()=>this.textContent='🔑 Kopieren', 1500);"
                                    class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer"
                                >🔑 Kopieren</button>
                            </div>
                        </div>

                        <?php if ($plainNotes !== ''): ?>
                            <div class="text-xs text-slate-600 dark:text-slate-300 bg-slate-50/60 dark:bg-slate-800/40 p-2.5 rounded-xl border border-slate-200/70 dark:border-slate-700/50 whitespace-pre-line"><?= e($plainNotes) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-800 text-[11px] text-slate-400">
                        <span>von <?= e((string)$item['owner_name']) ?> · <?= e(formatDateTime((string)$item['updated_at'])) ?></span>
                        <?php if ($isOwner): ?>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onclick="openPasswordModal(<?= $itemJson ?>)"
                                    class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 font-semibold cursor-pointer"
                                >✏️ Bearbeiten</button>
                                <form action="passwords.php" method="post" class="inline" onsubmit="return confirm('Diesen Zugang wirklich aus dem Schlüsselbund löschen?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_password">
                                    <input type="hidden" name="entry_id" value="<?= $id ?>">
                                    <button type="submit" class="px-2 py-1 rounded-lg bg-red-600/10 hover:bg-red-600 text-red-600 hover:text-white font-semibold cursor-pointer">🗑️</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Zugang anlegen / bearbeiten + Passwort-Generator -->
<div id="pwModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4 overflow-y-auto">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 max-w-xl w-full p-5 sm:p-6 shadow-2xl my-8">
        <div class="flex items-center justify-between mb-4">
            <h2 id="pwModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔐 Zugang speichern</h2>
            <button type="button" onclick="document.getElementById('pwModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-lg cursor-pointer">✕</button>
        </div>

        <form action="passwords.php" method="post" class="space-y-3.5">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_password">
            <input type="hidden" name="entry_id" id="pwEntryId" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Bezeichnung / Dienst *</label>
                    <input type="text" name="title" id="pwTitle" required maxlength="190" placeholder="z. B. Gäste-WLAN, Strato, Netflix…" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Kategorie</label>
                    <select name="category" id="pwCategory" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-900 dark:text-white">
                        <?php foreach ($categories as $catKey => $catLabel): ?>
                            <option value="<?= e($catKey) ?>"><?= e($catLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Benutzername / E-Mail / SSID</label>
                    <input type="text" name="username_login" id="pwUsername" maxlength="190" placeholder="z. B. name@beispiel.de" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Webseite / Server-URL (optional)</label>
                    <input type="text" name="website_url" id="pwUrl" maxlength="255" placeholder="https://…" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-xs font-semibold text-slate-700 dark:text-slate-300">Passwort / Schlüssel / PIN *</label>
                    <button type="button" onclick="generateStrongPassword()" class="text-xs font-bold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer">
                        🎲 Starkes Passwort generieren
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" name="password_plain" id="pwPlain" required class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm font-mono text-slate-900 dark:text-white">
                    <select id="pwGenLen" class="px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-800 dark:text-slate-200" title="Länge für Passwort-Generator">
                        <option value="16">16 Zeichen</option>
                        <option value="20" selected>20 Zeichen</option>
                        <option value="24">24 Zeichen</option>
                        <option value="32">32 Zeichen</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Verschlüsselte Zusatz-Notiz / Sicherheitsfragen / TAN-Hinweis (optional)</label>
                <textarea name="notes_plain" id="pwNotes" rows="2" class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Teilen mit (optional)</label>
                    <select name="share_target" id="pwShareTarget" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                        <option value="">🔒 Nur für mich persönlich</option>
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
                <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300 mt-4 cursor-pointer">
                    <input type="checkbox" name="is_favorite" id="pwFav" value="1" class="rounded border-slate-300">
                    <span>⭐ Als Favorit ganz oben anheften</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-800">
                <button type="button" onclick="document.getElementById('pwModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs cursor-pointer">
                    🔐 Verschlüsselt speichern
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openPasswordModal(data = null) {
    document.getElementById('pwModalTitle').textContent = data ? '✏️ Zugang bearbeiten' : '➕ Neuen Zugang speichern';
    document.getElementById('pwEntryId').value = data ? data.id : 0;
    document.getElementById('pwTitle').value = data ? data.title : '';
    document.getElementById('pwCategory').value = data ? data.category : 'Webseite';
    document.getElementById('pwUsername').value = data ? data.username_login : '';
    document.getElementById('pwPlain').value = data ? data.password_plain : '';
    document.getElementById('pwUrl').value = data ? data.website_url : '';
    document.getElementById('pwNotes').value = data ? data.notes_plain : '';
    document.getElementById('pwShareTarget').value = data ? data.share_target : '';
    document.getElementById('pwFav').checked = data ? (data.is_favorite === 1) : false;
    if (!data) {
        generateStrongPassword();
    }
    document.getElementById('pwModal').classList.remove('hidden');
}

function generateStrongPassword() {
    const len = parseInt(document.getElementById('pwGenLen').value || '20', 10);
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*+-=?';
    const arr = new Uint32Array(len);
    window.crypto.getRandomValues(arr);
    let out = '';
    for (let i = 0; i < len; i++) {
        out += chars[arr[i] % chars.length];
    }
    document.getElementById('pwPlain').value = out;
}

function filterPasswords() {
    const q = (document.getElementById('pwSearchInput').value || '').toLowerCase().trim();
    const cat = document.getElementById('pwCategoryFilter').value || '';
    document.querySelectorAll('.pw-card').forEach(card => {
        const matchQ = !q || (card.dataset.search || '').includes(q);
        const matchCat = !cat || card.dataset.category === cat;
        card.style.display = (matchQ && matchCat) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
