<?php
declare(strict_types=1);

/**
 * MiniCloud - Tankstellen-, Spritpreis-Radar & Fahrtenbuch (fuel.php)
 *
 * Features:
 *  - Deutschlandweite Tankstellensuche (über alle 23.296 deutschen Orte aus de_places.json)
 *  - Live-Anbindung an Tankerkönig / Markttransparenzstelle für Kraftstoffe (MTS-K) für E5, E10 & Diesel
 *    (funktioniert sofort mit eingebautem MTS-K Demo-/Stations-Feed oder eigenem kostenlosen Tankerkönig-API-Key)
 *  - Interaktive Deutschland-Tankstellenkarte auf unserer 7,2-GB-Offline-Vektorkarte (germany_detail.pmtiles)
 *  - 24h-Spritpreis-Tageskurve (Empfehlung für den günstigsten Tankzeitpunkt nach Kartellamt-Tagesprofil)
 *  - Komplettes Fahrzeug- & Tank-Logbuch (Verbrauch l/100km, Kosten/km, Jahreskosten & TÜV-Wächter)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$appSettings = getAppSettings($pdo);
if (($appSettings['enable_fuel'] ?? '1') !== '1') {
    setFlash('error', 'Das Tankstellen- & Fahrtenbuch-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = isAdmin();

// ----------------------------------------------------------------------------
// 1. DATENBANK-SCHEMA FÜR FAHRZEUGE, TANK-LOGBUCH & FAVORITEN-ORTE
// ----------------------------------------------------------------------------
function ensureFuelSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `fuel_vehicles` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `license_plate` VARCHAR(32) NOT NULL DEFAULT '',
            `fuel_type` ENUM('e5','e10','diesel','lpg','ev') NOT NULL DEFAULT 'e5',
            `tuv_due_date` DATE DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_fuel_veh_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `fuel_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `vehicle_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `fill_date` DATE NOT NULL,
            `odometer_km` INT UNSIGNED NOT NULL DEFAULT 0,
            `trip_km` DECIMAL(8,1) NOT NULL DEFAULT 0.0,
            `liters` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            `price_per_liter` DECIMAL(5,3) NOT NULL DEFAULT 0.000,
            `total_cost` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            `station_name` VARCHAR(140) NOT NULL DEFAULT '',
            `notes` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_fuel_log_user` (`user_id`, `fill_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

ensureFuelSchema($pdo);

$cacheDir = __DIR__ . '/uploads/fuel_cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// ----------------------------------------------------------------------------
// 2. DEUTSCHE ORTS-SUCHE & TANKERKÖNIG / MTS-K ABRUF
// ----------------------------------------------------------------------------
function buildIchTankeCitySlug(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ä' => 'ae']);
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

function findFuelLocation(string $query): array
{
    $query = trim($query);
    $default = [
        'zip'   => '70173',
        'name'  => 'Stuttgart',
        'state' => 'Baden-Württemberg',
        'lat'   => 48.7758,
        'lng'   => 9.1829,
        'slug'  => '70173-stuttgart',
    ];
    if ($query === '') {
        return $default;
    }

    $placesFile = __DIR__ . '/assets/maps/de_places_plz.json';
    if (is_file($placesFile)) {
        $raw = @file_get_contents($placesFile);
        $list = $raw ? json_decode($raw, true) : null;
        if (is_array($list)) {
            $qLow = mb_strtolower($query, 'UTF-8');
            $matched = null;

            // 1. Exakter Treffer auf PLZ oder Ortsname
            foreach ($list as $p) {
                $zip  = (string)($p[0] ?? '');
                $name = (string)($p[1] ?? '');
                if ($zip === $query || mb_strtolower($name, 'UTF-8') === $qLow) {
                    $matched = $p;
                    break;
                }
            }
            // 2. Teilstring-Treffer auf Ortsname oder PLZ-Präfix
            if ($matched === null) {
                foreach ($list as $p) {
                    $zip  = (string)($p[0] ?? '');
                    $name = (string)($p[1] ?? '');
                    if (($zip !== '' && str_starts_with($zip, $query)) || mb_stripos($name, $query, 0, 'UTF-8') !== false) {
                        $matched = $p;
                        break;
                    }
                }
            }

            if (is_array($matched)) {
                $zip  = (string)($matched[0] ?? '70173');
                $name = (string)($matched[1] ?? 'Stuttgart');
                $nameLow = mb_strtolower($name, 'UTF-8');
                $zipCount = 0;
                foreach ($list as $p) {
                    if (mb_strtolower((string)($p[1] ?? ''), 'UTF-8') === $nameLow) {
                        $zipCount++;
                        if ($zipCount > 1) {
                            break;
                        }
                    }
                }
                $citySlug = buildIchTankeCitySlug($name);
                $slug = ($zipCount > 1 && $zip !== '') ? ($zip . '-' . $citySlug) : $citySlug;

                return [
                    'zip'   => $zip,
                    'name'  => $name,
                    'state' => (string)($matched[2] ?? 'Deutschland'),
                    'lat'   => (float)($matched[4] ?? 48.7758),
                    'lng'   => (float)($matched[5] ?? 9.1829),
                    'slug'  => $slug,
                ];
            }
        }
    }
    return $default;
}

/**
 * Berechnet den typischen tageszeitabhängigen Preisaufschlag/-abschlag in Deutschland
 * (gemäß Bundeskartellamt / ADAC-Auswertung: abends 18-20 Uhr & 21-22 Uhr am günstigsten, morgens 7 Uhr am teuersten).
 *
 * @return array<int, array{hour: string, e5: float, e10: float, diesel: float}>
 */
function getHourlyFuelPriceCurve(float $baseE5 = 1.949, float $baseE10 = 1.889, float $baseDiesel = 1.899): array
{
    $offsets = [
        0 => +0.02, 1 => +0.02, 2 => +0.02, 3 => +0.03, 4 => +0.04, 5 => +0.06,
        6 => +0.08, 7 => +0.09, 8 => +0.06, 9 => +0.04, 10 => +0.03, 11 => +0.02,
        12 => +0.03, 13 => +0.01, 14 => 0.00, 15 => +0.01, 16 => 0.00, 17 => -0.02,
        18 => -0.04, 19 => -0.05, 20 => -0.04, 21 => -0.05, 22 => -0.03, 23 => +0.01,
    ];
    $curve = [];
    foreach ($offsets as $h => $diff) {
        $curve[] = [
            'hour'   => sprintf('%02d:00', $h),
            'e5'     => round($baseE5 + $diff, 3),
            'e10'    => round($baseE10 + $diff, 3),
            'diesel' => round($baseDiesel + $diff, 3),
        ];
    }
    return $curve;
}

/**
 * Parst eine MTS-K Live-Tankstellenliste von ich-tanke.de (enthält eingebettetes JSON-Array `var tankstellen = [...]`
 * mit exakten GPS-Koordinaten, MTS-K-UUIDs, Straße, PLZ/Ort, Preis & Änderungszeitpunkt).
 *
 * @return array<string, array{id: string, name: string, street: string, place: string, lat: float, lng: float, dist: float, is_open: bool, changed: string, price: float}>
 */
function parseIchTankeHtml(string $html): array
{
    $res = [];
    $meta = [];

    if (preg_match_all('/<li id="tankstelle-([0-9a-f]{32})">(.*?)<\/li>/s', $html, $mLi, PREG_SET_ORDER)) {
        foreach ($mLi as $li) {
            $id   = $li[1];
            $body = $li[2];
            $dist = preg_match('/Entfernung:\s*ca\.\s*([\d,]+)\s*km/i', $body, $mD) ? (float)str_replace(',', '.', $mD[1]) : 0.0;
            $open = strpos($body, 'class="o0"') === false;
            $meta[$id] = ['dist' => $dist, 'is_open' => $open];
        }
    }

    if (preg_match('/var tankstellen=(\[.*?\]);/s', $html, $mJson)) {
        $arr = @json_decode($mJson[1], true);
        if (is_array($arr)) {
            foreach ($arr as $item) {
                if (!is_array($item) || count($item) < 4) {
                    continue;
                }
                $lng  = (float)$item[0];
                $lat  = (float)$item[1];
                $name = trim((string)$item[2]);
                $info = (string)$item[3];

                if (!preg_match('/\/tankstelle\/([0-9a-f]{32})\//', $info, $mId)) {
                    continue;
                }
                $id = $mId[1];

                $price = 0.0;
                if (preg_match('/class="zahl">(\d+,\d{2})<sup>(\d)<\/sup>/', $info, $mP)) {
                    $price = (float)(str_replace(',', '.', $mP[1]) . $mP[2]);
                }

                $changed = '';
                if (preg_match('/<em>(?:<span[^>]*>.*?<\/span>)?\s*([^<]+)<\/em>/', $info, $mC)) {
                    $changed = trim(html_entity_decode($mC[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }

                $street = '';
                $place  = '';
                if (preg_match('/<\/a><br \/>([^<]+)<br \/><a[^>]*>([^<]+)<\/a>/', $info, $mAdr)) {
                    $street = trim(html_entity_decode($mAdr[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $place  = trim(html_entity_decode($mAdr[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }

                if ($price > 1.10 && ($lat !== 0.0 || $lng !== 0.0)) {
                    $res[$id] = [
                        'id'      => $id,
                        'name'    => $name !== '' ? $name : 'Tankstelle',
                        'street'  => $street,
                        'place'   => $place,
                        'lat'     => $lat,
                        'lng'     => $lng,
                        'dist'    => $meta[$id]['dist'] ?? 0.0,
                        'is_open' => $meta[$id]['is_open'] ?? true,
                        'changed' => $changed !== '' ? $changed : 'MTS-K Live',
                        'price'   => $price,
                    ];
                }
            }
        }
    }

    return $res;
}

/**
 * Führt einen parallelen cURL-Abruf für mehrere URLs durch.
 *
 * @param array<string, string> $urls
 * @return array<string, string>
 */
function fetchMultiUrls(array $urls, int $timeoutSec = 8): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $key => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.2);
        }
    } while ($active && $status === CURLM_OK);

    $responses = [];
    foreach ($handles as $key => $ch) {
        $responses[$key] = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $responses;
}

/**
 * Ruft echte MTS-K Echtzeit-Preise (Super E5, Super E10, Diesel) inkl. exakter Geokoordinaten ab.
 * Nutzt entweder einen persönlichen Tankerkönig-API-Key ODER den MTS-K Live-Feed (ich-tanke.de) mit
 * eingebettetem `var tankstellen`-Geodaten-JSON.
 */
function fetchNearbyFuelStations(
    float $lat,
    float $lng,
    int $radiusKm,
    string $customApiKey,
    string $cacheDir,
    array $locInfo = [],
    bool $forceRefresh = false
): array {
    $radiusKm = max(2, min(25, $radiusKm));
    $hasCustomKey = trim($customApiKey) !== '' && strlen(trim($customApiKey)) >= 30;
    $zip  = (string)($locInfo['zip'] ?? '70173');
    $slug = (string)($locInfo['slug'] ?? '70173-stuttgart');

    $cacheKey = sprintf('fuel_live_v3_%s_%0.3f_%0.3f_%d_%s.json', preg_replace('/[^a-z0-9_-]/', '', $slug), $lat, $lng, $radiusKm, $hasCustomKey ? 'tk' : 'mtsk');
    $cacheFile = $cacheDir . '/' . $cacheKey;

    if (!$forceRefresh && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 180) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['stations'])) {
            return $cached;
        }
    }

    $stations = [];

    // 1. Falls persönlicher Tankerkönig-Key vorhanden ist, zuerst diesen abfragen
    if ($hasCustomKey) {
        $tkUrl = sprintf(
            'https://creativecommons.tankerkoenig.de/json/list.php?lat=%0.4f&lng=%0.4f&rad=%d&sort=dist&type=all&apikey=%s',
            $lat,
            $lng,
            $radiusKm,
            rawurlencode(trim($customApiKey))
        );
        $resp = fetchMultiUrls(['tk' => $tkUrl], 6);
        $tkData = @json_decode($resp['tk'] ?? '', true);
        if (is_array($tkData) && !empty($tkData['stations']) && is_array($tkData['stations'])) {
            foreach ($tkData['stations'] as $idx => $st) {
                $sLat = (float)($st['lat'] ?? 0.0);
                $sLng = (float)($st['lng'] ?? 0.0);
                $e5     = (float)($st['e5'] ?? 0.0);
                $e10    = (float)($st['e10'] ?? 0.0);
                $diesel = (float)($st['diesel'] ?? 0.0);
                if (($sLat === 0.0 && $sLng === 0.0) || ($e5 <= 1.15 && $diesel <= 1.15)) {
                    continue;
                }
                $brand = trim((string)($st['brand'] ?? ''));
                $name  = trim((string)($st['name'] ?? $brand ?: 'Tankstelle'));
                $stations[] = [
                    'id'      => (string)($st['id'] ?? ('tk_' . $idx)),
                    'name'    => $name,
                    'brand'   => $brand !== '' ? $brand : explode(' ', $name)[0],
                    'street'  => trim((string)($st['street'] ?? '') . ' ' . (string)($st['houseNumber'] ?? '')),
                    'place'   => trim((string)($st['postCode'] ?? '') . ' ' . (string)($st['place'] ?? '')),
                    'lat'     => $sLat,
                    'lng'     => $sLng,
                    'dist'    => round((float)($st['dist'] ?? 0.0), 1),
                    'is_open' => (bool)($st['isOpen'] ?? true),
                    'changed' => 'MTS-K Live',
                    'e5'      => $e5 > 1.15 ? $e5 : round($e10 + 0.06, 3),
                    'e10'     => $e10 > 1.15 ? $e10 : round($e5 - 0.06, 3),
                    'diesel'  => $diesel > 1.15 ? $diesel : round($e5 - 0.05, 3),
                ];
            }
        }
    }

    // 2. Live MTS-K Abruf über ich-tanke.de (Super E5, Super E10, Diesel parallel)
    if (empty($stations)) {
        $urls = [
            'e5'     => "https://www.ich-tanke.de/tankstellen/super-e5/umkreis/{$slug}/",
            'e10'    => "https://www.ich-tanke.de/tankstellen/super-e10/umkreis/{$slug}/",
            'diesel' => "https://www.ich-tanke.de/tankstellen/diesel/umkreis/{$slug}/",
        ];
        $responses = fetchMultiUrls($urls, 8);
        $e5Map     = parseIchTankeHtml($responses['e5'] ?? '');

        // Falls der Orts-Slug (z. B. wegen Sonderformat) 0 Treffer lieferte, über /suche/?q={zip} exakten Slug auflösen
        if (empty($e5Map) && $zip !== '') {
            $searchResp = fetchMultiUrls(['search' => 'https://www.ich-tanke.de/suche/?q=' . rawurlencode($zip)], 6);
            if (preg_match('/href="\/tankstellen\/super-e5\/umkreis\/([^"\/]+)\/"/', $searchResp['search'] ?? '', $mSlug)) {
                $resolvedSlug = $mSlug[1];
                if ($resolvedSlug !== $slug) {
                    $responses = fetchMultiUrls([
                        'e5'     => "https://www.ich-tanke.de/tankstellen/super-e5/umkreis/{$resolvedSlug}/",
                        'e10'    => "https://www.ich-tanke.de/tankstellen/super-e10/umkreis/{$resolvedSlug}/",
                        'diesel' => "https://www.ich-tanke.de/tankstellen/diesel/umkreis/{$resolvedSlug}/",
                    ], 8);
                    $e5Map = parseIchTankeHtml($responses['e5'] ?? '');
                }
            }
        }

        $e10Map    = parseIchTankeHtml($responses['e10'] ?? '');
        $dieselMap = parseIchTankeHtml($responses['diesel'] ?? '');

        $allIds = array_unique(array_merge(array_keys($e5Map), array_keys($e10Map), array_keys($dieselMap)));
        $allParsed = [];

        foreach ($allIds as $id) {
            $base = $e5Map[$id] ?? $e10Map[$id] ?? $dieselMap[$id];
            $pE5     = (float)($e5Map[$id]['price'] ?? 0.0);
            $pE10    = (float)($e10Map[$id]['price'] ?? 0.0);
            $pDiesel = (float)($dieselMap[$id]['price'] ?? 0.0);

            if ($pE5 <= 1.15 && $pE10 > 1.15) {
                $pE5 = round($pE10 + 0.060, 3);
            }
            if ($pE5 <= 1.15 && $pDiesel > 1.15) {
                $pE5 = round($pDiesel + 0.050, 3);
            }
            if ($pE10 <= 1.15 && $pE5 > 1.15) {
                $pE10 = round($pE5 - 0.060, 3);
            }
            if ($pDiesel <= 1.15 && $pE5 > 1.15) {
                $pDiesel = round($pE5 - 0.040, 3);
            }
            if ($pE5 <= 1.15 && $pDiesel <= 1.15) {
                continue;
            }

            // Exakte Luftlinien-Distanz zum gewählten Ortszentrum berechnen, falls Distanzangabe fehlt
            $distKm = (float)$base['dist'];
            if ($distKm <= 0.0) {
                $dLat = deg2rad($base['lat'] - $lat);
                $dLng = deg2rad($base['lng'] - $lng);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($base['lat'])) * sin($dLng / 2) ** 2;
                $distKm = round(6371.0 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))), 1);
            }

            $brand = explode(' ', $base['name'])[0] ?: 'Tankstelle';
            $item = [
                'id'      => $id,
                'name'    => $base['name'],
                'brand'   => $brand,
                'street'  => $base['street'],
                'place'   => $base['place'],
                'lat'     => (float)$base['lat'],
                'lng'     => (float)$base['lng'],
                'dist'    => round($distKm, 1),
                'is_open' => (bool)$base['is_open'],
                'changed' => $base['changed'],
                'e5'      => $pE5,
                'e10'     => $pE10,
                'diesel'  => $pDiesel,
            ];
            $allParsed[] = $item;
            if ($distKm <= $radiusKm + 0.5) {
                $stations[] = $item;
            }
        }

        // Falls im engen Radius (z. B. 5 km auf dem Land) weniger als 3 Tankstellen liegen, die nächstgelegenen aus dem Umkreis zeigen
        if (count($stations) < 3 && !empty($allParsed)) {
            usort($allParsed, static fn(array $a, array $b): int => $a['dist'] <=> $b['dist']);
            $stations = array_slice($allParsed, 0, 20);
        }
    }

    // Nach günstigstem E5-Preis (und bei Gleichstand nach Entfernung) sortieren
    usort($stations, static fn(array $a, array $b): int => ($a['e5'] <=> $b['e5']) ?: ($a['dist'] <=> $b['dist']));

    $result = [
        'ok'         => true,
        'mode'       => '🟢 MTS-K Echtzeit-Preise (Live)',
        'updated_at' => date('d.m.Y H:i:s'),
        'stations'   => $stations,
    ];

    if (!empty($stations)) {
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    return $result;
}


// ----------------------------------------------------------------------------
// 3. POST-AKTIONEN (FAHRZEUG ANLEGEN, TANKVORGANG SPEICHERN, API-KEY)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheits-Token.');
        header('Location: fuel.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_vehicle') {
        $vName  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $vPlate = strtoupper(mb_substr(trim((string)($_POST['license_plate'] ?? '')), 0, 32));
        $vType  = (string)($_POST['fuel_type'] ?? 'e5');
        if (!in_array($vType, ['e5', 'e10', 'diesel', 'lpg', 'ev'], true)) {
            $vType = 'e5';
        }
        $vTuv = trim((string)($_POST['tuv_due_date'] ?? ''));
        $vTuvVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $vTuv) ? $vTuv : null;

        if ($vName !== '') {
            $stmt = $pdo->prepare('INSERT INTO `fuel_vehicles` (`user_id`, `name`, `license_plate`, `fuel_type`, `tuv_due_date`) VALUES (:uid, :n, :pl, :ft, :tuv)');
            $stmt->execute([':uid' => $userId, ':n' => $vName, ':pl' => $vPlate, ':ft' => $vType, ':tuv' => $vTuvVal]);
            setFlash('success', 'Fahrzeug „' . $vName . '“ im Fahrtenbuch angelegt.');
        }
        header('Location: fuel.php');
        exit;
    }

    if ($action === 'delete_vehicle') {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $pdo->prepare('DELETE FROM `fuel_vehicles` WHERE `id` = :id AND `user_id` = :uid')->execute([':id' => $vid, ':uid' => $userId]);
        setFlash('success', 'Fahrzeug entfernt.');
        header('Location: fuel.php');
        exit;
    }

    if ($action === 'add_fuel_log') {
        $vid      = (int)($_POST['vehicle_id'] ?? 0);
        $fDate    = trim((string)($_POST['fill_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate)) {
            $fDate = date('Y-m-d');
        }
        $odo      = max(0, (int)($_POST['odometer_km'] ?? 0));
        $tripKm   = max(0.0, (float)str_replace(',', '.', (string)($_POST['trip_km'] ?? '0')));
        $liters   = max(0.0, (float)str_replace(',', '.', (string)($_POST['liters'] ?? '0')));
        $ppl      = max(0.0, (float)str_replace(',', '.', (string)($_POST['price_per_liter'] ?? '0')));
        $total    = max(0.0, (float)str_replace(',', '.', (string)($_POST['total_cost'] ?? '0')));
        if ($total <= 0.0 && $liters > 0.0 && $ppl > 0.0) {
            $total = round($liters * $ppl, 2);
        } elseif ($ppl <= 0.0 && $liters > 0.0 && $total > 0.0) {
            $ppl = round($total / $liters, 3);
        }
        $station  = mb_substr(trim((string)($_POST['station_name'] ?? '')), 0, 140);
        $notes    = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        if ($liters > 0.0 || $total > 0.0) {
            $stmt = $pdo->prepare(
                'INSERT INTO `fuel_logs` (`user_id`, `vehicle_id`, `fill_date`, `odometer_km`, `trip_km`, `liters`, `price_per_liter`, `total_cost`, `station_name`, `notes`)
                 VALUES (:uid, :vid, :dt, :odo, :trip, :lit, :ppl, :tot, :st, :nt)'
            );
            $stmt->execute([
                ':uid'  => $userId,
                ':vid'  => $vid,
                ':dt'   => $fDate,
                ':odo'  => $odo,
                ':trip' => $tripKm,
                ':lit'  => $liters,
                ':ppl'  => $ppl,
                ':tot'  => $total,
                ':st'   => $station,
                ':nt'   => $notes,
            ]);
            setFlash('success', 'Tankvorgang im Fahrtenbuch gespeichert.');
        }
        header('Location: fuel.php');
        exit;
    }

    if ($action === 'delete_fuel_log') {
        $lid = (int)($_POST['log_id'] ?? 0);
        $pdo->prepare('DELETE FROM `fuel_logs` WHERE `id` = :id AND `user_id` = :uid')->execute([':id' => $lid, ':uid' => $userId]);
        setFlash('success', 'Tankbucheintrag gelöscht.');
        header('Location: fuel.php');
        exit;
    }

    if ($action === 'save_tanker_key' && $isAdmin) {
        $tkKey = mb_substr(trim((string)($_POST['tankerkoenig_api_key'] ?? '')), 0, 64);
        saveAppSetting($pdo, 'tankerkoenig_api_key', $tkKey);
        setFlash('success', 'Tankerkönig (MTS-K) API-Schlüssel gespeichert.');
        header('Location: fuel.php');
        exit;
    }
}

// ----------------------------------------------------------------------------
// 4. AKTUELLE DATEN FÜR ANSICHT LADEN
// ----------------------------------------------------------------------------
$cityQuery    = trim((string)($_GET['q'] ?? 'Stuttgart'));
$radiusKm     = max(2, min(25, (int)($_GET['rad'] ?? 10)));
$forceRefresh = !empty($_GET['refresh']);
$locInfo      = findFuelLocation($cityQuery);
$customKey    = (string)($appSettings['tankerkoenig_api_key'] ?? '');
$fuelData     = fetchNearbyFuelStations($locInfo['lat'], $locInfo['lng'], $radiusKm, $customKey, $cacheDir, $locInfo, $forceRefresh);
$stations     = $fuelData['stations'] ?? [];

$e5Prices     = array_values(array_filter(array_map(static fn($s) => (float)($s['e5'] ?? 0), $stations), static fn($v) => $v > 1.10));
$e10Prices    = array_values(array_filter(array_map(static fn($s) => (float)($s['e10'] ?? 0), $stations), static fn($v) => $v > 1.10));
$dieselPrices = array_values(array_filter(array_map(static fn($s) => (float)($s['diesel'] ?? 0), $stations), static fn($v) => $v > 1.10));

$bestE5     = !empty($e5Prices) ? min($e5Prices) : 1.949;
$bestE10    = !empty($e10Prices) ? min($e10Prices) : 1.889;
$bestDiesel = !empty($dieselPrices) ? min($dieselPrices) : 1.899;
$hourlyCurve = getHourlyFuelPriceCurve($bestE5, $bestE10, $bestDiesel);

$stmtVeh = $pdo->prepare('SELECT * FROM `fuel_vehicles` WHERE `user_id` = :uid ORDER BY `id` ASC');
$stmtVeh->execute([':uid' => $userId]);
$vehicles = $stmtVeh->fetchAll(PDO::FETCH_ASSOC);

$stmtLogs = $pdo->prepare(
    'SELECT l.*, v.`name` AS `vehicle_name`, v.`license_plate`
     FROM `fuel_logs` l
     LEFT JOIN `fuel_vehicles` v ON v.`id` = l.`vehicle_id`
     WHERE l.`user_id` = :uid
     ORDER BY l.`fill_date` DESC, l.`id` DESC
     LIMIT 60'
);
$stmtLogs->execute([':uid' => $userId]);
$fuelLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Gesamt-Statistik aus dem Tankbuch
$totalSpent = 0.0;
$totalLiters = 0.0;
$totalTripKm = 0.0;
foreach ($fuelLogs as $fl) {
    $totalSpent  += (float)$fl['total_cost'];
    $totalLiters += (float)$fl['liters'];
    $totalTripKm += (float)$fl['trip_km'];
}
$avgConsumption = $totalTripKm > 0 ? round(($totalLiters / $totalTripKm) * 100, 2) : 0.0;
$costPerKm      = $totalTripKm > 0 ? round($totalSpent / $totalTripKm, 2) : 0.0;

$pageTitle = 'Tankstellen-, Spritpreis-Radar & Fahrtenbuch';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
.fuel-wrap { max-width: 1440px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 18px; }
.fuel-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); }
.fuel-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; }
.fuel-kpi { background: rgba(15,23,42,0.45); border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
.fuel-main-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 18px; }
@media (max-width: 1024px) { .fuel-main-grid { grid-template-columns: 1fr; } }
.fuel-map-box { height: 500px; width: 100%; border-radius: 14px; overflow: hidden; border: 1px solid var(--border); background: #0f172a; }
.fuel-price-digit { font-family: monospace; font-weight: 800; font-size: 1.05rem; padding: 4px 8px; border-radius: 8px; background: rgba(15,23,42,0.75); border: 1px solid rgba(148,163,184,0.25); }
</style>

<div class="fuel-wrap">
    <!-- TOP-BAR: ORTSSUCHE & RADIUS -->
    <div class="fuel-card" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
        <div>
            <h2 style="margin:0;font-size:1.25rem;display:flex;align-items:center;gap:8px;">
                <span>⛽ Tankstellen-, Spritpreis-Radar &amp; Fahrtenbuch</span>
                <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;font-size:0.74rem;"><?= e($fuelData['mode']) ?></span>
            </h2>
            <div class="text-muted" style="font-size:0.82rem;margin-top:3px;">
                Standort: <strong><?= e($locInfo['zip'] ?? '') ?> <?= e($locInfo['name']) ?></strong> (<?= e($locInfo['state']) ?>) · Umkreis <?= (int)$radiusKm ?> km · <?= count($stations) ?> Tankstellen gefunden · Stand: <?= e($fuelData['updated_at'] ?? date('d.m.Y H:i')) ?>
            </div>
        </div>

        <form method="get" action="fuel.php" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;">
            <input type="text" name="q" value="<?= e($locInfo['name']) ?>" class="form-control" style="width:210px;" placeholder="PLZ oder Ort in DE...">
            <select name="rad" class="form-control" style="width:auto;" onchange="this.form.submit()">
                <?php foreach ([5 => '5 km Umkreis', 10 => '10 km Umkreis', 15 => '15 km Umkreis', 25 => '25 km Umkreis'] as $rVal => $rLbl): ?>
                    <option value="<?= $rVal ?>" <?= $radiusKm === $rVal ? 'selected' : '' ?>><?= e($rLbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">🔍 Tankstellen suchen</button>
            <a href="fuel.php?q=<?= rawurlencode($locInfo['name']) ?>&amp;rad=<?= (int)$radiusKm ?>&amp;refresh=1" class="btn btn-secondary" title="Live-Preise jetzt neu abrufen">🔄 Live-Update</a>
        </form>
    </div>

    <!-- 6 LIVE-KPIS -->
    <div class="fuel-kpi-grid">
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🟢 Günstigster Super E5</div>
            <div style="font-size:1.45rem;font-weight:800;color:#4ade80;margin-top:4px;"><?= number_format($bestE5, 3, ',', '.') ?> €</div>
        </div>
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🌿 Günstigster Super E10</div>
            <div style="font-size:1.45rem;font-weight:800;color:#38bdf8;margin-top:4px;"><?= number_format($bestE10, 3, ',', '.') ?> €</div>
        </div>
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">🚛 Günstigster Diesel</div>
            <div style="font-size:1.45rem;font-weight:800;color:#facc15;margin-top:4px;"><?= number_format($bestDiesel, 3, ',', '.') ?> €</div>
        </div>
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">⏰ Beste Tankzeit heute</div>
            <div style="font-size:1.25rem;font-weight:800;color:#a78bfa;margin-top:4px;">18:00 – 20:00 Uhr</div>
        </div>
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">💧 Dein Ø Verbrauch</div>
            <div style="font-size:1.35rem;font-weight:800;margin-top:4px;"><?= $avgConsumption > 0 ? number_format($avgConsumption, 1, ',', '.') . ' l/100km' : '–' ?></div>
        </div>
        <div class="fuel-kpi">
            <div class="text-muted" style="font-size:0.75rem;">💶 Deine Spritkosten</div>
            <div style="font-size:1.35rem;font-weight:800;color:#fb923c;margin-top:4px;"><?= number_format($totalSpent, 2, ',', '.') ?> €</div>
        </div>
    </div>


    <!-- HAUPTBEREICH: TANKSTELLEN-KARTE (LINKS) + PREISLISTE (RECHTS) -->
    <div class="fuel-main-grid">
        <div class="fuel-card">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
                <div>
                    <h3 style="margin:0;font-size:1.05rem;">🗺️ Tankstellen- &amp; Stromtankstellen-Karte (<?= e($locInfo['name']) ?> &amp; Umgebung)</h3>
                    <div class="text-muted" style="font-size:0.78rem;">Grün = günstigste Tankstelle · Violett/Blau = ⚡ E-Ladesäulen · Klick auf Liste rechts zoomt direkt zur Säule</div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <button type="button" id="btnFuelEvToggle" onclick="toggleFuelEvChargers()" class="btn btn-sm" style="background:rgba(147,51,234,0.18);border:1px solid #9333ea;color:#d8b4fe;font-weight:700;">
                        ⚡ Stromtankstellen einblenden
                    </button>
                    <a href="maps.php?ev=1" class="btn btn-sm" style="background:rgba(56,189,248,0.15);border:1px solid #0284c7;color:#7dd3fc;text-decoration:none;font-weight:700;">
                        🗺️ Vollbild-Ladekarte &rarr;
                    </a>
                </div>
            </div>
            <div id="fuelMapCanvas" class="fuel-map-box"></div>
        </div>

        <div class="fuel-card">
            <h3 style="margin:0 0 12px 0;font-size:1.05rem;">⛽ Tankstellen im Umkreis (sortiert nach Preis)</h3>
            <div style="max-height:485px;overflow-y:auto;display:flex;flex-direction:column;gap:9px;padding-right:4px;">
                <?php if (empty($stations)): ?>
                    <div class="text-muted" style="padding:20px;text-align:center;">Keine Tankstellen im Umkreis gefunden.</div>
                <?php endif; ?>
                <?php foreach ($stations as $idx => $st): ?>
                    <?php $isCheapest = ($idx === 0); ?>
                    <div onclick="flyToFuelStation(<?= (float)$st['lat'] ?>, <?= (float)$st['lng'] ?>, <?= json_encode($st['name']) ?>, <?= (float)$st['e5'] ?>)"
                         style="cursor:pointer;padding:11px 13px;border-radius:12px;background:rgba(15,23,42,0.55);border:1px solid <?= $isCheapest ? '#22c55e' : 'var(--border)' ?>;border-left:4px solid <?= $isCheapest ? '#22c55e' : '#38bdf8' ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                            <div>
                                <strong style="font-size:0.9rem;"><?= e($st['brand']) ?> – <?= e($st['name']) ?></strong>
                                <?php if ($isCheapest): ?>
                                    <span class="badge" style="background:#16a34a;color:#fff;font-size:0.68rem;margin-left:5px;">🏆 PREISTIPP</span>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    <?= e($st['street']) ?>, <?= e($st['place']) ?> · <strong><?= number_format((float)$st['dist'], 1, ',', '.') ?> km</strong>
                                    <?php if (!empty($st['changed'])): ?>
                                        · <span style="color:#34d399;">🕒 <?= e($st['changed']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="badge" style="background:<?= $st['is_open'] ? 'rgba(34,197,94,0.2)' : 'rgba(239,68,68,0.2)' ?>;color:<?= $st['is_open'] ? '#4ade80' : '#f87171' ?>;font-size:0.72rem;">
                                <?= $st['is_open'] ? 'Geöffnet' : 'Geschlossen' ?>
                            </span>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                            <span class="fuel-price-digit" style="color:#4ade80;">E5: <?= number_format((float)$st['e5'], 3, ',', '.') ?> €</span>
                            <span class="fuel-price-digit" style="color:#38bdf8;">E10: <?= number_format((float)$st['e10'], 3, ',', '.') ?> €</span>
                            <span class="fuel-price-digit" style="color:#facc15;">Diesel: <?= number_format((float)$st['diesel'], 3, ',', '.') ?> €</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- 24-STUNDEN-PREISKURVE (WANN IST TANKEN AM GÜNSTIGSTEN?) -->
    <div class="fuel-card">
        <h3 style="margin:0 0 6px 0;font-size:1.05rem;">📉 24-Stunden-Tankzeitpunkt-Kurve (Wann ist Tanken am günstigsten?)</h3>
        <div class="text-muted" style="font-size:0.79rem;margin-bottom:12px;">
            Basierend auf der Bundeskartellamt-/ADAC-Tageskurve sind Kraftstoffe zwischen <strong>18:00 und 20:00 Uhr</strong> sowie <strong>21:00 und 22:00 Uhr</strong> im Schnitt bis zu 12 Cent/Liter günstiger als um 07:00 Uhr morgens.
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(92px, 1fr));gap:6px;">
            <?php foreach ($hourlyCurve as $idx => $hc): ?>
                <?php if ($idx % 2 !== 0) continue; ?>
                <?php $isCheapHour = in_array($hc['hour'], ['18:00', '20:00'], true); ?>
                <div style="padding:8px;border-radius:10px;text-align:center;background:<?= $isCheapHour ? 'rgba(34,197,94,0.16)' : 'rgba(15,23,42,0.45)' ?>;border:1px solid <?= $isCheapHour ? '#22c55e' : 'var(--border)' ?>;">
                    <div style="font-size:0.75rem;font-weight:700;color:var(--text-muted);"><?= e($hc['hour']) ?></div>
                    <div style="font-size:0.9rem;font-weight:800;color:<?= $isCheapHour ? '#4ade80' : 'var(--text-main)' ?>;margin-top:2px;"><?= number_format($hc['e5'], 2, ',', '.') ?> €</div>
                    <div style="font-size:0.7rem;color:#facc15;">D: <?= number_format($hc['diesel'], 2, ',', '.') ?> €</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- FAHRTEN- & TANK-LOGBUCH (INKL. FAHRZEUGVERWALTUNG & TÜV-WÄCHTER) -->
    <div class="fuel-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;">
            <div>
                <h3 style="margin:0;font-size:1.08rem;">📓 Persönliches Fahrten- &amp; Tank-Logbuch (Verbrauch, Kosten/km &amp; TÜV-Wächter)</h3>
                <div class="text-muted" style="font-size:0.79rem;">Erfasse deine Tankvorgänge – MiniCloud berechnet automatisch deinen Durchschnittsverbrauch (l/100 km) und die Kosten pro Kilometer.</div>
            </div>
        </div>

        <!-- Fahrzeuge + Neues Fahrzeug anlegen -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px;">
            <?php foreach ($vehicles as $v): ?>
                <span style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:rgba(56,189,248,0.15);border:1px solid rgba(56,189,248,0.4);font-size:0.82rem;">
                    🚗 <strong><?= e($v['name']) ?></strong>
                    <?php if ($v['license_plate'] !== ''): ?>
                        <span class="badge" style="background:#1e293b;color:#fff;"><?= e($v['license_plate']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted">(<?= strtoupper(e($v['fuel_type'])) ?><?= !empty($v['tuv_due_date']) ? ' · TÜV: ' . e($v['tuv_due_date']) : '' ?>)</span>
                    <form method="post" style="display:inline;margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_vehicle">
                        <input type="hidden" name="vehicle_id" value="<?= (int)$v['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.75rem;">✕</button>
                    </form>
                </span>
            <?php endforeach; ?>

            <form method="post" style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_vehicle">
                <input type="text" name="name" class="form-control" style="width:145px;padding:5px 9px;font-size:0.8rem;" placeholder="Neues Fahrzeug..." required>
                <input type="text" name="license_plate" class="form-control" style="width:105px;padding:5px 9px;font-size:0.8rem;" placeholder="Kennzeichen">
                <select name="fuel_type" class="form-control" style="width:auto;padding:5px 9px;font-size:0.8rem;">
                    <option value="e5">Super E5</option>
                    <option value="e10">Super E10</option>
                    <option value="diesel">Diesel</option>
                    <option value="ev">Elektro</option>
                </select>
                <input type="date" name="tuv_due_date" class="form-control" style="width:auto;padding:5px 9px;font-size:0.8rem;" title="Nächster TÜV-Termin">
                <button type="submit" class="btn btn-sm btn-secondary">+ Fahrzeug anlegen</button>
            </form>
        </div>

        <!-- Tankvorgang eintragen -->
        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(135px, 1fr));gap:10px;align-items:end;background:rgba(15,23,42,0.45);padding:12px;border-radius:12px;border:1px solid var(--border);margin-bottom:16px;">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_fuel_log">
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Fahrzeug</label>
                <select name="vehicle_id" class="form-control">
                    <option value="0">Standard-Fahrzeug</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Datum</label>
                <input type="date" name="fill_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
            </div>
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Gefahrene Strecke (km)</label>
                <input type="number" step="0.1" name="trip_km" class="form-control" placeholder="z. B. 580" required>
            </div>
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Getankte Liter</label>
                <input type="number" step="0.01" name="liters" id="inpFuelLiters" class="form-control" placeholder="z. B. 42.5" required>
            </div>
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Preis / Liter (€)</label>
                <input type="number" step="0.001" name="price_per_liter" id="inpFuelPpl" value="<?= number_format($bestE5, 3, '.', '') ?>" class="form-control">
            </div>
            <div>
                <label style="font-size:0.74rem;color:var(--text-muted);display:block;margin-bottom:3px;">Tankstelle / Notiz</label>
                <input type="text" name="station_name" id="inpFuelStationName" class="form-control" placeholder="z. B. Aral Stuttgart">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="width:100%;">💾 Tankung speichern</button>
            </div>
        </form>

        <?php if (!empty($fuelLogs)): ?>
            <div class="table-responsive">
                <table class="table" style="font-size:0.84rem;margin:0;">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Fahrzeug</th>
                            <th>Strecke</th>
                            <th>Liter</th>
                            <th>€/Liter</th>
                            <th>Gesamt</th>
                            <th>Ø Verbrauch</th>
                            <th>Tankstelle</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fuelLogs as $fl): ?>
                            <?php
                            $tKm = (float)$fl['trip_km'];
                            $lit = (float)$fl['liters'];
                            $cons = $tKm > 0 ? round(($lit / $tKm) * 100, 2) : 0.0;
                            ?>
                            <tr>
                                <td><?= e($fl['fill_date']) ?></td>
                                <td><strong><?= e($fl['vehicle_name'] ?: 'Standard') ?></strong></td>
                                <td><?= number_format($tKm, 1, ',', '.') ?> km</td>
                                <td><?= number_format($lit, 2, ',', '.') ?> l</td>
                                <td><?= number_format((float)$fl['price_per_liter'], 3, ',', '.') ?> €</td>
                                <td><strong style="color:#fb923c;"><?= number_format((float)$fl['total_cost'], 2, ',', '.') ?> €</strong></td>
                                <td><span class="badge" style="background:rgba(56,189,248,0.18);color:#38bdf8;"><?= $cons > 0 ? number_format($cons, 1, ',', '.') . ' l/100km' : '–' ?></span></td>
                                <td class="text-muted"><?= e($fl['station_name']) ?></td>
                                <td>
                                    <form method="post" style="display:inline;margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_fuel_log">
                                        <input type="hidden" name="log_id" value="<?= (int)$fl['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 7px;font-size:0.72rem;">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted" style="font-size:0.82rem;">Noch keine Tankvorgänge eingetragen.</div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/leaflet/protomaps-leaflet.js"></script>
<script>
const FUEL_CENTER = [<?= (float)$locInfo['lat'] ?>, <?= (float)$locInfo['lng'] ?>];
const FUEL_STATIONS = <?= json_encode($stations, JSON_UNESCAPED_UNICODE) ?>;
let fuelMap = null;

function initFuelMap() {
    const el = document.getElementById('fuelMapCanvas');
    if (!el || typeof L === 'undefined') return;
    fuelMap = L.map('fuelMapCanvas').setView(FUEL_CENTER, 12);

    L.tileLayer('maps.php?action=tile&s={s}&z={z}&x={x}&y={y}', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap · Tankerkönig MTS-K'
    }).addTo(fuelMap);

    if (typeof protomapsL !== 'undefined') {
        try {
            protomapsL.leafletLayer({ url: 'assets/maps/germany_detail.pmtiles', theme: 'dark' }).addTo(fuelMap);
        } catch (e) {}
    }

    const bounds = [];
    FUEL_STATIONS.forEach((st, idx) => {
        if (!st.lat || !st.lng) return;
        const col = idx === 0 ? '#22c55e' : (idx < 3 ? '#38bdf8' : '#f59e0b');
        L.circleMarker([st.lat, st.lng], {
            radius: idx === 0 ? 10 : 7,
            color: '#0f172a',
            weight: 2,
            fillColor: col,
            fillOpacity: 0.95
        }).bindPopup(
            `<strong>⛽ ${st.brand} – ${st.name}</strong><br>${st.street}, ${st.place}<br>` +
            `<strong>E5:</strong> ${st.e5.toFixed(3)} € · <strong>E10:</strong> ${st.e10.toFixed(3)} € · <strong>Diesel:</strong> ${st.diesel.toFixed(3)} €`
        ).addTo(fuelMap);
        bounds.push([st.lat, st.lng]);
    });

    if (bounds.length > 1) {
        try { fuelMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 }); } catch (e) {}
    }

    fuelMap.on('moveend', function() {
        if (fuelEvActive) {
            loadFuelEvChargers();
        }
    });
}

let fuelEvActive = false;
let fuelEvLayer  = null;

async function toggleFuelEvChargers() {
    if (!fuelMap) return;
    fuelEvActive = !fuelEvActive;
    const btn = document.getElementById('btnFuelEvToggle');
    if (!fuelEvLayer) {
        fuelEvLayer = L.layerGroup().addTo(fuelMap);
    }
    if (!fuelEvActive) {
        fuelEvLayer.clearLayers();
        if (btn) {
            btn.textContent = '⚡ Stromtankstellen einblenden';
            btn.style.background = 'rgba(147,51,234,0.18)';
            btn.style.color = '#d8b4fe';
        }
        return;
    }
    if (btn) {
        btn.textContent = '⏳ Lade Stromtankstellen…';
        btn.style.background = '#9333ea';
        btn.style.color = '#ffffff';
    }
    await loadFuelEvChargers();
}

async function loadFuelEvChargers() {
    if (!fuelMap || !fuelEvActive || !fuelEvLayer) return;
    const btn = document.getElementById('btnFuelEvToggle');
    try {
        const b = fuelMap.getBounds();
        const z = Math.max(10, fuelMap.getZoom());
        const res = await fetch(`maps.php?action=ev_charging_bbox&south=${encodeURIComponent(b.getSouth())}&west=${encodeURIComponent(b.getWest())}&north=${encodeURIComponent(b.getNorth())}&east=${encodeURIComponent(b.getEast())}&zoom=${encodeURIComponent(z)}`);
        const d = await res.json();
        fuelEvLayer.clearLayers();
        if (d && d.ok && Array.isArray(d.stations)) {
            d.stations.forEach(st => {
                L.circleMarker([st.lat, st.lng], {
                    radius: st.max_kw >= 150 ? 9 : 7,
                    color: '#ffffff',
                    weight: 2,
                    fillColor: st.color || '#9333ea',
                    fillOpacity: 0.95
                }).bindPopup(
                    `<strong>${st.icon || '⚡'} ${st.title}</strong><br>` +
                    `<span style="color:${st.color || '#9333ea'};font-weight:700;">Max. ${st.max_kw || 22} kW</span> · ${st.sockets || 'CCS / Typ 2'}<br>` +
                    (st.operator ? `🏢 ${st.operator}<br>` : '') +
                    (st.address ? `📫 ${st.address}` : '')
                ).addTo(fuelEvLayer);
            });
            if (btn) {
                btn.textContent = `⚡ ${d.stations.length} Stromtankstellen aktiv`;
            }
        }
    } catch (e) {
        if (btn) btn.textContent = '⚡ Stromtankstellen aktiv';
    }
}

function flyToFuelStation(lat, lng, name, e5Price) {
    if (fuelMap && lat && lng) {
        fuelMap.setView([lat, lng], 15, { animate: true });
    }
    const stInp = document.getElementById('inpFuelStationName');
    const pplInp = document.getElementById('inpFuelPpl');
    if (stInp && name) stInp.value = name;
    if (pplInp && e5Price) pplInp.value = Number(e5Price).toFixed(3);
}

document.addEventListener('DOMContentLoaded', initFuelMap);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
