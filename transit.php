<?php
declare(strict_types=1);

/**
 * MiniCloud - ÖPNV-Abfahrtsmonitor & Bahn-Radar (transit.php)
 *
 * Features:
 *  - Deutschlandweite Bahnhofs- & Haltestellensuche (Fernverkehr ICE/IC, Regionalverkehr RE/RB, S-Bahn, U-Bahn, Tram & Bus)
 *  - Digitale Live-Abfahrtstafel mit Echtzeit-Verspätungen (+Min.), Gleiswechseln & Zugausfällen
 *  - Persönliche Pendler-Favoriten (Stamm-Bahnhöfe mit 1-Klick-Umschaltung)
 *  - Interaktive Bahnhofskarte auf unserer 7,2-GB-Offline-Deutschlandkarte (germany_detail.pmtiles)
 *  - Studio-Bahnhofsansage („🔊 Abfahrten vorlesen“) mit automatischem Webradio-Audio-Ducking
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$appSettings = getAppSettings($pdo);
if (($appSettings['enable_transit'] ?? '1') !== '1') {
    setFlash('error', 'Der ÖPNV-Abfahrtsmonitor ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

// ----------------------------------------------------------------------------
// 1. DATENBANK-SCHEMA FÜR STAMM-BAHNHÖFE / HALTESTELLEN
// ----------------------------------------------------------------------------
function ensureTransitSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `transit_favorites` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `stop_id` VARCHAR(64) NOT NULL,
            `stop_name` VARCHAR(160) NOT NULL,
            `lat` DECIMAL(10,7) NOT NULL DEFAULT 50.1071,
            `lng` DECIMAL(10,7) NOT NULL DEFAULT 8.6638,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_stop` (`user_id`, `stop_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

ensureTransitSchema($pdo);

// Standard-Bahnhöfe anlegen, falls der Nutzer noch keine Favoriten hat
$stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM `transit_favorites` WHERE `user_id` = :uid');
$stmtCnt->execute([':uid' => $userId]);
if ((int)$stmtCnt->fetchColumn() === 0) {
    $defStops = [
        ['de:08111:6115', 'Stuttgart, Hauptbahnhof', 48.78408, 9.18163],
        ['de:08111:6333', 'Stuttgart, Bad Cannstatt', 48.80142, 9.21758],
        ['de:08136:6100', 'Böbingen (Rems), Bahnhof', 48.82233, 9.91242],
        ['de:08136:1000', 'Aalen, Hauptbahnhof', 48.84102, 10.09675],
        ['de:06412:10',   'Frankfurt (Main), Hauptbahnhof', 50.10714, 8.66378],
        ['de:09162:100',  'München, Hbf', 48.14023, 11.55833],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO `transit_favorites` (`user_id`, `stop_id`, `stop_name`, `lat`, `lng`) VALUES (:u, :sid, :sn, :lat, :lng)');
    foreach ($defStops as $ds) {
        $ins->execute([':u' => $userId, ':sid' => $ds[0], ':sn' => $ds[1], ':lat' => $ds[2], ':lng' => $ds[3]]);
    }
}

$cacheDir = __DIR__ . '/uploads/transit_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// ----------------------------------------------------------------------------
// 2. BUNDESWEITE DELFI / EFA & HAFAS API-FUNKTIONEN (SUCHE & LIVE-ABFAHRTEN)
// ----------------------------------------------------------------------------

/**
 * Bereinigt umgangssprachliche Bahnhofs-Suchbegriffe (z.B. „Bahnhof Böbingen an der Rems“ -> „Böbingen Rems“),
 * damit die bundesweite DELFI/EFA-Haltestellensuche auch bei Zusätzen wie „an der“, „a.d.“ oder Bindestrichen
 * zuverlässig alle passenden Bahnhöfe findet.
 *
 * @return array<int, string>
 */
function buildTransitQueryCandidates(string $rawQuery): array
{
    $q = trim((string)preg_replace('/\s+/u', ' ', $rawQuery));
    if ($q === '') {
        return [];
    }

    $candidates = [];

    // 1. Umgangssprachliche Fluss-/Ortszusätze („an der“, „a. d.“, „a.d.“) und führendes „Bahnhof“ bereinigen
    $cleaned = (string)preg_replace('/\b(?:an\s+der|a\.\s*d\.|am|im|in\s+der)\b/iu', ' ', $q);
    $cleanedNoBhf = trim((string)preg_replace('/^\s*(?:bahnhof|bhf\.?|haltestelle|s-bahnhof)\s+/iu', '', $cleaned));
    $cleanedWithSpace = trim((string)preg_replace('/\s+/u', ' ', str_replace(['-', '/', '(', ')'], ' ', $cleanedNoBhf)));

    foreach ([$cleanedNoBhf, $cleanedWithSpace, $q] as $cand) {
        $cand = trim((string)preg_replace('/\s+/u', ' ', $cand));
        if ($cand !== '' && !in_array($cand, $candidates, true)) {
            $candidates[] = $cand;
        }
    }

    return $candidates;
}

/**
 * Durchsucht das bundesweite DELFI / EFA-Haltestellenverzeichnis (inkl. Regionalbahnhöfen wie Böbingen (Rems),
 * Stuttgart-Bad Cannstatt, S-Bahn, U-Bahn & Bus) und liefert bis zu $limit passende Haltestellen.
 *
 * @return array<int, array{id: string, name: string, lat: float, lng: float, is_rail: bool, badge: string}>
 */
function searchTransitStationsList(string $query, string $cacheDir, int $limit = 10): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $cacheFile = $cacheDir . '/loclist_v2_' . md5(mb_strtolower($query, 'UTF-8')) . '.json';
    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 86400) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return array_slice($cached, 0, $limit);
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 5.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-TransitRadar/2.0\r\n",
        ],
    ]);

    $results = [];
    $seenIds = [];
    $wantsBhf = preg_match('/\b(?:bahnhof|bhf|hbf|zug|s-bahn)\b/iu', $query) === 1;

    foreach (buildTransitQueryCandidates($query) as $cand) {
        // anyObjFilter_sf=2 erzwingt reine Haltestellen/Bahnhöfe (keine Rathäuser/POIs/Straßen)
        $efaUrl = 'https://www.efa-bw.de/nvbw/XML_STOPFINDER_REQUEST?outputFormat=RapidJSON'
                . '&type_sf=any&anyObjFilter_sf=2'
                . '&name_sf=' . rawurlencode($cand)
                . '&coordOutputFormat=WGS84%5Bdd.ddddd%5D&SpEncId=0';

        $raw = @file_get_contents($efaUrl, false, $ctx);
        $data = is_string($raw) ? @json_decode($raw, true) : null;
        $locs = is_array($data['locations'] ?? null) ? $data['locations'] : [];

        foreach ($locs as $loc) {
            if (!is_array($loc) || empty($loc['id']) || empty($loc['name'])) {
                continue;
            }
            if (($loc['type'] ?? 'stop') !== 'stop') {
                continue;
            }
            $id = (string)$loc['id'];
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $name = (string)$loc['name'];
            $classes = is_array($loc['productClasses'] ?? null) ? array_map('intval', $loc['productClasses']) : [];
            // Produktklassen in EFA: 0=ICE/IC, 1=S-Bahn, 2=U-Bahn, 3/4=Stadtbahn/Tram, 5/6=Bus, 13/14/15/16=Regionalzug/MEX/IRE/RB
            $hasRail = count(array_intersect($classes, [0, 1, 13, 14, 15, 16])) > 0
                || preg_match('/\b(?:Bahnhof|Hbf|Hauptbahnhof|Bf)\b/iu', $name) === 1;
            $hasSubway = count(array_intersect($classes, [2, 3, 4])) > 0;

            $badge = $hasRail ? '🚆 Bahnhof / Zug' : ($hasSubway ? '🚇 Stadtbahn / U-Bahn' : '🚌 Haltestelle / Bus');

            $lat = isset($loc['coord'][0]) ? (float)$loc['coord'][0] : 48.78408;
            $lng = isset($loc['coord'][1]) ? (float)$loc['coord'][1] : 9.18163;

            $score = (int)($loc['matchQuality'] ?? 500);
            if (!empty($loc['isBest'])) {
                $score += 400;
            }
            if ($hasRail) {
                $score += ($wantsBhf ? 800 : 350);
            }
            if (preg_match('/\b(?:Bahnhof|Hauptbahnhof|Hbf)\b/iu', $name)) {
                $score += 250;
            }

            $results[] = [
                'id'      => $id,
                'name'    => $name,
                'lat'     => $lat,
                'lng'     => $lng,
                'is_rail' => $hasRail,
                'badge'   => $badge,
                '_score'  => $score,
            ];
        }

        if (count($results) >= 5) {
            break;
        }
    }

    if (!empty($results)) {
        usort($results, static fn(array $a, array $b): int => $b['_score'] <=> $a['_score']);
        foreach ($results as &$r) {
            unset($r['_score']);
        }
        unset($r);
        @file_put_contents($cacheFile, json_encode($results, JSON_UNESCAPED_UNICODE));
        return array_slice($results, 0, $limit);
    }

    // Fallback auf HAFAS transport.rest, falls EFA nicht erreichbar sein sollte
    $url = 'https://v6.bvg.transport.rest/locations?query=' . rawurlencode($query) . '&results=' . $limit . '&stops=true&addresses=false&poi=false';
    $raw = @file_get_contents($url, false, $ctx);
    $list = is_string($raw) ? @json_decode($raw, true) : null;
    if (is_array($list)) {
        foreach ($list as $item) {
            if (!empty($item['id']) && !empty($item['name'])) {
                $results[] = [
                    'id'      => (string)$item['id'],
                    'name'    => (string)$item['name'],
                    'lat'     => (float)($item['location']['latitude'] ?? 51.1657),
                    'lng'     => (float)($item['location']['longitude'] ?? 10.4515),
                    'is_rail' => true,
                    'badge'   => '🚆 Bahnhof / Haltestelle',
                ];
            }
        }
    }

    return array_slice($results, 0, $limit);
}

function searchTransitStation(string $query, string $cacheDir): ?array
{
    $list = searchTransitStationsList($query, $cacheDir, 1);
    return $list[0] ?? null;
}

function fetchStationDepartures(string $stopId, string $stopName, float $lat, float $lng, string $cacheDir): array
{
    $stopId = preg_replace('/[^a-zA-Z0-9:_-]/', '', $stopId) ?: 'de:08111:6115';

    // Alte BVG-Standard-IDs automatisch auf bundesweite DELFI-IDs ummappen
    $legacyMap = [
        '900550405' => 'de:08111:6115', // Stuttgart Hbf
        '900003201' => 'de:11000:900003200', // Berlin Hbf
        '900500012' => 'de:02000:10950', // Hamburg Hbf
        '900350003' => 'de:06412:10',   // Frankfurt (Main) Hbf
        '900600003' => 'de:09162:100',  // München Hbf
    ];
    $lookupId = $legacyMap[$stopId] ?? $stopId;

    $cacheFile = $cacheDir . '/dep_v2_' . md5($lookupId) . '.json';
    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 55) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['departures']) && !empty($cached['departures'])) {
            return $cached;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 6.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-TransitRadar/2.0\r\n",
        ],
    ]);

    $departures = [];
    $delayedCount = 0;
    $cancelledCount = 0;
    $tzBerlin = new DateTimeZone('Europe/Berlin');

    // 1. Primär: Bundesweiter DELFI / EFA-Abfahrtsmonitor (unterstützt alle Regionalbahnhöfe wie Böbingen & Bad Cannstatt)
    $efaDmUrl = 'https://www.efa-bw.de/nvbw/XML_DM_REQUEST?outputFormat=RapidJSON'
              . '&type_dm=any&name_dm=' . rawurlencode($lookupId)
              . '&useRealtime=1&mode=direct&limit=35&coordOutputFormat=WGS84%5Bdd.ddddd%5D';
    $rawEfa = @file_get_contents($efaDmUrl, false, $ctx);
    $efaData = is_string($rawEfa) ? @json_decode($rawEfa, true) : null;

    if (is_array($efaData)) {
        if (!empty($efaData['locations'][0]['name'])) {
            $stopName = (string)$efaData['locations'][0]['name'];
        }
        if (isset($efaData['locations'][0]['coord'][0], $efaData['locations'][0]['coord'][1])) {
            $lat = (float)$efaData['locations'][0]['coord'][0];
            $lng = (float)$efaData['locations'][0]['coord'][1];
        }

        $events = is_array($efaData['stopEvents'] ?? null) ? $efaData['stopEvents'] : [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $plannedIso = (string)($ev['departureTimePlanned'] ?? '');
            $estIso     = (string)($ev['departureTimeEstimated'] ?? $plannedIso);
            if ($plannedIso === '') {
                continue;
            }

            $plannedTs = strtotime($plannedIso) ?: time();
            $whenTs    = $estIso !== '' ? (strtotime($estIso) ?: $plannedTs) : $plannedTs;

            $dtPlanned = (new DateTimeImmutable('@' . $plannedTs))->setTimezone($tzBerlin);
            $dtActual  = (new DateTimeImmutable('@' . $whenTs))->setTimezone($tzBerlin);

            $delayMin = max(0, (int)round(($whenTs - $plannedTs) / 60));
            if ($delayMin > 0) {
                $delayedCount++;
            }

            $rtStatus = is_array($ev['realtimeStatus'] ?? null) ? $ev['realtimeStatus'] : [];
            $cancelled = in_array('CANCELLED', $rtStatus, true) || !empty($ev['isCancelled']);
            if ($cancelled) {
                $cancelledCount++;
            }

            $trans = is_array($ev['transportation'] ?? null) ? $ev['transportation'] : [];
            $lineName = trim((string)($trans['disassembledName'] ?? $trans['number'] ?? $trans['name'] ?? 'Zug'));
            $pClass = (int)($trans['product']['class'] ?? $trans['product']['iconId'] ?? 13);

            $prod = match (true) {
                in_array($pClass, [0, 14, 15], true) || stripos($lineName, 'ICE') === 0 || stripos($lineName, 'IC') === 0 => 'nationalExpress',
                $pClass === 1 || preg_match('/^S\s?\d+/i', $lineName) === 1 => 'suburban',
                in_array($pClass, [2, 3, 4], true) || preg_match('/^U\s?\d+/i', $lineName) === 1 => 'subway',
                in_array($pClass, [5, 6], true) => 'bus',
                default => 'regional',
            };

            $locProps = is_array($ev['location']['properties'] ?? null) ? $ev['location']['properties'] : [];
            $plat = trim((string)($locProps['platformName'] ?? $locProps['platform'] ?? '–'));
            $plannedPlat = trim((string)($locProps['plannedPlatformName'] ?? $plat));
            $platChanged = ($plat !== '–' && $plannedPlat !== '' && $plat !== $plannedPlat);

            $remarks = [];
            if (!empty($ev['infos']) && is_array($ev['infos'])) {
                foreach ($ev['infos'] as $inf) {
                    $txt = trim((string)($inf['content'] ?? $inf['subtitle'] ?? $inf['title'] ?? ''));
                    if ($txt !== '') {
                        $remarks[] = $txt;
                    }
                }
            }
            if (($locProps['occupancy'] ?? '') === 'MANY_SEATS') {
                $remarks[] = '🟢 Niedrige Auslastung';
            } elseif (($locProps['occupancy'] ?? '') === 'FEW_SEATS') {
                $remarks[] = '🟡 Hohe Auslastung';
            } elseif (($locProps['occupancy'] ?? '') === 'STANDING_ONLY') {
                $remarks[] = '🔴 Sehr hohe Auslastung';
            }

            $departures[] = [
                'line'         => $lineName,
                'product'      => $prod,
                'operator'     => (string)($trans['operator']['name'] ?? ''),
                'direction'    => trim((string)($trans['destination']['name'] ?? 'Endstation')),
                'planned_time' => $dtPlanned->format('H:i'),
                'actual_time'  => $dtActual->format('H:i'),
                'in_min'       => max(0, (int)round(($whenTs - time()) / 60)),
                'delay_min'    => $delayMin,
                'platform'     => $plat !== '' ? $plat : '–',
                'plat_changed' => $platChanged,
                'cancelled'    => $cancelled,
                'remarks'      => implode(' · ', array_slice(array_unique($remarks), 0, 2)),
            ];
        }
    }

    // 2. Fallback auf transport.rest, falls eine reine HAFAS-ID ohne EFA-Treffer abgefragt wurde
    if (empty($departures) && preg_match('/^\d+$/', $stopId)) {
        $url = 'https://v6.bvg.transport.rest/stops/' . rawurlencode($stopId) . '/departures?duration=120&results=30';
        $raw = @file_get_contents($url, false, $ctx);
        $data = is_string($raw) ? @json_decode($raw, true) : null;
        if (is_array($data) && isset($data['departures']) && is_array($data['departures'])) {
            foreach ($data['departures'] as $dep) {
                if (!is_array($dep)) {
                    continue;
                }
                $whenStr    = (string)($dep['when'] ?? $dep['plannedWhen'] ?? '');
                $plannedStr = (string)($dep['plannedWhen'] ?? $whenStr);
                $whenTs     = $whenStr !== '' ? strtotime($whenStr) : time();
                $plannedTs  = $plannedStr !== '' ? strtotime($plannedStr) : $whenTs;

                $delaySec   = isset($dep['delay']) && is_numeric($dep['delay']) ? (int)$dep['delay'] : 0;
                $delayMin   = (int)round($delaySec / 60);
                if ($delayMin > 0) {
                    $delayedCount++;
                }
                $cancelled  = !empty($dep['cancelled']);
                if ($cancelled) {
                    $cancelledCount++;
                }

                $prod      = (string)($dep['line']['product'] ?? 'regional');
                $prodName  = trim((string)($dep['line']['productName'] ?? ''));
                $lineName  = trim((string)($dep['line']['name'] ?? 'Zug'));
                if ($prodName !== '' && stripos($lineName, $prodName) === false && strlen($lineName) <= 5) {
                    $lineName = $prodName . ' ' . $lineName;
                }

                $departures[] = [
                    'line'         => $lineName,
                    'product'      => $prod,
                    'operator'     => (string)($dep['line']['operator']['name'] ?? ''),
                    'direction'    => trim((string)($dep['direction'] ?? 'Endstation')),
                    'planned_time' => date('H:i', (int)$plannedTs),
                    'actual_time'  => date('H:i', (int)$whenTs),
                    'in_min'       => max(0, (int)round(((int)$whenTs - time()) / 60)),
                    'delay_min'    => $delayMin,
                    'platform'     => trim((string)($dep['platform'] ?? $dep['plannedPlatform'] ?? '–')),
                    'plat_changed' => !empty($dep['platform']) && !empty($dep['plannedPlatform']) && $dep['platform'] !== $dep['plannedPlatform'],
                    'cancelled'    => $cancelled,
                    'remarks'      => '',
                ];
            }
        }
    }

    $result = [
        'stop_id'         => $stopId,
        'stop_name'       => $stopName,
        'lat'             => $lat,
        'lng'             => $lng,
        'updated_at'      => date('H:i:s'),
        'departures'      => $departures,
        'delayed_count'   => $delayedCount,
        'cancelled_count' => $cancelledCount,
    ];

    if (!empty($departures)) {
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    } elseif (is_file($cacheFile)) {
        $old = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($old)) {
            return $old;
        }
    }

    return $result;
}

// 2b. Live-Autocomplete-Endpunkt für die Haltestellensuche (?ajax_action=station_suggest&q=...)
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'station_suggest') {
    @session_write_close();
    header('Content-Type: application/json; charset=UTF-8');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q, 'UTF-8') < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    $matches = searchTransitStationsList($q, $cacheDir, 10);
    echo json_encode(['ok' => true, 'results' => $matches], JSON_UNESCAPED_UNICODE);
    exit;
}


// ----------------------------------------------------------------------------
// 3. POST-AKTIONEN (FAVORITEN-BAHNHOF HINZUFÜGEN / LÖSCHEN)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheits-Token.');
        header('Location: transit.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_fav_station') {
        $sid   = trim((string)($_POST['stop_id'] ?? ''));
        $sname = mb_substr(trim((string)($_POST['stop_name'] ?? '')), 0, 160);
        $sLat  = (float)($_POST['lat'] ?? 51.1657);
        $sLng  = (float)($_POST['lng'] ?? 10.4515);
        if ($sid !== '' && $sname !== '') {
            $stmt = $pdo->prepare('INSERT IGNORE INTO `transit_favorites` (`user_id`, `stop_id`, `stop_name`, `lat`, `lng`) VALUES (:u, :sid, :sn, :lat, :lng)');
            $stmt->execute([':u' => $userId, ':sid' => $sid, ':sn' => $sname, ':lat' => $sLat, ':lng' => $sLng]);
            setFlash('success', 'Bahnhof „' . $sname . '“ zu deinen Favoriten hinzugefügt.');
        }
        header('Location: transit.php?stop_id=' . rawurlencode($sid));
        exit;
    }

    if ($action === 'delete_fav_station') {
        $fid = (int)($_POST['fav_id'] ?? 0);
        $pdo->prepare('DELETE FROM `transit_favorites` WHERE `id` = :id AND `user_id` = :u')->execute([':id' => $fid, ':u' => $userId]);
        setFlash('success', 'Bahnhof aus den Favoriten entfernt.');
        header('Location: transit.php');
        exit;
    }
}

// ----------------------------------------------------------------------------
// 4. AKTUELLE HALTESTELLE BESTIMMEN & ABFAHRTEN LADEN
// ----------------------------------------------------------------------------
$stmtFavs = $pdo->prepare('SELECT * FROM `transit_favorites` WHERE `user_id` = :u ORDER BY `id` ASC');
$stmtFavs->execute([':u' => $userId]);
$favStations = $stmtFavs->fetchAll(PDO::FETCH_ASSOC);

$activeStop = [
    'id'   => (string)($favStations[0]['stop_id'] ?? 'de:08111:6115'),
    'name' => (string)($favStations[0]['stop_name'] ?? 'Stuttgart, Hauptbahnhof'),
    'lat'  => (float)($favStations[0]['lat'] ?? 48.78408),
    'lng'  => (float)($favStations[0]['lng'] ?? 9.18163),
];

$searchMatches = [];
$searchQuery   = trim((string)($_GET['q'] ?? ''));

if ($searchQuery !== '') {
    $searchMatches = searchTransitStationsList($searchQuery, $cacheDir, 8);
    if (!empty($searchMatches)) {
        $activeStop = $searchMatches[0];
    } else {
        setFlash('error', 'Kein Bahnhof oder keine Haltestelle zu „' . e($searchQuery) . '“ gefunden.');
    }
} elseif (!empty($_GET['stop_id'])) {
    $reqId   = trim((string)$_GET['stop_id']);
    $matched = false;
    foreach ($favStations as $fs) {
        if ((string)$fs['stop_id'] === $reqId) {
            $activeStop = [
                'id'   => (string)$fs['stop_id'],
                'name' => (string)$fs['stop_name'],
                'lat'  => (float)$fs['lat'],
                'lng'  => (float)$fs['lng'],
            ];
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $activeStop = [
            'id'   => $reqId,
            'name' => trim((string)($_GET['stop_name'] ?? $reqId)),
            'lat'  => isset($_GET['lat']) ? (float)$_GET['lat'] : 48.78408,
            'lng'  => isset($_GET['lng']) ? (float)$_GET['lng'] : 9.18163,
        ];
    }
}

$boardData  = fetchStationDepartures($activeStop['id'], $activeStop['name'], $activeStop['lat'], $activeStop['lng'], $cacheDir);
$departures = $boardData['departures'] ?? [];

// Sprecher-Text für Bahnhofsansage bauen
$speechLines = [];
foreach (array_slice($departures, 0, 5) as $d) {
    if ($d['cancelled']) {
        $speechLines[] = $d['line'] . ' nach ' . $d['direction'] . ', planmäßig ' . $d['planned_time'] . ' Uhr, fällt heute leider aus.';
    } elseif ($d['delay_min'] >= 3) {
        $speechLines[] = $d['line'] . ' nach ' . $d['direction'] . ', Abfahrt ' . $d['planned_time'] . ' Uhr von Gleis ' . $d['platform'] . ', heute circa ' . $d['delay_min'] . ' Minuten später.';
    } else {
        $speechLines[] = $d['line'] . ' nach ' . $d['direction'] . ', Abfahrt ' . $d['planned_time'] . ' Uhr pünktlich von Gleis ' . $d['platform'] . '.';
    }
}
$stationSpeechText = 'Nächste Abfahrten ab ' . $boardData['stop_name'] . ': ' . (!empty($speechLines) ? implode(' ', $speechLines) : 'Aktuell liegen keine Abfahrten vor.');

$pageTitle = 'ÖPNV-Abfahrtsmonitor & Bahn-Radar';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
.tr-wrap { max-width: 1440px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 18px; }
.tr-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); }
.tr-board { background: #091326; color: #f8fafc; border: 2px solid #1e3a8a; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.35); }
.tr-board-header { background: linear-gradient(90deg, #1e3a8a, #1d4ed8); padding: 14px 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
.tr-board-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.tr-board-table th { background: #0f172a; color: #93c5fd; text-transform: uppercase; font-size: 0.73rem; letter-spacing: 0.05em; padding: 10px 14px; text-align: left; border-bottom: 1px solid #1e3a8a; }
.tr-board-table td { padding: 11px 14px; border-bottom: 1px solid rgba(30,58,138,0.45); vertical-align: middle; }
.tr-board-table tr:hover { background: rgba(30,64,175,0.25); }
.tr-line-badge { display: inline-block; padding: 3px 9px; border-radius: 6px; font-weight: 800; font-size: 0.8rem; background: #e2e8f0; color: #0f172a; }
.tr-line-express { background: #dc2626; color: #ffffff; }
.tr-line-suburban { background: #16a34a; color: #ffffff; }
.tr-line-subway { background: #2563eb; color: #ffffff; }
.tr-map-box { height: 390px; width: 100%; border-radius: 14px; overflow: hidden; border: 1px solid var(--border); background: #0f172a; }
.tr-suggest-wrap { position: relative; display: inline-flex; }
.tr-suggest-list { position: absolute; top: calc(100% + 6px); left: 0; min-width: 340px; max-width: 440px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,0.45); z-index: 1200; overflow: hidden; display: none; }
.tr-suggest-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 9px 13px; text-decoration: none; color: var(--text-main); border-bottom: 1px solid var(--border); font-size: 0.83rem; transition: background 0.15s; }
.tr-suggest-item:last-child { border-bottom: none; }
.tr-suggest-item:hover { background: rgba(37,99,235,0.18); }
</style>

<div class="tr-wrap">
    <!-- TOP-BAR: STAMM-BAHNHÖFE & HALTESTELLENSUCHE -->
    <div class="tr-card" style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <span style="font-weight:700;font-size:0.86rem;margin-right:4px;">🚆 Meine Bahnhöfe:</span>
                <?php foreach ($favStations as $fs): ?>
                    <?php $isAct = ((string)$fs['stop_id'] === $boardData['stop_id'] || mb_strtolower((string)$fs['stop_name']) === mb_strtolower((string)$boardData['stop_name'])); ?>
                    <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:<?= $isAct ? 'rgba(37,99,235,0.22)' : 'rgba(15,23,42,0.5)' ?>;border:1px solid <?= $isAct ? '#3b82f6' : 'var(--border)' ?>;font-size:0.82rem;">
                        <a href="transit.php?stop_id=<?= rawurlencode((string)$fs['stop_id']) ?>&amp;stop_name=<?= rawurlencode((string)$fs['stop_name']) ?>&amp;lat=<?= (float)$fs['lat'] ?>&amp;lng=<?= (float)$fs['lng'] ?>" style="text-decoration:none;color:var(--text-main);font-weight:700;">
                            🚉 <?= e($fs['stop_name']) ?>
                        </a>
                        <?php if (count($favStations) > 1): ?>
                            <form method="post" style="display:inline;margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_fav_station">
                                <input type="hidden" name="fav_id" value="<?= (int)$fs['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.75rem;">✕</button>
                            </form>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <form method="get" action="transit.php" style="display:inline-flex;gap:6px;margin:0;position:relative;" autocomplete="off">
                    <div class="tr-suggest-wrap">
                        <input type="text" id="trSearchInput" name="q" value="<?= e($searchQuery) ?>" class="form-control" style="width:270px;" placeholder="z.B. Böbingen Rems, Bad Cannstatt...">
                        <div id="trSuggestList" class="tr-suggest-list"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">🔍 Suchen</button>
                </form>

                <form method="post" style="display:inline;margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="add_fav_station">
                    <input type="hidden" name="stop_id" value="<?= e($boardData['stop_id']) ?>">
                    <input type="hidden" name="stop_name" value="<?= e($boardData['stop_name']) ?>">
                    <input type="hidden" name="lat" value="<?= (float)$boardData['lat'] ?>">
                    <input type="hidden" name="lng" value="<?= (float)$boardData['lng'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">⭐ Bahnhof merken</button>
                </form>

                <button type="button" id="btnSpeakTransit" onclick="speakStationDepartures()" class="btn btn-sm btn-primary" style="background:linear-gradient(135deg,#1d4ed8,#4f46e5);border:none;">
                    🔊 Bahnhofsansage vorlesen
                </button>
            </div>
        </div>

        <?php if (count($searchMatches) > 1): ?>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding-top:8px;border-top:1px solid var(--border);font-size:0.8rem;">
                <span style="color:var(--text-muted);font-weight:600;">Gefundene Haltestellen für „<?= e($searchQuery) ?>“:</span>
                <?php foreach ($searchMatches as $sm): ?>
                    <?php $isSmAct = ((string)$sm['id'] === (string)$boardData['stop_id']); ?>
                    <a href="transit.php?stop_id=<?= rawurlencode((string)$sm['id']) ?>&amp;stop_name=<?= rawurlencode((string)$sm['name']) ?>&amp;lat=<?= (float)$sm['lat'] ?>&amp;lng=<?= (float)$sm['lng'] ?>"
                       style="text-decoration:none;padding:4px 10px;border-radius:999px;background:<?= $isSmAct ? '#2563eb' : 'rgba(148,163,184,0.14)' ?>;color:<?= $isSmAct ? '#fff' : 'var(--text-main)' ?>;font-weight:<?= $isSmAct ? '700' : '500' ?>;">
                        <?= !empty($sm['is_rail']) ? '🚆' : '🚏' ?> <?= e($sm['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- DIGITALE LIVE-ABFAHRTSTAFEL (DB-BAHNHilfSTAFEL-OPTIK) -->
    <div class="tr-board">
        <div class="tr-board-header">
            <div>
                <div style="font-size:0.74rem;text-transform:uppercase;letter-spacing:0.08em;color:#bfdbfe;">Deutsche Bahn / ÖPNV Echtzeit-Abfahrtstafel</div>
                <h2 style="margin:2px 0 0 0;font-size:1.35rem;font-weight:800;color:#ffffff;">🚉 <?= e($boardData['stop_name']) ?></h2>
            </div>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <span class="badge" style="background:rgba(15,23,42,0.55);color:#e2e8f0;font-size:0.8rem;">🕒 Stand <?= e($boardData['updated_at']) ?> Uhr</span>
                <span class="badge" style="background:<?= $boardData['delayed_count'] > 0 ? '#f59e0b' : '#16a34a' ?>;color:#fff;font-size:0.8rem;">
                    ⏱️ <?= (int)$boardData['delayed_count'] ?> verspätet
                </span>
                <?php if ($boardData['cancelled_count'] > 0): ?>
                    <span class="badge" style="background:#dc2626;color:#fff;font-size:0.8rem;">⛔ <?= (int)$boardData['cancelled_count'] ?> Ausfälle</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="tr-board-table">
                <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>Prognose</th>
                        <th>Linie / Zug</th>
                        <th>Richtung / Ziel</th>
                        <th>Gleis / Steig</th>
                        <th>Status &amp; Hinweise</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departures)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:28px;color:#94a3b8;">Aktuell keine Abfahrten in den nächsten 120 Minuten gemeldet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($departures as $dep): ?>
                        <?php
                        $pCls = 'tr-line-badge';
                        if ($dep['product'] === 'nationalExpress' || $dep['product'] === 'express' || $dep['product'] === 'national') {
                            $pCls .= ' tr-line-express';
                        } elseif ($dep['product'] === 'suburban') {
                            $pCls .= ' tr-line-suburban';
                        } elseif ($dep['product'] === 'subway') {
                            $pCls .= ' tr-line-subway';
                        }
                        ?>
                        <tr style="<?= $dep['cancelled'] ? 'opacity:0.55;text-decoration:line-through;' : '' ?>">
                            <td style="font-family:monospace;font-size:1.05rem;font-weight:800;color:#ffffff;"><?= e($dep['planned_time']) ?></td>
                            <td style="font-family:monospace;font-size:0.95rem;">
                                <?php if ($dep['cancelled']): ?>
                                    <span style="color:#f87171;font-weight:800;">Fällt aus</span>
                                <?php elseif ($dep['delay_min'] > 0): ?>
                                    <span style="color:#fbbf24;font-weight:800;"><?= e($dep['actual_time']) ?> (+<?= (int)$dep['delay_min'] ?>)</span>
                                <?php else: ?>
                                    <span style="color:#4ade80;font-weight:700;">pünktlich</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?= $pCls ?>"><?= e($dep['line']) ?></span>
                            </td>
                            <td>
                                <strong style="font-size:0.96rem;color:#f8fafc;"><?= e($dep['direction']) ?></strong>
                                <?php if ($dep['operator'] !== ''): ?>
                                    <div style="font-size:0.72rem;color:#94a3b8;"><?= e($dep['operator']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-family:monospace;font-weight:800;font-size:0.95rem;padding:3px 8px;border-radius:6px;background:<?= $dep['plat_changed'] ? '#dc2626' : 'rgba(255,255,255,0.1)' ?>;color:#fff;">
                                    <?= e($dep['platform']) ?>
                                </span>
                            </td>
                            <td style="font-size:0.76rem;color:#cbd5e1;max-width:340px;">
                                <?php if ($dep['in_min'] <= 60 && !$dep['cancelled']): ?>
                                    <span class="badge" style="background:rgba(56,189,248,0.2);color:#38bdf8;margin-right:5px;">in <?= (int)$dep['in_min'] ?> Min.</span>
                                <?php endif; ?>
                                <?= e(mb_substr($dep['remarks'], 0, 120)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BAHNHOFS-UMGEBUNGSKARTE AUF UNSERER 7,2-GB-DEUTSCHLANDKARTE -->
    <div class="tr-card">
        <h3 style="margin:0 0 10px 0;font-size:1.05rem;">🗺️ Bahnhofs-Standort auf der Offline-Deutschlandkarte (Zoom 0–18)</h3>
        <div id="transitMapCanvas" class="tr-map-box"></div>
    </div>
</div>

<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/leaflet/protomaps-leaflet.js"></script>
<script>
const TR_STOP = <?= json_encode($boardData, JSON_UNESCAPED_UNICODE) ?>;
const TR_SPEECH = <?= json_encode($stationSpeechText, JSON_UNESCAPED_UNICODE) ?>;
let savedRadioVolTransit = null;

function speakStationDepartures() {
    if (!('speechSynthesis' in window)) return;
    if (window.speechSynthesis.speaking) {
        window.speechSynthesis.cancel();
        if (savedRadioVolTransit !== null && window.McGlobalRadio) {
            window.McGlobalRadio.setVolume(savedRadioVolTransit);
            savedRadioVolTransit = null;
        }
        document.getElementById('btnSpeakTransit').innerHTML = '🔊 Bahnhofsansage vorlesen';
        return;
    }

    if (window.McGlobalRadio && window.McGlobalRadio.getState && window.McGlobalRadio.getState().playing) {
        savedRadioVolTransit = window.McGlobalRadio.getState().volume || 0.8;
        window.McGlobalRadio.setVolume(Math.min(savedRadioVolTransit, 0.18));
    }

    const utter = new SpeechSynthesisUtterance(TR_SPEECH);
    utter.lang = 'de-DE';
    document.getElementById('btnSpeakTransit').innerHTML = '⏹️ Ansage stoppen';
    const restore = () => {
        if (savedRadioVolTransit !== null && window.McGlobalRadio) {
            window.McGlobalRadio.setVolume(savedRadioVolTransit);
            savedRadioVolTransit = null;
        }
        document.getElementById('btnSpeakTransit').innerHTML = '🔊 Bahnhofsansage vorlesen';
    };
    utter.onend = restore;
    utter.onerror = restore;
    window.speechSynthesis.speak(utter);
}

document.addEventListener('DOMContentLoaded', () => {
    const sInput = document.getElementById('trSearchInput');
    const sList  = document.getElementById('trSuggestList');
    let suggestTimer = null;

    if (sInput && sList) {
        sInput.addEventListener('input', () => {
            clearTimeout(suggestTimer);
            const q = sInput.value.trim();
            if (q.length < 2) {
                sList.style.display = 'none';
                sList.innerHTML = '';
                return;
            }
            suggestTimer = setTimeout(async () => {
                try {
                    const res  = await fetch('transit.php?ajax_action=station_suggest&q=' + encodeURIComponent(q));
                    const data = await res.json();
                    const items = (data && Array.isArray(data.items)) ? data.items : [];
                    if (!items.length) {
                        sList.style.display = 'none';
                        sList.innerHTML = '';
                        return;
                    }
                    sList.innerHTML = items.map(st => {
                        const url = 'transit.php?stop_id=' + encodeURIComponent(st.id)
                            + '&stop_name=' + encodeURIComponent(st.name)
                            + '&lat=' + encodeURIComponent(st.lat)
                            + '&lng=' + encodeURIComponent(st.lng);
                        const icon = st.is_rail ? '🚆' : '🚏';
                        const badge = st.is_rail ? '<span style="font-size:0.7rem;padding:2px 6px;border-radius:4px;background:rgba(37,99,235,0.22);color:#60a5fa;font-weight:700;">Bahn / S-Bahn</span>' : '<span style="font-size:0.7rem;color:var(--text-muted);">Haltestelle</span>';
                        return `<a class="tr-suggest-item" href="${url}"><span>${icon} <strong>${st.name.replace(/</g, '&lt;')}</strong></span>${badge}</a>`;
                    }).join('');
                    sList.style.display = 'block';
                } catch (e) {}
            }, 220);
        });

        document.addEventListener('click', (ev) => {
            if (!sInput.contains(ev.target) && !sList.contains(ev.target)) {
                sList.style.display = 'none';
            }
        });
    }

    const el = document.getElementById('transitMapCanvas');
    if (!el || typeof L === 'undefined') return;
    const map = L.map('transitMapCanvas').setView([TR_STOP.lat || 51.1657, TR_STOP.lng || 10.4515], 15);
    L.tileLayer('maps.php?action=tile&s={s}&z={z}&x={x}&y={y}', { maxZoom: 18 }).addTo(map);
    if (typeof protomapsL !== 'undefined') {
        try { protomapsL.leafletLayer({ url: 'assets/maps/germany_detail.pmtiles', theme: 'dark' }).addTo(map); } catch (e) {}
    }
    L.circleMarker([TR_STOP.lat || 51.1657, TR_STOP.lng || 10.4515], {
        radius: 10, color: '#ffffff', weight: 2, fillColor: '#2563eb', fillOpacity: 0.95
    }).bindPopup(`<strong>🚉 ${TR_STOP.stop_name}</strong>`).addTo(map).openPopup();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
