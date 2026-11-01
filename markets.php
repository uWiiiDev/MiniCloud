<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📈 BÖRSE, KRYPTO, MUSTERDEPOT & KI-KURS-ANALYSE (markets.php)
 * ============================================================================
 * - Live-Kurse & historische Charts (1T, 5T, 1M, 6M, 1J, 5J, MAX/Allzeit)
 * - Weltweite Suche für Aktien (Xetra/US), ETFs, Indizes, Krypto & Edelmetalle
 * - Persönliche Watchlist & Musterdepot (Stückzahl, Kaufpreis, Gewinn/Verlust)
 * - Vollautomatisches Analyse-Radar:
 *     • Wochentags-Analyse (statistisch bester Kauftag vs. bester Verkaufstag)
 *     • Uhrzeiten-Analyse (Intraday-Zeitfenster für häufigste Tagestiefs & Tageshochs)
 *     • Auffälligkeits-Scanner (RSI-14, SMA-20/50/200, Volumen-Ausreißer, Volatilität)
 * - Ressourcenschonender Datenbank-Cache (`markets_cache`) ohne DB-Aufblähung
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_markets'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Börse, Krypto & Depot-Watchlist“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

if (function_exists('recordModuleUsage')) {
    recordModuleUsage($pdo, 'markets');
}

// ============================================================================
// 1. DATENBANK-TABELLEN INITIALISIEREN & STARTER-WATCHLIST ANLEGEN
// ============================================================================
function ensureMarketsTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS markets_cache (
            cache_key VARCHAR(160) NOT NULL PRIMARY KEY,
            json_data MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS markets_watchlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            symbol VARCHAR(40) NOT NULL,
            display_name VARCHAR(140) NOT NULL DEFAULT '',
            asset_category VARCHAR(32) NOT NULL DEFAULT 'stock',
            quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
            buy_price DECIMAL(18,4) NOT NULL DEFAULT 0,
            currency VARCHAR(12) NOT NULL DEFAULT 'EUR',
            notes VARCHAR(255) NOT NULL DEFAULT '',
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_symbol (user_id, symbol),
            INDEX idx_user_fav (user_id, is_favorite)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Gelegentliche Cache-Bereinigung (Einträge älter als 3 Tage löschen, damit die DB winzig bleibt)
    if ( random_int(1, 25) === 1 ) {
        try {
            $pdo->exec("DELETE FROM markets_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
        } catch (Throwable) {}
    }
}

ensureMarketsTables($pdo);

/**
 * Legt beim allerersten Besuch eines Nutzers eine sinnvolle Starter-Watchlist an.
 */
function seedDefaultWatchlistIfNeeded(PDO $pdo, int $userId): void
{
    $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM markets_watchlist WHERE user_id = ?');
    $stmtCnt->execute([$userId]);
    if ((int)$stmtCnt->fetchColumn() > 0) {
        return;
    }

    $defaults = [
        ['^GDAXI',  'DAX Performance-Index',            'index',     'EUR', 1],
        ['^GSPC',   'S&P 500 Index (USA)',              'index',     'USD', 0],
        ['EUNL.DE', 'iShares Core MSCI World ETF',      'etf',       'EUR', 0],
        ['NVDA',    'NVIDIA Corporation',               'stock',     'USD', 0],
        ['AAPL',    'Apple Inc.',                       'stock',     'USD', 0],
        ['SAP.DE',  'SAP SE (Xetra)',                   'stock',     'EUR', 0],
        ['BTC-EUR', 'Bitcoin (BTC / EUR)',              'crypto',    'EUR', 0],
        ['GC=F',    'Gold (Feinunze / USD)',            'commodity', 'USD', 0],
    ];

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO markets_watchlist
         (user_id, symbol, display_name, asset_category, quantity, buy_price, currency, is_favorite, created_at)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, NOW())'
    );
    foreach ($defaults as $d) {
        $ins->execute([$userId, $d[0], $d[1], $d[2], $d[3], $d[4]]);
    }
}

seedDefaultWatchlistIfNeeded($pdo, $userId);

// ============================================================================
// 2. KURS- & CHART-ABRUF MIT INTELLIGENTEM CACHE
// ============================================================================
/**
 * Erlaubte Chart-Zeiträume & zugehörige Kerzen-Intervalle + Cache-Dauer.
 *
 * @return array<string, array{label: string, title: string, range: string, interval: string, ttl: int}>
 */
function getMarketTimeframes(): array
{
    return [
        '1d'  => ['label' => '1T',  'title' => '1 Tag (5-Min.-Takt)',       'range' => '1d',  'interval' => '5m',  'ttl' => 120],
        '5d'  => ['label' => '5T',  'title' => '5 Tage (15-Min.-Takt)',     'range' => '5d',  'interval' => '15m', 'ttl' => 180],
        '1mo' => ['label' => '1M',  'title' => '1 Monat (Tageskurse)',      'range' => '1mo', 'interval' => '1d',  'ttl' => 600],
        '6mo' => ['label' => '6M',  'title' => '6 Monate (Tageskurse)',     'range' => '6mo', 'interval' => '1d',  'ttl' => 900],
        '1y'  => ['label' => '1J',  'title' => '1 Jahr (Tageskurse)',       'range' => '1y',  'interval' => '1d',  'ttl' => 1800],
        '5y'  => ['label' => '5J',  'title' => '5 Jahre (Wochenkurse)',     'range' => '5y',  'interval' => '1wk', 'ttl' => 3600],
        'max' => ['label' => 'MAX', 'title' => 'Allzeit-Historie (Monate)', 'range' => 'max', 'interval' => '1mo', 'ttl' => 7200],
    ];
}

/**
 * Holt JSON-Daten von Yahoo Finance mit MariaDB-Cache in `markets_cache`.
 *
 * @return array{data: array<string, mixed>, cached_at: string, from_cache: bool}
 */
function fetchCachedMarketJson(PDO $pdo, string $cacheKey, string $url, int $ttlSeconds = 300, bool $forceRefresh = false): array
{
    $stmt = $pdo->prepare('SELECT json_data, updated_at FROM markets_cache WHERE cache_key = ?');
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cached && !$forceRefresh) {
        $age = time() - (int)strtotime((string)$cached['updated_at']);
        if ($age >= 0 && $age <= $ttlSeconds) {
            $dec = json_decode((string)$cached['json_data'], true);
            if (is_array($dec)) {
                return [
                    'data'       => $dec,
                    'cached_at'  => (string)$cached['updated_at'],
                    'from_cache' => true,
                ];
            }
        }
    }

    $raw = null;
    $userAgents = [
        'YahooFinance/9.32.1 (iPhone; iOS 17.4; Scale/3.00)',
        'FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ];
    $candidateUrls = [$url];
    if (str_contains($url, 'query1.finance.yahoo.com')) {
        $q2 = str_replace('query1.finance.yahoo.com', 'query2.finance.yahoo.com', $url);
        $candidateUrls = [$q2, $url];
    }

    if (function_exists('curl_init')) {
        foreach ($candidateUrls as $idx => $tryUrl) {
            $ua = $userAgents[$idx % count($userAgents)];
            $ch = curl_init($tryUrl);
            if ($ch === false) {
                continue;
            }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ];
            if (defined('CURL_IPRESOLVE_V4')) {
                $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            }
            curl_setopt_array($ch, $opts);
            $res  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($res) && $code >= 200 && $code < 300 && strlen($res) > 25) {
                $raw = $res;
                break;
            }
        }
    } else {
        $ua  = $userAgents[0];
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 6,
                'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') {
            $raw = $res;
        }
    }

    if ($raw !== null) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $now = date('Y-m-d H:i:s');
            $up  = $pdo->prepare(
                'INSERT INTO markets_cache (cache_key, json_data, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = VALUES(updated_at)'
            );
            $up->execute([$cacheKey, $raw, $now]);
            return [
                'data'       => $dec,
                'cached_at'  => $now,
                'from_cache' => false,
            ];
        }
    }

    if ($cached) {
        $dec = json_decode((string)$cached['json_data'], true);
        if (is_array($dec)) {
            return [
                'data'       => $dec,
                'cached_at'  => (string)$cached['updated_at'],
                'from_cache' => true,
            ];
        }
    }

    return [
        'data'       => [],
        'cached_at'  => date('Y-m-d H:i:s'),
        'from_cache' => false,
    ];
}

/**
 * Lädt die Chart- & Kursdaten eines Symbols für einen Zeitraum (`1d`, `5d`, `1mo`, `6mo`, `1y`, `5y`, `max`).
 *
 * @return array<string, mixed>
 */
function fetchSymbolChartSeries(PDO $pdo, string $symbol, string $range = '1mo', string $interval = '1d', int $ttl = 300, bool $forceRefresh = false): array
{
    $cleanSym = strtoupper(trim($symbol));
    $cacheKey = 'yf_chart_' . md5($cleanSym . '_' . $range . '_' . $interval);
    $url      = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($cleanSym)
              . '?range=' . rawurlencode($range) . '&interval=' . rawurlencode($interval) . '&includePrePost=false';

    $res    = fetchCachedMarketJson($pdo, $cacheKey, $url, $ttl, $forceRefresh);
    $result = $res['data']['chart']['result'][0] ?? null;
    if (!is_array($result)) {
        return [
            'ok'         => false,
            'symbol'     => $cleanSym,
            'price'      => 0.0,
            'prev_close' => 0.0,
            'change_abs' => 0.0,
            'change_pct' => 0.0,
            'currency'   => 'EUR',
            'points'     => [],
            'cached_at'  => $res['cached_at'],
        ];
    }

    $meta       = $result['meta'] ?? [];
    $timestamps = $result['timestamp'] ?? [];
    $quote      = $result['indicators']['quote'][0] ?? [];

    $opens   = $quote['open'] ?? [];
    $highs   = $quote['high'] ?? [];
    $lows    = $quote['low'] ?? [];
    $closes  = $quote['close'] ?? [];
    $volumes = $quote['volume'] ?? [];

    $points = [];
    if (is_array($timestamps)) {
        foreach ($timestamps as $i => $ts) {
            $c = $closes[$i] ?? null;
            if ($c === null) {
                continue;
            }
            $cFloat = (float)$c;
            $points[] = [
                'ts'     => (int)$ts,
                'date'   => date('d.m.Y', (int)$ts),
                'time'   => date('H:i', (int)$ts),
                'open'   => isset($opens[$i]) && $opens[$i] !== null ? (float)$opens[$i] : $cFloat,
                'high'   => isset($highs[$i]) && $highs[$i] !== null ? (float)$highs[$i] : $cFloat,
                'low'    => isset($lows[$i]) && $lows[$i] !== null ? (float)$lows[$i] : $cFloat,
                'close'  => $cFloat,
                'volume' => isset($volumes[$i]) && $volumes[$i] !== null ? (int)$volumes[$i] : 0,
            ];
        }
    }

    $price = isset($meta['regularMarketPrice']) ? (float)$meta['regularMarketPrice'] : (!empty($points) ? (float)end($points)['close'] : 0.0);
    $prev  = isset($meta['chartPreviousClose']) ? (float)$meta['chartPreviousClose'] : (isset($meta['previousClose']) ? (float)$meta['previousClose'] : (!empty($points) ? (float)$points[0]['close'] : $price));
    if ($prev <= 0 && !empty($points)) {
        $prev = (float)$points[0]['close'];
    }

    $changeAbs = $price - $prev;
    $changePct = $prev > 0 ? (($changeAbs / $prev) * 100.0) : 0.0;

    return [
        'ok'          => true,
        'symbol'      => $cleanSym,
        'short_name'  => (string)($meta['shortName'] ?? $meta['longName'] ?? $cleanSym),
        'exchange'    => (string)($meta['exchangeName'] ?? ''),
        'currency'    => (string)($meta['currency'] ?? 'EUR'),
        'price'       => $price,
        'prev_close'  => $prev,
        'high_52w'    => isset($meta['fiftyTwoWeekHigh']) ? (float)$meta['fiftyTwoWeekHigh'] : 0.0,
        'low_52w'     => isset($meta['fiftyTwoWeekLow']) ? (float)$meta['fiftyTwoWeekLow'] : 0.0,
        'change_abs'  => $changeAbs,
        'change_pct'  => $changePct,
        'points'      => $points,
        'cached_at'   => $res['cached_at'],
    ];
}

// ============================================================================
// 3. VOLLAUTOMATISCHE KURS-, WOCHENTAGS-, UHRZEITEN- & AUFFÄLLIGKEITS-ANALYSE
// ============================================================================
/**
 * Analysiert die historischen Tageskurse (1 Jahr) sowie die Intraday-Stundenkerzen (1 Monat / 60m)
 * eines Wertpapiers auf:
 *  - Technische Signale (RSI-14, SMA-20, SMA-50, SMA-200, Bollinger-Position)
 *  - Auffälligkeiten (Volumen-Ausreißer, ungewöhnliche Tagessprünge / Volatilität, Trendbrüche)
 *  - Beste Wochentage (Mo–Fr) für Kauf (häufigste Rücksetzer/Tagestiefs) & Verkauf (höchste Rendite)
 *  - Beste Uhrzeiten (Stundenfenster) für Kauf (häufigste Intraday-Tiefs) & Verkauf (häufigste Intraday-Hochs)
 *
 * @return array<string, mixed>
 */
function analyzeMarketSymbolPatterns(PDO $pdo, string $symbol, bool $forceRefresh = false): array
{
    $dailySeries    = fetchSymbolChartSeries($pdo, $symbol, '1y', '1d', 1800, $forceRefresh);
    $intradaySeries = fetchSymbolChartSeries($pdo, $symbol, '1mo', '60m', 900, $forceRefresh);

    $pts = $dailySeries['points'] ?? [];
    $cnt = count($pts);

    if ($cnt < 15) {
        return ['ok' => false];
    }

    $closes  = array_column($pts, 'close');
    $volumes = array_column($pts, 'volume');
    $lastPrice = (float)end($closes);

    // 1. Gleitende Durchschnitte (SMA 20, SMA 50, SMA 200)
    $sma20  = $cnt >= 20 ? array_sum(array_slice($closes, -20)) / 20 : $lastPrice;
    $sma50  = $cnt >= 50 ? array_sum(array_slice($closes, -50)) / 50 : $sma20;
    $sma200 = $cnt >= 200 ? array_sum(array_slice($closes, -200)) / 200 : $sma50;

    $distSma20Pct  = $sma20 > 0 ? (($lastPrice - $sma20) / $sma20) * 100.0 : 0.0;
    $distSma50Pct  = $sma50 > 0 ? (($lastPrice - $sma50) / $sma50) * 100.0 : 0.0;
    $distSma200Pct = $sma200 > 0 ? (($lastPrice - $sma200) / $sma200) * 100.0 : 0.0;

    // 2. RSI-14 (Wilder / Standard 14-Tage Relative Strength Index)
    $gains = 0.0;
    $losses = 0.0;
    $rsiWindow = min(14, $cnt - 1);
    for ($i = $cnt - $rsiWindow; $i < $cnt; $i++) {
        $diff = (float)$closes[$i] - (float)$closes[$i - 1];
        if ($diff > 0) {
            $gains += $diff;
        } else {
            $losses += abs($diff);
        }
    }
    $avgGain = $gains / max(1, $rsiWindow);
    $avgLoss = $losses / max(1, $rsiWindow);
    $rsi14   = $avgLoss == 0.0 ? 100.0 : (100.0 - (100.0 / (1.0 + ($avgGain / $avgLoss))));

    // 3. Bollinger-Spanne (20 Tage ± 2 Standardabweichungen) & 52-Wochen-Spanne
    $slice20 = array_slice($closes, -min(20, $cnt));
    $mean20  = array_sum($slice20) / count($slice20);
    $varSum  = 0.0;
    foreach ($slice20 as $v) {
        $varSum += ($v - $mean20) ** 2;
    }
    $stdDev20   = sqrt($varSum / max(1, count($slice20)));
    $upperBand  = $mean20 + 2.0 * $stdDev20;
    $lowerBand  = $mean20 - 2.0 * $stdDev20;
    $min52w     = min($closes);
    $max52w     = max($closes);
    $pos52wPct  = ($max52w > $min52w) ? (($lastPrice - $min52w) / ($max52w - $min52w)) * 100.0 : 50.0;

    // 4. Auffälligkeiten-Scanner (Anomalien bei Volumen, Kurssprüngen, Trend & RSI)
    $anomalies = [];

    // Volumen-Check (letztes Volumen vs. 20-Tage-Schnitt)
    $volSlice = array_filter(array_slice($volumes, -21, 20), static fn($v): bool => $v > 0);
    $avgVol20 = !empty($volSlice) ? (array_sum($volSlice) / count($volSlice)) : 0;
    $lastVol  = (int)end($volumes);
    $volRatioPct = ($avgVol20 > 0 && $lastVol > 0) ? (($lastVol / $avgVol20) * 100.0) : 100.0;

    if ($volRatioPct >= 160) {
        $anomalies[] = [
            'badge' => '🔥 Volumen-Ausreißer',
            'color' => 'amber',
            'text'  => sprintf('Ungewöhnlich hohes Handelsvolumen: %.0f %% des 20-Tage-Durchschnitts (erhöhte institutionelle Aktivität).', $volRatioPct),
        ];
    }

    // RSI-Check
    if ($rsi14 <= 32) {
        $anomalies[] = [
            'badge' => '🟢 Stark überverkauft (RSI ' . number_format($rsi14, 1, ',', '.') . ')',
            'color' => 'emerald',
            'text'  => 'Der RSI-14 liegt im überverkauften Bereich (< 32). Historisch deutet dies häufig auf eine baldige Gegenbewegung (Rebound / Kaufchance) hin.',
        ];
    } elseif ($rsi14 >= 68) {
        $anomalies[] = [
            'badge' => '🔴 Überkauft / Heißgelaufen (RSI ' . number_format($rsi14, 1, ',', '.') . ')',
            'color' => 'rose',
            'text'  => 'Der RSI-14 signalisiert eine kurzfristige Überhitzung (> 68). Rücksetzer oder Gewinnmitnahmen treten in dieser Zone gehäuft auf.',
        ];
    }

    // Bollinger-Ausbruch
    if ($stdDev20 > 0 && $lastPrice <= $lowerBand * 1.005) {
        $anomalies[] = [
            'badge' => '📉 Unteres Bollinger-Band erreicht',
            'color' => 'emerald',
            'text'  => 'Der Kurs notiert am unteren Rand seiner statistischen 20-Tage-Schwankungsbreite (-2σ) – potenzielles Unterstützungsniveau.',
        ];
    } elseif ($stdDev20 > 0 && $lastPrice >= $upperBand * 0.995) {
        $anomalies[] = [
            'badge' => '📈 Oberes Bollinger-Band erreicht',
            'color' => 'amber',
            'text'  => 'Der Kurs kratzt am oberen Bollinger-Band (+2σ) – starkes Momentum, aber kurzfristig erhöhtes Rückschlagrisiko.',
        ];
    }

    // Trend-Struktur (SMA 20 vs SMA 50)
    if ($sma20 > $sma50 && $lastPrice > $sma20) {
        $anomalies[] = [
            'badge' => '🚀 Intakter Aufwärtstrend',
            'color' => 'emerald',
            'text'  => sprintf('Kurs liegt über dem 20-Tage- (%s %%) und 50-Tage-Durchschnitt (%s %%).', ($distSma20Pct >= 0 ? '+' : '') . number_format($distSma20Pct, 1, ',', '.'), ($distSma50Pct >= 0 ? '+' : '') . number_format($distSma50Pct, 1, ',', '.')),
        ];
    } elseif ($sma20 < $sma50 && $lastPrice < $sma20) {
        $anomalies[] = [
            'badge' => '⚠️ Abwärtstrend aktiv',
            'color' => 'rose',
            'text'  => sprintf('Kurs notiert unterhalb des 20-Tage- (%s %%) und 50-Tage-Durchschnitts.', number_format($distSma20Pct, 1, ',', '.')),
        ];
    }

    if (empty($anomalies)) {
        $anomalies[] = [
            'badge' => '✅ Ruhiger Normalhandel',
            'color' => 'sky',
            'text'  => 'Aktuell keine extremen Volumen- oder Volatilitäts-Ausreißer. Kurs bewegt sich innerhalb der normalen Bandbreite.',
        ];
    }

    // 5. WOCHENTAGS-ANALYSE (Montag = 1 bis Freitag = 5)
    $weekdayNames = [
        1 => 'Montag',
        2 => 'Dienstag',
        3 => 'Mittwoch',
        4 => 'Donnerstag',
        5 => 'Freitag',
    ];
    $weekdayStats = [];
    foreach ($weekdayNames as $dNum => $dName) {
        $weekdayStats[$dNum] = [
            'day_num'    => $dNum,
            'name'       => $dName,
            'count'      => 0,
            'sum_ret'    => 0.0,
            'pos_days'   => 0,
            'dip_days'   => 0, // Tage mit Rücksetzer (< -0,25 %) -> gut für Nachkäufe
            'avg_ret'    => 0.0,
            'win_rate'   => 0.0,
            'dip_rate'   => 0.0,
        ];
    }

    for ($i = 1; $i < $cnt; $i++) {
        $prevClose = (float)$pts[$i - 1]['close'];
        $curClose  = (float)$pts[$i]['close'];
        $curLow    = (float)$pts[$i]['low'];
        if ($prevClose <= 0) {
            continue;
        }
        $dow = (int)date('N', (int)$pts[$i]['ts']);
        if (!isset($weekdayStats[$dow])) {
            continue;
        }

        $retPct    = (($curClose - $prevClose) / $prevClose) * 100.0;
        $lowDipPct = (($curLow - $prevClose) / $prevClose) * 100.0;

        $weekdayStats[$dow]['count']++;
        $weekdayStats[$dow]['sum_ret'] += $retPct;
        if ($retPct > 0) {
            $weekdayStats[$dow]['pos_days']++;
        }
        if ($retPct < -0.15 || $lowDipPct < -0.8) {
            $weekdayStats[$dow]['dip_days']++;
        }
    }

    $bestBuyDay  = null;
    $bestSellDay = null;
    foreach ($weekdayStats as $dNum => &$st) {
        if ($st['count'] > 0) {
            $st['avg_ret']  = $st['sum_ret'] / $st['count'];
            $st['win_rate'] = ($st['pos_days'] / $st['count']) * 100.0;
            $st['dip_rate'] = ($st['dip_days'] / $st['count']) * 100.0;

            if ($bestBuyDay === null || $st['avg_ret'] < $bestBuyDay['avg_ret']) {
                $bestBuyDay = $st;
            }
            if ($bestSellDay === null || $st['avg_ret'] > $bestSellDay['avg_ret']) {
                $bestSellDay = $st;
            }
        }
    }
    unset($st);

    // 6. UHRZEITEN-ANALYSE (Intraday-Stundenfenster für Tagestiefs = Kauf & Tageshochs = Verkauf)
    $intraPts = $intradaySeries['points'] ?? [];
    $byDate   = [];
    foreach ($intraPts as $ip) {
        $dayKey = date('Y-m-d', (int)$ip['ts']);
        $hour   = (int)date('G', (int)$ip['ts']);
        // Nur reguläre Handelsstunden 8:00 bis 22:00 Uhr betrachten
        if ($hour < 8 || $hour > 22) {
            continue;
        }
        $byDate[$dayKey][] = [
            'hour'  => $hour,
            'high'  => (float)$ip['high'],
            'low'   => (float)$ip['low'],
            'close' => (float)$ip['close'],
        ];
    }

    $lowHourCounts  = [];
    $highHourCounts = [];
    $analyzedDays   = 0;

    foreach ($byDate as $dCandles) {
        if (count($dCandles) < 3) {
            continue;
        }
        $analyzedDays++;
        $minLow   = null;
        $minHour  = null;
        $maxHigh  = null;
        $maxHour  = null;

        foreach ($dCandles as $c) {
            if ($minLow === null || $c['low'] < $minLow) {
                $minLow  = $c['low'];
                $minHour = $c['hour'];
            }
            if ($maxHigh === null || $c['high'] > $maxHigh) {
                $maxHigh = $c['high'];
                $maxHour = $c['hour'];
            }
        }

        if ($minHour !== null) {
            $lowHourCounts[$minHour] = ($lowHourCounts[$minHour] ?? 0) + 1;
        }
        if ($maxHour !== null) {
            $highHourCounts[$maxHour] = ($highHourCounts[$maxHour] ?? 0) + 1;
        }
    }

    arsort($lowHourCounts);
    arsort($highHourCounts);

    $bestBuyHour  = !empty($lowHourCounts) ? (int)array_key_first($lowHourCounts) : 10;
    $bestSellHour = !empty($highHourCounts) ? (int)array_key_first($highHourCounts) : 16;
    if ($bestBuyHour === $bestSellHour && count($highHourCounts) > 1) {
        $keys = array_keys($highHourCounts);
        $bestSellHour = (int)$keys[1];
    }

    $bestBuyHourPct  = ($analyzedDays > 0 && isset($lowHourCounts[$bestBuyHour]))
        ? round(($lowHourCounts[$bestBuyHour] / $analyzedDays) * 100)
        : 28;
    $bestSellHourPct = ($analyzedDays > 0 && isset($highHourCounts[$bestSellHour]))
        ? round(($highHourCounts[$bestSellHour] / $analyzedDays) * 100)
        : 26;

    // Gesamtbewertung (Score 0–100: >60 = Einstiegs-/Rebound-Vorteil, <40 = Überhitzt/Verkaufszone)
    $signalScore = 50;
    if ($rsi14 < 35) $signalScore += 20;
    elseif ($rsi14 < 45) $signalScore += 10;
    elseif ($rsi14 > 70) $signalScore -= 22;
    elseif ($rsi14 > 62) $signalScore -= 10;

    if ($lastPrice > $sma50) $signalScore += 8;
    else $signalScore -= 6;

    if ($pos52wPct < 25) $signalScore += 10;
    elseif ($pos52wPct > 92) $signalScore -= 8;

    $signalScore = max(5, min(95, $signalScore));

    if ($signalScore >= 62) {
        $overallLabel = '🟢 Günstige Einstiegs- / Aufbauzone';
        $overallColor = 'emerald';
    } elseif ($signalScore <= 38) {
        $overallLabel = '🔴 Kurzfristig heißgelaufen / Gewinnmitnahme-Zone';
        $overallColor = 'rose';
    } else {
        $overallLabel = '🟡 Ausgewogener Trend / Halte- & Beobachtungszone';
        $overallColor = 'amber';
    }

    return [
        'ok'                 => true,
        'rsi_14'             => $rsi14,
        'sma_20'             => $sma20,
        'sma_50'             => $sma50,
        'sma_200'            => $sma200,
        'dist_sma20_pct'     => $distSma20Pct,
        'dist_sma50_pct'     => $distSma50Pct,
        'dist_sma200_pct'    => $distSma200Pct,
        'pos_52w_pct'        => $pos52wPct,
        'min_52w'            => $min52w,
        'max_52w'            => $max52w,
        'vol_ratio_pct'      => $volRatioPct,
        'anomalies'          => $anomalies,
        'weekdays'           => array_values($weekdayStats),
        'best_buy_day'       => $bestBuyDay,
        'best_sell_day'      => $bestSellDay,
        'best_buy_window'    => sprintf('%02d:00 – %02d:00 Uhr', $bestBuyHour, ($bestBuyHour + 1) % 24),
        'best_buy_hour_pct'  => $bestBuyHourPct,
        'best_sell_window'   => sprintf('%02d:00 – %02d:00 Uhr', $bestSellHour, ($bestSellHour + 1) % 24),
        'best_sell_hour_pct' => $bestSellHourPct,
        'analyzed_days'      => $cnt,
        'overall_score'      => $signalScore,
        'overall_label'      => $overallLabel,
        'overall_color'      => $overallColor,
    ];
}

// ============================================================================
// 4. AJAX-SUCHE FÜR WERTPAPIERE, ETFS & KRYPTO (?ajax=search&q=...)
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=UTF-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'quotes' => []]);
        exit;
    }

    $url = 'https://query1.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($q) . '&quotesCount=10&newsCount=0';
    $res = fetchCachedMarketJson($pdo, 'yf_search_' . md5(mb_strtolower($q)), $url, 3600, false);
    $rawQuotes = $res['data']['quotes'] ?? [];

    $quotes = [];
    if (is_array($rawQuotes)) {
        foreach ($rawQuotes as $rq) {
            $sym = trim((string)($rq['symbol'] ?? ''));
            if ($sym === '') {
                continue;
            }
            $qType = strtolower((string)($rq['quoteType'] ?? 'equity'));
            $cat   = match ($qType) {
                'index'          => 'index',
                'etf', 'mutualfund' => 'etf',
                'cryptocurrency' => 'crypto',
                'future', 'currency' => 'commodity',
                default          => 'stock',
            };
            $quotes[] = [
                'symbol'   => $sym,
                'name'     => (string)($rq['shortname'] ?? $rq['longname'] ?? $sym),
                'exchange' => (string)($rq['exchDisp'] ?? $rq['exchange'] ?? ''),
                'type'     => strtoupper($qType),
                'category' => $cat,
            ];
        }
    }

    echo json_encode(['ok' => true, 'quotes' => $quotes], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 5. POST-AKTIONEN (WATCHLIST & MUSTERDEPOT VERWALTEN)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: markets.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // A) Neues Wertpapier zur Watchlist hinzufügen
    if ($action === 'add_symbol') {
        $sym  = strtoupper(mb_substr(trim((string)($_POST['symbol'] ?? '')), 0, 40));
        $name = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 140);
        $cat  = strtolower(trim((string)($_POST['asset_category'] ?? 'stock')));
        if (!in_array($cat, ['index', 'stock', 'etf', 'crypto', 'commodity'], true)) {
            $cat = 'stock';
        }

        if ($sym !== '' && preg_match('/^[A-Z0-9\^\.\-\=]{1,40}$/', $sym)) {
            $chartCheck = fetchSymbolChartSeries($pdo, $sym, '1mo', '1d', 600, false);
            if ($chartCheck['ok']) {
                $finalName = $name !== '' ? $name : (string)($chartCheck['short_name'] ?: $sym);
                $curr      = (string)($chartCheck['currency'] ?: 'EUR');
                $ins = $pdo->prepare(
                    'INSERT INTO markets_watchlist (user_id, symbol, display_name, asset_category, currency, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), asset_category = VALUES(asset_category)'
                );
                $ins->execute([$userId, $sym, $finalName, $cat, $curr]);
                setFlash('success', sprintf('📈 „%s (%s)“ wurde zu deiner Watchlist hinzugefügt.', $finalName, $sym));
                header('Location: markets.php?symbol=' . rawurlencode($sym));
                exit;
            }
        }
        setFlash('error', 'Das angegebene Börsensymbol konnte nicht gefunden werden.');
        header('Location: markets.php');
        exit;
    }

    // B) Musterdepot-Bestand (Stückzahl & Kaufkurs) aktualisieren
    if ($action === 'update_holding') {
        $sym      = strtoupper(trim((string)($_POST['symbol'] ?? '')));
        $qtyRaw   = str_replace(',', '.', trim((string)($_POST['quantity'] ?? '0')));
        $buyRaw   = str_replace(',', '.', trim((string)($_POST['buy_price'] ?? '0')));
        $notes    = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);
        $quantity = max(0.0, (float)$qtyRaw);
        $buyPrice = max(0.0, (float)$buyRaw);

        $upd = $pdo->prepare(
            'UPDATE markets_watchlist
             SET quantity = ?, buy_price = ?, notes = ?
             WHERE user_id = ? AND symbol = ?'
        );
        $upd->execute([$quantity, $buyPrice, $notes, $userId, $sym]);
        setFlash('success', sprintf('💼 Musterdepot-Position für %s wurde gespeichert.', $sym));
        header('Location: markets.php?symbol=' . rawurlencode($sym));
        exit;
    }

    // C) Favoriten-Stern (Start-Wertpapier) setzen
    if ($action === 'set_favorite_symbol') {
        $sym = strtoupper(trim((string)($_POST['symbol'] ?? '')));
        $pdo->prepare('UPDATE markets_watchlist SET is_favorite = 0 WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE markets_watchlist SET is_favorite = 1 WHERE user_id = ? AND symbol = ?')->execute([$userId, $sym]);
        setFlash('success', sprintf('⭐ %s ist jetzt dein Start-Wertpapier.', $sym));
        header('Location: markets.php?symbol=' . rawurlencode($sym));
        exit;
    }

    // D) Wertpapier aus Watchlist entfernen
    if ($action === 'remove_symbol') {
        $sym = strtoupper(trim((string)($_POST['symbol'] ?? '')));
        $pdo->prepare('DELETE FROM markets_watchlist WHERE user_id = ? AND symbol = ?')->execute([$userId, $sym]);
        setFlash('success', sprintf('%s wurde aus deiner Watchlist entfernt.', $sym));
        header('Location: markets.php');
        exit;
    }
}

// ============================================================================
// 6. WATCHLIST LADEN & AKTUELLES SYMBOL + ZEITRAUM BESTIMMEN
// ============================================================================
$stmtWl = $pdo->prepare('SELECT * FROM markets_watchlist WHERE user_id = ? ORDER BY is_favorite DESC, id ASC');
$stmtWl->execute([$userId]);
$watchlistRows = $stmtWl->fetchAll(PDO::FETCH_ASSOC);

$timeframes   = getMarketTimeframes();
$selectedTf   = strtolower(trim((string)($_GET['tf'] ?? '6mo')));
if (!isset($timeframes[$selectedTf])) {
    $selectedTf = '6mo';
}
$tfMeta       = $timeframes[$selectedTf];
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

$defaultSymbol = !empty($watchlistRows) ? (string)$watchlistRows[0]['symbol'] : '^GDAXI';
$selectedSym   = strtoupper(trim((string)($_GET['symbol'] ?? $defaultSymbol)));

// Live-Kurse für alle Watchlist-Einträge laden + Musterdepot-Summen berechnen
$watchlistEnriched = [];
$portfolioTotalInvested = 0.0;
$portfolioTotalCurrent  = 0.0;
$portfolioDayChange     = 0.0;
$selectedRowMeta        = null;

foreach ($watchlistRows as $wRow) {
    $sym = (string)$wRow['symbol'];
    $qData = fetchSymbolChartSeries($pdo, $sym, '5d', '1d', 240, $forceRefresh && $sym === $selectedSym);

    $price     = (float)($qData['price'] ?? 0.0);
    $prevClose = (float)($qData['prev_close'] ?? $price);
    $chgAbs    = (float)($qData['change_abs'] ?? 0.0);
    $chgPct    = (float)($qData['change_pct'] ?? 0.0);
    $curr      = (string)($qData['currency'] ?: $wRow['currency'] ?: 'EUR');

    $qty      = (float)$wRow['quantity'];
    $buyPrice = (float)$wRow['buy_price'];
    $posVal   = $qty * $price;
    $invVal   = $qty * $buyPrice;
    $posPnl   = $posVal - $invVal;
    $posPnlPct = $invVal > 0 ? (($posPnl / $invVal) * 100.0) : 0.0;

    if ($qty > 0) {
        $portfolioTotalInvested += $invVal;
        $portfolioTotalCurrent  += $posVal;
        $portfolioDayChange     += $qty * $chgAbs;
    }

    $item = array_merge($wRow, [
        'live_price'      => $price,
        'prev_close'      => $prevClose,
        'change_abs'      => $chgAbs,
        'change_pct'      => $chgPct,
        'live_currency'   => $curr,
        'pos_value'       => $posVal,
        'invested_value'  => $invVal,
        'pos_pnl'         => $posPnl,
        'pos_pnl_pct'     => $posPnlPct,
        'sparkline_pts'   => array_column($qData['points'] ?? [], 'close'),
    ]);

    if ($sym === $selectedSym) {
        $selectedRowMeta = $item;
    }
    $watchlistEnriched[] = $item;
}

// Haupt-Chartserie & KI-Musteranalyse für das ausgewählte Symbol abrufen
$activeChart    = fetchSymbolChartSeries($pdo, $selectedSym, $tfMeta['range'], $tfMeta['interval'], $tfMeta['ttl'], $forceRefresh);
$activeAnalysis = analyzeMarketSymbolPatterns($pdo, $selectedSym, $forceRefresh);

if ($selectedRowMeta === null) {
    $selectedRowMeta = [
        'symbol'         => $selectedSym,
        'display_name'   => (string)($activeChart['short_name'] ?? $selectedSym),
        'asset_category' => 'stock',
        'quantity'       => 0,
        'buy_price'      => 0,
        'notes'          => '',
        'is_favorite'    => 0,
        'live_price'     => (float)($activeChart['price'] ?? 0.0),
        'change_abs'     => (float)($activeChart['change_abs'] ?? 0.0),
        'change_pct'     => (float)($activeChart['change_pct'] ?? 0.0),
        'live_currency'  => (string)($activeChart['currency'] ?? 'EUR'),
    ];
}

// Zeitraum-Rendite für den aktuell gewählten Chart-Zeitraum berechnen
$chartPts = $activeChart['points'] ?? [];
$tfStartPrice = !empty($chartPts) ? (float)$chartPts[0]['close'] : (float)$activeChart['price'];
$tfEndPrice   = !empty($chartPts) ? (float)end($chartPts)['close'] : (float)$activeChart['price'];
$tfChangeAbs  = $tfEndPrice - $tfStartPrice;
$tfChangePct  = $tfStartPrice > 0 ? (($tfChangeAbs / $tfStartPrice) * 100.0) : 0.0;

$pageTitle = 'Börse, Krypto & Depot-Analyse';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- 1. KOPFBEREICH & WERTPAPIER-SCHNELLSUCHE                           -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-start sm:items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    📈
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Börse, Krypto &amp; KI-Kurs-Radar
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                            Echtzeit-Kurse &amp; Musterdepot
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex flex-wrap items-center gap-2">
                        <span>📡 Globale Marktdaten (Xetra, NASDAQ, NYSE, Krypto &amp; Rohstoffe)</span>
                        <span>•</span>
                        <span>Stand: <?= date('d.m.Y H:i', (int)strtotime((string)($activeChart['cached_at'] ?? 'now'))) ?> Uhr</span>
                        <a href="markets.php?symbol=<?= rawurlencode($selectedSym) ?>&amp;tf=<?= rawurlencode($selectedTf) ?>&amp;refresh=1"
                           class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 hover:underline font-semibold">
                            🔄 Kurse jetzt live aktualisieren
                        </a>
                    </p>
                </div>
            </div>

            <!-- Live-Suchfeld für Aktien, ETFs, Indizes & Krypto -->
            <div class="relative w-full lg:w-96">
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <input type="text" id="marketSearchInput"
                               placeholder="🔍 Aktie, ETF, Index oder Krypto suchen (z.B. Allianz, MSCI, BTC)…"
                               autocomplete="off"
                               oninput="searchMarketSymbols(this.value)"
                               class="w-full pl-3.5 pr-8 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>
                <!-- Dropdown-Suchergebnisse -->
                <div id="marketSearchResults" class="hidden absolute left-0 right-0 mt-1.5 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl z-30 max-h-80 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800"></div>
            </div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- 2. MUSTERDEPOT-ZUSAMMENFASSUNG (FALLS POSITIONEN EINGETRAGEN SIND) -->
    <!-- ================================================================== -->
    <?php if ($portfolioTotalInvested > 0 || $portfolioTotalCurrent > 0): ?>
        <?php
        $totalPnlAbs = $portfolioTotalCurrent - $portfolioTotalInvested;
        $totalPnlPct = $portfolioTotalInvested > 0 ? (($totalPnlAbs / $portfolioTotalInvested) * 100.0) : 0.0;
        ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">💼 Aktueller Musterdepot-Wert</div>
                <div class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white mt-1">
                    <?= number_format($portfolioTotalCurrent, 2, ',', '.') ?> €
                </div>
                <div class="text-xs text-slate-500 mt-0.5">Kaufwert: <?= number_format($portfolioTotalInvested, 2, ',', '.') ?> €</div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">📊 Gesamt-Gewinn / -Verlust</div>
                <div class="text-xl sm:text-2xl font-black mt-1 <?= $totalPnlAbs >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                    <?= ($totalPnlAbs >= 0 ? '+' : '') . number_format($totalPnlAbs, 2, ',', '.') ?> €
                    <span class="text-sm font-bold">(<?= ($totalPnlPct >= 0 ? '+' : '') . number_format($totalPnlPct, 2, ',', '.') ?> %)</span>
                </div>
                <div class="text-xs text-slate-500 mt-0.5">Über alle eingetragenen Depot-Positionen</div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400">⚡ Heutige Depot-Veränderung</div>
                <div class="text-xl sm:text-2xl font-black mt-1 <?= $portfolioDayChange >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                    <?= ($portfolioDayChange >= 0 ? '+' : '') . number_format($portfolioDayChange, 2, ',', '.') ?> €
                </div>
                <div class="text-xs text-slate-500 mt-0.5">Verglichen mit dem gestrigen Schlusskurs</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- 3. HAUPTGRID: LINKS WATCHLIST (4 SP.), RECHTS CHART & KI-ANALYSE   -->
    <!-- ================================================================== -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

        <!-- ============================================================== -->
        <!-- LINKE SPALTE: WATCHLIST & MUSTERDEPOT-LISTE                    -->
        <!-- ============================================================== -->
        <section class="xl:col-span-4 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-200/80 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-white">⭐ Meine Watchlist &amp; Depot</h2>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">Klicke auf einen Wert für Chart &amp; Timing-Analyse</p>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                    <?= count($watchlistEnriched) ?> Werte
                </span>
            </div>

            <div class="divide-y divide-slate-200/70 dark:divide-slate-800 max-h-[760px] overflow-y-auto">
                <?php foreach ($watchlistEnriched as $item): ?>
                    <?php
                    $sym       = (string)$item['symbol'];
                    $isActive  = ($sym === $selectedSym);
                    $isPos     = ((float)$item['change_pct'] >= 0);
                    $catBadge  = match ((string)$item['asset_category']) {
                        'index'     => '📊 Index',
                        'etf'       => '🌍 ETF',
                        'crypto'    => '🪙 Krypto',
                        'commodity' => '🥇 Rohstoff',
                        default     => '🏢 Aktie',
                    };
                    ?>
                    <div class="p-3.5 transition-colors <?= $isActive ? 'bg-emerald-50/80 dark:bg-emerald-950/30 border-l-4 border-emerald-500' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50' ?>">
                        <div class="flex items-start justify-between gap-2">
                            <a href="markets.php?symbol=<?= rawurlencode($sym) ?>&amp;tf=<?= rawurlencode($selectedTf) ?>" class="min-w-0 flex-1 group">
                                <div class="flex items-center gap-1.5">
                                    <?php if ((int)$item['is_favorite'] === 1): ?>
                                        <span class="text-amber-400 text-xs" title="Start-Favorit">⭐</span>
                                    <?php endif; ?>
                                    <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white group-hover:text-emerald-600 dark:group-hover:text-emerald-400 truncate">
                                        <?= e((string)$item['display_name']) ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5 mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    <span class="font-mono font-bold text-slate-700 dark:text-slate-300"><?= e($sym) ?></span>
                                    <span>•</span>
                                    <span><?= $catBadge ?></span>
                                </div>
                            </a>

                            <div class="text-right shrink-0">
                                <a href="markets.php?symbol=<?= rawurlencode($sym) ?>&amp;tf=<?= rawurlencode($selectedTf) ?>" class="block">
                                    <div class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white font-mono">
                                        <?= number_format((float)$item['live_price'], 2, ',', '.') ?> <?= e((string)$item['live_currency']) ?>
                                    </div>
                                    <span class="inline-block px-1.5 py-0.5 rounded-md text-[10px] font-extrabold font-mono mt-0.5 <?= $isPos ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300' ?>">
                                        <?= ($isPos ? '+' : '') . number_format((float)$item['change_pct'], 2, ',', '.') ?> %
                                    </span>
                                </a>
                            </div>
                        </div>

                        <?php if ((float)$item['quantity'] > 0): ?>
                            <div class="mt-2 pt-2 border-t border-slate-200/60 dark:border-slate-800 flex items-center justify-between text-[11px]">
                                <span class="text-slate-500 dark:text-slate-400">
                                    💼 <?= rtrim(rtrim(number_format((float)$item['quantity'], 4, ',', '.'), '0'), ',') ?> Stk.
                                    (<?= number_format((float)$item['pos_value'], 2, ',', '.') ?> <?= e((string)$item['live_currency']) ?>)
                                </span>
                                <span class="font-bold <?= (float)$item['pos_pnl'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                    <?= ((float)$item['pos_pnl'] >= 0 ? '+' : '') . number_format((float)$item['pos_pnl'], 2, ',', '.') ?> <?= e((string)$item['live_currency']) ?>
                                    (<?= ((float)$item['pos_pnl_pct'] >= 0 ? '+' : '') . number_format((float)$item['pos_pnl_pct'], 1, ',', '.') ?> %)
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- RECHTE SPALTE: INTERAKTIVER CHART + AUTOMATISCHE ANALYSE       -->
        <!-- ============================================================== -->
        <section class="xl:col-span-8 space-y-6">

            <!-- A) CHART-KARTE MIT ZEITRAUM-WAHL (1T BIS MAX) -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white">
                                <?= e((string)$selectedRowMeta['display_name']) ?>
                            </h2>
                            <span class="px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 font-mono text-xs font-bold text-slate-600 dark:text-slate-300">
                                <?= e($selectedSym) ?>
                            </span>

                            <!-- Als Start-Favorit markieren -->
                            <form method="post" action="markets.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="set_favorite_symbol">
                                <input type="hidden" name="symbol" value="<?= e($selectedSym) ?>">
                                <button type="submit" title="Als Start-Wertpapier mit Sternchen anpinnen"
                                        class="px-2 py-0.5 rounded-lg border text-xs font-bold cursor-pointer <?= (int)($selectedRowMeta['is_favorite'] ?? 0) === 1 ? 'bg-amber-400/20 border-amber-400 text-amber-800 dark:text-amber-300' : 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-500 hover:text-amber-500' ?>">
                                    <?= (int)($selectedRowMeta['is_favorite'] ?? 0) === 1 ? '⭐ Start-Wert' : '☆ Als Start-Wert' ?>
                                </button>
                            </form>

                            <?php if (count($watchlistEnriched) > 1): ?>
                                <form method="post" action="markets.php" class="inline" onsubmit="return confirm('Wertpapier aus deiner Watchlist entfernen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove_symbol">
                                    <input type="hidden" name="symbol" value="<?= e($selectedSym) ?>">
                                    <button type="submit" title="Aus Watchlist entfernen"
                                            class="px-2 py-0.5 rounded-lg bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 hover:bg-rose-100 text-xs font-bold cursor-pointer">
                                        🗑️
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-wrap items-baseline gap-3 mt-1.5">
                            <span class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white font-mono">
                                <?= number_format((float)$activeChart['price'], 2, ',', '.') ?> <?= e((string)$activeChart['currency']) ?>
                            </span>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-extrabold font-mono <?= $tfChangePct >= 0 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300' ?>">
                                <?= ($tfChangeAbs >= 0 ? '+' : '') . number_format($tfChangeAbs, 2, ',', '.') ?> <?= e((string)$activeChart['currency']) ?>
                                (<?= ($tfChangePct >= 0 ? '+' : '') . number_format($tfChangePct, 2, ',', '.') ?> % in <?= e($tfMeta['label']) ?>)
                            </span>
                        </div>
                    </div>

                    <!-- Zeitraum-Buttons: 1T, 5T, 1M, 6M, 1J, 5J, MAX -->
                    <div class="flex flex-wrap items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl border border-slate-200/70 dark:border-slate-700 self-start">
                        <?php foreach ($timeframes as $tfKey => $tfCfg): ?>
                            <a href="markets.php?symbol=<?= rawurlencode($selectedSym) ?>&amp;tf=<?= rawurlencode($tfKey) ?>"
                               title="<?= e($tfCfg['title']) ?>"
                               class="px-2.5 py-1.5 rounded-lg text-xs font-extrabold transition-all <?= $tfKey === $selectedTf ? 'bg-emerald-600 text-white shadow-xs' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>">
                                <?= e($tfCfg['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Interaktiver Kurs-Chart (Canvas mit Crosshair & SMA-Linien) -->
                <div class="relative">
                    <div id="chartHoverInfo" class="text-xs font-mono text-slate-500 dark:text-slate-400 mb-2 min-h-[20px] flex flex-wrap items-center justify-between gap-2">
                        <span>Bewege die Maus oder den Finger über den Chart für exakte Kurswerte</span>
                        <span class="inline-flex items-center gap-3 text-[11px]">
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-0.5 bg-amber-400 inline-block"></span> SMA-20</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-0.5 bg-indigo-400 inline-block"></span> SMA-50</span>
                        </span>
                    </div>
                    <div class="w-full h-72 sm:h-80 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200/70 dark:border-slate-800 p-2 relative">
                        <canvas id="marketPriceCanvas" class="w-full h-full cursor-crosshair"></canvas>
                    </div>
                </div>
            </div>

            <!-- B) 🧠 AUTOMATISCHE KURS-, WOCHENTAGS-, UHRZEITEN- & AUFFÄLLIGKEITS-ANALYSE -->
            <?php if (!empty($activeAnalysis['ok'])): ?>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5 space-y-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-lg">🧠</span>
                                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                                    Automatische Kursverlauf-, Timing- &amp; Auffälligkeits-Analyse
                                </h2>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                Statistische Auswertung der letzten <?= (int)$activeAnalysis['analyzed_days'] ?> Handelstage &amp; Intraday-Stundenkerzen für <strong><?= e((string)$selectedRowMeta['display_name']) ?></strong>
                            </p>
                        </div>

                        <?php
                        $ovCol = (string)$activeAnalysis['overall_color'];
                        $ovBadgeCls = $ovCol === 'emerald'
                            ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border-emerald-300 dark:border-emerald-800'
                            : ($ovCol === 'rose'
                                ? 'bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300 border-rose-300 dark:border-rose-800'
                                : 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-800');
                        ?>
                        <span class="px-3 py-1.5 rounded-xl text-xs font-extrabold border <?= $ovBadgeCls ?>">
                            <?= e((string)$activeAnalysis['overall_label']) ?>
                        </span>
                    </div>

                    <!-- 1. Empfohlene Tage & Uhrzeiten zum Kauf und Verkauf -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- KAUF-TIMING (Statistisch günstigste Einstiegsfenster) -->
                        <div class="p-4 rounded-2xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-800/70 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-extrabold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
                                    🟢 Statistisch bester Kauf-Zeitpunkt (Dip-Einstieg)
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <div class="p-2.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-emerald-200/60 dark:border-emerald-800/60">
                                    <div class="text-[10px] font-bold uppercase text-slate-400">📅 Bevorzugter Kauftag</div>
                                    <div class="text-sm sm:text-base font-black text-emerald-700 dark:text-emerald-300 mt-0.5">
                                        <?= e((string)($activeAnalysis['best_buy_day']['name'] ?? 'Dienstag')) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Ø <?= number_format((float)($activeAnalysis['best_buy_day']['avg_ret'] ?? 0), 2, ',', '.') ?> % •
                                        <?= number_format((float)($activeAnalysis['best_buy_day']['dip_rate'] ?? 0), 0, ',', '.') ?> % Rücksetzer-Quote
                                    </div>
                                </div>
                                <div class="p-2.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-emerald-200/60 dark:border-emerald-800/60">
                                    <div class="text-[10px] font-bold uppercase text-slate-400">⏰ Günstigste Uhrzeit</div>
                                    <div class="text-sm sm:text-base font-black text-emerald-700 dark:text-emerald-300 mt-0.5">
                                        <?= e((string)$activeAnalysis['best_buy_window']) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        <?= (int)$activeAnalysis['best_buy_hour_pct'] ?> % aller Tagestiefs in diesem Fenster
                                    </div>
                                </div>
                            </div>
                            <p class="text-[11px] text-emerald-900/80 dark:text-emerald-200/80">
                                💡 An diesem Wochentag und in diesem Stundenfenster traten in der Vergangenheit die häufigsten Kurs-Rücksetzer (Tagestiefs) für günstigere Einstiege auf.
                            </p>
                        </div>

                        <!-- VERKAUFS-TIMING (Statistisch stärkste Kursphasen) -->
                        <div class="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-800/70 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-extrabold uppercase tracking-wider text-indigo-800 dark:text-indigo-300">
                                    🔵 Statistisch bester Verkaufs-Zeitpunkt (Stärke)
                                </span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <div class="p-2.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-indigo-200/60 dark:border-indigo-800/60">
                                    <div class="text-[10px] font-bold uppercase text-slate-400">📅 Stärkster Handelstag</div>
                                    <div class="text-sm sm:text-base font-black text-indigo-700 dark:text-indigo-300 mt-0.5">
                                        <?= e((string)($activeAnalysis['best_sell_day']['name'] ?? 'Donnerstag')) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Ø +<?= number_format(max(0.01, (float)($activeAnalysis['best_sell_day']['avg_ret'] ?? 0)), 2, ',', '.') ?> % •
                                        <?= number_format((float)($activeAnalysis['best_sell_day']['win_rate'] ?? 50), 0, ',', '.') ?> % im Plus
                                    </div>
                                </div>
                                <div class="p-2.5 rounded-xl bg-white/90 dark:bg-slate-900/90 border border-indigo-200/60 dark:border-indigo-800/60">
                                    <div class="text-[10px] font-bold uppercase text-slate-400">⏰ Höchste Tageskurse</div>
                                    <div class="text-sm sm:text-base font-black text-indigo-700 dark:text-indigo-300 mt-0.5">
                                        <?= e((string)$activeAnalysis['best_sell_window']) ?>
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        <?= (int)$activeAnalysis['best_sell_hour_pct'] ?> % aller Tageshochs in diesem Fenster
                                    </div>
                                </div>
                            </div>
                            <p class="text-[11px] text-indigo-900/80 dark:text-indigo-200/80">
                                💡 In diesem Zeitfenster wurden historisch die höchsten Tageskurse und die stärksten Schlusskurse für Gewinnmitnahmen erzielt.
                            </p>
                        </div>
                    </div>

                    <!-- 2. Technische Kennzahlen-Leiste (RSI-14, SMA-20, SMA-50, 52W-Spanne) -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                            <div class="text-[10px] font-bold uppercase text-slate-400">RSI (14 Tage)</div>
                            <div class="text-base font-black text-slate-900 dark:text-white mt-0.5">
                                <?= number_format((float)$activeAnalysis['rsi_14'], 1, ',', '.') ?>
                            </div>
                            <div class="text-[10px] text-slate-500">
                                <?= (float)$activeAnalysis['rsi_14'] < 32 ? '🟢 Überverkauft (<32)' : ((float)$activeAnalysis['rsi_14'] > 68 ? '🔴 Überkauft (>68)' : '⚖️ Neutral (32–68)') ?>
                            </div>
                        </div>

                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Abstand SMA-20</div>
                            <div class="text-base font-black <?= (float)$activeAnalysis['dist_sma20_pct'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?> mt-0.5">
                                <?= ((float)$activeAnalysis['dist_sma20_pct'] >= 0 ? '+' : '') . number_format((float)$activeAnalysis['dist_sma20_pct'], 2, ',', '.') ?> %
                            </div>
                            <div class="text-[10px] text-slate-500">Ø <?= number_format((float)$activeAnalysis['sma_20'], 2, ',', '.') ?> <?= e((string)$activeChart['currency']) ?></div>
                        </div>

                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Abstand SMA-50</div>
                            <div class="text-base font-black <?= (float)$activeAnalysis['dist_sma50_pct'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?> mt-0.5">
                                <?= ((float)$activeAnalysis['dist_sma50_pct'] >= 0 ? '+' : '') . number_format((float)$activeAnalysis['dist_sma50_pct'], 2, ',', '.') ?> %
                            </div>
                            <div class="text-[10px] text-slate-500">Ø <?= number_format((float)$activeAnalysis['sma_50'], 2, ',', '.') ?> <?= e((string)$activeChart['currency']) ?></div>
                        </div>

                        <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700">
                            <div class="text-[10px] font-bold uppercase text-slate-400">Jahresspanne (52W)</div>
                            <div class="text-base font-black text-slate-900 dark:text-white mt-0.5">
                                <?= number_format((float)$activeAnalysis['pos_52w_pct'], 0, ',', '.') ?> %
                            </div>
                            <div class="text-[10px] text-slate-500">
                                <?= number_format((float)$activeAnalysis['min_52w'], 0, ',', '.') ?> – <?= number_format((float)$activeAnalysis['max_52w'], 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Erkannte Auffälligkeiten im Kursverlauf -->
                    <div class="space-y-2">
                        <div class="text-xs font-extrabold uppercase tracking-wider text-slate-400">
                            🚨 Automatisch erkannte Kurs-Auffälligkeiten &amp; Signale
                        </div>
                        <div class="grid grid-cols-1 gap-2">
                            <?php foreach ($activeAnalysis['anomalies'] as $anom): ?>
                                <?php
                                $aCol = (string)$anom['color'];
                                $aCls = $aCol === 'emerald'
                                    ? 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800 text-emerald-900 dark:text-emerald-200'
                                    : ($aCol === 'rose'
                                        ? 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800 text-rose-900 dark:text-rose-200'
                                        : ($aCol === 'amber'
                                            ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-200'
                                            : 'bg-sky-50 dark:bg-sky-950/40 border-sky-200 dark:border-sky-800 text-sky-900 dark:text-sky-200'));
                                ?>
                                <div class="p-3 rounded-xl border text-xs flex flex-col sm:flex-row sm:items-center gap-2 <?= $aCls ?>">
                                    <span class="font-extrabold shrink-0"><?= e((string)$anom['badge']) ?>:</span>
                                    <span><?= e((string)$anom['text']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 4. Wochentags-Übersicht (Mo–Fr) -->
                    <div class="space-y-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <div class="text-xs font-extrabold uppercase tracking-wider text-slate-400">
                            📅 Wochentags-Bilanz (Montag bis Freitag im 1-Jahres-Rückblick)
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                            <?php foreach ($activeAnalysis['weekdays'] as $wd): ?>
                                <?php $wdPos = ((float)$wd['avg_ret'] >= 0); ?>
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700 text-center">
                                    <div class="text-xs font-extrabold text-slate-800 dark:text-slate-200"><?= e((string)$wd['name']) ?></div>
                                    <div class="text-sm font-black font-mono mt-1 <?= $wdPos ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' ?>">
                                        <?= ($wdPos ? '+' : '') . number_format((float)$wd['avg_ret'], 2, ',', '.') ?> %
                                    </div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">
                                        <?= number_format((float)$wd['win_rate'], 0, ',', '.') ?> % Plus-Tage
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500 pt-1">
                            ℹ️ Hinweis: Diese Auswertung basiert auf mathematisch-statistischen Berechnungen historischer Kursdaten (RSI, gleitende Durchschnitte, Wochentags- und Intraday-Verteilung) und stellt keine Anlageberatung dar.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- C) 💼 MUSTERDEPOT-BESTAND FÜR DIESES WERTPAPIER BEARBEITEN -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5">
                <h3 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white mb-1">
                    💼 Eigener Musterdepot-Bestand für <?= e((string)$selectedRowMeta['display_name']) ?> (<?= e($selectedSym) ?>)
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                    Trage optional deine gekaufte Stückzahl und deinen Einstandskurs ein, um deinen persönlichen Gewinn / Verlust live zu verfolgen.
                </p>

                <form method="post" action="markets.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="update_holding">
                    <input type="hidden" name="symbol" value="<?= e($selectedSym) ?>">

                    <div class="sm:col-span-3">
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Stückzahl / Anteile</label>
                        <input type="number" step="0.000001" min="0" name="quantity"
                               value="<?= (float)($selectedRowMeta['quantity'] ?? 0) > 0 ? e(rtrim(rtrim(number_format((float)$selectedRowMeta['quantity'], 6, '.', ''), '0'), '.')) : '' ?>"
                               placeholder="z.B. 10"
                               class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    </div>

                    <div class="sm:col-span-3">
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Kaufkurs pro Stück (<?= e((string)$activeChart['currency']) ?>)</label>
                        <input type="number" step="0.0001" min="0" name="buy_price"
                               value="<?= (float)($selectedRowMeta['buy_price'] ?? 0) > 0 ? e(rtrim(rtrim(number_format((float)$selectedRowMeta['buy_price'], 4, '.', ''), '0'), '.')) : '' ?>"
                               placeholder="z.B. <?= number_format((float)$activeChart['price'], 2, '.', '') ?>"
                               class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    </div>

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">Notiz / Kursziel (optional)</label>
                        <input type="text" name="notes" maxlength="255"
                               value="<?= e((string)($selectedRowMeta['notes'] ?? '')) ?>"
                               placeholder="z.B. Sparplan monatlich / Nachkauf bei Rücksetzer"
                               class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white">
                    </div>

                    <div class="sm:col-span-2">
                        <button type="submit"
                                class="w-full py-2 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer">
                            💾 Speichern
                        </button>
                    </div>
                </form>
            </div>

        </section>
    </div>
</main>

<form id="quickAddSymbolForm" method="post" action="markets.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
    <input type="hidden" name="action" value="add_symbol">
    <input type="hidden" name="symbol" id="quickAddSymInput">
    <input type="hidden" name="display_name" id="quickAddNameInput">
    <input type="hidden" name="asset_category" id="quickAddCatInput">
</form>

<script>
const CHART_POINTS   = <?= json_encode($activeChart['points'] ?? []) ?>;
const CHART_CURRENCY = <?= json_encode((string)($activeChart['currency'] ?? 'EUR')) ?>;
const IS_POSITIVE_TF = <?= $tfChangePct >= 0 ? 'true' : 'false' ?>;

// 1. Interaktiven HTML5-Canvas-Chart mit SMA-20, SMA-50 & Crosshair zeichnen
(() => {
    const canvas = document.getElementById('marketPriceCanvas');
    const infoEl = document.getElementById('chartHoverInfo');
    if (!canvas || !Array.isArray(CHART_POINTS) || CHART_POINTS.length < 2) return;

    const ctx = canvas.getContext('2d');
    const closes = CHART_POINTS.map(p => Number(p.close));
    const minVal = Math.min(...closes);
    const maxVal = Math.max(...closes);
    const span   = (maxVal - minVal) || 1;

    // Gleitende Durchschnitte für die Chart-Kurve berechnen
    const calcSmaSeries = (period) => {
        return closes.map((_, idx) => {
            if (idx + 1 < period) return null;
            let sum = 0;
            for (let k = idx - period + 1; k <= idx; k++) sum += closes[k];
            return sum / period;
        });
    };
    const sma20Series = calcSmaSeries(20);
    const sma50Series = calcSmaSeries(50);

    const draw = (hoverIndex = null) => {
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width  = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.scale(dpr, dpr);

        const w = rect.width;
        const h = rect.height;
        const padLeft = 10;
        const padRight = 62;
        const padTop = 14;
        const padBottom = 24;
        const plotW = w - padLeft - padRight;
        const plotH = h - padTop - padBottom;

        ctx.clearRect(0, 0, w, h);

        // Horizontale Hilfslinien + Preisachse rechts
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.16)';
        ctx.fillStyle   = 'rgba(148, 163, 184, 0.75)';
        ctx.font        = '10px monospace';
        ctx.lineWidth   = 1;

        for (let g = 0; g <= 4; g++) {
            const y = padTop + (plotH * (g / 4));
            const val = maxVal - (span * (g / 4));
            ctx.beginPath();
            ctx.moveTo(padLeft, y);
            ctx.lineTo(padLeft + plotW, y);
            ctx.stroke();
            ctx.fillText(val.toFixed(2), padLeft + plotW + 6, y + 3);
        }

        const getX = (i) => padLeft + (i / (CHART_POINTS.length - 1)) * plotW;
        const getY = (v) => padTop + ((maxVal - v) / span) * plotH;

        // Farbverlauf unter der Hauptlinie
        const mainColor = IS_POSITIVE_TF ? '#10b981' : '#f43f5e';
        const grad = ctx.createLinearGradient(0, padTop, 0, padTop + plotH);
        grad.addColorStop(0, IS_POSITIVE_TF ? 'rgba(16, 185, 129, 0.28)' : 'rgba(244, 63, 94, 0.28)');
        grad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        ctx.beginPath();
        CHART_POINTS.forEach((p, i) => {
            const x = getX(i);
            const y = getY(p.close);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.lineTo(getX(CHART_POINTS.length - 1), padTop + plotH);
        ctx.lineTo(getX(0), padTop + plotH);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Haupt-Kurslinie
        ctx.beginPath();
        CHART_POINTS.forEach((p, i) => {
            const x = getX(i);
            const y = getY(p.close);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = mainColor;
        ctx.lineWidth   = 2.2;
        ctx.stroke();

        // SMA-20 Linie (Gelb)
        const drawSma = (series, color) => {
            ctx.beginPath();
            let started = false;
            series.forEach((v, i) => {
                if (v === null) return;
                const x = getX(i);
                const y = getY(v);
                if (!started) {
                    ctx.moveTo(x, y);
                    started = true;
                } else {
                    ctx.lineTo(x, y);
                }
            });
            ctx.strokeStyle = color;
            ctx.lineWidth   = 1.3;
            ctx.stroke();
        };
        drawSma(sma20Series, 'rgba(251, 191, 36, 0.85)');
        drawSma(sma50Series, 'rgba(129, 140, 248, 0.85)');

        // Crosshair bei Maus-Hover
        if (hoverIndex !== null && CHART_POINTS[hoverIndex]) {
            const hp = CHART_POINTS[hoverIndex];
            const hx = getX(hoverIndex);
            const hy = getY(hp.close);

            ctx.beginPath();
            ctx.setLineDash([4, 4]);
            ctx.moveTo(hx, padTop);
            ctx.lineTo(hx, padTop + plotH);
            ctx.strokeStyle = 'rgba(148, 163, 184, 0.6)';
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.setLineDash([]);

            ctx.beginPath();
            ctx.arc(hx, hy, 4.5, 0, Math.PI * 2);
            ctx.fillStyle = mainColor;
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.5;
            ctx.stroke();

            if (infoEl) {
                infoEl.innerHTML = `
                    <span>📅 <strong>${hp.date} (${hp.time})</strong> • Kurs: <strong>${Number(hp.close).toFixed(2)} ${CHART_CURRENCY}</strong> (Tief: ${Number(hp.low).toFixed(2)} / Hoch: ${Number(hp.high).toFixed(2)})</span>
                    <span class="text-[11px]">Volumen: ${Number(hp.volume || 0).toLocaleString('de-DE')}</span>
                `;
            }
        }
    };

    draw(null);
    window.addEventListener('resize', () => draw(null));

    const handleMove = (clientX) => {
        const rect = canvas.getBoundingClientRect();
        const padLeft = 10;
        const padRight = 62;
        const plotW = rect.width - padLeft - padRight;
        const relX = Math.max(0, Math.min(plotW, clientX - rect.left - padLeft));
        const idx  = Math.round((relX / plotW) * (CHART_POINTS.length - 1));
        draw(idx);
    };

    canvas.addEventListener('mousemove', (e) => handleMove(e.clientX));
    canvas.addEventListener('touchmove', (e) => {
        if (e.touches && e.touches[0]) handleMove(e.touches[0].clientX);
    }, { passive: true });
    canvas.addEventListener('mouseleave', () => draw(null));
})();

// 2. Live-Wertpapiersuche
let searchTimer = null;
function searchMarketSymbols(query) {
    const box = document.getElementById('marketSearchResults');
    if (!box) return;
    const q = (query || '').trim();
    if (q.length < 2) {
        box.classList.add('hidden');
        box.innerHTML = '';
        return;
    }

    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        box.classList.remove('hidden');
        box.innerHTML = '<div class="p-3 text-xs text-slate-400 text-center">🔍 Suche an den Weltbörsen…</div>';
        try {
            const res = await fetch('markets.php?ajax=search&q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data || !Array.isArray(data.quotes) || data.quotes.length === 0) {
                box.innerHTML = '<div class="p-3 text-xs text-slate-400 text-center">Keine passenden Wertpapiere gefunden.</div>';
                return;
            }
            box.innerHTML = data.quotes.map(item => `
                <button type="button"
                        onclick="quickAddMarketSymbol(${JSON.stringify(item.symbol).replace(/"/g, '&quot;')}, ${JSON.stringify(item.name).replace(/"/g, '&quot;')}, ${JSON.stringify(item.category).replace(/"/g, '&quot;')})"
                        class="w-full p-3 text-left hover:bg-emerald-50 dark:hover:bg-slate-800 flex items-center justify-between gap-2 transition-colors cursor-pointer">
                    <div class="min-w-0">
                        <div class="text-xs font-extrabold text-slate-900 dark:text-white truncate">${item.name}</div>
                        <div class="text-[11px] text-slate-500 font-mono">${item.symbol} • ${item.exchange} (${item.type})</div>
                    </div>
                    <span class="px-2 py-1 rounded-lg bg-emerald-600 text-white text-[10px] font-bold shrink-0">+ Watchlist</span>
                </button>
            `).join('');
        } catch (e) {
            box.innerHTML = '<div class="p-3 text-xs text-rose-500 text-center">Fehler bei der Suche.</div>';
        }
    }, 260);
}

function quickAddMarketSymbol(sym, name, cat) {
    document.getElementById('quickAddSymInput').value = sym;
    document.getElementById('quickAddNameInput').value = name;
    document.getElementById('quickAddCatInput').value = cat;
    document.getElementById('quickAddSymbolForm').submit();
}

document.addEventListener('click', (e) => {
    const box = document.getElementById('marketSearchResults');
    const inp = document.getElementById('marketSearchInput');
    if (box && inp && !box.contains(e.target) && e.target !== inp) {
        box.classList.add('hidden');
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
