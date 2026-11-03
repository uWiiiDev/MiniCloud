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
        ['900003201', 'Berlin Hauptbahnhof', 52.52508, 13.36940],
        ['900550405', 'Stuttgart, Hauptbahnhof', 48.78408, 9.18163],
        ['900500012', 'Hamburg, Hauptbahnhof', 53.55350, 10.00626],
        ['900350003', 'Frankfurt (Main) Hauptbahnhof', 50.10714, 8.66378],
        ['900600003', 'München Hbf', 48.14023, 11.55833],
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
// 2. HAFAS / TRANSPORT.REST API-FUNKTIONEN (SUCHE & LIVE-ABFAHRTEN)
// ----------------------------------------------------------------------------
function searchTransitStation(string $query, string $cacheDir): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }

    $cacheFile = $cacheDir . '/loc_' . md5(mb_strtolower($query, 'UTF-8')) . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 86400) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['id'])) {
            return $cached;
        }
    }

    $url = 'https://v6.bvg.transport.rest/locations?query=' . rawurlencode($query) . '&results=5&stops=true&addresses=false&poi=false';
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 5.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-TransitRadar/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $list = is_string($raw) ? @json_decode($raw, true) : null;
    if (is_array($list)) {
        foreach ($list as $item) {
            if (!empty($item['id']) && !empty($item['name'])) {
                $station = [
                    'id'   => (string)$item['id'],
                    'name' => (string)$item['name'],
                    'lat'  => (float)($item['location']['latitude'] ?? 51.1657),
                    'lng'  => (float)($item['location']['longitude'] ?? 10.4515),
                ];
                @file_put_contents($cacheFile, json_encode($station, JSON_UNESCAPED_UNICODE));
                return $station;
            }
        }
    }
    return null;
}

function fetchStationDepartures(string $stopId, string $stopName, float $lat, float $lng, string $cacheDir): array
{
    $stopId = preg_replace('/[^a-zA-Z0-9:_-]/', '', $stopId) ?: '900550405';
    $cacheFile = $cacheDir . '/dep_' . md5($stopId) . '.json';

    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 60) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['departures'])) {
            return $cached;
        }
    }

    $url = 'https://v6.bvg.transport.rest/stops/' . rawurlencode($stopId) . '/departures?duration=120&results=30';
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 6.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-TransitRadar/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? @json_decode($raw, true) : null;

    $departures = [];
    $delayedCount = 0;
    $cancelledCount = 0;

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

            $remarks = [];
            if (!empty($dep['remarks']) && is_array($dep['remarks'])) {
                foreach ($dep['remarks'] as $rm) {
                    if (!empty($rm['text'])) {
                        $remarks[] = trim((string)$rm['text']);
                    }
                }
            }

            if (isset($dep['stop']['location']['latitude'])) {
                $lat = (float)$dep['stop']['location']['latitude'];
                $lng = (float)$dep['stop']['location']['longitude'];
            }
            if (!empty($dep['stop']['name'])) {
                $stopName = (string)$dep['stop']['name'];
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
                'remarks'      => implode(' · ', array_slice($remarks, 0, 2)),
            ];
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
    'id'   => (string)($favStations[0]['stop_id'] ?? '900550405'),
    'name' => (string)($favStations[0]['stop_name'] ?? 'Stuttgart, Hauptbahnhof'),
    'lat'  => (float)($favStations[0]['lat'] ?? 48.78408),
    'lng'  => (float)($favStations[0]['lng'] ?? 9.18163),
];

if (!empty($_GET['q'])) {
    $found = searchTransitStation((string)$_GET['q'], $cacheDir);
    if ($found !== null) {
        $activeStop = $found;
    } else {
        setFlash('error', 'Kein Bahnhof zu „' . e((string)$_GET['q']) . '“ gefunden.');
    }
} elseif (!empty($_GET['stop_id'])) {
    $reqId = trim((string)$_GET['stop_id']);
    foreach ($favStations as $fs) {
        if ((string)$fs['stop_id'] === $reqId) {
            $activeStop = [
                'id'   => (string)$fs['stop_id'],
                'name' => (string)$fs['stop_name'],
                'lat'  => (float)$fs['lat'],
                'lng'  => (float)$fs['lng'],
            ];
            break;
        }
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
</style>

<div class="tr-wrap">
    <!-- TOP-BAR: STAMM-BAHNHÖFE & HALTESTELLENSUCHE -->
    <div class="tr-card" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <span style="font-weight:700;font-size:0.86rem;margin-right:4px;">🚆 Meine Bahnhöfe:</span>
            <?php foreach ($favStations as $fs): ?>
                <?php $isAct = ((string)$fs['stop_id'] === $boardData['stop_id']); ?>
                <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:<?= $isAct ? 'rgba(37,99,235,0.22)' : 'rgba(15,23,42,0.5)' ?>;border:1px solid <?= $isAct ? '#3b82f6' : 'var(--border)' ?>;font-size:0.82rem;">
                    <a href="transit.php?stop_id=<?= rawurlencode((string)$fs['stop_id']) ?>" style="text-decoration:none;color:var(--text-main);font-weight:700;">
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
            <form method="get" action="transit.php" style="display:inline-flex;gap:6px;margin:0;">
                <input type="text" name="q" class="form-control" style="width:230px;" placeholder="Bahnhof / Haltestelle suchen...">
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
