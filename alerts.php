<?php
declare(strict_types=1);

/**
 * MiniCloud - Bevölkerungsschutz-, NINA/Pegel- & Erdbeben-Zentrale (alerts.php)
 *
 * Features:
 *  - Amtliche Gefahrenmeldungen des Bundesamts für Bevölkerungsschutz & Katastrophenhilfe (BBK / NINA API:
 *    MoWaS, KATWARN, BIWAPP & Länderübergreifendes Hochwasserportal LHP)
 *  - Live-Flusspegelstände der Wasserstraßen- und Schifffahrtsverwaltung des Bundes (PegelOnline WSV)
 *    für Rhein, Donau, Elbe, Mosel, Main, Neckar & Weser (in cm inkl. Hochwasser-Status)
 *  - Seismologie / Erdbeben-Monitor für Deutschland & Mitteleuropa (D-A-CH)
 *  - Interaktive Deutschland-Lagekarte auf unserer 7,2-GB-Offline-Vektorkarte (germany_detail.pmtiles)
 *  - Persönliche BBK-Notfall- & Krisenvorsorge-Checkliste (100 % offlinefähig)
 *  - Radio-Warndurchsage mit automatischem Webradio-Audio-Ducking
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$appSettings = getAppSettings($pdo);
if (($appSettings['enable_alerts'] ?? '1') !== '1') {
    setFlash('error', 'Die Bevölkerungsschutz- & Pegel-Zentrale ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

// ----------------------------------------------------------------------------
// 1. DATENBANK-SCHEMA FÜR PERSÖNLICHE BBK-NOTFALL-CHECKLISTE
// ----------------------------------------------------------------------------
function ensureAlertsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `alert_checklist` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `category` VARCHAR(64) NOT NULL DEFAULT 'Vorrat',
            `item_title` VARCHAR(180) NOT NULL,
            `is_checked` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_alert_chk_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

ensureAlertsSchema($pdo);

// Standard-BBK-Vorsorge-Checkliste anlegen, falls noch leer
$stmtChkCnt = $pdo->prepare('SELECT COUNT(*) FROM `alert_checklist` WHERE `user_id` = :u');
$stmtChkCnt->execute([':u' => $userId]);
if ((int)$stmtChkCnt->fetchColumn() === 0) {
    $bbkDefaults = [
        ['Wasser & Ernährung', 'Trinkwasser-Vorrat: 2 Liter pro Person und Tag (für 10 Tage)'],
        ['Wasser & Ernährung', 'Haltbare Lebensmittel (Konserven, Nudeln, Reis, Haferflocken)'],
        ['Technik & Licht', 'Kurbelradio oder Batterieradio (UKW/DAB+) für amtliche Durchsagen'],
        ['Technik & Licht', 'Taschenlampen, Ersatzbatterien, Kerzen & Feuerzeuge'],
        ['Technik & Licht', 'Geladene Powerbank & Solar-Ladegerät für Smartphones'],
        ['Medizin & Hygiene', 'Vollständige Hausapotheke, persönliche Medikamente & Erste-Hilfe-Set'],
        ['Dokumente & Bargeld', 'Notgepäck-Dokumentenmappe (Ausweise, Urkunden, Policen + Cloud-Tresor-Backup)'],
        ['Dokumente & Bargeld', 'Ausreichend Bargeld-Reserve in kleinen Scheinen & Münzen'],
    ];
    $insChk = $pdo->prepare('INSERT INTO `alert_checklist` (`user_id`, `category`, `item_title`, `is_checked`) VALUES (:u, :c, :t, 0)');
    foreach ($bbkDefaults as $bd) {
        $insChk->execute([':u' => $userId, ':c' => $bd[0], ':t' => $bd[1]]);
    }
}

$cacheDir = __DIR__ . '/uploads/alerts_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// ----------------------------------------------------------------------------
// 2. LIVE-ABRUF: BBK NINA (MOWAS / KATWARN / BIWAPP / LHP), PEGELONLINE & ERDBEBEN
// ----------------------------------------------------------------------------
function fetchNinaAlerts(string $cacheDir): array
{
    $cacheFile = $cacheDir . '/nina_alerts.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 300) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $channels = [
        'mowas'   => 'BBK MoWaS',
        'katwarn' => 'KATWARN',
        'biwapp'  => 'BIWAPP',
        'lhp'     => 'Hochwasserportal (LHP)',
    ];

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 4.5,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-CivilProtection/1.0\r\n",
        ],
    ]);

    // Bundesland-Schwerpunkte aus ARS/Kennung ableiten für Karten-Pins
    $stateCoords = [
        'SH' => [54.2194, 9.6961], 'HH' => [53.5511, 9.9937], 'NI' => [52.6367, 9.8451],
        'HB' => [53.0793, 8.8017], 'NW' => [51.4332, 7.6616], 'HE' => [50.6521, 9.1624],
        'RP' => [49.9129, 7.4500], 'BW' => [48.6616, 9.3501], 'BY' => [48.7904, 11.4979],
        'SL' => [49.3964, 6.9817], 'BE' => [52.5200, 13.4050], 'BB' => [52.4125, 12.5316],
        'MV' => [53.6127, 12.4296], 'SN' => [51.1045, 13.2017], 'ST' => [51.9503, 11.6923],
        'TH' => [50.9010, 11.0348],
    ];

    $alerts = [];
    foreach ($channels as $chKey => $chLabel) {
        $url = 'https://warnung.bund.de/api31/' . $chKey . '/mapData.json';
        $raw = @file_get_contents($url, false, $ctx);
        $list = is_string($raw) ? @json_decode($raw, true) : null;
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id    = (string)($item['id'] ?? '');
            $title = trim((string)($item['i18nTitle']['de'] ?? $item['i18nTitle']['en'] ?? 'Amtliche Gefahrenmeldung'));
            $sev   = (string)($item['severity'] ?? 'Minor');
            $date  = (string)($item['startDate'] ?? '');
            $ts    = $date !== '' ? strtotime($date) : time();

            $lat = 51.1657 + ((crc32($id) % 400) - 200) * 0.008;
            $lng = 10.4515 + ((crc32($title) % 400) - 200) * 0.01;
            if (preg_match('/DE-([A-Z]{2})/', $id, $m) && isset($stateCoords[$m[1]])) {
                $lat = $stateCoords[$m[1]][0] + (($idx % 7) - 3) * 0.06;
                $lng = $stateCoords[$m[1]][1] + (($idx % 5) - 2) * 0.08;
            }

            $alerts[] = [
                'id'       => $id,
                'source'   => $chLabel,
                'title'    => $title,
                'severity' => $sev,
                'date_fmt' => date('d.m.Y H:i', (int)$ts),
                'lat'      => round($lat, 4),
                'lng'      => round($lng, 4),
            ];
        }
    }

    @file_put_contents($cacheFile, json_encode($alerts, JSON_UNESCAPED_UNICODE));
    return $alerts;
}

function fetchPegelStations(string $cacheDir): array
{
    $cacheFile = $cacheDir . '/pegel_wsv.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 600) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }

    $url = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/stations.json?includeTimeseries=true&includeCurrentMeasurement=true&waters=RHEIN,DONAU,ELBE,MOSEL,NECKAR,MAIN,WESER';
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 6.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\nUser-Agent: MiniCloud-CivilProtection/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $list = is_string($raw) ? @json_decode($raw, true) : null;
    $stations = [];

    // Bekannte Haupt-Pegel-Städte bevorzugen
    $famous = ['KONSTANZ', 'MAXAU', 'MANNHEIM', 'MAINZ', 'KOBLENZ', 'BONN', 'KÖLN', 'DÜSSELDORF', 'DUISBURG-RUHRORT', 'EMMERICH', 'PASSAU', 'REGENSBURG', 'INGOLSTADT', 'ULM', 'DRESDEN', 'MAGDEBURG', 'HAMBURG-ST. PAULI', 'TRIER', 'COCHEM', 'HEIDELBERG', 'PLOCKINGEN', 'STUTTGART', 'FRANKFURT', 'WÜRZBURG', 'BREMEN'];

    if (is_array($list)) {
        foreach ($list as $st) {
            if (!is_array($st) || empty($st['timeseries'][0]['currentMeasurement'])) {
                continue;
            }
            $name  = strtoupper(trim((string)($st['longname'] ?? $st['shortname'] ?? '')));
            $water = strtoupper(trim((string)($st['water']['longname'] ?? '')));
            $meas  = $st['timeseries'][0]['currentMeasurement'];
            $valCm = isset($meas['value']) ? (float)$meas['value'] : 0.0;
            if ($valCm <= 0) {
                continue;
            }

            $state = (string)($meas['stateMnwMhw'] ?? 'normal');
            $lat   = isset($st['latitude']) ? (float)$st['latitude'] : 0.0;
            $lng   = isset($st['longitude']) ? (float)$st['longitude'] : 0.0;

            $isFamous = in_array($name, $famous, true) || $state === 'high';
            if (!$isFamous && count($stations) >= 36) {
                continue;
            }

            $stations[] = [
                'name'       => ucwords(mb_strtolower($name, 'UTF-8')),
                'river'      => ucwords(mb_strtolower($water, 'UTF-8')),
                'cm'         => (int)round($valCm),
                'state'      => $state, // low, normal, high, commented
                'updated_at' => !empty($meas['timestamp']) ? date('H:i', (int)strtotime((string)$meas['timestamp'])) : date('H:i'),
                'lat'        => $lat,
                'lng'        => $lng,
            ];
        }
    }

    // Hochwasser-Pegel zuerst sortieren
    usort($stations, static function (array $a, array $b): int {
        $scoreA = ($a['state'] === 'high' ? 2 : ($a['state'] === 'low' ? 1 : 0));
        $scoreB = ($b['state'] === 'high' ? 2 : ($b['state'] === 'low' ? 1 : 0));
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }
        return strcmp($a['river'] . $a['name'], $b['river'] . $b['name']);
    });

    $stations = array_slice($stations, 0, 32);
    if (!empty($stations)) {
        @file_put_contents($cacheFile, json_encode($stations, JSON_UNESCAPED_UNICODE));
    }
    return $stations;
}

function fetchCentralEuropeQuakes(string $cacheDir): array
{
    $cacheFile = $cacheDir . '/quakes_dach.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 900) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = 'https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minlatitude=45.5&maxlatitude=55.5&minlongitude=5.0&maxlongitude=16.5&minmagnitude=1.8&limit=15';
    $ctx = stream_context_create(['http' => ['timeout' => 5.0, 'ignore_errors' => true, 'user_agent' => 'MiniCloud/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? @json_decode($raw, true) : null;

    $quakes = [];
    if (is_array($data) && !empty($data['features']) && is_array($data['features'])) {
        foreach ($data['features'] as $f) {
            $props  = $f['properties'] ?? [];
            $coords = $f['geometry']['coordinates'] ?? [];
            if (count($coords) >= 2) {
                $tsMs = (int)($props['time'] ?? 0);
                $quakes[] = [
                    'mag'      => round((float)($props['mag'] ?? 0.0), 1),
                    'place'    => (string)($props['place'] ?? 'Mitteleuropa'),
                    'depth_km' => round((float)($coords[2] ?? 10.0), 1),
                    'time_fmt' => $tsMs > 0 ? date('d.m.Y H:i', (int)round($tsMs / 1000)) : '',
                    'lat'      => (float)$coords[1],
                    'lng'      => (float)$coords[0],
                ];
            }
        }
    }
    @file_put_contents($cacheFile, json_encode($quakes, JSON_UNESCAPED_UNICODE));
    return $quakes;
}


// ----------------------------------------------------------------------------
// 3. POST-AKTIONEN (NOTFALL-CHECKLISTE ABHAKEN / ERGÄNZEN)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheits-Token.');
        header('Location: alerts.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_check_item') {
        $cid = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare('UPDATE `alert_checklist` SET `is_checked` = 1 - `is_checked` WHERE `id` = :id AND `user_id` = :u')
            ->execute([':id' => $cid, ':u' => $userId]);
        header('Location: alerts.php#sec-checklist');
        exit;
    }

    if ($action === 'add_check_item') {
        $cat   = mb_substr(trim((string)($_POST['category'] ?? 'Vorrat')), 0, 64);
        $title = mb_substr(trim((string)($_POST['item_title'] ?? '')), 0, 180);
        if ($title !== '') {
            $pdo->prepare('INSERT INTO `alert_checklist` (`user_id`, `category`, `item_title`, `is_checked`) VALUES (:u, :c, :t, 0)')
                ->execute([':u' => $userId, ':c' => $cat, ':t' => $title]);
            setFlash('success', 'Vorsorge-Punkt zur Notfall-Checkliste hinzugefügt.');
        }
        header('Location: alerts.php#sec-checklist');
        exit;
    }

    if ($action === 'delete_check_item') {
        $cid = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare('DELETE FROM `alert_checklist` WHERE `id` = :id AND `user_id` = :u')
            ->execute([':id' => $cid, ':u' => $userId]);
        header('Location: alerts.php#sec-checklist');
        exit;
    }
}

$ninaAlerts    = fetchNinaAlerts($cacheDir);
$pegelStations = fetchPegelStations($cacheDir);
$quakes        = fetchCentralEuropeQuakes($cacheDir);

$highWaterCount = 0;
foreach ($pegelStations as $ps) {
    if ($ps['state'] === 'high') {
        $highWaterCount++;
    }
}

$stmtChk = $pdo->prepare('SELECT * FROM `alert_checklist` WHERE `user_id` = :u ORDER BY `category` ASC, `id` ASC');
$stmtChk->execute([':u' => $userId]);
$checklist = $stmtChk->fetchAll(PDO::FETCH_ASSOC);
$chkDone = 0;
foreach ($checklist as $ci) {
    if ((int)$ci['is_checked'] === 1) {
        $chkDone++;
    }
}
$chkPct = !empty($checklist) ? (int)round(($chkDone / count($checklist)) * 100) : 0;

// Sprecher-Text für Radio-Bevölkerungsschutz-Durchsage
$alertSpeechParts = [];
foreach (array_slice($ninaAlerts, 0, 3) as $na) {
    $alertSpeechParts[] = $na['source'] . ' meldet: ' . $na['title'] . '.';
}
if ($highWaterCount > 0) {
    $alertSpeechParts[] = 'An ' . $highWaterCount . ' deutschen Flusspegeln wird aktuell erhöhter Wasserstand gemeldet.';
}
$alertSpeechText = 'Amtlicher Bevölkerungsschutz- und Pegelbericht: ' . (!empty($alertSpeechParts) ? implode(' ', $alertSpeechParts) : 'Aktuell liegen keine bundesweiten Katastrophenwarnungen vor.');

$pageTitle = 'Bevölkerungsschutz-, NINA/Pegel- & Erdbeben-Zentrale';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
.al-wrap { max-width: 1440px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 18px; }
.al-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); }
.al-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: 12px; }
.al-kpi { background: rgba(15,23,42,0.45); border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
.al-main-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px; }
@media (max-width: 1024px) { .al-main-grid { grid-template-columns: 1fr; } }
.al-map-box { height: 500px; width: 100%; border-radius: 14px; overflow: hidden; border: 1px solid var(--border); background: #0f172a; }
</style>

<div class="al-wrap">
    <!-- TOP-BANNER -->
    <div class="al-card" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;background:linear-gradient(135deg, rgba(220,38,38,0.14), rgba(15,23,42,0.85));border-color:rgba(239,68,68,0.35);">
        <div>
            <h2 style="margin:0;font-size:1.25rem;display:flex;align-items:center;gap:8px;">
                <span>🚨 Bevölkerungsschutz-, NINA/Pegel- &amp; Erdbeben-Zentrale</span>
                <span class="badge" style="background:#dc2626;color:#fff;font-size:0.72rem;">BBK NINA · WSV PEGEL · USGS</span>
            </h2>
            <div class="text-muted" style="font-size:0.82rem;margin-top:3px;">
                Amtliche Gefahrenmeldungen (MoWaS, KATWARN, BIWAPP, LHP), Echtzeit-Flusspegelstände deutscher Wasserstraßen &amp; Erdbeben-Monitor
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button type="button" id="btnSpeakAlert" onclick="speakCivilAlerts()" class="btn btn-sm btn-primary" style="background:linear-gradient(135deg,#dc2626,#b91c1c);border:none;">
                🔊 Warnlage im Radio vorlesen
            </button>
            <a href="weather.php" class="btn btn-sm btn-secondary">⛅ Zum DWD-Unwetterradar</a>
        </div>
    </div>

    <!-- 4 KPIS -->
    <div class="al-kpi-grid">
        <div class="al-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🚨 Amtliche NINA-Meldungen</div>
            <div style="font-size:1.45rem;font-weight:800;color:<?= count($ninaAlerts) > 0 ? '#f87171' : '#4ade80' ?>;margin-top:4px;"><?= count($ninaAlerts) ?> aktiv</div>
        </div>
        <div class="al-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🌊 Überwachte WSV-Flusspegel</div>
            <div style="font-size:1.45rem;font-weight:800;color:#38bdf8;margin-top:4px;"><?= count($pegelStations) ?> Stationen</div>
        </div>
        <div class="al-kpi">
            <div class="text-muted" style="font-size:0.75rem;">⚠️ Hochwasser-Meldungen</div>
            <div style="font-size:1.45rem;font-weight:800;color:<?= $highWaterCount > 0 ? '#fb923c' : '#4ade80' ?>;margin-top:4px;"><?= $highWaterCount ?> erhöht</div>
        </div>
        <div class="al-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🎒 Deine BBK-Notfallvorsorge</div>
            <div style="font-size:1.45rem;font-weight:800;color:#a78bfa;margin-top:4px;"><?= $chkPct ?> % bereit (<?= $chkDone ?>/<?= count($checklist) ?>)</div>
        </div>
    </div>

    <!-- HAUPTBEREICH: LAGEKARTE (LINKS) + AMTLICHE MELDUNGEN & ERDBEBEN (RECHTS) -->
    <div class="al-main-grid">
        <div class="al-card">
            <h3 style="margin:0 0 6px 0;font-size:1.05rem;">🗺️ Deutschland-Warn-, Flusspegel- &amp; Erdbeben-Karte</h3>
            <div class="text-muted" style="font-size:0.78rem;margin-bottom:10px;">
                🔴 Rot = Amtliche NINA/MoWaS-Warnung · 🔵 Blau = WSV-Flusspegel · 🟣 Violett = Seismisches Ereignis (Erdbeben)
            </div>
            <div id="alertMapCanvas" class="al-map-box"></div>
        </div>

        <div class="al-card">
            <h3 style="margin:0 0 12px 0;font-size:1.05rem;">📢 Aktuelle BBK NINA / MoWaS Gefahrenmeldungen</h3>
            <div style="max-height:470px;overflow-y:auto;display:flex;flex-direction:column;gap:9px;padding-right:4px;">
                <?php if (empty($ninaAlerts)): ?>
                    <div style="padding:20px;text-align:center;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:12px;">
                        <strong style="color:#4ade80;">🟢 Keine akuten Katastrophenmeldungen</strong>
                    </div>
                <?php endif; ?>
                <?php foreach ($ninaAlerts as $na): ?>
                    <div onclick="flyToAlertPin(<?= (float)$na['lat'] ?>, <?= (float)$na['lng'] ?>)"
                         style="cursor:pointer;padding:11px 13px;border-radius:12px;background:rgba(15,23,42,0.55);border:1px solid var(--border);border-left:4px solid #ef4444;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <span class="badge" style="background:#dc2626;color:#fff;font-size:0.7rem;"><?= e($na['source']) ?></span>
                            <span class="text-muted" style="font-size:0.74rem;"><?= e($na['date_fmt']) ?></span>
                        </div>
                        <div style="font-weight:700;font-size:0.88rem;margin-top:6px;line-height:1.35;"><?= e($na['title']) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($quakes)): ?>
                    <h4 style="margin:12px 0 4px 0;font-size:0.92rem;color:#c084fc;">🌋 Jüngste Erdbeben in Mitteleuropa (D-A-CH)</h4>
                    <?php foreach ($quakes as $qk): ?>
                        <div onclick="flyToAlertPin(<?= (float)$qk['lat'] ?>, <?= (float)$qk['lng'] ?>)"
                             style="cursor:pointer;padding:9px 12px;border-radius:10px;background:rgba(15,23,42,0.45);border:1px solid var(--border);border-left:4px solid #a855f7;">
                            <div style="display:flex;justify-content:space-between;font-size:0.82rem;">
                                <strong>Magnitude <?= number_format((float)$qk['mag'], 1, ',', '.') ?> – <?= e($qk['place']) ?></strong>
                                <span class="text-muted"><?= e($qk['time_fmt']) ?> (Tiefe <?= (float)$qk['depth_km'] ?> km)</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- WSV PEGELONLINE: LIVE-FLUSSPEGELSTÄNDE DEUTSCHER FLÜSSE -->
    <div class="al-card">
        <h3 style="margin:0 0 6px 0;font-size:1.05rem;">🌊 Live-Flusspegelstände (PegelOnline WSV – Rhein, Donau, Elbe, Mosel, Main, Neckar &amp; Weser)</h3>
        <div class="text-muted" style="font-size:0.79rem;margin-bottom:12px;">Offizielle Echtzeit-Messwerte der Wasserstraßen- und Schifffahrtsverwaltung des Bundes in Zentimetern (cm).</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(215px, 1fr));gap:10px;">
            <?php foreach ($pegelStations as $ps): ?>
                <?php
                $stCol = '#38bdf8';
                $stLbl = 'Normal';
                if ($ps['state'] === 'high') { $stCol = '#ef4444'; $stLbl = 'Hochwasser'; }
                elseif ($ps['state'] === 'low') { $stCol = '#f59e0b'; $stLbl = 'Niedrigwasser'; }
                ?>
                <div style="padding:11px 13px;border-radius:12px;background:rgba(15,23,42,0.5);border:1px solid var(--border);border-left:4px solid <?= $stCol ?>;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-size:0.74rem;font-weight:700;color:<?= $stCol ?>;"><?= e($ps['river']) ?></span>
                        <span class="badge" style="background:rgba(15,23,42,0.7);color:<?= $stCol ?>;font-size:0.68rem;"><?= e($stLbl) ?></span>
                    </div>
                    <div style="font-weight:800;font-size:0.94rem;margin-top:3px;"><?= e($ps['name']) ?></div>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:5px;">
                        <span style="font-size:1.25rem;font-weight:800;font-family:monospace;color:#f8fafc;"><?= (int)$ps['cm'] ?> cm</span>
                        <span class="text-muted" style="font-size:0.72rem;">Stand <?= e($ps['updated_at']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- PERSÖNLICHE BBK-KRISENVORSORGE-CHECKLISTE -->
    <div class="al-card" id="sec-checklist">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
            <div>
                <h3 style="margin:0;font-size:1.05rem;">🎒 Persönliche BBK-Notfall- &amp; Krisenvorsorge-Checkliste (<?= $chkPct ?> % erfüllt)</h3>
                <div class="text-muted" style="font-size:0.79rem;">Empfehlungen des Bundesamts für Bevölkerungsschutz für 10 Tage autarke Versorgung bei Stromausfall oder Unwetter.</div>
            </div>
            <form method="post" style="display:inline-flex;gap:6px;margin:0;flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_check_item">
                <input type="text" name="category" class="form-control" style="width:140px;font-size:0.8rem;" placeholder="Kategorie (z.B. Vorrat)">
                <input type="text" name="item_title" class="form-control" style="width:240px;font-size:0.8rem;" placeholder="Eigenen Vorsorge-Punkt ergänzen..." required>
                <button type="submit" class="btn btn-sm btn-primary">+ Hinzufügen</button>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:8px;">
            <?php foreach ($checklist as $ci): ?>
                <?php $done = ((int)$ci['is_checked'] === 1); ?>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 12px;border-radius:10px;background:<?= $done ? 'rgba(34,197,94,0.12)' : 'rgba(15,23,42,0.45)' ?>;border:1px solid <?= $done ? 'rgba(34,197,94,0.4)' : 'var(--border)' ?>;">
                    <form method="post" style="margin:0;flex:1;">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="toggle_check_item">
                        <input type="hidden" name="item_id" value="<?= (int)$ci['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:var(--text-main);text-align:left;cursor:pointer;display:flex;align-items:center;gap:8px;font-size:0.83rem;width:100%;">
                            <span><?= $done ? '✅' : '⬜' ?></span>
                            <span style="<?= $done ? 'text-decoration:line-through;opacity:0.75;' : '' ?>">
                                <strong style="color:#38bdf8;font-size:0.73rem;">[<?= e($ci['category']) ?>]</strong> <?= e($ci['item_title']) ?>
                            </span>
                        </button>
                    </form>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_check_item">
                        <input type="hidden" name="item_id" value="<?= (int)$ci['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.75rem;">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/leaflet/protomaps-leaflet.js"></script>
<script>
const AL_NINA = <?= json_encode($ninaAlerts, JSON_UNESCAPED_UNICODE) ?>;
const AL_PEGEL = <?= json_encode($pegelStations, JSON_UNESCAPED_UNICODE) ?>;
const AL_QUAKES = <?= json_encode($quakes, JSON_UNESCAPED_UNICODE) ?>;
const AL_SPEECH = <?= json_encode($alertSpeechText, JSON_UNESCAPED_UNICODE) ?>;
let alMap = null;
let savedRadioVolAlert = null;

function speakCivilAlerts() {
    if (!('speechSynthesis' in window)) return;
    if (window.speechSynthesis.speaking) {
        window.speechSynthesis.cancel();
        if (savedRadioVolAlert !== null && window.McGlobalRadio) {
            window.McGlobalRadio.setVolume(savedRadioVolAlert);
            savedRadioVolAlert = null;
        }
        document.getElementById('btnSpeakAlert').innerHTML = '🔊 Warnlage im Radio vorlesen';
        return;
    }
    if (window.McGlobalRadio && window.McGlobalRadio.getState && window.McGlobalRadio.getState().playing) {
        savedRadioVolAlert = window.McGlobalRadio.getState().volume || 0.8;
        window.McGlobalRadio.setVolume(Math.min(savedRadioVolAlert, 0.18));
    }
    const utter = new SpeechSynthesisUtterance(AL_SPEECH);
    utter.lang = 'de-DE';
    document.getElementById('btnSpeakAlert').innerHTML = '⏹️ Durchsage stoppen';
    const restore = () => {
        if (savedRadioVolAlert !== null && window.McGlobalRadio) {
            window.McGlobalRadio.setVolume(savedRadioVolAlert);
            savedRadioVolAlert = null;
        }
        document.getElementById('btnSpeakAlert').innerHTML = '🔊 Warnlage im Radio vorlesen';
    };
    utter.onend = restore;
    utter.onerror = restore;
    window.speechSynthesis.speak(utter);
}

function flyToAlertPin(lat, lng) {
    if (alMap && lat && lng) alMap.setView([lat, lng], 10, { animate: true });
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('alertMapCanvas');
    if (!el || typeof L === 'undefined') return;
    alMap = L.map('alertMapCanvas').setView([51.1657, 10.4515], 6);
    L.tileLayer('maps.php?action=tile&s={s}&z={z}&x={x}&y={y}', { maxZoom: 18 }).addTo(alMap);
    if (typeof protomapsL !== 'undefined') {
        try { protomapsL.leafletLayer({ url: 'assets/maps/germany_detail.pmtiles', theme: 'dark' }).addTo(alMap); } catch (e) {}
    }

    AL_NINA.forEach(na => {
        if (!na.lat || !na.lng) return;
        L.circleMarker([na.lat, na.lng], {
            radius: 8, color: '#ffffff', weight: 2, fillColor: '#ef4444', fillOpacity: 0.92
        }).bindPopup(`<strong>🚨 ${na.source}</strong><br>${na.title}<br><small>${na.date_fmt}</small>`).addTo(alMap);
    });

    AL_PEGEL.forEach(ps => {
        if (!ps.lat || !ps.lng) return;
        const col = ps.state === 'high' ? '#ef4444' : '#38bdf8';
        L.circleMarker([ps.lat, ps.lng], {
            radius: 6, color: '#0f172a', weight: 1.5, fillColor: col, fillOpacity: 0.9
        }).bindPopup(`<strong>🌊 Pegel ${ps.name} (${ps.river})</strong><br>Wasserstand: <strong>${ps.cm} cm</strong>`).addTo(alMap);
    });

    AL_QUAKES.forEach(qk => {
        if (!qk.lat || !qk.lng) return;
        L.circleMarker([qk.lat, qk.lng], {
            radius: 7, color: '#ffffff', weight: 1.5, fillColor: '#a855f7', fillOpacity: 0.9
        }).bindPopup(`<strong>🌋 Erdbeben M ${qk.mag}</strong><br>${qk.place}<br><small>${qk.time_fmt}</small>`).addTo(alMap);
    });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
