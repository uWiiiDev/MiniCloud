<?php
declare(strict_types=1);

/**
 * MiniCloud – 🚗 Verkehrszentrale, Autobahn-Staumelder & Webradio-Verkehrsfunk (traffic.php)
 * Kombiniert Echtzeit-Verkehrsdaten der Autobahn GmbH des Bundes (A1–A999: Staus,
 * Vollsperrungen, Baustellen, E-Schnellladesäulen inkl. exakter Strecken-Geometrie)
 * mit unserer 7,2-GB-Offline-Deutschlandkarte (Zoom 0–18), lokalem Festplatten-Cache,
 * eigenem Intranet-Gefahren-/Straßenmelder (für Bundes-/Landstraßen) und einem
 * automatischen Webradio-Verkehrsfunk-Sprecher mit Audio-Ducking.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$isAdminUser = isAdmin();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_traffic'] ?? '1') !== '1' && !$isAdminUser) {
    setFlash('error', 'Das Verkehrs-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$trafficCacheDir = __DIR__ . '/uploads/traffic_cache';
if (!is_dir($trafficCacheDir)) {
    @mkdir($trafficCacheDir, 0750, true);
}

// =========================================================================
// 1. DATENBANK-TABELLEN (STAMMSTRECKEN-FAVORITEN & LOKALE INTRANET-MELDUNGEN)
// =========================================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS traffic_favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            road_id VARCHAR(16) NOT NULL,
            label VARCHAR(80) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tf_user_road (user_id, road_id),
            INDEX idx_tf_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS traffic_local_reports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            road_name VARCHAR(80) NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'jam',
            title VARCHAR(160) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            delay_min INT NOT NULL DEFAULT 0,
            lat DECIMAL(10, 5) NOT NULL DEFAULT 51.16570,
            lng DECIMAL(10, 5) NOT NULL DEFAULT 10.45150,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tlr_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable) {
    // Tabellen existieren bereits
}

// =========================================================================
// 2. ALLE DEUTSCHEN AUTOBAHNEN & SERVER-PROXY MIT FESTPLATTEN-CACHE
// =========================================================================
function getGermanAutobahnList(): array
{
    return [
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10',
        'A11', 'A12', 'A13', 'A14', 'A15', 'A17', 'A19', 'A20', 'A21', 'A23',
        'A24', 'A26', 'A27', 'A28', 'A29', 'A30', 'A31', 'A33', 'A36', 'A37',
        'A38', 'A39', 'A40', 'A42', 'A43', 'A44', 'A45', 'A46', 'A48', 'A49',
        'A52', 'A57', 'A59', 'A60', 'A61', 'A62', 'A63', 'A64', 'A65', 'A66',
        'A67', 'A70', 'A71', 'A72', 'A73', 'A81', 'A92', 'A93', 'A94', 'A95',
        'A96', 'A98', 'A99', 'A100', 'A111', 'A113', 'A115', 'A555', 'A565', 'A661',
    ];
}

function normalizeAutobahnId(string $raw): string
{
    $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($raw)) ?? '');
    if (in_array($clean, getGermanAutobahnList(), true)) {
        return $clean;
    }
    return 'A1';
}

/**
 * Ruft für eine Autobahn (z.B. A1) live Staus/Warnungen, Vollsperrungen, Baustellen & Ladesäulen ab
 * und speichert sie lokal im Server-Cache (5 Min. Frische + unbegrenzter Offline-Fallback).
 */
function fetchAutobahnTrafficData(string $roadId, string $cacheDir, bool $allowNetwork = true, bool $includeChargers = false): array
{
    $roadId = normalizeAutobahnId($roadId);
    $cacheFile = $cacheDir . '/road_' . $roadId . ($includeChargers ? '_full' : '_core') . '.json';

    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 300) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['road'])) {
            $cached['source'] = 'Lokaler Server-Cache (Autobahn GmbH)';
            return $cached;
        }
    }

    if ($allowNetwork) {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3.2,
                'header'  => "User-Agent: MiniCloud-Traffic-Center/1.0\r\nAccept: application/json\r\n",
            ],
        ]);

        $baseUrl = 'https://verkehr.autobahn.de/o/autobahn/' . rawurlencode($roadId) . '/services/';
        $rawWarn = @file_get_contents($baseUrl . 'warning', false, $ctx);
        $rawClos = @file_get_contents($baseUrl . 'closure', false, $ctx);
        $rawWork = @file_get_contents($baseUrl . 'roadworks', false, $ctx);
        $rawChrg = $includeChargers ? @file_get_contents($baseUrl . 'electric_charging_station', false, $ctx) : null;

        $warnJson = is_string($rawWarn) ? @json_decode($rawWarn, true) : null;
        $closJson = is_string($rawClos) ? @json_decode($rawClos, true) : null;
        $workJson = is_string($rawWork) ? @json_decode($rawWork, true) : null;
        $chrgJson = is_string($rawChrg) ? @json_decode($rawChrg, true) : null;

        if (is_array($warnJson) || is_array($closJson) || is_array($workJson)) {
            $parseItems = static function (array $items, string $type, string $road): array {
                $out = [];
                foreach ($items as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    // Zukünftige geplante Sperrungen in der Zukunft markieren, aber aktive priorisieren
                    $isFuture = !empty($it['future']);
                    $lat = isset($it['coordinate']['lat']) ? (float)$it['coordinate']['lat'] : 0.0;
                    $lng = isset($it['coordinate']['long']) ? (float)$it['coordinate']['long'] : 0.0;
                    if ($lat === 0.0 || $lng === 0.0) {
                        continue;
                    }
                    $descArr = isset($it['description']) && is_array($it['description']) ? $it['description'] : [];
                    $descText = trim(implode("\n", array_filter(array_map('trim', $descArr), static fn($l) => $l !== '')));

                    $delayMin = isset($it['delayTimeValue']) ? (int)$it['delayTimeValue'] : 0;
                    $avgSpeed = isset($it['averageSpeed']) ? (int)$it['averageSpeed'] : 0;
                    $abnType  = (string)($it['abnormalTrafficType'] ?? '');
                    $isBlocked = ((string)($it['isBlocked'] ?? 'false') === 'true') || $type === 'closure';

                    $lineCoords = [];
                    if (isset($it['geometry']['coordinates']) && is_array($it['geometry']['coordinates'])) {
                        foreach ($it['geometry']['coordinates'] as $pt) {
                            if (is_array($pt) && isset($pt[0], $pt[1])) {
                                // GeoJSON ist [lng, lat] -> Leaflet erwartet [lat, lng]
                                $lineCoords[] = [round((float)$pt[1], 5), round((float)$pt[0], 5)];
                            }
                        }
                    }

                    $out[] = [
                        'id'         => (string)($it['identifier'] ?? md5($road . $type . ($it['title'] ?? ''))),
                        'road'       => $road,
                        'type'       => $type,
                        'future'     => $isFuture,
                        'blocked'    => $isBlocked,
                        'title'      => trim((string)($it['title'] ?? $road)),
                        'subtitle'   => trim((string)($it['subtitle'] ?? '')),
                        'delay_min'  => $delayMin,
                        'speed_kmh'  => $avgSpeed,
                        'abn_type'   => $abnType,
                        'desc'       => $descText,
                        'lat'        => round($lat, 5),
                        'lng'        => round($lng, 5),
                        'line'       => $lineCoords,
                    ];
                }
                return $out;
            };

            $warnings  = $parseItems($warnJson['warning'] ?? [], 'warning', $roadId);
            $closures  = array_values(array_filter($parseItems($closJson['closure'] ?? [], 'closure', $roadId), static fn($c) => !$c['future']));
            $roadworks = array_slice(array_values(array_filter($parseItems($workJson['roadworks'] ?? [], 'roadworks', $roadId), static fn($w) => !$w['future'])), 0, 35);
            $chargers  = $includeChargers ? array_slice($parseItems($chrgJson['electric_charging_station'] ?? [], 'charger', $roadId), 0, 40) : [];

            $totalDelay = 0;
            foreach ($warnings as $w) {
                $totalDelay += (int)$w['delay_min'];
            }

            $result = [
                'road'        => $roadId,
                'source'      => 'Autobahn GmbH Live',
                'updated_at'  => date('H:i'),
                'warnings'    => $warnings,
                'closures'    => $closures,
                'roadworks'   => $roadworks,
                'chargers'    => $chargers,
                'total_delay' => $totalDelay,
            ];
            @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $result;
        }
    }

    if (is_file($cacheFile)) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['road'])) {
            $cached['source'] = 'Offline-Festplatten-Cache';
            return $cached;
        }
    }

    return [
        'road'        => $roadId,
        'source'      => 'Offline-Modus (Keine Live-Verbindung)',
        'updated_at'  => date('H:i'),
        'warnings'    => [],
        'closures'    => [],
        'roadworks'   => [],
        'chargers'    => [],
        'total_delay' => 0,
    ];
}

/**
 * Erzeugt den gesprochenen Radiomoderations-Text für den Webradio-Verkehrsfunk.
 */
function buildRadioTrafficSpeechText(array $roadDataList, array $localReports = []): string
{
    $parts = ['Hier ist der MiniCloud Verkehrsfunk um ' . date('H') . ' Uhr ' . (int)date('i') . '.'];
    $incidentCount = 0;

    foreach ($roadDataList as $rd) {
        $road = (string)($rd['road'] ?? '');
        foreach ($rd['closures'] ?? [] as $cl) {
            $incidentCount++;
            $sub = $cl['subtitle'] !== '' ? (' Richtung ' . str_replace('->', 'nach', $cl['subtitle'])) : '';
            $parts[] = 'Achtung auf der ' . $road . $sub . ': Vollsperrung im Bereich ' . str_replace('|', ',', $cl['title']) . '.';
            if ($incidentCount >= 8) break 2;
        }
        foreach ($rd['warnings'] ?? [] as $w) {
            $incidentCount++;
            $sub = $w['subtitle'] !== '' ? (' Richtung ' . str_replace('->', 'nach', $w['subtitle'])) : '';
            $delayTxt = ((int)$w['delay_min'] > 0) ? (' Hier verlieren Sie aktuell rund ' . (int)$w['delay_min'] . ' Minuten.') : '';
            $parts[] = $road . $sub . ', ' . str_replace($road . ' |', '', $w['title']) . ': Stau oder stockender Verkehr.' . $delayTxt;
            if ($incidentCount >= 8) break 2;
        }
    }

    foreach (array_slice($localReports, 0, 3) as $lr) {
        $incidentCount++;
        $parts[] = 'Lokale Meldung für ' . $lr['road_name'] . ': ' . $lr['title'] . '.';
    }

    if ($incidentCount === 0) {
        $parts[] = 'Auf Ihren gespeicherten Stammstrecken liegen aktuell keine Staus oder Sperrungen vor. Freie Fahrt und gute Reise!';
    } else {
        $parts[] = 'Gute und sichere Weiterfahrt wünscht Ihr MiniCloud Verkehrsservice.';
    }

    return implode(' ', $parts);
}

// =========================================================================
// 3. AJAX & POST AKTIONEN (INKL. WEBRADIO-VERKEHRSFUNK JSON-ENDPOINT)
// =========================================================================
$stmtFavRoads = $pdo->prepare('SELECT * FROM traffic_favorites WHERE user_id = :uid ORDER BY id ASC');
$stmtFavRoads->execute([':uid' => $userId]);
$favRoads = $stmtFavRoads->fetchAll();

if (empty($favRoads)) {
    $defaultRoads = [
        ['A1', 'Nord-Süd / Hansalinie'],
        ['A2', 'Ruhrgebiet – Hannover – Berlin'],
        ['A3', 'Köln – Frankfurt – Nürnberg'],
        ['A7', 'Flensburg – Kassel – Ulm'],
        ['A8', 'Karlsruhe – Stuttgart – München'],
    ];
    $insR = $pdo->prepare('INSERT IGNORE INTO traffic_favorites (user_id, road_id, label) VALUES (:uid, :r, :l)');
    foreach ($defaultRoads as $dr) {
        $insR->execute([':uid' => $userId, ':r' => $dr[0], ':l' => $dr[1]]);
    }
    $stmtFavRoads->execute([':uid' => $userId]);
    $favRoads = $stmtFavRoads->fetchAll();
}

$stmtLocal = $pdo->query('
    SELECT r.*, u.username
    FROM traffic_local_reports r
    LEFT JOIN users u ON u.id = r.user_id
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT 20
');
$localReports = $stmtLocal ? $stmtLocal->fetchAll() : [];

if (isset($_GET['action']) && $_GET['action'] === 'radio_bulletin') {
    header('Content-Type: application/json; charset=UTF-8');
    $summaryList = [];
    foreach (array_slice($favRoads, 0, 5) as $fr) {
        $summaryList[] = fetchAutobahnTrafficData((string)$fr['road_id'], $trafficCacheDir, true, false);
    }
    $speech = buildRadioTrafficSpeechText($summaryList, $localReports);
    echo json_encode([
        'ok'     => true,
        'time'   => date('H:i'),
        'speech' => $speech,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    if (!verifyCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: traffic.php');
        exit;
    }

    if ($postAction === 'add_favorite_road') {
        $roadId = normalizeAutobahnId((string)($_POST['road_id'] ?? 'A1'));
        $label  = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
        $pdo->prepare('INSERT IGNORE INTO traffic_favorites (user_id, road_id, label) VALUES (:uid, :r, :l)')
            ->execute([':uid' => $userId, ':r' => $roadId, ':l' => $label]);
        setFlash('success', 'Autobahn ' . $roadId . ' zu deinen Stammstrecken hinzugefügt.');
        header('Location: traffic.php?road=' . rawurlencode($roadId));
        exit;
    }

    if ($postAction === 'delete_favorite_road') {
        $favId = (int)($_POST['fav_id'] ?? 0);
        $pdo->prepare('DELETE FROM traffic_favorites WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $favId, ':uid' => $userId]);
        setFlash('success', 'Stammstrecke entfernt.');
        header('Location: traffic.php');
        exit;
    }

    if ($postAction === 'add_local_report') {
        $roadName = mb_substr(trim((string)($_POST['road_name'] ?? 'B1 / Ortsstraße')), 0, 80) ?: 'Ortsstraße';
        $cat      = in_array($_POST['category'] ?? '', ['jam', 'closure', 'roadworks', 'hazard'], true) ? (string)$_POST['category'] : 'jam';
        $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $desc     = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
        $delayMin = max(0, min(300, (int)($_POST['delay_min'] ?? 0)));
        $lat      = max(47.0, min(55.5, (float)str_replace(',', '.', (string)($_POST['lat'] ?? '51.1657'))));
        $lng      = max(5.5, min(15.5, (float)str_replace(',', '.', (string)($_POST['lng'] ?? '10.4515'))));

        if ($title !== '') {
            $pdo->prepare('
                INSERT INTO traffic_local_reports (user_id, road_name, category, title, description, delay_min, lat, lng)
                VALUES (:uid, :rn, :cat, :t, :d, :dl, :lat, :lng)
            ')->execute([
                ':uid' => $userId,
                ':rn'  => $roadName,
                ':cat' => $cat,
                ':t'   => $title,
                ':d'   => $desc,
                ':dl'  => $delayMin,
                ':lat' => $lat,
                ':lng' => $lng,
            ]);
            setFlash('success', 'Lokale Verkehrsmeldung im Intranet veröffentlicht.');
        }
        header('Location: traffic.php');
        exit;
    }

    if ($postAction === 'delete_local_report') {
        $repId = (int)($_POST['rep_id'] ?? 0);
        $pdo->prepare('DELETE FROM traffic_local_reports WHERE id = :id AND (user_id = :uid OR :adm = 1)')
            ->execute([':id' => $repId, ':uid' => $userId, ':adm' => $isAdminUser ? 1 : 0]);
        setFlash('success', 'Lokale Verkehrsmeldung entfernt.');
        header('Location: traffic.php');
        exit;
    }
}

// =========================================================================
// 4. AKTUELLE AUTOBAHN & STAMMSTRECKEN-ÜBERSICHT LADEN
// =========================================================================
$activeRoad = isset($_GET['road']) ? normalizeAutobahnId((string)$_GET['road']) : (string)($favRoads[0]['road_id'] ?? 'A1');
$viewMode   = (isset($_GET['mode']) && $_GET['mode'] === 'all_favs') ? 'all_favs' : 'single';

$favSummaries = [];
$combinedWarnings  = [];
$combinedClosures  = [];
$combinedRoadworks = [];
$combinedChargers  = [];
$totalFavDelay     = 0;

foreach ($favRoads as $fr) {
    $rId = (string)$fr['road_id'];
    $needChargers = ($rId === $activeRoad);
    $rData = fetchAutobahnTrafficData($rId, $trafficCacheDir, true, $needChargers);
    $favSummaries[$rId] = $rData;
    $totalFavDelay += (int)$rData['total_delay'];
    if ($viewMode === 'all_favs') {
        $combinedWarnings  = array_merge($combinedWarnings, $rData['warnings']);
        $combinedClosures  = array_merge($combinedClosures, $rData['closures']);
        $combinedRoadworks = array_merge($combinedRoadworks, array_slice($rData['roadworks'], 0, 12));
    }
}

if (!isset($favSummaries[$activeRoad])) {
    $favSummaries[$activeRoad] = fetchAutobahnTrafficData($activeRoad, $trafficCacheDir, true, true);
}

$activeRoadData = $viewMode === 'all_favs'
    ? [
        'road'        => 'Alle Stammstrecken',
        'source'      => $favSummaries[$activeRoad]['source'] ?? 'Autobahn GmbH Live',
        'updated_at'  => date('H:i'),
        'warnings'    => $combinedWarnings,
        'closures'    => $combinedClosures,
        'roadworks'   => $combinedRoadworks,
        'chargers'    => $favSummaries[$activeRoad]['chargers'] ?? [],
        'total_delay' => $totalFavDelay,
    ]
    : $favSummaries[$activeRoad];

$radioSpeechText = buildRadioTrafficSpeechText(array_values($favSummaries), $localReports);
$hasGermanyPmtiles = is_file(__DIR__ . '/assets/maps/germany_detail.pmtiles') || is_file(__DIR__ . '/uploads/maps/tiles/germany_detail.pmtiles');

$pageTitle = '🚗 Verkehrszentrale, Staumelder & Verkehrsfunk';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
.tf-wrap { display:flex; flex-direction:column; gap:18px; }
.tf-topbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:14px 18px; }
.tf-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:18px 20px; }
.tf-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; font-size:0.82rem; font-weight:700; text-decoration:none; border:1px solid var(--border); background:rgba(148,163,184,0.08); color:var(--text-main); transition:all .15s; }
.tf-pill.active, .tf-pill:hover { border-color:#38bdf8; background:rgba(56,189,248,0.16); color:#38bdf8; }
.tf-badge-road { display:inline-flex; align-items:center; justify-content:center; padding:2px 8px; border-radius:6px; background:#1d4ed8; color:#fff; font-weight:800; font-size:0.78rem; border:1.5px solid #fff; box-shadow:0 2px 6px rgba(0,0,0,0.35); }
.tf-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(145px, 1fr)); gap:12px; }
.tf-kpi { background:rgba(15,23,42,0.45); border:1px solid var(--border); border-radius:12px; padding:12px 14px; }
.tf-kpi-label { font-size:0.75rem; color:var(--text-muted); }
.tf-kpi-val { font-size:1.25rem; font-weight:800; margin-top:4px; }
.tf-main-grid { display:grid; grid-template-columns: 1.35fr 1fr; gap:18px; }
@media (max-width: 1080px) { .tf-main-grid { grid-template-columns: 1fr; } }
.tf-map-box { width:100%; height:540px; border-radius:14px; border:1px solid var(--border); overflow:hidden; background:#0b192c; }
.tf-feed-list { display:flex; flex-direction:column; gap:10px; max-height:540px; overflow-y:auto; padding-right:4px; }
.tf-incident { background:rgba(15,23,42,0.42); border:1px solid var(--border); border-left:4px solid #ef4444; border-radius:12px; padding:11px 14px; cursor:pointer; transition:background .15s; }
.tf-incident:hover { background:rgba(56,189,248,0.10); }
.tf-pin { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:800; color:#fff; border:1.5px solid #fff; box-shadow:0 3px 10px rgba(0,0,0,0.45); white-space:nowrap; }
</style>

<div class="tf-wrap">
    <!-- KOPFLEISTE: STAMMSTRECKEN-PILLS, AUTOBAHN-WAHL & WEBRADIO-VERKEHRSFUNK -->
    <div class="tf-topbar">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <a href="traffic.php?mode=all_favs" class="tf-pill <?= $viewMode === 'all_favs' ? 'active' : '' ?>">
                📊 Alle meine Stammstrecken
            </a>
            <?php foreach ($favRoads as $fr):
                $rKey  = (string)$fr['road_id'];
                $rSum  = $favSummaries[$rKey] ?? null;
                $wCnt  = count($rSum['warnings'] ?? []);
                $cCnt  = count($rSum['closures'] ?? []);
                $delay = (int)($rSum['total_delay'] ?? 0);
                $dotCol = ($cCnt > 0 || $delay >= 15) ? '#ef4444' : ($wCnt > 0 ? '#f59e0b' : '#22c55e');
                $isAct = ($viewMode === 'single' && $activeRoad === $rKey);
            ?>
                <span class="tf-pill <?= $isAct ? 'active' : '' ?>">
                    <a href="traffic.php?road=<?= urlencode($rKey) ?>" style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                        <span style="color:<?= $dotCol ?>;">●</span>
                        <span class="tf-badge-road"><?= e($rKey) ?></span>
                        <?php if ($delay > 0): ?>
                            <span style="color:#f87171;font-size:0.75rem;">+<?= $delay ?> Min.</span>
                        <?php elseif ($wCnt > 0): ?>
                            <span class="text-muted" style="font-size:0.75rem;"><?= $wCnt ?> Meld.</span>
                        <?php else: ?>
                            <span style="color:#4ade80;font-size:0.74rem;">Frei</span>
                        <?php endif; ?>
                    </a>
                    <?php if (count($favRoads) > 1): ?>
                        <form method="post" style="display:inline;margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_favorite_road">
                            <input type="hidden" name="fav_id" value="<?= (int)$fr['id'] ?>">
                            <button type="submit" title="Stammstrecke entfernen" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0 2px;font-size:0.78rem;">✕</button>
                        </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <!-- Beliebige deutsche Autobahn A1..A661 auswählen / als Stammstrecke speichern -->
            <form method="get" action="traffic.php" style="display:inline-flex;gap:6px;align-items:center;margin:0;">
                <select name="road" onchange="this.form.submit()" class="form-control" style="padding:6px 10px;font-size:0.83rem;width:auto;">
                    <?php foreach (getGermanAutobahnList() as $abId): ?>
                        <option value="<?= e($abId) ?>" <?= $activeRoad === $abId ? 'selected' : '' ?>>🛣️ Autobahn <?= e($abId) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <form method="post" style="display:inline;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_favorite_road">
                <input type="hidden" name="road_id" value="<?= e($activeRoad) ?>">
                <button type="submit" class="btn btn-sm btn-secondary">⭐ <?= e($activeRoad) ?> als Stammstrecke merken</button>
            </form>

            <button type="button" id="btnSpeakTraffic" onclick="toggleSpeakTrafficBulletin()" class="btn btn-sm btn-primary" style="background:linear-gradient(135deg,#0284c7,#4f46e5);border:none;">
                🔊 Verkehrsfunk vorlesen
            </button>
            <a href="radio.php" class="btn btn-sm btn-secondary">📻 Zum Webradio</a>
        </div>
    </div>

    <!-- WEBRADIO-VERKEHRSFUNK STUDIO-BANNER -->
    <div class="tf-card" style="background:linear-gradient(135deg, rgba(2,132,199,0.16), rgba(30,41,59,0.82)); border-color:rgba(56,189,248,0.35); padding:14px 18px;">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
            <div style="flex:1;min-width:260px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="badge" style="background:#ef4444;color:#fff;font-size:0.72rem;">📻 LIVE-VERKEHRSFUNK</span>
                    <strong style="font-size:0.95rem;">Aktuelle Studio-Durchsage für deine Stammstrecken (mit automatischem Webradio-Ducking)</strong>
                    <span class="text-muted" style="font-size:0.78rem;">Quelle: <?= e($activeRoadData['source']) ?> · Stand <?= e($activeRoadData['updated_at']) ?> Uhr</span>
                </div>
                <div id="trafficSpeechPreview" style="font-size:0.84rem;color:var(--text-main);margin-top:6px;line-height:1.45;font-style:italic;">
                    „<?= e($radioSpeechText) ?>“
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8rem;cursor:pointer;background:rgba(15,23,42,0.5);padding:6px 11px;border-radius:10px;border:1px solid var(--border);">
                    <input type="checkbox" id="chkAutoTrafficHalfHour" onchange="toggleHalfHourAutoTraffic(this.checked)">
                    <span>⏱️ Alle 30 Min. automatisch im Radio ansagen</span>
                </label>
            </div>
        </div>
    </div>

    <!-- 6 LIVE-VERKEHRS-KPIS -->
    <div class="tf-kpi-grid">
        <div class="tf-kpi">
            <div class="tf-kpi-label">🛣️ Aktive Ansicht</div>
            <div class="tf-kpi-val" style="color:#38bdf8;"><?= e($activeRoadData['road']) ?></div>
        </div>
        <div class="tf-kpi">
            <div class="tf-kpi-label">🔴 Staus &amp; Warnungen</div>
            <div class="tf-kpi-val" style="color:<?= count($activeRoadData['warnings']) > 0 ? '#f87171' : '#4ade80' ?>;">
                <?= count($activeRoadData['warnings']) ?>
            </div>
        </div>
        <div class="tf-kpi">
            <div class="tf-kpi-label">⛔ Aktive Vollsperrungen</div>
            <div class="tf-kpi-val" style="color:<?= count($activeRoadData['closures']) > 0 ? '#ef4444' : '#4ade80' ?>;">
                <?= count($activeRoadData['closures']) ?>
            </div>
        </div>
        <div class="tf-kpi">
            <div class="tf-kpi-label">⏱️ Zeitverlust gesamt</div>
            <div class="tf-kpi-val" style="color:<?= (int)$activeRoadData['total_delay'] >= 15 ? '#fb923c' : '#e2e8f0' ?>;">
                +<?= (int)$activeRoadData['total_delay'] ?> Min.
            </div>
        </div>
        <div class="tf-kpi">
            <div class="tf-kpi-label">🚧 Baustellen auf Strecke</div>
            <div class="tf-kpi-val" style="color:#facc15;"><?= count($activeRoadData['roadworks']) ?></div>
        </div>
        <div class="tf-kpi">
            <div class="tf-kpi-label">⚡ E-Schnellladesäulen</div>
            <div class="tf-kpi-val" style="color:#34d399;"><?= count($activeRoadData['chargers']) ?></div>
        </div>
    </div>

    <!-- HAUPTBEREICH: INTERAKTIVE DEUTSCHLAND-VERKEHRSKARTE (LINKS) + MELDUNGSLISTE (RECHTS) -->
    <div class="tf-main-grid">
        <div class="tf-card">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
                <div>
                    <h3 style="margin:0;font-size:1.08rem;">🗺️ Echtzeit-Autobahn- &amp; Staukarte (<?= e($activeRoadData['road']) ?>)</h3>
                    <div class="text-muted" style="font-size:0.78rem;">Zeichnet die exakten Stau- &amp; Sperrungs-Abschnitte auf unserer 7,2-GB-Deutschlandkarte (Zoom 0–18)</div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <button type="button" class="btn btn-sm btn-primary" id="btnLayerWarn" onclick="toggleTfLayer('warn')">🔴 Staus &amp; Sperrungen</button>
                    <button type="button" class="btn btn-sm btn-secondary" id="btnLayerWork" onclick="toggleTfLayer('work')">🚧 Baustellen (<?= count($activeRoadData['roadworks']) ?>)</button>
                    <button type="button" class="btn btn-sm btn-secondary" id="btnLayerChrg" onclick="toggleTfLayer('chrg')">⚡ E-Ladesäulen (<?= count($activeRoadData['chargers']) ?>)</button>
                </div>
            </div>
            <div id="tfMapCanvas" class="tf-map-box"></div>
        </div>

        <div class="tf-card">
            <h3 style="margin:0 0 12px 0;font-size:1.08rem;">🚨 Aktuelle Verkehrsmeldungen (Klick zoomt auf Abschnitt)</h3>
            <div class="tf-feed-list">
                <?php if (empty($activeRoadData['closures']) && empty($activeRoadData['warnings']) && empty($activeRoadData['roadworks'])): ?>
                    <div style="padding:26px;text-align:center;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:12px;">
                        <div style="font-size:2rem;">🟢</div>
                        <strong style="color:#4ade80;">Freie Fahrt auf <?= e($activeRoadData['road']) ?>!</strong>
                        <div class="text-muted" style="font-size:0.82rem;margin-top:4px;">Aktuell liegen keine Staus oder Vollsperrungen vor.</div>
                    </div>
                <?php endif; ?>

                <?php foreach ($activeRoadData['closures'] as $cl): ?>
                    <div class="tf-incident" style="border-left-color:#dc2626;background:rgba(220,38,38,0.12);" onclick="flyToIncident(<?= (float)$cl['lat'] ?>, <?= (float)$cl['lng'] ?>)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <span class="tf-badge-road" style="background:#dc2626;">⛔ <?= e($cl['road']) ?> VOLLSPERRUNG</span>
                            <span style="font-size:0.75rem;color:#fca5a5;font-weight:700;">Gesperrt</span>
                        </div>
                        <div style="font-weight:700;font-size:0.9rem;margin-top:6px;"><?= e($cl['title']) ?></div>
                        <?php if ($cl['subtitle'] !== ''): ?>
                            <div style="font-size:0.78rem;color:#cbd5e1;">Richtung: <?= e($cl['subtitle']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:0.76rem;margin-top:5px;white-space:pre-line;"><?= e(mb_substr($cl['desc'], 0, 220)) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($activeRoadData['warnings'] as $w): ?>
                    <div class="tf-incident" style="border-left-color:#f97316;" onclick="flyToIncident(<?= (float)$w['lat'] ?>, <?= (float)$w['lng'] ?>)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <span class="tf-badge-road"><?= e($w['road']) ?></span>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <?php if ((int)$w['delay_min'] > 0): ?>
                                    <span class="badge" style="background:#ef4444;color:#fff;font-size:0.73rem;">⏱️ +<?= (int)$w['delay_min'] ?> Min.</span>
                                <?php endif; ?>
                                <?php if ((int)$w['speed_kmh'] > 0): ?>
                                    <span class="badge" style="background:rgba(245,158,11,0.2);color:#fbbf24;font-size:0.73rem;">🚗 Ø <?= (int)$w['speed_kmh'] ?> km/h</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-weight:700;font-size:0.9rem;margin-top:6px;">🔴 <?= e($w['title']) ?></div>
                        <?php if ($w['subtitle'] !== ''): ?>
                            <div style="font-size:0.78rem;color:#38bdf8;">Richtung: <?= e($w['subtitle']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:0.76rem;margin-top:5px;white-space:pre-line;"><?= e(mb_substr($w['desc'], 0, 220)) ?></div>
                    </div>
                <?php endforeach; ?>

                <?php foreach (array_slice($activeRoadData['roadworks'], 0, 12) as $rw): ?>
                    <div class="tf-incident" style="border-left-color:#eab308;" onclick="flyToIncident(<?= (float)$rw['lat'] ?>, <?= (float)$rw['lng'] ?>)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <span class="tf-badge-road" style="background:#b45309;">🚧 <?= e($rw['road']) ?> Baustelle</span>
                            <span class="text-muted" style="font-size:0.74rem;"><?= e($rw['subtitle']) ?></span>
                        </div>
                        <div style="font-weight:700;font-size:0.86rem;margin-top:5px;"><?= e($rw['title']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>


    <!-- LOKALER INTRANET-STRASSEN- & GEFAHRENMELDER (FÜR BUNDES-/LANDSTRASSEN & TEAM-LOGBUCH) -->
    <div class="tf-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;">
            <div>
                <h3 style="margin:0;font-size:1.08rem;">📋 Lokaler Intranet-Straßen- &amp; Gefahrenmelder (Bundes-/Landstraßen &amp; Team-Warnungen)</h3>
                <div class="text-muted" style="font-size:0.79rem;">Eigene Meldungen (z. B. Blitzer, Glätte, umgestürzter Baum, Dorfdurchfahrt gesperrt) werden auf der Karte angezeigt und im Webradio-Verkehrsfunk mit vorgelesen.</div>
            </div>
        </div>

        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:10px;align-items:end;margin-bottom:16px;background:rgba(15,23,42,0.45);padding:12px;border-radius:12px;border:1px solid var(--border);">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_local_report">
            <div>
                <label style="font-size:0.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Straße / Ort *</label>
                <input type="text" name="road_name" class="form-control" placeholder="z. B. B27 / L1100 / Ortskern" required>
            </div>
            <div>
                <label style="font-size:0.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Meldungstyp</label>
                <select name="report_type" class="form-control">
                    <option value="stau">🔴 Stau / Stockender Verkehr</option>
                    <option value="sperrung">⛔ Vollsperrung / Unfall</option>
                    <option value="gefahr">⚠️ Gefahr / Glätte / Hindernis</option>
                    <option value="baustelle">🚧 Baustelle / Ampel</option>
                </select>
            </div>
            <div style="grid-column:span 2;">
                <label style="font-size:0.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Kurzbeschreibung (wird auch im Radio vorgelesen) *</label>
                <input type="text" name="title" class="form-control" placeholder="z. B. Umgestürzter Baum hinter Kreisverkehr, 15 Min. Wartezeit" required>
            </div>
            <div>
                <label style="font-size:0.75rem;color:var(--text-muted);display:block;margin-bottom:4px;">Gültigkeit</label>
                <select name="valid_hours" class="form-control">
                    <option value="2">2 Stunden</option>
                    <option value="4" selected>4 Stunden</option>
                    <option value="12">12 Stunden</option>
                    <option value="24">24 Stunden</option>
                    <option value="72">3 Tage</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="width:100%;">➕ Meldung eintragen</button>
            </div>
        </form>

        <?php if (!empty($localReports)): ?>
            <div class="table-responsive">
                <table class="table" style="font-size:0.84rem;margin:0;">
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Straße / Bereich</th>
                            <th>Meldung</th>
                            <th>Gemeldet von</th>
                            <th>Gültig bis</th>
                            <th>Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($localReports as $lr): ?>
                            <tr>
                                <td><span class="badge" style="background:rgba(56,189,248,0.2);color:#38bdf8;"><?= e(strtoupper($lr['report_type'])) ?></span></td>
                                <td><strong><?= e($lr['road_name']) ?></strong></td>
                                <td><?= e($lr['title']) ?></td>
                                <td class="text-muted"><?= e($lr['author_name'] ?: 'Team') ?></td>
                                <td class="text-muted"><?= e((string)$lr['expires_at']) ?></td>
                                <td>
                                    <form method="post" style="display:inline;margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_local_report">
                                        <input type="hidden" name="report_id" value="<?= (int)$lr['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding:3px 8px;font-size:0.74rem;">Erledigt / Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted" style="font-size:0.82rem;">Aktuell sind keine lokalen Intranet-Gefahrenmeldungen eingetragen.</div>
        <?php endif; ?>
    </div>

</div>

<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/leaflet/protomaps-leaflet.js"></script>
<script>
const TF_ROAD_DATA = <?= json_encode($activeRoadData, JSON_UNESCAPED_UNICODE) ?>;
const TF_LOCAL_REPORTS = <?= json_encode($localReports, JSON_UNESCAPED_UNICODE) ?>;
const TF_SPEECH_TEXT = <?= json_encode($radioSpeechText, JSON_UNESCAPED_UNICODE) ?>;

let tfMap = null;
let tfLayerGroup = null;
let showWarnLayer = true;
let showWorkLayer = false;
let showChrgLayer = false;

function initTfMap() {
    const el = document.getElementById('tfMapCanvas');
    if (!el || typeof L === 'undefined') return;

    tfMap = L.map('tfMapCanvas', {
        center: [51.1657, 10.4515],
        zoom: 6,
        minZoom: 5,
        maxZoom: 18
    });

    // 1. Lokaler Raster-Kachel-Fallback
    L.tileLayer('maps.php?action=tile&s={s}&z={z}&x={x}&y={y}', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap · Autobahn GmbH des Bundes'
    }).addTo(tfMap);

    // 2. Hochauflösende 7,2-GB Offline-Deutschland-Vektorkarte (PMTiles Zoom 0-18)
    if (typeof protomapsL !== 'undefined') {
        try {
            protomapsL.leafletLayer({
                url: 'assets/maps/germany_detail.pmtiles',
                theme: 'dark'
            }).addTo(tfMap);
        } catch (e) {}
    }

    tfLayerGroup = L.layerGroup().addTo(tfMap);
    renderTfLayers(true);
}

function renderTfLayers(fitBoundsAfter) {
    if (!tfMap || !tfLayerGroup) return;
    tfLayerGroup.clearLayers();
    const boundsPts = [];

    if (showWarnLayer) {
        // Vollsperrungen (dunkelrot + dicke Linie)
        (TF_ROAD_DATA.closures || []).forEach(cl => {
            if (Array.isArray(cl.line) && cl.line.length > 1) {
                L.polyline(cl.line, { color: '#dc2626', weight: 7, opacity: 0.92 })
                    .bindPopup(`<strong>⛔ ${cl.road} VOLLSPERRUNG</strong><br>${cl.title}<br><small>${cl.subtitle || ''}</small>`)
                    .addTo(tfLayerGroup);
                cl.line.forEach(pt => boundsPts.push(pt));
            }
            if (cl.lat && cl.lng) {
                L.circleMarker([cl.lat, cl.lng], {
                    radius: 8, color: '#ffffff', weight: 2, fillColor: '#dc2626', fillOpacity: 0.95
                }).bindPopup(`<strong>⛔ ${cl.road} GESPERRT</strong><br>${cl.title}`).addTo(tfLayerGroup);
                boundsPts.push([cl.lat, cl.lng]);
            }
        });

        // Staus & Warnungen (orange/rot mit exakter Streckengeometrie)
        (TF_ROAD_DATA.warnings || []).forEach(w => {
            const isSevere = (w.delay_min || 0) >= 10;
            const col = isSevere ? '#ef4444' : '#f97316';
            if (Array.isArray(w.line) && w.line.length > 1) {
                L.polyline(w.line, { color: col, weight: 6, opacity: 0.88 })
                    .bindPopup(`<strong>🔴 ${w.road}: ${w.title}</strong><br>${w.delay_min > 0 ? '⏱️ +' + w.delay_min + ' Min. Verzögerung<br>' : ''}<small>${w.desc || ''}</small>`)
                    .addTo(tfLayerGroup);
                w.line.forEach(pt => boundsPts.push(pt));
            }
            if (w.lat && w.lng) {
                L.circleMarker([w.lat, w.lng], {
                    radius: 7, color: '#1e293b', weight: 2, fillColor: col, fillOpacity: 0.95
                }).bindPopup(`<strong>🔴 ${w.road}: ${w.title}</strong><br>${w.delay_min > 0 ? '⏱️ +' + w.delay_min + ' Min.<br>' : ''}<small>${w.subtitle || ''}</small>`).addTo(tfLayerGroup);
                boundsPts.push([w.lat, w.lng]);
            }
        });
    }

    if (showWorkLayer) {
        (TF_ROAD_DATA.roadworks || []).forEach(rw => {
            if (Array.isArray(rw.line) && rw.line.length > 1) {
                L.polyline(rw.line, { color: '#eab308', weight: 4, dashArray: '6,6', opacity: 0.8 })
                    .bindPopup(`<strong>🚧 ${rw.road} Baustelle</strong><br>${rw.title}`)
                    .addTo(tfLayerGroup);
            }
            if (rw.lat && rw.lng) {
                L.circleMarker([rw.lat, rw.lng], {
                    radius: 6, color: '#1e293b', weight: 1.5, fillColor: '#eab308', fillOpacity: 0.88
                }).bindPopup(`<strong>🚧 ${rw.road} Baustelle</strong><br>${rw.title}`).addTo(tfLayerGroup);
                boundsPts.push([rw.lat, rw.lng]);
            }
        });
    }

    if (showChrgLayer) {
        (TF_ROAD_DATA.chargers || []).forEach(ch => {
            if (ch.lat && ch.lng) {
                L.circleMarker([ch.lat, ch.lng], {
                    radius: 6, color: '#064e3b', weight: 1.5, fillColor: '#10b981', fillOpacity: 0.92
                }).bindPopup(`<strong>⚡ ${ch.road} E-Schnellladesäule</strong><br>${ch.title}<br><small>${ch.desc || ''}</small>`).addTo(tfLayerGroup);
                boundsPts.push([ch.lat, ch.lng]);
            }
        });
    }

    // Lokale Intranet-Meldungen
    (TF_LOCAL_REPORTS || []).forEach(lr => {
        if (lr.lat && lr.lng) {
            L.circleMarker([parseFloat(lr.lat), parseFloat(lr.lng)], {
                radius: 8, color: '#ffffff', weight: 2, fillColor: '#38bdf8', fillOpacity: 0.95
            }).bindPopup(`<strong>📋 Intranet-Meldung (${lr.road_name})</strong><br>${lr.title}`).addTo(tfLayerGroup);
            boundsPts.push([parseFloat(lr.lat), parseFloat(lr.lng)]);
        }
    });

    if (fitBoundsAfter && boundsPts.length > 0) {
        try {
            tfMap.fitBounds(boundsPts, { padding: [36, 36], maxZoom: 11 });
        } catch (e) {}
    }
}

function toggleTfLayer(which) {
    if (which === 'warn') showWarnLayer = !showWarnLayer;
    if (which === 'work') showWorkLayer = !showWorkLayer;
    if (which === 'chrg') showChrgLayer = !showChrgLayer;

    document.getElementById('btnLayerWarn').className = 'btn btn-sm ' + (showWarnLayer ? 'btn-primary' : 'btn-secondary');
    document.getElementById('btnLayerWork').className = 'btn btn-sm ' + (showWorkLayer ? 'btn-primary' : 'btn-secondary');
    document.getElementById('btnLayerChrg').className = 'btn btn-sm ' + (showChrgLayer ? 'btn-primary' : 'btn-secondary');
    renderTfLayers(false);
}

function flyToIncident(lat, lng) {
    if (!tfMap || !lat || !lng) return;
    tfMap.setView([lat, lng], 13, { animate: true });
}

// ============================================================================
// WEBRADIO-VERKEHRSFUNK-SPRECHER MIT AUTOMATISCHEM AUDIO-DUCKING
// ============================================================================
let savedRadioVolumeBeforeTraffic = null;

function restoreRadioVolumeAfterTraffic() {
    if (savedRadioVolumeBeforeTraffic !== null && window.McGlobalRadio) {
        try {
            window.McGlobalRadio.setVolume(savedRadioVolumeBeforeTraffic);
        } catch (e) {}
        savedRadioVolumeBeforeTraffic = null;
    }
    const btn = document.getElementById('btnSpeakTraffic');
    if (btn) btn.innerHTML = '🔊 Verkehrsfunk vorlesen';
}

function toggleSpeakTrafficBulletin(customText) {
    if (!('speechSynthesis' in window)) {
        alert('Dein Browser unterstützt leider keine Sprachausgabe (Web Speech API).');
        return;
    }
    if (window.speechSynthesis.speaking) {
        window.speechSynthesis.cancel();
        restoreRadioVolumeAfterTraffic();
        return;
    }

    const textToSpeak = customText || TF_SPEECH_TEXT;
    const utter = new SpeechSynthesisUtterance(textToSpeak);
    utter.lang = 'de-DE';
    utter.rate = 1.0;

    // Falls das Webradio im Hintergrund läuft -> Lautstärke sanft auf 18% absenken (Audio-Ducking)
    if (window.McGlobalRadio && typeof window.McGlobalRadio.getState === 'function') {
        const st = window.McGlobalRadio.getState();
        if (st && st.playing) {
            savedRadioVolumeBeforeTraffic = typeof st.volume === 'number' ? st.volume : 0.8;
            window.McGlobalRadio.setVolume(Math.min(savedRadioVolumeBeforeTraffic, 0.18));
        }
    }

    const btn = document.getElementById('btnSpeakTraffic');
    if (btn) btn.innerHTML = '⏹️ Durchsage stoppen';

    utter.onend = restoreRadioVolumeAfterTraffic;
    utter.onerror = restoreRadioVolumeAfterTraffic;
    window.speechSynthesis.speak(utter);
}

function toggleHalfHourAutoTraffic(enabled) {
    try {
        localStorage.setItem('mc_auto_traffic_30m', enabled ? '1' : '0');
    } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
    initTfMap();
    try {
        const autoOn = localStorage.getItem('mc_auto_traffic_30m') === '1';
        const chk = document.getElementById('chkAutoTrafficHalfHour');
        if (chk) chk.checked = autoOn;
    } catch (e) {}
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
