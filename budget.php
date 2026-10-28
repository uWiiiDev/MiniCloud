<?php
declare(strict_types=1);

/**
 * MiniCloud - Haushaltsplaner & Finanz-Übersicht (budget.php)
 * Persönliche und geteilte Haushaltsbücher mit Einnahmen & Ausgaben,
 * Fixkosten-/Abo-Verwaltung (inkl. 1-Klick-Monatsbuchung), Kategorie-Auswertung,
 * 6-Monats-Verlauf, Sparzielen, WG-/Familien-Kostenausgleich, Beleg-Verknüpfung & CSV-Export.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'User');
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_budget'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Modul Haushaltsplaner ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Stellt sicher, dass alle Tabellen für den Haushaltsplaner existieren.
 */
function ensureBudgetSchema(PDO $pdo): void
{
    static $done = false;
    if ($done || (!empty($_SESSION['_mc_budget_schema_ok']) && $_SESSION['_mc_budget_schema_ok'] === 1)) {
        $done = true;
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `budget_books` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT '€',
            `monthly_limit` DECIMAL(12,2) NOT NULL DEFAULT 2000.00,
            `shared_users` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_budget_books_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `budget_entries` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `book_id` INT UNSIGNED NOT NULL,
            `user_id` INT NOT NULL,
            `type` VARCHAR(16) NOT NULL DEFAULT 'expense',
            `category` VARCHAR(60) NOT NULL DEFAULT 'Sonstiges',
            `title` VARCHAR(190) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `entry_date` DATE NOT NULL,
            `is_shared_split` TINYINT(1) NOT NULL DEFAULT 1,
            `file_id` INT DEFAULT NULL,
            `note` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_budget_entries_book_date` (`book_id`, `entry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `budget_subscriptions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `book_id` INT UNSIGNED NOT NULL,
            `user_id` INT NOT NULL,
            `type` VARCHAR(16) NOT NULL DEFAULT 'expense',
            `category` VARCHAR(60) NOT NULL DEFAULT 'Fixkosten',
            `title` VARCHAR(190) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly',
            `day_of_month` INT NOT NULL DEFAULT 1,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_budget_subs_book` (`book_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `budget_goals` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `book_id` INT UNSIGNED NOT NULL,
            `user_id` INT NOT NULL,
            `title` VARCHAR(190) NOT NULL,
            `icon` VARCHAR(16) NOT NULL DEFAULT '🐷',
            `target_amount` DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
            `current_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `target_date` DATE DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_budget_goals_book` (`book_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_mc_budget_schema_ok'] = 1;
    }
}

ensureBudgetSchema($pdo);

/**
 * Kategorien-Katalog mit Icons & Farben.
 *
 * @return array<string, array{icon: string, color: string}>
 */
function getBudgetCategories(): array
{
    return [
        'Gehalt & Lohn'     => ['icon' => '💼', 'color' => '#059669'],
        'Nebeneinkünfte'    => ['icon' => '📈', 'color' => '#10b981'],
        'Wohnen & Energie'  => ['icon' => '🏠', 'color' => '#2563eb'],
        'Lebensmittel'      => ['icon' => '🛒', 'color' => '#d97706'],
        'Mobilität & Auto'  => ['icon' => '🚗', 'color' => '#7c3aed'],
        'Verträge & Abos'   => ['icon' => '📱', 'color' => '#0891b2'],
        'Freizeit & Kultur' => ['icon' => '🎭', 'color' => '#db2777'],
        'Gesundheit'        => ['icon' => '🩺', 'color' => '#dc2626'],
        'Shopping & Haus'   => ['icon' => '🛍️', 'color' => '#ea580c'],
        'Sparen & Vorsorge' => ['icon' => '🐷', 'color' => '#0d9488'],
        'Sonstiges'         => ['icon' => '📌', 'color' => '#64748b'],
    ];
}

/**
 * Prüft, ob ein Benutzer Zugriff auf ein Haushaltsbuch hat.
 *
 * @param array<string, mixed> $book
 */
function canAccessBudgetBook(array $book, int $userId): bool
{
    if ((int)$book['user_id'] === $userId) {
        return true;
    }
    $shared = array_filter(array_map('intval', explode(',', (string)($book['shared_users'] ?? ''))));
    return in_array($userId, $shared, true);
}

/**
 * Formatiert einen Geldbetrag im deutschen Format.
 */
function formatMoney(float $amount, string $currency = '€'): string
{
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
}

// --- Alle zugänglichen Haushaltsbücher laden ---
$stmtBooks = $pdo->prepare(
    'SELECT b.*, u.username AS owner_name
     FROM budget_books b
     LEFT JOIN users u ON u.id = b.user_id
     WHERE b.user_id = :uid
        OR FIND_IN_SET(:uid_str, b.shared_users) > 0
     ORDER BY b.id ASC'
);
$stmtBooks->execute([
    ':uid'     => $userId,
    ':uid_str' => (string)$userId,
]);
$allBooks = $stmtBooks->fetchAll();

// Falls noch kein Haushaltsbuch existiert, ein Standard-Haushaltsbuch anlegen
if (empty($allBooks)) {
    $stmtInit = $pdo->prepare(
        "INSERT INTO budget_books (user_id, title, currency, monthly_limit, shared_users)
         VALUES (:uid, 'Mein Haushaltsbuch', '€', 2500.00, '')"
    );
    $stmtInit->execute([':uid' => $userId]);
    $firstBookId = (int)$pdo->lastInsertId();

    // Zwei hilfreiche Beispiel-Fixkosten anlegen, damit man sofort die Funktionsweise sieht
    $stmtSubInit = $pdo->prepare(
        "INSERT INTO budget_subscriptions (book_id, user_id, type, category, title, amount, billing_cycle, day_of_month, active)
         VALUES (:bid, :uid, :type, :cat, :title, :amt, 'monthly', 1, 1)"
    );
    $stmtSubInit->execute([':bid' => $firstBookId, ':uid' => $userId, ':type' => 'expense', ':cat' => 'Wohnen & Energie', ':title' => 'Miete / Hausrate (Beispiel)', ':amt' => 850.00]);
    $stmtSubInit->execute([':bid' => $firstBookId, ':uid' => $userId, ':type' => 'expense', ':cat' => 'Verträge & Abos', ':title' => 'Internet & Mobilfunk (Beispiel)', ':amt' => 49.90]);

    // Beispiel-Sparziel
    $pdo->prepare(
        "INSERT INTO budget_goals (book_id, user_id, title, icon, target_amount, current_amount)
         VALUES (:bid, :uid, 'Urlaubskasse', '🌴', 1500.00, 250.00)"
    )->execute([':bid' => $firstBookId, ':uid' => $userId]);

    header('Location: budget.php?book=' . $firstBookId);
    exit;
}

$activeBookId = isset($_GET['book']) ? (int)$_GET['book'] : (int)($_POST['book_id'] ?? $allBooks[0]['id']);
$activeBook = null;
foreach ($allBooks as $b) {
    if ((int)$b['id'] === $activeBookId) {
        $activeBook = $b;
        break;
    }
}
if ($activeBook === null) {
    $activeBook = $allBooks[0];
    $activeBookId = (int)$activeBook['id'];
}

$isBookOwner = ((int)$activeBook['user_id'] === $userId);
$currency = (string)($activeBook['currency'] ?: '€');
$selectedMonth = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// --- POST-Aktionen verarbeiten ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Neues Haushaltsbuch anlegen
    if ($action === 'create_book') {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180) ?: 'Neues Haushaltsbuch';
        $curr  = mb_substr(trim((string)($_POST['currency'] ?? '€')), 0, 8) ?: '€';
        $limit = max(0.0, (float)str_replace(',', '.', (string)($_POST['monthly_limit'] ?? '2000')));

        $stmt = $pdo->prepare(
            'INSERT INTO budget_books (user_id, title, currency, monthly_limit, shared_users)
             VALUES (:uid, :title, :curr, :lim, :shared)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':title'  => $title,
            ':curr'   => $curr,
            ':lim'    => $limit,
            ':shared' => '',
        ]);
        $newBookId = (int)$pdo->lastInsertId();
        setFlash('success', 'Haushaltsbuch „' . $title . '“ wurde angelegt.');
        header('Location: budget.php?book=' . $newBookId);
        exit;
    }

    // 2. Haushaltsbuch-Einstellungen & Team-Freigabe speichern
    if ($action === 'update_book' && $isBookOwner) {
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180) ?: (string)$activeBook['title'];
        $curr  = mb_substr(trim((string)($_POST['currency'] ?? '€')), 0, 8) ?: '€';
        $limit = max(0.0, (float)str_replace(',', '.', (string)($_POST['monthly_limit'] ?? '2000')));
        $sharedArr = isset($_POST['shared_users']) && is_array($_POST['shared_users']) ? $_POST['shared_users'] : [];
        $candIds   = array_values(array_unique(array_filter(array_map('intval', $sharedArr), static fn(int $id): bool => $id > 0 && $id !== $userId)));
        $validIds  = [];
        if (!empty($candIds)) {
            $placeholders = implode(',', array_fill(0, count($candIds), '?'));
            $stmtVis = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND allow_user_sharing = 1 AND is_locked = 0");
            $stmtVis->execute($candIds);
            $validIds = array_map('intval', $stmtVis->fetchAll(PDO::FETCH_COLUMN));
        }
        $cleanShared = implode(',', $validIds);

        $pdo->prepare(
            'UPDATE budget_books SET title = :t, currency = :c, monthly_limit = :l, shared_users = :s WHERE id = :id'
        )->execute([
            ':t'  => $title,
            ':c'  => $curr,
            ':l'  => $limit,
            ':s'  => $cleanShared,
            ':id' => $activeBookId,
        ]);

        setFlash('success', 'Einstellungen für das Haushaltsbuch wurden gespeichert.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth));
        exit;
    }

    // 3. Neue Einnahme / Ausgabe buchen
    if ($action === 'add_entry') {
        $type   = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $cat    = mb_substr(trim((string)($_POST['category'] ?? 'Sonstiges')), 0, 60);
        $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
        $amount = round(max(0.01, abs((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')))), 2);
        $date   = (string)($_POST['entry_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $isSplit = isset($_POST['is_shared_split']) && $_POST['is_shared_split'] === '1' ? 1 : 0;
        $fileId  = !empty($_POST['file_id']) ? (int)$_POST['file_id'] : null;
        if ($fileId !== null && $fileId > 0) {
            $stmtChkFile = $pdo->prepare('SELECT id FROM files WHERE id = :fid AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmtChkFile->execute([':fid' => $fileId, ':uid' => $userId]);
            if (!$stmtChkFile->fetchColumn()) {
                $fileId = null;
            }
        } else {
            $fileId = null;
        }
        $note    = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 480);

        if ($title === '') {
            $title = $cat;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO budget_entries (book_id, user_id, type, category, title, amount, entry_date, is_shared_split, file_id, note)
             VALUES (:bid, :uid, :type, :cat, :title, :amt, :dt, :split, :fid, :note)'
        );
        $stmt->execute([
            ':bid'   => $activeBookId,
            ':uid'   => $userId,
            ':type'  => $type,
            ':cat'   => $cat,
            ':title' => $title,
            ':amt'   => $amount,
            ':dt'    => $date,
            ':split' => $isSplit,
            ':fid'   => $fileId,
            ':note'  => $note,
        ]);

        $targetMonth = substr($date, 0, 7);
        setFlash('success', ($type === 'income' ? 'Einnahme' : 'Ausgabe') . ' „' . $title . '“ (' . formatMoney($amount, $currency) . ') wurde verbucht.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($targetMonth));
        exit;
    }

    // 4. Buchung löschen
    if ($action === 'delete_entry') {
        $entryId = (int)($_POST['entry_id'] ?? 0);
        $pdo->prepare('DELETE FROM budget_entries WHERE id = :id AND book_id = :bid')
            ->execute([':id' => $entryId, ':bid' => $activeBookId]);
        setFlash('success', 'Buchungseintrag wurde gelöscht.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth));
        exit;
    }

    // 5. Fixkosten / Abo hinzufügen
    if ($action === 'add_subscription') {
        $type  = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $cat   = mb_substr(trim((string)($_POST['category'] ?? 'Verträge & Abos')), 0, 60);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180) ?: 'Vertrag / Fixposten';
        $amt   = round(max(0.01, abs((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')))), 2);
        $cycle = (string)($_POST['billing_cycle'] ?? 'monthly');
        if (!in_array($cycle, ['monthly', 'quarterly', 'yearly'], true)) {
            $cycle = 'monthly';
        }
        $day = max(1, min(28, (int)($_POST['day_of_month'] ?? 1)));

        $pdo->prepare(
            'INSERT INTO budget_subscriptions (book_id, user_id, type, category, title, amount, billing_cycle, day_of_month, active)
             VALUES (:bid, :uid, :type, :cat, :title, :amt, :cycle, :day, 1)'
        )->execute([
            ':bid'   => $activeBookId,
            ':uid'   => $userId,
            ':type'  => $type,
            ':cat'   => $cat,
            ':title' => $title,
            ':amt'   => $amt,
            ':cycle' => $cycle,
            ':day'   => $day,
        ]);

        setFlash('success', 'Regelmäßiger Posten „' . $title . '“ wurde gespeichert.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth) . '#tab-subs');
        exit;
    }

    // 6. Fixkosten / Abo löschen
    if ($action === 'delete_subscription') {
        $subId = (int)($_POST['sub_id'] ?? 0);
        $pdo->prepare('DELETE FROM budget_subscriptions WHERE id = :id AND book_id = :bid')
            ->execute([':id' => $subId, ':bid' => $activeBookId]);
        setFlash('success', 'Regelmäßiger Posten wurde entfernt.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth) . '#tab-subs');
        exit;
    }

    // 7. Alle aktiven monatlichen Fixkosten für den gewählten Monat per 1-Klick ins Haushaltsbuch übernehmen
    if ($action === 'apply_subscriptions_to_month') {
        $stmtSubs = $pdo->prepare('SELECT * FROM budget_subscriptions WHERE book_id = :bid AND active = 1');
        $stmtSubs->execute([':bid' => $activeBookId]);
        $subs = $stmtSubs->fetchAll();

        $inserted = 0;
        foreach ($subs as $sub) {
            $subTitle = (string)$sub['title'] . ' (Fixposten)';
            // Prüfen, ob in diesem Monat bereits gebucht
            $stmtChk = $pdo->prepare(
                "SELECT COUNT(*) FROM budget_entries
                 WHERE book_id = :bid
                   AND DATE_FORMAT(entry_date, '%Y-%m') = :ym
                   AND title = :title"
            );
            $stmtChk->execute([
                ':bid'   => $activeBookId,
                ':ym'    => $selectedMonth,
                ':title' => $subTitle,
            ]);
            if ((int)$stmtChk->fetchColumn() > 0) {
                continue;
            }

            $amt = (float)$sub['amount'];
            if ($sub['billing_cycle'] === 'quarterly') {
                $amt = round($amt / 3, 2);
            } elseif ($sub['billing_cycle'] === 'yearly') {
                $amt = round($amt / 12, 2);
            }

            $day = str_pad((string)max(1, min(28, (int)$sub['day_of_month'])), 2, '0', STR_PAD_LEFT);
            $entryDate = $selectedMonth . '-' . $day;

            $pdo->prepare(
                'INSERT INTO budget_entries (book_id, user_id, type, category, title, amount, entry_date, is_shared_split, note)
                 VALUES (:bid, :uid, :type, :cat, :title, :amt, :dt, 1, :note)'
            )->execute([
                ':bid'   => $activeBookId,
                ':uid'   => (int)$sub['user_id'],
                ':type'  => (string)$sub['type'],
                ':cat'   => (string)$sub['category'],
                ':title' => $subTitle,
                ':amt'   => $amt,
                ':dt'    => $entryDate,
                ':note'  => 'Automatisch aus Fixkosten übernommen',
            ]);
            $inserted++;
        }

        if ($inserted > 0) {
            setFlash('success', $inserted . ' regelmäßige Fixposten wurden für ' . $selectedMonth . ' in das Haushaltsbuch eingetragen.');
        } else {
            setFlash('warning', 'Alle aktiven Fixposten sind für diesen Monat bereits im Haushaltsbuch verbucht.');
        }
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth));
        exit;
    }

    // 8. Sparziel anlegen / aktualisieren / löschen
    if ($action === 'add_goal') {
        $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180) ?: 'Sparziel';
        $icon   = mb_substr(trim((string)($_POST['icon'] ?? '🐷')), 0, 12) ?: '🐷';
        $target = round(max(1.0, abs((float)str_replace(',', '.', (string)($_POST['target_amount'] ?? '1000')))), 2);
        $curr   = round(max(0.0, abs((float)str_replace(',', '.', (string)($_POST['current_amount'] ?? '0')))), 2);
        $tDate  = !empty($_POST['target_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['target_date']) ? (string)$_POST['target_date'] : null;

        $pdo->prepare(
            'INSERT INTO budget_goals (book_id, user_id, title, icon, target_amount, current_amount, target_date)
             VALUES (:bid, :uid, :title, :icon, :target, :curr, :tdate)'
        )->execute([
            ':bid'    => $activeBookId,
            ':uid'    => $userId,
            ':title'  => $title,
            ':icon'   => $icon,
            ':target' => $target,
            ':curr'   => $curr,
            ':tdate'  => $tDate,
        ]);

        setFlash('success', 'Sparziel „' . $title . '“ wurde erstellt.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth) . '#tab-goals');
        exit;
    }

    if ($action === 'adjust_goal') {
        $goalId = (int)($_POST['goal_id'] ?? 0);
        $delta  = round((float)str_replace(',', '.', (string)($_POST['delta'] ?? '0')), 2);
        $pdo->prepare(
            'UPDATE budget_goals SET current_amount = GREATEST(0, current_amount + :delta) WHERE id = :id AND book_id = :bid'
        )->execute([
            ':delta' => $delta,
            ':id'    => $goalId,
            ':bid'   => $activeBookId,
        ]);
        setFlash('success', 'Sparstand wurde aktualisiert.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth) . '#tab-goals');
        exit;
    }

    if ($action === 'delete_goal') {
        $goalId = (int)($_POST['goal_id'] ?? 0);
        $pdo->prepare('DELETE FROM budget_goals WHERE id = :id AND book_id = :bid')
            ->execute([':id' => $goalId, ':bid' => $activeBookId]);
        setFlash('success', 'Sparziel wurde gelöscht.');
        header('Location: budget.php?book=' . $activeBookId . '&month=' . urlencode($selectedMonth) . '#tab-goals');
        exit;
    }
}

// --- CSV-Export für den gewählten Monat ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtCsv = $pdo->prepare(
        "SELECT e.*, u.username
         FROM budget_entries e
         LEFT JOIN users u ON u.id = e.user_id
         WHERE e.book_id = :bid
           AND DATE_FORMAT(e.entry_date, '%Y-%m') = :ym
         ORDER BY e.entry_date ASC, e.id ASC"
    );
    $stmtCsv->execute([':bid' => $activeBookId, ':ym' => $selectedMonth]);
    $rows = $stmtCsv->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Haushaltsbuch_' . $selectedMonth . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel
    fputcsv($out, ['Datum', 'Typ', 'Kategorie', 'Bezeichnung', 'Betrag (' . $currency . ')', 'Erfasst von', 'Notiz'], ';');
    foreach ($rows as $r) {
        $signed = $r['type'] === 'income' ? (float)$r['amount'] : -(float)$r['amount'];
        fputcsv($out, [
            (string)$r['entry_date'],
            $r['type'] === 'income' ? 'Einnahme' : 'Ausgabe',
            (string)$r['category'],
            (string)$r['title'],
            number_format($signed, 2, ',', ''),
            (string)($r['username'] ?? ''),
            (string)$r['note'],
        ], ';');
    }
    fclose($out);
    exit;
}

// --- Daten für den gewählten Monat laden ---
$stmtEntries = $pdo->prepare(
    "SELECT e.*, u.username, f.original_name AS file_name
     FROM budget_entries e
     LEFT JOIN users u ON u.id = e.user_id
     LEFT JOIN files f ON f.id = e.file_id AND f.deleted_at IS NULL
     WHERE e.book_id = :bid
       AND DATE_FORMAT(e.entry_date, '%Y-%m') = :ym
     ORDER BY e.entry_date DESC, e.id DESC"
);
$stmtEntries->execute([
    ':bid' => $activeBookId,
    ':ym'  => $selectedMonth,
]);
$monthEntries = $stmtEntries->fetchAll();

$totalIncome = 0.0;
$totalExpense = 0.0;
$expenseByCategory = [];

foreach ($monthEntries as $entry) {
    $amt = (float)$entry['amount'];
    if ($entry['type'] === 'income') {
        $totalIncome += $amt;
    } else {
        $totalExpense += $amt;
        $cat = (string)$entry['category'];
        $expenseByCategory[$cat] = ($expenseByCategory[$cat] ?? 0.0) + $amt;
    }
}
arsort($expenseByCategory);

$balance = $totalIncome - $totalExpense;
$monthlyLimit = (float)$activeBook['monthly_limit'];
$limitPercent = $monthlyLimit > 0 ? min(100.0, round(($totalExpense / $monthlyLimit) * 100, 1)) : 0.0;

// --- 6-Monats-Verlauf berechnen ---
$trendMonths = [];
$baseDt = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01') ?: new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $mKey = $baseDt->modify("-{$i} months")->format('Y-m');
    $trendMonths[$mKey] = [
        'label'   => $baseDt->modify("-{$i} months")->format('m/y'),
        'income'  => 0.0,
        'expense' => 0.0,
    ];
}
$firstTrendMonth = array_key_first($trendMonths);
$lastTrendMonth  = array_key_last($trendMonths);

$stmtTrend = $pdo->prepare(
    "SELECT DATE_FORMAT(entry_date, '%Y-%m') AS ym,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS sum_inc,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS sum_exp
     FROM budget_entries
     WHERE book_id = :bid
       AND DATE_FORMAT(entry_date, '%Y-%m') BETWEEN :m1 AND :m2
     GROUP BY ym"
);
$stmtTrend->execute([
    ':bid' => $activeBookId,
    ':m1'  => $firstTrendMonth,
    ':m2'  => $lastTrendMonth,
]);
$maxTrendValue = 100.0;
foreach ($stmtTrend->fetchAll() as $tRow) {
    $ym = (string)$tRow['ym'];
    if (isset($trendMonths[$ym])) {
        $trendMonths[$ym]['income']  = (float)$tRow['sum_inc'];
        $trendMonths[$ym]['expense'] = (float)$tRow['sum_exp'];
        $maxTrendValue = max($maxTrendValue, (float)$tRow['sum_inc'], (float)$tRow['sum_exp']);
    }
}

// --- Fixkosten & Abos laden ---
$stmtSubs = $pdo->prepare('SELECT * FROM budget_subscriptions WHERE book_id = :bid ORDER BY type ASC, amount DESC');
$stmtSubs->execute([':bid' => $activeBookId]);
$subscriptions = $stmtSubs->fetchAll();

$monthlyFixedExpense = 0.0;
$monthlyFixedIncome  = 0.0;
foreach ($subscriptions as $sub) {
    if (!(int)$sub['active']) {
        continue;
    }
    $mAmt = (float)$sub['amount'];
    if ($sub['billing_cycle'] === 'quarterly') {
        $mAmt /= 3.0;
    } elseif ($sub['billing_cycle'] === 'yearly') {
        $mAmt /= 12.0;
    }
    if ($sub['type'] === 'income') {
        $monthlyFixedIncome += $mAmt;
    } else {
        $monthlyFixedExpense += $mAmt;
    }
}

// --- Sparziele laden ---
$stmtGoals = $pdo->prepare('SELECT * FROM budget_goals WHERE book_id = :bid ORDER BY id ASC');
$stmtGoals->execute([':bid' => $activeBookId]);
$goals = $stmtGoals->fetchAll();

// --- Eigene Dateien für optionale Beleg-Verknüpfung laden ---
$stmtFiles = $pdo->prepare(
    'SELECT id, original_name, folder_path FROM files WHERE user_id = :uid AND deleted_at IS NULL ORDER BY upload_date DESC LIMIT 60'
);
$stmtFiles->execute([':uid' => $userId]);
$userFiles = $stmtFiles->fetchAll();

// --- Mitglieder des Haushaltsbuchs & WG-/Familien-Kostenausgleich berechnen ---
$sharedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($activeBook['shared_users'] ?? ''))))));
$memberIds = array_values(array_unique(array_merge([(int)$activeBook['user_id']], $sharedIds)));

$memberNames = [];
if (!empty($memberIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmtMem = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($inPlaceholders)");
    $stmtMem->execute($memberIds);
    foreach ($stmtMem->fetchAll() as $uRow) {
        $memberNames[(int)$uRow['id']] = (string)$uRow['username'];
    }
}

$paidByMember = [];
foreach ($memberIds as $mId) {
    $paidByMember[$mId] = 0.0;
}
$totalSharedPool = 0.0;
foreach ($monthEntries as $entry) {
    if ($entry['type'] === 'expense' && (int)$entry['is_shared_split'] === 1) {
        $payer = (int)$entry['user_id'];
        $amt   = (float)$entry['amount'];
        $totalSharedPool += $amt;
        $paidByMember[$payer] = ($paidByMember[$payer] ?? 0.0) + $amt;
    }
}
$memberCount = max(1, count($memberIds));
$fairSharePerPerson = $totalSharedPool / $memberCount;

// Andere Cloud-Benutzer für Freigabe-Auswahl
$otherUsers = [];
try {
    $stmtUsers = $pdo->prepare('SELECT id, username FROM users WHERE id != :uid ORDER BY username ASC');
    $stmtUsers->execute([':uid' => $userId]);
    $otherUsers = $stmtUsers->fetchAll();
} catch (Throwable) {
    $otherUsers = [];
}

$categories = getBudgetCategories();
$prevMonth = $baseDt->modify('-1 month')->format('Y-m');
$nextMonth = $baseDt->modify('+1 month')->format('Y-m');

$pageTitle = 'Haushaltsplaner';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich: Buch-Auswahl, Monats-Navigation & Aktionen -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">
                    💰
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">
                        Haushaltsplaner &amp; Finanz-Übersicht
                    </h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Einnahmen, Ausgaben, Fixkosten, Sparziele &amp; gemeinsamer Haushalts-Ausgleich
                    </p>
                </div>

                <!-- Haushaltsbuch-Wechsler -->
                <div class="flex items-center gap-1.5 ml-0 sm:ml-2">
                    <select
                        onchange="window.location.href='budget.php?book='+this.value+'&month=<?= e($selectedMonth) ?>'"
                        class="px-3 py-1.5 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100"
                    >
                        <?php foreach ($allBooks as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $activeBookId ? 'selected' : '' ?>>
                                📘 <?= e((string)$b['title']) ?><?= (int)$b['user_id'] !== $userId ? ' (von ' . e((string)$b['owner_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="button"
                        onclick="document.getElementById('newBookModal').classList.remove('hidden')"
                        class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 cursor-pointer"
                        title="Weiteres Haushaltsbuch anlegen"
                    >
                        ＋ Buch
                    </button>
                    <?php if ($isBookOwner): ?>
                        <button
                            type="button"
                            onclick="document.getElementById('bookSettingsModal').classList.remove('hidden')"
                            class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 cursor-pointer"
                            title="Budget-Limit & Team-Freigabe bearbeiten"
                        >
                            ⚙️
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rechts: Monatswähler & CSV-Export -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex items-center rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 p-1">
                    <a
                        href="budget.php?book=<?= (int)$activeBookId ?>&month=<?= e($prevMonth) ?>"
                        class="px-2.5 py-1 rounded-lg text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-colors"
                        title="Vorheriger Monat"
                    >◀</a>
                    <input
                        type="month"
                        value="<?= e($selectedMonth) ?>"
                        onchange="if(this.value) window.location.href='budget.php?book=<?= (int)$activeBookId ?>&month='+this.value"
                        class="px-2 py-0.5 text-xs sm:text-sm font-bold bg-transparent text-slate-900 dark:text-white focus:outline-none cursor-pointer"
                    >
                    <a
                        href="budget.php?book=<?= (int)$activeBookId ?>&month=<?= e($nextMonth) ?>"
                        class="px-2.5 py-1 rounded-lg text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 transition-colors"
                        title="Nächster Monat"
                    >▶</a>
                </div>

                <a
                    href="budget.php?book=<?= (int)$activeBookId ?>&month=<?= e($selectedMonth) ?>&export=csv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/50 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800/70 transition-colors"
                >
                    <span>📥</span>
                    <span>CSV-Export</span>
                </a>
            </div>
        </div>
    </div>

    <!-- 4 große Finanz-KPI-Karten -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Einnahmen -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                <span>📈 Einnahmen (<?= e($selectedMonth) ?>)</span>
                <span class="text-emerald-600 dark:text-emerald-400">+</span>
            </div>
            <p class="mt-2 text-xl sm:text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">
                +<?= e(formatMoney($totalIncome, $currency)) ?>
            </p>
            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                Regelmäßige Fix-Einnahmen: <?= e(formatMoney($monthlyFixedIncome, $currency)) ?>/Monat
            </p>
        </div>

        <!-- Ausgaben -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                <span>📉 Ausgaben (<?= e($selectedMonth) ?>)</span>
                <span class="text-red-500">−</span>
            </div>
            <p class="mt-2 text-xl sm:text-2xl font-extrabold text-red-600 dark:text-red-400">
                −<?= e(formatMoney($totalExpense, $currency)) ?>
            </p>
            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                Regelmäßige Fixkosten: <?= e(formatMoney($monthlyFixedExpense, $currency)) ?>/Monat
            </p>
        </div>

        <!-- Monats-Bilanz / Saldo -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                <span>⚖️ Monats-Saldo</span>
                <span><?= $balance >= 0 ? '🟢 Im Plus' : '🔴 Im Minus' ?></span>
            </div>
            <p class="mt-2 text-xl sm:text-2xl font-extrabold <?= $balance >= 0 ? 'text-slate-900 dark:text-white' : 'text-red-600 dark:text-red-400' ?>">
                <?= ($balance >= 0 ? '+' : '') . e(formatMoney($balance, $currency)) ?>
            </p>
            <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                <?= count($monthEntries) ?> Buchungen in diesem Monat
            </p>
        </div>

        <!-- Monatsbudget-Ampel -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400">
                <span>🎯 Monatsbudget-Limit</span>
                <span class="font-bold text-slate-800 dark:text-slate-100"><?= e(number_format($limitPercent, 0, ',', '.')) ?>%</span>
            </div>
            <div class="mt-2.5 w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <?php
                $barCls = $limitPercent >= 95
                    ? 'bg-red-600'
                    : ($limitPercent >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                ?>
                <div class="h-full <?= $barCls ?> rounded-full transition-all duration-300" style="width: <?= e((string)$limitPercent) ?>%"></div>
            </div>
            <p class="mt-1.5 text-[11px] text-slate-400 dark:text-slate-500">
                <?php $rem = $monthlyLimit - $totalExpense; ?>
                <?php if ($rem >= 0): ?>
                    Noch <strong class="text-emerald-600 dark:text-emerald-400"><?= e(formatMoney($rem, $currency)) ?></strong> von <?= e(formatMoney($monthlyLimit, $currency)) ?> frei
                <?php else: ?>
                    Budget um <strong class="text-red-600 dark:text-red-400"><?= e(formatMoney(abs($rem), $currency)) ?></strong> überschritten
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Navigation-Tabs innerhalb des Haushaltsplaners -->
    <div class="flex items-center gap-2 overflow-x-auto pb-1">
        <button type="button" onclick="switchBudgetTab('entries')" id="tabBtn_entries" class="budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 text-white shadow-2xs cursor-pointer shrink-0">
            📒 Buchungen &amp; Diagramme
        </button>
        <button type="button" onclick="switchBudgetTab('subs')" id="tabBtn_subs" class="budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-800 cursor-pointer shrink-0">
            🔄 Fixkosten &amp; Abos (<?= count($subscriptions) ?>)
        </button>
        <button type="button" onclick="switchBudgetTab('goals')" id="tabBtn_goals" class="budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-800 cursor-pointer shrink-0">
            🐷 Sparziele (<?= count($goals) ?>)
        </button>
        <button type="button" onclick="switchBudgetTab('split')" id="tabBtn_split" class="budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-800 cursor-pointer shrink-0">
            🤝 Familien- &amp; WG-Ausgleich
        </button>
    </div>

    <!-- TAB 1: Buchungen & Diagramme -->
    <div id="budgetTab_entries" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Linke Spalte: Neue Buchung erfassen -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 h-fit space-y-4">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2.5">
                ➕ Neue Buchung eintragen
            </h2>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_entry">
                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">

                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="expense" checked class="peer sr-only">
                        <div class="text-center py-2 px-3 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 peer-checked:bg-red-600 peer-checked:text-white peer-checked:border-red-600 text-slate-600 dark:text-slate-300 transition-colors">
                            📉 Ausgabe
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="income" class="peer sr-only">
                        <div class="text-center py-2 px-3 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 text-slate-600 dark:text-slate-300 transition-colors">
                            📈 Einnahme
                        </div>
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Bezeichnung / Verwendungszweck</label>
                    <input
                        type="text"
                        name="title"
                        required
                        placeholder="z.B. Wocheneinkauf, Tanken, Gehalt..."
                        class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                    >
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Betrag (<?= e($currency) ?>)</label>
                        <input
                            type="text"
                            name="amount"
                            required
                             inputmode="decimal"
                            placeholder="0,00"
                            class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Datum</label>
                        <input
                            type="date"
                            name="entry_date"
                            value="<?= e($selectedMonth === date('Y-m') ? date('Y-m-d') : $selectedMonth . '-01') ?>"
                            class="w-full px-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                        >
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Kategorie</label>
                    <select
                        name="category"
                        class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white"
                    >
                        <?php foreach ($categories as $catName => $meta): ?>
                            <option value="<?= e($catName) ?>" <?= $catName === 'Lebensmittel' ? 'selected' : '' ?>>
                                <?= $meta['icon'] ?> <?= e($catName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($userFiles)): ?>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">📎 Kassenbon / Beleg aus Cloud verknüpfen (optional)</label>
                        <select
                            name="file_id"
                            class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200"
                        >
                            <option value="">— Kein Beleg ausgewählt —</option>
                            <?php foreach ($userFiles as $f): ?>
                                <option value="<?= (int)$f['id'] ?>">
                                    📄 <?= e((string)$f['original_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Notiz (optional)</label>
                    <input
                        type="text"
                        name="note"
                        placeholder="Kurze Bemerkung..."
                        class="w-full px-3 py-1.5 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100"
                    >
                </div>

                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300 cursor-pointer pt-1">
                    <input type="checkbox" name="is_shared_split" value="1" checked class="rounded border-slate-300 text-brand-600">
                    <span>🤝 Gemeinsame Haushaltsausgabe (für WG-/Familien-Ausgleich berücksichtigen)</span>
                </label>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-xs transition-colors cursor-pointer"
                >
                    Buchung speichern
                </button>
            </form>
        </div>

        <!-- Rechte 2 Spalten: Grafische Auswertung & Buchungsliste -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Grafiken: Kategorien-Verteilung & 6-Monats-Trend -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Ausgaben nach Kategorien -->
                <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        📊 Ausgaben nach Kategorien
                    </h3>
                    <?php if (empty($expenseByCategory)): ?>
                        <p class="text-xs text-slate-400 py-6 text-center">Noch keine Ausgaben in diesem Monat erfasst.</p>
                    <?php else: ?>
                        <div class="space-y-2.5">
                            <?php foreach ($expenseByCategory as $catName => $catSum): ?>
                                <?php
                                $pct = $totalExpense > 0 ? round(($catSum / $totalExpense) * 100, 1) : 0.0;
                                $meta = $categories[$catName] ?? ['icon' => '📌', 'color' => '#64748b'];
                                ?>
                                <div>
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="font-semibold text-slate-700 dark:text-slate-200">
                                            <?= $meta['icon'] ?> <?= e($catName) ?>
                                        </span>
                                        <span class="font-bold text-slate-900 dark:text-white">
                                            <?= e(formatMoney($catSum, $currency)) ?> <span class="text-[10px] text-slate-400">(<?= $pct ?>%)</span>
                                        </span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full" style="width: <?= e((string)$pct) ?>%; background-color: <?= e($meta['color']) ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 6-Monats-Trend (Einnahmen vs. Ausgaben) -->
                <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            📈 6-Monats-Verlauf
                        </h3>
                        <div class="flex items-center gap-2 text-[10px] font-semibold">
                            <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Einnahmen</span>
                            <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"><span class="w-2 h-2 rounded-full bg-red-500"></span>Ausgaben</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-6 gap-2 items-end h-36 pt-4 border-b border-slate-200/70 dark:border-slate-800">
                        <?php foreach ($trendMonths as $mKey => $tData): ?>
                            <?php
                            $incH = min(100, round(($tData['income'] / $maxTrendValue) * 100));
                            $expH = min(100, round(($tData['expense'] / $maxTrendValue) * 100));
                            ?>
                            <div class="flex flex-col items-center h-full justify-end gap-1">
                                <div class="w-full flex items-end justify-center gap-1 h-28">
                                    <div
                                        class="w-2.5 sm:w-3 bg-emerald-500 rounded-t-md transition-all"
                                        style="height: <?= max(4, $incH) ?>%"
                                        title="Einnahmen <?= e($tData['label']) ?>: <?= e(formatMoney($tData['income'], $currency)) ?>"
                                    ></div>
                                    <div
                                        class="w-2.5 sm:w-3 bg-red-500 rounded-t-md transition-all"
                                        style="height: <?= max(4, $expH) ?>%"
                                        title="Ausgaben <?= e($tData['label']) ?>: <?= e(formatMoney($tData['expense'], $currency)) ?>"
                                    ></div>
                                </div>
                                <span class="text-[10px] font-semibold <?= $mKey === $selectedMonth ? 'text-brand-600 dark:text-brand-400 font-bold' : 'text-slate-400' ?>">
                                    <?= e($tData['label']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Buchungsliste des Monats -->
            <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">
                        🧾 Buchungen im Monat <?= e($selectedMonth) ?> (<?= count($monthEntries) ?>)
                    </h2>
                    <input
                        type="search"
                        id="entrySearchInput"
                        oninput="filterBudgetEntries()"
                        placeholder="Buchung suchen..."
                        class="px-3 py-1.5 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100"
                    >
                </div>

                <?php if (empty($monthEntries)): ?>
                    <div class="text-center py-8 space-y-2">
                        <div class="text-3xl">📭</div>
                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">Noch keine Buchungen im Monat <?= e($selectedMonth) ?> vorhanden.</p>
                        <?php if (!empty($subscriptions)): ?>
                            <form method="post" class="inline-block pt-1">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="apply_subscriptions_to_month">
                                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                                    ⚡ Regelmäßige Fixkosten jetzt für <?= e($selectedMonth) ?> eintragen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800" id="budgetEntriesList">
                        <?php foreach ($monthEntries as $entry): ?>
                            <?php
                            $catMeta = $categories[(string)$entry['category']] ?? ['icon' => '📌', 'color' => '#64748b'];
                            $isInc = ($entry['type'] === 'income');
                            ?>
                            <div
                                class="budget-entry-row py-3 flex items-center justify-between gap-3"
                                data-search="<?= e(mb_strtolower($entry['title'] . ' ' . $entry['category'] . ' ' . $entry['note'] . ' ' . ($entry['username'] ?? ''))) ?>"
                            >
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-lg shrink-0">
                                        <?= $catMeta['icon'] ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate">
                                                <?= e((string)$entry['title']) ?>
                                            </span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                                <?= e((string)$entry['category']) ?>
                                            </span>
                                            <?php if (!empty($entry['file_id']) && !empty($entry['file_name'])): ?>
                                                <a
                                                    href="download.php?id=<?= (int)$entry['file_id'] ?>&inline=1"
                                                    target="_blank"
                                                    class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 hover:underline"
                                                    title="Verknüpften Beleg öffnen"
                                                >
                                                    📎 <?= e((string)$entry['file_name']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-400 dark:text-slate-500">
                                            <?= e(date('d.m.Y', strtotime((string)$entry['entry_date']))) ?>
                                            · von <?= e((string)($entry['username'] ?: 'Benutzer')) ?>
                                            <?= !empty($entry['note']) ? ' · ' . e((string)$entry['note']) : '' ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2.5 shrink-0">
                                    <span class="text-xs sm:text-sm font-extrabold <?= $isInc ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>">
                                        <?= ($isInc ? '+' : '−') . e(formatMoney((float)$entry['amount'], $currency)) ?>
                                    </span>
                                    <form method="post" onsubmit="return confirm('Buchung wirklich löschen?');" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_entry">
                                        <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                                        <button type="submit" class="text-slate-400 hover:text-red-600 text-xs p-1 cursor-pointer" title="Löschen">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TAB 2: Fixkosten, Verträge & Abos -->
    <div id="budgetTab_subs" class="hidden grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 h-fit space-y-4">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2.5">
                🔄 Neuer Fixposten / Vertrag
            </h2>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_subscription">
                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">

                <div class="grid grid-cols-2 gap-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="expense" checked class="peer sr-only">
                        <div class="text-center py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 peer-checked:bg-red-600 peer-checked:text-white">
                            📉 Fix-Ausgabe
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="income" class="peer sr-only">
                        <div class="text-center py-2 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 peer-checked:bg-emerald-600 peer-checked:text-white">
                            📈 Fix-Einnahme
                        </div>
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Bezeichnung (z.B. Miete, Strom, Netflix, Gehalt)</label>
                    <input type="text" name="title" required placeholder="Vertrag oder Abo..." class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Betrag (<?= e($currency) ?>)</label>
                        <input type="text" name="amount" required placeholder="0,00" class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Turnus</label>
                        <select name="billing_cycle" class="w-full px-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                            <option value="monthly">Monatlich</option>
                            <option value="quarterly">Vierteljährlich</option>
                            <option value="yearly">Jährlich</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Kategorie</label>
                        <select name="category" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                            <?php foreach ($categories as $catName => $meta): ?>
                                <option value="<?= e($catName) ?>" <?= $catName === 'Verträge & Abos' ? 'selected' : '' ?>>
                                    <?= $meta['icon'] ?> <?= e($catName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Abbuchungstag (1–28)</label>
                        <input type="number" name="day_of_month" min="1" max="28" value="1" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                    Fixposten speichern
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-white">
                        📋 Regelmäßige Verträge, Fixkosten &amp; Abos
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Monatliche Fixkosten-Belastung: <strong class="text-red-600 dark:text-red-400"><?= e(formatMoney($monthlyFixedExpense, $currency)) ?></strong> · Aufs Jahr gerechnet: <strong><?= e(formatMoney($monthlyFixedExpense * 12, $currency)) ?></strong>
                    </p>
                </div>

                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="apply_subscriptions_to_month">
                    <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                    <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                    <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs cursor-pointer">
                        ⚡ Alle für <?= e($selectedMonth) ?> ins Buch übernehmen
                    </button>
                </form>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($subscriptions as $sub): ?>
                    <?php
                    $cLabel = match ($sub['billing_cycle']) {
                        'quarterly' => 'vierteljährlich',
                        'yearly'    => 'jährlich',
                        default     => 'monatlich',
                    };
                    ?>
                    <div class="py-3 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">
                                <?= e((string)$sub['title']) ?>
                                <span class="ml-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    <?= e((string)$sub['category']) ?>
                                </span>
                            </p>
                            <p class="text-[11px] text-slate-400">
                                Jeweils am <?= (int)$sub['day_of_month'] ?>. · Turnus: <?= e($cLabel) ?>
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs sm:text-sm font-extrabold <?= $sub['type'] === 'income' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>">
                                <?= ($sub['type'] === 'income' ? '+' : '−') . e(formatMoney((float)$sub['amount'], $currency)) ?>
                            </span>
                            <form method="post" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_subscription">
                                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                <input type="hidden" name="sub_id" value="<?= (int)$sub['id'] ?>">
                                <button type="submit" class="text-slate-400 hover:text-red-600 text-xs p-1 cursor-pointer">🗑️</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- TAB 3: Sparziele & Rücklagen -->
    <div id="budgetTab_goals" class="hidden grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 h-fit space-y-4">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-2.5">
                🐷 Neues Sparziel anlegen
            </h2>
            <form method="post" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_goal">
                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">

                <div class="grid grid-cols-4 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Icon</label>
                        <select name="icon" class="w-full px-2 py-2 rounded-xl text-base bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                            <option value="🐷">🐷</option>
                            <option value="🌴">🌴</option>
                            <option value="🚗">🚗</option>
                            <option value="🏠">🏠</option>
                            <option value="💻">💻</option>
                            <option value="🎓">🎓</option>
                            <option value="💍">💍</option>
                        </select>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Sparziel-Name</label>
                        <input type="text" name="title" required placeholder="z.B. Sommerurlaub, Notgroschen..." class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Zielbetrag (<?= e($currency) ?>)</label>
                        <input type="text" name="target_amount" required placeholder="1500,00" class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Startbetrag (<?= e($currency) ?>)</label>
                        <input type="text" name="current_amount" value="0" class="w-full px-3.5 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Zieldatum (optional)</label>
                    <input type="date" name="target_date" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">
                    Sparziel erstellen
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php foreach ($goals as $g): ?>
                <?php
                $target = max(1.0, (float)$g['target_amount']);
                $curr   = (float)$g['current_amount'];
                $pct    = min(100.0, round(($curr / $target) * 100, 1));
                ?>
                <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-5 shadow-xs flex flex-col justify-between space-y-4">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2.5">
                                <span class="text-2xl"><?= e((string)$g['icon']) ?></span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900 dark:text-white"><?= e((string)$g['title']) ?></h3>
                                    <?php if (!empty($g['target_date'])): ?>
                                        <p class="text-[11px] text-slate-400">Ziel bis <?= e(date('d.m.Y', strtotime((string)$g['target_date']))) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="post" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_goal">
                                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                                <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                                <button type="submit" class="text-slate-400 hover:text-red-500 text-xs cursor-pointer">🗑️</button>
                            </form>
                        </div>

                        <div class="mt-4 flex items-baseline justify-between text-xs">
                            <span class="font-extrabold text-base text-emerald-600 dark:text-emerald-400"><?= e(formatMoney($curr, $currency)) ?></span>
                            <span class="text-slate-400">von <?= e(formatMoney($target, $currency)) ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="mt-1.5 w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full transition-all" style="width: <?= e((string)$pct) ?>%"></div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <?php foreach ([25, 50, 100, -50] as $step): ?>
                            <form method="post" class="flex-1 m-0">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="adjust_goal">
                                <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
                                <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
                                <input type="hidden" name="delta" value="<?= $step ?>">
                                <button
                                    type="submit"
                                    class="w-full py-1.5 rounded-lg text-xs font-bold <?= $step > 0 ? 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300' ?> cursor-pointer"
                                >
                                    <?= ($step > 0 ? '+' : '') . $step ?> <?= e($currency) ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TAB 4: Familien- & WG-Ausgleich -->
    <div id="budgetTab_split" class="hidden rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs p-5 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
            <div>
                <h2 class="text-sm font-bold text-slate-900 dark:text-white">
                    🤝 Gemeinsame Haushaltskasse &amp; Kostenausgleich (<?= e($selectedMonth) ?>)
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Berechnet automatisch aus allen gemeinsamen Ausgaben im Monat <?= e($selectedMonth) ?>, wer wie viel ausgelegt hat und wie hoch der faire Pro-Kopf-Anteil ist.
                </p>
            </div>
            <?php if ($isBookOwner): ?>
                <button
                    type="button"
                    onclick="document.getElementById('bookSettingsModal').classList.remove('hidden')"
                    class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200/70 dark:border-brand-800/70 cursor-pointer shrink-0"
                >
                    👥 Haushalts-Mitglieder verwalten
                </button>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                <p class="text-xs text-slate-500">Gemeinsame Ausgaben gesamt</p>
                <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-1"><?= e(formatMoney($totalSharedPool, $currency)) ?></p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                <p class="text-xs text-slate-500">Beteiligte Personen</p>
                <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-1"><?= $memberCount ?> <?= $memberCount === 1 ? 'Person' : 'Personen' ?></p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                <p class="text-xs text-slate-500">Fairer Anteil pro Person</p>
                <p class="text-lg font-extrabold text-brand-600 dark:text-brand-400 mt-1"><?= e(formatMoney($fairSharePerPerson, $currency)) ?></p>
            </div>
        </div>

        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($memberIds as $mId): ?>
                <?php
                $mName = $memberNames[$mId] ?? ('Nutzer #' . $mId);
                $paid  = $paidByMember[$mId] ?? 0.0;
                $diff  = $paid - $fairSharePerPerson;
                ?>
                <div class="py-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">👤 <?= e($mName) ?></p>
                        <p class="text-[11px] text-slate-400">Selbst bezahlt: <?= e(formatMoney($paid, $currency)) ?></p>
                    </div>
                    <div class="text-right">
                        <?php if (abs($diff) < 0.01): ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">Ausgeglichen (0,00 <?= e($currency) ?>)</span>
                        <?php elseif ($diff > 0): ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300">
                                Bekommt +<?= e(formatMoney($diff, $currency)) ?> zurück
                            </span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300">
                                Zahlt noch <?= e(formatMoney(abs($diff), $currency)) ?> in die Kasse
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal: Neues Haushaltsbuch anlegen -->
<div id="newBookModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">📘 Neues Haushaltsbuch erstellen</h3>
            <button type="button" onclick="document.getElementById('newBookModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
        </div>
        <form method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_book">
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Bezeichnung</label>
                <input type="text" name="title" required placeholder="z.B. Familienkasse, WG-Haushalt, Urlaub 2027..." class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Währung</label>
                    <input type="text" name="currency" value="€" class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Monatsbudget-Limit</label>
                    <input type="text" name="monthly_limit" value="2500" class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('newBookModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">Anlegen</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Haushaltsbuch-Einstellungen & Team-Freigabe -->
<?php if ($isBookOwner): ?>
<div id="bookSettingsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-5 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white">⚙️ Haushaltsbuch-Einstellungen</h3>
            <button type="button" onclick="document.getElementById('bookSettingsModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 cursor-pointer">✕</button>
        </div>
        <form method="post" class="space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_book">
            <input type="hidden" name="book_id" value="<?= (int)$activeBookId ?>">
            <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
            <div>
                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Name des Haushaltsbuchs</label>
                <input type="text" name="title" value="<?= e((string)$activeBook['title']) ?>" required class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Währung</label>
                    <input type="text" name="currency" value="<?= e($currency) ?>" class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">Monatsbudget-Limit</label>
                    <input type="text" name="monthly_limit" value="<?= e(number_format($monthlyLimit, 2, ',', '')) ?>" class="w-full px-3.5 py-2 rounded-xl text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-900 dark:text-white">
                </div>
            </div>

            <?php if (!empty($otherUsers)): ?>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 dark:text-slate-200 mb-1">👥 Gemeinsam führen mit (Familie / WG)</label>
                    <div class="max-h-36 overflow-y-auto space-y-1.5 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        <?php foreach ($otherUsers as $u): ?>
                            <?php $uidItem = (int)$u['id']; ?>
                            <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                <input type="checkbox" name="shared_users[]" value="<?= $uidItem ?>" <?= in_array($uidItem, $sharedIds, true) ? 'checked' : '' ?> class="rounded border-slate-300 text-brand-600">
                                <span><?= e((string)$u['username']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('bookSettingsModal').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white cursor-pointer">Speichern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function switchBudgetTab(tab) {
    ['entries', 'subs', 'goals', 'split'].forEach(t => {
        const panel = document.getElementById('budgetTab_' + t);
        const btn   = document.getElementById('tabBtn_' + t);
        if (panel) panel.classList.toggle('hidden', t !== tab);
        if (btn) {
            btn.className = (t === tab)
                ? 'budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 text-white shadow-2xs cursor-pointer shrink-0'
                : 'budget-tab-btn px-4 py-2 rounded-xl text-xs sm:text-sm font-bold bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200/80 dark:border-slate-800 cursor-pointer shrink-0';
        }
    });
}

function filterBudgetEntries() {
    const q = (document.getElementById('entrySearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('.budget-entry-row').forEach(row => {
        const text = row.getAttribute('data-search') || '';
        row.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}

window.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash;
    if (hash === '#tab-subs') switchBudgetTab('subs');
    else if (hash === '#tab-goals') switchBudgetTab('goals');
    else if (hash === '#tab-split') switchBudgetTab('split');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
