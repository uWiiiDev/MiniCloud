<?php
declare(strict_types=1);

/**
 * MiniCloud - 🏷️ Preiswächter CLI- & Cron-Worker (deals_worker.php)
 * Kann manuell oder per Cronjob aufgerufen werden:
 * CLI-Beispiel: php /pfad/zu/deals_worker.php
 * Web-Beispiel: /deals_worker.php?key=... (falls abgesichert)
 */

if (php_sapi_name() !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/includes/auth.php';
    requireLogin();
}

require_once __DIR__ . '/includes/functions.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_deals'] ?? '1') !== '1') {
    echo "Deals module is disabled in settings.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting deals price check worker...\n";

// Alle aktiven Produkte laden, die seit mindestens 4 Stunden nicht geprüft wurden
$stmt = $pdo->prepare('SELECT * FROM price_watch_items
    WHERE is_archived = 0 AND (last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL 4 HOUR))
    ORDER BY last_checked_at ASC LIMIT 30');
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "No items need checking right now.\n";
    exit(0);
}

$checkedCount = 0;
$alertCount = 0;

foreach ($items as $item) {
    $itemId = (int)$item['id'];
    $userId = (int)$item['user_id'];
    $title = $item['title'];
    $url = $item['url'];

    echo "Checking #{$itemId}: {$title}...\n";

    // Scrape
    $res = scrapeProductDetails((string)$url);
    $newPrice = $res['price'];

    if ($newPrice !== null && $newPrice > 0) {
        $oldPrice    = $item['current_price'] !== null ? (float)$item['current_price'] : (float)$newPrice;
        $lowestPrice = $item['lowest_price'] !== null ? min((float)$item['lowest_price'], $newPrice) : $newPrice;
        $highestPrice= $item['highest_price'] !== null ? max((float)$item['highest_price'], $newPrice) : $newPrice;
        $targetPrice = $item['target_price'] !== null ? (float)$item['target_price'] : null;

        $hasChanged = (abs($oldPrice - $newPrice) >= 0.01);

        $stmtUp = $pdo->prepare('UPDATE price_watch_items SET
            current_price = :cur,
            lowest_price = :low,
            highest_price = :high,
            last_checked_at = NOW(),
            last_price_change_at = IF(:changed = 1, NOW(), last_price_change_at)
            WHERE id = :id');
        $stmtUp->execute([
            ':cur'     => $newPrice,
            ':low'     => $lowestPrice,
            ':high'    => $highestPrice,
            ':changed' => $hasChanged ? 1 : 0,
            ':id'      => $itemId
        ]);

        $pdo->prepare('INSERT INTO price_watch_history (item_id, price, recorded_at) VALUES (:iid, :p, NOW())')
            ->execute([':iid' => $itemId, ':p' => $newPrice]);

        // Alarme prüfen
        if ($targetPrice !== null && $newPrice <= $targetPrice && ((int)$item['notify_target'] === 1)) {
            $msg = '🎯 Wunschpreis erreicht für „' . $title . '“: ' . number_format($newPrice, 2, ',', '.') . ' € (Ziel: ' . number_format($targetPrice, 2, ',', '.') . ' €)!';
            $pdo->prepare('INSERT INTO price_watch_alerts (item_id, user_id, old_price, new_price, alert_type, message) VALUES (:iid, :uid, :op, :np, "target_reached", :msg)')
                ->execute([':iid' => $itemId, ':uid' => $userId, ':op' => $oldPrice, ':np' => $newPrice, ':msg' => $msg]);
            $alertCount++;
            echo "  -> Target price alert triggered!\n";

            // E-Mail Benachrichtigung falls gewünscht
            if ((int)$item['notify_email'] === 1) {
                $uStmt = $pdo->prepare('SELECT email FROM users WHERE id = :uid');
                $uStmt->execute([':uid' => $userId]);
                $uEmail = (string)$uStmt->fetchColumn();
                if ($uEmail !== '' && filter_var($uEmail, FILTER_VALIDATE_EMAIL)) {
                    $body = "Hallo,\n\ndein Wunschpreis für das Produkt „{$title}“ wurde erreicht!\n\nNeuer Preis: " . number_format($newPrice, 2, ',', '.') . " €\nDein Wunschpreis: " . number_format($targetPrice, 2, ',', '.') . " €\n\nDirekt zum Angebot: {$url}\n\nViele Grüße,\nDeine MiniCloud";
                    sendSystemEmail($pdo, $uEmail, '🎯 Wunschpreis erreicht: ' . $title, $body);
                }
            }
        } elseif ($hasChanged && $newPrice < $oldPrice && ((int)$item['notify_drop'] === 1)) {
            $diff = $oldPrice - $newPrice;
            $msg = '📉 Preis gefallen für „' . $title . '“ um ' . number_format($diff, 2, ',', '.') . ' € auf ' . number_format($newPrice, 2, ',', '.') . ' €!';
            $pdo->prepare('INSERT INTO price_watch_alerts (item_id, user_id, old_price, new_price, alert_type, message) VALUES (:iid, :uid, :op, :np, "price_drop", :msg)')
                ->execute([':iid' => $itemId, ':uid' => $userId, ':op' => $oldPrice, ':np' => $newPrice, ':msg' => $msg]);
            $alertCount++;
            echo "  -> Price drop alert triggered!\n";
        }

        $checkedCount++;
    } else {
        $pdo->prepare('UPDATE price_watch_items SET last_checked_at = NOW() WHERE id = :id')
            ->execute([':id' => $itemId]);
        echo "  -> Could not parse price, timestamp updated.\n";
    }

    // Kleine Pause gegen Rate Limiting
    usleep(300000); // 0.3s
}

echo "[" . date('Y-m-d H:i:s') . "] Finished. Checked {$checkedCount} items, triggered {$alertCount} alerts.\n";
