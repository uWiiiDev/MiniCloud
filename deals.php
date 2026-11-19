<?php
declare(strict_types=1);

/**
 * MiniCloud - 🏷️ Preiswächter & Deal-Radar (deals.php)
 * 100 % privater, werbefreier Produkt-Preis-Tracker & Watchdog:
 * - 🌐 Produkt-Überwachung per Link (Amazon, Idealo, Geizhals, Otto, MediaMarkt, etc.)
 * - 🎯 Wunschpreis-Schwelle & automatischer Preisalarm (Glocke / E-Mail)
 * - 📈 Interaktive Verlaufscharts, Allzeittief-Erkennung & Ersparnis-Rechner
 * - 🔄 Automatischer Hintergrund-Check (Lazy-Cron) + 1-Klick-Aktualisierung
 * - 📌 1-Klick-Browser-Bookmarklet für bequemes Hinzufügen beim Surfen
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_deals'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Preiswächter-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$csrfToken = getCsrfToken();

// ============================================================================
// 1. DATENBANK-SCHEMA LAZY INITIALISIEREN
// ============================================================================
function ensureDealsSchema(PDO $pdo, int $userId): void
{
    static $done = false;
    if ($done || (!empty($_SESSION['_mc_deals_schema_ok']) && $_SESSION['_mc_deals_schema_ok'] === 1)) {
        $done = true;
        return;
    }
    $done = true;

    // 1. Beobachtete Produkte
    $pdo->exec("CREATE TABLE IF NOT EXISTS `price_watch_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `url` TEXT NOT NULL,
        `domain` VARCHAR(100) NOT NULL DEFAULT '',
        `image_url` TEXT NULL,
        `category` VARCHAR(60) NOT NULL DEFAULT 'Allgemein',
        `current_price` DECIMAL(10,2) NULL,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
        `target_price` DECIMAL(10,2) NULL,
        `initial_price` DECIMAL(10,2) NULL,
        `lowest_price` DECIMAL(10,2) NULL,
        `highest_price` DECIMAL(10,2) NULL,
        `in_stock` TINYINT(1) NOT NULL DEFAULT 1,
        `availability_text` VARCHAR(100) NOT NULL DEFAULT 'Verfügbar',
        `notify_target` TINYINT(1) NOT NULL DEFAULT 1,
        `notify_drop` TINYINT(1) NOT NULL DEFAULT 1,
        `notify_email` TINYINT(1) NOT NULL DEFAULT 0,
        `last_checked_at` DATETIME NULL,
        `last_notified_at` DATETIME NULL,
        `last_price_change_at` DATETIME NULL,
        `notes` TEXT NULL,
        `priority` VARCHAR(20) NOT NULL DEFAULT 'medium',
        `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pw_user` (`user_id`),
        KEY `idx_pw_active` (`user_id`, `is_archived`),
        KEY `idx_pw_checked` (`last_checked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Preis-Historie
    $pdo->exec("CREATE TABLE IF NOT EXISTS `price_watch_history` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_id` INT UNSIGNED NOT NULL,
        `price` DECIMAL(10,2) NOT NULL,
        `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pwh_item` (`item_id`),
        KEY `idx_pwh_date` (`item_id`, `recorded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Preis-Alarme & Benachrichtigungen
    $pdo->exec("CREATE TABLE IF NOT EXISTS `price_watch_alerts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `item_id` INT UNSIGNED NOT NULL,
        `user_id` INT NOT NULL,
        `old_price` DECIMAL(10,2) NOT NULL,
        `new_price` DECIMAL(10,2) NOT NULL,
        `alert_type` VARCHAR(30) NOT NULL DEFAULT 'target_reached',
        `message` VARCHAR(255) NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_pwa_user` (`user_id`, `is_read`),
        KEY `idx_pwa_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $_SESSION['_mc_deals_schema_ok'] = 1;

    // Seed: Beispielartikel einfügen, falls Nutzer noch 0 Artikel hat
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM price_watch_items WHERE user_id = :uid');
    $stmtCount->execute([':uid' => $userId]);
    if ((int)$stmtCount->fetchColumn() === 0) {
        $sampleItems = [
            [
                'title'         => 'Sony WH-1000XM5 Wireless Noise Cancelling Kopfhörer',
                'url'           => 'https://geizhals.de/sony-wh-1000xm5-schwarz-a2730628.html',
                'domain'        => 'geizhals.de',
                'image_url'     => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?w=400&q=80',
                'category'      => 'Technik',
                'current_price' => 289.00,
                'initial_price' => 349.00,
                'target_price'  => 279.00,
                'lowest_price'  => 289.00,
                'highest_price' => 349.00,
                'notes'         => 'Warten auf unter 280 € für die nächste Urlaubsreise.',
                'history'       => [349.00, 335.00, 329.00, 319.00, 299.00, 289.00]
            ],
            [
                'title'         => 'Philips Hue White & Color Ambiance E27 Starter-Set',
                'url'           => 'https://www.idealo.de/preisvergleich/OffersOfProduct/200593457_-hue-white-color-ambiance-e27-starter-set-philips.html',
                'domain'        => 'idealo.de',
                'image_url'     => 'https://images.unsplash.com/photo-1550525811-e5869dd03032?w=400&q=80',
                'category'      => 'Haushalt',
                'current_price' => 99.99,
                'initial_price' => 129.99,
                'target_price'  => 100.00,
                'lowest_price'  => 99.99,
                'highest_price' => 129.99,
                'notes'         => 'Wunschpreis erreicht! Für das Wohnzimmer-Ambilight.',
                'history'       => [129.99, 125.00, 119.99, 115.00, 109.99, 99.99]
            ]
        ];

        foreach ($sampleItems as $s) {
            $stmtIns = $pdo->prepare('INSERT INTO price_watch_items
                (user_id, title, url, domain, image_url, category, current_price, initial_price, target_price, lowest_price, highest_price, notes, last_checked_at)
                VALUES (:uid, :title, :url, :domain, :img, :cat, :cur, :init, :tgt, :low, :high, :notes, NOW())');
            $stmtIns->execute([
                ':uid'    => $userId,
                ':title'  => $s['title'],
                ':url'    => $s['url'],
                ':domain' => $s['domain'],
                ':img'    => $s['image_url'],
                ':cat'    => $s['category'],
                ':cur'    => $s['current_price'],
                ':init'   => $s['initial_price'],
                ':tgt'    => $s['target_price'],
                ':low'    => $s['lowest_price'],
                ':high'   => $s['highest_price'],
                ':notes'  => $s['notes'],
            ]);
            $itemId = (int)$pdo->lastInsertId();

            // Historie simulieren (über die letzten Tage)
            $hist = $s['history'];
            $daysAgo = count($hist);
            foreach ($hist as $hPrice) {
                $recDate = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
                $pdo->prepare('INSERT INTO price_watch_history (item_id, price, recorded_at) VALUES (:iid, :p, :dt)')
                    ->execute([':iid' => $itemId, ':p' => $hPrice, ':dt' => $recDate]);
                $daysAgo--;
            }

            // Wunschpreis-Alarm eintragen falls erreicht
            if ($s['current_price'] <= $s['target_price']) {
                $pdo->prepare('INSERT INTO price_watch_alerts (item_id, user_id, old_price, new_price, alert_type, message) VALUES (:iid, :uid, :op, :np, "target_reached", :msg)')
                    ->execute([
                        ':iid' => $itemId,
                        ':uid' => $userId,
                        ':op'  => $s['initial_price'],
                        ':np'  => $s['current_price'],
                        ':msg' => 'Wunschpreis erreicht: ' . number_format((float)$s['current_price'], 2, ',', '.') . ' € (Ziel: ' . number_format((float)$s['target_price'], 2, ',', '.') . ' €)'
                    ]);
            }
        }
    }
}

ensureDealsSchema($pdo, $userId);

// ============================================================================
// 2. SMARTER SCRAPER & PREIS-PARSER
// ============================================================================
require_once __DIR__ . '/includes/deals_scraper.php';

// ============================================================================
// 3. AJAX-API ACTIONS (PREVIEW, CHECK-SINGLE, CHECK-ALL)
// ============================================================================
$apiAction = $_GET['api'] ?? '';

if ($apiAction === 'fetch_preview') {
    header('Content-Type: application/json; charset=utf-8');
    $fetchUrl = trim((string)($_GET['url'] ?? ''));
    if ($fetchUrl === '') {
        echo json_encode(['ok' => false, 'error' => 'Keine URL übergeben.']);
        exit;
    }
    $scraped = scrapeProductDetails($fetchUrl);
    echo json_encode($scraped, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($apiAction === 'check_single') {
    header('Content-Type: application/json; charset=utf-8');
    $itemId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM price_watch_items WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $itemId, ':uid' => $userId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['ok' => false, 'error' => 'Artikel nicht gefunden.']);
        exit;
    }

    $res = scrapeProductDetails((string)$item['url']);
    $newPrice = $res['price'];

    if ($newPrice !== null && $newPrice > 0) {
        $oldPrice    = $item['current_price'] !== null ? (float)$item['current_price'] : (float)$newPrice;
        $lowestPrice = $item['lowest_price'] !== null ? min((float)$item['lowest_price'], $newPrice) : $newPrice;
        $highestPrice= $item['highest_price'] !== null ? max((float)$item['highest_price'], $newPrice) : $newPrice;
        $targetPrice = $item['target_price'] !== null ? (float)$item['target_price'] : null;

        $hasChanged = (abs($oldPrice - $newPrice) >= 0.01);

        // Update DB
        $stmtUp = $pdo->prepare('UPDATE price_watch_items SET
            current_price = :cur,
            lowest_price = :low,
            highest_price = :high,
            last_checked_at = NOW(),
            last_price_change_at = IF(:changed = 1, NOW(), last_price_change_at)
            WHERE id = :id AND user_id = :uid');
        $stmtUp->execute([
            ':cur'     => $newPrice,
            ':low'     => $lowestPrice,
            ':high'    => $highestPrice,
            ':changed' => $hasChanged ? 1 : 0,
            ':id'      => $itemId,
            ':uid'     => $userId
        ]);

        // Historie eintragen (höchstens 1x alle paar Stunden bei gleichem Preis)
        $pdo->prepare('INSERT INTO price_watch_history (item_id, price, recorded_at) VALUES (:iid, :p, NOW())')
            ->execute([':iid' => $itemId, ':p' => $newPrice]);

        // Alarme prüfen
        $alertTriggered = false;
        if ($targetPrice !== null && $newPrice <= $targetPrice && ((int)$item['notify_target'] === 1)) {
            $msg = '🎯 Wunschpreis erreicht für „' . $item['title'] . '“: ' . number_format($newPrice, 2, ',', '.') . ' € (Ziel: ' . number_format($targetPrice, 2, ',', '.') . ' €)!';
            $pdo->prepare('INSERT INTO price_watch_alerts (item_id, user_id, old_price, new_price, alert_type, message) VALUES (:iid, :uid, :op, :np, "target_reached", :msg)')
                ->execute([':iid' => $itemId, ':uid' => $userId, ':op' => $oldPrice, ':np' => $newPrice, ':msg' => $msg]);
            $alertTriggered = true;
        } elseif ($hasChanged && $newPrice < $oldPrice && ((int)$item['notify_drop'] === 1)) {
            $diff = $oldPrice - $newPrice;
            $msg = '📉 Preis gefallen für „' . $item['title'] . '“ um ' . number_format($diff, 2, ',', '.') . ' € auf ' . number_format($newPrice, 2, ',', '.') . ' €!';
            $pdo->prepare('INSERT INTO price_watch_alerts (item_id, user_id, old_price, new_price, alert_type, message) VALUES (:iid, :uid, :op, :np, "price_drop", :msg)')
                ->execute([':iid' => $itemId, ':uid' => $userId, ':op' => $oldPrice, ':np' => $newPrice, ':msg' => $msg]);
            $alertTriggered = true;
        }

        echo json_encode([
            'ok'            => true,
            'item_id'       => $itemId,
            'old_price'     => $oldPrice,
            'new_price'     => $newPrice,
            'lowest_price'  => $lowestPrice,
            'has_changed'   => $hasChanged,
            'target_reached'=> ($targetPrice !== null && $newPrice <= $targetPrice),
            'last_checked'  => 'Gerade eben'
        ]);
        exit;
    } else {
        // Nur Datum aktualisieren
        $pdo->prepare('UPDATE price_watch_items SET last_checked_at = NOW() WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $itemId, ':uid' => $userId]);

        echo json_encode([
            'ok'           => false,
            'item_id'      => $itemId,
            'error'        => 'Preis konnte nicht automatisch ausgelesen werden. Bitte prüfe die Shop-Seite.',
            'last_checked' => 'Gerade eben'
        ]);
        exit;
    }
}

if ($apiAction === 'get_history') {
    header('Content-Type: application/json; charset=utf-8');
    $itemId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT price, recorded_at FROM price_watch_history WHERE item_id = :iid ORDER BY recorded_at ASC');
    $stmt->execute([':iid' => $itemId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'history' => $history]);
    exit;
}

// ============================================================================
// 4. POST-AKTIONEN (CRUD)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        setFlash('error', 'Ungültiges Sicherheitstoken (CSRF).');
        header('Location: deals.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // ------------------------------------------------------------------------
    // AKTION: Neues Produkt zur Überwachung hinzufügen
    // ------------------------------------------------------------------------
    if ($action === 'add_item') {
        $url         = trim((string)($_POST['url'] ?? ''));
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $category    = mb_substr(trim((string)($_POST['category'] ?? 'Allgemein')), 0, 60);
        $targetPrice = parsePriceString((string)($_POST['target_price'] ?? ''));
        $currentPrice= parsePriceString((string)($_POST['current_price'] ?? ''));
        $imageUrl    = trim((string)($_POST['image_url'] ?? ''));
        $notes       = trim((string)($_POST['notes'] ?? ''));
        $priority    = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium';
        $notifyTarget= isset($_POST['notify_target']) ? 1 : 0;
        $notifyDrop  = isset($_POST['notify_drop']) ? 1 : 0;
        $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;

        if ($url === '') {
            setFlash('error', 'Bitte gib eine gültige Produkt-URL ein.');
            header('Location: deals.php');
            exit;
        }

        $cleanedInput = cleanProductInput($url);
        $url = $cleanedInput['url'];
        if ($title === '' && !empty($cleanedInput['title_hint'])) {
            $title = $cleanedInput['title_hint'];
        }
        $domain = $cleanedInput['domain'] ?: 'unbekannt';

        // Falls noch kein Preis oder Titel eingegeben wurde: live scrapen
        if ($title === '' || $currentPrice === null) {
            $scraped = scrapeProductDetails($url);
            if (!empty($scraped['url'])) {
                $url = $scraped['url'];
            }
            if ($title === '' && !empty($scraped['title'])) {
                $title = $scraped['title'];
            }
            if ($currentPrice === null && $scraped['price'] !== null) {
                $currentPrice = $scraped['price'];
            }
            if ($imageUrl === '' && !empty($scraped['image_url'])) {
                $imageUrl = $scraped['image_url'];
            }
        }

        if ($title === '') {
            $title = $domain . ' Produkt (' . date('d.m.Y') . ')';
        }

        $lowestPrice  = $currentPrice;
        $highestPrice = $currentPrice;

        $stmtIns = $pdo->prepare('INSERT INTO price_watch_items
            (user_id, title, url, domain, image_url, category, current_price, initial_price, target_price, lowest_price, highest_price,
             notify_target, notify_drop, notify_email, notes, priority, last_checked_at)
            VALUES
            (:uid, :title, :url, :domain, :img, :cat, :cur, :init, :tgt, :low, :high,
             :nt, :nd, :ne, :notes, :prio, NOW())');
        $stmtIns->execute([
            ':uid'    => $userId,
            ':title'  => $title,
            ':url'    => $url,
            ':domain' => $domain,
            ':img'    => $imageUrl ?: null,
            ':cat'    => $category,
            ':cur'    => $currentPrice,
            ':init'   => $currentPrice,
            ':tgt'    => $targetPrice,
            ':low'    => $lowestPrice,
            ':high'   => $highestPrice,
            ':nt'     => $notifyTarget,
            ':nd'     => $notifyDrop,
            ':ne'     => $notifyEmail,
            ':notes'  => $notes ?: null,
            ':prio'   => $priority,
        ]);
        $newId = (int)$pdo->lastInsertId();

        if ($currentPrice !== null) {
            $pdo->prepare('INSERT INTO price_watch_history (item_id, price, recorded_at) VALUES (:iid, :p, NOW())')
                ->execute([':iid' => $newId, ':p' => $currentPrice]);
        }

        setFlash('success', 'Produkt „' . $title . '“ wird ab sofort überwacht!');
        header('Location: deals.php');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Produkt bearbeiten
    // ------------------------------------------------------------------------
    if ($action === 'edit_item') {
        $itemId      = (int)($_POST['item_id'] ?? 0);
        $title       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
        $category    = mb_substr(trim((string)($_POST['category'] ?? 'Allgemein')), 0, 60);
        $targetPrice = parsePriceString((string)($_POST['target_price'] ?? ''));
        $manualPrice = parsePriceString((string)($_POST['manual_price'] ?? ''));
        $notes       = trim((string)($_POST['notes'] ?? ''));
        $priority    = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['priority'] : 'medium';
        $notifyTarget= isset($_POST['notify_target']) ? 1 : 0;
        $notifyDrop  = isset($_POST['notify_drop']) ? 1 : 0;
        $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;

        $stmt = $pdo->prepare('SELECT * FROM price_watch_items WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $itemId, ':uid' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $curP = $existing['current_price'];
            $lowP = $existing['lowest_price'];
            $highP= $existing['highest_price'];

            // Manuelle Preiskorrektur falls angegeben
            if ($manualPrice !== null && $manualPrice > 0) {
                $curP = $manualPrice;
                $lowP = $lowP !== null ? min((float)$lowP, $manualPrice) : $manualPrice;
                $highP= $highP !== null ? max((float)$highP, $manualPrice) : $manualPrice;

                $pdo->prepare('INSERT INTO price_watch_history (item_id, price, recorded_at) VALUES (:iid, :p, NOW())')
                    ->execute([':iid' => $itemId, ':p' => $manualPrice]);
            }

            $stmtUp = $pdo->prepare('UPDATE price_watch_items SET
                title = :title,
                category = :cat,
                target_price = :tgt,
                current_price = :cur,
                lowest_price = :low,
                highest_price = :high,
                notify_target = :nt,
                notify_drop = :nd,
                notify_email = :ne,
                notes = :notes,
                priority = :prio
                WHERE id = :id AND user_id = :uid');
            $stmtUp->execute([
                ':title' => $title !== '' ? $title : $existing['title'],
                ':cat'   => $category,
                ':tgt'   => $targetPrice,
                ':cur'   => $curP,
                ':low'   => $lowP,
                ':high'  => $highP,
                ':nt'    => $notifyTarget,
                ':nd'    => $notifyDrop,
                ':ne'    => $notifyEmail,
                ':notes' => $notes ?: null,
                ':prio'  => $priority,
                ':id'    => $itemId,
                ':uid'   => $userId
            ]);

            setFlash('success', 'Produkt-Einstellungen gespeichert.');
        }

        header('Location: deals.php');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Produkt löschen
    // ------------------------------------------------------------------------
    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare('DELETE FROM price_watch_items WHERE id = :id AND user_id = :uid')->execute([':id' => $itemId, ':uid' => $userId]);
        $pdo->prepare('DELETE FROM price_watch_history WHERE item_id = :id')->execute([':id' => $itemId]);
        $pdo->prepare('DELETE FROM price_watch_alerts WHERE item_id = :id AND user_id = :uid')->execute([':id' => $itemId, ':uid' => $userId]);
        setFlash('success', 'Produkt aus der Preisüberwachung entfernt.');
        header('Location: deals.php');
        exit;
    }

    // ------------------------------------------------------------------------
    // AKTION: Alle Alarme als gelesen markieren
    // ------------------------------------------------------------------------
    if ($action === 'clear_alerts') {
        $pdo->prepare('UPDATE price_watch_alerts SET is_read = 1 WHERE user_id = :uid')->execute([':uid' => $userId]);
        setFlash('success', 'Alle Preisalarme als erledigt markiert.');
        header('Location: deals.php');
        exit;
    }
}

// ============================================================================
// 5. DATEN FÜR DAS DASHBOARD LADEN
// ============================================================================

// Filter & Sortierung
$filterCat    = trim((string)($_GET['cat'] ?? 'all'));
$filterStatus = trim((string)($_GET['status'] ?? 'all'));
$searchQuery  = trim((string)($_GET['q'] ?? ''));
$sortMode     = trim((string)($_GET['sort'] ?? 'newest'));

$sql = 'SELECT * FROM price_watch_items WHERE user_id = :uid AND is_archived = 0';
$params = [':uid' => $userId];

if ($filterCat !== 'all' && $filterCat !== '') {
    $sql .= ' AND category = :cat';
    $params[':cat'] = $filterCat;
}

if ($filterStatus === 'target_reached') {
    $sql .= ' AND target_price IS NOT NULL AND current_price IS NOT NULL AND current_price <= target_price';
} elseif ($filterStatus === 'dropped') {
    $sql .= ' AND initial_price IS NOT NULL AND current_price IS NOT NULL AND current_price < initial_price';
} elseif ($filterStatus === 'lowest') {
    $sql .= ' AND lowest_price IS NOT NULL AND current_price IS NOT NULL AND current_price <= lowest_price';
}

if ($searchQuery !== '') {
    $sql .= ' AND (title LIKE :q OR domain LIKE :q OR notes LIKE :q)';
    $params[':q'] = '%' . $searchQuery . '%';
}

switch ($sortMode) {
    case 'savings_desc':
        $sql .= ' ORDER BY (initial_price - current_price) DESC, id DESC';
        break;
    case 'discount_desc':
        $sql .= ' ORDER BY ((initial_price - current_price) / initial_price) DESC, id DESC';
        break;
    case 'price_asc':
        $sql .= ' ORDER BY current_price ASC, id DESC';
        break;
    case 'price_desc':
        $sql .= ' ORDER BY current_price DESC, id DESC';
        break;
    case 'newest':
    default:
        $sql .= ' ORDER BY id DESC';
        break;
}

$stmtItems = $pdo->prepare($sql);
$stmtItems->execute($params);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Statistiken berechnen
$totalItems       = count($items);
$targetMetCount   = 0;
$totalSavings     = 0.0;
$totalWatchlistVal= 0.0;

foreach ($items as $it) {
    $cur  = $it['current_price'] !== null ? (float)$it['current_price'] : 0.0;
    $init = $it['initial_price'] !== null ? (float)$it['initial_price'] : $cur;
    $tgt  = $it['target_price'] !== null ? (float)$it['target_price'] : null;

    $totalWatchlistVal += $cur;
    if ($tgt !== null && $cur > 0 && $cur <= $tgt) {
        $targetMetCount++;
    }
    if ($init > $cur && $cur > 0) {
        $totalSavings += ($init - $cur);
    }
}

// Kategorien-Liste ermitteln
$stmtCats = $pdo->prepare('SELECT DISTINCT category FROM price_watch_items WHERE user_id = :uid AND category != "" ORDER BY category ASC');
$stmtCats->execute([':uid' => $userId]);
$categories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
if (empty($categories)) {
    $categories = ['Allgemein', 'Technik', 'Haushalt', 'Gaming', 'Geschenke', 'Werkzeug'];
}

// Ungelesene Alarme laden
$stmtAlerts = $pdo->prepare('SELECT a.*, i.title, i.domain FROM price_watch_alerts a
    JOIN price_watch_items i ON a.item_id = i.id
    WHERE a.user_id = :uid AND a.is_read = 0 ORDER BY a.created_at DESC LIMIT 10');
$stmtAlerts->execute([':uid' => $userId]);
$unreadAlerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC);

// Verlaufshistorie für Sparklines (letzte 10 Punkte pro Artikel)
$itemHistories = [];
if (!empty($items)) {
    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmtH = $pdo->prepare("SELECT item_id, price, recorded_at FROM price_watch_history WHERE item_id IN ($placeholders) ORDER BY recorded_at ASC");
    $stmtH->execute($itemIds);
    while ($row = $stmtH->fetch(PDO::FETCH_ASSOC)) {
        $itemHistories[(int)$row['item_id']][] = (float)$row['price'];
    }
}

// Bookmarklet URL für aktuellen Server generieren
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$serverHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentPath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$bookmarkletBaseUrl = $protocol . $serverHost . $currentPath . '/deals.php?';
$bookmarkletTargetUrl = $bookmarkletBaseUrl . 'add_url=';

$pageTitle = 'Preiswächter & Deal-Radar';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">

    <!-- ====================================================================== -->
    <!-- 1. KOPFZEILE & STATS-COCKPIT                                           -->
    <!-- ====================================================================== -->
    <div class="mc-card p-5 sm:p-6 rounded-3xl bg-gradient-to-br from-amber-600 via-orange-600 to-rose-600 text-white shadow-xl relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 backdrop-blur-xs text-xs font-bold uppercase tracking-wider text-amber-100">
                    <span>🏷️</span> Deal-Radar &amp; Wunschpreis-Alarm
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">
                    Preiswächter
                </h1>
                <p class="text-xs sm:text-sm text-amber-100 max-w-xl">
                    100 % private Überwachung für Amazon, Idealo, Geizhals &amp; alle Online-Shops mit automatischem Preisalarm und Allzeittief-Erkennung.
                </p>
            </div>

            <!-- Schnell-Aktionen -->
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" onclick="openAddModal()"
                        class="px-4 py-2.5 rounded-2xl bg-white text-slate-900 font-bold text-xs sm:text-sm shadow-md hover:bg-amber-50 transition cursor-pointer flex items-center gap-1.5">
                    ➕ Produkt überwachen
                </button>
                <button type="button" onclick="checkAllDealsBatch()" id="btnCheckAllDeals"
                        class="px-3.5 py-2.5 rounded-2xl bg-white/15 hover:bg-white/25 text-white font-semibold text-xs border border-white/20 transition cursor-pointer flex items-center gap-1.5"
                        title="Alle Preise jetzt sofort online prüfen">
                    🔄 <span class="hidden sm:inline">Alle prüfen</span>
                </button>
                <button type="button" onclick="openBookmarkletModal()"
                        class="px-3.5 py-2.5 rounded-2xl bg-white/15 hover:bg-white/25 text-white font-semibold text-xs border border-white/20 transition cursor-pointer flex items-center gap-1.5"
                        title="1-Klick-Browser-Bookmarklet">
                    📌 <span class="hidden sm:inline">Bookmarklet</span>
                </button>
            </div>
        </div>

        <!-- KPI-Karten -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-6 pt-5 border-t border-white/20 text-xs">
            <div class="p-3 rounded-2xl bg-white/10 backdrop-blur-xs border border-white/10">
                <div class="text-white/70 text-[11px] font-medium">Beobachtete Artikel</div>
                <div class="text-xl sm:text-2xl font-black mt-0.5"><?= $totalItems ?></div>
            </div>
            <div class="p-3 rounded-2xl bg-white/10 backdrop-blur-xs border border-white/10">
                <div class="text-white/70 text-[11px] font-medium">🎯 Wunschpreis erreicht</div>
                <div class="text-xl sm:text-2xl font-black mt-0.5 <?= $targetMetCount > 0 ? 'text-amber-200 animate-pulse' : '' ?>">
                    <?= $targetMetCount ?>
                </div>
            </div>
            <div class="p-3 rounded-2xl bg-white/10 backdrop-blur-xs border border-white/10">
                <div class="text-white/70 text-[11px] font-medium">Gesamtersparnis</div>
                <div class="text-xl sm:text-2xl font-black mt-0.5 text-emerald-200">
                    <?= number_format($totalSavings, 2, ',', '.') ?> €
                </div>
            </div>
            <div class="p-3 rounded-2xl bg-white/10 backdrop-blur-xs border border-white/10">
                <div class="text-white/70 text-[11px] font-medium">Watchlist-Wert</div>
                <div class="text-xl sm:text-2xl font-black mt-0.5">
                    <?= number_format($totalWatchlistVal, 2, ',', '.') ?> €
                </div>
            </div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- 2. UNGELESENE ALARME & DEAL-BENACHRICHTIGUNGEN                          -->
    <!-- ====================================================================== -->
    <?php if (!empty($unreadAlerts)): ?>
        <div class="mc-card p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 shadow-sm space-y-2">
            <div class="flex items-center justify-between">
                <h3 class="text-xs sm:text-sm font-bold text-amber-900 dark:text-amber-200 flex items-center gap-1.5">
                    <span>🔔</span> Neueste Preis-Alarme (<?= count($unreadAlerts) ?>)
                </h3>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="clear_alerts">
                    <button type="submit" class="text-[11px] font-semibold text-amber-700 dark:text-amber-300 hover:underline cursor-pointer">
                        Als gelesen markieren &times;
                    </button>
                </form>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                <?php foreach ($unreadAlerts as $alt): ?>
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-900/60 flex items-start gap-2">
                        <span class="text-base shrink-0"><?= $alt['alert_type'] === 'target_reached' ? '🎯' : '📉' ?></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-slate-900 dark:text-white truncate"><?= e($alt['title']) ?></p>
                            <p class="text-[11px] text-amber-800 dark:text-amber-300"><?= e($alt['message']) ?></p>
                            <span class="text-[10px] text-slate-400"><?= date('d.m.Y H:i', strtotime($alt['created_at'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ====================================================================== -->
    <!-- 3. STEUER- & FILTERLEISTE                                              -->
    <!-- ====================================================================== -->
    <div class="mc-card p-4 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs space-y-3">
        <form method="get" class="flex flex-wrap items-center justify-between gap-3 text-xs">
            <!-- Suche -->
            <div class="relative flex-1 min-w-[200px]">
                <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Artikel oder Shop suchen…"
                       class="w-full pl-8 pr-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs">
                <span class="absolute left-2.5 top-2.5 text-slate-400">🔍</span>
            </div>

            <!-- Status-Filter -->
            <select name="status" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold cursor-pointer">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Alle Status</option>
                <option value="target_reached" <?= $filterStatus === 'target_reached' ? 'selected' : '' ?>>🎯 Wunschpreis erreicht</option>
                <option value="dropped" <?= $filterStatus === 'dropped' ? 'selected' : '' ?>>📉 Preis gefallen</option>
                <option value="lowest" <?= $filterStatus === 'lowest' ? 'selected' : '' ?>>🟢 Allzeittief</option>
            </select>

            <!-- Kategorie -->
            <select name="cat" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold cursor-pointer">
                <option value="all" <?= $filterCat === 'all' ? 'selected' : '' ?>>Alle Kategorien</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Sortierung -->
            <select name="sort" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold cursor-pointer">
                <option value="newest" <?= $sortMode === 'newest' ? 'selected' : '' ?>>Neueste zuerst</option>
                <option value="savings_desc" <?= $sortMode === 'savings_desc' ? 'selected' : '' ?>>Größte Ersparnis (€)</option>
                <option value="discount_desc" <?= $sortMode === 'discount_desc' ? 'selected' : '' ?>>Größter Rabatt (%)</option>
                <option value="price_asc" <?= $sortMode === 'price_asc' ? 'selected' : '' ?>>Günstigste zuerst</option>
                <option value="price_desc" <?= $sortMode === 'price_desc' ? 'selected' : '' ?>>Teuerste zuerst</option>
            </select>

            <?php if ($filterCat !== 'all' || $filterStatus !== 'all' || $searchQuery !== '' || $sortMode !== 'newest'): ?>
                <a href="deals.php" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white font-semibold">
                    ✕ Reset
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Batch-Fortschrittsbalken (erscheint bei "Alle prüfen") -->
    <div id="batchProgressContainer" class="hidden mc-card p-3 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800">
        <div class="flex items-center justify-between text-xs font-bold text-amber-900 dark:text-amber-200 mb-1.5">
            <span id="batchProgressText">Prüfe Online-Preise...</span>
            <span id="batchProgressPct">0%</span>
        </div>
        <div class="w-full bg-amber-200 dark:bg-amber-900 rounded-full h-2 overflow-hidden">
            <div id="batchProgressBar" class="bg-amber-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
    </div>

    <!-- ====================================================================== -->
    <!-- 4. PRODUKT-KARTEN GRID                                                 -->
    <!-- ====================================================================== -->
    <?php if (empty($items)): ?>
        <div class="mc-card p-12 text-center rounded-3xl border border-slate-200 dark:border-slate-800 space-y-4">
            <div class="text-5xl">🏷️</div>
            <div class="max-w-md mx-auto space-y-1">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">Keine überwachten Artikel gefunden</h3>
                <p class="text-xs text-slate-500">
                    <?= ($filterCat !== 'all' || $filterStatus !== 'all' || $searchQuery !== '') ? 'Keine Treffer für diese Filtereinstellungen.' : 'Füge jetzt dein erstes Wunschprodukt mit URL ein, um Preissenkungen zu beobachten!' ?>
                </p>
            </div>
            <button type="button" onclick="openAddModal()" class="px-4 py-2 rounded-2xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md cursor-pointer">
                ➕ Erstes Produkt anlegen
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <?php foreach ($items as $item):
                $itemId     = (int)$item['id'];
                $curPrice   = $item['current_price'] !== null ? (float)$item['current_price'] : null;
                $initPrice  = $item['initial_price'] !== null ? (float)$item['initial_price'] : null;
                $tgtPrice   = $item['target_price'] !== null ? (float)$item['target_price'] : null;
                $lowPrice   = $item['lowest_price'] !== null ? (float)$item['lowest_price'] : null;
                $highPrice  = $item['highest_price'] !== null ? (float)$item['highest_price'] : null;

                $targetMet  = ($tgtPrice !== null && $curPrice !== null && $curPrice <= $tgtPrice);
                $isAllTimeLow = ($curPrice !== null && $lowPrice !== null && $curPrice <= $lowPrice && count($itemHistories[$itemId] ?? []) > 1);

                $diffVal = ($initPrice !== null && $curPrice !== null) ? ($curPrice - $initPrice) : 0.0;
                $diffPct = ($initPrice !== null && $initPrice > 0 && $curPrice !== null) ? round(($diffVal / $initPrice) * 100, 1) : 0.0;

                $historyPoints = $itemHistories[$itemId] ?? [];
            ?>
                <div class="mc-card rounded-3xl bg-white dark:bg-slate-900 border <?= $targetMet ? 'border-2 border-emerald-500 dark:border-emerald-500 shadow-emerald-500/10 shadow-lg' : 'border-slate-200/80 dark:border-slate-800' ?> flex flex-col justify-between overflow-hidden p-4 space-y-3.5 transition hover:shadow-md relative group">

                    <!-- Badge oben rechts -->
                    <div class="flex items-center justify-between gap-1 text-[11px]">
                        <span class="px-2 py-0.5 rounded-full font-bold uppercase text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 flex items-center gap-1">
                            <span>🌐</span> <?= e($item['domain'] ?: 'Shop') ?>
                        </span>

                        <?php if ($targetMet): ?>
                            <span class="px-2 py-0.5 rounded-full font-bold text-[10px] bg-emerald-600 text-white shadow-2xs animate-pulse">
                                🎯 WUNSCHPREIS!
                            </span>
                        <?php elseif ($isAllTimeLow): ?>
                            <span class="px-2 py-0.5 rounded-full font-bold text-[10px] bg-teal-600 text-white shadow-2xs">
                                🟢 Tiefstpreis
                            </span>
                        <?php elseif ($diffVal < 0): ?>
                            <span class="px-2 py-0.5 rounded-full font-bold text-[10px] bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                                📉 <?= number_format($diffPct, 1, ',', '.') ?> %
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Bild & Titel -->
                    <div class="flex items-start gap-3">
                        <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden shrink-0 flex items-center justify-center text-2xl relative">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= e($item['image_url']) ?>" alt="" class="w-full h-full object-contain p-1" onerror="this.onerror=null; this.parentElement.innerHTML='📦';">
                            <?php else: ?>
                                <span>📦</span>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block truncate">
                                <?= e($item['category']) ?>
                            </span>
                            <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer"
                               class="font-bold text-xs text-slate-900 dark:text-white hover:text-amber-600 dark:hover:text-amber-400 transition line-clamp-2"
                               title="<?= e($item['title']) ?> (In neuem Tab öffnen)">
                                <?= e($item['title']) ?>
                            </a>
                        </div>
                    </div>

                    <!-- Preisbereich -->
                    <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                        <div class="flex items-baseline justify-between">
                            <div>
                                <span class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                                    <?= $curPrice !== null ? number_format($curPrice, 2, ',', '.') . ' €' : 'Preis n/a' ?>
                                </span>
                            </div>
                            <?php if ($initPrice !== null && $curPrice !== null && abs($diffVal) >= 0.01): ?>
                                <span class="text-xs font-bold <?= $diffVal < 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500' ?>">
                                    <?= $diffVal < 0 ? '-' : '+' ?><?= number_format(abs($diffVal), 2, ',', '.') ?> €
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Wunschpreis Info -->
                        <?php if ($tgtPrice !== null): ?>
                            <div class="text-[11px] flex items-center justify-between text-slate-500 dark:text-slate-400 pt-1 border-t border-slate-200/60 dark:border-slate-700/60">
                                <span>🎯 Ziel: <strong><?= number_format($tgtPrice, 2, ',', '.') ?> €</strong></span>
                                <?php if ($curPrice !== null): ?>
                                    <?php if ($curPrice <= $tgtPrice): ?>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-bold">Erreicht!</span>
                                    <?php else: ?>
                                        <span>Noch <?= number_format($curPrice - $tgtPrice, 2, ',', '.') ?> €</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Mini-Sparkline Preischart (SVG) -->
                    <?php if (count($historyPoints) >= 2):
                        $minP = min($historyPoints);
                        $maxP = max($historyPoints);
                        $pRange = max(0.01, $maxP - $minP);
                        $svgWidth = 240;
                        $svgHeight = 36;
                        $coords = [];
                        $step = (count($historyPoints) > 1) ? ($svgWidth / (count($historyPoints) - 1)) : 0;
                        foreach ($historyPoints as $idx => $hp) {
                            $x = round($idx * $step, 1);
                            $y = round($svgHeight - (($hp - $minP) / $pRange * ($svgHeight - 8)) - 4, 1);
                            $coords[] = "{$x},{$y}";
                        }
                        $polyPoints = implode(' ', $coords);
                    ?>
                        <div class="h-9 relative cursor-pointer" onclick="openHistoryModal(<?= $itemId ?>, '<?= e(addslashes($item['title'])) ?>')" title="Preishistorie anzeigen (Klicken für Details)">
                            <svg viewBox="0 0 <?= $svgWidth ?> <?= $svgHeight ?>" class="w-full h-full overflow-visible">
                                <polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                          class="<?= $targetMet ? 'text-emerald-500' : 'text-amber-500' ?>"
                                          points="<?= $polyPoints ?>" />
                            </svg>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($item['notes'])): ?>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 italic line-clamp-1">
                            💬 <?= e($item['notes']) ?>
                        </p>
                    <?php endif; ?>

                    <!-- Fußzeile mit Aktionen -->
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-400">
                        <span class="text-[10px]" id="checkLabel-<?= $itemId ?>">
                            <?= !empty($item['last_checked_at']) ? 'Geprüft: ' . date('d.m. H:i', strtotime($item['last_checked_at'])) : 'Noch nicht geprüft' ?>
                        </span>

                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="checkSingleDeal(<?= $itemId ?>, this)"
                                    class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
                                    title="Diesen Preis jetzt aktualisieren">
                                🔄
                            </button>
                            <button type="button" onclick="openHistoryModal(<?= $itemId ?>, '<?= e(addslashes($item['title'])) ?>')"
                                    class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
                                    title="Preischart &amp; Verlauf">
                                📊
                            </button>
                            <button type="button" onclick='openEditModal(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
                                    title="Wunschpreis &amp; Notiz anpassen">
                                ✏️
                            </button>
                            <form method="post" onsubmit="return confirm('Möchtest du diesen Artikel wirklich aus der Preisüberwachung löschen?');" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                <button type="submit" class="p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/40 text-slate-400 hover:text-rose-600 transition cursor-pointer" title="Löschen">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ========================================================================== -->
<!-- MODAL: PRODUKT HINZUFÜGEN                                                  -->
<!-- ========================================================================== -->
<div id="modalAddDeal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[92vh] overflow-y-auto rounded-3xl shadow-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                ➕ Produkt zur Preisüberwachung hinzufügen
            </h3>
            <button type="button" onclick="document.getElementById('modalAddDeal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="post" id="formAddDeal" class="space-y-3.5 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="image_url" id="addInputImage" value="">

            <div>
                <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Produkt-URL (Amazon, Idealo, Geizhals, Otto etc.) *</label>
                <div class="flex gap-1.5">
                    <input type="text" name="url" id="addInputUrl" required placeholder="https://www.amazon.de/dp/... oder Link aus App teilen"
                           class="flex-1 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                    <button type="button" onclick="fetchUrlPreview()" id="btnFetchUrl"
                            class="px-3.5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold shrink-0 cursor-pointer flex items-center gap-1">
                        <span>🔍</span> <span class="hidden sm:inline">Laden</span>
                    </button>
                </div>
                <p class="text-[11px] text-slate-400 mt-1">Kopiere einfach den Browser-Link oder die App-Freigabe hier hinein.</p>
            </div>

            <!-- Bot-Hinweis / Status-Banner -->
            <div id="addNoticeBox" class="hidden p-3 rounded-2xl bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-[11px] text-amber-800 dark:text-amber-200 flex items-start gap-2.5">
                <span class="text-base shrink-0">🛡️</span>
                <div id="addNoticeContent" class="leading-relaxed"></div>
            </div>

            <!-- Auto-geladene Vorschau -->
            <div id="addPreviewBox" class="hidden p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex items-center gap-3">
                <img id="addPreviewImg" src="" alt="" class="w-12 h-12 object-contain rounded-xl bg-white dark:bg-slate-900 p-1 shrink-0 border border-slate-200 dark:border-slate-700">
                <div class="min-w-0 flex-1">
                    <div id="addPreviewTitle" class="font-bold text-slate-900 dark:text-white truncate"></div>
                    <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-bold" id="addPreviewPrice"></div>
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Produktname / Titel *</label>
                <input type="text" name="title" id="addInputTitle" required placeholder="Wird automatisch geladen oder hier eingeben..."
                       class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Aktueller Preis (€)</label>
                    <input type="text" name="current_price" id="addInputCurrentPrice" placeholder="z. B. 199,99"
                           class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono font-bold">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">🎯 Wunschpreis (€)</label>
                    <input type="text" name="target_price" id="addInputTargetPrice" placeholder="z. B. 175,00"
                           class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Kategorie</label>
                    <input type="text" name="category" id="addInputCategory" list="catList" value="Technik"
                           class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                    <datalist id="catList">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Priorität</label>
                    <select name="priority" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-semibold">
                        <option value="high">🔴 Hoch (Sofortkauf geplant)</option>
                        <option value="medium" selected>🟡 Mittel</option>
                        <option value="low">🟢 Niedrig (Irgendwann)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Persönliche Notiz / Verwendungszweck</label>
                <input type="text" name="notes" placeholder="z. B. Geschenk für Papa zum Geburtstag"
                       class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                <span class="font-bold text-[11px] text-slate-700 dark:text-slate-300 block mb-1">Alarm-Einstellungen:</span>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="notify_target" value="1" checked class="rounded text-amber-600">
                    <span>Alarm auslösen, sobald der Wunschpreis erreicht ist</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="notify_drop" value="1" checked class="rounded text-amber-600">
                    <span>Alarm bei jedem signifikanten Preissturz</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalAddDeal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md cursor-pointer">
                    💾 Artikel überwachen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: PRODUKT BEARBEITEN                                                  -->
<!-- ========================================================================== -->
<div id="modalEditDeal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 max-h-[92vh] overflow-y-auto rounded-3xl shadow-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                ✏️ Produkt-Einstellungen bearbeiten
            </h3>
            <button type="button" onclick="document.getElementById('modalEditDeal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <form method="post" class="space-y-3.5 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="item_id" id="editInputId" value="0">

            <div>
                <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Titel</label>
                <input type="text" name="title" id="editInputTitle" required class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">🎯 Wunschpreis (€)</label>
                    <input type="text" name="target_price" id="editInputTargetPrice" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Manuelle Preiskorrektur (€)</label>
                    <input type="text" name="manual_price" id="editInputManualPrice" placeholder="Nur bei Bedarf überschreiben" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Kategorie</label>
                    <input type="text" name="category" id="editInputCategory" list="catList" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
                </div>
                <div>
                    <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Priorität</label>
                    <select name="priority" id="editInputPriority" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs font-semibold">
                        <option value="high">🔴 Hoch</option>
                        <option value="medium">🟡 Mittel</option>
                        <option value="low">🟢 Niedrig</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold mb-1 text-slate-700 dark:text-slate-200">Notiz</label>
                <input type="text" name="notes" id="editInputNotes" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs">
            </div>

            <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="notify_target" id="editInputNotifyTarget" value="1" class="rounded text-amber-600">
                    <span>Alarm auslösen, sobald Wunschpreis erreicht ist</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="notify_drop" id="editInputNotifyDrop" value="1" class="rounded text-amber-600">
                    <span>Alarm bei Preissturz</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-200 dark:border-slate-700">
                <button type="button" onclick="document.getElementById('modalEditDeal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">Abbrechen</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs shadow-md cursor-pointer">
                    Speichern
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: PREISVERLAUF & HISTORIE-CHART                                       -->
<!-- ========================================================================== -->
<div id="modalDealHistory" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4">
    <div class="mc-card w-full max-w-2xl p-6 space-y-4 max-h-[92vh] overflow-y-auto rounded-3xl shadow-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <div>
                <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    📈 Preisverlauf &amp; Historie
                </h3>
                <p id="historyItemTitle" class="text-xs text-slate-500 truncate max-w-md"></p>
            </div>
            <button type="button" onclick="document.getElementById('modalDealHistory').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <div id="historyStatsBox" class="grid grid-cols-3 gap-2 text-center text-xs">
            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                <span class="text-slate-400 text-[10px] block">🟢 Allzeittief</span>
                <span id="histMinPrice" class="font-extrabold text-sm text-emerald-600 dark:text-emerald-400">-</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                <span class="text-slate-400 text-[10px] block">⚖️ Durchschnitt</span>
                <span id="histAvgPrice" class="font-extrabold text-sm text-slate-800 dark:text-slate-200">-</span>
            </div>
            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                <span class="text-slate-400 text-[10px] block">🔴 Allzeithoch</span>
                <span id="histMaxPrice" class="font-extrabold text-sm text-rose-500">-</span>
            </div>
        </div>

        <!-- Großer SVG-Chart -->
        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800">
            <div id="historyChartSvgBox" class="h-44 w-full flex items-center justify-center text-slate-400 text-xs">
                Lade Daten...
            </div>
        </div>

        <!-- Verlaufstabelle -->
        <div class="max-h-48 overflow-y-auto rounded-2xl border border-slate-200 dark:border-slate-800 divide-y divide-slate-100 dark:divide-slate-800 text-xs">
            <div id="historyTableList" class="p-2">
                <!-- Wird per JS befüllt -->
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="button" onclick="document.getElementById('modalDealHistory').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">
                Schließen
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================== -->
<!-- MODAL: BROWSER-BOOKMARKLET                                                 -->
<!-- ========================================================================== -->
<div id="modalBookmarklet" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4">
    <div class="mc-card w-full max-w-lg p-6 space-y-4 rounded-3xl shadow-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                📌 1-Klick-Browser-Bookmarklet
            </h3>
            <button type="button" onclick="document.getElementById('modalBookmarklet').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg">✕</button>
        </div>

        <p class="text-xs text-slate-600 dark:text-slate-300">
            Ziehe diesen Button mit der Maus direkt in deine <strong>Browser-Lesezeichenleiste</strong>:
        </p>

        <div class="p-6 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-center">
            <a href="javascript:(function(){try{var u=location.href,t='',p='',img='';var tEl=document.querySelector('#productTitle')||document.querySelector('h1')||document.querySelector('meta[property=\'og:title\']');if(tEl)t=(tEl.innerText||tEl.content||'').trim();if(!t)t=document.title;var pEl=document.querySelector('.a-price .a-offscreen')||document.querySelector('#priceblock_ourprice')||document.querySelector('.apexPriceToPay .a-offscreen')||document.querySelector('[class*=\'oopStage-price\']')||document.querySelector('.gh_price')||document.querySelector('[itemprop=\'price\']')||document.querySelector('meta[property=\'og:price:amount\']');if(pEl)p=(pEl.innerText||pEl.content||pEl.value||'').trim();var iEl=document.querySelector('#landingImage')||document.querySelector('#imgBlkFront')||document.querySelector('meta[property=\'og:image\']');if(iEl)img=(iEl.src||iEl.content||'').trim();var dest='<?= e($bookmarkletBaseUrl) ?>add_url='+encodeURIComponent(u)+'&title='+encodeURIComponent(t)+'&price='+encodeURIComponent(p)+'&image='+encodeURIComponent(img);window.open(dest,'_blank');}catch(e){window.open('<?= e($bookmarkletBaseUrl) ?>add_url='+encodeURIComponent(location.href),'_blank');}})();"
               onclick="alert('Tipp: Ziehe diesen Button mit der linken Maustaste in deine Browser-Lesezeichenleiste oben!'); return false;"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-amber-600 hover:bg-amber-500 text-white font-extrabold text-xs shadow-md transition cursor-grab active:cursor-grabbing">
                <span>🏷️</span> Zu MiniCloud Preiswächter
            </a>
            <p class="text-[11px] text-slate-400 mt-2">← Diesen Button einfach per Drag &amp; Drop in die Lesezeichenleiste ziehen!</p>
        </div>

        <div class="text-xs text-slate-500 dark:text-slate-400 space-y-1.5">
            <p class="font-bold text-slate-800 dark:text-slate-200">So einfach geht's:</p>
            <ol class="list-decimal pl-4 space-y-1">
                <li>Surfe in einem beliebigen Online-Shop (Amazon, Idealo, Mindfactory etc.).</li>
                <li>Klicke auf das Lesezeichen <strong>„🏷️ Zu MiniCloud Preiswächter“</strong>.</li>
                <li>MiniCloud öffnet sich und hat Produktname, Preis und Bild bereits fertig ausgefüllt!</li>
            </ol>
        </div>

        <div class="flex justify-end pt-2 border-t border-slate-200 dark:border-slate-700">
            <button type="button" onclick="document.getElementById('modalBookmarklet').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold text-xs">
                Fertig
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================== -->
<!-- 6. JAVASCRIPT-LOGIK                                                        -->
<!-- ========================================================================== -->
<script>
const ITEMS_TO_CHECK = <?= json_encode(array_column($items, 'id')) ?>;

// Hilfsfunktion: Preis-String zu Float parsen
function parsePriceJs(raw) {
    if (!raw) return null;
    raw = String(raw).trim();
    const clean = raw.replace(/[^0-9.,]/g, '');
    if (!clean) return null;
    if (clean.includes(',') && clean.includes('.')) {
        if (clean.lastIndexOf(',') > clean.lastIndexOf('.')) {
            return parseFloat(clean.replace(/\./g, '').replace(',', '.'));
        } else {
            return parseFloat(clean.replace(/,/g, ''));
        }
    } else if (clean.includes(',')) {
        return parseFloat(clean.replace(',', '.'));
    }
    const val = parseFloat(clean);
    return !isNaN(val) && val > 0 ? Math.round(val * 100) / 100 : null;
}

// Erkennt geteilten Text aus Shopping-Apps (z. B. "Apple MacBook Air ... https://amzn.eu/d/...")
function handlePastedUrl() {
    const input = document.getElementById('addInputUrl');
    if (!input) return;
    const val = input.value.trim();
    if (!val) return;

    const match = val.match(/(https?:\/\/[^\s]+)/i);
    if (match) {
        const foundUrl = match[1];
        const textAround = val.replace(foundUrl, '').trim().replace(/\s+/g, ' ');
        const titleInput = document.getElementById('addInputTitle');
        if (textAround && titleInput && !titleInput.value.trim()) {
            titleInput.value = textAround;
        }
        input.value = foundUrl;
    }
}

function openAddModal(prefillUrl = '', prefillTitle = '', prefillPrice = '', prefillImage = '') {
    const modal = document.getElementById('modalAddDeal');
    if (!modal) return;
    modal.classList.remove('hidden');

    const noticeBox = document.getElementById('addNoticeBox');
    if (noticeBox) noticeBox.classList.add('hidden');

    if (prefillUrl) {
        document.getElementById('addInputUrl').value = prefillUrl;
        handlePastedUrl();
    }
    if (prefillTitle) {
        document.getElementById('addInputTitle').value = prefillTitle;
        document.getElementById('addPreviewTitle').textContent = prefillTitle;
    }
    if (prefillPrice) {
        const p = parsePriceJs(prefillPrice);
        if (p !== null) {
            document.getElementById('addInputCurrentPrice').value = p.toFixed(2).replace('.', ',');
            const suggestedTarget = Math.round((p * 0.90) * 100) / 100;
            document.getElementById('addInputTargetPrice').value = suggestedTarget.toFixed(2).replace('.', ',');
            document.getElementById('addPreviewPrice').textContent = p.toFixed(2).replace('.', ',') + ' €';
        }
    }
    if (prefillImage) {
        document.getElementById('addInputImage').value = prefillImage;
        document.getElementById('addPreviewImg').src = prefillImage;
    }
    if (prefillTitle || prefillPrice || prefillImage) {
        document.getElementById('addPreviewBox')?.classList.remove('hidden');
    }

    // Nur fetchen, falls URL vorhanden aber noch kein Preis (z. B. Bookmarklet ohne DOM-Preis)
    if (prefillUrl && !prefillPrice) {
        fetchUrlPreview();
    }
}

function openEditModal(item) {
    if (!item) return;
    document.getElementById('editInputId').value           = item.id;
    document.getElementById('editInputTitle').value        = item.title || '';
    document.getElementById('editInputCategory').value     = item.category || 'Allgemein';
    document.getElementById('editInputTargetPrice').value  = item.target_price ? Number(item.target_price).toFixed(2).replace('.', ',') : '';
    document.getElementById('editInputManualPrice').value  = '';
    document.getElementById('editInputNotes').value        = item.notes || '';
    document.getElementById('editInputPriority').value     = item.priority || 'medium';
    document.getElementById('editInputNotifyTarget').checked = (item.notify_target == 1);
    document.getElementById('editInputNotifyDrop').checked   = (item.notify_drop == 1);
    document.getElementById('modalEditDeal').classList.remove('hidden');
}

function openBookmarkletModal() {
    document.getElementById('modalBookmarklet').classList.remove('hidden');
}

// URL-Vorschau live abrufen
async function fetchUrlPreview() {
    handlePastedUrl();
    const url = document.getElementById('addInputUrl').value.trim();
    if (!url) return;

    const btn = document.getElementById('btnFetchUrl');
    const origText = btn.innerHTML;
    btn.innerHTML = '<span>⏳</span> Lädt...';
    btn.disabled = true;

    const noticeBox = document.getElementById('addNoticeBox');
    const noticeContent = document.getElementById('addNoticeContent');
    if (noticeBox) noticeBox.classList.add('hidden');

    try {
        const resp = await fetch('deals.php?api=fetch_preview&url=' + encodeURIComponent(url));
        const data = await resp.json();

        if (data && data.ok) {
            if (data.url && data.url !== url) {
                document.getElementById('addInputUrl').value = data.url;
            }
            if (data.title && !document.getElementById('addInputTitle').value.trim()) {
                document.getElementById('addInputTitle').value = data.title;
            }
            if (data.price !== null) {
                const p = Number(data.price);
                document.getElementById('addInputCurrentPrice').value = p.toFixed(2).replace('.', ',');
                const suggestedTarget = Math.round((p * 0.90) * 100) / 100;
                document.getElementById('addInputTargetPrice').value = suggestedTarget.toFixed(2).replace('.', ',');
                document.getElementById('addPreviewPrice').textContent = p.toFixed(2).replace('.', ',') + ' €';
            } else {
                document.getElementById('addPreviewPrice').textContent = 'Preis bitte manuell eintragen';
            }
            if (data.image_url) {
                document.getElementById('addInputImage').value = data.image_url;
                document.getElementById('addPreviewImg').src = data.image_url;
            } else {
                document.getElementById('addPreviewImg').src = '';
            }

            document.getElementById('addPreviewTitle').textContent = data.title || document.getElementById('addInputTitle').value || 'Produkt';
            document.getElementById('addPreviewBox').classList.remove('hidden');

            if (data.bot_blocked || data.price === null) {
                if (noticeBox && noticeContent) {
                    noticeContent.innerHTML = `<strong>Hinweis (${data.shop_name || data.domain || 'Shop'}):</strong> Der Shop blockiert automatisierte Server-Abfragen (HTTP ${data.http_code || '503'} Bot-Schutz). Die Produktdaten wurden übernommen – bitte trage den aktuellen Preis kurz von Hand ein.`;
                    noticeBox.classList.remove('hidden');
                }
                setTimeout(() => document.getElementById('addInputCurrentPrice').focus(), 150);
            }
        } else {
            if (noticeBox && noticeContent) {
                noticeContent.innerHTML = data.error || 'Seite konnte nicht automatisch geladen werden. Bitte trage Titel und Preis manuell ein.';
                noticeBox.classList.remove('hidden');
            }
            setTimeout(() => document.getElementById('addInputTitle').focus(), 150);
        }
    } catch (e) {
        if (noticeBox && noticeContent) {
            noticeContent.innerHTML = 'Netzwerkfehler beim Abrufen der Produktdaten. Bitte trage Titel und Preis manuell ein.';
            noticeBox.classList.remove('hidden');
        }
    } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
    }
}

// Einzelnes Produkt aktualisieren
async function checkSingleDeal(itemId, btnEl) {
    if (!itemId) return;
    if (btnEl) {
        btnEl.classList.add('animate-spin');
        btnEl.disabled = true;
    }

    try {
        const resp = await fetch('deals.php?api=check_single&id=' + itemId);
        const data = await resp.json();

        if (data && data.ok) {
            const lbl = document.getElementById('checkLabel-' + itemId);
            if (lbl) lbl.textContent = 'Geprüft: Gerade eben';
            if (data.has_changed || data.target_reached) {
                window.location.reload();
            }
        } else {
            const lbl = document.getElementById('checkLabel-' + itemId);
            if (lbl) lbl.textContent = 'Geprüft (Bot-Schutz)';
        }
    } catch (e) {
        // Stille Duldung bei Netzwerkfehler
    } finally {
        if (btnEl) {
            btnEl.classList.remove('animate-spin');
            btnEl.disabled = false;
        }
    }
}

// Alle Produkte in der Liste nacheinander prüfen (Batch)
async function checkAllDealsBatch() {
    if (!ITEMS_TO_CHECK || ITEMS_TO_CHECK.length === 0) {
        alert('Keine Artikel zum Prüfen vorhanden.');
        return;
    }

    const container = document.getElementById('batchProgressContainer');
    const bar = document.getElementById('batchProgressBar');
    const txt = document.getElementById('batchProgressText');
    const pct = document.getElementById('batchProgressPct');
    const mainBtn = document.getElementById('btnCheckAllDeals');

    container.classList.remove('hidden');
    mainBtn.disabled = true;

    let completed = 0;
    const total = ITEMS_TO_CHECK.length;

    for (const id of ITEMS_TO_CHECK) {
        txt.textContent = `Prüfe Artikel ${completed + 1} von ${total}...`;
        try {
            await fetch('deals.php?api=check_single&id=' + id);
        } catch (e) {}
        completed++;
        const percent = Math.round((completed / total) * 100);
        bar.style.width = percent + '%';
        pct.textContent = percent + '%';
    }

    txt.textContent = 'Alle Preise erfolgreich aktualisiert!';
    setTimeout(() => {
        window.location.reload();
    }, 800);
}

// Historie-Modal öffnen & Chart zeichnen
async function openHistoryModal(itemId, itemTitle) {
    const modal = document.getElementById('modalDealHistory');
    const titleEl = document.getElementById('historyItemTitle');
    const chartBox = document.getElementById('historyChartSvgBox');
    const tableList = document.getElementById('historyTableList');

    titleEl.textContent = itemTitle || '';
    chartBox.innerHTML = '<div class="text-slate-400 text-xs">Lade Preishistorie...</div>';
    tableList.innerHTML = '';
    modal.classList.remove('hidden');

    try {
        const resp = await fetch('deals.php?api=get_history&id=' + itemId);
        const data = await resp.json();

        if (data && data.ok && Array.isArray(data.history) && data.history.length > 0) {
            const hist = data.history;
            const prices = hist.map(h => Number(h.price));
            const minP = Math.min(...prices);
            const maxP = Math.max(...prices);
            const sumP = prices.reduce((a, b) => a + b, 0);
            const avgP = sumP / prices.length;

            document.getElementById('histMinPrice').textContent = minP.toFixed(2).replace('.', ',') + ' €';
            document.getElementById('histAvgPrice').textContent = avgP.toFixed(2).replace('.', ',') + ' €';
            document.getElementById('histMaxPrice').textContent = maxP.toFixed(2).replace('.', ',') + ' €';

            // SVG-Chart generieren
            const w = 560;
            const h = 150;
            const pRange = Math.max(0.01, maxP - minP);
            const step = (hist.length > 1) ? (w / (hist.length - 1)) : 0;
            const coords = hist.map((pt, idx) => {
                const x = Math.round(idx * step);
                const y = Math.round(h - ((Number(pt.price) - minP) / pRange * (h - 24)) - 12);
                return `${x},${y}`;
            });
            const polyPoints = coords.join(' ');

            chartBox.innerHTML = `
                <svg viewBox="0 0 ${w} ${h}" class="w-full h-full overflow-visible">
                    <defs>
                        <linearGradient id="dealGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f59e0b" stop-opacity="0.3"/>
                            <stop offset="100%" stop-color="#f59e0b" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <polygon fill="url(#dealGrad)" points="0,${h} ${polyPoints} ${w},${h}" />
                    <polyline fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="${polyPoints}" />
                    ${coords.map((pt, i) => {
                        const [cx, cy] = pt.split(',');
                        return `<circle cx="${cx}" cy="${cy}" r="3.5" class="fill-white stroke-amber-500" stroke-width="2"/>`;
                    }).join('')}
                </svg>
            `;

            // Tabelle befüllen (neueste zuerst)
            const revHist = hist.slice().reverse();
            tableList.innerHTML = revHist.map(h => `
                <div class="py-1.5 px-3 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-800">
                    <span class="text-slate-500">${new Date(h.recorded_at).toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'})}</span>
                    <span class="font-mono font-bold text-slate-800 dark:text-slate-100">${Number(h.price).toFixed(2).replace('.', ',')} €</span>
                </div>
            `).join('');
        } else {
            chartBox.innerHTML = '<div class="text-slate-400 text-xs">Noch keine historischen Messpunkte vorhanden.</div>';
        }
    } catch (e) {
        chartBox.innerHTML = '<div class="text-rose-500 text-xs">Fehler beim Laden der Historie.</div>';
    }
}

// Auto-Trigger falls Bookmarklet mit Parametern aufgerufen wurde
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const addUrl = params.get('add_url');
    const title = params.get('title') || '';
    const price = params.get('price') || '';
    const image = params.get('image') || '';
    if (addUrl || title || price) {
        openAddModal(addUrl || '', title, price, image);
    }

    // Auto-Berechnung Wunschpreis (10 % Rabatt-Vorschlag) bei manueller Preiseingabe
    const curPriceInput = document.getElementById('addInputCurrentPrice');
    if (curPriceInput) {
        curPriceInput.addEventListener('input', function() {
            const p = parsePriceJs(this.value);
            const targetInput = document.getElementById('addInputTargetPrice');
            if (p !== null && targetInput && (!targetInput.value.trim() || targetInput.dataset.autofill === '1')) {
                const suggested = Math.round((p * 0.90) * 100) / 100;
                targetInput.value = suggested.toFixed(2).replace('.', ',');
                targetInput.dataset.autofill = '1';
            }
        });
    }

    // Paste- & Change-Listener für URL-Eingabe (erkennt geteilten Text aus Apps)
    const urlInput = document.getElementById('addInputUrl');
    if (urlInput) {
        urlInput.addEventListener('paste', function() {
            setTimeout(handlePastedUrl, 50);
        });
        urlInput.addEventListener('change', handlePastedUrl);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
