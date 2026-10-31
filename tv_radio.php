<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📺 FERNSEHPROGRAMM & LIVE-EPG ZENTRALE (tv_radio.php)
 * ============================================================================
 * Eigenständiges TV-Programm-Modul für die MiniCloud:
 * - Aktuelles Fernsehprogramm (🔴 Jetzt im TV, 🍿 Heute 20:15 Uhr, 🌙 Heute 22:00 Uhr, 🎬 Highlights & Tipps)
 * - DSGVO-schonender Server-Cache (MariaDB) für EPG/RSS-Programmdaten inkl. „⏭️ Danach“-Folgesendung
 * - Lieblingssender anpinnen (automatische Sortierung nach oben + Schnellfilter)
 * - Direktlinks zu den kostenlosen Mediathek-Livestreams (ARD, ZDF, Arte, 3sat, Joyn, RTL+ u.v.m.)
 * - 1-Klick „Im Cloud-Kalender vormerken“ (trägt die Sendung direkt in calendar.php ein)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// Falls ein alter Link mit ?view=radio aufgerufen wird, direkt zum neuen Webradio-Modul weiterleiten
if (strtolower(trim((string)($_GET['view'] ?? ''))) === 'radio') {
    header('Location: radio.php');
    exit;
}

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_tv_radio'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('error', 'Das Modul „Fernsehprogramm“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// ============================================================================
// 1. DATENBANK-SCHEMA INITIALISIEREN (Auto-Migration)
// ============================================================================
function ensureTvSchema(PDO $pdo): void
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
}

ensureTvSchema($pdo);

// ============================================================================
// 2. BENUTZER-FAVORITEN & POST-AKTIONEN
// ============================================================================
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuche es erneut.');
        header("Location: tv_radio.php?slot=" . urlencode($tvFeedSlot));
        exit;
    }

    // 2a) TV-Lieblingssender umschalten
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
        header("Location: tv_radio.php?slot=" . urlencode($tvFeedSlot));
        exit;
    }

    // 2b) TV-Sendung im Cloud-Kalender vormerken
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
        header("Location: tv_radio.php?slot=" . urlencode($tvFeedSlot));
        exit;
    }
}

// ============================================================================
// 3. TV-PROGRAMM RSS/EPG ABRUF & PARSER (MIT SERVER-CACHE)
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

        $channel = 'TV';
        if (preg_match('/<td\s+class="station"[^>]*>.*?title="([^"]+)"/isu', $rowHtml, $mCh)) {
            $channel = trim(html_entity_decode($mCh[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif (preg_match('/class="stationIcon"[^>]*alt="([^"]+)"/isu', $rowHtml, $mCh2)) {
            $channel = trim(html_entity_decode($mCh2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        preg_match_all('/<td\s+class="time[^"]*"[^>]*>\s*(\d{1,2}:\d{2})/isu', $rowHtml, $timeMatches);
        $timeCurrent = $timeMatches[1][0] ?? '';
        $timeNext    = $timeMatches[1][1] ?? '';

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

        $descHtml = preg_replace('/^.*?<\/a>\s*(?:<br\s*\/?>)*/isu', '', $det1) ?? $det1;
        $descHtml = preg_replace('/<br\s*\/?>/i', ' • ', $descHtml) ?? $descHtml;
        $desc     = trim(preg_replace('/(\s*•\s*)+/u', ' • ', strip_tags(html_entity_decode($descHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '', " \t\n\r\0\x0B•");

        $img = '';
        if (preg_match('/<img\s+class="epgImage"\s+src="([^"]+)"/isu', $rowHtml, $mImg)) {
            $img = trim($mImg[1]);
        }

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
            [$chCand, $tCand] = array_map('trim', explode(':', $rawTitle, 2));
            if ($chCand !== '' && $tCand !== '') {
                $channel = $chCand;
                $title   = $tCand;
            }
        }

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

$availableChannels = [];
foreach ($tvItems as $it) {
    $ch = (string)$it['channel'];
    if ($ch !== '' && !in_array($ch, $availableChannels, true)) {
        $availableChannels[] = $ch;
    }
}

$csrfToken = getCsrfToken();
$pageTitle = '📺 Fernsehprogramm';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- 1. KOPFBEREICH                                                     -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-500 via-pink-600 to-rose-700 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    📺
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Fernsehprogramm
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-100 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                            Live-EPG &amp; Mediathek-Streams
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Was läuft gerade, heute 20:15 Uhr, 22:00 Uhr &amp; TV-Highlights – inkl. Lieblingssender-Filter und 1-Klick Kalender-Erinnerung.
                    </p>
                </div>
            </div>

            <!-- Schnellwechsel zu Webradio & Nachrichten -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if (($appSettings['enable_radio'] ?? '1') === '1'): ?>
                    <a href="radio.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors">
                        <span>📻</span>
                        <span>Zum Live-Webradio</span>
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
    </div>

    <!-- ================================================================== -->
    <!-- 2. ZEIT-SLOTS, SENDER-FILTER & LIVE-SUCHE                          -->
    <!-- ================================================================== -->
    <section class="space-y-5">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-sm space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <!-- 4 Zeit-Kategorien -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach ($tvFeeds as $slotKey => $slotCfg): ?>
                        <?php $isSlotActive = ((string)$slotKey === $tvFeedSlot); ?>
                        <a href="tv_radio.php?slot=<?= urlencode((string)$slotKey) ?>"
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $isSlotActive ? 'bg-rose-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                            <?= e($slotCfg['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Stand & Refresh -->
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <span>Stand: <?= date('H:i', (int)strtotime((string)$tvUpdatedAt)) ?> Uhr</span>
                    <a href="tv_radio.php?slot=<?= urlencode($tvFeedSlot) ?>&amp;refresh=1"
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
                                <form method="post" action="tv_radio.php?slot=<?= urlencode($tvFeedSlot) ?>" class="shrink-0">
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
                            <form method="post" action="tv_radio.php?slot=<?= urlencode($tvFeedSlot) ?>">
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

</main>

<script>
let activeTvChannelFilter = 'ALL';

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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
