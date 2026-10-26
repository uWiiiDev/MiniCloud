<?php
/**
 * uWiiiMiniCloud – Adressbuch & Kontakte (contacts.php)
 * Verwaltung persönlicher und geteilter Kontakte inkl. Favoriten, Geburtstagen
 * (automatische Anzeige im Kalender) sowie vCard (.vcf) Import & Export.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_contacts'] ?? '1') !== '1') {
    if (isLoggedIn()) {
        setFlash('error', 'Das Modul „Adressbuch & Kontakte“ ist durch den Administrator deaktiviert.');
        header('Location: index.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

// --- 1. Öffentlicher .vcf-Abo-Feed über persönliches Token (analog zum Kalender-Abo) ---
if (isset($_GET['vcf_token']) && is_string($_GET['vcf_token'])) {
    $token = trim($_GET['vcf_token']);
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
        http_response_code(403);
        exit('Ungültiges Adressbuch-Token.');
    }

    $stmtTokenUser = $pdo->prepare('SELECT id, username, contacts_token FROM users WHERE contacts_token = :t LIMIT 1');
    $stmtTokenUser->execute([':t' => $token]);
    $tokenUser = $stmtTokenUser->fetch();

    if (!$tokenUser || !hash_equals((string)$tokenUser['contacts_token'], $token)) {
        http_response_code(404);
        exit('Adressbuch-Feed nicht gefunden.');
    }

    $feedContacts = getAccessibleContactsForUser($pdo, (int)$tokenUser['id']);
    $vcfContent   = '';
    foreach ($feedContacts as $fc) {
        $vcfContent .= buildVcardEntry($fc);
    }

    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: inline; filename="minicloud-kontakte-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$tokenUser['username']) . '.vcf"');
    header('Cache-Control: no-cache, must-revalidate');
    echo $vcfContent;
    exit;
}

requireLogin();

$userId = currentUserId();

$visiblePeers   = getVisibleUsersForUser($pdo, $userId);
$visiblePeerIds = array_map(static fn(array $u): int => (int)$u['id'], $visiblePeers);
$myGroups       = getGroupsForUser($pdo, $userId);
$myGroupIds     = array_map(static fn(array $g): int => (int)$g['id'], $myGroups);

/**
 * Prüft, ob der aktuelle Benutzer Zugriff auf einen Kontakt hat.
 */
function canAccessContact(array $contact, int $userId, array $myGroupIds): bool
{
    if ((int)$contact['user_id'] === $userId) {
        return true;
    }
    if (!empty($contact['shared_user_id']) && (int)$contact['shared_user_id'] === $userId) {
        return true;
    }
    if (!empty($contact['group_id']) && in_array((int)$contact['group_id'], $myGroupIds, true)) {
        return true;
    }
    return false;
}

$allContacts = getAccessibleContactsForUser($pdo, $userId);

// vCard Export (GET ?export=vcf [&id=X])
if (isset($_GET['export']) && $_GET['export'] === 'vcf') {
    $singleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $out = '';
    $filename = 'Kontakte_uWiiiMiniCloud.vcf';

    foreach ($allContacts as $c) {
        if ($singleId > 0 && (int)$c['id'] !== $singleId) {
            continue;
        }
        $out .= buildVcardEntry($c);
        if ($singleId > 0) {
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($c['first_name'] . '_' . $c['last_name'])) ?: 'Kontakt';
            $filename = $safeName . '.vcf';
        }
    }

    if ($out === '') {
        setFlash('error', 'Keine Kontakte für den Export gefunden.');
        header('Location: contacts.php');
        exit;
    }

    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// POST-Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: contacts.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_contact') {
        $contactId   = isset($_POST['contact_id']) ? (int)$_POST['contact_id'] : 0;
        $firstName   = trim((string)($_POST['first_name'] ?? ''));
        $lastName    = trim((string)($_POST['last_name'] ?? ''));
        $org         = trim((string)($_POST['organization'] ?? ''));
        $roleTitle   = trim((string)($_POST['role_title'] ?? ''));
        $email       = trim((string)($_POST['email'] ?? ''));
        $phone       = trim((string)($_POST['phone'] ?? ''));
        $mobile      = trim((string)($_POST['mobile'] ?? ''));
        $street      = trim((string)($_POST['street'] ?? ''));
        $postalCode  = trim((string)($_POST['postal_code'] ?? ''));
        $city        = trim((string)($_POST['city'] ?? ''));
        $country     = trim((string)($_POST['country'] ?? ''));
        $website     = trim((string)($_POST['website'] ?? ''));
        $bdayRaw     = trim((string)($_POST['birthday'] ?? ''));
        $notes       = trim((string)($_POST['notes'] ?? ''));
        $isFav       = !empty($_POST['is_favorite']) ? 1 : 0;
        $shareTarget = trim((string)($_POST['share_target'] ?? ''));

        if ($firstName === '' && $lastName === '' && $org === '') {
            setFlash('error', 'Bitte gib mindestens Vorname, Nachname oder Firma an.');
            header('Location: contacts.php');
            exit;
        }

        $birthday = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdayRaw)) ? $bdayRaw : null;

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

        if ($contactId > 0) {
            $stmtChk = $pdo->prepare("SELECT * FROM `contacts` WHERE `id` = :id LIMIT 1");
            $stmtChk->execute([':id' => $contactId]);
            $existing = $stmtChk->fetch();
            if (!$existing || !canAccessContact($existing, $userId, $myGroupIds)) {
                setFlash('error', 'Kontakt nicht gefunden oder keine Berechtigung.');
                header('Location: contacts.php');
                exit;
            }

            if ((int)$existing['user_id'] !== $userId) {
                $sharedUserId = $existing['shared_user_id'] ? (int)$existing['shared_user_id'] : null;
                $groupId      = $existing['group_id'] ? (int)$existing['group_id'] : null;
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE `contacts`
                 SET `first_name` = :fn, `last_name` = :ln, `organization` = :org, `role_title` = :rt,
                     `email` = :em, `phone` = :ph, `mobile` = :mob, `street` = :str, `postal_code` = :pc,
                     `city` = :city, `country` = :cnt, `website` = :web, `birthday` = :bday, `notes` = :nt,
                     `is_favorite` = :fav, `shared_user_id` = :su, `group_id` = :gid
                 WHERE `id` = :id"
            );
            $stmtUpd->execute([
                ':fn'   => mb_substr($firstName, 0, 100),
                ':ln'   => mb_substr($lastName, 0, 100),
                ':org'  => mb_substr($org, 0, 150),
                ':rt'   => mb_substr($roleTitle, 0, 120),
                ':em'   => mb_substr($email, 0, 190),
                ':ph'   => mb_substr($phone, 0, 80),
                ':mob'  => mb_substr($mobile, 0, 80),
                ':str'  => mb_substr($street, 0, 190),
                ':pc'   => mb_substr($postalCode, 0, 30),
                ':city' => mb_substr($city, 0, 100),
                ':cnt'  => mb_substr($country, 0, 100),
                ':web'  => mb_substr($website, 0, 255),
                ':bday' => $birthday,
                ':nt'   => $notes !== '' ? $notes : null,
                ':fav'  => $isFav,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
                ':id'   => $contactId,
            ]);
            setFlash('success', 'Kontakt aktualisiert.');
        } else {
            $stmtIns = $pdo->prepare(
                "INSERT INTO `contacts`
                    (`user_id`, `first_name`, `last_name`, `organization`, `role_title`, `email`, `phone`, `mobile`,
                     `street`, `postal_code`, `city`, `country`, `website`, `birthday`, `notes`, `is_favorite`, `shared_user_id`, `group_id`)
                 VALUES
                    (:uid, :fn, :ln, :org, :rt, :em, :ph, :mob, :str, :pc, :city, :cnt, :web, :bday, :nt, :fav, :su, :gid)"
            );
            $stmtIns->execute([
                ':uid'  => $userId,
                ':fn'   => mb_substr($firstName, 0, 100),
                ':ln'   => mb_substr($lastName, 0, 100),
                ':org'  => mb_substr($org, 0, 150),
                ':rt'   => mb_substr($roleTitle, 0, 120),
                ':em'   => mb_substr($email, 0, 190),
                ':ph'   => mb_substr($phone, 0, 80),
                ':mob'  => mb_substr($mobile, 0, 80),
                ':str'  => mb_substr($street, 0, 190),
                ':pc'   => mb_substr($postalCode, 0, 30),
                ':city' => mb_substr($city, 0, 100),
                ':cnt'  => mb_substr($country, 0, 100),
                ':web'  => mb_substr($website, 0, 255),
                ':bday' => $birthday,
                ':nt'   => $notes !== '' ? $notes : null,
                ':fav'  => $isFav,
                ':su'   => $sharedUserId,
                ':gid'  => $groupId,
            ]);
            setFlash('success', 'Neuer Kontakt gespeichert.');
        }

        header('Location: contacts.php');
        exit;
    }

    if ($action === 'toggle_favorite') {
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `contacts` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $contactId]);
        $c = $stmtChk->fetch();
        if ($c && canAccessContact($c, $userId, $myGroupIds)) {
            $newFav = (int)$c['is_favorite'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE `contacts` SET `is_favorite` = :f WHERE `id` = :id")
                ->execute([':f' => $newFav, ':id' => $contactId]);
        }
        header('Location: contacts.php');
        exit;
    }

    if ($action === 'delete_contact') {
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $stmtChk = $pdo->prepare("SELECT * FROM `contacts` WHERE `id` = :id LIMIT 1");
        $stmtChk->execute([':id' => $contactId]);
        $c = $stmtChk->fetch();
        if ($c && (int)$c['user_id'] === $userId) {
            $pdo->prepare("DELETE FROM `contacts` WHERE `id` = :id")->execute([':id' => $contactId]);
            setFlash('success', 'Kontakt gelöscht.');
        } else {
            setFlash('error', 'Nur der Eigentümer kann diesen Kontakt löschen.');
        }
        header('Location: contacts.php');
        exit;
    }

    if ($action === 'import_vcf') {
        if (empty($_FILES['vcf_file']['tmp_name']) || !is_uploaded_file($_FILES['vcf_file']['tmp_name'])) {
            setFlash('error', 'Bitte wähle eine gültige .vcf-Datei aus.');
            header('Location: contacts.php');
            exit;
        }
        $rawContent = (string)file_get_contents($_FILES['vcf_file']['tmp_name']);
        $parsedList = parseVcardFileContent($rawContent);

        if (empty($parsedList)) {
            setFlash('error', 'Es konnten keine vCard-Kontakte in der Datei erkannt werden.');
            header('Location: contacts.php');
            exit;
        }

        $stmtIns = $pdo->prepare(
            "INSERT INTO `contacts`
                (`user_id`, `first_name`, `last_name`, `organization`, `role_title`, `email`, `phone`, `mobile`,
                 `street`, `postal_code`, `city`, `country`, `website`, `birthday`, `notes`, `is_favorite`)
             VALUES
                (:uid, :fn, :ln, :org, :rt, :em, :ph, :mob, :str, :pc, :city, :cnt, :web, :bday, :nt, 0)"
        );

        $importedCount = 0;
        foreach ($parsedList as $item) {
            $stmtIns->execute([
                ':uid'  => $userId,
                ':fn'   => mb_substr($item['first_name'], 0, 100),
                ':ln'   => mb_substr($item['last_name'], 0, 100),
                ':org'  => mb_substr($item['organization'], 0, 150),
                ':rt'   => mb_substr($item['role_title'], 0, 120),
                ':em'   => mb_substr($item['email'], 0, 190),
                ':ph'   => mb_substr($item['phone'], 0, 80),
                ':mob'  => mb_substr($item['mobile'], 0, 80),
                ':str'  => mb_substr($item['street'], 0, 190),
                ':pc'   => mb_substr($item['postal_code'], 0, 30),
                ':city' => mb_substr($item['city'], 0, 100),
                ':cnt'  => mb_substr($item['country'], 0, 100),
                ':web'  => mb_substr($item['website'], 0, 255),
                ':bday' => $item['birthday'],
                ':nt'   => $item['notes'] !== '' ? $item['notes'] : null,
            ]);
            $importedCount++;
        }

        setFlash('success', $importedCount . ' Kontakt(e) erfolgreich aus der vCard-Datei importiert.');
        header('Location: contacts.php');
        exit;
    }

    if ($action === 'regenerate_contacts_token') {
        $newToken = bin2hex(random_bytes(24));
        $pdo->prepare('UPDATE users SET contacts_token = :t WHERE id = :uid')
            ->execute([':t' => $newToken, ':uid' => $userId]);
        setFlash('success', 'Persönlicher .vcf-Adressbuch-Abo-Link wurde neu generiert.');
        header('Location: contacts.php?sync_modal=1');
        exit;
    }

    if ($action === 'revoke_contacts_token') {
        $pdo->prepare('UPDATE users SET contacts_token = NULL WHERE id = :uid')
            ->execute([':uid' => $userId]);
        setFlash('success', 'Der .vcf-Adressbuch-Abo-Link wurde deaktiviert.');
        header('Location: contacts.php?sync_modal=1');
        exit;
    }
}

$stmtUserToken = $pdo->prepare('SELECT contacts_token FROM users WHERE id = :uid LIMIT 1');
$stmtUserToken->execute([':uid' => $userId]);
$myContactsToken = (string)($stmtUserToken->fetchColumn() ?: '');
$vcfFeedUrl      = $myContactsToken !== '' ? (getBaseUrl() . '/contacts.php?vcf_token=' . $myContactsToken) : '';
$carddavUrl      = getBaseUrl() . '/carddav.php';

$pageTitle = 'Adressbuch & Kontakte';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.contacts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 1.1rem;
}
.contact-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.1rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.contact-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}
.contact-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    flex-shrink: 0;
}
.contact-detail-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem;
    color: var(--text);
    word-break: break-word;
}
</style>

<div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">
    <div>
        <h1 class="page-title" style="margin:0;">📇 Adressbuch &amp; Kontakte</h1>
        <p class="page-subtitle" style="margin:0.25rem 0 0 0;">Persönliche &amp; geteilte Kontakte inkl. vCard (<code>.vcf</code>) Import/Export und automatischer Geburtstags-Anzeige im Kalender</p>
    </div>
    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
        <input type="search" id="contactSearchInput" class="form-control" placeholder="🔍 Name, Firma, E-Mail, Ort..." style="max-width:220px;" oninput="filterContacts()">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('contactsSyncModal').style.display='flex'" title="Adressbuch abonnieren (CardDAV &amp; .vcf-Link)">📡 Abo &amp; CardDAV</button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('vcfImportModal').style.display='flex'">📥 .vcf Import</button>
        <?php if (!empty($allContacts)): ?>
            <a href="contacts.php?export=vcf" class="btn btn-outline" title="Alle sichtbaren Kontakte als vCard (.vcf) herunterladen">📤 .vcf Export</a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary" onclick="openContactModal()">➕ Neuer Kontakt</button>
    </div>
</div>

<?php if (empty($allContacts)): ?>
    <div class="card" style="text-align:center; padding:3rem 1.5rem;">
        <div style="font-size:3rem; margin-bottom:0.6rem;">📇</div>
        <h3 style="margin:0 0 0.4rem 0;">Noch keine Kontakte im Adressbuch</h3>
        <p class="text-muted" style="margin:0 0 1.2rem 0;">Lege deinen ersten Kontakt an oder importiere bestehende Kontakte aus einer <code>.vcf</code>-Datei (z. B. vom Smartphone oder aus Outlook/Thunderbird).</p>
        <div style="display:flex; gap:0.6rem; justify-content:center; flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" onclick="openContactModal()">➕ Kontakt anlegen</button>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('vcfImportModal').style.display='flex'">📥 vCard (.vcf) importieren</button>
        </div>
    </div>
<?php else: ?>
    <div class="contacts-grid" id="contactsGrid">
        <?php foreach ($allContacts as $c):
            $cid = (int)$c['id'];
            $isOwner = (int)$c['user_id'] === $userId;
            $isFav   = (int)$c['is_favorite'] === 1;
            $fullName = trim($c['first_name'] . ' ' . $c['last_name']);
            if ($fullName === '') {
                $fullName = (string)$c['organization'];
            }
            $initials = mb_strtoupper(mb_substr((string)$c['first_name'], 0, 1) . mb_substr((string)$c['last_name'], 0, 1));
            if ($initials === '') {
                $initials = mb_strtoupper(mb_substr($fullName, 0, 2));
            }
            $addressLine = trim(
                ($c['street'] ? $c['street'] . ', ' : '')
                . trim($c['postal_code'] . ' ' . $c['city'])
                . ($c['country'] ? ' (' . $c['country'] . ')' : ''),
                ', '
            );
            $searchBlob = mb_strtolower(
                $fullName . ' ' . ($c['organization'] ?? '') . ' ' . ($c['role_title'] ?? '') . ' '
                . ($c['email'] ?? '') . ' ' . ($c['phone'] ?? '') . ' ' . ($c['mobile'] ?? '') . ' '
                . $addressLine . ' ' . ($c['notes'] ?? '')
            );
            $contactJson = htmlspecialchars(json_encode([
                'id'           => $cid,
                'first_name'   => (string)$c['first_name'],
                'last_name'    => (string)$c['last_name'],
                'organization' => (string)$c['organization'],
                'role_title'   => (string)$c['role_title'],
                'email'        => (string)$c['email'],
                'phone'        => (string)$c['phone'],
                'mobile'       => (string)$c['mobile'],
                'street'       => (string)$c['street'],
                'postal_code'  => (string)$c['postal_code'],
                'city'         => (string)$c['city'],
                'country'      => (string)$c['country'],
                'website'      => (string)$c['website'],
                'birthday'     => (string)($c['birthday'] ?? ''),
                'notes'        => (string)($c['notes'] ?? ''),
                'is_favorite'  => $isFav ? 1 : 0,
                'is_owner'     => $isOwner,
                'share_target' => !empty($c['shared_user_id'])
                    ? 'user:' . (int)$c['shared_user_id']
                    : (!empty($c['group_id']) ? 'group:' . (int)$c['group_id'] : ''),
            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
            <div class="contact-card" data-search="<?= e($searchBlob) ?>">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <div class="contact-avatar"><?= e($initials) ?></div>
                        <div>
                            <div style="font-weight:700; font-size:1rem; line-height:1.3;">
                                <?= e($fullName) ?>
                            </div>
                            <?php if ($c['organization'] !== '' || $c['role_title'] !== ''): ?>
                                <div class="text-muted" style="font-size:0.8rem;">
                                    <?= e(trim($c['role_title'] . ($c['role_title'] && $c['organization'] ? ' · ' : '') . $c['organization'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="post" action="contacts.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_favorite">
                        <input type="hidden" name="contact_id" value="<?= $cid ?>">
                        <button type="submit" class="btn btn-xs btn-outline" title="<?= $isFav ? 'Favorit entfernen' : 'Als Favorit markieren' ?>" style="border:none; font-size:1.05rem;">
                            <?= $isFav ? '⭐' : '☆' ?>
                        </button>
                    </form>
                </div>

                <div style="display:flex; flex-direction:column; gap:0.35rem; margin-top:0.2rem;">
                    <?php if ($c['email'] !== ''): ?>
                        <div class="contact-detail-row">
                            <span>✉️</span>
                            <a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($c['phone'] !== ''): ?>
                        <div class="contact-detail-row">
                            <span>☎️</span>
                            <a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($c['mobile'] !== ''): ?>
                        <div class="contact-detail-row">
                            <span>📱</span>
                            <a href="tel:<?= e($c['mobile']) ?>"><?= e($c['mobile']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($addressLine !== ''): ?>
                        <div class="contact-detail-row">
                            <span>📍</span>
                            <span><?= e($addressLine) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($c['birthday'])): ?>
                        <div class="contact-detail-row">
                            <span>🎂</span>
                            <span><?= e(date('d.m.Y', strtotime((string)$c['birthday']))) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($c['website'] !== ''): ?>
                        <div class="contact-detail-row">
                            <span>🌐</span>
                            <a href="<?= e(preg_match('~^https?://~i', $c['website']) ? $c['website'] : 'https://' . $c['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($c['website']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($c['notes'])): ?>
                        <div class="text-muted" style="font-size:0.8rem; margin-top:0.2rem; white-space:pre-wrap;"><?= e((string)$c['notes']) ?></div>
                    <?php endif; ?>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem; margin-top:auto; padding-top:0.6rem; border-top:1px solid var(--border); font-size:0.75rem;">
                    <div class="text-muted">
                        <?php if (!$isOwner): ?>
                            👤 Von <?= e((string)$c['owner_name']) ?>
                        <?php elseif (!empty($c['shared_username'])): ?>
                            🤝 Geteilt mit <?= e((string)$c['shared_username']) ?>
                        <?php elseif (!empty($c['group_name'])): ?>
                            👥 Gruppe: <?= e((string)$c['group_name']) ?>
                        <?php else: ?>
                            🔒 Privat
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:0.3rem;">
                        <a href="contacts.php?export=vcf&amp;id=<?= $cid ?>" class="btn btn-xs btn-outline" title="Als vCard (.vcf) herunterladen">📇 .vcf</a>
                        <button type="button" class="btn btn-xs btn-outline" onclick='openContactModal(<?= $contactJson ?>)' title="Bearbeiten">✏️</button>
                        <?php if ($isOwner): ?>
                            <form method="post" action="contacts.php" style="margin:0;" onsubmit="return confirm('Kontakt wirklich löschen?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_contact">
                                <input type="hidden" name="contact_id" value="<?= $cid ?>">
                                <button type="submit" class="btn btn-xs btn-danger" title="Löschen">🗑️</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Modal: Kontakt erstellen / bearbeiten -->
<div class="modal-overlay" id="contactModal" style="display:none;" onclick="if(event.target===this) closeContactModal();">
    <div class="modal-box" style="max-width:640px; max-height:92vh; overflow-y:auto;">
        <div class="modal-header">
            <h3 id="contactModalTitle" style="margin:0;">➕ Neuer Kontakt</h3>
            <button type="button" class="modal-close" onclick="closeContactModal()">✕</button>
        </div>
        <form method="post" action="contacts.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_contact">
            <input type="hidden" name="contact_id" id="contactFormId" value="0">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="contactFirstName">Vorname</label>
                    <input type="text" name="first_name" id="contactFirstName" class="form-control" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactLastName">Nachname</label>
                    <input type="text" name="last_name" id="contactLastName" class="form-control" maxlength="100">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="contactOrg">🏢 Firma / Organisation</label>
                    <input type="text" name="organization" id="contactOrg" class="form-control" maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactRole">💼 Position / Rolle</label>
                    <input type="text" name="role_title" id="contactRole" class="form-control" maxlength="120">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="contactEmail">✉️ E-Mail</label>
                    <input type="email" name="email" id="contactEmail" class="form-control" maxlength="190">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactPhone">☎️ Telefon</label>
                    <input type="text" name="phone" id="contactPhone" class="form-control" maxlength="80">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactMobile">📱 Mobil</label>
                    <input type="text" name="mobile" id="contactMobile" class="form-control" maxlength="80">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:2fr 1fr 1.5fr 1.2fr; gap:0.6rem;">
                <div class="form-group">
                    <label class="form-label" for="contactStreet">Straße &amp; Nr.</label>
                    <input type="text" name="street" id="contactStreet" class="form-control" maxlength="190">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactPostal">PLZ</label>
                    <input type="text" name="postal_code" id="contactPostal" class="form-control" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactCity">Ort</label>
                    <input type="text" name="city" id="contactCity" class="form-control" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactCountry">Land</label>
                    <input type="text" name="country" id="contactCountry" class="form-control" maxlength="100">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label" for="contactBday">🎂 Geburtstag</label>
                    <input type="date" name="birthday" id="contactBday" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactWeb">🌐 Webseite</label>
                    <input type="text" name="website" id="contactWeb" class="form-control" placeholder="https://...">
                </div>
                <div class="form-group" id="contactShareWrapper">
                    <label class="form-label" for="contactShare">🤝 Teilen mit</label>
                    <select name="share_target" id="contactShare" class="form-control">
                        <option value="">🔒 Privat</option>
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
                <label class="form-label" for="contactNotes">📝 Notizen</label>
                <textarea name="notes" id="contactNotes" class="form-control" rows="2"></textarea>
            </div>

            <div class="form-group">
                <label style="display:inline-flex; align-items:center; gap:0.5rem; cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" name="is_favorite" id="contactFav" value="1">
                    ⭐ Als Favorit ganz oben anzeigen
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeContactModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">💾 Kontakt speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: vCard (.vcf) Import -->
<div class="modal-overlay" id="vcfImportModal" style="display:none;" onclick="if(event.target===this) this.style.display='none';">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-header">
            <h3 style="margin:0;">📥 Kontakte aus vCard (.vcf) importieren</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('vcfImportModal').style.display='none'">✕</button>
        </div>
        <form method="post" action="contacts.php" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_vcf">
            <p class="text-muted" style="font-size:0.86rem; margin-top:0;">
                Wähle eine <code>.vcf</code>-Datei (vCard 3.0 oder 4.0) aus. Es können auch Sammel-Dateien mit mehreren Kontakten gleichzeitig importiert werden.
            </p>
            <div class="form-group">
                <input type="file" name="vcf_file" class="form-control" accept=".vcf,text/vcard" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('vcfImportModal').style.display='none'">Abbrechen</button>
                <button type="submit" class="btn btn-primary">📥 Jetzt importieren</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Adressbuch abonnieren (CardDAV & persönlicher .vcf-Link + QR-Code) -->
<div class="modal-overlay" id="contactsSyncModal" style="display:<?= !empty($_GET['sync_modal']) ? 'flex' : 'none' ?>;" onclick="if(event.target===this) this.style.display='none';">
    <div class="modal-box" style="max-width:640px; max-height:92vh; overflow-y:auto;">
        <div class="modal-header">
            <h3 style="margin:0;">📡 Adressbuch abonnieren &amp; synchronisieren</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('contactsSyncModal').style.display='none'">✕</button>
        </div>

        <!-- 1. Nativer CardDAV-Server (2-Wege-Sync für iOS, macOS, Thunderbird, Android) -->
        <div style="padding:1rem; border-radius:0.85rem; border:1px solid var(--border); background:var(--bg-hover); margin-bottom:1.1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                <div style="font-weight:700; font-size:0.95rem;">📱 1. Live-Synchronisation per CardDAV (Smartphone &amp; PC)</div>
                <span class="badge" style="background:rgba(16,185,129,0.18); color:#10b981;">2-Wege-Sync</span>
            </div>
            <p class="text-muted" style="font-size:0.82rem; margin:0 0 0.65rem 0;">
                Verbinde dein Adressbuch direkt über das <strong>CardDAV-Protokoll</strong> mit deinem iPhone/iPad, macOS Kontakte, Mozilla Thunderbird oder Android (z.&nbsp;B. über <em>DAVx⁵</em>). Neue und geänderte Kontakte werden automatisch synchronisiert.
            </p>
            <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.75rem;">
                <input type="text" id="carddavUrlInput" class="form-control" readonly value="<?= e($carddavUrl) ?>" style="font-family:monospace; font-size:0.82rem;">
                <button type="button" class="btn btn-primary" style="flex-shrink:0;" onclick="navigator.clipboard.writeText(document.getElementById('carddavUrlInput').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='📋 Kopieren', 2000);">📋 Kopieren</button>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(175px, 1fr)); gap:0.55rem; font-size:0.76rem;" class="text-muted">
                <div style="padding:0.55rem; border-radius:0.6rem; border:1px solid var(--border); background:var(--bg-card);">
                    <strong style="color:var(--text);">🍎 iPhone / iPad / macOS</strong><br>
                    <em>Einstellungen &rarr; Apps &rarr; Kontakte &rarr; Accounts &rarr; Account hinzufügen &rarr; Andere &rarr; CardDAV-Account</em> &rarr; Server: <code><?= e($carddavUrl) ?></code> + dein Cloud-Benutzername &amp; Passwort.
                </div>
                <div style="padding:0.55rem; border-radius:0.6rem; border:1px solid var(--border); background:var(--bg-card);">
                    <strong style="color:var(--text);">🦅 Mozilla Thunderbird</strong><br>
                    <em>Adressbuch &rarr; Neues Adressbuch &rarr; CardDAV-Adressbuch hinzufügen</em> &rarr; Benutzername &amp; Adresse <code><?= e($carddavUrl) ?></code> eintragen.
                </div>
                <div style="padding:0.55rem; border-radius:0.6rem; border:1px solid var(--border); background:var(--bg-card);">
                    <strong style="color:var(--text);">🤖 Android (DAVx⁵)</strong><br>
                    In <em>DAVx⁵</em> &rarr; <em>Mit URL und Benutzername anmelden</em> &rarr; Basis-URL <code><?= e($carddavUrl) ?></code> und deine Cloud-Zugangsdaten eingeben.
                </div>
            </div>
        </div>

        <!-- 2. Persönlicher .vcf-Abo-Link per Token (analog zum .ics-Kalender-Abo) -->
        <div style="padding:1rem; border-radius:0.85rem; border:1px solid var(--border); background:var(--bg-hover);">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-bottom:0.4rem;">
                <div style="font-weight:700; font-size:0.95rem;">🔗 2. Persönlicher .vcf-Abo-Link &amp; QR-Code (wie beim Kalender)</div>
                <span class="badge" style="background:<?= $vcfFeedUrl !== '' ? 'rgba(59,130,246,0.18); color:#3b82f6;' : 'rgba(148,163,184,0.2); color:var(--text-muted);' ?>">
                    <?= $vcfFeedUrl !== '' ? 'Aktiv' : 'Inaktiv' ?>
                </span>
            </div>
            <p class="text-muted" style="font-size:0.82rem; margin:0 0 0.75rem 0;">
                Über diesen geheimen Token-Link kannst du dein gesamtes Adressbuch ohne erneute Passworteingabe als Live-<code>.vcf</code>-Feed abrufen oder per QR-Code direkt auf dein Smartphone laden.
            </p>

            <?php if ($vcfFeedUrl !== ''): ?>
                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.85rem;">
                    <input type="text" id="vcfFeedUrlInput" class="form-control" readonly value="<?= e($vcfFeedUrl) ?>" style="font-family:monospace; font-size:0.8rem;">
                    <button type="button" class="btn btn-primary" style="flex-shrink:0;" onclick="navigator.clipboard.writeText(document.getElementById('vcfFeedUrlInput').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='📋 Kopieren', 2000);">📋 Kopieren</button>
                </div>

                <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:0.85rem; padding:0.75rem; border-radius:0.75rem; border:1px solid var(--border); background:var(--bg-card);">
                    <div style="flex-shrink:0;">
                        <?= generateQrCodeSvg($vcfFeedUrl, 128) ?>
                    </div>
                    <div style="flex:1; min-width:200px; font-size:0.8rem;" class="text-muted">
                        <strong style="color:var(--text); display:block; margin-bottom:0.25rem;">📱 QR-Code mit der Smartphone-Kamera scannen</strong>
                        Scanne diesen 100&nbsp;% lokal erzeugten QR-Code mit deinem Handy, um alle aktuellen Kontakte sofort als <code>.vcf</code>-Adressbuch zu öffnen und zu importieren.
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
                    <form method="post" action="contacts.php" style="margin:0;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="regenerate_contacts_token">
                        <button type="submit" class="btn btn-xs btn-outline">🔄 Neuen Link generieren (alten ungültig machen)</button>
                    </form>
                    <form method="post" action="contacts.php" style="margin:0;" onsubmit="return confirm('Möchtest du den .vcf-Abo-Link wirklich deaktivieren?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="revoke_contacts_token">
                        <button type="submit" class="btn btn-xs btn-danger">🗑️ Abo-Link deaktivieren</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" action="contacts.php" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="regenerate_contacts_token">
                    <button type="submit" class="btn btn-primary">🔗 Persönlichen .vcf-Abo-Link jetzt aktivieren</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function filterContacts() {
    const q = (document.getElementById('contactSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.contact-card').forEach(card => {
        const hay = card.getAttribute('data-search') || '';
        card.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
    });
}

function openContactModal(c = null) {
    document.getElementById('contactModalTitle').textContent = c ? '✏️ Kontakt bearbeiten' : '➕ Neuer Kontakt';
    document.getElementById('contactFormId').value = c ? c.id : 0;
    document.getElementById('contactFirstName').value = c ? c.first_name : '';
    document.getElementById('contactLastName').value = c ? c.last_name : '';
    document.getElementById('contactOrg').value = c ? c.organization : '';
    document.getElementById('contactRole').value = c ? c.role_title : '';
    document.getElementById('contactEmail').value = c ? c.email : '';
    document.getElementById('contactPhone').value = c ? c.phone : '';
    document.getElementById('contactMobile').value = c ? c.mobile : '';
    document.getElementById('contactStreet').value = c ? c.street : '';
    document.getElementById('contactPostal').value = c ? c.postal_code : '';
    document.getElementById('contactCity').value = c ? c.city : '';
    document.getElementById('contactCountry').value = c ? c.country : '';
    document.getElementById('contactWeb').value = c ? c.website : '';
    document.getElementById('contactBday').value = c ? c.birthday : '';
    document.getElementById('contactNotes').value = c ? c.notes : '';
    document.getElementById('contactFav').checked = c ? (Number(c.is_favorite) === 1) : false;
    document.getElementById('contactShare').value = c ? (c.share_target || '') : '';
    document.getElementById('contactShareWrapper').style.display = (!c || c.is_owner) ? '' : 'none';
    document.getElementById('contactModal').style.display = 'flex';
}

function closeContactModal() {
    document.getElementById('contactModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
