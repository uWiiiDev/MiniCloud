<?php
declare(strict_types=1);

/**
 * ============================================================================
 * ⚽ BUNDESLIGA-ZENTRALE & CLOUD-TIPPSPIEL (bundesliga.php)
 * ============================================================================
 * Live-Tabellen, Spieltage, Torschützen, Lieblingsverein-Spotlight &
 * integriertes Cloud-Tippspiel für 1. Bundesliga, 2. Bundesliga und 3. Liga.
 * Datenquelle: OpenLigaDB (https://api.openligadb.de) mit schnellem,
 * DSGVO-konformem Server-Side-Caching in MariaDB/MySQL.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_bundesliga'] ?? '1') !== '1') {
    setFlash('error', 'Das Modul „Bundesliga & Tippspiel“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// ============================================================================
// 1. DATENBANK-TABELLEN INITIALISIEREN (Auto-Migration)
// ============================================================================
function ensureBundesligaTables(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bundesliga_cache (
            cache_key VARCHAR(140) NOT NULL PRIMARY KEY,
            json_data MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bundesliga_user_prefs (
            user_id INT NOT NULL PRIMARY KEY,
            fav_team_id INT NOT NULL DEFAULT 0,
            fav_team_name VARCHAR(140) NOT NULL DEFAULT '',
            fav_team_icon VARCHAR(255) NOT NULL DEFAULT '',
            fav_league VARCHAR(16) NOT NULL DEFAULT 'bl1',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bundesliga_user_favs (
            user_id INT NOT NULL,
            league VARCHAR(16) NOT NULL,
            fav_team_id INT NOT NULL DEFAULT 0,
            fav_team_name VARCHAR(140) NOT NULL DEFAULT '',
            fav_team_icon VARCHAR(255) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, league)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Einmalige Übernahme bestehender Lieblingsvereine aus bundesliga_user_prefs nach bundesliga_user_favs
    $pdo->exec("
        INSERT IGNORE INTO bundesliga_user_favs (user_id, league, fav_team_id, fav_team_name, fav_team_icon, updated_at)
        SELECT user_id, fav_league, fav_team_id, fav_team_name, fav_team_icon, updated_at
        FROM bundesliga_user_prefs
        WHERE fav_team_id > 0
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bundesliga_bets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            league VARCHAR(16) NOT NULL,
            season INT NOT NULL,
            matchday INT NOT NULL,
            match_id INT NOT NULL,
            goals_team1 INT NOT NULL,
            goals_team2 INT NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_match (user_id, match_id),
            INDEX idx_league_season (league, season),
            INDEX idx_league_season_md (league, season, matchday)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureBundesligaTables($pdo);

// ============================================================================
// 2. LIGA-KONFIGURATION & HILFSFUNKTIONEN
// ============================================================================
$leagues = [
    'bl1' => [
        'name'         => '1. Bundesliga',
        'short'        => '1. BL',
        'icon'         => '🏆',
        'max_matchday' => 34,
        'teams_count'  => 18,
        'zones'        => [
            1  => ['label' => 'Champions League',            'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            2  => ['label' => 'Champions League',            'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            3  => ['label' => 'Champions League',            'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            4  => ['label' => 'Champions League',            'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            5  => ['label' => 'Europa League',               'color' => 'bg-sky-500',     'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            6  => ['label' => 'Conference League Quali.',    'color' => 'bg-indigo-500',  'row_bg' => 'bg-indigo-50/40 dark:bg-indigo-950/20',   'badge' => 'text-indigo-700 dark:text-indigo-300 bg-indigo-100 dark:bg-indigo-900/50'],
            16 => ['label' => 'Relegation',                  'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
            17 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
            18 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
        ],
        'legend' => [
            ['color' => 'bg-emerald-500', 'label' => 'Platz 1–4: UEFA Champions League'],
            ['color' => 'bg-sky-500',     'label' => 'Platz 5: UEFA Europa League'],
            ['color' => 'bg-indigo-500',  'label' => 'Platz 6: UEFA Conference League'],
            ['color' => 'bg-amber-500',   'label' => 'Platz 16: Relegation'],
            ['color' => 'bg-rose-500',    'label' => 'Platz 17–18: Abstieg'],
        ],
    ],
    'bl2' => [
        'name'         => '2. Bundesliga',
        'short'        => '2. BL',
        'icon'         => '🥈',
        'max_matchday' => 34,
        'teams_count'  => 18,
        'zones'        => [
            1  => ['label' => 'Direkter Aufstieg',           'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            2  => ['label' => 'Direkter Aufstieg',           'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            3  => ['label' => 'Aufstiegs-Relegation',        'color' => 'bg-teal-500',    'row_bg' => 'bg-teal-50/40 dark:bg-teal-950/20',       'badge' => 'text-teal-700 dark:text-teal-300 bg-teal-100 dark:bg-teal-900/50'],
            16 => ['label' => 'Abstiegs-Relegation',         'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
            17 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
            18 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
        ],
        'legend' => [
            ['color' => 'bg-emerald-500', 'label' => 'Platz 1–2: Aufstieg in die 1. Bundesliga'],
            ['color' => 'bg-teal-500',    'label' => 'Platz 3: Aufstiegs-Relegation'],
            ['color' => 'bg-amber-500',   'label' => 'Platz 16: Abstiegs-Relegation'],
            ['color' => 'bg-rose-500',    'label' => 'Platz 17–18: Abstieg'],
        ],
    ],
    'bl3' => [
        'name'         => '3. Liga',
        'short'        => '3. Liga',
        'icon'         => '🥉',
        'max_matchday' => 38,
        'teams_count'  => 20,
        'zones'        => [
            1  => ['label' => 'Direkter Aufstieg',           'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            2  => ['label' => 'Direkter Aufstieg',           'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            3  => ['label' => 'Aufstiegs-Relegation',        'color' => 'bg-teal-500',    'row_bg' => 'bg-teal-50/40 dark:bg-teal-950/20',       'badge' => 'text-teal-700 dark:text-teal-300 bg-teal-100 dark:bg-teal-900/50'],
            17 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
            18 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
            19 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
            20 => ['label' => 'Direkter Abstieg',            'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
        ],
        'legend' => [
            ['color' => 'bg-emerald-500', 'label' => 'Platz 1–2: Aufstieg in die 2. Bundesliga'],
            ['color' => 'bg-teal-500',    'label' => 'Platz 3: Aufstiegs-Relegation'],
            ['color' => 'bg-rose-500',    'label' => 'Platz 17–20: Abstieg'],
        ],
    ],
    'del' => [
        'name'         => 'PENNY DEL (Eishockey)',
        'short'        => 'DEL',
        'icon'         => '🏒',
        'max_matchday' => 52,
        'teams_count'  => 14,
        'zones'        => [
            1  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            2  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            3  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            4  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            5  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            6  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            7  => ['label' => '1. Playoff-Runde (Pre-Playoffs)', 'color' => 'bg-sky-500', 'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            8  => ['label' => '1. Playoff-Runde (Pre-Playoffs)', 'color' => 'bg-sky-500', 'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            9  => ['label' => '1. Playoff-Runde (Pre-Playoffs)', 'color' => 'bg-sky-500', 'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            10 => ['label' => '1. Playoff-Runde (Pre-Playoffs)', 'color' => 'bg-sky-500', 'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            14 => ['label' => 'Abstieg in die DEL2',         'color' => 'bg-rose-500',    'row_bg' => 'bg-rose-50/45 dark:bg-rose-950/20',       'badge' => 'text-rose-700 dark:text-rose-300 bg-rose-100 dark:bg-rose-900/50'],
        ],
        'legend' => [
            ['color' => 'bg-emerald-500', 'label' => 'Platz 1–6: Playoff-Viertelfinale'],
            ['color' => 'bg-sky-500',     'label' => 'Platz 7–10: 1. Playoff-Runde (Pre-Playoffs)'],
            ['color' => 'bg-rose-500',    'label' => 'Platz 14: Abstieg in die DEL2'],
        ],
    ],
    'del2' => [
        'name'         => 'DEL2 (2. Eishockey-BL)',
        'short'        => 'DEL2',
        'icon'         => '🥅',
        'max_matchday' => 52,
        'teams_count'  => 14,
        'zones'        => [
            1  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            2  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            3  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            4  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            5  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            6  => ['label' => 'Playoff-Viertelfinale',       'color' => 'bg-emerald-500', 'row_bg' => 'bg-emerald-50/50 dark:bg-emerald-950/20', 'badge' => 'text-emerald-700 dark:text-emerald-300 bg-emerald-100 dark:bg-emerald-900/50'],
            7  => ['label' => 'Pre-Playoffs',                'color' => 'bg-sky-500',     'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            8  => ['label' => 'Pre-Playoffs',                'color' => 'bg-sky-500',     'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            9  => ['label' => 'Pre-Playoffs',                'color' => 'bg-sky-500',     'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            10 => ['label' => 'Pre-Playoffs',                'color' => 'bg-sky-500',     'row_bg' => 'bg-sky-50/40 dark:bg-sky-950/20',         'badge' => 'text-sky-700 dark:text-sky-300 bg-sky-100 dark:bg-sky-900/50'],
            11 => ['label' => 'Playdowns',                   'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
            12 => ['label' => 'Playdowns',                   'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
            13 => ['label' => 'Playdowns',                   'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
            14 => ['label' => 'Playdowns',                   'color' => 'bg-amber-500',   'row_bg' => 'bg-amber-50/40 dark:bg-amber-950/20',     'badge' => 'text-amber-700 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/50'],
        ],
        'legend' => [
            ['color' => 'bg-emerald-500', 'label' => 'Platz 1–6: Playoff-Viertelfinale'],
            ['color' => 'bg-sky-500',     'label' => 'Platz 7–10: Pre-Playoffs'],
            ['color' => 'bg-amber-500',   'label' => 'Platz 11–14: Playdowns (Klassenerhalt)'],
        ],
    ],
];

/**
 * Holt JSON-Daten von OpenLigaDB mit MariaDB-Cache.
 * Gibt ['data' => array, 'cached_at' => string, 'from_cache' => bool] zurück.
 */
function fetchOpenLigaData(PDO $pdo, string $cacheKey, string $endpoint, int $ttlSeconds = 300, bool $forceRefresh = false): array
{
    $stmt = $pdo->prepare("SELECT json_data, updated_at FROM bundesliga_cache WHERE cache_key = ?");
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cached && !$forceRefresh) {
        $age = time() - (int)strtotime((string)$cached['updated_at']);
        if ($age >= 0 && $age <= $ttlSeconds) {
            $decoded = json_decode((string)$cached['json_data'], true);
            if (is_array($decoded)) {
                return [
                    'data'       => $decoded,
                    'cached_at'  => (string)$cached['updated_at'],
                    'from_cache' => true,
                ];
            }
        }
    }

    $url = 'https://api.openligadb.de/' . ltrim($endpoint, '/');
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'MiniCloud-Bundesliga-Module/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($res) && $code >= 200 && $code < 300) {
            $raw = $res;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 6,
                'header'  => "User-Agent: MiniCloud-Bundesliga-Module/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') {
            $raw = $res;
        }
    }

    if ($raw !== null) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $now = date('Y-m-d H:i:s');
            $up  = $pdo->prepare("
                INSERT INTO bundesliga_cache (cache_key, json_data, updated_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = VALUES(updated_at)
            ");
            $up->execute([$cacheKey, $raw, $now]);
            return [
                'data'       => $decoded,
                'cached_at'  => $now,
                'from_cache' => false,
            ];
        }
    }

    // Fallback auf abgelaufenen Cache, falls API kurzzeitig nicht erreichbar
    if ($cached) {
        $decoded = json_decode((string)$cached['json_data'], true);
        if (is_array($decoded)) {
            return [
                'data'       => $decoded,
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
 * Normalisiert Vereinsnamen für den zuverlässigen Abgleich zwischen OpenLigaDB und dem Echtzeit-Scoreboard.
 */
function normalizeClubMatchToken(string $name): string
{
    $s = mb_strtolower($name, 'UTF-8');
    $s = strtr($s, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'munich' => 'muenchen', 'cologne' => 'koeln', 'nuremberg' => 'nuernberg',
    ]);
    $s = preg_replace('/\b(1\.|fc|sv|vfb|vfl|tsg|rb|sc|bv|borussia|bayer|04|05|07|98|1846|1899|eintracht|spvgg|fortuna|hertha|bsc|arminia|dynamo|hans|st\.)\b/u', ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Ergänzt Spieltags-Begegnungen aus OpenLigaDB automatisch um Echtzeit-Spielstände, Spielstatus (Beendet / Live-Minute)
 * und Torschützen über das Live-Scoreboard, falls OpenLigaDB-Moderatoren ein Ergebnis noch nicht als beendet eingetragen haben.
 */
function enrichMatchdayWithLiveScoreboard(PDO $pdo, string $league, array $matchdayData, bool $forceRefresh = false): array
{
    if (empty($matchdayData) || !in_array($league, ['bl1', 'bl2'], true)) {
        return $matchdayData;
    }

    $espnLeague = $league === 'bl1' ? 'ger.1' : 'ger.2';
    $datesNeeded = [];
    $now = time();

    foreach ($matchdayData as $m) {
        $ko = !empty($m['matchDateTime']) ? (int)strtotime((string)$m['matchDateTime']) : 0;
        if ($ko > 0 && $ko <= $now && empty($m['matchIsFinished'])) {
            $datesNeeded[date('Ymd', $ko)] = true;
        }
    }

    if (empty($datesNeeded)) {
        return $matchdayData;
    }

    $espnEvents = [];
    foreach (array_keys($datesNeeded) as $ymd) {
        $cacheKey = "espn_{$espnLeague}_{$ymd}";
        $stmt = $pdo->prepare("SELECT json_data, updated_at FROM bundesliga_cache WHERE cache_key = ?");
        $stmt->execute([$cacheKey]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);

        $raw = null;
        if ($cached && !$forceRefresh && ($now - (int)strtotime((string)$cached['updated_at'])) <= 120) {
            $raw = (string)$cached['json_data'];
        } else {
            $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnLeague}/scoreboard?dates={$ymd}";
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 4,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_USERAGENT      => 'MiniCloud-Bundesliga-Live/1.0',
                ]);
                $res  = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (is_string($res) && $code >= 200 && $code < 300) {
                    $raw = $res;
                }
            } else {
                $rawRes = @file_get_contents($url);
                if (is_string($rawRes) && $rawRes !== '') {
                    $raw = $rawRes;
                }
            }

            if ($raw !== null) {
                $up = $pdo->prepare("
                    INSERT INTO bundesliga_cache (cache_key, json_data, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = NOW()
                ");
                $up->execute([$cacheKey, $raw]);
            } elseif ($cached) {
                $raw = (string)$cached['json_data'];
            }
        }

        if ($raw !== null) {
            $dec = json_decode($raw, true);
            if (is_array($dec['events'] ?? null)) {
                foreach ($dec['events'] as $ev) {
                    $espnEvents[] = $ev;
                }
            }
        }
    }

    if (empty($espnEvents)) {
        return $matchdayData;
    }

    foreach ($matchdayData as &$m) {
        if (!empty($m['matchIsFinished'])) {
            continue;
        }
        $t1Token = normalizeClubMatchToken((string)($m['team1']['teamName'] ?? '') . ' ' . (string)($m['team1']['shortName'] ?? ''));
        $t2Token = normalizeClubMatchToken((string)($m['team2']['teamName'] ?? '') . ' ' . (string)($m['team2']['shortName'] ?? ''));
        $koTs    = !empty($m['matchDateTime']) ? (int)strtotime((string)$m['matchDateTime']) : 0;

        foreach ($espnEvents as $ev) {
            $comp = $ev['competitions'][0] ?? null;
            if (!is_array($comp) || empty($comp['competitors'])) {
                continue;
            }
            $homeComp = null;
            $awayComp = null;
            foreach ($comp['competitors'] as $c) {
                if (($c['homeAway'] ?? '') === 'home') {
                    $homeComp = $c;
                } elseif (($c['homeAway'] ?? '') === 'away') {
                    $awayComp = $c;
                }
            }
            if (!$homeComp || !$awayComp) {
                continue;
            }

            $eHomeTok = normalizeClubMatchToken((string)($homeComp['team']['displayName'] ?? '') . ' ' . (string)($homeComp['team']['shortDisplayName'] ?? ''));
            $eAwayTok = normalizeClubMatchToken((string)($awayComp['team']['displayName'] ?? '') . ' ' . (string)($awayComp['team']['shortDisplayName'] ?? ''));

            // Prüfen, ob Heim- oder Gastteam-Schlagwort übereinstimmt (und gleicher Tag)
            $homeWords = array_filter(explode(' ', $eHomeTok), static fn(string $w): bool => strlen($w) >= 3);
            $awayWords = array_filter(explode(' ', $eAwayTok), static fn(string $w): bool => strlen($w) >= 3);

            $matchHome = false;
            foreach ($homeWords as $hw) {
                if (str_contains($t1Token, $hw)) {
                    $matchHome = true;
                    break;
                }
            }
            $matchAway = false;
            foreach ($awayWords as $aw) {
                if (str_contains($t2Token, $aw)) {
                    $matchAway = true;
                    break;
                }
            }

            if (!$matchHome && !$matchAway) {
                continue;
            }
            // Falls nur eines durch Sondername gematcht hat, zumindest Anstoßzeit (±2h) prüfen
            if (!($matchHome && $matchAway)) {
                $evTs = !empty($ev['date']) ? (int)strtotime((string)$ev['date']) : 0;
                if ($koTs <= 0 || $evTs <= 0 || abs($koTs - $evTs) > 7200) {
                    continue;
                }
            }

            $state     = strtolower((string)($comp['status']['type']['state'] ?? ''));
            $completed = !empty($comp['status']['type']['completed']) || $state === 'post';
            $clockStr  = trim((string)($comp['status']['displayClock'] ?? ''));

            if ($state === 'in' || $completed) {
                $s1 = (int)($homeComp['score'] ?? 0);
                $s2 = (int)($awayComp['score'] ?? 0);

                $m['matchIsFinished'] = $completed;
                $m['_live_state']     = $completed ? 'post' : 'in';
                $m['_live_clock']     = $clockStr;

                $m['matchResults'] = [
                    [
                        'resultTypeID' => 2,
                        'pointsTeam1'  => $s1,
                        'pointsTeam2'  => $s2,
                    ],
                ];

                // Torschützen aus ESPN details übernehmen, falls OpenLigaDB noch keine Tore hat
                if (empty($m['goals']) && !empty($comp['details']) && is_array($comp['details'])) {
                    $homeEspnId = (string)($homeComp['team']['id'] ?? '');
                    $run1 = 0;
                    $run2 = 0;
                    $builtGoals = [];
                    foreach ($comp['details'] as $det) {
                        if (empty($det['scoringPlay'])) {
                            continue;
                        }
                        $detTeamId = (string)($det['team']['id'] ?? '');
                        if ($detTeamId === $homeEspnId) {
                            $run1++;
                        } else {
                            $run2++;
                        }
                        $minVal = (int)preg_replace('/\D+/', '', (string)($det['clock']['displayValue'] ?? '0'));
                        $scorer = (string)($det['athletesInvolved'][0]['displayName'] ?? 'Torschütze');
                        $builtGoals[] = [
                            'matchMinute'    => $minVal,
                            'goalGetterName' => $scorer,
                            'scoreTeam1'     => $run1,
                            'scoreTeam2'     => $run2,
                            'isPenalty'      => !empty($det['penaltyKick']),
                            'isOwnGoal'      => !empty($det['ownGoal']),
                        ];
                    }
                    if (!empty($builtGoals)) {
                        $m['goals'] = $builtGoals;
                    }
                }
            }
            break;
        }
    }
    unset($m);

    return $matchdayData;
}

/**
 * Extrahiert Endergebnis, Halbzeitergebnis (Fußball) bzw. Drittelergebnisse & Overtime/Penalty (Eishockey).
 */
function extractMatchScores(array $match): array
{
    $final1      = null;
    $final2      = null;
    $ht1         = null;
    $ht2         = null;
    $periods     = [];
    $extraSuffix = '';

    $results = $match['matchResults'] ?? [];
    if (is_array($results) && !empty($results)) {
        usort($results, static fn(array $a, array $b): int => ((int)($a['resultOrderID'] ?? 0)) <=> ((int)($b['resultOrderID'] ?? 0)));

        foreach ($results as $r) {
            $typeId = (int)($r['resultTypeID'] ?? 0);
            $rName  = strtolower(trim((string)($r['resultName'] ?? '')));
            $rKind  = strtolower(trim((string)($r['resultTypeKind'] ?? '')));
            $p1     = (int)($r['pointsTeam1'] ?? 0);
            $p2     = (int)($r['pointsTeam2'] ?? 0);

            if (str_contains($rName, 'drittel')) {
                $periods[] = "{$p1}:{$p2}";
                $final1 = $p1;
                $final2 = $p2;
            } elseif (str_contains($rName, 'overtime') || str_contains($rName, 'verlängerung') || $rKind === 'afterextratime' || $typeId === 4) {
                $final1      = $p1;
                $final2      = $p2;
                $extraSuffix = 'n.V.';
            } elseif (str_contains($rName, 'penalty') || str_contains($rName, 'shootout') || $typeId === 5) {
                $final1      = $p1;
                $final2      = $p2;
                $extraSuffix = 'n.P.';
            } elseif ($typeId === 2) {
                $final1 = $p1;
                $final2 = $p2;
            } elseif ($typeId === 1) {
                $ht1 = $p1;
                $ht2 = $p2;
            }
        }

        // Fallback: höchstes Resultat in der Reihenfolge als aktuellen/endgültigen Stand verwenden
        $last = end($results);
        if ($last && ($final1 === null || (int)($last['resultOrderID'] ?? 0) > 2)) {
            $final1 = (int)($last['pointsTeam1'] ?? 0);
            $final2 = (int)($last['pointsTeam2'] ?? 0);
        }
    }

    // Falls Spiel läuft und Tore in goals vorhanden sind, aber noch kein matchResult
    if ($final1 === null && !empty($match['goals']) && is_array($match['goals'])) {
        $lastGoal = end($match['goals']);
        if (isset($lastGoal['scoreTeam1'], $lastGoal['scoreTeam2'])) {
            $final1 = (int)$lastGoal['scoreTeam1'];
            $final2 = (int)$lastGoal['scoreTeam2'];
        }
    }

    $kickoffTs  = !empty($match['matchDateTime']) ? (int)strtotime((string)$match['matchDateTime']) : 0;
    $now        = time();
    $isStarted  = $kickoffTs > 0 && $now >= $kickoffTs;
    $isFinished = !empty($match['matchIsFinished']);

    // Sicherheits-Fallback: Wenn ein Spiel vor mehr als 150 Minuten (2,5 Std.) angepfiffen wurde
    // und nicht explizit als laufend (_live_state === 'in') gemeldet ist, gilt es als beendet statt endlos "LIVE".
    $liveState = (string)($match['_live_state'] ?? '');
    if ($liveState === 'post') {
        $isFinished = true;
    } elseif ($liveState !== 'in' && $isStarted && !$isFinished && ($now - $kickoffTs) > 9000) {
        $isFinished = true;
    }

    $isLive = $isStarted && !$isFinished;

    return [
        'has_score'    => ($final1 !== null && $final2 !== null && ($isStarted || $isFinished)),
        'score1'       => $final1,
        'score2'       => $final2,
        'ht1'          => $ht1,
        'ht2'          => $ht2,
        'periods_str'  => !empty($periods) ? implode(' • ', $periods) : '',
        'extra_suffix' => $extraSuffix,
        'is_finished'  => $isFinished,
        'is_started'   => $isStarted,
        'is_live'      => $isLive,
        'live_clock'   => (string)($match['_live_clock'] ?? ''),
        'kickoff_ts'   => $kickoffTs,
    ];
}

/**
 * Berechnet Tippspiel-Punkte für einen Tipp im Vergleich zum echten Spielstand:
 * - 4 Punkte: Exaktes Ergebnis
 * - 3 Punkte: Richtige Tordifferenz (inkl. richtiges Unentschieden mit anderer Toranzahl)
 * - 2 Punkte: Richtige Tendenz (Heim- oder Auswärtssieg)
 * - 0 Punkte: Falsch getippt
 */
function calculateBetPoints(int $bet1, int $bet2, int $real1, int $real2): array
{
    if ($bet1 === $real1 && $bet2 === $real2) {
        return ['points' => 4, 'type' => 'exact', 'label' => 'Volltreffer (4 Pkt.)'];
    }
    $betDiff  = $bet1 - $bet2;
    $realDiff = $real1 - $real2;
    if ($betDiff === $realDiff) {
        return ['points' => 3, 'type' => 'diff', 'label' => 'Tordifferenz (3 Pkt.)'];
    }
    if (($betDiff > 0 && $realDiff > 0) || ($betDiff < 0 && $realDiff < 0)) {
        return ['points' => 2, 'type' => 'tendency', 'label' => 'Tendenz (2 Pkt.)'];
    }
    return ['points' => 0, 'type' => 'miss', 'label' => '0 Pkt.'];
}

// ============================================================================
// 3. BENUTZER-EINSTELLUNGEN (Lieblingsverein je Liga) LADEN
// ============================================================================
$favsByLeague = [];
$favStmt = $pdo->prepare("SELECT * FROM bundesliga_user_favs WHERE user_id = ? AND fav_team_id > 0");
$favStmt->execute([$userId]);
foreach ($favStmt->fetchAll(PDO::FETCH_ASSOC) as $fRow) {
    $favsByLeague[(string)$fRow['league']] = [
        'fav_team_id'   => (int)$fRow['fav_team_id'],
        'fav_team_name' => (string)$fRow['fav_team_name'],
        'fav_team_icon' => (string)$fRow['fav_team_icon'],
        'fav_league'    => (string)$fRow['league'],
    ];
}

// ============================================================================
// 4. AKTUELLE PARAMETER & POST-AKTIONEN VERARBEITEN
// ============================================================================
$defaultLeague = !empty($favsByLeague) ? (string)array_key_first($favsByLeague) : 'bl1';
$selectedLeague = strtolower(trim((string)($_GET['league'] ?? $_POST['league'] ?? $defaultLeague)));
if (!isset($leagues[$selectedLeague])) {
    $selectedLeague = 'bl1';
}
$leagueMeta   = $leagues[$selectedLeague];
$userPrefs    = $favsByLeague[$selectedLeague] ?? [
    'fav_team_id'   => 0,
    'fav_team_name' => '',
    'fav_team_icon' => '',
    'fav_league'    => $selectedLeague,
];
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// Aktuellen Spieltag & aktuelle Saison von OpenLigaDB ermitteln
$currentMatchdayRes  = fetchOpenLigaData($pdo, "current_{$selectedLeague}", "getmatchdata/{$selectedLeague}", 300, $forceRefresh);
$currentMatchdayData = $currentMatchdayRes['data'];

$detectedSeason   = (int)date('Y');
$detectedMatchday = 1;
if (!empty($currentMatchdayData) && is_array($currentMatchdayData)) {
    $firstMatch = $currentMatchdayData[0] ?? [];
    if (!empty($firstMatch['leagueSeason'])) {
        $detectedSeason = (int)$firstMatch['leagueSeason'];
    }
    if (!empty($firstMatch['group']['groupOrderID'])) {
        $detectedMatchday = (int)$firstMatch['group']['groupOrderID'];
    }
}

$selectedSeason = isset($_GET['season']) ? (int)$_GET['season'] : (isset($_POST['season']) ? (int)$_POST['season'] : $detectedSeason);
if ($selectedSeason < 2010 || $selectedSeason > $detectedSeason + 1) {
    $selectedSeason = $detectedSeason;
}

$selectedMatchday = isset($_GET['matchday']) ? (int)$_GET['matchday'] : (isset($_POST['matchday']) ? (int)$_POST['matchday'] : ($selectedSeason === $detectedSeason ? $detectedMatchday : 1));
if ($selectedMatchday < 1 || $selectedMatchday > $leagueMeta['max_matchday']) {
    $selectedMatchday = 1;
}

// POST-Aktionen (Lieblingsverein speichern / Tippspiel-Tipps speichern)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuche es erneut.');
        header("Location: bundesliga.php?league={$selectedLeague}&season={$selectedSeason}&matchday={$selectedMatchday}");
        exit;
    }

    $postAction = (string)($_POST['action'] ?? '');

    // A) Lieblingsverein für die jeweilige Liga festlegen oder entfernen
    if ($postAction === 'save_fav_team') {
        $favTeamId   = (int)($_POST['fav_team_id'] ?? 0);
        $favTeamName = mb_substr(trim((string)($_POST['fav_team_name'] ?? '')), 0, 140);
        $favTeamIcon = mb_substr(trim((string)($_POST['fav_team_icon'] ?? '')), 0, 255);
        $favLeague   = isset($leagues[$_POST['fav_league'] ?? '']) ? (string)$_POST['fav_league'] : $selectedLeague;
        $leagueLabel = $leagues[$favLeague]['name'] ?? $favLeague;

        if ($favTeamId > 0) {
            $upPref = $pdo->prepare("
                INSERT INTO bundesliga_user_favs (user_id, league, fav_team_id, fav_team_name, fav_team_icon, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    fav_team_id   = VALUES(fav_team_id),
                    fav_team_name = VALUES(fav_team_name),
                    fav_team_icon = VALUES(fav_team_icon),
                    updated_at    = NOW()
            ");
            $upPref->execute([$userId, $favLeague, $favTeamId, $favTeamName, $favTeamIcon]);
            setFlash('success', "⭐ „{$favTeamName}“ wurde als dein Lieblingsverein für die {$leagueLabel} gespeichert!");
        } else {
            $delPref = $pdo->prepare("DELETE FROM bundesliga_user_favs WHERE user_id = ? AND league = ?");
            $delPref->execute([$userId, $favLeague]);
            setFlash('success', "Lieblingsverein für die {$leagueLabel} wurde zurückgesetzt.");
        }
        header("Location: bundesliga.php?league={$selectedLeague}&season={$selectedSeason}&matchday={$selectedMatchday}");
        exit;
    }

    // B) Tippspiel-Tipps für den gewählten Spieltag speichern
    if ($postAction === 'save_bets') {
        $betsInput = $_POST['bets'] ?? [];
        if (is_array($betsInput)) {
            // Spiele des Spieltags laden, um Anstoßzeiten zu prüfen (Tippabgabe nur vor Anpfiff!)
            $mdCheckRes = fetchOpenLigaData(
                $pdo,
                "md_{$selectedLeague}_{$selectedSeason}_{$selectedMatchday}",
                "getmatchdata/{$selectedLeague}/{$selectedSeason}/{$selectedMatchday}",
                300,
                false
            );
            $validMatchesById = [];
            foreach ($mdCheckRes['data'] as $m) {
                $mId = (int)($m['matchID'] ?? 0);
                if ($mId > 0) {
                    $validMatchesById[$mId] = $m;
                }
            }

            $savedCount  = 0;
            $lockedCount = 0;
            $insBet = $pdo->prepare("
                INSERT INTO bundesliga_bets (user_id, league, season, matchday, match_id, goals_team1, goals_team2, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    goals_team1 = VALUES(goals_team1),
                    goals_team2 = VALUES(goals_team2),
                    updated_at  = NOW()
            ");
            $delBet = $pdo->prepare("DELETE FROM bundesliga_bets WHERE user_id = ? AND match_id = ?");

            foreach ($betsInput as $matchIdRaw => $scores) {
                $matchId = (int)$matchIdRaw;
                if (!isset($validMatchesById[$matchId]) || !is_array($scores)) {
                    continue;
                }
                $matchObj  = $validMatchesById[$matchId];
                $scoreInfo = extractMatchScores($matchObj);

                // Gesperrt, sobald Spiel angepfiffen oder beendet
                if ($scoreInfo['is_started'] || $scoreInfo['is_finished']) {
                    $lockedCount++;
                    continue;
                }

                $g1Raw = trim((string)($scores['g1'] ?? ''));
                $g2Raw = trim((string)($scores['g2'] ?? ''));

                if ($g1Raw === '' || $g2Raw === '') {
                    // Leeres Feld löscht einen evtl. zuvor gespeicherten offenen Tipp
                    $delBet->execute([$userId, $matchId]);
                    continue;
                }

                $g1 = max(0, min(25, (int)$g1Raw));
                $g2 = max(0, min(25, (int)$g2Raw));

                $insBet->execute([$userId, $selectedLeague, $selectedSeason, $selectedMatchday, $matchId, $g1, $g2]);
                $savedCount++;
            }

            if ($savedCount > 0) {
                setFlash('success', "🎯 {$savedCount} Tipp(s) für den {$selectedMatchday}. Spieltag gespeichert!");
            } elseif ($lockedCount > 0) {
                setFlash('error', 'Spiele wurden bereits angepfiffen – Tipps können nur vor Spielbeginn abgegeben werden.');
            } else {
                setFlash('success', 'Tipps aktualisiert.');
            }
        }
        header("Location: bundesliga.php?league={$selectedLeague}&season={$selectedSeason}&matchday={$selectedMatchday}#tippspiel");
        exit;
    }
}

// ============================================================================
// 5. TABELLE & SPIELTAG-DATEN LADEN
// ============================================================================
$tableRes  = fetchOpenLigaData(
    $pdo,
    "table_{$selectedLeague}_{$selectedSeason}",
    "getbltable/{$selectedLeague}/{$selectedSeason}",
    600,
    $forceRefresh
);
$tableData = $tableRes['data'];

// Falls gewählter Spieltag = aktueller Spieltag der aktuellen Saison, können wir direkt $currentMatchdayRes nutzen
if ($selectedSeason === $detectedSeason && $selectedMatchday === $detectedMatchday && !empty($currentMatchdayData)) {
    $matchdayRes  = $currentMatchdayRes;
    $matchdayData = $currentMatchdayData;
} else {
    $matchdayRes  = fetchOpenLigaData(
        $pdo,
        "md_{$selectedLeague}_{$selectedSeason}_{$selectedMatchday}",
        "getmatchdata/{$selectedLeague}/{$selectedSeason}/{$selectedMatchday}",
        300,
        $forceRefresh
    );
    $matchdayData = $matchdayRes['data'];
}

$matchdayData   = enrichMatchdayWithLiveScoreboard($pdo, $selectedLeague, $matchdayData, $forceRefresh);
$lastUpdatedStr = $matchdayRes['cached_at'] ?: $tableRes['cached_at'];

// ============================================================================
// 6. LIEBLINGSVEREIN-SPOTLIGHT AUFBEREITEN
// ============================================================================
$favTeamId      = (int)($userPrefs['fav_team_id'] ?? 0);
$favStanding    = null;
$favMatch       = null;

if ($favTeamId > 0) {
    foreach ($tableData as $idx => $row) {
        if ((int)($row['teamInfoId'] ?? 0) === $favTeamId) {
            $favStanding         = $row;
            $favStanding['rank'] = $idx + 1;
            break;
        }
    }
    foreach ($matchdayData as $m) {
        $t1Id = (int)($m['team1']['teamId'] ?? 0);
        $t2Id = (int)($m['team2']['teamId'] ?? 0);
        if ($t1Id === $favTeamId || $t2Id === $favTeamId) {
            $favMatch = $m;
            break;
        }
    }
}

// ============================================================================
// 7. TIPPSPIEL-DATEN & RANGLISTE BERECHNEN
// ============================================================================
// Eigene Tipps für den angezeigten Spieltag
$myBetsStmt = $pdo->prepare("
    SELECT match_id, goals_team1, goals_team2
    FROM bundesliga_bets
    WHERE user_id = ? AND league = ? AND season = ? AND matchday = ?
");
$myBetsStmt->execute([$userId, $selectedLeague, $selectedSeason, $selectedMatchday]);
$myMatchdayBets = [];
foreach ($myBetsStmt->fetchAll(PDO::FETCH_ASSOC) as $bRow) {
    $myMatchdayBets[(int)$bRow['match_id']] = [
        'g1' => (int)$bRow['goals_team1'],
        'g2' => (int)$bRow['goals_team2'],
    ];
}

// Alle Tipps aller Cloud-User für diese Liga & Saison laden (für Bestenliste + Anzeige bei gestarteten Spielen)
$allBetsStmt = $pdo->prepare("
    SELECT b.*, u.username
    FROM bundesliga_bets b
    JOIN users u ON u.id = b.user_id
    WHERE b.league = ? AND b.season = ?
    ORDER BY b.updated_at ASC
");
$allBetsStmt->execute([$selectedLeague, $selectedSeason]);
$allSeasonBets = $allBetsStmt->fetchAll(PDO::FETCH_ASSOC);

// Für die Auswertung aller bisherigen Spieltage holen wir bekannte Ergebnisse aus den gecachten Spieltagen
// sowie aus dem aktuell geöffneten Spieltag
$realScoresByMatchId = [];
foreach ($matchdayData as $m) {
    $mId = (int)($m['matchID'] ?? 0);
    $sc  = extractMatchScores($m);
    if ($mId > 0 && $sc['has_score']) {
        $realScoresByMatchId[$mId] = $sc;
    }
}

// Zusätzlich alle bereits gecachten Spieltage dieser Saison für die Gesamtwertung heranziehen
$cacheMdStmt = $pdo->prepare("SELECT json_data FROM bundesliga_cache WHERE cache_key LIKE ?");
$cacheMdStmt->execute(["md_{$selectedLeague}_{$selectedSeason}_%"]);
foreach ($cacheMdStmt->fetchAll(PDO::FETCH_COLUMN) as $rawMdJson) {
    $mdArr = json_decode((string)$rawMdJson, true);
    if (is_array($mdArr)) {
        foreach ($mdArr as $m) {
            $mId = (int)($m['matchID'] ?? 0);
            if ($mId > 0 && !isset($realScoresByMatchId[$mId])) {
                $sc = extractMatchScores($m);
                if ($sc['has_score']) {
                    $realScoresByMatchId[$mId] = $sc;
                }
            }
        }
    }
}

$leaderboard       = [];
$communityBetsByMd = []; // match_id => array of user bets

foreach ($allSeasonBets as $b) {
    $bUid     = (int)$b['user_id'];
    $bMid     = (int)$b['match_id'];
    $bMd      = (int)$b['matchday'];
    $bG1      = (int)$b['goals_team1'];
    $bG2      = (int)$b['goals_team2'];
    $bUname   = (string)$b['username'];

    if (!isset($leaderboard[$bUid])) {
        $leaderboard[$bUid] = [
            'user_id'   => $bUid,
            'username'  => $bUname,
            'points'    => 0,
            'exact'     => 0,
            'diff'      => 0,
            'tendency'  => 0,
            'evaluated' => 0,
            'total_bets'=> 0,
        ];
    }
    $leaderboard[$bUid]['total_bets']++;

    $eval = null;
    if (isset($realScoresByMatchId[$bMid])) {
        $real = $realScoresByMatchId[$bMid];
        $eval = calculateBetPoints($bG1, $bG2, (int)$real['score1'], (int)$real['score2']);
        $leaderboard[$bUid]['points']    += $eval['points'];
        $leaderboard[$bUid]['evaluated'] += 1;
        if ($eval['type'] === 'exact') {
            $leaderboard[$bUid]['exact']++;
        } elseif ($eval['type'] === 'diff') {
            $leaderboard[$bUid]['diff']++;
        } elseif ($eval['type'] === 'tendency') {
            $leaderboard[$bUid]['tendency']++;
        }
    }

    if ($bMd === $selectedMatchday) {
        $communityBetsByMd[$bMid][] = [
            'user_id'  => $bUid,
            'username' => $bUname,
            'g1'       => $bG1,
            'g2'       => $bG2,
            'eval'     => $eval,
        ];
    }
}

usort($leaderboard, static function (array $a, array $b): int {
    if ($b['points'] !== $a['points']) {
        return $b['points'] <=> $a['points'];
    }
    if ($b['exact'] !== $a['exact']) {
        return $b['exact'] <=> $a['exact'];
    }
    return strcasecmp($a['username'], $b['username']);
});

$germanWeekdays = [
    1 => 'Mo.', 2 => 'Di.', 3 => 'Mi.', 4 => 'Do.', 5 => 'Fr.', 6 => 'Sa.', 7 => 'So.',
];

$pageTitle = $leagueMeta['name'] . ' – Tabelle & Cloud-Tippspiel';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- KOPFBEREICH: Liga-Umschalter, Saison, Spieltag & Live-Refresh      -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-start sm:items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br <?= in_array($selectedLeague, ['del', 'del2'], true) ? 'from-sky-500 to-blue-700' : 'from-emerald-500 to-green-700' ?> text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    <?= in_array($selectedLeague, ['del', 'del2'], true) ? '🏒' : '⚽' ?>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            <?= e($leagueMeta['name']) ?> &amp; Cloud-Tippspiel
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                            Saison <?= (int)$selectedSeason ?>/<?= substr((string)($selectedSeason + 1), -2) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex flex-wrap items-center gap-2">
                        <span>📡 Datenquelle: OpenLigaDB (Server-Cache)</span>
                        <span>•</span>
                        <span>Stand: <?= date('d.m.Y H:i', (int)strtotime((string)$lastUpdatedStr)) ?> Uhr</span>
                        <a href="bundesliga.php?league=<?= urlencode($selectedLeague) ?>&amp;season=<?= (int)$selectedSeason ?>&amp;matchday=<?= (int)$selectedMatchday ?>&amp;refresh=1"
                           class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 hover:underline font-semibold">
                            🔄 Jetzt live aktualisieren
                        </a>
                    </p>
                </div>
            </div>

            <!-- Liga-Tabs + Saison-Wähler -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700 gap-1">
                    <?php foreach ($leagues as $lKey => $lCfg): ?>
                        <?php $isActiveLeague = ($lKey === $selectedLeague); ?>
                        <a href="bundesliga.php?league=<?= urlencode($lKey) ?>&amp;season=<?= (int)$selectedSeason ?>"
                           class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?= $isActiveLeague ? 'bg-white dark:bg-slate-900 text-emerald-700 dark:text-emerald-300 shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>">
                            <span><?= $lCfg['icon'] ?></span>
                            <span><?= e($lCfg['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="get" action="bundesliga.php" class="inline-flex items-center gap-1.5">
                    <input type="hidden" name="league" value="<?= e($selectedLeague) ?>">
                    <select name="season" onchange="this.form.submit()"
                            aria-label="Saison auswählen"
                            class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <?php for ($yr = $detectedSeason; $yr >= 2018; $yr--): ?>
                            <option value="<?= $yr ?>" <?= $yr === $selectedSeason ? 'selected' : '' ?>>
                                Saison <?= $yr ?>/<?= substr((string)($yr + 1), -2) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- ⭐ LIEBLINGSVEREIN-SPOTLIGHT (je Liga einzeln speicherbar!)        -->
    <!-- ================================================================== -->
    <?php if ($favTeamId > 0 || !empty($favsByLeague)): ?>
        <div class="bg-gradient-to-r from-amber-50 via-emerald-50/70 to-teal-50 dark:from-amber-950/30 dark:via-slate-900 dark:to-emerald-950/30 rounded-2xl border border-amber-300/70 dark:border-amber-700/60 p-4 sm:p-5 shadow-sm space-y-3">
            <?php if (count($favsByLeague) > 1 || ($favTeamId === 0 && !empty($favsByLeague))): ?>
                <!-- Schnellwahl-Leiste über alle Ligen mit gespeichertem Lieblingsverein -->
                <div class="flex flex-wrap items-center gap-1.5 pb-2 border-b border-amber-200/60 dark:border-amber-800/40">
                    <span class="text-[11px] font-bold text-amber-800 dark:text-amber-300 mr-1">⭐ Meine Vereine:</span>
                    <?php foreach ($favsByLeague as $fLgKey => $fLgData): ?>
                        <?php if (!isset($leagues[$fLgKey])) continue; ?>
                        <a href="bundesliga.php?league=<?= urlencode($fLgKey) ?>"
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold transition-all <?= $fLgKey === $selectedLeague ? 'bg-amber-500 text-white shadow-2xs' : 'bg-white/80 dark:bg-slate-800/80 text-slate-800 dark:text-slate-200 hover:bg-amber-100 dark:hover:bg-slate-700 border border-amber-300/50 dark:border-slate-700' ?>">
                            <?php if ($fLgData['fav_team_icon'] !== ''): ?>
                                <img src="<?= e($fLgData['fav_team_icon']) ?>" alt="" class="w-4 h-4 object-contain" loading="lazy" referrerpolicy="no-referrer">
                            <?php else: ?>
                                <span><?= $leagues[$fLgKey]['icon'] ?></span>
                            <?php endif; ?>
                            <span><?= e($fLgData['fav_team_name']) ?></span>
                            <span class="text-[10px] opacity-75">(<?= e($leagues[$fLgKey]['short']) ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($favTeamId > 0 && ($favStanding !== null || !empty($userPrefs['fav_team_name']))): ?>
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex items-center gap-3.5">
                        <?php
                        $favIcon = (string)($favStanding['teamIconUrl'] ?? $userPrefs['fav_team_icon'] ?? '');
                        $favName = (string)($favStanding['teamName'] ?? $userPrefs['fav_team_name'] ?? 'Mein Verein');
                        ?>
                        <?php if ($favIcon !== ''): ?>
                            <img src="<?= e($favIcon) ?>" alt="<?= e($favName) ?>" class="w-12 h-12 object-contain shrink-0 drop-shadow-sm" loading="lazy" referrerpolicy="no-referrer">
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center text-2xl">⭐</div>
                        <?php endif; ?>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-amber-200/80 dark:bg-amber-900/70 text-amber-900 dark:text-amber-200">
                                    ⭐ Dein Lieblingsverein (<?= e($leagueMeta['short']) ?>)
                                </span>
                                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                                    <?= e($favName) ?>
                                </h2>
                            </div>
                            <?php if ($favStanding !== null): ?>
                                <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                                    Aktuell <strong>Platz <?= (int)$favStanding['rank'] ?></strong> in der <?= e($leagueMeta['name']) ?>
                                    • <strong><?= (int)($favStanding['points'] ?? 0) ?> Punkte</strong>
                                    • Torverhältnis <strong><?= (int)($favStanding['goals'] ?? 0) ?>:<?= (int)($favStanding['opponentGoals'] ?? 0) ?></strong> (<?= ((int)($favStanding['goalDiff'] ?? 0) >= 0 ? '+' : '') . (int)($favStanding['goalDiff'] ?? 0) ?>)
                                    • Bilanz: <?= (int)($favStanding['won'] ?? 0) ?>S / <?= (int)($favStanding['draw'] ?? 0) ?>U / <?= (int)($favStanding['lost'] ?? 0) ?>N
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    In der gewählten Saison <?= (int)$selectedSeason ?>/<?= substr((string)($selectedSeason + 1), -2) ?> nicht in dieser Liga vertreten.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($favMatch !== null): ?>
                        <?php
                        $fmScores = extractMatchScores($favMatch);
                        $fmT1     = (string)($favMatch['team1']['shortName'] ?: $favMatch['team1']['teamName'] ?? '');
                        $fmT2     = (string)($favMatch['team2']['shortName'] ?: $favMatch['team2']['teamName'] ?? '');
                        ?>
                        <div class="px-3.5 py-2.5 rounded-xl bg-white/90 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700 flex items-center justify-between gap-4">
                            <div>
                                <div class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                    <?= (int)$selectedMatchday ?>. Spieltag <?= $fmScores['is_live'] ? '• 🔴 LIVE' : ($fmScores['is_finished'] ? '• Endstand' : '• Anstoß') ?>
                                </div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white mt-0.5">
                                    <?= e($fmT1) ?> <span class="text-slate-400 font-normal">vs.</span> <?= e($fmT2) ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <?php if ($fmScores['has_score']): ?>
                                    <span class="px-2.5 py-1 rounded-lg text-sm font-extrabold <?= $fmScores['is_live'] ? 'bg-rose-600 text-white animate-pulse' : 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' ?>">
                                        <?= (int)$fmScores['score1'] ?> : <?= (int)$fmScores['score2'] ?><?= $fmScores['extra_suffix'] !== '' ? ' ' . e($fmScores['extra_suffix']) : '' ?>
                                    </span>
                                <?php elseif ($fmScores['kickoff_ts'] > 0): ?>
                                    <span class="text-xs font-bold text-emerald-700 dark:text-emerald-300">
                                        <?= $germanWeekdays[(int)date('N', $fmScores['kickoff_ts'])] ?? '' ?> <?= date('d.m., H:i', $fmScores['kickoff_ts']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-xs text-slate-600 dark:text-slate-300">
                    💡 Für die <strong><?= e($leagueMeta['name']) ?></strong> hast du noch keinen Lieblingsverein ausgewählt – klicke unten in der Tabelle einfach auf den Stern <kbd class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-bold">☆</kbd> neben deinem Wunschverein!
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- HAUPTGRID: LINKS LIVE-TABELLE (7 Spalten), RECHTS SPIELTAG & TIPPS -->
    <!-- ================================================================== -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

        <!-- ============================================================== -->
        <!-- LINKE SPALTE: OFFIZIELLE LIVE-TABELLE                          -->
        <!-- ============================================================== -->
        <section class="xl:col-span-7 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="p-4 sm:p-5 border-b border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>📊 Aktuelle Tabelle</span>
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">(<?= e($leagueMeta['name']) ?>)</span>
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Klicke auf den Stern ⭐ neben einem Verein, um ihn als deinen Lieblingsverein anzupinnen.
                    </p>
                </div>
            </div>

            <?php if (empty($tableData)): ?>
                <div class="p-8 text-center space-y-2">
                    <div class="text-3xl">⚽</div>
                    <p class="text-sm font-bold text-slate-800 dark:text-slate-200">
                        Für diese Saison liegen aktuell noch keine Tabellendaten vor.
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Wähle oben eine andere Saison oder klicke auf „Jetzt live aktualisieren“.
                    </p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 bg-slate-50/70 dark:bg-slate-800/40">
                                <th class="py-2.5 pl-3 pr-1 w-10 text-center">#</th>
                                <th class="py-2.5 px-2">Verein</th>
                                <th class="py-2.5 px-1.5 text-center" title="Spiele">Sp</th>
                                <th class="py-2.5 px-1.5 text-center hidden sm:table-cell" title="Siege">S</th>
                                <th class="py-2.5 px-1.5 text-center hidden sm:table-cell" title="Unentschieden">U</th>
                                <th class="py-2.5 px-1.5 text-center hidden sm:table-cell" title="Niederlagen">N</th>
                                <th class="py-2.5 px-2 text-center hidden md:table-cell" title="Tore : Gegentore">Tore</th>
                                <th class="py-2.5 px-1.5 text-center" title="Tordifferenz">Diff</th>
                                <th class="py-2.5 pl-2 pr-4 text-right" title="Punkte">Pkt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800 text-xs sm:text-sm">
                            <?php foreach ($tableData as $idx => $team): ?>
                                <?php
                                $rank      = $idx + 1;
                                $teamId    = (int)($team['teamInfoId'] ?? 0);
                                $teamName  = (string)($team['teamName'] ?? '');
                                $shortName = (string)($team['shortName'] ?: $teamName);
                                $iconUrl   = (string)($team['teamIconUrl'] ?? '');
                                $matches   = (int)($team['matches'] ?? 0);
                                $won       = (int)($team['won'] ?? 0);
                                $draw      = (int)($team['draw'] ?? 0);
                                $lost      = (int)($team['lost'] ?? 0);
                                $goals     = (int)($team['goals'] ?? 0);
                                $oppGoals  = (int)($team['opponentGoals'] ?? 0);
                                $goalDiff  = (int)($team['goalDiff'] ?? ($goals - $oppGoals));
                                $points    = (int)($team['points'] ?? 0);

                                $zoneInfo  = $leagueMeta['zones'][$rank] ?? null;
                                $isFav     = ($favTeamId > 0 && $teamId === $favTeamId);

                                $rowBgClass = 'hover:bg-slate-50/90 dark:hover:bg-slate-800/50';
                                if ($isFav) {
                                    $rowBgClass = 'bg-amber-50/90 dark:bg-amber-950/35 ring-1 ring-inset ring-amber-400/60';
                                } elseif ($zoneInfo !== null) {
                                    $rowBgClass = $zoneInfo['row_bg'] . ' hover:bg-slate-100/70 dark:hover:bg-slate-800/60';
                                }
                                ?>
                                <tr class="transition-colors <?= $rowBgClass ?>">
                                    <!-- Platzierung + Zonen-Indikator -->
                                    <td class="py-2.5 pl-3 pr-1 text-center font-bold relative">
                                        <?php if ($zoneInfo !== null): ?>
                                            <span class="absolute left-0 top-0 bottom-0 w-1 <?= $zoneInfo['color'] ?>" title="<?= e($zoneInfo['label']) ?>"></span>
                                        <?php endif; ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-extrabold <?= $zoneInfo ? $zoneInfo['badge'] : 'text-slate-600 dark:text-slate-300' ?>">
                                            <?= $rank ?>
                                        </span>
                                    </td>

                                    <!-- Vereinswappen + Name + Lieblingsverein-Stern -->
                                    <td class="py-2.5 px-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5 min-w-0">
                                                <?php if ($iconUrl !== ''): ?>
                                                    <img src="<?= e($iconUrl) ?>" alt="" class="w-6 h-6 object-contain shrink-0" loading="lazy" referrerpolicy="no-referrer">
                                                <?php else: ?>
                                                    <span class="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-700 inline-flex items-center justify-center text-[10px] font-bold shrink-0">⚽</span>
                                                <?php endif; ?>
                                                <div class="truncate">
                                                    <span class="font-bold text-slate-900 dark:text-white hidden sm:inline"><?= e($teamName) ?></span>
                                                    <span class="font-bold text-slate-900 dark:text-white sm:hidden"><?= e($shortName) ?></span>
                                                    <?php if ($isFav): ?>
                                                        <span class="ml-1 text-[10px] font-extrabold text-amber-600 dark:text-amber-400 uppercase">Mein Verein</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Stern-Button für Lieblingsverein -->
                                            <form method="post" action="bundesliga.php?league=<?= urlencode($selectedLeague) ?>&amp;season=<?= (int)$selectedSeason ?>&amp;matchday=<?= (int)$selectedMatchday ?>" class="shrink-0">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="save_fav_team">
                                                <input type="hidden" name="fav_team_id" value="<?= $isFav ? 0 : $teamId ?>">
                                                <input type="hidden" name="fav_team_name" value="<?= e($teamName) ?>">
                                                <input type="hidden" name="fav_team_icon" value="<?= e($iconUrl) ?>">
                                                <input type="hidden" name="fav_league" value="<?= e($selectedLeague) ?>">
                                                <button type="submit"
                                                        title="<?= $isFav ? 'Als Lieblingsverein entfernen' : e($teamName) . ' als Lieblingsverein markieren' ?>"
                                                        class="p-1 rounded-lg transition-transform hover:scale-110 <?= $isFav ? 'text-amber-500' : 'text-slate-300 dark:text-slate-600 hover:text-amber-400' ?>">
                                                    <?= $isFav ? '⭐' : '☆' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>

                                    <td class="py-2.5 px-1.5 text-center font-medium text-slate-600 dark:text-slate-300"><?= $matches ?></td>
                                    <td class="py-2.5 px-1.5 text-center text-slate-500 dark:text-slate-400 hidden sm:table-cell"><?= $won ?></td>
                                    <td class="py-2.5 px-1.5 text-center text-slate-500 dark:text-slate-400 hidden sm:table-cell"><?= $draw ?></td>
                                    <td class="py-2.5 px-1.5 text-center text-slate-500 dark:text-slate-400 hidden sm:table-cell"><?= $lost ?></td>
                                    <td class="py-2.5 px-2 text-center text-slate-500 dark:text-slate-400 font-mono text-xs hidden md:table-cell"><?= $goals ?>:<?= $oppGoals ?></td>
                                    <td class="py-2.5 px-1.5 text-center font-mono text-xs font-semibold <?= $goalDiff > 0 ? 'text-emerald-600 dark:text-emerald-400' : ($goalDiff < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500') ?>">
                                        <?= ($goalDiff > 0 ? '+' : '') . $goalDiff ?>
                                    </td>
                                    <td class="py-2.5 pl-2 pr-4 text-right font-extrabold text-slate-900 dark:text-white text-sm">
                                        <?= $points ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Tabellen-Legende -->
                <div class="p-3.5 bg-slate-50/80 dark:bg-slate-800/40 border-t border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-slate-600 dark:text-slate-400">
                    <?php foreach ($leagueMeta['legend'] as $leg): ?>
                        <span class="inline-flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full <?= $leg['color'] ?>"></span>
                            <span><?= e($leg['label']) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================== -->
        <!-- RECHTE SPALTE: SPIELTAG, TORSCHÜTZEN & CLOUD-TIPPSPIEL         -->
        <!-- ============================================================== -->
        <section id="tippspiel" class="xl:col-span-5 space-y-6">

            <!-- Spieltags-Box + Tippabgabe -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-slate-200/80 dark:border-slate-800 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>🗓️ <?= (int)$selectedMatchday ?>. Spieltag</span>
                                <?php if ($selectedSeason === $detectedSeason && $selectedMatchday === $detectedMatchday): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Aktuell
                                    </span>
                                <?php endif; ?>
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Ergebnisse, Torschützen &amp; deine Tippspiel-Tipps vor Anpfiff.
                            </p>
                        </div>

                        <!-- Spieltag Vor/Zurück + Dropdown -->
                        <div class="flex items-center gap-1">
                            <?php $prevMd = max(1, $selectedMatchday - 1); ?>
                            <?php $nextMd = min($leagueMeta['max_matchday'], $selectedMatchday + 1); ?>
                            <a href="bundesliga.php?league=<?= urlencode($selectedLeague) ?>&amp;season=<?= (int)$selectedSeason ?>&amp;matchday=<?= $prevMd ?>"
                               class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 <?= $selectedMatchday <= 1 ? 'opacity-40 pointer-events-none' : '' ?>"
                               title="Vorheriger Spieltag">◀</a>

                            <form method="get" action="bundesliga.php" class="inline">
                                <input type="hidden" name="league" value="<?= e($selectedLeague) ?>">
                                <input type="hidden" name="season" value="<?= (int)$selectedSeason ?>">
                                <select name="matchday" onchange="this.form.submit()"
                                        aria-label="Spieltag auswählen"
                                        class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer">
                                    <?php for ($md = 1; $md <= $leagueMeta['max_matchday']; $md++): ?>
                                        <option value="<?= $md ?>" <?= $md === $selectedMatchday ? 'selected' : '' ?>>
                                            <?= $md ?>. Spieltag<?= ($selectedSeason === $detectedSeason && $md === $detectedMatchday) ? ' (Aktuell)' : '' ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </form>

                            <a href="bundesliga.php?league=<?= urlencode($selectedLeague) ?>&amp;season=<?= (int)$selectedSeason ?>&amp;matchday=<?= $nextMd ?>"
                               class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 <?= $selectedMatchday >= $leagueMeta['max_matchday'] ? 'opacity-40 pointer-events-none' : '' ?>"
                               title="Nächster Spieltag">▶</a>
                        </div>
                    </div>
                </div>

                <?php if (empty($matchdayData)): ?>
                    <div class="p-8 text-center text-xs text-slate-500 dark:text-slate-400">
                        Für diesen Spieltag sind noch keine Begegnungen eingetragen.
                    </div>
                <?php else: ?>
                    <form method="post" action="bundesliga.php?league=<?= urlencode($selectedLeague) ?>&amp;season=<?= (int)$selectedSeason ?>&amp;matchday=<?= (int)$selectedMatchday ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="save_bets">
                        <input type="hidden" name="league" value="<?= e($selectedLeague) ?>">
                        <input type="hidden" name="season" value="<?= (int)$selectedSeason ?>">
                        <input type="hidden" name="matchday" value="<?= (int)$selectedMatchday ?>">

                        <?php $hasOpenMatchesForBetting = false; ?>
                        <div class="divide-y divide-slate-200/80 dark:divide-slate-800">
                            <?php foreach ($matchdayData as $m): ?>
                                <?php
                                $mId     = (int)($m['matchID'] ?? 0);
                                $t1      = $m['team1'] ?? [];
                                $t2      = $m['team2'] ?? [];
                                $t1Id    = (int)($t1['teamId'] ?? 0);
                                $t2Id    = (int)($t2['teamId'] ?? 0);
                                $t1Name  = (string)($t1['shortName'] ?: $t1['teamName'] ?? 'Heim');
                                $t2Name  = (string)($t2['shortName'] ?: $t2['teamName'] ?? 'Gast');
                                $t1Full  = (string)($t1['teamName'] ?? $t1Name);
                                $t2Full  = (string)($t2['teamName'] ?? $t2Name);
                                $t1Icon  = (string)($t1['teamIconUrl'] ?? '');
                                $t2Icon  = (string)($t2['teamIconUrl'] ?? '');
                                $scores  = extractMatchScores($m);
                                $goals   = is_array($m['goals'] ?? null) ? $m['goals'] : [];
                                $stadium = (string)($m['location']['locationStadium'] ?? '');
                                $city    = (string)($m['location']['locationCity'] ?? '');
                                $viewers = (int)($m['numberOfViewers'] ?? 0);

                                $isFavMatch = ($favTeamId > 0 && ($t1Id === $favTeamId || $t2Id === $favTeamId));
                                $canBet     = !$scores['is_started'] && !$scores['is_finished'];
                                if ($canBet) {
                                    $hasOpenMatchesForBetting = true;
                                }

                                $myBet     = $myMatchdayBets[$mId] ?? null;
                                $myBetEval = ($myBet !== null && $scores['has_score'])
                                    ? calculateBetPoints($myBet['g1'], $myBet['g2'], (int)$scores['score1'], (int)$scores['score2'])
                                    : null;
                                $commBets  = $communityBetsByMd[$mId] ?? [];
                                ?>
                                <div class="p-3.5 sm:p-4 transition-colors <?= $isFavMatch ? 'bg-amber-50/60 dark:bg-amber-950/25' : 'hover:bg-slate-50/70 dark:hover:bg-slate-800/40' ?>">
                                    <!-- Kopfzeile des Spiels: Anstoßzeit + Stadion + Status -->
                                    <div class="flex items-center justify-between gap-2 text-[11px] text-slate-400 dark:text-slate-500 mb-2">
                                        <span class="font-semibold">
                                            <?php if ($scores['kickoff_ts'] > 0): ?>
                                                <?= $germanWeekdays[(int)date('N', $scores['kickoff_ts'])] ?? '' ?>
                                                <?= date('d.m.Y • H:i', $scores['kickoff_ts']) ?> Uhr
                                            <?php else: ?>
                                                Termin offen
                                            <?php endif; ?>
                                            <?php if ($stadium !== '' || $city !== ''): ?>
                                                <span class="hidden sm:inline text-slate-400">• 🏟️ <?= e($stadium ?: $city) ?><?= $viewers > 0 ? ' (' . number_format($viewers, 0, ',', '.') . ')' : '' ?></span>
                                            <?php endif; ?>
                                        </span>

                                        <?php if ($scores['is_live']): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-rose-600 text-white animate-pulse">
                                                🔴 LIVE<?= $scores['live_clock'] !== '' ? ' • ' . e($scores['live_clock']) : '' ?>
                                            </span>
                                        <?php elseif ($scores['is_finished']): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                                Beendet
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                                Vorbericht
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Paarung & Ergebnis -->
                                    <div class="grid grid-cols-12 items-center gap-2">
                                        <!-- Heimteam -->
                                        <div class="col-span-5 flex items-center justify-end gap-2 text-right min-w-0">
                                            <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white truncate" title="<?= e($t1Full) ?>">
                                                <?= e($t1Name) ?>
                                            </span>
                                            <?php if ($t1Icon !== ''): ?>
                                                <img src="<?= e($t1Icon) ?>" alt="" class="w-6 h-6 object-contain shrink-0" loading="lazy" referrerpolicy="no-referrer">
                                            <?php endif; ?>
                                        </div>

                                        <!-- Mittelblock: Spielstand oder Anstoß -->
                                        <div class="col-span-2 flex flex-col items-center justify-center">
                                            <?php if ($scores['has_score']): ?>
                                                <div class="px-2.5 py-1 rounded-lg font-extrabold text-xs sm:text-sm tracking-tight whitespace-nowrap <?= $scores['is_live'] ? 'bg-rose-600 text-white' : 'bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900' ?>">
                                                    <?= (int)$scores['score1'] ?> : <?= (int)$scores['score2'] ?><?= $scores['extra_suffix'] !== '' ? ' <span class="text-[10px] font-bold opacity-85">' . e($scores['extra_suffix']) . '</span>' : '' ?>
                                                </div>
                                                <?php if ($scores['periods_str'] !== ''): ?>
                                                    <span class="text-[10px] text-slate-400 mt-0.5 whitespace-nowrap" title="Zwischenstände nach Dritteln">(<?= e($scores['periods_str']) ?>)</span>
                                                <?php elseif ($scores['ht1'] !== null && $scores['ht2'] !== null): ?>
                                                    <span class="text-[10px] text-slate-400 mt-0.5">(<?= (int)$scores['ht1'] ?>:<?= (int)$scores['ht2'] ?>)</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 font-bold text-xs">
                                                    - : -
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Gastteam -->
                                        <div class="col-span-5 flex items-center justify-start gap-2 text-left min-w-0">
                                            <?php if ($t2Icon !== ''): ?>
                                                <img src="<?= e($t2Icon) ?>" alt="" class="w-6 h-6 object-contain shrink-0" loading="lazy" referrerpolicy="no-referrer">
                                            <?php endif; ?>
                                            <span class="font-bold text-xs sm:text-sm text-slate-900 dark:text-white truncate" title="<?= e($t2Full) ?>">
                                                <?= e($t2Name) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Torschützen (ausklappbar, falls Tore gefallen sind) -->
                                    <?php if (!empty($goals)): ?>
                                        <details class="mt-2 group">
                                            <summary class="cursor-pointer text-[11px] font-semibold text-slate-500 dark:text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400 flex items-center gap-1 select-none">
                                                <span>⚽ <?= count($goals) ?> <?= count($goals) === 1 ? 'Tor' : 'Tore' ?> anzeigen</span>
                                                <span class="transition-transform group-open:rotate-180">▾</span>
                                            </summary>
                                            <div class="mt-1.5 pl-2 border-l-2 border-emerald-500/40 space-y-1 text-[11px] text-slate-600 dark:text-slate-300">
                                                <?php foreach ($goals as $g): ?>
                                                    <?php
                                                    $gMin    = (int)($g['matchMinute'] ?? 0);
                                                    $gScorer = trim((string)($g['goalGetterName'] ?? 'Torschütze'));
                                                    $gS1     = (int)($g['scoreTeam1'] ?? 0);
                                                    $gS2     = (int)($g['scoreTeam2'] ?? 0);
                                                    $isPen   = !empty($g['isPenalty']);
                                                    $isOwn   = !empty($g['isOwnGoal']);
                                                    ?>
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-mono font-bold text-emerald-700 dark:text-emerald-400 w-9"><?= $gMin > 0 ? $gMin . "'" : '•' ?></span>
                                                        <span class="px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 font-bold"><?= $gS1 ?>:<?= $gS2 ?></span>
                                                        <span class="font-medium"><?= e($gScorer ?: 'Tor') ?></span>
                                                        <?php if ($isPen): ?>
                                                            <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400">(Elfmeter)</span>
                                                        <?php endif; ?>
                                                        <?php if ($isOwn): ?>
                                                            <span class="text-[10px] font-bold text-rose-600 dark:text-rose-400">(Eigentor)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>

                                    <!-- 🎯 Tippspiel-Zeile für dieses Spiel -->
                                    <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <?php if ($canBet): ?>
                                            <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300 font-semibold">
                                                <span>🎯 Dein Tipp:</span>
                                            </div>
                                            <div class="flex items-center gap-1.5">
                                                <input type="number"
                                                       min="0" max="25"
                                                       name="bets[<?= $mId ?>][g1]"
                                                       value="<?= $myBet !== null ? (int)$myBet['g1'] : '' ?>"
                                                       placeholder="-"
                                                       aria-label="Heimtore Tipp <?= e($t1Name) ?>"
                                                       class="w-12 px-2 py-1 text-center rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-extrabold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                <span class="font-bold text-slate-400">:</span>
                                                <input type="number"
                                                       min="0" max="25"
                                                       name="bets[<?= $mId ?>][g2]"
                                                       value="<?= $myBet !== null ? (int)$myBet['g2'] : '' ?>"
                                                       placeholder="-"
                                                       aria-label="Gasttore Tipp <?= e($t2Name) ?>"
                                                       class="w-12 px-2 py-1 text-center rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 font-extrabold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                            </div>
                                        <?php else: ?>
                                            <div class="flex items-center gap-2">
                                                <span class="text-slate-500 dark:text-slate-400">🎯 Dein Tipp:</span>
                                                <?php if ($myBet !== null): ?>
                                                    <span class="font-extrabold text-slate-900 dark:text-white"><?= (int)$myBet['g1'] ?> : <?= (int)$myBet['g2'] ?></span>
                                                    <?php if ($myBetEval !== null): ?>
                                                        <?php
                                                        $badgeColor = 'bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400';
                                                        if ($myBetEval['points'] === 4) {
                                                            $badgeColor = 'bg-emerald-500 text-white';
                                                        } elseif ($myBetEval['points'] === 3) {
                                                            $badgeColor = 'bg-teal-500 text-white';
                                                        } elseif ($myBetEval['points'] === 2) {
                                                            $badgeColor = 'bg-amber-500 text-white';
                                                        }
                                                        ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold <?= $badgeColor ?>">
                                                            +<?= (int)$myBetEval['points'] ?> Pkt.
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-slate-400 italic text-[11px]">kein Tipp abgegeben</span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($commBets)): ?>
                                                <div class="flex flex-wrap items-center gap-1 text-[10px] text-slate-500">
                                                    <?php foreach ($commBets as $cb): ?>
                                                        <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800">
                                                            <?= e($cb['username']) ?>: <strong><?= (int)$cb['g1'] ?>:<?= (int)$cb['g2'] ?></strong><?= $cb['eval'] ? ' (' . (int)$cb['eval']['points'] . 'P)' : '' ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($hasOpenMatchesForBetting): ?>
                            <div class="p-4 bg-slate-50 dark:bg-slate-800/60 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between gap-3">
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    💡 Tipps sind bis zum jeweiligen Anpfiff änderbar.
                                </span>
                                <button type="submit"
                                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-extrabold shadow-sm transition-all">
                                    🎯 Tipps speichern
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ========================================================== -->
            <!-- 🏆 CLOUD-TIPPSPIEL BESTENLISTE                             -->
            <!-- ========================================================== -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="p-4 sm:p-5 border-b border-slate-200/80 dark:border-slate-800">
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>🏆 Cloud-Tippspiel Bestenliste</span>
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">(<?= e($leagueMeta['short']) ?> <?= (int)$selectedSeason ?>/<?= substr((string)($selectedSeason + 1), -2) ?>)</span>
                    </h2>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                        Wertung: <strong>4 Pkt.</strong> Exakt • <strong>3 Pkt.</strong> Tordifferenz • <strong>2 Pkt.</strong> Tendenz
                    </p>
                </div>

                <?php if (empty($leaderboard)): ?>
                    <div class="p-6 text-center text-xs text-slate-500 dark:text-slate-400">
                        Noch keine Tipps für diese Saison abgegeben. Gib oben deinen ersten Tipp ab! 🎯
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800 text-[10px] font-bold uppercase text-slate-400 bg-slate-50/70 dark:bg-slate-800/40">
                                    <th class="py-2 pl-4 pr-2">#</th>
                                    <th class="py-2 px-2">Tipper</th>
                                    <th class="py-2 px-1.5 text-center" title="Ausgewertete Spiele / Abgegebene Tipps">Spiele</th>
                                    <th class="py-2 px-1.5 text-center" title="Exakter Tipp (4 Punkte)">4P</th>
                                    <th class="py-2 px-1.5 text-center" title="Richtige Tordifferenz (3 Punkte)">3P</th>
                                    <th class="py-2 px-1.5 text-center" title="Richtige Tendenz (2 Punkte)">2P</th>
                                    <th class="py-2 pl-2 pr-4 text-right">Punkte</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                                <?php foreach ($leaderboard as $lIdx => $entry): ?>
                                    <?php
                                    $lRank = $lIdx + 1;
                                    $isMe  = ((int)$entry['user_id'] === $userId);
                                    $medal = $lRank === 1 ? '🥇' : ($lRank === 2 ? '🥈' : ($lRank === 3 ? '🥉' : (string)$lRank));
                                    ?>
                                    <tr class="<?= $isMe ? 'bg-emerald-50/70 dark:bg-emerald-950/30 font-bold' : '' ?>">
                                        <td class="py-2.5 pl-4 pr-2 font-extrabold"><?= $medal ?></td>
                                        <td class="py-2.5 px-2 font-bold text-slate-900 dark:text-white">
                                            <?= e($entry['username']) ?>
                                            <?php if ($isMe): ?>
                                                <span class="ml-1 text-[10px] text-emerald-600 dark:text-emerald-400">(Du)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2.5 px-1.5 text-center text-slate-500"><?= (int)$entry['evaluated'] ?>/<?= (int)$entry['total_bets'] ?></td>
                                        <td class="py-2.5 px-1.5 text-center text-emerald-600 dark:text-emerald-400 font-semibold"><?= (int)$entry['exact'] ?></td>
                                        <td class="py-2.5 px-1.5 text-center text-teal-600 dark:text-teal-400 font-semibold"><?= (int)$entry['diff'] ?></td>
                                        <td class="py-2.5 px-1.5 text-center text-amber-600 dark:text-amber-400 font-semibold"><?= (int)$entry['tendency'] ?></td>
                                        <td class="py-2.5 pl-2 pr-4 text-right font-extrabold text-sm text-slate-900 dark:text-white"><?= (int)$entry['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </section>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
