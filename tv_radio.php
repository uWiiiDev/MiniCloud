<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📺📻 TV-PROGRAMM & LIVE-WEBRADIO ZENTRALE (tv_radio.php)
 * ============================================================================
 * Kombiniertes Medien-Modul für die MiniCloud:
 * 1. 📺 Aktuelles Fernsehprogramm (Jetzt im TV, 20:15 Uhr, 22:00 Uhr, Spielfilme)
 *    - DSGVO-schonender Server-Cache (MariaDB) für EPG/RSS-Programmdaten
 *    - Lieblingssender anpinnen (automatische Sortierung nach oben + Schnellfilter)
 *    - Direktlinks zu den kostenlosen Mediathek-Livestreams (ARD, ZDF, Arte, 3sat, Joyn u.v.m.)
 *    - 1-Klick „Im Cloud-Kalender vormerken“ (trägt die Sendung direkt in calendar.php ein)
 * 2. 📻 Live-Webradio-Player & Weltweite Sendersuche (> 30.000 Sender)
 *    - Durchgehender HTML5-Audio-Player (läuft beim Wechsel zwischen TV & Radio unterbrechungsfrei weiter)
 *    - Live-ICY-Titelanzeige („Was läuft gerade?“ – liest aktuellen Song & Interpret aus dem Stream)
 *    - 24 kuratierte deutsche & Genre-Top-Sender + Live-Suche über Radio-Browser API
 *    - Persönliche Favoriten-Sender & eigene Stream-URLs speichern + Sleep-Timer & MediaSession
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_tv_radio'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('error', 'Das Modul „TV-Programm & Webradio“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// ============================================================================
// 1. DATENBANK-SCHEMA INITIALISIEREN (Auto-Migration)
// ============================================================================
function ensureTvRadioSchema(PDO $pdo): void
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
        CREATE TABLE IF NOT EXISTS tv_radio_prefs (
            user_id INT NOT NULL PRIMARY KEY,
            fav_tv_channels TEXT NOT NULL,
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

ensureTvRadioSchema($pdo);

// ============================================================================
// 2. SSRF-SCHUTZ & ICY-STREAM-METADATEN-READER („WAS LÄUFT GERADE?“)
// ============================================================================
function isSafePublicHttpUrl(string $url): bool
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
    if (!isSafePublicHttpUrl($streamUrl)) {
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
            if ( stripos((string)$h, 'icy-metaint:') === 0 ) {
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
$prefStmt = $pdo->prepare("SELECT fav_tv_channels FROM tv_radio_prefs WHERE user_id = ?");
$prefStmt->execute([$userId]);
$prefRow = $prefStmt->fetch(PDO::FETCH_ASSOC);
$favTvChannels = [];
if ($prefRow && !empty($prefRow['fav_tv_channels'])) {
    $decodedFavs = json_decode((string)$prefRow['fav_tv_channels'], true);
    if (is_array($decodedFavs)) {
        $favTvChannels = array_values(array_unique(array_filter(array_map('strval', $decodedFavs))));
    }
}

$tvFeedSlot = strtolower(trim((string)($_GET['slot'] ?? $_POST['slot'] ?? 'now')));
if (!in_array($tvFeedSlot, ['now', '2015', '2200', 'movies'], true)) {
    $tvFeedSlot = 'now';
}
$activeMainView = strtolower(trim((string)($_GET['view'] ?? $_POST['view'] ?? 'tv')));
if (!in_array($activeMainView, ['tv', 'radio'], true)) {
    $activeMainView = 'tv';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuche es erneut.');
        header("Location: tv_radio.php?view={$activeMainView}&slot={$tvFeedSlot}");
        exit;
    }

    // 4a) TV-Lieblingssender umschalten
    if ($action === 'toggle_fav_channel') {
        $channelName = trim((string)($_POST['channel'] ?? ''));
        if ($channelName !== '') {
            if (in_array($channelName, $favTvChannels, true)) {
                $favTvChannels = array_values(array_diff($favTvChannels, [$channelName]));
                setFlash('success', "Sender „{$channelName}“ aus deinen Lieblingssendern entfernt.");
            } else {
                $favTvChannels[] = $channelName;
                setFlash('success', "⭐ Sender „{$channelName}“ als Lieblingssender angepinnt!");
            }
            $upPref = $pdo->prepare("
                INSERT INTO tv_radio_prefs (user_id, fav_tv_channels, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE fav_tv_channels = VALUES(fav_tv_channels), updated_at = NOW()
            ");
            $upPref->execute([$userId, json_encode($favTvChannels, JSON_UNESCAPED_UNICODE)]);
        }
        header("Location: tv_radio.php?view=tv&slot={$tvFeedSlot}");
        exit;
    }

    // 4b) TV-Sendung im Cloud-Kalender vormerken
    if ($action === 'add_tv_to_calendar') {
        $tvTitle   = mb_substr(trim((string)($_POST['tv_title'] ?? 'TV-Sendung')), 0, 160);
        $tvChannel = mb_substr(trim((string)($_POST['tv_channel'] ?? 'TV')), 0, 60);
        $tvTime    = trim((string)($_POST['tv_time'] ?? '20:15'));
        $tvDesc    = mb_substr(trim((string)($_POST['tv_desc'] ?? '')), 0, 1000);

        $datePart = date('Y-m-d');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $tvTime, $mTime)) {
            $hh = str_pad($mTime[1], 2, '0', STR_PAD_LEFT);
            $mm = $mTime[2];
            $startAt = "{$datePart} {$hh}:{$mm}:00";
        } else {
            $startAt = date('Y-m-d H:i:00');
        }
        $startTs = strtotime($startAt) ?: time();
        $endAt   = date('Y-m-d H:i:s', $startTs + 5400); // Standard 90 Min.

        try {
            $stmtCal = $pdo->prepare("
                INSERT INTO calendar_events
                    (user_id, title, description, location, start_at, end_at, all_day, recurrence, color, is_private)
                VALUES
                    (:uid, :title, :descr, :loc, :start_at, :end_at, 0, 'none', '#e11d48', 0)
            ");
            $stmtCal->execute([
                ':uid'      => $userId,
                ':title'    => "📺 {$tvChannel}: {$tvTitle}",
                ':descr'    => $tvDesc !== '' ? $tvDesc : "TV-Erinnerung ({$tvChannel}, {$tvTime} Uhr)",
                ':loc'      => $tvChannel,
                ':start_at' => $startAt,
                ':end_at'   => $endAt,
            ]);
            setFlash('success', "📅 „{$tvTitle}“ ({$tvChannel}, {$tvTime} Uhr) wurde in deinen Cloud-Kalender eingetragen!");
        } catch (Throwable) {
            setFlash('error', 'Kalender-Eintrag konnte nicht gespeichert werden (bitte öffne einmalig das Kalender-Modul).');
        }
        header("Location: tv_radio.php?view=tv&slot={$tvFeedSlot}");
        exit;
    }

    // 4c) Eigenen oder gefundenen Radiosender als Favorit speichern
    if ($action === 'save_radio_station') {
        $stName    = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
        $stUrl     = mb_substr(trim((string)($_POST['stream_url'] ?? '')), 0, 500);
        $stGenre   = mb_substr(trim((string)($_POST['genre'] ?? '')), 0, 110);
        $stCountry = strtoupper(mb_substr(trim((string)($_POST['country'] ?? 'DE')), 0, 10));
        $stLogo    = mb_substr(trim((string)($_POST['logo_url'] ?? '')), 0, 500);

        if ($stName === '' || !preg_match('#^https?://#i', $stUrl)) {
            setFlash('error', 'Bitte gib einen gültigen Sendernamen und eine Stream-URL (http/https) an.');
        } else {
            // Prüfen, ob schon vorhanden
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
        header("Location: tv_radio.php?view=radio");
        exit;
    }

    // 4d) Gespeicherten Favoriten-Radiosender löschen
    if ($action === 'delete_radio_station') {
        $stId = (int)($_POST['station_id'] ?? 0);
        if ($stId > 0) {
            $del = $pdo->prepare("DELETE FROM tv_radio_stations WHERE id = ? AND user_id = ?");
            $del->execute([$stId, $userId]);
            setFlash('success', 'Radiosender wurde aus deinen Favoriten entfernt.');
        }
        header("Location: tv_radio.php?view=radio");
        exit;
    }
}

// ============================================================================
// 5. KURATIERTE TOP-WEBRADIOSENDER & BENUTZER-FAVORITEN
// ============================================================================
$presetRadioStations = [
    // Öffentlich-Rechtlich & Popwellen
    ['name' => '1LIVE (WDR)',          'genre' => 'Pop, Charts & Newcomer',    'country' => 'DE', 'icon' => '🔥', 'stream_url' => 'https://wdr-1live-live.icecastssl.wdr.de/wdr/1live/live/mp3/128/stream.mp3'],
    ['name' => 'WDR 2 Rheinland',      'genre' => 'Pop, Infos & Bundesliga',   'country' => 'DE', 'icon' => '📻', 'stream_url' => 'https://wdr-wdr2-rheinland.icecastssl.wdr.de/wdr/wdr2/rheinland/mp3/128/stream.mp3'],
    ['name' => 'WDR 4',                'genre' => 'Lieblingshits & Klassiker', 'country' => 'DE', 'icon' => '🎶', 'stream_url' => 'https://wdr-wdr4-live.icecastssl.wdr.de/wdr/wdr4/live/mp3/128/stream.mp3'],
    ['name' => 'SWR3',                 'genre' => 'Pop, Comedy & Aktuelles',   'country' => 'DE', 'icon' => '⚡', 'stream_url' => 'https://liveradio.swr.de/sw282p3/swr3/play.mp3'],
    ['name' => 'Bayern 1',             'genre' => '70er, 80er & Kulthits',     'country' => 'DE', 'icon' => '🥨', 'stream_url' => 'https://streams.br.de/bayern1obb_2.m3u'],
    ['name' => 'BAYERN 3',             'genre' => 'Hits & Neue Musik',         'country' => 'DE', 'icon' => '🎧', 'stream_url' => 'https://dispatcher.rndfnk.com/br/br3/live/mp3/mid'],
    ['name' => 'NDR 2',                'genre' => 'Norddeutschlands Popwelle', 'country' => 'DE', 'icon' => '🌊', 'stream_url' => 'https://icecast.ndr.de/ndr/ndr2/niedersachsen/mp3/128/stream.mp3'],
    ['name' => 'hr3',                  'genre' => 'Pop & Hits aus Hessen',     'country' => 'DE', 'icon' => '🎵', 'stream_url' => 'https://dispatcher.rndfnk.com/hr/hr3/live/mp3/high'],
    ['name' => 'MDR JUMP',             'genre' => 'Pop & Dance',               'country' => 'DE', 'icon' => '🚀', 'stream_url' => 'https://mdr-284280-1.sslcast.mdr.de/mdr/284280/1/mp3/high/stream.mp3'],
    ['name' => 'Deutschlandfunk',      'genre' => 'Nachrichten, Politik & Kultur', 'country' => 'DE', 'icon' => '📰', 'stream_url' => 'https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3'],
    // Privat- & Genre-Sender
    ['name' => 'ANTENNE BAYERN',       'genre' => 'Besten Hits & Charts',      'country' => 'DE', 'icon' => '☀️', 'stream_url' => 'https://stream.antenne.de/antenne/stream/mp3'],
    ['name' => 'ROCK ANTENNE',         'genre' => 'Classic & Modern Rock',     'country' => 'DE', 'icon' => '🎸', 'stream_url' => 'https://stream.rockantenne.de/rockantenne/stream/mp3'],
    ['name' => 'RADIO BOB!',           'genre' => 'Rock, Metal & Hardrock',    'country' => 'DE', 'icon' => '🤘', 'stream_url' => 'https://streams.radiobob.de/bob-live/mp3-192/mediaplayer'],
    ['name' => '80s80s Radio',         'genre' => '100 % 80er Kult-Hits',      'country' => 'DE', 'icon' => '🕹️', 'stream_url' => 'https://streams.80s80s.de/web/mp3-192/'],
    ['name' => '90s90s Hits',          'genre' => 'Eurodance & 90er Hits',     'country' => 'DE', 'icon' => '💿', 'stream_url' => 'https://streams.90s90s.de/pop/mp3-192/'],
    ['name' => 'sunshine live',        'genre' => 'Electronic, Techno & House','country' => 'DE', 'icon' => '🎛️', 'stream_url' => 'https://stream.sunshine-live.de/live/mp3-192'],
    ['name' => 'bigFM',                'genre' => 'Hip-Hop, Urban & Charts',   'country' => 'DE', 'icon' => '🎤', 'stream_url' => 'https://streams.bigfm.de/bigfm-deutschland-128-mp3'],
    ['name' => 'Klassik Radio',        'genre' => 'Klassik & Filmmusik',       'country' => 'DE', 'icon' => '🎻', 'stream_url' => 'https://stream.klassikradio.de/live/mp3-192'],
    ['name' => 'Absolut relax',        'genre' => 'Soft Pop & Entspannung',    'country' => 'DE', 'icon' => '☕', 'stream_url' => 'https://absolut-relax.live-sm.absolutradio.de/absolut-relax/stream/mp3'],
    ['name' => 'Radio Schlagerparadies','genre'=> 'Deutscher Schlager & Party','country' => 'DE', 'icon' => '🥳', 'stream_url' => 'https://irmedia.streamabc.net/irm-schlagerparadieslive-mp3-128-9657315'],
    ['name' => 'Radio Swiss Jazz',     'genre' => 'Jazz, Soul & Blues (Werbefrei)', 'country' => 'CH', 'icon' => '🎷', 'stream_url' => 'https://stream.srg-ssr.ch/m/rsj/mp3_128'],
    ['name' => 'SomaFM Groove Salad',  'genre' => 'Ambient, Chillout & Lo-Fi', 'country' => 'US', 'icon' => '🌴', 'stream_url' => 'https://ice2.somafm.com/groovesalad-128-mp3'],
];

// Korrektur für Bayern 1 direkten MP3-Stream statt .m3u
$presetRadioStations[4]['stream_url'] = 'https://dispatcher.rndfnk.com/br/br1/obb/mp3/mid';

$myStationsStmt = $pdo->prepare("SELECT * FROM tv_radio_stations WHERE user_id = ? ORDER BY name ASC");
$myStationsStmt->execute([$userId]);
$myFavoriteStations = $myStationsStmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================================
// 6. TV-PROGRAMM RSS/EPG ABRUF & PARSER (MIT SERVER-CACHE)
// ============================================================================
$tvFeeds = [
    'now'    => [
        'label' => '🔴 Jetzt im TV',
        'urls'  => [
            'https://www.klack.de/fernsehprogramm/was-laeuft-gerade/0/-1/free.html',
            'https://www.klack.de/fernsehprogramm/was-laeuft-gerade/0/0/all.html',
            'https://www.tvspielfilm.de/tv-programm/rss/jetzt.xml',
        ],
        'ttl'   => 180,
    ],
    '2015'   => [
        'label' => '🍿 Heute 20:15 Uhr',
        'urls'  => [
            'https://www.klack.de/fernsehprogramm/2015-im-tv/0/-1/free.html',
            'https://www.klack.de/xml/2015RSS.xml',
            'https://www.tvspielfilm.de/tv-programm/rss/heute2015.xml',
        ],
        'ttl'   => 600,
    ],
    '2200'   => [
        'label' => '🌙 Heute 22:00 Uhr',
        'urls'  => [
            'https://www.klack.de/fernsehprogramm/2200-im-tv/0/-1/free.html',
            'https://www.tvspielfilm.de/tv-programm/rss/heute2200.xml',
        ],
        'ttl'   => 600,
    ],
    'movies' => [
        'label' => '🎬 Highlights & Tipps',
        'urls'  => [
            'https://www.klack.de/xml/topRSS.xml',
            'https://www.tvspielfilm.de/tv-programm/rss/filme.xml',
        ],
        'ttl'   => 900,
    ],
];

// Direktlinks zu offiziellen Live-TV-Streams bekannter Sender
$channelLiveStreams = [
    'Das Erste'       => 'https://www.ardmediathek.de/live',
    'ARD'             => 'https://www.ardmediathek.de/live',
    'ZDF'             => 'https://www.zdf.de/live-tv',
    'ZDFneo'          => 'https://www.zdf.de/live-tv',
    'ZDFinfo'         => 'https://www.zdf.de/live-tv',
    '3sat'            => 'https://www.3sat.de/programm/3sat-livestream-100.html',
    'ARTE'            => 'https://www.arte.tv/de/live/',
    'Arte'            => 'https://www.arte.tv/de/live/',
    'PHOENIX'         => 'https://www.ardmediathek.de/live',
    'KiKA'            => 'https://www.kika.de/sehen/livestream-kika-100',
    'WDR'             => 'https://www.ardmediathek.de/live',
    'NDR'             => 'https://www.ardmediathek.de/live',
    'BR'              => 'https://www.ardmediathek.de/live',
    'SWR'             => 'https://www.ardmediathek.de/live',
    'MDR'             => 'https://www.ardmediathek.de/live',
    'hr'              => 'https://www.ardmediathek.de/live',
    'rbb'             => 'https://www.ardmediathek.de/live',
    'ONE'             => 'https://www.ardmediathek.de/live',
    'tagesschau24'    => 'https://www.ardmediathek.de/live',
    'ARD-alpha'       => 'https://www.ardmediathek.de/live',
    'ProSieben'       => 'https://www.joyn.de/live-tv/prosieben',
    'PRO 7'           => 'https://www.joyn.de/live-tv/prosieben',
    'SAT.1'           => 'https://www.joyn.de/live-tv/sat1',
    'Sat.1'           => 'https://www.joyn.de/live-tv/sat1',
    'Kabel Eins'      => 'https://www.joyn.de/live-tv/kabel-eins',
    'kabel eins'      => 'https://www.joyn.de/live-tv/kabel-eins',
    'ProSieben Maxx'  => 'https://www.joyn.de/live-tv/prosieben-maxx',
    'SIXX'            => 'https://www.joyn.de/live-tv/sixx',
    'sixx'            => 'https://www.joyn.de/live-tv/sixx',
    'SAT.1 Gold'      => 'https://www.joyn.de/live-tv/sat1-gold',
    'DMAX'            => 'https://www.joyn.de/live-tv/dmax',
    'TELE 5'          => 'https://www.joyn.de/live-tv/tele-5',
    'Tele 5'          => 'https://www.joyn.de/live-tv/tele-5',
    'WELT'            => 'https://www.joyn.de/live-tv/welt',
    'Eurosport 1'     => 'https://www.joyn.de/live-tv/eurosport-1',
    'Comedy Central'  => 'https://www.joyn.de/live-tv/comedy-central',
    'RTL'             => 'https://plus.rtl.de/video-tv/live-tv/rtl-1',
    'VOX'             => 'https://plus.rtl.de/video-tv/live-tv/vox-4',
    'RTL Zwei'        => 'https://plus.rtl.de/video-tv/live-tv/rtlzwei-3',
    'RTL II'          => 'https://plus.rtl.de/video-tv/live-tv/rtlzwei-3',
    'NITRO'           => 'https://plus.rtl.de/video-tv/live-tv/nitro-2',
    'RTLup'           => 'https://plus.rtl.de/video-tv/live-tv/rtlup-10',
    'n-tv'            => 'https://www.n-tv.de/mediathek/livestream/',
    'SUPER RTL'       => 'https://plus.rtl.de/video-tv/live-tv/super-rtl-7',
];

/**
 * Lädt eine URL per cURL oder Stream-Context mit Browser-User-Agent.
 */
function fetchRemoteTextUrl(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 7,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($res) && $res !== '' && $code >= 200 && $code < 300) {
            return $res;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 7,
                'header'  => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36\r\nAccept-Language: de-DE,de;q=0.9\r\n",
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') {
            return $res;
        }
    }
    return null;
}

/**
 * Parst Klack.de EPG-HTML-Tabellen (Jetzt im TV, 20:15 Uhr, 22:00 Uhr) inklusive Folgesendung („Danach“).
 */
function parseKlackEpgHtml(string $html): array
{
    $items = [];
    if (!preg_match_all('/<tr\s+class="(?:oddRow|evenRow)"[^>]*>(.*?)<\/tr>/isu', $html, $rowMatches)) {
        return [];
    }

    foreach ($rowMatches[1] as $rowHtml) {
        if (!str_contains($rowHtml, 'class="station"')) {
            continue;
        }

        // 1. Sendername & Senderlogo
        $channel = 'TV';
        if (preg_match('/<td\s+class="station"[^>]*>.*?title="([^"]+)"/isu', $rowHtml, $mCh)) {
            $channel = trim(html_entity_decode($mCh[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif (preg_match('/class="stationIcon"[^>]*alt="([^"]+)"/isu', $rowHtml, $mCh2)) {
            $channel = trim(html_entity_decode($mCh2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // 2. Uhrzeiten im Row finden (1. = aktuelle Sendung, 2. = Folgesendung)
        preg_match_all('/<td\s+class="time[^"]*"[^>]*>\s*(\d{1,2}:\d{2})/isu', $rowHtml, $timeMatches);
        $timeCurrent = $timeMatches[1][0] ?? '';
        $timeNext    = $timeMatches[1][1] ?? '';

        // 3. Details-Spalten (1. = aktuelle Sendung, 2. = Folgesendung)
        preg_match_all('/<td\s+class="details"[^>]*>\s*<div\s+class="content">(.*?)<\/div>\s*<\/td>/isu', $rowHtml, $detailMatches);
        if (empty($detailMatches[1][0])) {
            continue;
        }

        $det1 = $detailMatches[1][0];
        $title = '';
        $link  = '';
        if (preg_match('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/isu', $det1, $mLink)) {
            $rawHref = trim($mLink[1]);
            $link    = str_starts_with($rawHref, 'http') ? $rawHref : ('https://www.klack.de' . $rawHref);
            $title   = trim(strip_tags(html_entity_decode($mLink[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if ($title === '') {
            continue;
        }

        // Beschreibung ohne den ersten Titel-Link aufbereiten
        $descHtml = preg_replace('/^.*?<\/a>\s*(?:<br\s*\/?>)*/isu', '', $det1) ?? $det1;
        $descHtml = preg_replace('/<br\s*\/?>/i', ' • ', $descHtml) ?? $descHtml;
        $desc     = trim(preg_replace('/(\s*•\s*)+/u', ' • ', strip_tags(html_entity_decode($descHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '', " \t\n\r\0\x0B•");

        // 4. Vorschaubild (epgImage)
        $img = '';
        if (preg_match('/<img\s+class="epgImage"\s+src="([^"]+)"/isu', $rowHtml, $mImg)) {
            $img = trim($mImg[1]);
        }

        // 5. Folgesendung („Danach“)
        $nextTitle = '';
        if (!empty($detailMatches[1][1]) && preg_match('/<a\s+[^>]*>(.*?)<\/a>/isu', $detailMatches[1][1], $mNext)) {
            $nextTitle = trim(strip_tags(html_entity_decode($mNext[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        $items[] = [
            'time'        => $timeCurrent,
            'channel'     => $channel,
            'title'       => $title,
            'description' => mb_substr($desc, 0, 420),
            'image'       => $img,
            'link'        => $link,
            'next_time'   => $timeNext,
            'next_title'  => $nextTitle,
        ];
    }

    return $items;
}

/**
 * Parst RSS-XML-Feeds (sowohl Klack.de XML als auch TV-Spielfilm XML).
 */
function parseTvRssXml(string $rawXml): array
{
    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false || !isset($xml->channel->item)) {
        return [];
    }

    $items = [];
    foreach ($xml->channel->item as $item) {
        $rawTitle = trim((string)$item->title);
        $rawDesc  = (string)$item->description;
        $link     = trim((string)$item->link);
        $img      = '';

        if (isset($item->enclosure['url'])) {
            $img = trim((string)$item->enclosure['url']);
        } elseif (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawDesc, $mImg)) {
            $img = trim($mImg[1]);
        }

        $timeStr = '';
        $channel = 'TV';
        $title   = $rawTitle;

        // Variante 1: TV Spielfilm ("20:15 | Das Erste | Klein gegen Groß")
        if (str_contains($rawTitle, '|')) {
            $parts = array_map('trim', explode('|', $rawTitle, 3));
            if (count($parts) === 3) {
                $timeStr = $parts[0];
                $channel = $parts[1];
                $title   = $parts[2];
            } elseif (count($parts) === 2) {
                $channel = $parts[0];
                $title   = $parts[1];
            }
        } elseif (str_contains($rawTitle, ':')) {
            // Variante 2: Klack RSS ("Das Erste: Klein gegen Groß")
            [$chCand, $tCand] = array_map('trim', explode(':', $rawTitle, 2));
            if ($chCand !== '' && $tCand !== '') {
                $channel = $chCand;
                $title   = $tCand;
            }
        }

        // Uhrzeit aus Klack-Description ("20:15 Uhr - <b>...</b>") oder dc:date extrahieren
        if ($timeStr === '' && preg_match('/^\s*(\d{1,2}:\d{2})\s*Uhr\s*-\s*(?:<b>.*?<\/b>)?/isu', $rawDesc, $mT)) {
            $timeStr = $mT[1];
            $rawDesc = preg_replace('/^\s*\d{1,2}:\d{2}\s*Uhr\s*-\s*(?:<b>.*?<\/b>)?\s*(?:<br\s*\/?>)*/isu', '', $rawDesc) ?? $rawDesc;
        }

        $desc = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');

        $items[] = [
            'time'        => $timeStr,
            'channel'     => $channel,
            'title'       => $title,
            'description' => mb_substr($desc, 0, 420),
            'image'       => $img,
            'link'        => $link,
            'next_time'   => '',
            'next_title'  => '',
        ];
    }

    return $items;
}

function fetchTvProgramSlot(PDO $pdo, string $slotKey, array $feedMeta, bool $forceRefresh = false): array
{
    $cacheKey = 'tv_epg_' . $slotKey;
    $stmt     = $pdo->prepare("SELECT payload, updated_at FROM tv_radio_cache WHERE cache_key = ?");
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cached && !$forceRefresh) {
        $age = time() - (int)strtotime((string)$cached['updated_at']);
        if ($age >= 0 && $age <= (int)$feedMeta['ttl']) {
            $decoded = json_decode((string)$cached['payload'], true);
            if (is_array($decoded) && !empty($decoded)) {
                return ['items' => $decoded, 'updated_at' => (string)$cached['updated_at']];
            }
        }
    }

    $urls = $feedMeta['urls'] ?? [];
    foreach ($urls as $url) {
        $raw = fetchRemoteTextUrl((string)$url);
        if ($raw === null) {
            continue;
        }

        $items = str_ends_with(strtolower((string)$url), '.xml')
            ? parseTvRssXml($raw)
            : parseKlackEpgHtml($raw);

        if (!empty($items)) {
            $now = date('Y-m-d H:i:s');
            $up  = $pdo->prepare("
                INSERT INTO tv_radio_cache (cache_key, payload, updated_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)
            ");
            $up->execute([$cacheKey, json_encode($items, JSON_UNESCAPED_UNICODE), $now]);
            return ['items' => $items, 'updated_at' => $now];
        }
    }

    if ($cached) {
        $decoded = json_decode((string)$cached['payload'], true);
        if (is_array($decoded) && !empty($decoded)) {
            return ['items' => $decoded, 'updated_at' => (string)$cached['updated_at']];
        }
    }

    return ['items' => [], 'updated_at' => date('Y-m-d H:i:s')];
}

$forceTvRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$tvProgramRes   = fetchTvProgramSlot($pdo, $tvFeedSlot, $tvFeeds[$tvFeedSlot], $forceTvRefresh);
$tvItems        = $tvProgramRes['items'];
$tvUpdatedAt    = $tvProgramRes['updated_at'];

// Lieblingssender automatisch nach oben sortieren
usort($tvItems, static function (array $a, array $b) use ($favTvChannels): int {
    $aFav = in_array($a['channel'], $favTvChannels, true) ? 1 : 0;
    $bFav = in_array($b['channel'], $favTvChannels, true) ? 1 : 0;
    if ($aFav !== $bFav) {
        return $bFav <=> $aFav;
    }
    return 0;
});

// Liste aller im aktuellen Slot vertretenen Sender
$availableChannels = [];
foreach ($tvItems as $it) {
    $ch = (string)$it['channel'];
    if ($ch !== '' && !in_array($ch, $availableChannels, true)) {
        $availableChannels[] = $ch;
    }
}

$csrfToken = getCsrfToken();
$pageTitle = '📺📻 TV-Programm & Live-Webradio';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- 1. KOPFBEREICH & DURCHGEHENDER LIVE-WEBRADIO-PLAYER (DOCK)         -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500 via-pink-600 to-indigo-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    📺
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            TV-Programm &amp; Live-Webradio
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                            Live-EPG &amp; 30.000+ Radiosender
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Aktuelles Fernsehprogramm mit Mediathek-Livestreams &amp; Kalender-Merker sowie durchlaufendes Internetradio.
                    </p>
                </div>
            </div>

            <!-- Haupt-Umschalter: Fernsehprogramm vs. Webradio (Client-seitig für unterbrechungsfreien Radio-Ton!) -->
            <div class="inline-flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700 self-start lg:self-auto">
                <button type="button" id="btnSwitchTv" onclick="switchTvRadioMainTab('tv')"
                        class="px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer <?= $activeMainView === 'tv' ? 'bg-white dark:bg-slate-900 text-rose-600 dark:text-rose-400 shadow-sm' : 'text-slate-600 dark:text-slate-300' ?>">
                    <span>📺</span>
                    <span>Fernsehprogramm</span>
                </button>
                <button type="button" id="btnSwitchRadio" onclick="switchTvRadioMainTab('radio')"
                        class="px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer <?= $activeMainView === 'radio' ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm' : 'text-slate-600 dark:text-slate-300' ?>">
                    <span>📻</span>
                    <span>Live-Webradio</span>
                    <span id="radioLiveBadgeTop" class="hidden px-1.5 py-0.2 rounded-full text-[10px] font-extrabold bg-emerald-500 text-white animate-pulse">ON AIR</span>
                </button>
            </div>
        </div>

        <!-- DURCHGEHENDER WEBRADIO-PLAYER (bleibt auch beim Blättern im TV-Programm aktiv) -->
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
                            <span id="radioCurrentSongTitle" class="truncate font-medium">Bereit zum Abspielen – läuft auch beim Wechsel zum TV-Programm weiter</span>
                        </div>
                    </div>
                </div>

                <!-- Rechts: Lautstärke, Sleep-Timer & Schnellwechsel -->
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
    <!-- 2. ANSICHT A: 📺 AKTUELLES FERNSEHPROGRAMM                         -->
    <!-- ================================================================== -->
    <section id="sectionTvProgram" class="space-y-5 <?= $activeMainView === 'tv' ? '' : 'hidden' ?>">

        <!-- Zeit-Slots + Sender-Filter + Live-Suche -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-sm space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <!-- 4 Zeit-Kategorien -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach ($tvFeeds as $slotKey => $slotCfg): ?>
                        <?php $isSlotActive = ((string)$slotKey === $tvFeedSlot); ?>
                        <a href="tv_radio.php?view=tv&amp;slot=<?= urlencode((string)$slotKey) ?>"
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $isSlotActive ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                            <?= e($slotCfg['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Stand & Refresh -->
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span>Stand: <?= date('H:i', (int)strtotime((string)$tvUpdatedAt)) ?> Uhr</span>
                    <a href="tv_radio.php?view=tv&amp;slot=<?= urlencode($tvFeedSlot) ?>&amp;refresh=1"
                       class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-slate-700 text-rose-600 dark:text-rose-400 font-bold">
                        🔄 Aktualisieren
                    </a>
                </div>
            </div>

            <!-- Filter nach Sender & Freitextsuche -->
            <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" onclick="filterTvChannel('ALL')" data-tv-ch-btn="ALL"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-900 dark:bg-white text-white dark:text-slate-900 cursor-pointer">
                        Alle Sender (<?= count($tvItems) ?>)
                    </button>
                    <?php if (!empty($favTvChannels)): ?>
                        <button type="button" onclick="filterTvChannel('FAV')" data-tv-ch-btn="FAV"
                                class="px-2.5 py-1 rounded-lg text-xs font-bold bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border border-amber-300/60 dark:border-amber-800 cursor-pointer">
                            ⭐ Meine Lieblingssender (<?= count($favTvChannels) ?>)
                        </button>
                    <?php endif; ?>
                    <select id="tvChannelDropdown" onchange="filterTvChannel(this.value)"
                            aria-label="Nach einzelnem Sender filtern"
                            class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer">
                        <option value="ALL">📺 Einzelnen Sender wählen…</option>
                        <?php foreach ($availableChannels as $chName): ?>
                            <option value="<?= e($chName) ?>"><?= in_array($chName, $favTvChannels, true) ? '⭐ ' : '' ?><?= e($chName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="w-full sm:w-64">
                    <input type="text" id="tvSearchInput" oninput="applyTvProgramFilters()"
                           placeholder="Sendung, Film oder Thema filtern…"
                           class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-rose-500">
                </div>
            </div>
        </div>

        <!-- TV-Sendungen Raster -->
        <?php if (empty($tvItems)): ?>
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-2">
                <div class="text-3xl">📺</div>
                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Programmdaten konnten gerade nicht geladen werden.</p>
                <p class="text-xs text-slate-500">Bitte klicke oben rechts auf „🔄 Aktualisieren“.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="tvProgramGrid">
                <?php foreach ($tvItems as $item): ?>
                    <?php
                    $chName    = (string)$item['channel'];
                    $isFavCh   = in_array($chName, $favTvChannels, true);
                    $liveUrl   = $channelLiveStreams[$chName] ?? '';
                    $searchHay = mb_strtolower($chName . ' ' . $item['title'] . ' ' . $item['description'], 'UTF-8');
                    ?>
                    <article class="bg-white dark:bg-slate-900 rounded-2xl border <?= $isFavCh ? 'border-amber-400/80 dark:border-amber-600/70 ring-1 ring-amber-400/30' : 'border-slate-200/80 dark:border-slate-800' ?> shadow-sm overflow-hidden flex flex-col justify-between transition-all hover:shadow-md"
                             data-tv-card
                             data-channel="<?= e($chName) ?>"
                             data-fav="<?= $isFavCh ? '1' : '0' ?>"
                             data-search="<?= e($searchHay) ?>">
                        <div>
                            <!-- Kopfzeile: Uhrzeit + Sendername + Lieblings-Stern -->
                            <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <?php if ($item['time'] !== ''): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-rose-600 text-white font-mono text-xs font-extrabold shrink-0">
                                            <?= e($item['time']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                        <?= e($chName) ?>
                                    </span>
                                    <?php if ($isFavCh): ?>
                                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.2 rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">Favorit</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Stern-Button für Lieblingssender -->
                                <form method="post" action="tv_radio.php?view=tv&amp;slot=<?= urlencode($tvFeedSlot) ?>" class="shrink-0">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="toggle_fav_channel">
                                    <input type="hidden" name="channel" value="<?= e($chName) ?>">
                                    <button type="submit"
                                            title="<?= $isFavCh ? e($chName) . ' aus Lieblingssendern entfernen' : e($chName) . ' als Lieblingssender anpinnen' ?>"
                                            class="p-1 rounded-lg hover:scale-110 transition-transform cursor-pointer <?= $isFavCh ? 'text-amber-500' : 'text-slate-300 dark:text-slate-600 hover:text-amber-400' ?>">
                                        <?= $isFavCh ? '⭐' : '☆' ?>
                                    </button>
                                </form>
                            </div>

                            <!-- Inhalt: Vorschaubild + Sendungstitel + Beschreibung -->
                            <div class="p-4 flex gap-3.5">
                                <?php if ($item['image'] !== ''): ?>
                                    <img src="<?= e($item['image']) ?>" alt=""
                                         loading="lazy" referrerpolicy="no-referrer"
                                         class="w-24 h-16 sm:w-28 sm:h-20 rounded-xl object-cover shrink-0 bg-slate-100 dark:bg-slate-800 border border-slate-200/60 dark:border-slate-700">
                                <?php endif; ?>
                                <div class="min-w-0 flex-1 space-y-1">
                                    <h3 class="text-sm font-extrabold text-slate-900 dark:text-white leading-snug">
                                        <?= e($item['title']) ?>
                                    </h3>
                                    <?php if ($item['description'] !== ''): ?>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 line-clamp-3 leading-relaxed">
                                            <?= e($item['description']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <?php if (!empty($item['next_title'])): ?>
                                        <p class="pt-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 truncate">
                                            ⏭️ Danach<?= !empty($item['next_time']) ? ' ' . e((string)$item['next_time']) : '' ?>: <?= e((string)$item['next_title']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Fußzeile: Live-Stream & Im Cloud-Kalender vormerken -->
                        <div class="px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2">
                            <?php if ($liveUrl !== ''): ?>
                                <a href="<?= e($liveUrl) ?>" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-rose-50 dark:bg-rose-950/60 hover:bg-rose-100 text-rose-700 dark:text-rose-300 text-[11px] font-bold transition-colors">
                                    <span>▶️</span>
                                    <span>Live-Stream</span>
                                </a>
                            <?php elseif ($item['link'] !== ''): ?>
                                <a href="<?= e($item['link']) ?>" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">
                                    <span>ℹ️ Details</span>
                                </a>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>

                            <!-- 1-Klick Kalender-Erinnerung -->
                            <form method="post" action="tv_radio.php?view=tv&amp;slot=<?= urlencode($tvFeedSlot) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="add_tv_to_calendar">
                                <input type="hidden" name="tv_title" value="<?= e($item['title']) ?>">
                                <input type="hidden" name="tv_channel" value="<?= e($chName) ?>">
                                <input type="hidden" name="tv_time" value="<?= e($item['time'] ?: '20:15') ?>">
                                <input type="hidden" name="tv_desc" value="<?= e($item['description']) ?>">
                                <button type="submit"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-[11px] font-semibold cursor-pointer"
                                        title="Sendung als Erinnerung in meinen Cloud-Kalender eintragen">
                                    <span>📅</span>
                                    <span>Im Kalender merken</span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ================================================================== -->
    <!-- 3. ANSICHT B: 📻 LIVE-WEBRADIO & WELTWEITE SENDERSUCHE             -->
    <!-- ================================================================== -->
    <section id="sectionWebRadio" class="space-y-6 <?= $activeMainView === 'radio' ? '' : 'hidden' ?>">

        <!-- A) MEINE FAVORITEN-RADIOSENDER (falls vorhanden) -->
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
            <form id="customRadioFormBox" method="post" action="tv_radio.php?view=radio" class="hidden p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
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
                    Du hast noch keine eigenen Favoriten-Sender gespeichert. Klicke bei einem Sender auf „⭐ Merken“, um ihn hier anzupinnen.
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
                            <form method="post" action="tv_radio.php?view=radio" class="shrink-0">
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

        <!-- B) BELIEBTESTE DEUTSCHE & GENRE-SENDER (SOFORT-START) -->
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
                        <form method="post" action="tv_radio.php?view=radio" class="shrink-0">
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

        <!-- C) WELTWEITE LIVE-SENDERSUCHE (COMMUNITY RADIO BROWSER > 30.000 SENDER) -->
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
const TV_RADIO_CSRF = <?= json_encode($csrfToken) ?>;
const DEFAULT_RADIO_STATION = <?= json_encode($presetRadioStations[0]) ?>;

let activeRadioStation = null;
let icyMetaTimer       = null;
let sleepTimerInterval = null;
let sleepEndTimestamp  = 0;
let activeTvChannelFilter = 'ALL';

// 1. Umschalter zwischen TV-Programm und Webradio (ohne Seitenreload, damit Radio weiterläuft!)
function switchTvRadioMainTab(tab) {
    const tvSec  = document.getElementById('sectionTvProgram');
    const radSec = document.getElementById('sectionWebRadio');
    const btnTv  = document.getElementById('btnSwitchTv');
    const btnRad = document.getElementById('btnSwitchRadio');

    if (tab === 'radio') {
        tvSec?.classList.add('hidden');
        radSec?.classList.remove('hidden');
        if (btnRad) btnRad.className = 'px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm';
        if (btnTv)  btnTv.className  = 'px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer text-slate-600 dark:text-slate-300';
    } else {
        radSec?.classList.add('hidden');
        tvSec?.classList.remove('hidden');
        if (btnTv)  btnTv.className  = 'px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer bg-white dark:bg-slate-900 text-rose-600 dark:text-rose-400 shadow-sm';
        if (btnRad) btnRad.className = 'px-4 py-2 rounded-lg text-xs sm:text-sm font-bold transition-all flex items-center gap-2 cursor-pointer text-slate-600 dark:text-slate-300';
    }
}

// 2. TV-Programm Filter (Sender & Textsuche)
function filterTvChannel(ch) {
    activeTvChannelFilter = ch;
    const dd = document.getElementById('tvChannelDropdown');
    if (dd && ch !== 'FAV') {
        dd.value = ch;
    }
    applyTvProgramFilters();
}

function applyTvProgramFilters() {
    const q = (document.getElementById('tvSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('[data-tv-card]').forEach((card) => {
        const ch  = card.getAttribute('data-channel') || '';
        const fav = card.getAttribute('data-fav') === '1';
        const hay = card.getAttribute('data-search') || '';

        let matchChannel = true;
        if (activeTvChannelFilter === 'FAV') {
            matchChannel = fav;
        } else if (activeTvChannelFilter !== 'ALL') {
            matchChannel = (ch === activeTvChannelFilter);
        }

        const matchQuery = (q === '' || hay.includes(q));
        card.classList.toggle('hidden', !(matchChannel && matchQuery));
    });
}

// 3. Webradio-Player Steuerung
function playRadioStation(name, streamUrl, genre) {
    const audio = document.getElementById('globalRadioAudio');
    if (!audio) return;

    activeRadioStation = { name, streamUrl, genre: genre || 'Live-Radio' };

    document.getElementById('radioCurrentStationName').textContent = name;
    const genreBadge = document.getElementById('radioCurrentGenreBadge');
    if (genreBadge) {
        genreBadge.textContent = genre || 'Live';
        genreBadge.classList.remove('hidden');
    }
    document.getElementById('radioCurrentSongTitle').textContent = 'Verbinde mit Live-Stream…';

    audio.src = streamUrl;
    audio.volume = (Number(document.getElementById('radioVolumeSlider')?.value || 85)) / 100;
    audio.play().then(() => {
        updateRadioUiPlaying(true);
        fetchCurrentIcySongTitle();
        if (icyMetaTimer) clearInterval(icyMetaTimer);
        icyMetaTimer = setInterval(fetchCurrentIcySongTitle, 25000);
    }).catch(() => {
        document.getElementById('radioCurrentSongTitle').textContent = 'Stream konnte nicht direkt gestartet werden (bitte erneut auf ▶️ klicken).';
        updateRadioUiPlaying(false);
    });
}

function toggleRadioPlayback() {
    const audio = document.getElementById('globalRadioAudio');
    if (!audio) return;

    if (!activeRadioStation) {
        playRadioStation(DEFAULT_RADIO_STATION.name, DEFAULT_RADIO_STATION.stream_url, DEFAULT_RADIO_STATION.genre);
        return;
    }

    if (audio.paused) {
        audio.play().then(() => updateRadioUiPlaying(true)).catch(() => {});
    } else {
        audio.pause();
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
    const audio = document.getElementById('globalRadioAudio');
    if (!audio) return;
    audio.muted  = false;
    audio.volume = Math.max(0, Math.min(1, Number(val) / 100));
    const muteBtn = document.getElementById('radioMuteBtn');
    if (muteBtn) muteBtn.textContent = audio.volume === 0 ? '🔇' : '🔊';
}

function toggleRadioMute() {
    const audio = document.getElementById('globalRadioAudio');
    if (!audio) return;
    audio.muted = !audio.muted;
    const muteBtn = document.getElementById('radioMuteBtn');
    if (muteBtn) muteBtn.textContent = audio.muted ? '🔇' : '🔊';
}

async function fetchCurrentIcySongTitle() {
    const audio = document.getElementById('globalRadioAudio');
    if (!audio || audio.paused || !activeRadioStation) return;

    try {
        const res  = await fetch('tv_radio.php?action=icy_meta&url=' + encodeURIComponent(activeRadioStation.streamUrl));
        const data = await res.json();
        const lbl  = document.getElementById('radioCurrentSongTitle');
        if (!lbl) return;

        if (data && data.ok && data.title) {
            lbl.textContent = data.title;
        } else {
            lbl.textContent = 'Live- Übertragung • ' + activeRadioStation.name + ' (' + (activeRadioStation.genre || 'Live') + ')';
        }
    } catch (e) {}
}

// 4. Sleep-Timer
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
            const audio = document.getElementById('globalRadioAudio');
            if (audio && !audio.paused) {
                audio.pause();
                updateRadioUiPlaying(false);
            }
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

// 5. Radio-Browser Live-Suche
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
        const url = `tv_radio.php?action=radio_search&q=${encodeURIComponent(q)}&tag=${encodeURIComponent(tag)}&country=${encodeURIComponent(country)}`;
        const res = await fetch(url);
        const d   = await res.json();

        if (!d || !d.ok || !Array.isArray(d.stations) || d.stations.length === 0) {
            box.innerHTML = '<div class="col-span-full py-6 text-center text-xs text-slate-400">Keine Sender für diese Suchkriterien gefunden.</div>';
            return;
        }

        box.innerHTML = d.stations.map((st) => {
            const safeName   = escapeHtml(st.name || 'Radio');
            const safeGenre  = escapeHtml(st.genre || st.country || 'Live');
            const safeUrl    = escapeHtml(st.stream_url || '');
            const safeCtry   = escapeHtml(st.country || 'DE');
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
                    <form method="post" action="tv_radio.php?view=radio" class="shrink-0">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(TV_RADIO_CSRF)}">
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
