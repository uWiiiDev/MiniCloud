<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_banking'] ?? '1') !== '1') {
    header('Location: index.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'Benutzer');
$isAdmin  = !empty($_SESSION['is_admin']);

/**
 * Kategorien-Katalog für Kontoauszüge & Finanz-Analyse
 *
 * @return array<string, array{label:string, icon:string, color:string, type:string}>
 */
function getBankingCategories(): array
{
    return [
        'income_salary' => ['label' => 'Gehalt, Rente & Lohn',           'icon' => '💼', 'color' => '#10b981', 'type' => 'income'],
        'income_gov'    => ['label' => 'Kindergeld, Amt & Erstattung',   'icon' => '🏛️', 'color' => '#14b8a6', 'type' => 'income'],
        'income_other'  => ['label' => 'Sonstige Einnahmen & Gutschrift','icon' => '🎁', 'color' => '#34d399', 'type' => 'income'],
        'housing'       => ['label' => 'Wohnen, Miete & Energie',        'icon' => '🏠', 'color' => '#6366f1', 'type' => 'expense'],
        'groceries'     => ['label' => 'Supermarkt & Drogerie',          'icon' => '🛒', 'color' => '#f59e0b', 'type' => 'expense'],
        'mobility'      => ['label' => 'Auto, Tanken & ÖPNV',            'icon' => '🚗', 'color' => '#3b82f6', 'type' => 'expense'],
        'telecom_media' => ['label' => 'Internet, Mobilfunk & Abos',     'icon' => '📱', 'color' => '#8b5cf6', 'type' => 'expense'],
        'insurance_bank'=> ['label' => 'Versicherungen & Bankgebühren',  'icon' => '🛡️', 'color' => '#ec4899', 'type' => 'expense'],
        'shopping'      => ['label' => 'Online-Shopping & Handel',       'icon' => '🛍️', 'color' => '#f97316', 'type' => 'expense'],
        'dining_leisure'=> ['label' => 'Gastronomie, Freizeit & Reise',  'icon' => '🍽️', 'color' => '#eab308', 'type' => 'expense'],
        'health'        => ['label' => 'Gesundheit, Arzt & Apotheke',    'icon' => '🩺', 'color' => '#06b6d4', 'type' => 'expense'],
        'cash_transfer' => ['label' => 'Bargeld & Umbuchung',            'icon' => '💸', 'color' => '#64748b', 'type' => 'expense'],
        'savings_invest'=> ['label' => 'Sparen, Depot & Wertpapiere',    'icon' => '📈', 'color' => '#0ea5e9', 'type' => 'expense'],
        'other'         => ['label' => 'Sonstige Ausgaben',              'icon' => '📦', 'color' => '#94a3b8', 'type' => 'expense'],
    ];
}

/**
 * Stellt sicher, dass alle Datenbanktabellen für das Kontoauszugs-Modul existieren
 * und legt bei Bedarf ein Standardkonto („Postbank Girokonto“) an.
 */
function ensureBankingSchema(PDO $pdo, int $userId): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            bank_name TEXT NOT NULL DEFAULT 'Postbank',
            iban TEXT NOT NULL DEFAULT '',
            bic TEXT NOT NULL DEFAULT '',
            account_type TEXT NOT NULL DEFAULT 'Girokonto',
            color TEXT NOT NULL DEFAULT '#2563eb',
            initial_balance REAL NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bank_accounts_user ON bank_accounts(user_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_statements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            file_id INTEGER DEFAULT NULL,
            filename TEXT NOT NULL,
            file_format TEXT NOT NULL DEFAULT 'pdf',
            statement_no TEXT NOT NULL DEFAULT '',
            statement_year INTEGER NOT NULL DEFAULT 0,
            period_start TEXT DEFAULT NULL,
            period_end TEXT DEFAULT NULL,
            start_balance REAL DEFAULT NULL,
            end_balance REAL DEFAULT NULL,
            tx_count INTEGER NOT NULL DEFAULT 0,
            total_income REAL NOT NULL DEFAULT 0,
            total_expense REAL NOT NULL DEFAULT 0,
            balance_check_ok INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bank_statements_user_acc ON bank_statements(user_id, account_id, period_end DESC)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            statement_id INTEGER DEFAULT NULL,
            user_id INTEGER NOT NULL,
            file_id INTEGER DEFAULT NULL,
            booking_date TEXT NOT NULL,
            valuta_date TEXT NOT NULL DEFAULT '',
            booking_type TEXT NOT NULL DEFAULT '',
            counterparty TEXT NOT NULL DEFAULT '',
            purpose TEXT NOT NULL DEFAULT '',
            iban TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT 'EUR',
            category TEXT NOT NULL DEFAULT 'other',
            is_fixed_cost INTEGER NOT NULL DEFAULT 0,
            note TEXT NOT NULL DEFAULT '',
            tx_hash TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bank_tx_user_acc_date ON bank_transactions(user_id, account_id, booking_date DESC, id DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bank_tx_hash ON bank_transactions(user_id, account_id, tx_hash)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            keyword TEXT NOT NULL,
            category TEXT NOT NULL,
            is_fixed_cost INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bank_rules_user ON bank_rules(user_id)");

    // Standard-Konto anlegen, falls der Benutzer noch kein Konto hat
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bank_accounts WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $ins = $pdo->prepare("
            INSERT INTO bank_accounts (user_id, name, bank_name, iban, bic, account_type, color, initial_balance)
            VALUES (:uid, 'Postbank Girokonto', 'Postbank', '', 'PBNKDEFF', 'Girokonto', '#2563eb', 0)
        ");
        $ins->execute([':uid' => $userId]);
    }
}

ensureBankingSchema($pdo, $userId);

/**
 * Lädt benutzerdefinierte Kategorisierungs-Regeln.
 *
 * @return array<int, array<string, mixed>>
 */
function getUserBankingRules(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT * FROM bank_rules WHERE user_id = :uid ORDER BY LENGTH(keyword) DESC, id ASC");
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Automatische Kategorisierung & Fixkosten-Erkennung für deutsche Bankbuchungen (inkl. Postbank-Spezifika).
 *
 * @param array<int, array<string, mixed>> $userRules
 * @return array{category:string, is_fixed_cost:int}
 */
function categorizeBankTransaction(string $counterparty, string $purpose, string $bookingType, float $amount, array $userRules = []): array
{
    $haystack = mb_strtolower(trim($counterparty . ' ' . $purpose . ' ' . $bookingType), 'UTF-8');
    $typeLow  = mb_strtolower(trim($bookingType), 'UTF-8');

    // 1. Eigene Regeln des Benutzers haben höchste Priorität
    foreach ($userRules as $r) {
        $kw = mb_strtolower(trim((string)($r['keyword'] ?? '')), 'UTF-8');
        if ($kw !== '' && mb_strpos($haystack, $kw) !== false) {
            return [
                'category'      => (string)$r['category'],
                'is_fixed_cost' => (int)($r['is_fixed_cost'] ?? 0) === 1 ? 1 : 0,
            ];
        }
    }

    $isRecurringHint = (
        mb_strpos($typeLow, 'dauerauftrag') !== false ||
        mb_strpos($haystack, 'dauerauftrag') !== false ||
        mb_strpos($haystack, 'monatlich') !== false ||
        mb_strpos($haystack, 'abschlag') !== false ||
        mb_strpos($haystack, 'beitrag') !== false ||
        mb_strpos($haystack, 'miete') !== false ||
        mb_strpos($haystack, 'abo') !== false
    );

    // 2. Einnahmen (Betrag > 0)
    if ($amount > 0) {
        if (preg_match('/(gehalt|lohn|bezüge|bezuege|entgeltabrechnung|rente|pension|rentenservice|deutsche rentenversicherung|sold|stipendium|arbeitgeber)/u', $haystack)) {
            return ['category' => 'income_salary', 'is_fixed_cost' => 1];
        }
        if (preg_match('/(kindergeld|familienkasse|finanzamt|steuererstattung|bundeskasse|arbeitsagentur|jobcenter|wohngeld|elterngeld|krankenkasse|beihilfe)/u', $haystack)) {
            return ['category' => 'income_gov', 'is_fixed_cost' => 0];
        }
        if (preg_match('/(umbuchung|eigenübertrag|tagesgeld|sparkonto)/u', $haystack)) {
            return ['category' => 'cash_transfer', 'is_fixed_cost' => 0];
        }
        return ['category' => 'income_other', 'is_fixed_cost' => 0];
    }

    // 3. Ausgaben (Betrag <= 0)
    // Wohnen, Miete, Strom, Gas, Wasser, GEZ
    if (preg_match('/(miete|kaltmiete|warmmiete|nebenkosten|hausgeld|hausverwaltung|wohnungsbau|vonovia|leg wohnen|stadtwerke|vattenfall|e\.on|eon |enbw|rheinenergie|mainova|ewb|lichtblick|strom|gasabschlag|heizung|wasserwerk|abwasser|rundfunk|ard zdf|beitragsservice|schornsteinfeger|müllabfuhr|grundsteuer)/u', $haystack)) {
        return ['category' => 'housing', 'is_fixed_cost' => 1];
    }

    // Supermarkt, Lebensmittel, Drogerie, Bäckerei
    if (preg_match('/(rewe|edeka|aldi|lidl|netto|penny|kaufland|norma|globus|tegut|hit markt|real |biomarkt|denns|alnatura|rossmann|dm-drogerie|dm drogerie|mueller|müller drogerie|budni|bäckerei|baeckerei|metzgerei|getränke|getraenke|fressnapf|trinkgut|picnic|flaschenpost)/u', $haystack)) {
        return ['category' => 'groceries', 'is_fixed_cost' => 0];
    }

    // Telekommunikation, Internet, Streaming & Medien-Abos
    if (preg_match('/(telekom|vodafone|1&1|1und1|o2|telefonica|congstar|freenet|drillisch|sim\.de|winsim|alditalk|pyur|deutsche glasfaser|netflix|spotify|disney|amazon prime|prime video|sky |dazn|youtube|apple\.com\/bill|itunes|google play|audible|readly|strato|hetzner|ionos|openai|chatgpt|microsoft)/u', $haystack)) {
        return ['category' => 'telecom_media', 'is_fixed_cost' => 1];
    }

    // Versicherungen, Bankgebühren, Postbank Entgelt, Steuern
    if (preg_match('/(allianz|huk-coburg|huk coburg|huk24|axa |ergo |debeka|signal iduna|devk|generali|provinzial|lvm |r\+v |vhv |gothaer|cosmosdirekt|hansemerkur|arag |adac|rechtsschutz|haftpflicht|hausrat|kfz-versicherung|lebensversicherung|berufsunfähigkeit|kontoführung|kontofuehrung|abschluss|entgelt|buchungsposten|kartenpreis|jahresgebühr|postbank|deutsche bank|sparkasse|volksbank|finanzamt|kfz-steuer)/u', $haystack)) {
        return ['category' => 'insurance_bank', 'is_fixed_cost' => 1];
    }

    // Auto, Tanken, Bahn & ÖPNV
    if (preg_match('/(aral|shell|esso|total|jet tankstelle|star tankstelle|hem |avia|orlen|bft |westfalen ag|tankstelle|adac|atu |werkstatt|tüv|tuev|dekra|parkhaus|parken|easypark|db vertrieb|deutsche bahn|bahn\.de|deutschlandticket|d-ticket|bvg|hvv|mvv|vrs|vrr|rmv|vbb|flixbus|uber|bolt|sixt|tier |lime)/u', $haystack)) {
        return ['category' => 'mobility', 'is_fixed_cost' => $isRecurringHint ? 1 : 0];
    }

    // Gesundheit, Arzt, Apotheke, Fitness
    if (preg_match('/(apotheke|docmorris|shop apotheke|arzt|zahnarzt|praxis|klinik|krankenhaus|optiker|fielmann|apollo optik|mcfit|fitx|clever fit|fitnessstudio|physiotherapie|tk |techniker krankenkasse|aok|barmer|dak |ikk )/u', $haystack)) {
        return ['category' => 'health', 'is_fixed_cost' => $isRecurringHint ? 1 : 0];
    }

    // Sparen, Depot, Wertpapiere, Bausparen
    if (preg_match('/(sparplan|etf|wertpapier|depot|scalable|trade republic|flatex|comdirect|ing-diba|dkb |bauspar|wüstenrot|schwäbisch hall|lbs |tagesgeld)/u', $haystack)) {
        return ['category' => 'savings_invest', 'is_fixed_cost' => $isRecurringHint ? 1 : 0];
    }

    // Bargeld & Geldautomat
    if (preg_match('/(bargeldauszahlung|bargeld|geldautomat|ga nr|cash group|auszahlung|ec-automat|atm )/u', $haystack)) {
        return ['category' => 'cash_transfer', 'is_fixed_cost' => 0];
    }

    // Gastronomie, Freizeit, Lieferdienste, Urlaub
    if (preg_match('/(restaurant|pizzeria|bistro|cafe|café|mcdonalds|mc donalds|burger king|kfc|subway|starbucks|nordsee|vapiano|l\'osteria|lieferando|wolt|uber eats|hellofresh|kino|cinemaxx|uci |theater|konzert|eventim|booking\.com|airbnb|hotel|tui |ryanair|lufthansa|eurowings)/u', $haystack)) {
        return ['category' => 'dining_leisure', 'is_fixed_cost' => 0];
    }

    // Online-Shopping, Baumarkt, Möbel, Bekleidung
    if (preg_match('/(amazon|amzn|ebay|paypal|klarna|otto |zalando|about you|h&m|c&a|zara|uniqlo|decathlon|ikea|obi |bauhaus|hornbach|toom|hagebau|mediamarkt|media markt|saturn|cyberport|notebooksbilliger|thomann|thalia|hugendubel|christ |douglas|tchibo)/u', $haystack)) {
        return ['category' => 'shopping', 'is_fixed_cost' => 0];
    }

    return [
        'category'      => 'other',
        'is_fixed_cost' => $isRecurringHint ? 1 : 0,
    ];
}

/**
 * Parst einen deutschen oder internationalen Geldbetrag wie "- 1.234,56 EUR", "123,45 -", "123,45 S", "+ 450,00 H".
 */
function parseGermanAmountString(string $raw): ?float
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    // Ungewöhnliche Unicode-Minuszeichen und Leerzeichen normalisieren
    $s = str_replace(["\xC2\xA0", "\u{202F}", '−', '–', '—'], [' ', ' ', '-', '-', '-'], $s);
    $s = preg_replace('/\b(EUR|EURO|USD|CHF)\b/i', '', $s) ?? $s;
    $s = str_replace('€', '', $s);
    $s = trim($s);

    $sign = 1.0;
    // Vorzeichen oder Soll/Haben am Anfang oder Ende prüfen
    if (preg_match('/^-\s*/', $s) || preg_match('/\s*-\s*$/', $s) || preg_match('/\s+S$/i', $s) || preg_match('/^S\s+/i', $s)) {
        $sign = -1.0;
    } elseif (preg_match('/^\+\s*/', $s) || preg_match('/\s*\+\s*$/', $s) || preg_match('/\s+H$/i', $s) || preg_match('/^H\s+/i', $s)) {
        $sign = 1.0;
    }

    // Alles außer Ziffern, Punkt und Komma entfernen
    $numPart = preg_replace('/[^0-9.,]/', '', $s) ?? '';
    if ($numPart === '') {
        return null;
    }

    // Deutsches Format: 1.234,56 oder 123,45
    if (strpos($numPart, ',') !== false && strpos($numPart, '.') !== false) {
        $lastComma = strrpos($numPart, ',');
        $lastDot   = strrpos($numPart, '.');
        if ($lastComma > $lastDot) {
            // Punkt = Tausender, Komma = Dezimal
            $numPart = str_replace('.', '', $numPart);
            $numPart = str_replace(',', '.', $numPart);
        } else {
            // Komma = Tausender, Punkt = Dezimal
            $numPart = str_replace(',', '', $numPart);
        }
    } elseif (strpos($numPart, ',') !== false) {
        $numPart = str_replace(',', '.', $numPart);
    }

    if (!is_numeric($numPart)) {
        return null;
    }

    return round((float)$numPart * $sign, 2);
}

/**
 * Normalisiert ein deutsches Datum (DD.MM.YYYY, DD.MM.YY, DD.MM. oder YYYY-MM-DD) in YYYY-MM-DD.
 */
function normalizeBankDateString(string $raw, int $fallbackYear = 0): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if ($fallbackYear <= 1990 || $fallbackYear > 2100) {
        $fallbackYear = (int)date('Y');
    }

    // YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        $y = (int)$m[1];
        $mo = (int)$m[2];
        $d = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    // DD.MM.YYYY oder DD.MM.YY
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m)) {
        $d  = (int)$m[1];
        $mo = (int)$m[2];
        $y  = (int)$m[3];
        if ($y < 100) {
            $y += ($y >= 70 ? 1900 : 2000);
        }
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    // DD.MM. (ohne Jahr, typisch auf Postbank PDF-Kontoauszügen!)
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.?$/', $s, $m)) {
        $d  = (int)$m[1];
        $mo = (int)$m[2];
        $y  = $fallbackYear;
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }

    return null;
}

/**
 * Erzeugt einen deterministischen Hash für eine Bankbuchung zur Duplikat-Vermeidung.
 * `$occurrenceOnSameDay` zählt identische Buchungen innerhalb desselben Kontoauszugs hoch,
 * falls jemand am selben Tag zweimal denselben Betrag beim selben Händler zahlt.
 */
function buildBankTransactionHash(string $bookingDate, float $amount, string $counterparty, string $purpose, int $occurrenceOnSameDay = 1): string
{
    $normCounter = preg_replace('/\s+/', ' ', mb_strtolower(trim($counterparty), 'UTF-8')) ?? '';
    $normPurpose = preg_replace('/\s+/', ' ', mb_strtolower(trim($purpose), 'UTF-8')) ?? '';
    // Nur die ersten 80 Zeichen des Verwendungszwecks nehmen, damit minimale Formatunterschiede zwischen CSV/PDF abgefangen werden
    $key = sprintf('%s|%.2f|%s|%s|#%d', $bookingDate, $amount, mb_substr($normCounter, 0, 50, 'UTF-8'), mb_substr($normPurpose, 0, 80, 'UTF-8'), $occurrenceOnSameDay);
    return substr(hash('sha256', $key), 0, 32);
}

/**
 * Parst CSV-Kontoauszüge (Postbank, Deutsche Bank, Sparkasse, Volksbank/VR, ING, DKB, Comdirect, N26, PayPal).
 * Unterstützt mehrzeilige Vorspanntexte (Postbank IBAN/Kontostand-Vorspann), Semikolon/Komma/Tab,
 * sowie sowohl einzelne Betragsspalten als auch getrennte Soll/Haben-Spalten.
 *
 * @return array{
 *   transactions: array<int, array<string, mixed>>,
 *   start_balance: ?float,
 *   end_balance: ?float,
 *   iban: string,
 *   statement_year: int,
 *   period_start: ?string,
 *   period_end: ?string
 * }
 */
function parseBankStatementCsv(string $rawContent): array
{
    // Encoding prüfen (Postbank & Sparkasse exportieren oft ISO-8859-1 / Windows-1252)
    $content = $rawContent;
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    } elseif (!mb_check_encoding($content, 'UTF-8')) {
        $converted = @mb_convert_encoding($content, 'UTF-8', 'Windows-1252,ISO-8859-1');
        if (is_string($converted) && $converted !== '') {
            $content = $converted;
        }
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $startBalance  = null;
    $endBalance    = null;
    $detectedIban  = '';
    $statementYear = (int)date('Y');

    $headerIdx = -1;
    $delimiter = ';';
    $headerCols = [];

    foreach ($lines as $idx => $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        // Im Vorspann nach IBAN und Kontoständen suchen (Postbank CSV hat oben Metadaten!)
        if ($headerIdx === -1) {
            if ($detectedIban === '' && preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9 ]{11,26})\b/', strtoupper($trimmed), $im)) {
                $candIban = preg_replace('/\s+/', '', $im[1]) ?? '';
                if (strlen($candIban) >= 15 && strlen($candIban) <= 32) {
                    $detectedIban = $candIban;
                }
            }
            if (preg_match('/(alter kontostand|anfangssaldo|startsaldo)[^0-9+-]*([+-]?\s*\d[\d.,]*\s*[+-]?)/iu', $trimmed, $bm)) {
                $val = parseGermanAmountString($bm[2]);
                if ($val !== null) {
                    $startBalance = $val;
                }
            }
            if (preg_match('/(neuer kontostand|aktueller kontostand|endsaldo|kontostand)[^0-9+-]*([+-]?\s*\d[\d.,]*\s*[+-]?)/iu', $trimmed, $bm)) {
                $val = parseGermanAmountString($bm[2]);
                if ($val !== null) {
                    $endBalance = $val;
                }
            }
        }

        // Prüfen, ob diese Zeile die Spaltenüberschrift der Umsatztabelle ist
        $semiCount  = substr_count($trimmed, ';');
        $commaCount = substr_count($trimmed, ',');
        $tabCount   = substr_count($trimmed, "\t");

        $candDelim = ';';
        if ($tabCount > $semiCount && $tabCount > $commaCount) {
            $candDelim = "\t";
        } elseif ($commaCount > $semiCount) {
            $candDelim = ',';
        }

        $cols = str_getcsv($trimmed, $candDelim, '"', '\\');
        if (count($cols) >= 3) {
            $joinedLow = mb_strtolower(implode(' | ', $cols), 'UTF-8');
            $hasDateCol = (
                mb_strpos($joinedLow, 'buchungstag') !== false ||
                mb_strpos($joinedLow, 'buchungsdatum') !== false ||
                mb_strpos($joinedLow, 'datum') !== false ||
                mb_strpos($joinedLow, 'valuta') !== false ||
                mb_strpos($joinedLow, 'date') !== false
            );
            $hasAmountCol = (
                mb_strpos($joinedLow, 'betrag') !== false ||
                mb_strpos($joinedLow, 'umsatz') !== false ||
                mb_strpos($joinedLow, 'soll') !== false ||
                mb_strpos($joinedLow, 'amount') !== false ||
                mb_strpos($joinedLow, 'wert') !== false
            );
            if ($hasDateCol && $hasAmountCol) {
                $headerIdx  = $idx;
                $delimiter  = $candDelim;
                $headerCols = array_map(static fn($c) => mb_strtolower(trim((string)$c, " \t\n\r\0\x0B\""), 'UTF-8'), $cols);
                break;
            }
        }
    }

    if ($headerIdx === -1) {
        return [
            'transactions'   => [],
            'start_balance'  => $startBalance,
            'end_balance'    => $endBalance,
            'iban'           => $detectedIban,
            'statement_year' => $statementYear,
            'period_start'   => null,
            'period_end'     => null,
        ];
    }

    // Spalten-Indizes bestimmen
    $colBookingDate  = -1;
    $colValutaDate   = -1;
    $colType         = -1;
    $colCounterparty = -1;
    $colReceiver     = -1;
    $colSender       = -1;
    $colPurpose      = -1;
    $colIban         = -1;
    $colAmount       = -1;
    $colDebit        = -1; // Soll (Postbank/Deutsche Bank CSV hat oft getrennte Soll / Haben Spalten!)
    $colCredit       = -1; // Haben
    $colShIndicator  = -1; // Soll/Haben-Kennzeichen ('S' / 'H')
    $colCurrency     = -1;
    $colBalanceAfter = -1;

    foreach ($headerCols as $i => $h) {
        if ($colBookingDate === -1 && preg_match('/^(buchungstag|buchungsdatum|datum|buchung|date|completed date)$/', $h)) {
            $colBookingDate = $i;
        } elseif ($colValutaDate === -1 && preg_match('/^(wert|wertstellung|wertstellungsdatum|valuta|valutadatum)$/', $h)) {
            $colValutaDate = $i;
        } elseif ($colType === -1 && preg_match('/(umsatzart|buchungstext|buchungsart|vorgang|art|type)/', $h)) {
            $colType = $i;
        } elseif ($colCounterparty === -1 && preg_match('/(begünstigter|beguenstigter|auftraggeber|empfänger|empfaenger|zahlungsempfänger|zahlungspflichtiger|name|partner|payee)/', $h)) {
            $colCounterparty = $i;
        } elseif ($colReceiver === -1 && preg_match('/(empfänger|empfaenger|begünstigter)/', $h)) {
            $colReceiver = $i;
        } elseif ($colSender === -1 && preg_match('/(auftraggeber|zahlungspflichtiger)/', $h)) {
            $colSender = $i;
        } elseif ($colPurpose === -1 && preg_match('/(verwendungszweck|kundenreferenz|buchungsinformationen|beschreibung|notiz|purpose|reference|details)/', $h)) {
            $colPurpose = $i;
        } elseif ($colIban === -1 && preg_match('/(iban|kontonummer)/', $h)) {
            $colIban = $i;
        } elseif ($colAmount === -1 && preg_match('/^(betrag|betrag in eur|betrag \(eur\)|umsatz|umsatz in eur|amount|brutto)$/', $h)) {
            $colAmount = $i;
        } elseif ($colDebit === -1 && preg_match('/^(soll|lastschrift|ausgabe|belastung)$/', $h)) {
            $colDebit = $i;
        } elseif ($colCredit === -1 && preg_match('/^(haben|gutschrift|einnahme)$/', $h)) {
            $colCredit = $i;
        } elseif ($colShIndicator === -1 && preg_match('/^(soll\/haben|s\/h|kennzeichen)$/', $h)) {
            $colShIndicator = $i;
        } elseif ($colCurrency === -1 && preg_match('/^(währung|waehrung|currency)$/', $h)) {
            $colCurrency = $i;
        } elseif ($colBalanceAfter === -1 && preg_match('/(kontostand|saldo)/', $h)) {
            $colBalanceAfter = $i;
        }
    }

    // Fallback für Betrag-Spalte (falls z.B. "Betrag (EUR)" anders heißt)
    if ($colAmount === -1 && $colDebit === -1) {
        foreach ($headerCols as $i => $h) {
            if (mb_strpos($h, 'betrag') !== false || mb_strpos($h, 'umsatz') !== false) {
                $colAmount = $i;
                break;
            }
        }
    }

    $transactions = [];
    $occurrenceMap = [];

    for ($idx = $headerIdx + 1, $total = count($lines); $idx < $total; $idx++) {
        $line = trim($lines[$idx]);
        if ($line === '') {
            continue;
        }

        // Manche Bank-CSVs haben am Ende eine Summen-/Kontostandszeile ("Kontostand;15.11.2026;;;1.234,56")
        if (preg_match('/^(kontostand|endsaldo|neuer kontostand|anfangssaldo|alter kontostand)/iu', $line)) {
            $parts = str_getcsv($line, $delimiter, '"', '\\');
            foreach (array_reverse($parts) as $p) {
                $v = parseGermanAmountString((string)$p);
                if ($v !== null) {
                    if (preg_match('/^(anfangssaldo|alter kontostand)/iu', $line)) {
                        $startBalance = $v;
                    } else {
                        $endBalance = $v;
                    }
                    break;
                }
            }
            continue;
        }

        $cols = str_getcsv($line, $delimiter, '"', '\\');
        if (count($cols) < 2) {
            continue;
        }

        $rawDate   = $colBookingDate >= 0 ? trim((string)($cols[$colBookingDate] ?? '')) : '';
        $rawValuta = $colValutaDate >= 0 ? trim((string)($cols[$colValutaDate] ?? '')) : '';
        $bDate     = normalizeBankDateString($rawDate, $statementYear) ?? normalizeBankDateString($rawValuta, $statementYear);
        if ($bDate === null) {
            continue;
        }
        $vDate = normalizeBankDateString($rawValuta, (int)substr($bDate, 0, 4)) ?? $bDate;

        // Betrag ermitteln (Einzelspalte oder Soll/Haben-Spalten)
        $amount = null;
        if ($colAmount >= 0 && isset($cols[$colAmount]) && trim((string)$cols[$colAmount]) !== '') {
            $amount = parseGermanAmountString((string)$cols[$colAmount]);
            if ($amount !== null && $colShIndicator >= 0 && isset($cols[$colShIndicator])) {
                $sh = strtoupper(trim((string)$cols[$colShIndicator]));
                if ($sh === 'S' || $sh === 'SOLL' || $sh === 'DBIT') {
                    $amount = -abs($amount);
                } elseif ($sh === 'H' || $sh === 'HABEN' || $sh === 'CRDT') {
                    $amount = abs($amount);
                }
            }
        } elseif ($colDebit >= 0 || $colCredit >= 0) {
            $debStr  = $colDebit >= 0 ? trim((string)($cols[$colDebit] ?? '')) : '';
            $credStr = $colCredit >= 0 ? trim((string)($cols[$colCredit] ?? '')) : '';
            if ($debStr !== '' && $debStr !== '0' && $debStr !== '0,00') {
                $dVal = parseGermanAmountString($debStr);
                if ($dVal !== null && abs($dVal) > 0.0001) {
                    $amount = -abs($dVal);
                }
            }
            if ($amount === null && $credStr !== '' && $credStr !== '0' && $credStr !== '0,00') {
                $cVal = parseGermanAmountString($credStr);
                if ($cVal !== null) {
                    $amount = abs($cVal);
                }
            }
        }

        if ($amount === null) {
            continue;
        }

        $bType = $colType >= 0 ? trim((string)($cols[$colType] ?? '')) : '';
        $counterparty = $colCounterparty >= 0 ? trim((string)($cols[$colCounterparty] ?? '')) : '';
        if ($counterparty === '') {
            $rec = $colReceiver >= 0 ? trim((string)($cols[$colReceiver] ?? '')) : '';
            $snd = $colSender >= 0 ? trim((string)($cols[$colSender] ?? '')) : '';
            $counterparty = $amount < 0 ? ($rec !== '' ? $rec : $snd) : ($snd !== '' ? $snd : $rec);
        }
        $purpose  = $colPurpose >= 0 ? trim((string)($cols[$colPurpose] ?? '')) : '';
        $iban     = $colIban >= 0 ? preg_replace('/\s+/', '', trim((string)($cols[$colIban] ?? ''))) ?? '' : '';
        $currency = $colCurrency >= 0 && trim((string)($cols[$colCurrency] ?? '')) !== '' ? strtoupper(trim((string)$cols[$colCurrency])) : 'EUR';

        if ($counterparty === '' && $purpose !== '') {
            $counterparty = mb_substr($purpose, 0, 50, 'UTF-8');
        }

        if ($endBalance === null && $colBalanceAfter >= 0 && isset($cols[$colBalanceAfter])) {
            $bAfter = parseGermanAmountString((string)$cols[$colBalanceAfter]);
            if ($bAfter !== null && empty($transactions)) {
                // Erste Zeile in absteigend sortierter CSV = neuester Kontostand
                $endBalance = $bAfter;
            }
        }

        $baseKey = $bDate . '|' . number_format($amount, 2, '.', '') . '|' . mb_strtolower($counterparty, 'UTF-8') . '|' . mb_strtolower(mb_substr($purpose, 0, 60, 'UTF-8'), 'UTF-8');
        $occurrenceMap[$baseKey] = ($occurrenceMap[$baseKey] ?? 0) + 1;

        $transactions[] = [
            'booking_date' => $bDate,
            'valuta_date'  => $vDate,
            'booking_type' => $bType !== '' ? $bType : ($amount >= 0 ? 'Gutschrift' : 'Lastschrift'),
            'counterparty' => $counterparty,
            'purpose'      => $purpose,
            'iban'         => $iban,
            'amount'       => $amount,
            'currency'     => $currency,
            'tx_hash'      => buildBankTransactionHash($bDate, $amount, $counterparty, $purpose, $occurrenceMap[$baseKey]),
        ];
    }

    $periodStart = null;
    $periodEnd   = null;
    foreach ($transactions as $t) {
        $d = (string)$t['booking_date'];
        if ($periodStart === null || $d < $periodStart) {
            $periodStart = $d;
        }
        if ($periodEnd === null || $d > $periodEnd) {
            $periodEnd = $d;
        }
    }
    if ($periodEnd !== null) {
        $statementYear = (int)substr($periodEnd, 0, 4);
    }

    return [
        'transactions'   => $transactions,
        'start_balance'  => $startBalance,
        'end_balance'    => $endBalance,
        'iban'           => $detectedIban,
        'statement_year' => $statementYear,
        'period_start'   => $periodStart,
        'period_end'     => $periodEnd,
    ];
}

/**
 * Extrahiert strukturierten, zeilenweisen Text aus einer PDF-Datei auf dem Server.
 * Nutzt vorrangig `pdftotext -layout` (erhält Tabellenspalten von Postbank-Kontoauszügen perfekt),
 * danach `gs -sDEVICE=txtwrite`, und als Pure-PHP-Fallback einen CMap + Hex + Koordinaten-Parser.
 */
function extractBankPdfTextServer(string $pdfPath): string
{
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        return '';
    }

    if (function_exists('shell_exec')) {
        $pdftotext = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($pdftotext !== '') {
            $out = (string)@shell_exec(escapeshellcmd($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' - 2>/dev/null');
            if (trim($out) !== '') {
                return $out;
            }
        }
        $gs = trim((string)@shell_exec('command -v gs 2>/dev/null'));
        if ($gs !== '') {
            $out = (string)@shell_exec(escapeshellcmd($gs) . ' -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -sOutputFile=- ' . escapeshellarg($pdfPath) . ' 2>/dev/null');
            if (trim($out) !== '') {
                return $out;
            }
        }
    }

    // Pure-PHP PDF-Text-Extraktor mit ToUnicode CMap, <HEX>-Strings und Zeilen-Erkennung
    $raw = (string)@file_get_contents($pdfPath);
    if ($raw === '') {
        return '';
    }

    $streams = [];
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $raw, $m)) {
        foreach ($m[1] as $streamData) {
            $dec = @gzuncompress($streamData);
            if ($dec === false) {
                $dec = @gzinflate($streamData);
            }
            $streams[] = ($dec !== false && $dec !== '') ? $dec : $streamData;
        }
    } else {
        $streams[] = $raw;
    }

    // 1. ToUnicode CMaps aus allen Streams sammeln (wichtig für Bank-PDFs!)
    $cmap = [];
    foreach ($streams as $st) {
        if (strpos($st, 'beginbfchar') !== false && preg_match_all('/beginbfchar(.*?)endbfchar/s', $st, $bfm)) {
            foreach ($bfm[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $srcHex = strtoupper($p[1]);
                        $dstHex = $p[2];
                        $utf16  = @pack('H*', $dstHex);
                        if ($utf16 !== false) {
                            $cmap[$srcHex] = @mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE') ?: '';
                        }
                    }
                }
            }
        }
        if (strpos($st, 'beginbfrange') !== false && preg_match_all('/beginbfrange(.*?)endbfrange/s', $st, $bfr)) {
            foreach ($bfr[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) {
                        $start = hexdec($r[1]);
                        $end   = hexdec($r[2]);
                        $dst   = hexdec($r[3]);
                        $len   = strlen($r[1]);
                        if ($end >= $start && ($end - $start) <= 512) {
                            for ($c = $start; $c <= $end; $c++) {
                                $kHex = strtoupper(str_pad(dechex($c), $len, '0', STR_PAD_LEFT));
                                $uHex = str_pad(dechex($dst + ($c - $start)), 4, '0', STR_PAD_LEFT);
                                $utf16 = @pack('H*', $uHex);
                                if ($utf16 !== false) {
                                    $cmap[$kHex] = @mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE') ?: '';
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $decodePdfToken = static function (string $token) use ($cmap): string {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if ($token[0] === '<' && substr($token, -1) === '>') {
            $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', substr($token, 1, -1)) ?? '');
            if ($hex === '') {
                return '';
            }
            if (!empty($cmap)) {
                $out = '';
                $step = isset($cmap[substr($hex, 0, 4)]) ? 4 : 2;
                for ($i = 0, $l = strlen($hex); $i < $l; $i += $step) {
                    $chunk = substr($hex, $i, $step);
                    if (isset($cmap[$chunk])) {
                        $out .= $cmap[$chunk];
                    } else {
                        $b = @pack('H*', $chunk);
                        if ($b !== false) {
                            $out .= @mb_convert_encoding($b, 'UTF-8', 'Windows-1252,ISO-8859-1') ?: '';
                        }
                    }
                }
                return $out;
            }
            $bin = @pack('H*', $hex);
            if ($bin === false) {
                return '';
            }
            return @mb_convert_encoding($bin, 'UTF-8', 'Windows-1252,ISO-8859-1') ?: '';
        }
        if ($token[0] === '(' && substr($token, -1) === ')') {
            $str = substr($token, 1, -1);
            $str = preg_replace_callback('/\\\\([0-7]{1,3})/', static fn($m) => chr(octdec($m[1])), $str) ?? $str;
            $str = strtr($str, ['\\n' => "\n", '\\r' => "\r", '\\t' => ' ', '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
            if (!mb_check_encoding($str, 'UTF-8')) {
                $str = @mb_convert_encoding($str, 'UTF-8', 'Windows-1252,ISO-8859-1') ?: $str;
            }
            return $str;
        }
        return '';
    };

    $lines = [];
    foreach ($streams as $st) {
        if (strpos($st, 'beginbfchar') !== false || strpos($st, 'beginbfrange') !== false) {
            continue;
        }
        if (preg_match_all('/BT(.*?)ET/s', $st, $btBlocks)) {
            $curLine = '';
            $lastY   = null;
            foreach ($btBlocks[1] as $bt) {
                // Y-Koordinate aus Tm oder Td prüfen
                if (preg_match('/([+-]?\d+(?:\.\d+)?)\s+([+-]?\d+(?:\.\d+)?)\s+(?:Td|TD)\b/', $bt, $tdm)) {
                    $dy = (float)$tdm[2];
                    if (abs($dy) > 1.5 && trim($curLine) !== '') {
                        $lines[] = trim($curLine);
                        $curLine = '';
                    }
                } elseif (preg_match('/[+-]?\d+(?:\.\d+)?\s+[+-]?\d+(?:\.\d+)?\s+[+-]?\d+(?:\.\d+)?\s+[+-]?\d+(?:\.\d+)?\s+([+-]?\d+(?:\.\d+)?)\s+([+-]?\d+(?:\.\d+)?)\s+Tm\b/', $bt, $tmm)) {
                    $y = (float)$tmm[2];
                    if ($lastY !== null && abs($y - $lastY) > 2.5 && trim($curLine) !== '') {
                        $lines[] = trim($curLine);
                        $curLine = '';
                    }
                    $lastY = $y;
                }

                if (preg_match_all('/(\((?:\\\\.|[^\\\\()])*\)|<[0-9A-Fa-f\s]+>)/', $bt, $toks)) {
                    $piece = '';
                    foreach ($toks[1] as $tk) {
                        $piece .= $decodePdfToken($tk);
                    }
                    if (trim($piece) !== '') {
                        $curLine .= ($curLine !== '' ? '  ' : '') . trim($piece);
                    }
                }
            }
            if (trim($curLine) !== '') {
                $lines[] = trim($curLine);
            }
        }
    }

    return implode("\n", $lines);
}

/**
 * Parst den extrahierten Text eines PDF-Kontoauszugs (speziell optimiert für Postbank PDF-Kontoauszüge
 * im klassischen und neuen Deutsche-Bank-Layout sowie Sparkasse, Volksbank/VR, ING, DKB, Comdirect).
 *
 * @return array{
 *   transactions: array<int, array<string, mixed>>,
 *   start_balance: ?float,
 *   end_balance: ?float,
 *   iban: string,
 *   statement_no: string,
 *   statement_year: int,
 *   period_start: ?string,
 *   period_end: ?string
 * }
 */
function parseBankStatementText(string $rawText, string $filenameHint = ''): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $rawText);
    $rawLines = explode("\n", $text);

    $statementYear = (int)date('Y');
    $statementNo   = '';
    $detectedIban  = '';
    $startBalance  = null;
    $endBalance    = null;

    // 1. Jahr & Auszugsnummer aus Dateinamen oder Kopfbereich erkennen
    if (preg_match('/\b(20\d{2})\b/', $filenameHint, $ym)) {
        $statementYear = (int)$ym[1];
    }
    if (preg_match('/(?:Auszug|Kontoauszug)\s*(?:Nr\.?|Nummer)?\s*(\d{1,3})\s*(?:\/|-|\s+von\s+|\s+aus\s+)?\s*(20\d{2})?/iu', $text, $am)) {
        $statementNo = trim($am[1]);
        if (!empty($am[2])) {
            $statementYear = (int)$am[2];
        }
    }
    // Falls im Text vollständige Daten DD.MM.20XX vorkommen, das häufigste Jahr als Auszugsjahr nutzen
    if (preg_match_all('/\b\d{2}\.\d{2}\.(20\d{2})\b/', $text, $allYears) && !empty($allYears[1])) {
        $counts = array_count_values($allYears[1]);
        arsort($counts);
        $topYear = (int)array_key_first($counts);
        if ($topYear >= 2000 && $topYear <= 2100) {
            $statementYear = $topYear;
        }
    }

    // IBAN im Kopfbereich erkennen
    if (preg_match('/\b(DE\d{2}\s*(?:\d{4}\s*){4}\d{2})\b/i', $text, $im)) {
        $detectedIban = preg_replace('/\s+/', '', strtoupper($im[1])) ?? '';
    }

    // 2. Alter Kontostand / Anfangssaldo & Neuer Kontostand / Endsaldo erkennen
    foreach ($rawLines as $idx => $line) {
        $clean = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($clean === '') {
            continue;
        }
        // Postbank & allgemeine Bank-Begriffe für Anfangssaldo
        if ($startBalance === null && preg_match('/(alter kontostand|anfangssaldo|kontostand am|letzter kontostand|vorheriger kontostand)/iu', $clean)) {
            if (preg_match('/([+-]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]\b)?)/iu', $clean, $bm)) {
                // Sicherstellen, dass es kein Datum wie 01.03. ist
                $val = parseGermanAmountString($bm[1]);
                if ($val !== null) {
                    $startBalance = $val;
                }
            } elseif (isset($rawLines[$idx + 1]) && preg_match('/([+-]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]\b)?)/iu', $rawLines[$idx + 1], $bm2)) {
                $val = parseGermanAmountString($bm2[1]);
                if ($val !== null) {
                    $startBalance = $val;
                }
            }
        }
        // Postbank & allgemeine Bank-Begriffe für Endsaldo
        if (preg_match('/(neuer kontostand|aktueller kontostand|endsaldo|abschlusssaldo|kontostand neu)/iu', $clean)) {
            if (preg_match('/([+-]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]\b)?)/iu', $clean, $bm)) {
                $val = parseGermanAmountString($bm[1]);
                if ($val !== null) {
                    $endBalance = $val;
                }
            } elseif (isset($rawLines[$idx + 1]) && preg_match('/([+-]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]\b)?)/iu', $rawLines[$idx + 1], $bm2)) {
                $val = parseGermanAmountString($bm2[1]);
                if ($val !== null) {
                    $endBalance = $val;
                }
            }
        }
    }

    // Bekannte Buchungsarten (Postbank, Sparkasse, VR, ING, DKB) zum sauberen Abtrennen vom Empfänger
    $knownBookingTypes = [
        'Echtzeitüberweisung', 'SEPA-Lastschrift', 'SEPA-Überweisung', 'SEPA-Gutschrift',
        'Kartenzahlung', 'Kartenverfügung', 'Girocard', 'Lastschrift', 'Überweisung', 'Ueberweisung',
        'Gutschrift', 'Dauerauftrag', 'Bargeldauszahlung', 'Auszahlung', 'Einzahlung',
        'Abschluss', 'Entgelt', 'Zinsen', 'Dividende', 'Wertpapierkauf', 'Wertpapierverkauf',
        'Scheckeinreichung', 'Rücklastschrift', 'Gehalt/Rente', 'Lohn/Gehalt',
    ];

    // Zeilen-Filter für Kopf-/Fußzeilen auf mehrseitigen Postbank-PDFs
    $isNoiseLine = static function (string $l): bool {
        if ($l === '') {
            return true;
        }
        if (preg_match('/^(Seite\s+\d+\s*(von|\/)\s*\d+|Blatt\s+\d+|Auszug\s+\d+|Kontoauszug|Buchung\s*\/?\s*Wert|Buchungstag|Vorgang\s+Valuta|Datum\s+Wertstellung|Übertrag\s+auf|Übertrag\s+von|Zwischensaldo)/iu', $l)) {
            return true;
        }
        if (preg_match('/(Postbank\s*[–-]\s*eine\s*Niederlassung|Deutsche Bank AG|Vorsitzender des Aufsichtsrats|Amtsgericht|USt-IdNr|BIC:\s*PBNKDEFF|IBAN\s+DE\d{2}|Konto-Nr\.|BLZ\s+\d{3}|Bitte beachten Sie|Einlagensicherung|Rechnungsabschluss)/iu', $l)) {
            return true;
        }
        return false;
    };

    $transactions  = [];
    $occurrenceMap = [];
    $currentTx     = null;

    $flushCurrentTx = static function () use (&$currentTx, &$transactions, &$occurrenceMap, $knownBookingTypes): void {
        if ($currentTx === null) {
            return;
        }
        $bDate  = (string)$currentTx['booking_date'];
        $vDate  = (string)$currentTx['valuta_date'];
        $amount = (float)$currentTx['amount'];
        $head   = trim((string)$currentTx['head_text']);
        $extra  = $currentTx['detail_lines'];

        $bType = '';
        $counterparty = '';
        $purposeParts = [];

        // Prüfen, ob head_text mit einer bekannten Buchungsart beginnt
        foreach ($knownBookingTypes as $kt) {
            if (mb_stripos($head, $kt, 0, 'UTF-8') === 0) {
                $bType = $kt;
                $rest  = trim(mb_substr($head, mb_strlen($kt, 'UTF-8'), null, 'UTF-8'), " \t-–/:");
                if ($rest !== '') {
                    $counterparty = $rest;
                }
                break;
            }
        }

        if ($bType === '') {
            // Falls kein bekannter Typ am Anfang stand: Ist head selbst kurz wie eine Umsatzart und Zeile 1 der Empfänger?
            if (!empty($extra) && mb_strlen($head, 'UTF-8') <= 28 && !preg_match('/\d{4}/', $head)) {
                $bType        = $head;
                $counterparty = (string)array_shift($extra);
            } else {
                $bType        = $amount >= 0 ? 'Gutschrift' : 'Buchung';
                $counterparty = $head;
            }
        } elseif ($counterparty === '' && !empty($extra)) {
            $counterparty = (string)array_shift($extra);
        }

        foreach ($extra as $el) {
            $el = trim((string)$el);
            if ($el !== '') {
                $purposeParts[] = $el;
            }
        }

        $purpose = implode(' · ', $purposeParts);
        if ($counterparty === '' && $purpose !== '') {
            $counterparty = mb_substr($purpose, 0, 50, 'UTF-8');
        }
        if ($counterparty === '') {
            $counterparty = $bType !== '' ? $bType : 'Kontobuchung';
        }

        // Eventuelle IBAN aus den Detailzeilen extrahieren
        $txIban = '';
        if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{11,26})\b/', strtoupper($counterparty . ' ' . $purpose), $im)) {
            $txIban = $im[1];
        }

        $baseKey = $bDate . '|' . number_format($amount, 2, '.', '') . '|' . mb_strtolower($counterparty, 'UTF-8') . '|' . mb_strtolower(mb_substr($purpose, 0, 60, 'UTF-8'), 'UTF-8');
        $occurrenceMap[$baseKey] = ($occurrenceMap[$baseKey] ?? 0) + 1;

        $transactions[] = [
            'booking_date' => $bDate,
            'valuta_date'  => $vDate,
            'booking_type' => $bType,
            'counterparty' => $counterparty,
            'purpose'      => $purpose,
            'iban'         => $txIban,
            'amount'       => $amount,
            'currency'     => 'EUR',
            'tx_hash'      => buildBankTransactionHash($bDate, $amount, $counterparty, $purpose, $occurrenceMap[$baseKey]),
        ];

        $currentTx = null;
    };

    // Regex für eine Buchungszeile:
    // Unterstützt:
    // - "12.03./12.03.  SEPA-Lastschrift Stadtwerke   - 85,00" (Postbank Klassik)
    // - "12.03. 13.03.  Kartenzahlung REWE Sagt Danke   -24,99" (Postbank / DB / Sparkasse)
    // - "12.03.2026 12.03.2026  Gutschrift Gehalt   + 2.450,00 EUR"
    // - "12.03.2026  Überweisung Max Mustermann   120,00 S"
    $bookingLineRegex = '/^(\d{2}\.\d{2}\.(?:\d{2,4})?)(?:\s*[\/|\s]\s*(\d{2}\.\d{2}\.(?:\d{2,4})?))?\s+(.+?)\s+([+-]?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]\b)?)\s*(?:EUR|€)?$/iu';

    for ($i = 0, $n = count($rawLines); $i < $n; $i++) {
        $line = trim(preg_replace('/\s+/', ' ', $rawLines[$i]) ?? '');
        if ($line === '') {
            continue;
        }

        // Prüfen, ob Kontostands-Ende erreicht ist -> offene Buchung abschließen
        if (preg_match('/^(Neuer Kontostand|Endsaldo|Abschlusssaldo|Alter Kontostand|Anfangssaldo|Kontostand am)/iu', $line)) {
            $flushCurrentTx();
            continue;
        }

        if ($isNoiseLine($line)) {
            continue;
        }

        // Fall 1: Komplette Buchungszeile mit Datum vorne und Betrag hinten
        if (preg_match($bookingLineRegex, $line, $m)) {
            $flushCurrentTx();

            $d1   = normalizeBankDateString($m[1], $statementYear);
            $d2   = !empty($m[2]) ? normalizeBankDateString($m[2], $statementYear) : $d1;
            $head = trim($m[3]);
            $amt  = parseGermanAmountString($m[4]);

            // Falls im Buchungstext kein Vorzeichen am Betrag stand, aber das Wort Lastschrift/Auszahlung/Kartenzahlung vorkommt
            if ($amt !== null && $amt > 0 && !preg_match('/(\+|H\b|gutschrift|eingang|gehalt|lohn|rente|erstattung)/iu', $m[4] . ' ' . $head)) {
                if (preg_match('/(lastschrift|kartenzahlung|auszahlung|dauerauftrag|überweisung|abrechnung|entgelt|gebühr)/iu', $head)) {
                    $amt = -abs($amt);
                }
            }

            if ($d1 !== null && $amt !== null && !preg_match('/^(alter kontostand|neuer kontostand|anfangssaldo|endsaldo|übertrag)/iu', $head)) {
                $currentTx = [
                    'booking_date' => $d1,
                    'valuta_date'  => $d2 ?? $d1,
                    'head_text'    => $head,
                    'amount'       => $amt,
                    'detail_lines' => [],
                ];
                continue;
            }
        }

        // Fall 2: Zweizeiliger Buchungskopf (Zeile 1 hat "DD.MM. / DD.MM.  Umsatzart", Zeile 2 endet mit dem Betrag)
        if (preg_match('/^(\d{2}\.\d{2}\.(?:\d{2,4})?)(?:\s*[\/|\s]\s*(\d{2}\.\d{2}\.(?:\d{2,4})?))?\s+(.+)$/u', $line, $m2) && isset($rawLines[$i + 1])) {
            $nextLine = trim(preg_replace('/\s+/', ' ', $rawLines[$i + 1]) ?? '');
            if (preg_match('/^(.*?)\s*([+-]\s*\d{1,3}(?:\.\d{3})*,\d{2}|\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:[+-]|[SH]))\s*(?:EUR|€)?$/iu', $nextLine, $mNext)
                && !preg_match('/^\d{2}\.\d{2}\./', $nextLine)) {
                $d1  = normalizeBankDateString($m2[1], $statementYear);
                $d2  = !empty($m2[2]) ? normalizeBankDateString($m2[2], $statementYear) : $d1;
                $amt = parseGermanAmountString($mNext[2]);
                if ($d1 !== null && $amt !== null) {
                    $flushCurrentTx();
                    $currentTx = [
                        'booking_date' => $d1,
                        'valuta_date'  => $d2 ?? $d1,
                        'head_text'    => trim($m2[3]),
                        'amount'       => $amt,
                        'detail_lines' => trim($mNext[1]) !== '' ? [trim($mNext[1])] : [],
                    ];
                    $i++; // nächste Zeile wurde bereits konsumiert
                    continue;
                }
            }
        }

        // Detailzeile (Empfänger / Verwendungszweck) zur aktuellen Buchung anhängen
        if ($currentTx !== null && count($currentTx['detail_lines']) < 5) {
            $currentTx['detail_lines'][] = $line;
        }
    }

    $flushCurrentTx();

    $periodStart = null;
    $periodEnd   = null;
    foreach ($transactions as $t) {
        $d = (string)$t['booking_date'];
        if ($periodStart === null || $d < $periodStart) {
            $periodStart = $d;
        }
        if ($periodEnd === null || $d > $periodEnd) {
            $periodEnd = $d;
        }
    }

    return [
        'transactions'   => $transactions,
        'start_balance'  => $startBalance,
        'end_balance'    => $endBalance,
        'iban'           => $detectedIban,
        'statement_no'   => $statementNo,
        'statement_year' => $statementYear,
        'period_start'   => $periodStart,
        'period_end'     => $periodEnd,
    ];
}

/**
 * Speichert eine hochgeladene Kontoauszugs-Datei (PDF/CSV) automatisch im MiniCloud-Dateimanager
 * unter `Kontoauszüge/<Kontoname>/<Jahr>` und gibt die `file_id` zurück.
 */
function archiveStatementInCloudFiles(PDO $pdo, int $userId, string $accountName, int $year, string $originalName, string $tmpOrSourcePath, string $extractedText = ''): ?int
{
    if (!is_file($tmpOrSourcePath)) {
        return null;
    }
    $size = (int)@filesize($tmpOrSourcePath);
    if ($size <= 0) {
        return null;
    }

    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'csv', 'txt'], true)) {
        $ext = 'pdf';
    }

    $safeAccountFolder = trim(preg_replace('/[^a-zA-Z0-9äöüÄÖÜß _.-]/u', '', $accountName) ?? 'Postbank');
    if ($safeAccountFolder === '') {
        $safeAccountFolder = 'Konto';
    }
    $yearFolder = ($year >= 2000 && $year <= 2100) ? (string)$year : date('Y');
    $folderPath = normalizeFolderPath('Kontoauszüge/' . $safeAccountFolder . '/' . $yearFolder);

    $userUploadDir = getUserUploadDir($userId);
    $storedName    = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath      = $userUploadDir . '/' . $storedName;

    if (!@copy($tmpOrSourcePath, $destPath)) {
        return null;
    }

    // Ordner-Hierarchie in user_folders eintragen, damit sie in index.php sofort sichtbar ist
    $parts = explode('/', $folderPath);
    $accum = '';
    foreach ($parts as $p) {
        $accum = $accum === '' ? $p : ($accum . '/' . $p);
        $fStmt = $pdo->prepare("INSERT OR IGNORE INTO user_folders (user_id, folder_path) VALUES (:uid, :fp)");
        $fStmt->execute([':uid' => $userId, ':fp' => $accum]);
    }

    $ins = $pdo->prepare("
        INSERT INTO files (user_id, folder_path, original_name, stored_name, file_size)
        VALUES (:uid, :folder, :orig, :stored, :size)
    ");
    $ins->execute([
        ':uid'    => $userId,
        ':folder' => $folderPath,
        ':orig'   => sanitizeFilename($originalName),
        ':stored' => $storedName,
        ':size'   => $size,
    ]);
    $fileId = (int)$pdo->lastInsertId();

    $uStmt = $pdo->prepare("UPDATE users SET storage_used = storage_used + :sz WHERE id = :uid");
    $uStmt->execute([':sz' => $size, ':uid' => $userId]);

    if ($extractedText !== '' && function_exists('indexFileFulltext')) {
        try {
            $ftStmt = $pdo->prepare("
                INSERT INTO file_fulltext (file_id, user_id, content_text, updated_at)
                VALUES (:fid, :uid, :txt, CURRENT_TIMESTAMP)
                ON CONFLICT(file_id) DO UPDATE SET content_text = excluded.content_text, updated_at = CURRENT_TIMESTAMP
            ");
            $ftStmt->execute([
                ':fid' => $fileId,
                ':uid' => $userId,
                ':txt' => mb_substr($extractedText, 0, 120000, 'UTF-8'),
            ]);
        } catch (Throwable $e) {
            // Ignorieren falls Volltexttabelle noch nicht initialisiert
        }
    }

    return $fileId;
}

/**
 * Speichert geparste Umsätze und den Kontoauszugs-Datensatz in der Datenbank,
 * führt die automatische Kategorisierung sowie die Cent-genaue Saldo-Prüfung durch.
 *
 * @param array<string, mixed> $parsed
 * @return array{inserted:int, skipped:int, statement_id:int, balance_check_ok:?int}
 */
function importParsedBankStatement(
    PDO $pdo,
    int $userId,
    int $accountId,
    string $filename,
    string $format,
    ?int $fileId,
    array $parsed
): array {
    $userRules = getUserBankingRules($pdo, $userId);
    $txList    = is_array($parsed['transactions'] ?? null) ? $parsed['transactions'] : [];

    $startBal      = isset($parsed['start_balance']) && $parsed['start_balance'] !== null ? (float)$parsed['start_balance'] : null;
    $endBal        = isset($parsed['end_balance']) && $parsed['end_balance'] !== null ? (float)$parsed['end_balance'] : null;
    $statementNo   = (string)($parsed['statement_no'] ?? '');
    $statementYear = (int)($parsed['statement_year'] ?? (int)date('Y'));
    $periodStart   = $parsed['period_start'] ?? null;
    $periodEnd     = $parsed['period_end'] ?? null;
    $detectedIban  = (string)($parsed['iban'] ?? '');

    // Falls das Konto noch keine IBAN hinterlegt hat und im Auszug eine gefunden wurde -> automatisch ergänzen
    if ($detectedIban !== '') {
        $ibStmt = $pdo->prepare("UPDATE bank_accounts SET iban = :iban WHERE id = :aid AND user_id = :uid AND (iban = '' OR iban IS NULL)");
        $ibStmt->execute([':iban' => $detectedIban, ':aid' => $accountId, ':uid' => $userId]);
    }

    $totalIncome  = 0.0;
    $totalExpense = 0.0;
    foreach ($txList as $t) {
        $amt = (float)($t['amount'] ?? 0);
        if ($amt >= 0) {
            $totalIncome += $amt;
        } else {
            $totalExpense += abs($amt);
        }
    }
    $totalIncome  = round($totalIncome, 2);
    $totalExpense = round($totalExpense, 2);

    // Cent-genaue Saldo-Prüfung (Alter Kontostand + Einnahmen - Ausgaben == Neuer Kontostand)
    $balanceCheckOk = null;
    if ($startBal !== null && $endBal !== null && !empty($txList)) {
        $expectedEnd = round($startBal + $totalIncome - $totalExpense, 2);
        $balanceCheckOk = (abs($expectedEnd - $endBal) <= 0.02) ? 1 : 0;
    }

    $stIns = $pdo->prepare("
        INSERT INTO bank_statements (
            account_id, user_id, file_id, filename, file_format, statement_no, statement_year,
            period_start, period_end, start_balance, end_balance, tx_count,
            total_income, total_expense, balance_check_ok
        ) VALUES (
            :aid, :uid, :fid, :fname, :fmt, :sno, :syear,
            :pstart, :pend, :sbal, :ebal, :txc,
            :inc, :exp, :bok
        )
    ");
    $stIns->execute([
        ':aid'    => $accountId,
        ':uid'    => $userId,
        ':fid'    => $fileId,
        ':fname'  => $filename,
        ':fmt'    => $format,
        ':sno'    => $statementNo,
        ':syear'  => $statementYear,
        ':pstart' => $periodStart,
        ':pend'   => $periodEnd,
        ':sbal'   => $startBal,
        ':ebal'   => $endBal,
        ':txc'    => count($txList),
        ':inc'    => $totalIncome,
        ':exp'    => $totalExpense,
        ':bok'    => $balanceCheckOk,
    ]);
    $statementId = (int)$pdo->lastInsertId();

    $chkStmt = $pdo->prepare("SELECT id FROM bank_transactions WHERE user_id = :uid AND account_id = :aid AND tx_hash = :hash LIMIT 1");
    $txIns   = $pdo->prepare("
        INSERT INTO bank_transactions (
            account_id, statement_id, user_id, file_id, booking_date, valuta_date,
            booking_type, counterparty, purpose, iban, amount, currency,
            category, is_fixed_cost, note, tx_hash
        ) VALUES (
            :aid, :sid, :uid, :fid, :bdate, :vdate,
            :btype, :cparty, :purp, :iban, :amt, :curr,
            :cat, :fixed, '', :hash
        )
    ");

    $inserted = 0;
    $skipped  = 0;

    foreach ($txList as $t) {
        $hash = (string)($t['tx_hash'] ?? '');
        if ($hash !== '') {
            $chkStmt->execute([':uid' => $userId, ':aid' => $accountId, ':hash' => $hash]);
            if ($chkStmt->fetchColumn()) {
                $skipped++;
                continue;
            }
        }

        $cparty = (string)($t['counterparty'] ?? '');
        $purp   = (string)($t['purpose'] ?? '');
        $btype  = (string)($t['booking_type'] ?? '');
        $amt    = round((float)($t['amount'] ?? 0), 2);

        $catInfo = categorizeBankTransaction($cparty, $purp, $btype, $amt, $userRules);

        $txIns->execute([
            ':aid'    => $accountId,
            ':sid'    => $statementId,
            ':uid'    => $userId,
            ':fid'    => $fileId,
            ':bdate'  => (string)$t['booking_date'],
            ':vdate'  => (string)($t['valuta_date'] ?? $t['booking_date']),
            ':btype'  => $btype,
            ':cparty' => $cparty,
            ':purp'   => $purp,
            ':iban'   => (string)($t['iban'] ?? ''),
            ':amt'    => $amt,
            ':curr'   => (string)($t['currency'] ?? 'EUR'),
            ':cat'    => $catInfo['category'],
            ':fixed'  => $catInfo['is_fixed_cost'],
            ':hash'   => $hash,
        ]);
        $inserted++;
    }

    return [
        'inserted'         => $inserted,
        'skipped'          => $skipped,
        'statement_id'     => $statementId,
        'balance_check_ok' => $balanceCheckOk,
    ];
}

// =========================================================================
// Konten des Benutzers laden & aktives Konto bestimmen
// =========================================================================
$accStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE user_id = :uid ORDER BY id ASC");
$accStmt->execute([':uid' => $userId]);
$accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedAccountId = isset($_REQUEST['account_id']) ? (int)$_REQUEST['account_id'] : (int)($accounts[0]['id'] ?? 0);
$activeAccount = null;
foreach ($accounts as $acc) {
    if ((int)$acc['id'] === $selectedAccountId) {
        $activeAccount = $acc;
        break;
    }
}
if ($activeAccount === null && !empty($accounts)) {
    $activeAccount     = $accounts[0];
    $selectedAccountId = (int)$activeAccount['id'];
}

$bankingCategories = getBankingCategories();
$flashSuccess = '';
$flashError   = '';
$activeTab    = trim((string)($_GET['tab'] ?? 'cockpit'));
if (!in_array($activeTab, ['cockpit', 'transactions', 'statements', 'settings'], true)) {
    $activeTab = 'cockpit';
}

// =========================================================================
// CSV-Export der gefilterten Umsätze
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $activeAccount !== null) {
    $expSql = "SELECT booking_date, valuta_date, booking_type, counterparty, purpose, iban, amount, currency, category, is_fixed_cost, note
               FROM bank_transactions WHERE user_id = :uid AND account_id = :aid ORDER BY booking_date DESC, id DESC";
    $expStmt = $pdo->prepare($expSql);
    $expStmt->execute([':uid' => $userId, ':aid' => $selectedAccountId]);
    $rows = $expStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Kontoauszug_Export_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$activeAccount['name']) . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Buchungstag', 'Wertstellung', 'Umsatzart', 'Empfänger / Auftraggeber', 'Verwendungszweck', 'IBAN', 'Betrag (EUR)', 'Währung', 'Kategorie', 'Fixkosten', 'Notiz'], ';');
        foreach ($rows as $r) {
            $catKey = (string)$r['category'];
            $catLbl = $bankingCategories[$catKey]['label'] ?? $catKey;
            fputcsv($out, [
                (string)$r['booking_date'],
                (string)$r['valuta_date'],
                (string)$r['booking_type'],
                (string)$r['counterparty'],
                (string)$r['purpose'],
                (string)$r['iban'],
                number_format((float)$r['amount'], 2, ',', '.'),
                (string)$r['currency'],
                $catLbl,
                (int)$r['is_fixed_cost'] === 1 ? 'Ja' : 'Nein',
                (string)$r['note'],
            ], ';');
        }
        fclose($out);
    }
    exit;
}

// =========================================================================
// POST-Aktionen verarbeiten
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $flashError = 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        // 1. Kontoauszüge (PDF / CSV) hochladen & auswerten
        if ($action === 'upload_statements' && $activeAccount !== null) {
            $activeTab = 'statements';
            $archiveInCloud = isset($_POST['archive_in_cloud']) && $_POST['archive_in_cloud'] === '1';
            $clientPdfTexts = [];
            if (!empty($_POST['extracted_pdf_texts_json'])) {
                $decoded = json_decode((string)$_POST['extracted_pdf_texts_json'], true);
                if (is_array($decoded)) {
                    $clientPdfTexts = $decoded;
                }
            }

            $filesArr = $_FILES['statement_files'] ?? null;
            if (!$filesArr || empty($filesArr['name'])) {
                $flashError = 'Bitte wählen Sie mindestens eine PDF- oder CSV-Datei aus.';
            } else {
                $names   = is_array($filesArr['name']) ? $filesArr['name'] : [$filesArr['name']];
                $tmpList = is_array($filesArr['tmp_name']) ? $filesArr['tmp_name'] : [$filesArr['tmp_name']];
                $errList = is_array($filesArr['error']) ? $filesArr['error'] : [$filesArr['error']];

                $totalInserted  = 0;
                $totalSkipped   = 0;
                $processedFiles = 0;
                $balanceOkCount = 0;

                foreach ($names as $idx => $origName) {
                    $origName = trim((string)$origName);
                    $tmpPath  = (string)($tmpList[$idx] ?? '');
                    $errCode  = (int)($errList[$idx] ?? UPLOAD_ERR_NO_FILE);
                    if ($origName === '' || $errCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
                        continue;
                    }

                    $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['pdf', 'csv', 'txt'], true)) {
                        continue;
                    }

                    $parsed = null;
                    $extractedRawText = '';

                    if ($ext === 'csv') {
                        $csvContent = (string)@file_get_contents($tmpPath);
                        $extractedRawText = $csvContent;
                        $parsed = parseBankStatementCsv($csvContent);
                    } else {
                        // PDF oder Textdatei: Zuerst den vom Browser (PDF.js mit Koordinaten-Sortierung) gelieferten Text nutzen,
                        // ergänzend den Server-Extraktor (pdftotext -layout / Pure-PHP) heranziehen und das bessere Ergebnis wählen!
                        $browserText = trim((string)($clientPdfTexts[$origName] ?? $clientPdfTexts[(string)$idx] ?? ''));
                        $serverText  = ($ext === 'txt')
                            ? (string)@file_get_contents($tmpPath)
                            : extractBankPdfTextServer($tmpPath);

                        $parsedBrowser = $browserText !== '' ? parseBankStatementText($browserText, $origName) : null;
                        $parsedServer  = $serverText !== '' ? parseBankStatementText($serverText, $origName) : null;

                        $cntBrowser = $parsedBrowser ? count($parsedBrowser['transactions']) : 0;
                        $cntServer  = $parsedServer ? count($parsedServer['transactions']) : 0;

                        if ($cntServer > $cntBrowser) {
                            $parsed = $parsedServer;
                            $extractedRawText = $serverText;
                        } else {
                            $parsed = $parsedBrowser ?? $parsedServer ?? [
                                'transactions'   => [],
                                'start_balance'  => null,
                                'end_balance'    => null,
                                'iban'           => '',
                                'statement_no'   => '',
                                'statement_year' => (int)date('Y'),
                                'period_start'   => null,
                                'period_end'     => null,
                            ];
                            $extractedRawText = $browserText !== '' ? $browserText : $serverText;
                        }
                    }

                    $sYear = (int)($parsed['statement_year'] ?? (int)date('Y'));
                    $cloudFileId = null;
                    if ($archiveInCloud) {
                        $cloudFileId = archiveStatementInCloudFiles(
                            $pdo,
                            $userId,
                            (string)$activeAccount['name'],
                            $sYear,
                            $origName,
                            $tmpPath,
                            $extractedRawText
                        );
                    }

                    $res = importParsedBankStatement(
                        $pdo,
                        $userId,
                        $selectedAccountId,
                        $origName,
                        $ext === 'csv' ? 'csv' : 'pdf',
                        $cloudFileId,
                        $parsed
                    );

                    $processedFiles++;
                    $totalInserted += $res['inserted'];
                    $totalSkipped  += $res['skipped'];
                    if ($res['balance_check_ok'] === 1) {
                        $balanceOkCount++;
                    }
                }

                if ($processedFiles === 0) {
                    $flashError = 'Es konnten keine gültigen PDF- oder CSV-Dateien verarbeitet werden.';
                } else {
                    $msg = sprintf(
                        '✅ %d Kontoauszug/Kontoauszüge verarbeitet: %d neue Buchungen importiert',
                        $processedFiles,
                        $totalInserted
                    );
                    if ($totalSkipped > 0) {
                        $msg .= sprintf(' (%d bereits vorhandene Duplikate übersprungen)', $totalSkipped);
                    }
                    if ($balanceOkCount > 0) {
                        $msg .= sprintf(' • 🎯 %d× Cent-genaue Saldo-Prüfung erfolgreich!', $balanceOkCount);
                    }
                    $flashSuccess = $msg;
                    $activeTab    = $totalInserted > 0 ? 'cockpit' : 'statements';
                }
            }
        }

        // 2. Vorhandene Datei direkt aus der MiniCloud importieren (z.B. über Rechtsklick oder Auswahlmenü)
        elseif ($action === 'import_cloud_file' && $activeAccount !== null) {
            $cloudFileId = (int)($_POST['cloud_file_id'] ?? 0);
            $browserText = trim((string)($_POST['cloud_pdf_extracted_text'] ?? ''));

            $fStmt = $pdo->prepare("SELECT * FROM files WHERE id = :fid AND user_id = :uid AND deleted_at IS NULL LIMIT 1");
            $fStmt->execute([':fid' => $cloudFileId, ':uid' => $userId]);
            $fRow = $fStmt->fetch(PDO::FETCH_ASSOC);

            if (!$fRow) {
                $flashError = 'Die gewählte Cloud-Datei wurde nicht gefunden.';
            } elseif ((int)($fRow['is_encrypted'] ?? 0) === 1) {
                $flashError = 'Verschlüsselte Tresor-Dateien müssen vor der Auswertung entschlüsselt werden.';
            } else {
                $filePath = getUserUploadDir((int)$fRow['user_id']) . '/' . $fRow['stored_name'];
                $origName = (string)$fRow['original_name'];
                $ext      = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));

                if (!is_file($filePath)) {
                    $flashError = 'Die physische Datei auf dem Server wurde nicht gefunden.';
                } else {
                    if ($ext === 'csv') {
                        $parsed = parseBankStatementCsv((string)@file_get_contents($filePath));
                    } else {
                        $serverText   = extractBankPdfTextServer($filePath);
                        $parsedClient = $browserText !== '' ? parseBankStatementText($browserText, $origName) : null;
                        $parsedServer = $serverText !== '' ? parseBankStatementText($serverText, $origName) : null;
                        $parsed       = (count($parsedServer['transactions'] ?? []) > count($parsedClient['transactions'] ?? []))
                            ? $parsedServer
                            : ($parsedClient ?? $parsedServer ?? ['transactions' => [], 'statement_year' => (int)date('Y')]);
                    }

                    $res = importParsedBankStatement(
                        $pdo,
                        $userId,
                        $selectedAccountId,
                        $origName,
                        $ext === 'csv' ? 'csv' : 'pdf',
                        $cloudFileId,
                        $parsed
                    );

                    $flashSuccess = sprintf(
                        '✅ Cloud-Datei „%s“ ausgewertet: %d neue Umsätze importiert (%d Duplikate übersprungen).',
                        $origName,
                        $res['inserted'],
                        $res['skipped']
                    );
                    $activeTab = 'cockpit';
                }
            }
        }

        // 3. Text / Tabelle per Copy & Paste aus Online-Banking oder PDF einfügen
        elseif ($action === 'import_pasted_text' && $activeAccount !== null) {
            $pasted = trim((string)($_POST['pasted_statement_text'] ?? ''));
            $title  = trim((string)($_POST['pasted_title'] ?? '')) ?: ('Text-Import vom ' . date('d.m.Y H:i'));
            if ($pasted === '') {
                $flashError = 'Bitte fügen Sie zuerst den kopierten Kontoauszugs-Text ein.';
                $activeTab  = 'statements';
            } else {
                // Prüfen ob CSV oder PDF-Textstruktur
                $parsedCsv = parseBankStatementCsv($pasted);
                $parsedTxt = parseBankStatementText($pasted, $title);
                $parsed    = (count($parsedCsv['transactions']) >= count($parsedTxt['transactions']) && count($parsedCsv['transactions']) > 0)
                    ? $parsedCsv
                    : $parsedTxt;

                $res = importParsedBankStatement($pdo, $userId, $selectedAccountId, $title, 'text', null, $parsed);
                if ($res['inserted'] === 0 && $res['skipped'] === 0) {
                    $flashError = 'Im eingefügten Text wurden keine Buchungszeilen mit Datum und Betrag erkannt.';
                    $activeTab  = 'statements';
                } else {
                    $flashSuccess = sprintf('✅ %d Buchungen aus dem eingefügten Text importiert (%d Duplikate übersprungen).', $res['inserted'], $res['skipped']);
                    $activeTab    = 'cockpit';
                }
            }
        }

        // 4. Einzelne Buchung manuell hinzufügen
        elseif ($action === 'add_manual_tx' && $activeAccount !== null) {
            $bDate  = normalizeBankDateString((string)($_POST['booking_date'] ?? ''), (int)date('Y')) ?? date('Y-m-d');
            $cparty = trim((string)($_POST['counterparty'] ?? ''));
            $purp   = trim((string)($_POST['purpose'] ?? ''));
            $btype  = trim((string)($_POST['booking_type'] ?? 'Manuelle Buchung'));
            $amt    = parseGermanAmountString((string)($_POST['amount'] ?? ''));
            $cat    = trim((string)($_POST['category'] ?? 'auto'));
            $fixed  = isset($_POST['is_fixed_cost']) && $_POST['is_fixed_cost'] === '1' ? 1 : 0;
            $note   = trim((string)($_POST['note'] ?? ''));

            if ($cparty === '' || $amt === null) {
                $flashError = 'Bitte geben Sie einen Empfänger/Auftraggeber und einen gültigen Betrag ein.';
            } else {
                if ($cat === 'auto' || !isset($bankingCategories[$cat])) {
                    $auto = categorizeBankTransaction($cparty, $purp, $btype, $amt, getUserBankingRules($pdo, $userId));
                    $cat  = $auto['category'];
                    if (!isset($_POST['is_fixed_cost'])) {
                        $fixed = $auto['is_fixed_cost'];
                    }
                }
                $hash = buildBankTransactionHash($bDate, $amt, $cparty, $purp, random_int(100, 999999));
                $ins  = $pdo->prepare("
                    INSERT INTO bank_transactions (
                        account_id, statement_id, user_id, file_id, booking_date, valuta_date,
                        booking_type, counterparty, purpose, iban, amount, currency,
                        category, is_fixed_cost, note, tx_hash
                    ) VALUES (
                        :aid, NULL, :uid, NULL, :bdate, :bdate,
                        :btype, :cparty, :purp, '', :amt, 'EUR',
                        :cat, :fixed, :note, :hash
                    )
                ");
                $ins->execute([
                    ':aid'    => $selectedAccountId,
                    ':uid'    => $userId,
                    ':bdate'  => $bDate,
                    ':btype'  => $btype,
                    ':cparty' => $cparty,
                    ':purp'   => $purp,
                    ':amt'    => $amt,
                    ':cat'    => $cat,
                    ':fixed'  => $fixed,
                    ':note'   => $note,
                    ':hash'   => $hash,
                ]);
                $flashSuccess = '✅ Buchung wurde gespeichert.';
            }
            $activeTab = 'transactions';
        }

        // 5. Buchung inline aktualisieren (Kategorie, Fixkosten-Flag, Notiz + optional als Regel merken)
        elseif ($action === 'update_tx') {
            $txId       = (int)($_POST['tx_id'] ?? 0);
            $newCat     = trim((string)($_POST['category'] ?? 'other'));
            $isFixed    = isset($_POST['is_fixed_cost']) && $_POST['is_fixed_cost'] === '1' ? 1 : 0;
            $note       = trim((string)($_POST['note'] ?? ''));
            $saveAsRule = isset($_POST['save_as_rule']) && $_POST['save_as_rule'] === '1';

            if (!isset($bankingCategories[$newCat])) {
                $newCat = 'other';
            }

            $txStmt = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = :id AND user_id = :uid LIMIT 1");
            $txStmt->execute([':id' => $txId, ':uid' => $userId]);
            $txRow = $txStmt->fetch(PDO::FETCH_ASSOC);

            if ($txRow) {
                $upd = $pdo->prepare("
                    UPDATE bank_transactions
                    SET category = :cat, is_fixed_cost = :fixed, note = :note
                    WHERE id = :id AND user_id = :uid
                ");
                $upd->execute([
                    ':cat'   => $newCat,
                    ':fixed' => $isFixed,
                    ':note'  => $note,
                    ':id'    => $txId,
                    ':uid'   => $userId,
                ]);

                if ($saveAsRule && trim((string)$txRow['counterparty']) !== '') {
                    $kw = trim((string)$txRow['counterparty']);
                    $rIns = $pdo->prepare("INSERT INTO bank_rules (user_id, keyword, category, is_fixed_cost) VALUES (:uid, :kw, :cat, :fixed)");
                    $rIns->execute([':uid' => $userId, ':kw' => $kw, ':cat' => $newCat, ':fixed' => $isFixed]);

                    // Alle bestehenden Buchungen dieses Empfängers direkt mit aktualisieren
                    $bUpd = $pdo->prepare("
                        UPDATE bank_transactions
                        SET category = :cat, is_fixed_cost = :fixed
                        WHERE user_id = :uid AND LOWER(counterparty) LIKE :kwlike
                    ");
                    $bUpd->execute([
                        ':cat'    => $newCat,
                        ':fixed'  => $isFixed,
                        ':uid'    => $userId,
                        ':kwlike' => '%' . mb_strtolower($kw, 'UTF-8') . '%',
                    ]);
                    $flashSuccess = sprintf('✅ Buchung aktualisiert und Regel für „%s“ auf alle passenden Umsätze angewendet.', $kw);
                } else {
                    $flashSuccess = '✅ Buchung aktualisiert.';
                }
            }
            $activeTab = 'transactions';
        }

        // 6. Einzelne Buchung löschen
        elseif ($action === 'delete_tx') {
            $txId = (int)($_POST['tx_id'] ?? 0);
            $del  = $pdo->prepare("DELETE FROM bank_transactions WHERE id = :id AND user_id = :uid");
            $del->execute([':id' => $txId, ':uid' => $userId]);
            $flashSuccess = '🗑️ Buchung wurde gelöscht.';
            $activeTab    = 'transactions';
        }

        // 7. Kompletten Kontoauszug (inkl. zugehöriger Umsätze) löschen
        elseif ($action === 'delete_statement') {
            $stId = (int)($_POST['statement_id'] ?? 0);
            $delT = $pdo->prepare("DELETE FROM bank_transactions WHERE statement_id = :sid AND user_id = :uid");
            $delT->execute([':sid' => $stId, ':uid' => $userId]);

            $delS = $pdo->prepare("DELETE FROM bank_statements WHERE id = :sid AND user_id = :uid");
            $delS->execute([':sid' => $stId, ':uid' => $userId]);

            $flashSuccess = '🗑️ Kontoauszug und die daraus importierten Umsätze wurden aus der Auswertung entfernt.';
            $activeTab    = 'statements';
        }

        // 8. Neues Bankkonto anlegen oder aktualisieren
        elseif ($action === 'save_account') {
            $editId   = (int)($_POST['edit_account_id'] ?? 0);
            $name     = trim((string)($_POST['name'] ?? ''));
            $bankName = trim((string)($_POST['bank_name'] ?? 'Postbank'));
            $iban     = preg_replace('/\s+/', '', strtoupper(trim((string)($_POST['iban'] ?? '')))) ?? '';
            $bic      = strtoupper(trim((string)($_POST['bic'] ?? '')));
            $accType  = trim((string)($_POST['account_type'] ?? 'Girokonto'));
            $color    = trim((string)($_POST['color'] ?? '#2563eb'));
            $initBal  = parseGermanAmountString((string)($_POST['initial_balance'] ?? '0')) ?? 0.0;

            if ($name === '') {
                $flashError = 'Bitte geben Sie einen Kontonamen ein (z.B. Postbank Girokonto).';
            } else {
                if ($editId > 0) {
                    $upd = $pdo->prepare("
                        UPDATE bank_accounts
                        SET name = :name, bank_name = :bank, iban = :iban, bic = :bic,
                            account_type = :type, color = :color, initial_balance = :ibal
                        WHERE id = :id AND user_id = :uid
                    ");
                    $upd->execute([
                        ':name'  => $name,
                        ':bank'  => $bankName,
                        ':iban'  => $iban,
                        ':bic'   => $bic,
                        ':type'  => $accType,
                        ':color' => $color,
                        ':ibal'  => $initBal,
                        ':id'    => $editId,
                        ':uid'   => $userId,
                    ]);
                    $flashSuccess = '✅ Kontodaten wurden aktualisiert.';
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO bank_accounts (user_id, name, bank_name, iban, bic, account_type, color, initial_balance)
                        VALUES (:uid, :name, :bank, :iban, :bic, :type, :color, :ibal)
                    ");
                    $ins->execute([
                        ':uid'   => $userId,
                        ':name'  => $name,
                        ':bank'  => $bankName,
                        ':iban'  => $iban,
                        ':bic'   => $bic,
                        ':type'  => $accType,
                        ':color' => $color,
                        ':ibal'  => $initBal,
                    ]);
                    $selectedAccountId = (int)$pdo->lastInsertId();
                    $flashSuccess = '✅ Neues Bankkonto „' . $name . '“ wurde angelegt.';
                }

                // Kontenliste neu laden
                $accStmt->execute([':uid' => $userId]);
                $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($accounts as $acc) {
                    if ((int)$acc['id'] === $selectedAccountId) {
                        $activeAccount = $acc;
                        break;
                    }
                }
            }
            $activeTab = 'settings';
        }

        // 9. Bankkonto löschen
        elseif ($action === 'delete_account') {
            $delAccId = (int)($_POST['delete_account_id'] ?? 0);
            if (count($accounts) <= 1) {
                $flashError = 'Das letzte verbleibende Bankkonto kann nicht gelöscht werden.';
            } else {
                $pdo->prepare("DELETE FROM bank_transactions WHERE account_id = :aid AND user_id = :uid")->execute([':aid' => $delAccId, ':uid' => $userId]);
                $pdo->prepare("DELETE FROM bank_statements WHERE account_id = :aid AND user_id = :uid")->execute([':aid' => $delAccId, ':uid' => $userId]);
                $pdo->prepare("DELETE FROM bank_accounts WHERE id = :aid AND user_id = :uid")->execute([':aid' => $delAccId, ':uid' => $userId]);

                $accStmt->execute([':uid' => $userId]);
                $accounts = $accStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $activeAccount = $accounts[0] ?? null;
                $selectedAccountId = (int)($activeAccount['id'] ?? 0);
                $flashSuccess = '🗑️ Bankkonto wurde samt Umsätzen gelöscht.';
            }
            $activeTab = 'settings';
        }

        // 10. Eigene Kategorisierungs-Regel anlegen / löschen / alle neu kategorisieren
        elseif ($action === 'add_rule') {
            $kw    = trim((string)($_POST['keyword'] ?? ''));
            $cat   = trim((string)($_POST['category'] ?? 'other'));
            $fixed = isset($_POST['is_fixed_cost']) && $_POST['is_fixed_cost'] === '1' ? 1 : 0;
            if ($kw !== '' && isset($bankingCategories[$cat])) {
                $ins = $pdo->prepare("INSERT INTO bank_rules (user_id, keyword, category, is_fixed_cost) VALUES (:uid, :kw, :cat, :fixed)");
                $ins->execute([':uid' => $userId, ':kw' => $kw, ':cat' => $cat, ':fixed' => $fixed]);
                $flashSuccess = '✅ Regel für „' . $kw . '“ gespeichert.';
            }
            $activeTab = 'settings';
        } elseif ($action === 'delete_rule') {
            $rId = (int)($_POST['rule_id'] ?? 0);
            $pdo->prepare("DELETE FROM bank_rules WHERE id = :id AND user_id = :uid")->execute([':id' => $rId, ':uid' => $userId]);
            $flashSuccess = '🗑️ Regel gelöscht.';
            $activeTab    = 'settings';
        } elseif ($action === 'recategorize_all') {
            $rules   = getUserBankingRules($pdo, $userId);
            $allStmt = $pdo->prepare("SELECT id, counterparty, purpose, booking_type, amount FROM bank_transactions WHERE user_id = :uid");
            $allStmt->execute([':uid' => $userId]);
            $allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $uStmt = $pdo->prepare("UPDATE bank_transactions SET category = :cat, is_fixed_cost = :fixed WHERE id = :id AND user_id = :uid");
            $updatedCount = 0;
            foreach ($allRows as $r) {
                $info = categorizeBankTransaction((string)$r['counterparty'], (string)$r['purpose'], (string)$r['booking_type'], (float)$r['amount'], $rules);
                $uStmt->execute([
                    ':cat'   => $info['category'],
                    ':fixed' => $info['is_fixed_cost'],
                    ':id'    => (int)$r['id'],
                    ':uid'   => $userId,
                ]);
                $updatedCount++;
            }
            $flashSuccess = sprintf('🔄 Alle %d gespeicherten Buchungen wurden anhand der aktuellen Regeln neu kategorisiert.', $updatedCount);
            $activeTab    = 'settings';
        }
    }
}

// =========================================================================
// Filter & Auswertungsdaten für das aktive Konto laden
// =========================================================================
$filterYear     = trim((string)($_GET['year'] ?? 'all'));
$filterMonth    = trim((string)($_GET['month'] ?? 'all'));
$filterCategory = trim((string)($_GET['cat'] ?? 'all'));
$filterType     = trim((string)($_GET['type'] ?? 'all')); // all, income, expense, fixed
$searchQuery    = trim((string)($_GET['q'] ?? ''));

// Verfügbare Buchungsjahre des Kontos abfragen
$yearsStmt = $pdo->prepare("
    SELECT DISTINCT SUBSTR(booking_date, 1, 4) AS yr
    FROM bank_transactions
    WHERE user_id = :uid AND account_id = :aid AND LENGTH(booking_date) >= 4
    ORDER BY yr DESC
");
$yearsStmt->execute([':uid' => $userId, ':aid' => $selectedAccountId]);
$availableYears = array_values(array_filter(array_map('strval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
if (empty($availableYears)) {
    $availableYears = [(string)date('Y')];
}

// Alle Buchungen des aktiven Kontos (für Cockpit-Diagramme & Fixkosten-Radar)
$allTxStmt = $pdo->prepare("
    SELECT t.*, s.filename AS statement_filename
    FROM bank_transactions t
    LEFT JOIN bank_statements s ON s.id = t.statement_id
    WHERE t.user_id = :uid AND t.account_id = :aid
    ORDER BY t.booking_date DESC, t.id DESC
");
$allTxStmt->execute([':uid' => $userId, ':aid' => $selectedAccountId]);
$allAccountTransactions = $allTxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Gefilterte Buchungen bestimmen
$filteredTransactions = [];
$filteredIncome       = 0.0;
$filteredExpense      = 0.0;
$filteredFixedExpense = 0.0;
$categoryTotals       = [];
$counterpartyTotals   = [];

foreach ($allAccountTransactions as $tx) {
    $bDate = (string)$tx['booking_date'];
    $yr    = substr($bDate, 0, 4);
    $mo    = substr($bDate, 5, 2);
    $amt   = (float)$tx['amount'];
    $cat   = (string)$tx['category'];
    $fixed = (int)$tx['is_fixed_cost'] === 1;

    if ($filterYear !== 'all' && $yr !== $filterYear) {
        continue;
    }
    if ($filterMonth !== 'all' && str_pad($filterMonth, 2, '0', STR_PAD_LEFT) !== $mo) {
        continue;
    }
    if ($filterCategory !== 'all' && $cat !== $filterCategory) {
        continue;
    }
    if ($filterType === 'income' && $amt < 0) {
        continue;
    }
    if ($filterType === 'expense' && $amt >= 0) {
        continue;
    }
    if ($filterType === 'fixed' && !$fixed) {
        continue;
    }
    if ($searchQuery !== '') {
        $hay = mb_strtolower($tx['counterparty'] . ' ' . $tx['purpose'] . ' ' . $tx['booking_type'] . ' ' . $tx['note'] . ' ' . number_format($amt, 2, ',', '.'), 'UTF-8');
        if (mb_strpos($hay, mb_strtolower($searchQuery, 'UTF-8')) === false) {
            continue;
        }
    }

    $filteredTransactions[] = $tx;

    if ($amt >= 0) {
        $filteredIncome += $amt;
    } else {
        $absAmt = abs($amt);
        $filteredExpense += $absAmt;
        if ($fixed) {
            $filteredFixedExpense += $absAmt;
        }
        $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0.0) + $absAmt;
        $cpKey = trim((string)$tx['counterparty']) ?: 'Unbekannt';
        if (!isset($counterpartyTotals[$cpKey])) {
            $counterpartyTotals[$cpKey] = ['name' => $cpKey, 'total' => 0.0, 'count' => 0, 'category' => $cat];
        }
        $counterpartyTotals[$cpKey]['total'] += $absAmt;
        $counterpartyTotals[$cpKey]['count']++;
    }
}

arsort($categoryTotals);
usort($counterpartyTotals, static fn($a, $b) => $b['total'] <=> $a['total']);
$topCounterparties = array_slice($counterpartyTotals, 0, 8);

// 12-Monats-Zeitreihe (Einnahmen vs. Ausgaben) für das Balkendiagramm
$monthlySeries = [];
foreach ($allAccountTransactions as $tx) {
    $ym = substr((string)$tx['booking_date'], 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        continue;
    }
    if ($filterYear !== 'all' && substr($ym, 0, 4) !== $filterYear) {
        continue;
    }
    if (!isset($monthlySeries[$ym])) {
        $monthlySeries[$ym] = ['ym' => $ym, 'income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    }
    $amt = (float)$tx['amount'];
    if ($amt >= 0) {
        $monthlySeries[$ym]['income'] += $amt;
    } else {
        $monthlySeries[$ym]['expense'] += abs($amt);
    }
    $monthlySeries[$ym]['net'] = $monthlySeries[$ym]['income'] - $monthlySeries[$ym]['expense'];
}
ksort($monthlySeries);
if (count($monthlySeries) > 12) {
    $monthlySeries = array_slice($monthlySeries, -12, 12, true);
}

// Kontostands-Berechnung:
// Entweder der `end_balance` des neuesten Kontoauszugs + seitdem gebuchte Umsätze,
// oder `initial_balance` + Summe aller Umsätze.
$stmtListQuery = $pdo->prepare("
    SELECT * FROM bank_statements
    WHERE user_id = :uid AND account_id = :aid
    ORDER BY COALESCE(period_end, created_at) DESC, id DESC
");
$stmtListQuery->execute([':uid' => $userId, ':aid' => $selectedAccountId]);
$accountStatements = $stmtListQuery->fetchAll(PDO::FETCH_ASSOC) ?: [];

$currentAccountBalance = (float)($activeAccount['initial_balance'] ?? 0);
$latestStatementBalanceInfo = null;
foreach ($accountStatements as $st) {
    if ($st['end_balance'] !== null) {
        $currentAccountBalance = (float)$st['end_balance'];
        $latestStatementBalanceInfo = $st;
        break;
    }
}
if ($latestStatementBalanceInfo === null) {
    $sumAllStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE user_id = :uid AND account_id = :aid");
    $sumAllStmt->execute([':uid' => $userId, ':aid' => $selectedAccountId]);
    $currentAccountBalance = round((float)($activeAccount['initial_balance'] ?? 0) + (float)$sumAllStmt->fetchColumn(), 2);
}

// =========================================================================
// 🔄 Automatischer Fixkosten-, Abo- & Preiserhöhungs-Detektor
// =========================================================================
$recurringGroups = [];
foreach (array_reverse($allAccountTransactions) as $tx) {
    $amt = (float)$tx['amount'];
    if ($amt >= 0) {
        continue;
    }
    $cpNorm = trim(preg_replace('/\s+/', ' ', (string)$tx['counterparty']) ?? '');
    if ($cpNorm === '') {
        continue;
    }
    $key = mb_strtolower($cpNorm, 'UTF-8');
    if (!isset($recurringGroups[$key])) {
        $recurringGroups[$key] = [
            'counterparty' => $cpNorm,
            'category'     => (string)$tx['category'],
            'is_fixed_flag'=> (int)$tx['is_fixed_cost'] === 1,
            'history'      => [],
        ];
    }
    if ((int)$tx['is_fixed_cost'] === 1) {
        $recurringGroups[$key]['is_fixed_flag'] = true;
    }
    $recurringGroups[$key]['history'][] = [
        'date'   => (string)$tx['booking_date'],
        'amount' => abs($amt),
    ];
}

$detectedSubscriptions = [];
foreach ($recurringGroups as $g) {
    $hist = $g['history'];
    $cnt  = count($hist);
    // Als wiederkehrende Fixkosten/Abo werten, wenn als Fixkosten markiert oder mindestens 2 Abbuchungen in verschiedenen Monaten
    $distinctMonths = count(array_unique(array_map(static fn($h) => substr($h['date'], 0, 7), $hist)));
    if (!$g['is_fixed_flag'] && $distinctMonths < 2) {
        continue;
    }
    // Supermärkte/Tankstellen ohne Fixkosten-Markierung ausblenden
    if (!$g['is_fixed_flag'] && in_array($g['category'], ['groceries', 'dining_leisure', 'shopping', 'cash_transfer'], true)) {
        continue;
    }

    $latest   = $hist[$cnt - 1];
    $previous = $cnt >= 2 ? $hist[$cnt - 2] : null;
    $priceDelta = ($previous !== null) ? round($latest['amount'] - $previous['amount'], 2) : 0.0;

    $detectedSubscriptions[] = [
        'counterparty' => $g['counterparty'],
        'category'     => $g['category'],
        'count'        => $cnt,
        'last_date'    => $latest['date'],
        'last_amount'  => $latest['amount'],
        'prev_amount'  => $previous['amount'] ?? null,
        'price_delta'  => $priceDelta,
    ];
}
usort($detectedSubscriptions, static fn($a, $b) => $b['last_amount'] <=> $a['last_amount']);

// Vorhandene PDF/CSV-Dateien in der Cloud für den Direkt-Import laden
$cloudDocsStmt = $pdo->prepare("
    SELECT id, original_name, folder_path, file_size, uploaded_at
    FROM files
    WHERE user_id = :uid AND deleted_at IS NULL AND is_encrypted = 0
      AND (LOWER(original_name) LIKE '%.pdf' OR LOWER(original_name) LIKE '%.csv')
    ORDER BY uploaded_at DESC
    LIMIT 80
");
$cloudDocsStmt->execute([':uid' => $userId]);
$cloudBankCandidateFiles = $cloudDocsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$preselectedCloudFileId = isset($_GET['import_file_id']) ? (int)$_GET['import_file_id'] : 0;
if ($preselectedCloudFileId > 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $activeTab = 'statements';
}

$userRulesList = getUserBankingRules($pdo, $userId);

$pageTitle = '🏦 Kontoauszüge & Finanz-Analyse';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-3 sm:px-6 py-6 space-y-6">

    <!-- Kopfbereich mit Kontowahl & Schnell-Aktionen -->
    <div class="rounded-2xl bg-white/85 dark:bg-slate-900/85 backdrop-blur-md border border-slate-200/80 dark:border-slate-800 shadow-xs p-4 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-2xl sm:text-3xl">🏦</span>
                    <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                        Kontoauszüge &amp; Finanz-Analyse
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300 border border-blue-200/60 dark:border-blue-800/60">
                        Postbank · Sparkasse · VR · ING · DKB · CSV &amp; PDF
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                    Kontoauszüge als PDF oder CSV hochladen, revisionssicher im Cloud-Ordner <code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">Kontoauszüge/</code> archivieren und Umsätze automatisch mit Saldo-Prüfung auswerten.
                </p>
            </div>

            <!-- Kontowahl & Schnell-Buttons -->
            <div class="flex flex-wrap items-center gap-2.5">
                <form method="get" action="banking.php" class="flex items-center gap-2">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                    <label for="accountSelectTop" class="text-xs font-bold text-slate-500 dark:text-slate-400">Konto:</label>
                    <select
                        id="accountSelectTop"
                        name="account_id"
                        onchange="this.form.submit()"
                        class="px-3 py-2 rounded-xl text-xs sm:text-sm font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 border border-slate-200 dark:border-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= (int)$acc['id'] ?>" <?= (int)$acc['id'] === $selectedAccountId ? 'selected' : '' ?>>
                                🏦 <?= e((string)$acc['name']) ?><?= trim((string)$acc['iban']) !== '' ? ' (' . e(substr((string)$acc['iban'], 0, 4) . '…' . substr((string)$acc['iban'], -4)) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <button
                    type="button"
                    onclick="switchBankingTab('statements')"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-500 text-white shadow-xs transition-all cursor-pointer"
                >
                    <span>📥</span>
                    <span>Kontoauszug (PDF / CSV) importieren</span>
                </button>

                <a
                    href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;export=csv"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs sm:text-sm font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors"
                    title="Alle Umsätze dieses Kontos als CSV exportieren"
                >
                    <span>⬇️</span>
                    <span class="hidden sm:inline">CSV-Export</span>
                </a>
            </div>
        </div>

        <!-- Haupt-Tabs -->
        <div class="flex flex-wrap items-center gap-1.5 mt-5 pt-4 border-t border-slate-200/80 dark:border-slate-800">
            <button
                type="button"
                data-banking-tab-btn="cockpit"
                onclick="switchBankingTab('cockpit')"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer flex items-center gap-1.5"
            >
                <span>📊</span>
                <span>Cockpit &amp; Auswertung</span>
            </button>
            <button
                type="button"
                data-banking-tab-btn="transactions"
                onclick="switchBankingTab('transactions')"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer flex items-center gap-1.5"
            >
                <span>📑</span>
                <span>Umsätze &amp; Suche</span>
                <span class="px-1.5 py-0.2 rounded-full text-[11px] bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                    <?= count($filteredTransactions) ?>
                </span>
            </button>
            <button
                type="button"
                data-banking-tab-btn="statements"
                onclick="switchBankingTab('statements')"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer flex items-center gap-1.5"
            >
                <span>📥</span>
                <span>Kontoauszüge &amp; PDF/CSV-Import</span>
                <span class="px-1.5 py-0.2 rounded-full text-[11px] bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                    <?= count($accountStatements) ?>
                </span>
            </button>
            <button
                type="button"
                data-banking-tab-btn="settings"
                onclick="switchBankingTab('settings')"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer flex items-center gap-1.5"
            >
                <span>⚙️</span>
                <span>Konten &amp; Regeln</span>
            </button>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800/80 text-emerald-800 dark:text-emerald-200 text-xs sm:text-sm font-semibold flex items-center justify-between gap-3">
            <span><?= e($flashSuccess) ?></span>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 cursor-pointer">✕</button>
        </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="p-4 rounded-2xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800/80 text-red-800 dark:text-red-200 text-xs sm:text-sm font-semibold flex items-center justify-between gap-3">
            <span>⚠️ <?= e($flashError) ?></span>
            <button type="button" onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800 cursor-pointer">✕</button>
        </div>
    <?php endif; ?>

    <!-- Gemeinsame Zeitraum- & Suchfilterleiste (für Cockpit & Umsätze) -->
    <div id="bankingFilterBar" class="rounded-2xl bg-white/85 dark:bg-slate-900/85 backdrop-blur-md border border-slate-200/80 dark:border-slate-800 p-3.5 sm:p-4">
        <form method="get" action="banking.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2.5 items-end">
            <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
            <input type="hidden" name="tab" id="filterFormTabInput" value="<?= e($activeTab) ?>">

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Jahr</label>
                <select name="year" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100">
                    <option value="all" <?= $filterYear === 'all' ? 'selected' : '' ?>>Alle Jahre</option>
                    <?php foreach ($availableYears as $yr): ?>
                        <option value="<?= e($yr) ?>" <?= $filterYear === $yr ? 'selected' : '' ?>><?= e($yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Monat</label>
                <select name="month" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100">
                    <option value="all" <?= $filterMonth === 'all' ? 'selected' : '' ?>>Alle Monate</option>
                    <?php
                    $monthNames = [
                        '01' => '01 · Januar', '02' => '02 · Februar', '03' => '03 · März', '04' => '04 · April',
                        '05' => '05 · Mai', '06' => '06 · Juni', '07' => '07 · Juli', '08' => '08 · August',
                        '09' => '09 · September', '10' => '10 · Oktober', '11' => '11 · November', '12' => '12 · Dezember',
                    ];
                    foreach ($monthNames as $mNum => $mLabel):
                    ?>
                        <option value="<?= $mNum ?>" <?= str_pad($filterMonth, 2, '0', STR_PAD_LEFT) === $mNum ? 'selected' : '' ?>><?= e($mLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Buchungstyp</label>
                <select name="type" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Alle Buchungen</option>
                    <option value="income" <?= $filterType === 'income' ? 'selected' : '' ?>>➕ Nur Einnahmen</option>
                    <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>➖ Nur Ausgaben</option>
                    <option value="fixed" <?= $filterType === 'fixed' ? 'selected' : '' ?>>🔄 Nur Fixkosten &amp; Abos</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Kategorie</label>
                <select name="cat" onchange="this.form.submit()" class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100">
                    <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>Alle Kategorien</option>
                    <?php foreach ($bankingCategories as $cKey => $cMeta): ?>
                        <option value="<?= e($cKey) ?>" <?= $filterCategory === $cKey ? 'selected' : '' ?>>
                            <?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2 flex items-center gap-2">
                <div class="flex-1">
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-1">Volltextsuche (Empfänger, Zweck, Betrag)</label>
                    <input
                        type="text"
                        name="q"
                        value="<?= e($searchQuery) ?>"
                        placeholder="z.B. Postbank, REWE, Miete, Stadtwerke, 49,00..."
                        class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100"
                    >
                </div>
                <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white cursor-pointer shrink-0">
                    🔍 Filtern
                </button>
                <?php if ($filterYear !== 'all' || $filterMonth !== 'all' || $filterCategory !== 'all' || $filterType !== 'all' || $searchQuery !== ''): ?>
                    <a href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;tab=<?= e($activeTab) ?>" class="px-2.5 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 shrink-0" title="Filter zurücksetzen">
                        ✕
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- =====================================================================
         TAB 1: 📊 COCKPIT & AUSWERTUNG
         ===================================================================== -->
    <section id="bankingTab_cockpit" class="space-y-6">

        <!-- 4 KPI-Karten -->
        <?php
        $netCashflow = round($filteredIncome - $filteredExpense, 2);
        $savingsRate = $filteredIncome > 0 ? round(max(-100, min(100, (($filteredIncome - $filteredExpense) / $filteredIncome) * 100)), 1) : 0.0;
        ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Aktueller Kontostand -->
            <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Kontostand</span>
                    <span class="text-lg">🏦</span>
                </div>
                <p class="mt-2 text-2xl font-extrabold <?= $currentAccountBalance >= 0 ? 'text-slate-900 dark:text-white' : 'text-red-600 dark:text-red-400' ?>">
                    <?= number_format($currentAccountBalance, 2, ',', '.') ?> €
                </p>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400 truncate">
                    <?php if ($latestStatementBalanceInfo !== null): ?>
                        Lt. Auszug „<?= e((string)$latestStatementBalanceInfo['filename']) ?>“
                    <?php else: ?>
                        Startsaldo + <?= count($allAccountTransactions) ?> Buchungen
                    <?php endif; ?>
                </p>
            </div>

            <!-- Einnahmen im Zeitraum -->
            <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Einnahmen (Auswahl)</span>
                    <span class="text-lg">📈</span>
                </div>
                <p class="mt-2 text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">
                    +<?= number_format($filteredIncome, 2, ',', '.') ?> €
                </p>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    Gutschriften, Gehalt &amp; Eingänge
                </p>
            </div>

            <!-- Ausgaben im Zeitraum -->
            <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400">Ausgaben (Auswahl)</span>
                    <span class="text-lg">📉</span>
                </div>
                <p class="mt-2 text-2xl font-extrabold text-rose-600 dark:text-rose-400">
                    -<?= number_format($filteredExpense, 2, ',', '.') ?> €
                </p>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    Davon Fixkosten: <strong><?= number_format($filteredFixedExpense, 2, ',', '.') ?> €</strong>
                </p>
            </div>

            <!-- Netto-Cashflow & Sparquote -->
            <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Bilanz / Sparquote</span>
                    <span class="text-lg"><?= $netCashflow >= 0 ? '🟢' : '🔴' ?></span>
                </div>
                <p class="mt-2 text-2xl font-extrabold <?= $netCashflow >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                    <?= ($netCashflow >= 0 ? '+' : '') . number_format($netCashflow, 2, ',', '.') ?> €
                </p>
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    Sparquote: <strong><?= number_format($savingsRate, 1, ',', '.') ?> %</strong> (<?= count($filteredTransactions) ?> Umsätze)
                </p>
            </div>
        </div>

        <?php if (empty($allAccountTransactions)): ?>
            <!-- Leerer Startzustand mit Direkt-Einstieg -->
            <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-dashed border-brand-300 dark:border-brand-700 p-8 text-center space-y-4">
                <div class="text-4xl">🏦</div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                    Noch keine Kontoauszüge für „<?= e((string)($activeAccount['name'] ?? 'Konto')) ?>“ importiert
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-xl mx-auto">
                    Laden Sie Ihre Kontoauszüge der <strong>Postbank</strong> (oder Sparkasse, Volksbank, ING, DKB) einfach als <strong>PDF</strong> oder <strong>CSV</strong> hoch. Die Datei wird automatisch im Ordner <code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800">Kontoauszüge/</code> gespeichert und alle Buchungen werden sofort nach Kategorien, Empfängern und Fixkosten ausgewertet.
                </p>
                <div class="pt-2">
                    <button
                        type="button"
                        onclick="switchBankingTab('statements')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-500 text-white shadow-sm cursor-pointer"
                    >
                        <span>📥</span>
                        <span>Jetzt ersten Kontoauszug (PDF / CSV) hochladen</span>
                    </button>
                </div>
            </div>
        <?php else: ?>

            <!-- Monatsverlauf (Einnahmen vs. Ausgaben) & Ausgaben nach Kategorien -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                <!-- Monats-Vergleich (7 Spalten) -->
                <div class="lg:col-span-7 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                                📊 Monatsverlauf: Einnahmen vs. Ausgaben
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Klick auf einen Monat filtert alle Auswertungen auf diesen Zeitraum
                            </p>
                        </div>
                        <div class="flex items-center gap-3 text-xs font-semibold">
                            <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400"><span class="w-2.5 h-2.5 rounded-xs bg-emerald-500 inline-block"></span> Einnahmen</span>
                            <span class="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400"><span class="w-2.5 h-2.5 rounded-xs bg-rose-500 inline-block"></span> Ausgaben</span>
                        </div>
                    </div>

                    <?php
                    $maxMonthVal = 1.0;
                    foreach ($monthlySeries as $ms) {
                        $maxMonthVal = max($maxMonthVal, $ms['income'], $ms['expense']);
                    }
                    ?>
                    <div class="space-y-3 pt-1">
                        <?php foreach ($monthlySeries as $ym => $ms):
                            [$yPart, $mPart] = explode('-', $ym);
                            $incPct = min(100, round(($ms['income'] / $maxMonthVal) * 100, 1));
                            $expPct = min(100, round(($ms['expense'] / $maxMonthVal) * 100, 1));
                            $netM   = round($ms['income'] - $ms['expense'], 2);
                        ?>
                            <a
                                href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;year=<?= e($yPart) ?>&amp;month=<?= e($mPart) ?>&amp;tab=cockpit"
                                class="block p-2.5 rounded-xl bg-slate-50/80 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 transition-colors"
                            >
                                <div class="flex items-center justify-between text-xs mb-1.5">
                                    <span class="font-bold text-slate-800 dark:text-slate-100"><?= e($mPart . '/' . $yPart) ?></span>
                                    <div class="flex items-center gap-3">
                                        <span class="text-emerald-600 dark:text-emerald-400 font-semibold">+<?= number_format($ms['income'], 2, ',', '.') ?> €</span>
                                        <span class="text-rose-600 dark:text-rose-400 font-semibold">-<?= number_format($ms['expense'], 2, ',', '.') ?> €</span>
                                        <span class="font-bold <?= $netM >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' ?>">
                                            (<?= ($netM >= 0 ? '+' : '') . number_format($netM, 2, ',', '.') ?> €)
                                        </span>
                                    </div>
                                </div>
                                <div class="space-y-1">
                                    <div class="w-full h-2 rounded-full bg-slate-200/70 dark:bg-slate-700/60 overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-500" style="width: <?= $incPct ?>%"></div>
                                    </div>
                                    <div class="w-full h-2 rounded-full bg-slate-200/70 dark:bg-slate-700/60 overflow-hidden">
                                        <div class="h-full rounded-full bg-rose-500" style="width: <?= $expPct ?>%"></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Ausgaben nach Kategorien (5 Spalten) -->
                <div class="lg:col-span-5 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                    <div>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                            🍩 Ausgaben nach Kategorien
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Klick auf eine Kategorie zeigt alle zugehörigen Buchungen
                        </p>
                    </div>

                    <?php if (empty($categoryTotals)): ?>
                        <p class="text-xs text-slate-400 italic py-6 text-center">Keine Ausgaben im gewählten Filterzeitraum.</p>
                    <?php else: ?>
                        <div class="space-y-2.5">
                            <?php foreach ($categoryTotals as $catKey => $catSum):
                                $cMeta = $bankingCategories[$catKey] ?? $bankingCategories['other'];
                                $pct   = $filteredExpense > 0 ? round(($catSum / $filteredExpense) * 100, 1) : 0.0;
                            ?>
                                <a
                                    href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;year=<?= e($filterYear) ?>&amp;month=<?= e($filterMonth) ?>&amp;cat=<?= e($catKey) ?>&amp;tab=transactions"
                                    class="block p-2.5 rounded-xl bg-slate-50/80 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 transition-colors"
                                >
                                    <div class="flex items-center justify-between text-xs mb-1">
                                        <span class="font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                                            <span><?= e($cMeta['icon']) ?></span>
                                            <span><?= e($cMeta['label']) ?></span>
                                        </span>
                                        <span class="font-bold text-slate-900 dark:text-white">
                                            <?= number_format($catSum, 2, ',', '.') ?> €
                                            <span class="text-[11px] font-normal text-slate-400">(<?= number_format($pct, 1, ',', '.') ?> %)</span>
                                        </span>
                                    </div>
                                    <div class="w-full h-2 rounded-full bg-slate-200/70 dark:bg-slate-700/60 overflow-hidden">
                                        <div class="h-full rounded-full" style="width: <?= min(100, $pct) ?>%; background-color: <?= e($cMeta['color']) ?>"></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Fixkosten- & Abo-Radar + Top-Empfänger -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                <!-- Fixkosten- & Abo-Detektor (7 Spalten) -->
                <div class="lg:col-span-7 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                                🔄 Erkannte Fixkosten, Verträge &amp; Abonnements
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Automatische Erkennung regelmäßiger Lastschriften, Daueraufträge und Preisänderungen
                            </p>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-indigo-50 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300">
                            <?= count($detectedSubscriptions) ?> Verträge
                        </span>
                    </div>

                    <?php if (empty($detectedSubscriptions)): ?>
                        <p class="text-xs text-slate-400 italic py-4">Noch keine wiederkehrenden Abbuchungen oder markierten Fixkosten erkannt.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                        <th class="py-2 pr-2">Empfänger / Vertrag</th>
                                        <th class="py-2 px-2">Kategorie</th>
                                        <th class="py-2 px-2">Letzte Abbuchung</th>
                                        <th class="py-2 pl-2 text-right">Betrag &amp; Trend</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                                    <?php foreach (array_slice($detectedSubscriptions, 0, 12) as $sub):
                                        $cMeta = $bankingCategories[$sub['category']] ?? $bankingCategories['other'];
                                    ?>
                                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                            <td class="py-2.5 pr-2 font-bold text-slate-800 dark:text-slate-100">
                                                <a href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;q=<?= urlencode($sub['counterparty']) ?>&amp;tab=transactions" class="hover:text-brand-600 dark:hover:text-brand-400">
                                                    <?= e($sub['counterparty']) ?>
                                                </a>
                                                <span class="text-[10px] font-normal text-slate-400 ml-1">(<?= (int)$sub['count'] ?>×)</span>
                                            </td>
                                            <td class="py-2.5 px-2 text-slate-600 dark:text-slate-300">
                                                <?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?>
                                            </td>
                                            <td class="py-2.5 px-2 text-slate-500 dark:text-slate-400">
                                                <?= e(date('d.m.Y', strtotime($sub['last_date']))) ?>
                                            </td>
                                            <td class="py-2.5 pl-2 text-right font-bold text-rose-600 dark:text-rose-400">
                                                -<?= number_format((float)$sub['last_amount'], 2, ',', '.') ?> €
                                                <?php if ($sub['price_delta'] > 0.05): ?>
                                                    <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 text-[10px]" title="Teurer als bei der vorherigen Abbuchung (<?= number_format((float)$sub['prev_amount'], 2, ',', '.') ?> €)">
                                                        ⬆️ +<?= number_format((float)$sub['price_delta'], 2, ',', '.') ?> €
                                                    </span>
                                                <?php elseif ($sub['price_delta'] < -0.05): ?>
                                                    <span class="ml-1 px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[10px]">
                                                        ⬇️ <?= number_format((float)$sub['price_delta'], 2, ',', '.') ?> €
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Top-Empfänger (5 Spalten) -->
                <div class="lg:col-span-5 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                    <div>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                            🏢 Größte Zahlungsempfänger (Auswahl)
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Wohin im gewählten Zeitraum das meiste Geld geflossen ist
                        </p>
                    </div>

                    <?php if (empty($topCounterparties)): ?>
                        <p class="text-xs text-slate-400 italic py-4">Keine Zahlungsempfänger im gewählten Zeitraum.</p>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($topCounterparties as $cp):
                                $cMeta = $bankingCategories[$cp['category']] ?? $bankingCategories['other'];
                            ?>
                                <a
                                    href="banking.php?account_id=<?= (int)$selectedAccountId ?>&amp;q=<?= urlencode($cp['name']) ?>&amp;tab=transactions"
                                    class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-slate-50/80 dark:bg-slate-800/50 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/60 dark:border-slate-700/60 text-xs transition-colors"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="font-bold text-slate-800 dark:text-slate-100 truncate">
                                            <?= e($cMeta['icon']) ?> <?= e($cp['name']) ?>
                                        </p>
                                        <p class="text-[11px] text-slate-400">
                                            <?= (int)$cp['count'] ?> Buchung<?= (int)$cp['count'] === 1 ? '' : 'en' ?> · <?= e($cMeta['label']) ?>
                                        </p>
                                    </div>
                                    <span class="font-extrabold text-rose-600 dark:text-rose-400 shrink-0">
                                        -<?= number_format((float)$cp['total'], 2, ',', '.') ?> €
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================================
         TAB 2: 📑 UMSÄTZE & VOLLTEXTSUCHE
         ===================================================================== -->
    <section id="bankingTab_transactions" class="space-y-5 hidden">

        <!-- Manuelle Buchung erfassen (ausklappbar) -->
        <details class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4">
            <summary class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100 cursor-pointer select-none">
                ➕ Einzelne Buchung manuell nachtragen
            </summary>
            <form method="post" action="banking.php" class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_manual_tx">
                <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">

                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Buchungstag</label>
                    <input type="date" name="booking_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Empfänger / Auftraggeber</label>
                    <input type="text" name="counterparty" placeholder="z.B. Stadtwerke, REWE, Arbeitgeber..." required class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Verwendungszweck</label>
                    <input type="text" name="purpose" placeholder="Kundennummer / Rechnungs-Nr." class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Betrag in EUR (- für Ausgabe)</label>
                    <input type="text" name="amount" placeholder="-49,90" required class="w-full px-3 py-2 rounded-xl text-xs font-bold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Kategorie</label>
                    <select name="category" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        <option value="auto">✨ Automatisch erkennen</option>
                        <?php foreach ($bankingCategories as $cKey => $cMeta): ?>
                            <option value="<?= e($cKey) ?>"><?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2 flex items-center gap-2 pt-4">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input type="checkbox" name="is_fixed_cost" value="1" class="rounded border-slate-300 text-brand-600">
                        <span>🔄 Als regelmäßige Fixkosten / Abo markieren</span>
                    </label>
                </div>
                <div class="sm:col-span-2 text-right">
                    <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white cursor-pointer">
                        💾 Buchung speichern
                    </button>
                </div>
            </form>
        </details>

        <!-- Umsatztabelle -->
        <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 shadow-2xs overflow-hidden">
            <div class="p-4 border-b border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                        📑 Gebuchte Umsätze (<?= count($filteredTransactions) ?>)
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Klicken Sie auf ✏️ bei einer Buchung, um die Kategorie zu ändern oder als dauerhafte Regel für diesen Empfänger zu speichern.
                    </p>
                </div>
            </div>

            <?php if (empty($filteredTransactions)): ?>
                <div class="p-8 text-center text-xs sm:text-sm text-slate-400">
                    Keine Umsätze für die aktuellen Filterkriterien gefunden.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-3 px-3">Datum</th>
                                <th class="py-3 px-3">Empfänger / Auftraggeber &amp; Verwendungszweck</th>
                                <th class="py-3 px-3">Kategorie</th>
                                <th class="py-3 px-3">Auszug</th>
                                <th class="py-3 px-3 text-right">Betrag</th>
                                <th class="py-3 px-3 text-right">Aktion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                            <?php foreach ($filteredTransactions as $tx):
                                $amt   = (float)$tx['amount'];
                                $cKey  = (string)$tx['category'];
                                $cMeta = $bankingCategories[$cKey] ?? $bankingCategories['other'];
                                $txId  = (int)$tx['id'];
                                $fId   = (int)($tx['file_id'] ?? 0);
                            ?>
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 align-top">
                                    <td class="py-3 px-3 whitespace-nowrap">
                                        <div class="font-bold text-slate-800 dark:text-slate-100">
                                            <?= e(date('d.m.Y', strtotime((string)$tx['booking_date']))) ?>
                                        </div>
                                        <?php if ($tx['booking_type'] !== ''): ?>
                                            <div class="text-[10px] text-slate-400 truncate max-w-[130px]">
                                                <?= e((string)$tx['booking_type']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3 max-w-md">
                                        <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5 flex-wrap">
                                            <span><?= e((string)$tx['counterparty']) ?></span>
                                            <?php if ((int)$tx['is_fixed_cost'] === 1): ?>
                                                <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300" title="Wiederkehrende Fixkosten / Abo">
                                                    🔄 Fixkosten
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (trim((string)$tx['purpose']) !== ''): ?>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2 mt-0.5 break-words">
                                                <?= e((string)$tx['purpose']) ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (trim((string)$tx['note']) !== ''): ?>
                                            <p class="text-[11px] text-amber-700 dark:text-amber-300 font-medium mt-1">
                                                📝 <?= e((string)$tx['note']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                                            <span><?= e($cMeta['icon']) ?></span>
                                            <span><?= e($cMeta['label']) ?></span>
                                        </span>
                                    </td>

                                    <td class="py-3 px-3 whitespace-nowrap">
                                        <?php if ($fId > 0): ?>
                                            <a
                                                href="download.php?id=<?= $fId ?>&amp;inline=1"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 dark:text-brand-400 hover:underline"
                                                title="Original-Kontoauszug (<?= e((string)($tx['statement_filename'] ?? 'PDF/CSV')) ?>) öffnen"
                                            >
                                                📄 Original öffnen
                                            </a>
                                        <?php elseif (!empty($tx['statement_filename'])): ?>
                                            <span class="text-[11px] text-slate-400 truncate max-w-[120px] inline-block">
                                                <?= e((string)$tx['statement_filename']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400">Manuell</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3 whitespace-nowrap text-right font-extrabold text-sm <?= $amt >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                        <?= ($amt >= 0 ? '+' : '') . number_format($amt, 2, ',', '.') ?> €
                                    </td>

                                    <td class="py-3 px-3 whitespace-nowrap text-right">
                                        <button
                                            type="button"
                                            onclick="toggleTxEditRow(<?= $txId ?>)"
                                            class="px-2 py-1 rounded-lg text-xs bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer"
                                            title="Kategorie, Fixkosten-Status oder Notiz bearbeiten"
                                        >
                                            ✏️
                                        </button>
                                    </td>
                                </tr>

                                <!-- Ausklappbare Bearbeitungszeile -->
                                <tr id="txEditRow_<?= $txId ?>" class="hidden bg-slate-50 dark:bg-slate-800/70">
                                    <td colspan="6" class="p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <form method="post" action="banking.php" class="flex flex-wrap items-center gap-2.5 flex-1">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="update_tx">
                                                <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                                                <input type="hidden" name="tx_id" value="<?= $txId ?>">

                                                <select name="category" class="px-2.5 py-1.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                                                    <?php foreach ($bankingCategories as $k => $m): ?>
                                                        <option value="<?= e($k) ?>" <?= $k === $cKey ? 'selected' : '' ?>>
                                                            <?= e($m['icon'] . ' ' . $m['label']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <input
                                                    type="text"
                                                    name="note"
                                                    value="<?= e((string)$tx['note']) ?>"
                                                    placeholder="Eigene Notiz / Kommentar..."
                                                    class="px-2.5 py-1.5 rounded-xl text-xs bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 w-48 sm:w-64"
                                                >

                                                <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                                                    <input type="checkbox" name="is_fixed_cost" value="1" <?= (int)$tx['is_fixed_cost'] === 1 ? 'checked' : '' ?> class="rounded border-slate-300 text-brand-600">
                                                    <span>🔄 Fixkosten</span>
                                                </label>

                                                <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-700 dark:text-indigo-300 cursor-pointer">
                                                    <input type="checkbox" name="save_as_rule" value="1" class="rounded border-slate-300 text-indigo-600">
                                                    <span>✨ Für „<?= e(mb_substr((string)$tx['counterparty'], 0, 28, 'UTF-8')) ?>“ als Regel merken</span>
                                                </label>

                                                <button type="submit" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white cursor-pointer">
                                                    💾 Speichern
                                                </button>
                                            </form>

                                            <form method="post" action="banking.php" onsubmit="return confirm('Diese Buchung wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete_tx">
                                                <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                                                <input type="hidden" name="tx_id" value="<?= $txId ?>">
                                                <button type="submit" class="px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 hover:bg-red-100 cursor-pointer">
                                                    🗑️ Löschen
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- =====================================================================
         TAB 3: 📥 KONTOAUSZÜGE (PDF / CSV) HOCHLADEN & ARCHIV
         ===================================================================== -->
    <section id="bankingTab_statements" class="space-y-6 hidden">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Linke Spalte: Upload von PDF / CSV Kontoauszügen (7 Spalten) -->
            <div class="lg:col-span-7 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-base font-bold text-slate-900 dark:text-white">
                            📥 Kontoauszüge hochladen (PDF &amp; CSV)
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Unterstützt <strong>Postbank</strong> (PDF-Kontoauszüge &amp; CSV-Umsatzexport), Deutsche Bank, Sparkasse, Volksbank/VR, ING, DKB, Comdirect &amp; N26. Sie können auch mehrere Auszüge gleichzeitig auswählen.
                        </p>
                    </div>
                </div>

                <form id="bankUploadForm" method="post" action="banking.php" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="upload_statements">
                    <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                    <input type="hidden" name="extracted_pdf_texts_json" id="extractedPdfTextsJson" value="">

                    <label
                        for="statementFilesInput"
                        class="flex flex-col items-center justify-center p-6 border-2 border-dashed border-brand-300 dark:border-brand-700 rounded-2xl bg-brand-50/40 dark:bg-brand-950/20 hover:bg-brand-50/80 dark:hover:bg-brand-950/40 transition-colors cursor-pointer text-center"
                    >
                        <span class="text-3xl mb-2">📄📊</span>
                        <span class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100">
                            PDF- oder CSV-Kontoauszüge hierher ziehen oder klicken
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                            Mehrfachauswahl möglich (.pdf, .csv) • Automatischer Duplikat-Schutz &amp; Saldo-Kontrolle
                        </span>
                        <input
                            type="file"
                            id="statementFilesInput"
                            name="statement_files[]"
                            accept=".pdf,.csv,.txt,application/pdf,text/csv"
                            multiple
                            required
                            class="mt-3 text-xs text-slate-600 dark:text-slate-300"
                        >
                    </label>

                    <div id="pdfExtractionStatusBox" class="hidden p-3 rounded-xl bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 text-xs text-blue-800 dark:text-blue-200 font-medium">
                        ⏳ PDF-Tabellenzeilen werden für die Auswertung vorbereitet...
                    </div>

                    <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                        <input type="checkbox" name="archive_in_cloud" value="1" checked class="mt-0.5 rounded border-slate-300 text-brand-600">
                        <span>
                            <strong>Original-Kontoauszüge automatisch im Cloud-Dateimanager archivieren</strong><br>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                Speichert jede Datei sauber im Ordner <code class="px-1 rounded bg-slate-100 dark:bg-slate-800">Kontoauszüge/<?= e((string)($activeAccount['name'] ?? 'Postbank')) ?>/&lt;Jahr&gt;/</code> und verknüpft sie mit jeder Buchung.
                            </span>
                        </span>
                    </label>

                    <button
                        type="submit"
                        id="bankUploadSubmitBtn"
                        class="w-full py-2.5 px-4 rounded-xl text-xs sm:text-sm font-bold bg-brand-600 hover:bg-brand-500 text-white shadow-xs transition-all cursor-pointer"
                    >
                        🚀 Kontoauszüge jetzt importieren &amp; auswerten
                    </button>
                </form>
            </div>

            <!-- Rechte Spalte: Aus Cloud-Bestand wählen ODER per Copy & Paste einfügen (5 Spalten) -->
            <div class="lg:col-span-5 space-y-6">

                <!-- Aus vorhandenen Cloud-Dateien importieren -->
                <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-3">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white">
                        ☁️ Bereits in der Cloud gespeicherte PDF / CSV auswerten
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Wählen Sie einen Kontoauszug, der sich bereits in Ihrer MiniCloud befindet:
                    </p>
                    <form id="cloudImportForm" method="post" action="banking.php" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="import_cloud_file">
                        <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                        <input type="hidden" name="cloud_pdf_extracted_text" id="cloudPdfExtractedText" value="">

                        <select
                            name="cloud_file_id"
                            id="cloudFileSelect"
                            required
                            class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100"
                        >
                            <option value="">— Cloud-Datei (.pdf / .csv) auswählen —</option>
                            <?php foreach ($cloudBankCandidateFiles as $cf):
                                $cfId = (int)$cf['id'];
                                $folderLbl = trim((string)$cf['folder_path']) !== '' ? ($cf['folder_path'] . '/') : '';
                            ?>
                                <option
                                    value="<?= $cfId ?>"
                                    data-ext="<?= e(strtolower((string)pathinfo((string)$cf['original_name'], PATHINFO_EXTENSION))) ?>"
                                    <?= $preselectedCloudFileId === $cfId ? 'selected' : '' ?>
                                >
                                    <?= e($folderLbl . $cf['original_name']) ?> (<?= e(formatBytes((int)$cf['file_size'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" id="cloudImportSubmitBtn" class="w-full py-2 px-3 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-500 text-white cursor-pointer">
                            🔍 Ausgewählte Cloud-Datei analysieren
                        </button>
                    </form>
                </div>

                <!-- Text / Umsatztabelle direkt per Copy & Paste einfügen -->
                <details class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-4">
                    <summary class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer select-none">
                        📋 Alternativ: Umsätze per Copy &amp; Paste aus Online-Banking einfügen
                    </summary>
                    <form method="post" action="banking.php" class="mt-3 space-y-2.5">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="import_pasted_text">
                        <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                        <input
                            type="text"
                            name="pasted_title"
                            placeholder="Bezeichnung (z.B. Postbank Auszug 03/2026)"
                            class="w-full px-3 py-1.5 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700"
                        >
                        <textarea
                            name="pasted_statement_text"
                            rows="5"
                            placeholder="Kopierte Zeilen aus dem PDF-Kontoauszug oder Online-Banking hier einfügen..."
                            class="w-full px-3 py-2 rounded-xl text-xs font-mono bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700"
                        ></textarea>
                        <button type="submit" class="w-full py-2 px-3 rounded-xl text-xs font-bold bg-slate-800 dark:bg-slate-700 hover:bg-slate-700 text-white cursor-pointer">
                            📥 Text-Umsätze auswerten
                        </button>
                    </form>
                </details>
            </div>
        </div>

        <!-- Archiv-Tabelle aller importierten Kontoauszüge -->
        <div class="rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 overflow-hidden">
            <div class="p-4 border-b border-slate-200/80 dark:border-slate-800">
                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    🗂️ Archivierte &amp; ausgewertete Kontoauszüge (<?= count($accountStatements) ?>)
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Übersicht aller eingelesenen PDF- und CSV-Auszüge inklusive automatischer Saldo-Prüfung (Alter Kontostand &rarr; Neuer Kontostand).
                </p>
            </div>

            <?php if (empty($accountStatements)): ?>
                <div class="p-8 text-center text-xs text-slate-400">
                    Noch keine Kontoauszüge für dieses Konto hochgeladen.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-[11px] uppercase text-slate-400">
                                <th class="py-3 px-3">Datei / Auszug</th>
                                <th class="py-3 px-3">Zeitraum</th>
                                <th class="py-3 px-3 text-right">Alter Saldo</th>
                                <th class="py-3 px-3 text-right">Einnahmen / Ausgaben</th>
                                <th class="py-3 px-3 text-right">Neuer Saldo</th>
                                <th class="py-3 px-3">Saldo-Prüfung</th>
                                <th class="py-3 px-3 text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                            <?php foreach ($accountStatements as $st):
                                $fId = (int)($st['file_id'] ?? 0);
                            ?>
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                    <td class="py-3 px-3 font-bold text-slate-900 dark:text-white">
                                        <div class="flex items-center gap-1.5">
                                            <span><?= $st['file_format'] === 'csv' ? '📊' : '📄' ?></span>
                                            <span><?= e((string)$st['filename']) ?></span>
                                        </div>
                                        <div class="text-[10px] font-normal text-slate-400">
                                            <?= (int)$st['tx_count'] ?> Buchungen · Importiert am <?= e(date('d.m.Y H:i', strtotime((string)$st['created_at']))) ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3 text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                        <?php if (!empty($st['period_start']) && !empty($st['period_end'])): ?>
                                            <?= e(date('d.m.Y', strtotime((string)$st['period_start']))) ?> – <?= e(date('d.m.Y', strtotime((string)$st['period_end']))) ?>
                                        <?php else: ?>
                                            Jahr <?= (int)$st['statement_year'] ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 text-right whitespace-nowrap text-slate-600 dark:text-slate-300">
                                        <?= $st['start_balance'] !== null ? number_format((float)$st['start_balance'], 2, ',', '.') . ' €' : '—' ?>
                                    </td>
                                    <td class="py-3 px-3 text-right whitespace-nowrap">
                                        <span class="text-emerald-600 dark:text-emerald-400 font-semibold">+<?= number_format((float)$st['total_income'], 2, ',', '.') ?> €</span>
                                        <span class="text-slate-400 mx-1">/</span>
                                        <span class="text-rose-600 dark:text-rose-400 font-semibold">-<?= number_format((float)$st['total_expense'], 2, ',', '.') ?> €</span>
                                    </td>
                                    <td class="py-3 px-3 text-right whitespace-nowrap font-bold text-slate-900 dark:text-white">
                                        <?= $st['end_balance'] !== null ? number_format((float)$st['end_balance'], 2, ',', '.') . ' €' : '—' ?>
                                    </td>
                                    <td class="py-3 px-3 whitespace-nowrap">
                                        <?php if ($st['balance_check_ok'] === 1 || $st['balance_check_ok'] === '1'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                                                ✅ Cent-genau geprüft
                                            </span>
                                        <?php elseif ($st['balance_check_ok'] === 0 || $st['balance_check_ok'] === '0'): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300" title="Zwischen Anfangs- und Endsaldo fehlt evtl. eine Seite oder Buchung">
                                                ⚠️ Differenz prüfen
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400">Umsatzliste</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-3 whitespace-nowrap text-right">
                                        <div class="inline-flex items-center gap-1.5">
                                            <?php if ($fId > 0): ?>
                                                <a
                                                    href="download.php?id=<?= $fId ?>&amp;inline=1"
                                                    target="_blank"
                                                    class="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-violet-50 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 hover:bg-violet-100"
                                                    title="Originaldatei im Browser ansehen"
                                                >
                                                    👁️ Öffnen
                                                </a>
                                                <a
                                                    href="download.php?id=<?= $fId ?>"
                                                    class="px-2 py-1 rounded-lg text-[11px] font-semibold bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100"
                                                    title="Originaldatei herunterladen"
                                                >
                                                    ⬇️
                                                </a>
                                            <?php endif; ?>
                                            <form method="post" action="banking.php" class="inline" onsubmit="return confirm('Diesen Kontoauszug und seine importierten Buchungen aus der Analyse entfernen? (Die Datei im Cloud-Ordner bleibt erhalten)');">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete_statement">
                                                <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                                                <input type="hidden" name="statement_id" value="<?= (int)$st['id'] ?>">
                                                <button type="submit" class="px-2 py-1 rounded-lg text-[11px] bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-400 hover:bg-red-100 cursor-pointer" title="Auswertung löschen">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- =====================================================================
         TAB 4: ⚙️ KONTEN & KATEGORISIERUNGS-REGELN
         ===================================================================== -->
    <section id="bankingTab_settings" class="space-y-6 hidden">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Bankkonten verwalten (6 Spalten) -->
            <div class="lg:col-span-6 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">
                    🏦 Meine Bankkonten verwalten
                </h2>

                <div class="space-y-2.5">
                    <?php foreach ($accounts as $acc): ?>
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-between gap-2 text-xs">
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full inline-block" style="background-color: <?= e((string)$acc['color']) ?>"></span>
                                    <span><?= e((string)$acc['name']) ?></span>
                                    <span class="px-2 py-0.2 rounded bg-slate-200/70 dark:bg-slate-700 text-[10px]"><?= e((string)$acc['bank_name']) ?></span>
                                </div>
                                <div class="text-[11px] text-slate-500 mt-0.5">
                                    IBAN: <?= trim((string)$acc['iban']) !== '' ? e((string)$acc['iban']) : '—' ?> · Startsaldo: <?= number_format((float)$acc['initial_balance'], 2, ',', '.') ?> €
                                </div>
                            </div>
                            <?php if (count($accounts) > 1): ?>
                                <form method="post" action="banking.php" onsubmit="return confirm('Konto „<?= e((string)$acc['name']) ?>“ samt Buchungen wirklich löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_account">
                                    <input type="hidden" name="delete_account_id" value="<?= (int)$acc['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-400 hover:bg-red-100 font-semibold cursor-pointer">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Neues Konto oder aktives Konto bearbeiten -->
                <form method="post" action="banking.php" class="pt-3 border-t border-slate-200/80 dark:border-slate-800 space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_account">
                    <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">

                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        Konto bearbeiten oder neu anlegen
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">Aktion</label>
                            <select name="edit_account_id" class="w-full px-3 py-2 rounded-xl text-xs font-semibold bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                <option value="0">➕ Neues weiteres Konto anlegen</option>
                                <?php if ($activeAccount !== null): ?>
                                    <option value="<?= (int)$activeAccount['id'] ?>">✏️ Aktives Konto „<?= e((string)$activeAccount['name']) ?>“ bearbeiten</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">Kontobezeichnung</label>
                            <input type="text" name="name" placeholder="z.B. Postbank Girokonto" required class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">Bank / Institut</label>
                            <input type="text" name="bank_name" value="Postbank" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">IBAN (optional, wird sonst aus Auszug erkannt)</label>
                            <input type="text" name="iban" placeholder="DE..." class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">Startsaldo (falls kein PDF-Endsaldo vorliegt)</label>
                            <input type="text" name="initial_balance" value="0,00" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-500 mb-1">Kontoart</label>
                            <select name="account_type" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                <option value="Girokonto">Girokonto</option>
                                <option value="Gemeinschaftskonto">Gemeinschaftskonto</option>
                                <option value="Tagesgeld / Sparkonto">Tagesgeld / Sparkonto</option>
                                <option value="Kreditkarte">Kreditkarte</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white cursor-pointer">
                        💾 Konto speichern
                    </button>
                </form>
            </div>

            <!-- Eigene Kategorisierungs-Regeln (6 Spalten) -->
            <div class="lg:col-span-6 rounded-2xl bg-white/90 dark:bg-slate-900/90 border border-slate-200/80 dark:border-slate-800 p-5 space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="text-base font-bold text-slate-900 dark:text-white">
                            ✨ Automatische Zuordnungs-Regeln
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Zusätzlich zu den über 180 integrierten deutschen Händler-Erkennungen können Sie hier eigene Stichworte definieren.
                        </p>
                    </div>
                    <form method="post" action="banking.php">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="recategorize_all">
                        <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                        <button type="submit" class="px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-50 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 cursor-pointer" title="Alle vorhandenen Umsätze anhand der Regeln neu zuordnen">
                            🔄 Alle neu zuordnen
                        </button>
                    </form>
                </div>

                <form method="post" action="banking.php" class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="add_rule">
                    <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">

                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1">Suchbegriff / Empfänger</label>
                        <input type="text" name="keyword" placeholder="z.B. Vermieter Müller" required class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 mb-1">Ziel-Kategorie</label>
                        <select name="category" class="w-full px-3 py-2 rounded-xl text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                            <?php foreach ($bankingCategories as $cKey => $cMeta): ?>
                                <option value="<?= e($cKey) ?>"><?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input type="checkbox" name="is_fixed_cost" value="1" class="rounded border-slate-300 text-brand-600">
                            <span>Fixkosten</span>
                        </label>
                        <button type="submit" class="px-3.5 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-500 text-white cursor-pointer ml-auto">
                            ➕ Regel
                        </button>
                    </div>
                </form>

                <?php if (empty($userRulesList)): ?>
                    <p class="text-xs text-slate-400 italic py-4">Noch keine eigenen Zusatz-Regeln angelegt.</p>
                <?php else: ?>
                    <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                        <?php foreach ($userRulesList as $r):
                            $cMeta = $bankingCategories[(string)$r['category']] ?? $bankingCategories['other'];
                        ?>
                            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 dark:text-white">„<?= e((string)$r['keyword']) ?>“</span>
                                    <span class="mx-1.5 text-slate-400">&rarr;</span>
                                    <span class="font-semibold text-slate-700 dark:text-slate-200"><?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?></span>
                                    <?php if ((int)$r['is_fixed_cost'] === 1): ?>
                                        <span class="ml-1.5 px-1.5 py-0.2 rounded text-[10px] font-bold bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">🔄 Fixkosten</span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="banking.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
                                    <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:underline font-semibold cursor-pointer">Entfernen</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

</main>

<!-- PDF.js für koordinatengenaue Tabellen-Extraktion aus Bank-PDFs direkt im Browser -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function() {
    if (window.pdfjsLib && window.pdfjsLib.GlobalWorkerOptions) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }

    const initialTab = <?= json_encode($activeTab) ?>;

    window.switchBankingTab = function(tabName) {
        const tabs = ['cockpit', 'transactions', 'statements', 'settings'];
        tabs.forEach(t => {
            const sec = document.getElementById('bankingTab_' + t);
            if (sec) sec.classList.toggle('hidden', t !== tabName);
        });
        document.querySelectorAll('[data-banking-tab-btn]').forEach(btn => {
            const isAct = btn.getAttribute('data-banking-tab-btn') === tabName;
            btn.className = 'px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer flex items-center gap-1.5 ' + (
                isAct
                    ? 'bg-brand-600 text-white shadow-xs'
                    : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700'
            );
        });
        const filterBar = document.getElementById('bankingFilterBar');
        if (filterBar) {
            filterBar.classList.toggle('hidden', tabName === 'statements' || tabName === 'settings');
        }
        const tabInp = document.getElementById('filterFormTabInput');
        if (tabInp && (tabName === 'cockpit' || tabName === 'transactions')) {
            tabInp.value = tabName;
        }
    };

    window.toggleTxEditRow = function(txId) {
        const row = document.getElementById('txEditRow_' + txId);
        if (row) row.classList.toggle('hidden');
    };

    switchBankingTab(initialTab);

    /**
     * Liest ein PDF-Dokument (ArrayBuffer oder URL) über PDF.js ein und gruppiert
     * alle Text-Elemente jeder Seite exakt nach ihrer vertikalen Y-Koordinate (Toleranz 2.8pt)
     * und sortiert sie horizontal nach X-Koordinate.
     * Dadurch bleiben Postbank-Tabellenspalten ("Datum | Umsatzart / Empfänger | Betrag")
     * exakt in derselben Zeile erhalten!
     */
    async function extractStructuredLinesFromPdfSource(src) {
        if (!window.pdfjsLib) return '';
        const loadingTask = window.pdfjsLib.getDocument(src);
        const pdf = await loadingTask.promise;
        const allPageLines = [];

        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const textContent = await page.getTextContent();
            const rows = [];

            for (const item of textContent.items) {
                const str = (item.str || '').trim();
                if (!str || !item.transform) continue;
                const x = Number(item.transform[4] || 0);
                const y = Number(item.transform[5] || 0);

                // Passende Zeile innerhalb von 2.8pt Y-Toleranz suchen
                let targetRow = null;
                for (const r of rows) {
                    if (Math.abs(r.y - y) <= 2.8) {
                        targetRow = r;
                        break;
                    }
                }
                if (!targetRow) {
                    targetRow = { y: y, items: [] };
                    rows.push(targetRow);
                }
                targetRow.items.push({ x: x, str: str });
            }

            // Von oben nach unten sortieren (in PDF ist Y=0 unten, also absteigend)
            rows.sort((a, b) => b.y - a.y);

            for (const r of rows) {
                r.items.sort((a, b) => a.x - b.x);
                const lineText = r.items.map(it => it.str).join('   ');
                if (lineText.trim() !== '') {
                    allPageLines.push(lineText.trim());
                }
            }
        }
        return allPageLines.join('\n');
    }

    // 1. Beim Auswählen von lokalen PDF-Dateien automatisch Textzeilen im Browser vor-extrahieren
    const fileInput = document.getElementById('statementFilesInput');
    const statusBox = document.getElementById('pdfExtractionStatusBox');
    const hiddenJsonInput = document.getElementById('extractedPdfTextsJson');
    const submitBtn = document.getElementById('bankUploadSubmitBtn');

    if (fileInput && hiddenJsonInput) {
        fileInput.addEventListener('change', async () => {
            const files = Array.from(fileInput.files || []);
            const pdfFiles = files.filter(f => f.name.toLowerCase().endsWith('.pdf'));
            if (pdfFiles.length === 0 || !window.pdfjsLib) {
                hiddenJsonInput.value = '';
                if (statusBox) statusBox.classList.add('hidden');
                return;
            }

            if (statusBox) {
                statusBox.classList.remove('hidden');
                statusBox.textContent = `⏳ Lese ${pdfFiles.length} PDF-Kontoauszug/Kontoauszüge für die Tabellen-Analyse ein...`;
            }
            if (submitBtn) submitBtn.disabled = true;

            const extractedMap = {};
            for (const f of pdfFiles) {
                try {
                    const buf = await f.arrayBuffer();
                    const text = await extractStructuredLinesFromPdfSource({ data: buf });
                    if (text) {
                        extractedMap[f.name] = text;
                    }
                } catch (err) {
                    console.warn('PDF.js Extraktion Hinweis:', err);
                }
            }

            hiddenJsonInput.value = JSON.stringify(extractedMap);
            if (statusBox) {
                const countOk = Object.keys(extractedMap).length;
                statusBox.textContent = countOk > 0
                    ? `✅ ${countOk} PDF-Kontoauszug/Kontoauszüge bereit zur Auswertung. Klicken Sie jetzt auf „Importieren & auswerten“.`
                    : 'ℹ️ PDF wird direkt auf dem Server ausgewertet.';
            }
            if (submitBtn) submitBtn.disabled = false;
        });
    }

    // 2. Beim Import einer bereits in der Cloud liegenden PDF-Datei ebenfalls PDF.js unterstützen
    const cloudForm = document.getElementById('cloudImportForm');
    const cloudSelect = document.getElementById('cloudFileSelect');
    const cloudHiddenText = document.getElementById('cloudPdfExtractedText');
    const cloudSubmitBtn = document.getElementById('cloudImportSubmitBtn');

    if (cloudForm && cloudSelect && cloudHiddenText) {
        cloudForm.addEventListener('submit', async (ev) => {
            const opt = cloudSelect.options[cloudSelect.selectedIndex];
            if (!opt || !opt.value) return;
            const ext = opt.getAttribute('data-ext') || '';
            if (ext !== 'pdf' || !window.pdfjsLib || cloudHiddenText.getAttribute('data-extracted-for') === opt.value) {
                return;
            }
            ev.preventDefault();
            if (cloudSubmitBtn) {
                cloudSubmitBtn.disabled = true;
                cloudSubmitBtn.textContent = '⏳ Lese PDF-Tabellenzeilen...';
            }
            try {
                const text = await extractStructuredLinesFromPdfSource('download.php?id=' + encodeURIComponent(opt.value) + '&inline=1');
                cloudHiddenText.value = text || '';
            } catch (e) {
                cloudHiddenText.value = '';
            }
            cloudHiddenText.setAttribute('data-extracted-for', opt.value);
            cloudForm.submit();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
