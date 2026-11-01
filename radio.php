<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📻 LIVE-WEBRADIO & WELTWEITE SENDERSUCHE (radio.php)
 * ============================================================================
 * Eigenständiges Webradio-Modul für die MiniCloud:
 * - Durchgehender HTML5-Audio-Player mit Equalizer-Animation & Lautstärkeregler
 * - Live-ICY-Titelanzeige („Was läuft gerade?“ – liest aktuellen Song & Interpret direkt aus dem Stream)
 * - 22 kuratierte deutsche & Genre-Top-Sender (1LIVE, WDR 2, SWR3, Rock Antenne, 80s80s u.v.m.)
 * - Weltweite Live-Suche über die freie Radio-Browser API (> 30.000 Internetradios nach Name, Genre & Land)
 * - Persönliche Favoriten-Sender & eigene Stream-URLs (.mp3 / .aac) speichern + Sleep-Timer & MediaSession
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_radio'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('error', 'Das Modul „Live-Webradio“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// ============================================================================
// 1. DATENBANK-SCHEMA INITIALISIEREN (Auto-Migration)
// ============================================================================
function ensureRadioSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tv_radio_cache (
            cache_key VARCHAR(160) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tv_radio_stations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(160) NOT NULL,
            stream_url VARCHAR(500) NOT NULL,
            genre VARCHAR(120) NOT NULL DEFAULT '',
            country VARCHAR(80) NOT NULL DEFAULT 'DE',
            logo_url VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureRadioSchema($pdo);

// ============================================================================
// 2. SSRF-SCHUTZ & ICY-STREAM-METADATEN-READER („WAS LÄUFT GERADE?“)
// ============================================================================
function isSafeRadioStreamUrl(string $url): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower(trim((string)$parts['host'], '[]'));
    $port = isset($parts['port']) ? (int)$parts['port'] : (strtolower((string)($parts['scheme'] ?? 'http')) === 'https' ? 443 : 80);

    $blockedPorts = [21, 22, 23, 25, 53, 110, 143, 445, 3306, 5432, 6379, 9200, 11211, 11434, 27017];
    if (in_array($port, $blockedPorts, true)) {
        return false;
    }
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '169.254.169.254', 'metadata.google.internal'], true)) {
        return false;
    }
    $ip = @gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/**
 * Liest den aktuellen Song-Titel (StreamTitle) über das Icecast/Shoutcast ICY-Protokoll aus einem Webradio-Stream.
 */
function fetchIcyStreamTitle(string $streamUrl): string
{
    if (!isSafeRadioStreamUrl($streamUrl)) {
        return '';
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "Icy-MetaData: 1\r\nUser-Agent: MiniCloud-Webradio/1.0\r\nAccept: */*\r\nConnection: close\r\n",
            'timeout'         => 3.5,
            'follow_location' => 1,
            'max_redirects'   => 3,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $fp = @fopen($streamUrl, 'rb', false, $ctx);
    if (!$fp) {
        return '';
    }

    stream_set_timeout($fp, 3);
    $metaData = stream_get_meta_data($fp);
    $headers  = $metaData['wrapper_data'] ?? [];
    $metaInt  = 0;

    if (is_array($headers)) {
        foreach ($headers as $h) {
            if (stripos((string)$h, 'icy-metaint:') === 0) {
                $metaInt = (int)trim(substr((string)$h, 12));
            }
        }
    }

    $title = '';
    if ($metaInt > 0 && $metaInt <= 131072) {
        $readBytes = 0;
        while ($readBytes < $metaInt && !feof($fp)) {
            $chunk = @fread($fp, min(8192, $metaInt - $readBytes));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $readBytes += strlen($chunk);
        }
        if ($readBytes === $metaInt && !feof($fp)) {
            $lenByte = @fread($fp, 1);
            if ($lenByte !== false && strlen($lenByte) === 1) {
                $metaLen = ord($lenByte) * 16;
                if ($metaLen > 0 && $metaLen <= 4096) {
                    $metaStr = '';
                    while (strlen($metaStr) < $metaLen && !feof($fp)) {
                        $c = @fread($fp, $metaLen - strlen($metaStr));
                        if ($c === false || $c === '') {
                            break;
                        }
                        $metaStr .= $c;
                    }
                    if (preg_match("/StreamTitle='([^']*)'/i", $metaStr, $m)) {
                        $rawTitle = trim($m[1]);
                        if (!mb_check_encoding($rawTitle, 'UTF-8')) {
                            $rawTitle = mb_convert_encoding($rawTitle, 'UTF-8', 'ISO-8859-1');
                        }
                        $title = mb_substr(trim($rawTitle), 0, 180);
                    }
                }
            }
        }
    }

    @fclose($fp);
    return $title;
}

// ============================================================================
// 3. AJAX-ENDPUNKTE (ICY-METADATEN & RADIO-BROWSER-SUCHE)
// ============================================================================
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'icy_meta') {
    header('Content-Type: application/json; charset=UTF-8');
    $streamUrl = trim((string)($_GET['url'] ?? ''));
    $songTitle = $streamUrl !== '' ? fetchIcyStreamTitle($streamUrl) : '';
    echo json_encode([
        'ok'    => true,
        'title' => $songTitle,
        'time'  => date('H:i'),
    ]);
    exit;
}

if ($action === 'radio_search') {
    header('Content-Type: application/json; charset=UTF-8');
    $query   = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
    $tag     = mb_substr(trim((string)($_GET['tag'] ?? '')), 0, 40);
    $country = strtoupper(mb_substr(trim((string)($_GET['country'] ?? 'DE')), 0, 4));

    $cacheKey = 'rb_' . md5(strtolower($query . '|' . $tag . '|' . $country));
    $stmtC    = $pdo->prepare("SELECT payload, updated_at FROM tv_radio_cache WHERE cache_key = ?");
    $stmtC->execute([$cacheKey]);
    $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);

    if ($rowC && (time() - (int)strtotime((string)$rowC['updated_at'])) < 1800) {
        $cachedStations = json_decode((string)$rowC['payload'], true);
        if (is_array($cachedStations)) {
            echo json_encode(['ok' => true, 'stations' => $cachedStations, 'cached' => true]);
            exit;
        }
    }

    $params = [
        'limit'      => 36,
        'order'      => 'clickcount',
        'reverse'    => 'true',
        'hidebroken' => 'true',
        'is_https'   => 'true',
    ];
    if ($query !== '') {
        $params['name'] = $query;
    }
    if ($tag !== '') {
        $params['tag'] = strtolower($tag);
    }
    if ($country !== '' && $country !== 'ALL') {
        $params['countrycode'] = $country;
    }

    $endpoints = [
        'https://de1.api.radio-browser.info/json/stations/search?' . http_build_query($params),
        'https://at1.api.radio-browser.info/json/stations/search?' . http_build_query($params),
        'https://nl1.api.radio-browser.info/json/stations/search?' . http_build_query($params),
    ];

    $rawJson = null;
    foreach ($endpoints as $apiUrl) {
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT      => 'MiniCloud-RadioModule/1.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $res  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($res) && $code >= 200 && $code < 300) {
                $rawJson = $res;
                break;
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 5,
                    'header'  => "User-Agent: MiniCloud-RadioModule/1.0\r\nAccept: application/json\r\n",
                ],
            ]);
            $res = @file_get_contents($apiUrl, false, $ctx);
            if (is_string($res) && $res !== '') {
                $rawJson = $res;
                break;
            }
        }
    }

    $stations = [];
    if ($rawJson !== null) {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $seenUrls = [];
            foreach ($decoded as $st) {
                $streamUrl = trim((string)($st['url_resolved'] ?? $st['url'] ?? ''));
                $name      = trim((string)($st['name'] ?? ''));
                if ($streamUrl === '' || $name === '' || !str_starts_with(strtolower($streamUrl), 'https://')) {
                    continue;
                }
                if (isset($seenUrls[$streamUrl])) {
                    continue;
                }
                $seenUrls[$streamUrl] = true;

                $stations[] = [
                    'name'       => mb_substr($name, 0, 120),
                    'stream_url' => $streamUrl,
                    'genre'      => mb_substr(trim((string)($st['tags'] ?? '')), 0, 90),
                    'country'    => strtoupper(trim((string)($st['countrycode'] ?? 'DE'))),
                    'bitrate'    => (int)($st['bitrate'] ?? 0),
                    'codec'      => strtoupper(trim((string)($st['codec'] ?? 'MP3'))),
                    'logo_url'   => trim((string)($st['favicon'] ?? '')),
                ];
            }

            $up = $pdo->prepare("
                INSERT INTO tv_radio_cache (cache_key, payload, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()
            ");
            $up->execute([$cacheKey, json_encode($stations, JSON_UNESCAPED_UNICODE)]);
        }
    }

    echo json_encode(['ok' => true, 'stations' => $stations, 'cached' => false]);
    exit;
}

// ============================================================================
// 4. BENUTZER-FAVORITEN & POST-AKTIONEN
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuche es erneut.');
        header('Location: radio.php');
        exit;
    }

    // 4a) Eigenen oder gefundenen Radiosender als Favorit speichern
    if ($action === 'save_radio_station') {
        $stName    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
        $stUrl     = mb_substr(trim((string)($_POST['stream_url'] ?? '')), 0, 500);
        $stGenre   = mb_substr(trim((string)($_POST['genre'] ?? '')), 0, 110);
        $stCountry = strtoupper(mb_substr(trim((string)($_POST['country'] ?? 'DE')), 0, 10));
        $stLogo    = mb_substr(trim((string)($_POST['logo_url'] ?? '')), 0, 500);

        if ($stName === '' || !preg_match('#^https?://#i', $stUrl)) {
            setFlash('error', 'Bitte gib einen gültigen Sendernamen und eine Stream-URL (http/https) an.');
        } else {
            $chk = $pdo->prepare("SELECT id FROM tv_radio_stations WHERE user_id = ? AND stream_url = ? LIMIT 1");
            $chk->execute([$userId, $stUrl]);
            if (!$chk->fetch()) {
                $ins = $pdo->prepare("
                    INSERT INTO tv_radio_stations (user_id, name, stream_url, genre, country, logo_url)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$userId, $stName, $stUrl, $stGenre, $stCountry, $stLogo]);
            }
            setFlash('success', "⭐ Radiosender „{$stName}“ wurde zu deinen Favoriten hinzugefügt!");
        }
        header('Location: radio.php');
        exit;
    }

    // 4b) Gespeicherten Favoriten-Radiosender löschen
    if ($action === 'delete_radio_station') {
        $stId = (int)($_POST['station_id'] ?? 0);
        if ($stId > 0) {
            $del = $pdo->prepare("DELETE FROM tv_radio_stations WHERE id = ? AND user_id = ?");
            $del->execute([$stId, $userId]);
            setFlash('success', 'Radiosender wurde aus deinen Favoriten entfernt.');
        }
        header('Location: radio.php');
        exit;
    }
}

// ============================================================================
// 5. KURATIERTE TOP-WEBRADIOSENDER & BENUTZER-FAVORITEN
// ============================================================================
$presetRadioStations = [
    // Öffentlich-Rechtlich & Popwellen
    ['name' => '1LIVE (WDR)',           'genre' => 'Pop, Charts & Newcomer',          'country' => 'DE', 'icon' => '🔥', 'stream_url' => 'https://wdr-1live-live.icecastssl.wdr.de/wdr/1live/live/mp3/128/stream.mp3'],
    ['name' => 'WDR 2 Rheinland',       'genre' => 'Pop, Infos & Bundesliga',         'country' => 'DE', 'icon' => '📻', 'stream_url' => 'https://wdr-wdr2-rheinland.icecastssl.wdr.de/wdr/wdr2/rheinland/mp3/128/stream.mp3'],
    ['name' => 'WDR 4',                 'genre' => 'Lieblingshits & Klassiker',       'country' => 'DE', 'icon' => '🎶', 'stream_url' => 'https://wdr-wdr4-live.icecastssl.wdr.de/wdr/wdr4/live/mp3/128/stream.mp3'],
    ['name' => 'SWR3',                  'genre' => 'Pop, Comedy & Aktuelles',         'country' => 'DE', 'icon' => '⚡', 'stream_url' => 'https://liveradio.swr.de/sw282p3/swr3/play.mp3'],
    ['name' => 'Bayern 1',              'genre' => '70er, 80er & Kulthits',           'country' => 'DE', 'icon' => '🥨', 'stream_url' => 'https://dispatcher.rndfnk.com/br/br1/obb/mp3/mid'],
    ['name' => 'BAYERN 3',              'genre' => 'Hits & Neue Musik',               'country' => 'DE', 'icon' => '🎧', 'stream_url' => 'https://dispatcher.rndfnk.com/br/br3/live/mp3/mid'],
    ['name' => 'NDR 2',                 'genre' => 'Norddeutschlands Popwelle',       'country' => 'DE', 'icon' => '🌊', 'stream_url' => 'https://icecast.ndr.de/ndr/ndr2/niedersachsen/mp3/128/stream.mp3'],
    ['name' => 'hr3',                   'genre' => 'Pop & Hits aus Hessen',           'country' => 'DE', 'icon' => '🎵', 'stream_url' => 'https://dispatcher.rndfnk.com/hr/hr3/live/mp3/high'],
    ['name' => 'MDR JUMP',              'genre' => 'Pop & Dance',                     'country' => 'DE', 'icon' => '🚀', 'stream_url' => 'https://mdr-284280-1.sslcast.mdr.de/mdr/284280/1/mp3/high/stream.mp3'],
    ['name' => 'Deutschlandfunk',       'genre' => 'Nachrichten, Politik & Kultur',   'country' => 'DE', 'icon' => '📰', 'stream_url' => 'https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'],
    // Privat- & Genre-Sender
    ['name' => 'ANTENNE BAYERN',        'genre' => 'Besten Hits & Charts',            'country' => 'DE', 'icon' => '☀️', 'stream_url' => 'https://stream.antenne.de/antenne/stream/mp3'],
    ['name' => 'ROCK ANTENNE',          'genre' => 'Classic & Modern Rock',           'country' => 'DE', 'icon' => '🎸', 'stream_url' => 'https://stream.rockantenne.de/rockantenne/stream/mp3'],
    ['name' => 'RADIO BOB!',            'genre' => 'Rock, Metal & Hardrock',          'country' => 'DE', 'icon' => '🤘', 'stream_url' => 'https://streams.radiobob.de/bob-live/mp3-192/mediaplayer'],
    ['name' => '80s80s Radio',          'genre' => '100 % 80er Kult-Hits',            'country' => 'DE', 'icon' => '🕹️', 'stream_url' => 'https://streams.80s80s.de/web/mp3-192/'],
    ['name' => '90s90s Hits',           'genre' => 'Eurodance & 90er Hits',           'country' => 'DE', 'icon' => '💿', 'stream_url' => 'https://streams.90s90s.de/pop/mp3-192/'],
    ['name' => 'sunshine live',         'genre' => 'Electronic, Techno & House',      'country' => 'DE', 'icon' => '🎛️', 'stream_url' => 'https://stream.sunshine-live.de/live/mp3-192'],
    ['name' => 'bigFM',                 'genre' => 'Hip-Hop, Urban & Charts',         'country' => 'DE', 'icon' => '🎤', 'stream_url' => 'https://streams.bigfm.de/bigfm-deutschland-128-mp3'],
    ['name' => 'Klassik Radio',         'genre' => 'Klassik & Filmmusik',             'country' => 'DE', 'icon' => '🎻', 'stream_url' => 'https://stream.klassikradio.de/live/mp3-192'],
    ['name' => 'Absolut relax',         'genre' => 'Soft Pop & Entspannung',          'country' => 'DE', 'icon' => '☕', 'stream_url' => 'https://absolut-relax.live-sm.absolutradio.de/absolut-relax/stream/mp3'],
    ['name' => 'Radio Schlagerparadies','genre' => 'Deutscher Schlager & Party',      'country' => 'DE', 'icon' => '🥳', 'stream_url' => 'https://irmedia.streamabc.net/irm-schlagerparadieslive-mp3-128-9657315'],
    ['name' => 'Radio Swiss Jazz',      'genre' => 'Jazz, Soul & Blues (Werbefrei)',  'country' => 'CH', 'icon' => '🎷', 'stream_url' => 'https://stream.srg-ssr.ch/m/rsj/mp3_128'],
    ['name' => 'SomaFM Groove Salad',   'genre' => 'Ambient, Chillout & Lo-Fi',       'country' => 'US', 'icon' => '🌴', 'stream_url' => 'https://ice2.somafm.com/groovesalad-128-mp3'],
];

$myStationsStmt = $pdo->prepare("SELECT * FROM tv_radio_stations WHERE user_id = ? ORDER BY name ASC");
$myStationsStmt->execute([$userId]);
$myFavoriteStations = $myStationsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = getCsrfToken();
$pageTitle = '📻 Live-Webradio';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- 1. KOPFBEREICH & LIVE-WEBRADIO-PLAYER (DOCK)                       -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 via-violet-600 to-purple-700 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    📻
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Live-Webradio
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                            30.000+ Sender &amp; Live-Songtitel
                        </span>
                        <span id="radioLiveBadgeTop" class="hidden px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500 text-white animate-pulse">
                            ON AIR
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Deutsche &amp; internationale Internetradios mit Live-ICY-Titelanzeige, eigenen Favoriten, Sleep-Timer &amp; Sendersuche.
                    </p>
                </div>
            </div>

            <!-- Schnellwechsel zu TV-Programm & Nachrichten -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if (($appSettings['enable_tv_radio'] ?? '1') === '1'): ?>
                    <a href="tv_radio.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors">
                        <span>📺</span>
                        <span>Zum Fernsehprogramm</span>
                    </a>
                <?php endif; ?>
                <?php if (($appSettings['enable_news'] ?? '1') === '1'): ?>
                    <a href="news.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-sky-50 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors">
                        <span>📰</span>
                        <span>Zu den Welt-Nachrichten</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- LIVE-WEBRADIO-PLAYER -->
        <div id="globalRadioDock" class="rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white p-4 border border-indigo-500/30 shadow-md">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <!-- Links: Play/Stop + Aktueller Sender + Live-Songtitel (ICY) -->
                <div class="flex items-center gap-3.5 min-w-0">
                    <button type="button" id="radioMasterPlayBtn" onclick="toggleRadioPlayback()"
                            class="w-12 h-12 rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xl flex items-center justify-center shadow-lg transition-transform active:scale-95 cursor-pointer shrink-0"
                            title="Webradio starten / pausieren">
                        ▶️
                    </button>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span id="radioStatusDot" class="w-2.5 h-2.5 rounded-full bg-slate-500 shrink-0"></span>
                            <span id="radioCurrentStationName" class="text-sm sm:text-base font-extrabold truncate">
                                Wähle einen Radiosender oder klicke auf ▶️ (1LIVE)
                            </span>
                            <span id="radioCurrentGenreBadge" class="hidden sm:inline-block px-2 py-0.5 rounded-md text-[10px] font-bold bg-white/10 text-indigo-200 truncate max-w-[180px]">
                                Pop &amp; Charts
                            </span>
                        </div>
                        <div class="text-xs text-indigo-200/90 truncate mt-0.5 flex items-center gap-1.5">
                            <span>🎶</span>
                            <span id="radioCurrentSongTitle" class="truncate font-medium">Bereit zum Abspielen – 1-Klick auf einen Sender unten startet den Stream</span>
                        </div>
                    </div>
                </div>

                <!-- Rechts: Lautstärke & Sleep-Timer -->
                <div class="flex flex-wrap items-center gap-3 shrink-0">
                    <!-- Animierter Equalizer-Balken -->
                    <div id="radioEqBars" class="hidden sm:flex items-end gap-0.5 h-5 px-2">
                        <span class="w-1 bg-emerald-400 rounded-t h-2 animate-pulse"></span>
                        <span class="w-1 bg-emerald-400 rounded-t h-4 animate-pulse" style="animation-delay:150ms"></span>
                        <span class="w-1 bg-emerald-400 rounded-t h-3 animate-pulse" style="animation-delay:300ms"></span>
                        <span class="w-1 bg-emerald-400 rounded-t h-5 animate-pulse" style="animation-delay:75ms"></span>
                    </div>

                    <!-- Lautstärke -->
                    <div class="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-xl">
                        <button type="button" onclick="toggleRadioMute()" id="radioMuteBtn" class="text-xs cursor-pointer" title="Stummschalten">🔊</button>
                        <input type="range" id="radioVolumeSlider" min="0" max="100" value="85"
                               oninput="setRadioVolume(this.value)"
                               aria-label="Radio-Lautstärke"
                               class="w-20 sm:w-24 accent-emerald-400 cursor-pointer">
                    </div>

                    <!-- Sleep-Timer -->
                    <select id="radioSleepTimerSelect" onchange="setRadioSleepTimer(this.value)"
                            aria-label="Sleep-Timer"
                            class="px-2.5 py-1.5 rounded-xl bg-white/10 border border-white/15 text-xs font-bold text-white cursor-pointer focus:outline-none">
                        <option value="0" class="text-slate-900">🌙 Sleep-Timer: Aus</option>
                        <option value="15" class="text-slate-900">🌙 15 Min.</option>
                        <option value="30" class="text-slate-900">🌙 30 Min.</option>
                        <option value="45" class="text-slate-900">🌙 45 Min.</option>
                        <option value="60" class="text-slate-900">🌙 60 Min.</option>
                        <option value="90" class="text-slate-900">🌙 90 Min.</option>
                    </select>
                    <span id="radioSleepCountdown" class="hidden text-[11px] font-mono font-bold text-amber-300"></span>
                </div>
            </div>
            <audio id="globalRadioAudio" preload="none" crossorigin="anonymous"></audio>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- 2. MEINE GESPEICHERTEN LIEBLINGSSENDER                             -->
    <!-- ================================================================== -->
    <section class="space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-sm space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>⭐ Meine gespeicherten Lieblingssender</span>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                            <?= count($myFavoriteStations) ?>
                        </span>
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Speichere Sender aus der Suche unten oder füge deine eigene Stream-URL (.mp3 / .aac) hinzu.
                    </p>
                </div>

                <button type="button" onclick="document.getElementById('customRadioFormBox').classList.toggle('hidden')"
                        class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                    ➕ Eigene Stream-URL hinzufügen
                </button>
            </div>

            <!-- Formular für eigenen Stream -->
            <form id="customRadioFormBox" method="post" action="radio.php" class="hidden p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_radio_station">
                <div class="sm:col-span-4">
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Sendername *</label>
                    <input type="text" name="name" required placeholder="z. B. Radio Hagen / Mein Stream"
                           class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div class="sm:col-span-5">
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Stream-URL (HTTPS MP3/AAC) *</label>
                    <input type="url" name="stream_url" required placeholder="https://..."
                           class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Genre</label>
                    <input type="text" name="genre" placeholder="Pop / Lokal"
                           class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                </div>
                <div class="sm:col-span-1">
                    <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold cursor-pointer">
                        Speichern
                    </button>
                </div>
            </form>

            <?php if (empty($myFavoriteStations)): ?>
                <p class="text-xs text-slate-400 italic">
                    Du hast noch keine eigenen Favoriten-Sender gespeichert. Klicke bei einem Sender auf „☆“ oder „⭐ Merken“, um ihn hier anzupinnen.
                </p>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($myFavoriteStations as $favSt): ?>
                        <div class="p-3 rounded-xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-300/70 dark:border-amber-800/60 flex items-center justify-between gap-2">
                            <button type="button"
                                    data-st-name="<?= e((string)$favSt['name']) ?>"
                                    data-st-url="<?= e((string)$favSt['stream_url']) ?>"
                                    data-st-genre="<?= e((string)$favSt['genre']) ?>"
                                    onclick="playRadioStation(this.getAttribute('data-st-name'), this.getAttribute('data-st-url'), this.getAttribute('data-st-genre'))"
                                    class="flex items-center gap-2.5 min-w-0 text-left flex-1 cursor-pointer group">
                                <span class="w-9 h-9 rounded-xl bg-amber-500 text-white flex items-center justify-center font-bold text-sm shrink-0 group-hover:scale-105 transition-transform">▶️</span>
                                <span class="min-w-0">
                                    <span class="block text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate"><?= e((string)$favSt['name']) ?></span>
                                    <span class="block text-[11px] text-slate-500 dark:text-slate-400 truncate"><?= e((string)($favSt['genre'] ?: $favSt['country'])) ?></span>
                                </span>
                            </button>
                            <form method="post" action="radio.php" class="shrink-0">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_radio_station">
                                <input type="hidden" name="station_id" value="<?= (int)$favSt['id'] ?>">
                                <button type="submit" title="Aus Favoriten entfernen" class="p-1.5 text-slate-400 hover:text-rose-600 text-xs cursor-pointer">✕</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ================================================================== -->
        <!-- 3. BELIEBTESTE DEUTSCHE & GENRE-SENDER (SOFORT-START)              -->
        <!-- ================================================================== -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-sm space-y-4">
            <div>
                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                    📻 Beliebte deutsche Sender &amp; Musikrichtungen (1-Klick Start)
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Klicke auf einen Sender, um den Live-Stream sofort im oberen Player zu starten.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                <?php foreach ($presetRadioStations as $st): ?>
                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-indigo-50/60 dark:hover:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 transition-all flex items-center justify-between gap-2"
                         data-radio-card="<?= e($st['stream_url']) ?>">
                        <button type="button"
                                data-st-name="<?= e($st['name']) ?>"
                                data-st-url="<?= e($st['stream_url']) ?>"
                                data-st-genre="<?= e($st['genre']) ?>"
                                onclick="playRadioStation(this.getAttribute('data-st-name'), this.getAttribute('data-st-url'), this.getAttribute('data-st-genre'))"
                                class="flex items-center gap-3 min-w-0 text-left flex-1 cursor-pointer group">
                            <span class="w-10 h-10 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-lg shadow-2xs group-hover:scale-105 transition-transform shrink-0">
                                <?= $st['icon'] ?>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                                    <?= e($st['name']) ?>
                                </span>
                                <span class="block text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                    <?= e($st['genre']) ?>
                                </span>
                            </span>
                        </button>

                        <!-- Als Favorit merken -->
                        <form method="post" action="radio.php" class="shrink-0">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="save_radio_station">
                            <input type="hidden" name="name" value="<?= e($st['name']) ?>">
                            <input type="hidden" name="stream_url" value="<?= e($st['stream_url']) ?>">
                            <input type="hidden" name="genre" value="<?= e($st['genre']) ?>">
                            <input type="hidden" name="country" value="<?= e($st['country']) ?>">
                            <button type="submit" title="Zu meinen Favoriten hinzufügen"
                                    class="p-1.5 rounded-lg text-slate-300 dark:text-slate-600 hover:text-amber-500 cursor-pointer">
                                ☆
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ================================================================== -->
        <!-- 4. WELTWEITE LIVE-SENDERSUCHE (RADIO-BROWSER > 30.000 SENDER)      -->
        <!-- ================================================================== -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 shadow-sm space-y-4">
            <div>
                <h2 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                    🌍 Weltweite Sendersuche (über 30.000 Internetradios)
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Durchsuche die freie Radio-Browser-Datenbank nach Lokalradios, Musikrichtungen oder Ländern.
                </p>
            </div>

            <form onsubmit="searchRadioBrowser(event)" class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                <div class="sm:col-span-5">
                    <input type="text" id="rbQueryInput" placeholder="Sendername (z. B. FFH, Energy, Ibiza, BBC, Lokalradio)…"
                           class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="sm:col-span-3">
                    <select id="rbTagSelect" aria-label="Musikrichtung auswählen"
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                        <option value="">🎵 Alle Genres</option>
                        <option value="pop">Pop &amp; Charts</option>
                        <option value="rock">Rock &amp; Alternative</option>
                        <option value="80s">80er Jahre</option>
                        <option value="90s">90er Jahre</option>
                        <option value="dance">Dance, House &amp; Electronic</option>
                        <option value="chillout">Chillout, Lounge &amp; Ambient</option>
                        <option value="schlager">Schlager &amp; Oldies</option>
                        <option value="jazz">Jazz, Blues &amp; Soul</option>
                        <option value="classical">Klassik</option>
                        <option value="news">Nachrichten &amp; Sport</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <select id="rbCountrySelect" aria-label="Land auswählen"
                            class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 cursor-pointer">
                        <option value="DE">🇩🇪 Deutschland</option>
                        <option value="AT">🇦🇹 Österreich</option>
                        <option value="CH">🇨🇭 Schweiz</option>
                        <option value="GB">🇬🇧 Großbritannien</option>
                        <option value="US">🇺🇸 USA</option>
                        <option value="FR">🇫🇷 Frankreich</option>
                        <option value="ES">🇪🇸 Spanien</option>
                        <option value="IT">🇮🇹 Italien</option>
                        <option value="NL">🇳🇱 Niederlande</option>
                        <option value="ALL">🌍 Weltweit</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit"
                            class="w-full px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-extrabold shadow-xs cursor-pointer">
                        🔍 Sender suchen
                    </button>
                </div>
            </form>

            <!-- Schnell-Genre-Pills -->
            <div class="flex flex-wrap items-center gap-1.5">
                <?php foreach (['80s' => '🕹️ 80er', '90s' => '💿 90er', 'rock' => '🎸 Rock', 'chillout' => '🌴 Chillout', 'dance' => '🎛️ Dance & House', 'schlager' => '🥳 Schlager', 'jazz' => '🎷 Jazz', 'classical' => '🎻 Klassik'] as $qTag => $qLbl): ?>
                    <button type="button" onclick="quickSearchRadioTag('<?= e($qTag) ?>')"
                            class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 cursor-pointer">
                        <?= e($qLbl) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div id="rbSearchResults" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-2"></div>
        </div>
    </section>

</main>

<script>
const RADIO_CSRF = <?= json_encode($csrfToken) ?>;
const DEFAULT_RADIO_STATION = <?= json_encode($presetRadioStations[0]) ?>;
const RADIO_STATE_KEY = 'mc_global_radio_state_v1';

let activeRadioStation = null;
let icyMetaTimer       = null;
let sleepTimerInterval = null;
let sleepEndTimestamp  = 0;

function getSharedRadioHost() {
    try {
        if (window.parent && window.parent !== window && window.parent.McGlobalRadio) {
            return window.parent.McGlobalRadio;
        }
    } catch (e) {}
    return window.McGlobalRadio || null;
}

function readSavedRadioState() {
    try {
        const raw = sessionStorage.getItem(RADIO_STATE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return (parsed && typeof parsed === 'object') ? parsed : null;
    } catch (e) {
        return null;
    }
}

function writeSavedRadioState(patch) {
    try {
        const prev = readSavedRadioState() || {};
        const next = Object.assign({}, prev, patch, { updatedAt: Date.now() });
        sessionStorage.setItem(RADIO_STATE_KEY, JSON.stringify(next));
        const host = getSharedRadioHost();
        if (host && typeof host.syncFromState === 'function') {
            host.syncFromState(next);
        }
        return next;
    } catch (e) {
        return null;
    }
}

function getEffectiveRadioAudio() {
    const host = getSharedRadioHost();
    if (host && typeof host.getAudio === 'function') {
        const hostAudio = host.getAudio();
        if (hostAudio) return hostAudio;
    }
    return document.getElementById('globalRadioAudio');
}

function playRadioStation(name, streamUrl, genre) {
    const host = getSharedRadioHost();
    if (host && typeof host.playStation === 'function') {
        host.playStation(name, streamUrl, genre || 'Live-Radio');
        syncRadioDockFromState();
        return;
    }

    const audio = getEffectiveRadioAudio();
    if (!audio) return;

    activeRadioStation = { name, streamUrl, genre: genre || 'Live-Radio' };

    document.getElementById('radioCurrentStationName').textContent = name;
    const genreBadge = document.getElementById('radioCurrentGenreBadge');
    if (genreBadge) {
        genreBadge.textContent = genre || 'Live';
        genreBadge.classList.remove('hidden');
    }
    document.getElementById('radioCurrentSongTitle').textContent = 'Verbinde mit Live-Stream…';

    const volVal = Number(document.getElementById('radioVolumeSlider')?.value || 85);
    audio.src = streamUrl;
    audio.volume = Math.max(0, Math.min(1, volVal / 100));
    audio.play().then(() => {
        writeSavedRadioState({
            playing: true,
            name: name,
            streamUrl: streamUrl,
            genre: genre || 'Live-Radio',
            volume: volVal,
            muted: audio.muted,
            songTitle: 'Live-Übertragung • ' + name
        });
        updateRadioUiPlaying(true);
        fetchCurrentIcySongTitle();
        if (icyMetaTimer) clearInterval(icyMetaTimer);
        icyMetaTimer = setInterval(fetchCurrentIcySongTitle, 25000);
    }).catch(() => {
        document.getElementById('radioCurrentSongTitle').textContent = 'Stream konnte nicht direkt gestartet werden (bitte erneut auf ▶️ klicken).';
        writeSavedRadioState({ playing: false });
        updateRadioUiPlaying(false);
    });
}

function toggleRadioPlayback() {
    const host = getSharedRadioHost();
    if (host && typeof host.toggle === 'function') {
        const st = readSavedRadioState();
        if (!st || !st.streamUrl) {
            host.playStation(DEFAULT_RADIO_STATION.name, DEFAULT_RADIO_STATION.stream_url, DEFAULT_RADIO_STATION.genre);
        } else {
            host.toggle();
        }
        syncRadioDockFromState();
        return;
    }

    const audio = getEffectiveRadioAudio();
    if (!audio) return;

    if (!activeRadioStation) {
        playRadioStation(DEFAULT_RADIO_STATION.name, DEFAULT_RADIO_STATION.stream_url, DEFAULT_RADIO_STATION.genre);
        return;
    }

    if (audio.paused) {
        audio.play().then(() => {
            writeSavedRadioState({ playing: true });
            updateRadioUiPlaying(true);
        }).catch(() => {});
    } else {
        audio.pause();
        writeSavedRadioState({ playing: false });
        updateRadioUiPlaying(false);
    }
}

function updateRadioUiPlaying(isPlaying) {
    const playBtn   = document.getElementById('radioMasterPlayBtn');
    const dot       = document.getElementById('radioStatusDot');
    const eqBars    = document.getElementById('radioEqBars');
    const topBadge  = document.getElementById('radioLiveBadgeTop');

    if (playBtn) playBtn.textContent = isPlaying ? '⏸️' : '▶️';
    if (dot) {
        dot.className = isPlaying
            ? 'w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping shrink-0'
            : 'w-2.5 h-2.5 rounded-full bg-slate-500 shrink-0';
    }
    if (eqBars)   eqBars.classList.toggle('hidden', !isPlaying);
    if (topBadge) topBadge.classList.toggle('hidden', !isPlaying);

    if ('mediaSession' in navigator && activeRadioStation) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: document.getElementById('radioCurrentSongTitle')?.textContent || activeRadioStation.name,
            artist: activeRadioStation.name,
            album: 'MiniCloud Live-Webradio'
        });
        navigator.mediaSession.setActionHandler('play', () => toggleRadioPlayback());
        navigator.mediaSession.setActionHandler('pause', () => toggleRadioPlayback());
    }
}

function setRadioVolume(val) {
    const volNum = Math.max(0, Math.min(100, Number(val)));
    const host = getSharedRadioHost();
    if (host && typeof host.setVolume === 'function') {
        host.setVolume(volNum);
    } else {
        const audio = getEffectiveRadioAudio();
        if (audio) {
            audio.muted  = false;
            audio.volume = volNum / 100;
        }
        writeSavedRadioState({ volume: volNum, muted: false });
    }
    const muteBtn = document.getElementById('radioMuteBtn');
    if (muteBtn) muteBtn.textContent = volNum === 0 ? '🔇' : '🔊';
}

function toggleRadioMute() {
    const host = getSharedRadioHost();
    if (host && typeof host.toggleMute === 'function') {
        const mutedNow = host.toggleMute();
        const muteBtn = document.getElementById('radioMuteBtn');
        if (muteBtn) muteBtn.textContent = mutedNow ? '🔇' : '🔊';
        return;
    }
    const audio = getEffectiveRadioAudio();
    if (!audio) return;
    audio.muted = !audio.muted;
    writeSavedRadioState({ muted: audio.muted });
    const muteBtn = document.getElementById('radioMuteBtn');
    if (muteBtn) muteBtn.textContent = audio.muted ? '🔇' : '🔊';
}

async function fetchCurrentIcySongTitle() {
    const audio = getEffectiveRadioAudio();
    if (!audio || audio.paused || !activeRadioStation) return;

    try {
        const res  = await fetch('radio.php?action=icy_meta&url=' + encodeURIComponent(activeRadioStation.streamUrl));
        const data = await res.json();
        const lbl  = document.getElementById('radioCurrentSongTitle');
        const nextTitle = (data && data.ok && data.title)
            ? data.title
            : ('Live-Übertragung • ' + activeRadioStation.name + ' (' + (activeRadioStation.genre || 'Live') + ')');
        if (lbl) lbl.textContent = nextTitle;
        writeSavedRadioState({ songTitle: nextTitle });
    } catch (e) {}
}

function syncRadioDockFromState() {
    const st = readSavedRadioState();
    if (!st || !st.streamUrl) return;
    activeRadioStation = {
        name: st.name || 'Live-Radio',
        streamUrl: st.streamUrl,
        genre: st.genre || 'Live'
    };
    const stNameEl = document.getElementById('radioCurrentStationName');
    if (stNameEl && st.name) stNameEl.textContent = st.name;

    const genreBadge = document.getElementById('radioCurrentGenreBadge');
    if (genreBadge && st.genre) {
        genreBadge.textContent = st.genre;
        genreBadge.classList.remove('hidden');
    }
    const songEl = document.getElementById('radioCurrentSongTitle');
    if (songEl && st.songTitle) {
        songEl.textContent = st.songTitle;
    }
    const volSlider = document.getElementById('radioVolumeSlider');
    if (volSlider && typeof st.volume === 'number') {
        volSlider.value = String(st.volume);
    }
    const muteBtn = document.getElementById('radioMuteBtn');
    if (muteBtn) {
        muteBtn.textContent = (st.muted || st.volume === 0) ? '🔇' : '🔊';
    }
    const audio = getEffectiveRadioAudio();
    const isActuallyPlaying = Boolean(st.playing && audio && !audio.paused);
    updateRadioUiPlaying(isActuallyPlaying || Boolean(st.playing));
}

window.addEventListener('DOMContentLoaded', () => {
    syncRadioDockFromState();
    setInterval(syncRadioDockFromState, 2000);
});

function setRadioSleepTimer(minutes) {
    if (sleepTimerInterval) {
        clearInterval(sleepTimerInterval);
        sleepTimerInterval = null;
    }
    const cd = document.getElementById('radioSleepCountdown');
    const mins = Number(minutes);
    if (mins <= 0) {
        if (cd) cd.classList.add('hidden');
        return;
    }
    sleepEndTimestamp = Date.now() + mins * 60000;
    if (cd) cd.classList.remove('hidden');

    const updateCd = () => {
        const remSec = Math.max(0, Math.floor((sleepEndTimestamp - Date.now()) / 1000));
        if (remSec <= 0) {
            clearInterval(sleepTimerInterval);
            const host = getSharedRadioHost();
            if (host && typeof host.pause === 'function') {
                host.pause();
            } else {
                const audio = getEffectiveRadioAudio();
                if (audio && !audio.paused) {
                    audio.pause();
                }
                writeSavedRadioState({ playing: false });
            }
            updateRadioUiPlaying(false);
            if (cd) cd.textContent = '🌙 Gestoppt';
            return;
        }
        const m = Math.floor(remSec / 60);
        const s = String(remSec % 60).padStart(2, '0');
        if (cd) cd.textContent = `${m}:${s}`;
    };
    updateCd();
    sleepTimerInterval = setInterval(updateCd, 1000);
}

function quickSearchRadioTag(tag) {
    const sel = document.getElementById('rbTagSelect');
    if (sel) sel.value = tag;
    searchRadioBrowser(null);
}

async function searchRadioBrowser(ev) {
    if (ev) ev.preventDefault();
    const q       = (document.getElementById('rbQueryInput')?.value || '').trim();
    const tag     = (document.getElementById('rbTagSelect')?.value || '').trim();
    const country = (document.getElementById('rbCountrySelect')?.value || 'DE').trim();
    const box     = document.getElementById('rbSearchResults');
    if (!box) return;

    box.innerHTML = '<div class="col-span-full py-6 text-center text-xs text-slate-400">🔍 Suche passende Radiosender…</div>';

    try {
        const url = `radio.php?action=radio_search&q=${encodeURIComponent(q)}&tag=${encodeURIComponent(tag)}&country=${encodeURIComponent(country)}`;
        const res = await fetch(url);
        const d   = await res.json();

        if (!d || !d.ok || !Array.isArray(d.stations) || d.stations.length === 0) {
            box.innerHTML = '<div class="col-span-full py-6 text-center text-xs text-slate-400">Keine Sender für diese Suchkriterien gefunden.</div>';
            return;
        }

        box.innerHTML = d.stations.map((st) => {
            const safeName     = escapeHtml(st.name || 'Radio');
            const safeGenre    = escapeHtml(st.genre || st.country || 'Live');
            const safeUrl      = escapeHtml(st.stream_url || '');
            const safeCtry     = escapeHtml(st.country || 'DE');
            const bitrateBadge = st.bitrate > 0 ? `<span class="px-1.5 py-0.2 rounded bg-slate-200 dark:bg-slate-700 text-[10px] font-mono">${Number(st.bitrate)}k</span>` : '';

            return `
                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 flex items-center justify-between gap-2">
                    <button type="button"
                            data-st-name="${safeName}"
                            data-st-url="${safeUrl}"
                            data-st-genre="${safeGenre}"
                            onclick="playRadioStation(this.getAttribute('data-st-name'), this.getAttribute('data-st-url'), this.getAttribute('data-st-genre'))"
                            class="flex items-center gap-2.5 min-w-0 text-left flex-1 cursor-pointer group">
                        <span class="w-9 h-9 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-xs font-bold shrink-0 group-hover:scale-105 transition-transform">▶️</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-extrabold text-slate-900 dark:text-white truncate">${safeName}</span>
                            <span class="flex items-center gap-1.5 text-[10px] text-slate-500 dark:text-slate-400 truncate mt-0.5">
                                <span>${safeCtry}</span>
                                <span>•</span>
                                <span class="truncate">${safeGenre}</span>
                                ${bitrateBadge}
                            </span>
                        </span>
                    </button>
                    <form method="post" action="radio.php" class="shrink-0">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(RADIO_CSRF)}">
                        <input type="hidden" name="action" value="save_radio_station">
                        <input type="hidden" name="name" value="${safeName}">
                        <input type="hidden" name="stream_url" value="${safeUrl}">
                        <input type="hidden" name="genre" value="${safeGenre}">
                        <input type="hidden" name="country" value="${safeCtry}">
                        <button type="submit" title="Zu meinen Favoriten hinzufügen"
                                class="px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/70 hover:bg-amber-200 text-amber-800 dark:text-amber-300 text-[11px] font-bold cursor-pointer">
                            ⭐ Merken
                        </button>
                    </form>
                </div>
            `;
        }).join('');
    } catch (e) {
        box.innerHTML = '<div class="col-span-full py-6 text-center text-xs text-rose-500">Fehler bei der Sendersuche.</div>';
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
