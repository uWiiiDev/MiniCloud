<?php
declare(strict_types=1);

/**
 * MiniCloud – ⛅ Wetter, Regenradar & Landkreis-Warnzentrale (weather.php)
 * Kombiniert die lokale MiniCloud-Karten-Engine (assets/leaflet/ & assets/maps/)
 * mit hochpräzisen DWD-ICON Wetterdaten (via Server-Proxy mit Offline-Cache),
 * mathematischer Astronomie-Engine (Sonnenlauf, Mondphase, Tageslänge),
 * interaktiver Deutschland-Wetter- & Landkreis-Warnkarte, 24h-Stundenverlauf,
 * 7-Tage-Prognose, Outdoor-/Einsatz-Ampel und eigenem Messstationen-Logbuch fürs Intranet.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$isAdminUser = isAdmin();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_weather'] ?? '1') !== '1' && !$isAdminUser) {
    setFlash('error', 'Das Wetter-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$weatherCacheDir = __DIR__ . '/uploads/weather_cache';
if (!is_dir($weatherCacheDir)) {
    @mkdir($weatherCacheDir, 0750, true);
}

// =========================================================================
// 1. DATENBANK-TABELLEN FÜR FAVORITEN-ORTE & LOKALE MESSWERTE INITIALISIEREN
// =========================================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_favorites (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            zip VARCHAR(16) NOT NULL DEFAULT '',
            state VARCHAR(80) NOT NULL DEFAULT '',
            lat DECIMAL(10, 4) NOT NULL,
            lng DECIMAL(10, 4) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wf_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS weather_observations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            station_name VARCHAR(120) NOT NULL,
            temp_c DECIMAL(5, 1) NOT NULL,
            humidity INT NOT NULL DEFAULT 50,
            pressure_hpa INT NOT NULL DEFAULT 1013,
            wind_kmh DECIMAL(5, 1) NOT NULL DEFAULT 0.0,
            rain_mm DECIMAL(6, 1) NOT NULL DEFAULT 0.0,
            condition_label VARCHAR(80) NOT NULL DEFAULT 'Klar / Bewölkt',
            notes VARCHAR(255) NOT NULL DEFAULT '',
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_recorded (recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable) {
    // Tabellen existieren bereits
}

// =========================================================================
// 2. WETTER-CODES (WMO), ASTRONOMIE-BERECHNUNG & OFFLINE-KLIMAMODELL
// =========================================================================
function getWmoWeatherMeta(int $code, bool $isDay = true): array
{
    return match ($code) {
        0  => ['label' => 'Sonnig / Klar',              'icon' => $isDay ? '☀️' : '🌙', 'color' => '#f59e0b', 'warn' => 0],
        1  => ['label' => 'Überwiegend heiter',         'icon' => $isDay ? '🌤️' : '🌙', 'color' => '#fbbf24', 'warn' => 0],
        2  => ['label' => 'Teilweise bewölkt',          'icon' => '⛅',                 'color' => '#38bdf8', 'warn' => 0],
        3  => ['label' => 'Bedeckt',                    'icon' => '☁️',                 'color' => '#64748b', 'warn' => 0],
        45, 48 => ['label' => 'Nebel / Reifnebel',      'icon' => '🌫️',                 'color' => '#94a3b8', 'warn' => 1, 'warn_text' => 'Sichtbehinderung durch dichten Nebel'],
        51, 53, 55 => ['label' => 'Sprühregen',         'icon' => '🌦️',                 'color' => '#0ea5e9', 'warn' => 0],
        56, 57 => ['label' => 'Gefrierender Sprühregen','icon' => '🌧️❄️',               'color' => '#dc2626', 'warn' => 2, 'warn_text' => 'Glättegefahr durch gefrierenden Regen!'],
        61  => ['label' => 'Leichter Regen',            'icon' => '🌦️',                 'color' => '#0284c7', 'warn' => 0],
        63  => ['label' => 'Mäßiger Regen',             'icon' => '🌧️',                 'color' => '#2563eb', 'warn' => 0],
        65  => ['label' => 'Starkregen',                'icon' => '🌧️⚠️',               'color' => '#ea580c', 'warn' => 2, 'warn_text' => 'Warnung vor kräftigem Starkregen'],
        66, 67 => ['label' => 'Gefrierender Regen',     'icon' => '🧊🌧️',               'color' => '#dc2626', 'warn' => 3, 'warn_text' => 'Unwetterwarnung: Glatteisbildung!'],
        71, 73 => ['label' => 'Schneefall',             'icon' => '🌨️',                 'color' => '#6366f1', 'warn' => 1, 'warn_text' => 'Winterliche Straßenverhältnisse / Schneeglätte'],
        75, 77 => ['label' => 'Starker Schneefall',     'icon' => '❄️⚠️',               'color' => '#dc2626', 'warn' => 2, 'warn_text' => 'Warnung vor starkem Schneefall & Verwehungen'],
        80, 81 => ['label' => 'Regenschauer',           'icon' => '🌦️',                 'color' => '#0284c7', 'warn' => 0],
        82  => ['label' => 'Heftige Regenschauer',      'icon' => '⛈️',                 'color' => '#ea580c', 'warn' => 2, 'warn_text' => 'Kräftige Schauer mit Starkregen'],
        85, 86 => ['label' => 'Schneeschauer',          'icon' => '🌨️',                 'color' => '#6366f1', 'warn' => 1, 'warn_text' => 'Glätte durch Schneeschauer'],
        95  => ['label' => 'Gewitter',                  'icon' => '⛈️⚡',               'color' => '#ea580c', 'warn' => 2, 'warn_text' => 'Wetterwarnung: Gewitter mit Sturmböen'],
        96, 99 => ['label' => 'Schweres Gewitter / Hagel','icon' => '⛈️🚨',             'color' => '#dc2626', 'warn' => 3, 'warn_text' => 'Unwetterwarnung: Schweres Gewitter mit Hagel & Orkanböen!'],
        default => ['label' => 'Wechselhaft',           'icon' => '⛅',                 'color' => '#38bdf8', 'warn' => 0],
    };
}

function windDegToCompass(float $deg): string
{
    $dirs = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    $idx = (int)round(((fmod($deg, 360.0) + 360.0) / 22.5)) % 16;
    return $dirs[$idx];
}

/**
 * Berechnet 100 % offline Sonnenaufgang, Sonnenuntergang, Tageslänge & Mondphase für beliebige Koordinaten.
 */
function calculateAstronomyOffline(float $lat, float $lng, ?int $ts = null): array
{
    $ts = $ts ?? time();
    $sunInfo = @date_sun_info($ts, $lat, $lng);
    $sunriseTs = isset($sunInfo['sunrise']) && is_int($sunInfo['sunrise']) ? $sunInfo['sunrise'] : strtotime('today 07:00');
    $sunsetTs  = isset($sunInfo['sunset'])  && is_int($sunInfo['sunset'])  ? $sunInfo['sunset']  : strtotime('today 18:00');
    $dayLenSec = max(0, $sunsetTs - $sunriseTs);
    $dayHours  = (int)floor($dayLenSec / 3600);
    $dayMins   = (int)round(($dayLenSec % 3600) / 60);

    // Mondphasen-Berechnung (Synodischer Monat = 29,53058867 Tage)
    $knownNewMoon = 947182440; // 2000-01-06 18:14 UTC
    $synodicSecs  = 29.53058867 * 86400.0;
    $phaseRatio   = fmod(($ts - $knownNewMoon) / $synodicSecs, 1.0);
    if ($phaseRatio < 0) {
        $phaseRatio += 1.0;
    }

    if ($phaseRatio < 0.06 || $phaseRatio > 0.94) {
        $moon = ['icon' => '🌑', 'label' => 'Neumond'];
    } elseif ($phaseRatio < 0.22) {
        $moon = ['icon' => '🌒', 'label' => 'Zunehmende Sichel'];
    } elseif ($phaseRatio < 0.31) {
        $moon = ['icon' => '🌓', 'label' => 'Erstes Viertel'];
    } elseif ($phaseRatio < 0.44) {
        $moon = ['icon' => '🌔', 'label' => 'Zunehmender Mond'];
    } elseif ($phaseRatio < 0.56) {
        $moon = ['icon' => '🌕', 'label' => 'Vollmond'];
    } elseif ($phaseRatio < 0.69) {
        $moon = ['icon' => '🌖', 'label' => 'Abnehmender Mond'];
    } elseif ($phaseRatio < 0.78) {
        $moon = ['icon' => '🌗', 'label' => 'Letztes Viertel'];
    } else {
        $moon = ['icon' => '🌘', 'label' => 'Abnehmende Sichel'];
    }

    return [
        'sunrise'     => date('H:i', $sunriseTs),
        'sunset'      => date('H:i', $sunsetTs),
        'day_length'  => sprintf('%d Std. %02d Min.', $dayHours, $dayMins),
        'is_day'      => ($ts >= $sunriseTs && $ts <= $sunsetTs),
        'moon_icon'   => $moon['icon'],
        'moon_label'  => $moon['label'],
        'moon_pct'    => (int)round((1.0 - abs($phaseRatio - 0.5) * 2.0) * 100),
    ];
}

/**
 * Erzeugt ein realistisches, 100 % lokales Offline-Wettermodell (falls Server im Air-Gap-Modus läuft
 * und noch kein gecachter Wetterbericht für diesen Ort vorliegt).
 */
function buildOfflineClimateForecast(float $lat, float $lng): array
{
    $month = (int)date('n');
    // Typische Monats-Mitteltemperaturen für Mitteleuropa (Jan..Dez)
    $monthlyAvgMax = [1 => 3.5, 2 => 5.5, 3 => 10.2, 4 => 15.1, 5 => 19.6, 6 => 23.1, 7 => 25.2, 8 => 24.8, 9 => 19.8, 10 => 14.1, 11 => 8.2, 12 => 4.5];
    $monthlyAvgMin = [1 => -1.5, 2 => -0.8, 3 => 2.1, 4 => 5.4, 5 => 9.5, 6 => 12.8, 7 => 14.7, 8 => 14.3, 9 => 10.8, 10 => 6.8, 11 => 2.9, 12 => -0.2];

    $latDelta = (50.5 - $lat) * 0.45;
    $baseMax  = ($monthlyAvgMax[$month] ?? 12.0) + $latDelta;
    $baseMin  = ($monthlyAvgMin[$month] ?? 5.0) + $latDelta;
    $hourNow  = (int)date('G');

    $hourly = [];
    for ($h = 0; $h < 24; $h++) {
        $tsH = strtotime(sprintf('today +%d hours', $h));
        $wave = sin((($h - 5) / 24.0) * 2.0 * M_PI);
        $temp = round(($baseMin + $baseMax) / 2.0 + (($baseMax - $baseMin) / 2.0) * $wave, 1);
        $wmo  = ($h >= 13 && $h <= 17) ? 2 : 1;
        $hourly[] = [
            'time'      => date('H:00', $tsH),
            'temp'      => $temp,
            'rain_prob' => ($h >= 15 && $h <= 18) ? 25 : 10,
            'rain_mm'   => 0.0,
            'wind_kmh'  => round(11.0 + ($h % 5) * 1.5, 1),
            'wmo'       => $wmo,
        ];
    }

    $daily = [];
    $wmoCycle = [1, 2, 0, 3, 61, 2, 1];
    for ($d = 0; $d < 7; $d++) {
        $tsD = strtotime(sprintf('today +%d days', $d));
        $seed = ((int)abs(crc32(date('Y-m-d', $tsD) . '_' . round($lat, 1))) % 50) / 10.0 - 2.5;
        $wmo  = $wmoCycle[$d % count($wmoCycle)];
        $daily[] = [
            'date'      => date('Y-m-d', $tsD),
            't_max'     => round($baseMax + $seed, 1),
            't_min'     => round($baseMin + $seed * 0.7, 1),
            'rain_sum'  => $wmo === 61 ? 3.4 : 0.0,
            'rain_prob' => $wmo === 61 ? 65 : 15,
            'wind_max'  => round(16.0 + ($d * 2.3) % 12, 1),
            'wmo'       => $wmo,
        ];
    }

    $curTemp = $hourly[$hourNow]['temp'] ?? round(($baseMax + $baseMin) / 2.0, 1);
    return [
        'source' => 'offline-climate-model',
        'current' => [
            'temp'       => $curTemp,
            'feels_like' => round($curTemp - 0.8, 1),
            'humidity'   => 68,
            'pressure'   => 1016,
            'wind_kmh'   => 14.0,
            'gusts_kmh'  => 24.0,
            'wind_deg'   => 245,
            'rain_mm'    => 0.0,
            'uv_index'   => $month >= 5 && $month <= 8 ? 5.5 : 2.0,
            'wmo'        => 2,
        ],
        'hourly' => $hourly,
        'daily'  => $daily,
    ];
}

/**
 * Ruft aktuelle Wetter-, 24h-Stunden- & 7-Tage-Daten ab (mit lokalem Festplatten-Cache & Air-Gap-Fallback).
 */
function fetchWeatherForCoords(float $lat, float $lng, string $cacheDir, bool $allowNetwork = true): array
{
    $cacheKey  = sprintf('wx_%s_%s.json', number_format($lat, 2, '.', ''), number_format($lng, 2, '.', ''));
    $cacheFile = $cacheDir . '/' . $cacheKey;

    // 1. Frischer Cache vorhanden (< 20 Minuten alt)?
    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 1200) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['current'])) {
            $cached['source'] = 'local-cache';
            return $cached;
        }
    }

    // 2. Falls Netzwerk erlaubt: Live-Daten vom DWD-ICON / Open-Meteo Modell abrufen
    if ($allowNetwork) {
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&current=temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,surface_pressure,wind_speed_10m,wind_direction_10m,wind_gusts_10m&hourly=temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,uv_index_max&timezone=Europe%%2FBerlin&forecast_days=7',
            number_format($lat, 4, '.', ''),
            number_format($lng, 4, '.', '')
        );
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3.0,
                'header'  => "User-Agent: MiniCloud-Weather-Module/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $json = is_string($raw) ? @json_decode($raw, true) : null;

        if (is_array($json) && isset($json['current'])) {
            $c = $json['current'];
            $hourly = [];
            $nowHourPrefix = date('Y-m-d\TH');
            $startIdx = 0;
            if (isset($json['hourly']['time']) && is_array($json['hourly']['time'])) {
                foreach ($json['hourly']['time'] as $idx => $tStr) {
                    if (str_starts_with((string)$tStr, $nowHourPrefix)) {
                        $startIdx = (int)$idx;
                        break;
                    }
                }
                for ($i = $startIdx; $i < min($startIdx + 24, count($json['hourly']['time'])); $i++) {
                    $tRaw = (string)($json['hourly']['time'][$i] ?? '');
                    $hourly[] = [
                        'time'      => substr($tRaw, 11, 5) ?: '00:00',
                        'temp'      => round((float)($json['hourly']['temperature_2m'][$i] ?? 0.0), 1),
                        'rain_prob' => (int)($json['hourly']['precipitation_probability'][$i] ?? 0),
                        'rain_mm'   => round((float)($json['hourly']['precipitation'][$i] ?? 0.0), 1),
                        'wind_kmh'  => round((float)($json['hourly']['wind_speed_10m'][$i] ?? 0.0), 1),
                        'wmo'       => (int)($json['hourly']['weather_code'][$i] ?? 1),
                    ];
                }
            }

            $daily = [];
            if (isset($json['daily']['time']) && is_array($json['daily']['time'])) {
                foreach ($json['daily']['time'] as $i => $dStr) {
                    $daily[] = [
                        'date'      => (string)$dStr,
                        't_max'     => round((float)($json['daily']['temperature_2m_max'][$i] ?? 0.0), 1),
                        't_min'     => round((float)($json['daily']['temperature_2m_min'][$i] ?? 0.0), 1),
                        'rain_sum'  => round((float)($json['daily']['precipitation_sum'][$i] ?? 0.0), 1),
                        'rain_prob' => (int)($json['daily']['precipitation_probability_max'][$i] ?? 0),
                        'wind_max'  => round((float)($json['daily']['wind_speed_10m_max'][$i] ?? 0.0), 1),
                        'uv_max'    => round((float)($json['daily']['uv_index_max'][$i] ?? 0.0), 1),
                        'wmo'       => (int)($json['daily']['weather_code'][$i] ?? 1),
                    ];
                }
            }

            $result = [
                'source'     => 'live-dwd-icon',
                'updated_at' => date('d.m.Y H:i'),
                'current'    => [
                    'temp'       => round((float)($c['temperature_2m'] ?? 12.0), 1),
                    'feels_like' => round((float)($c['apparent_temperature'] ?? 11.0), 1),
                    'humidity'   => (int)($c['relative_humidity_2m'] ?? 65),
                    'pressure'   => (int)round((float)($c['surface_pressure'] ?? 1013)),
                    'wind_kmh'   => round((float)($c['wind_speed_10m'] ?? 10.0), 1),
                    'gusts_kmh'  => round((float)($c['wind_gusts_10m'] ?? 18.0), 1),
                    'wind_deg'   => (int)($c['wind_direction_10m'] ?? 240),
                    'rain_mm'    => round((float)($c['precipitation'] ?? 0.0), 1),
                    'uv_index'   => $daily[0]['uv_max'] ?? 2.0,
                    'wmo'        => (int)($c['weather_code'] ?? 1),
                ],
                'hourly' => $hourly,
                'daily'  => $daily,
            ];
            @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $result;
        }
    }

    // 3. Älterer Cache auf der Festplatte vorhanden (Offline-Betrieb nach vorherigem Abruf)?
    if (is_file($cacheFile)) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['current'])) {
            $cached['source'] = 'offline-disk-cache';
            return $cached;
        }
    }

    // 4. Reiner Air-Gap-Offline-Betrieb: Lokales mathematisches Klimamodell liefern
    return buildOfflineClimateForecast($lat, $lng);
}

/**
 * 16 Referenz-Stationen für die interaktive Deutschland-Wetter- & Warnkarte.
 */
function getGermanyWeatherStations(string $cacheDir, bool $allowNetwork): array
{
    $stations = [
        ['name' => 'Sylt / Nordsee',     'state' => 'Schleswig-Holstein',     'lat' => 54.91, 'lng' => 8.31],
        ['name' => 'Kiel / Ostsee',      'state' => 'Schleswig-Holstein',     'lat' => 54.32, 'lng' => 10.13],
        ['name' => 'Rostock',            'state' => 'Mecklenburg-Vorpommern', 'lat' => 54.09, 'lng' => 12.10],
        ['name' => 'Hamburg',            'state' => 'Hamburg',                'lat' => 53.55, 'lng' => 9.99],
        ['name' => 'Bremen',             'state' => 'Bremen',                 'lat' => 53.08, 'lng' => 8.80],
        ['name' => 'Hannover',           'state' => 'Niedersachsen',          'lat' => 52.38, 'lng' => 9.73],
        ['name' => 'Berlin',             'state' => 'Berlin',                 'lat' => 52.52, 'lng' => 13.40],
        ['name' => 'Münster',            'state' => 'Nordrhein-Westfalen',    'lat' => 51.96, 'lng' => 7.63],
        ['name' => 'Düsseldorf / Köln',  'state' => 'Nordrhein-Westfalen',    'lat' => 51.10, 'lng' => 6.85],
        ['name' => 'Kassel',             'state' => 'Hessen',                 'lat' => 51.31, 'lng' => 9.48],
        ['name' => 'Leipzig / Dresden',  'state' => 'Sachsen',                'lat' => 51.20, 'lng' => 13.05],
        ['name' => 'Erfurt',             'state' => 'Thüringen',              'lat' => 50.98, 'lng' => 11.03],
        ['name' => 'Frankfurt am Main',  'state' => 'Hessen',                 'lat' => 50.11, 'lng' => 8.68],
        ['name' => 'Trier / Saarbrücken','state' => 'Rheinland-Pfalz',        'lat' => 49.50, 'lng' => 6.80],
        ['name' => 'Nürnberg',           'state' => 'Bayern',                 'lat' => 49.45, 'lng' => 11.08],
        ['name' => 'Stuttgart',          'state' => 'Baden-Württemberg',      'lat' => 48.78, 'lng' => 9.18],
        ['name' => 'Freiburg / Oberrhein','state' => 'Baden-Württemberg',     'lat' => 48.00, 'lng' => 7.84],
        ['name' => 'München / Alpenrand','state' => 'Bayern',                 'lat' => 48.14, 'lng' => 11.58],
    ];

    $gridCacheFile = $cacheDir . '/germany_grid_18.json';
    if (is_file($gridCacheFile) && (time() - (int)@filemtime($gridCacheFile)) < 1800) {
        $cached = @json_decode((string)@file_get_contents($gridCacheFile), true);
        if (is_array($cached) && count($cached) === count($stations)) {
            return $cached;
        }
    }

    if ($allowNetwork) {
        $lats = implode(',', array_map(static fn($s) => number_format($s['lat'], 2, '.', ''), $stations));
        $lngs = implode(',', array_map(static fn($s) => number_format($s['lng'], 2, '.', ''), $stations));
        $url  = "https://api.open-meteo.com/v1/forecast?latitude={$lats}&longitude={$lngs}&current=temperature_2m,precipitation,weather_code,wind_speed_10m,wind_gusts_10m&timezone=Europe%2FBerlin";
        $ctx  = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3.5, 'header' => "User-Agent: MiniCloud-WeatherGrid/1.0\r\n"]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $arr  = is_string($raw) ? @json_decode($raw, true) : null;
        if (is_array($arr) && isset($arr[0]['current'])) {
            foreach ($stations as $i => &$st) {
                $c = $arr[$i]['current'] ?? [];
                $wmo = (int)($c['weather_code'] ?? 1);
                $meta = getWmoWeatherMeta($wmo, true);
                $gust = round((float)($c['wind_gusts_10m'] ?? 15.0), 1);
                $rain = round((float)($c['precipitation'] ?? 0.0), 1);
                $warnLvl = (int)$meta['warn'];
                $warnTxt = (string)($meta['warn_text'] ?? '');
                if ($gust >= 75.0 && $warnLvl < 3) {
                    $warnLvl = 3;
                    $warnTxt = 'Unwetterwarnung: Schwere Sturmböen (' . $gust . ' km/h)';
                } elseif ($gust >= 50.0 && $warnLvl < 1) {
                    $warnLvl = 1;
                    $warnTxt = 'Wind-/Sturmböen bis ' . $gust . ' km/h';
                }
                $st['temp']      = round((float)($c['temperature_2m'] ?? 12.0), 1);
                $st['rain_mm']   = $rain;
                $st['wind_kmh']  = round((float)($c['wind_speed_10m'] ?? 10.0), 1);
                $st['gusts_kmh'] = $gust;
                $st['wmo']       = $wmo;
                $st['icon']      = $meta['icon'];
                $st['label']     = $meta['label'];
                $st['warn_lvl']  = $warnLvl;
                $st['warn_text'] = $warnTxt;
            }
            unset($st);
            @file_put_contents($gridCacheFile, json_encode($stations, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $stations;
        }
    }

    if (is_file($gridCacheFile)) {
        $cached = @json_decode((string)@file_get_contents($gridCacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }

    // Offline-Fallback für alle 18 deutschen Stationen
    foreach ($stations as &$st) {
        $fc = buildOfflineClimateForecast($st['lat'], $st['lng']);
        $meta = getWmoWeatherMeta((int)$fc['current']['wmo'], true);
        $st['temp']      = $fc['current']['temp'];
        $st['rain_mm']   = $fc['current']['rain_mm'];
        $st['wind_kmh']  = $fc['current']['wind_kmh'];
        $st['gusts_kmh'] = $fc['current']['gusts_kmh'];
        $st['wmo']       = $fc['current']['wmo'];
        $st['icon']      = $meta['icon'];
        $st['label']     = $meta['label'];
        $st['warn_lvl']  = 0;
        $st['warn_text'] = '';
    }
    unset($st);
    return $stations;
}

// =========================================================================
// 3. POST & AJAX AKTIONEN (FAVORITEN, EIGENE WETTERSTATION / MESSBUCH, OFFLINE-SUCHE)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'offline_search') {
    header('Content-Type: application/json; charset=UTF-8');
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    $plzFile = __DIR__ . '/assets/maps/de_places_plz.json';
    $results = [];
    if (is_file($plzFile)) {
        $entries = @json_decode((string)@file_get_contents($plzFile), true);
        if (is_array($entries)) {
            $exact = [];
            $part  = [];
            foreach ($entries as $r) {
                $zip   = (string)($r[0] ?? '');
                $name  = (string)($r[1] ?? '');
                $state = (string)($r[2] ?? '');
                $kreis = (string)($r[3] ?? '');
                $nl = mb_strtolower($name);
                if (str_starts_with($zip, $q) || str_starts_with($nl, $q)) {
                    $exact[] = ['zip' => $zip, 'name' => $name, 'state' => $state, 'kreis' => $kreis, 'lat' => (float)$r[4], 'lng' => (float)$r[5]];
                    if (count($exact) >= 15) {
                        break;
                    }
                } elseif (count($part) < 12 && str_contains($nl, $q)) {
                    $part[] = ['zip' => $zip, 'name' => $name, 'state' => $state, 'kreis' => $kreis, 'lat' => (float)$r[4], 'lng' => (float)$r[5]];
                }
            }
            $results = array_slice(array_merge($exact, $part), 0, 15);
        }
    }
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    if (!verifyCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        setFlash('error', 'Ungültiges Sicherheits-Token.');
        header('Location: weather.php');
        exit;
    }

    if ($postAction === 'add_favorite') {
        $name  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $zip   = mb_substr(trim((string)($_POST['zip'] ?? '')), 0, 16);
        $state = mb_substr(trim((string)($_POST['state'] ?? '')), 0, 80);
        $lat   = max(-90.0, min(90.0, (float)str_replace(',', '.', (string)($_POST['lat'] ?? '51.16'))));
        $lng   = max(-180.0, min(180.0, (float)str_replace(',', '.', (string)($_POST['lng'] ?? '10.45'))));
        if ($name !== '') {
            $stmt = $pdo->prepare('INSERT INTO weather_favorites (user_id, name, zip, state, lat, lng) VALUES (:uid, :name, :zip, :st, :lat, :lng)');
            $stmt->execute([':uid' => $userId, ':name' => $name, ':zip' => $zip, ':st' => $state, ':lat' => $lat, ':lng' => $lng]);
            setFlash('success', 'Wetter-Favorit „' . $name . '“ gespeichert.');
        }
        header(sprintf('Location: weather.php?lat=%s&lng=%s&name=%s&zip=%s&state=%s', rawurlencode((string)$lat), rawurlencode((string)$lng), rawurlencode($name), rawurlencode($zip), rawurlencode($state)));
        exit;
    }

    if ($postAction === 'delete_favorite') {
        $favId = (int)($_POST['fav_id'] ?? 0);
        $pdo->prepare('DELETE FROM weather_favorites WHERE id = :id AND user_id = :uid')->execute([':id' => $favId, ':uid' => $userId]);
        setFlash('success', 'Favorit entfernt.');
        header('Location: weather.php');
        exit;
    }

    if ($postAction === 'add_observation') {
        $station  = mb_substr(trim((string)($_POST['station_name'] ?? 'Eigene Messung')), 0, 120) ?: 'Eigene Messung';
        $tempC    = max(-60.0, min(65.0, (float)str_replace(',', '.', (string)($_POST['temp_c'] ?? '15'))));
        $humidity = max(0, min(100, (int)($_POST['humidity'] ?? 60)));
        $pressure = max(850, min(1090, (int)($_POST['pressure_hpa'] ?? 1013)));
        $windKmh  = max(0.0, min(300.0, (float)str_replace(',', '.', (string)($_POST['wind_kmh'] ?? '0'))));
        $rainMm   = max(0.0, min(500.0, (float)str_replace(',', '.', (string)($_POST['rain_mm'] ?? '0'))));
        $condLbl  = mb_substr(trim((string)($_POST['condition_label'] ?? 'Klar / Bewölkt')), 0, 80);
        $notes    = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        $stmt = $pdo->prepare('
            INSERT INTO weather_observations (user_id, station_name, temp_c, humidity, pressure_hpa, wind_kmh, rain_mm, condition_label, notes)
            VALUES (:uid, :st, :t, :h, :p, :w, :r, :c, :n)
        ');
        $stmt->execute([
            ':uid' => $userId,
            ':st'  => $station,
            ':t'   => $tempC,
            ':h'   => $humidity,
            ':p'   => $pressure,
            ':w'   => $windKmh,
            ':r'   => $rainMm,
            ':c'   => $condLbl,
            ':n'   => $notes,
        ]);
        setFlash('success', 'Lokaler Wetter-Messwert im Intranet-Logbuch protokolliert.');
        header('Location: weather.php');
        exit;
    }

    if ($postAction === 'delete_observation') {
        $obsId = (int)($_POST['obs_id'] ?? 0);
        $pdo->prepare('DELETE FROM weather_observations WHERE id = :id AND (user_id = :uid OR :adm = 1)')->execute([':id' => $obsId, ':uid' => $userId, ':adm' => $isAdminUser ? 1 : 0]);
        setFlash('success', 'Messwert gelöscht.');
        header('Location: weather.php');
        exit;
    }
}


// =========================================================================
// 4. DATEN LADEN & STANDARD-FAVORITEN
// =========================================================================
$stmtFav = $pdo->prepare('SELECT * FROM weather_favorites WHERE user_id = :uid ORDER BY is_default DESC, id ASC');
$stmtFav->execute([':uid' => $userId]);
$favorites = $stmtFav->fetchAll();

if (empty($favorites)) {
    $seedFavs = [
        ['Berlin', '10115', 'Berlin', 52.5200, 13.4050, 1],
        ['Hamburg', '20095', 'Hamburg', 53.5511, 9.9937, 0],
        ['Düsseldorf', '40213', 'Nordrhein-Westfalen', 51.2277, 6.7735, 0],
        ['Frankfurt am Main', '60311', 'Hessen', 50.1109, 8.6821, 0],
        ['München', '80331', 'Bayern', 48.1351, 11.5820, 0],
    ];
    $insFav = $pdo->prepare('INSERT INTO weather_favorites (user_id, name, zip, state, lat, lng, is_default) VALUES (:uid, :n, :z, :st, :lat, :lng, :def)');
    foreach ($seedFavs as $sf) {
        $insFav->execute([':uid' => $userId, ':n' => $sf[0], ':z' => $sf[1], ':st' => $sf[2], ':lat' => $sf[3], ':lng' => $sf[4], ':def' => $sf[5]]);
    }
    $stmtFav->execute([':uid' => $userId]);
    $favorites = $stmtFav->fetchAll();
}

$activeFav   = $favorites[0] ?? ['name' => 'Berlin', 'zip' => '10115', 'state' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050];
$activeLat   = isset($_GET['lat']) ? max(-90.0, min(90.0, (float)str_replace(',', '.', (string)$_GET['lat']))) : (float)$activeFav['lat'];
$activeLng   = isset($_GET['lng']) ? max(-180.0, min(180.0, (float)str_replace(',', '.', (string)$_GET['lng']))) : (float)$activeFav['lng'];
$activeName  = isset($_GET['name']) && trim((string)$_GET['name']) !== '' ? trim((string)$_GET['name']) : (string)$activeFav['name'];
$activeZip   = isset($_GET['zip']) ? trim((string)$_GET['zip']) : (string)($activeFav['zip'] ?? '');
$activeState = isset($_GET['state']) ? trim((string)$_GET['state']) : (string)($activeFav['state'] ?? '');

$weather     = fetchWeatherForCoords($activeLat, $activeLng);
$curr        = $weather['current'];
$currMeta    = getWmoWeatherMeta((int)$curr['wmo'], (bool)$curr['is_day']);
$astro       = calculateAstronomyOffline($activeLat, $activeLng);
$deStations  = getGermanyWeatherStations();

// Warnstufe für aktuellen Standort ermitteln
$activeWarnLvl = 0;
$activeWarnTxt = '';
if ($curr['gusts_kmh'] >= 75 || $curr['rain_mm'] >= 15 || in_array((int)$curr['wmo'], [96, 99], true)) {
    $activeWarnLvl = 3;
    $activeWarnTxt = 'UNWETTERWARNUNG (Stufe 3): Schwere Sturmböen (' . $curr['gusts_kmh'] . ' km/h), Starkregen oder schweres Gewitter möglich!';
} elseif ($curr['gusts_kmh'] >= 50 || $curr['rain_mm'] >= 5 || in_array((int)$curr['wmo'], [65, 82, 95], true)) {
    $activeWarnLvl = 2;
    $activeWarnTxt = 'WETTERWARNUNG (Stufe 2): Markante Windböen (' . $curr['gusts_kmh'] . ' km/h), kräftiger Niederschlag oder Gewitter.';
} elseif ($curr['temp'] <= 0.5) {
    $activeWarnLvl = 1;
    $activeWarnTxt = 'GLÄTTE-/FROSTHINWEIS (Stufe 1): Temperaturen um oder unter dem Gefrierpunkt (' . $curr['temp'] . ' °C).';
}

// Outdoor- & Einsatz-Ampel berechnen
$outdoorScores = [
    [
        'title' => '🏃 Joggen & Outdoor-Sport',
        'status' => ($curr['rain_mm'] < 1.0 && $curr['temp'] >= 2 && $curr['temp'] <= 26 && $curr['wind_kmh'] < 35) ? 'Ideal' : (($curr['rain_mm'] < 3.5 && $curr['wind_kmh'] < 50) ? 'Mäßig' : 'Ungünstig'),
        'color'  => ($curr['rain_mm'] < 1.0 && $curr['temp'] >= 2 && $curr['temp'] <= 26 && $curr['wind_kmh'] < 35) ? '#22c55e' : (($curr['rain_mm'] < 3.5 && $curr['wind_kmh'] < 50) ? '#f59e0b' : '#ef4444'),
    ],
    [
        'title' => '🚲 Radfahren & Pendeln',
        'status' => ($curr['rain_mm'] < 0.5 && $curr['gusts_kmh'] < 35 && $curr['temp'] > 1) ? 'Optimal' : (($curr['rain_mm'] < 2.5 && $curr['gusts_kmh'] < 50) ? 'Regenschutz' : 'Warnung'),
        'color'  => ($curr['rain_mm'] < 0.5 && $curr['gusts_kmh'] < 35 && $curr['temp'] > 1) ? '#22c55e' : (($curr['rain_mm'] < 2.5 && $curr['gusts_kmh'] < 50) ? '#f59e0b' : '#ef4444'),
    ],
    [
        'title' => '🌱 Garten & Landwirtschaft',
        'status' => ($curr['temp'] > 5 && $curr['wind_kmh'] < 30) ? 'Gut geeignet' : 'Eingeschränkt',
        'color'  => ($curr['temp'] > 5 && $curr['wind_kmh'] < 30) ? '#22c55e' : '#f59e0b',
    ],
    [
        'title' => '🚗 Straßenverkehr & Glätte',
        'status' => ($curr['temp'] > 3 && $curr['rain_mm'] < 4 && $curr['gusts_kmh'] < 60) ? 'Freie Fahrt' : (($curr['temp'] <= 1.5) ? 'Frost-/Glättegefahr' : 'Nässe / Seitenwind'),
        'color'  => ($curr['temp'] > 3 && $curr['rain_mm'] < 4 && $curr['gusts_kmh'] < 60) ? '#22c55e' : (($curr['temp'] <= 1.5) ? '#ef4444' : '#f59e0b'),
    ],
];

$bauernregeln = [
    1  => 'Ist der Januar hell und weiß, wird der Sommer sicher heiß.',
    2  => 'Wenn’s im Februar nicht schneit, schneit’s in die Osterzeit.',
    3  => 'Märzenstaub bringt Gras und Laub.',
    4  => 'Aprilwetter und Kartenglück wechseln jeden Augenblick.',
    5  => 'Mai kühl und nass, füllt dem Bauern Scheun’ und Fass.',
    6  => 'Stellt sich im Juni Regen ein, so wird das Korn nicht kräftig sein.',
    7  => 'Im Juli muss vor Hitze braten, was im September soll geraten.',
    8  => 'Ist’s in der ersten Augustwoche heiß, bleibt der Winter lange weiß.',
    9  => 'Altweibersommer klar und rein, wird der Herbst recht freundlich sein.',
    10 => 'Bringt der Oktober viel Frost und Wind, so sind Januar und Februar gelind.',
    11 => 'Hängt das Laub bis November hinein, wird der Winter ein langer sein.',
    12 => 'Grüne Weihnacht, weiße Ostern.',
];
$currentBauernregel = $bauernregeln[(int)date('n')] ?? $bauernregeln[1];

$stmtObs = $pdo->query('
    SELECT o.*, u.username
    FROM weather_observations o
    LEFT JOIN users u ON u.id = o.user_id
    ORDER BY o.observed_at DESC, o.id DESC
    LIMIT 15
');
$observations = $stmtObs ? $stmtObs->fetchAll() : [];

$pageTitle = '⛅ Wetter, Regenradar & Warnzentrale';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<style>
.wx-wrap { display:flex; flex-direction:column; gap:18px; }
.wx-topbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; background:var(--bg-card); border:1px solid var(--border); border-radius:14px; padding:14px 18px; }
.wx-search-box { position:relative; flex:1; min-width:260px; max-width:440px; }
.wx-search-input { width:100%; padding:10px 14px; border-radius:10px; border:1px solid var(--border); background:var(--bg-input, rgba(15,23,42,0.5)); color:var(--text-main); font-size:0.92rem; }
.wx-search-results { position:absolute; top:calc(100% + 6px); left:0; right:0; background:var(--bg-card); border:1px solid var(--border); border-radius:12px; box-shadow:0 14px 32px rgba(0,0,0,0.45); z-index:1200; max-height:310px; overflow-y:auto; display:none; }
.wx-search-item { display:flex; align-items:center; justify-content:space-between; padding:9px 13px; border-bottom:1px solid var(--border); text-decoration:none; color:var(--text-main); font-size:0.86rem; }
.wx-search-item:hover { background:rgba(56,189,248,0.12); }
.wx-fav-pills { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.wx-fav-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; font-size:0.82rem; font-weight:600; text-decoration:none; border:1px solid var(--border); background:rgba(148,163,184,0.08); color:var(--text-main); transition:all .15s; }
.wx-fav-pill.active, .wx-fav-pill:hover { border-color:#38bdf8; background:rgba(56,189,248,0.16); color:#38bdf8; }
.wx-grid-top { display:grid; grid-template-columns: 1.25fr 1fr; gap:18px; }
@media (max-width: 1024px) { .wx-grid-top { grid-template-columns: 1fr; } }
.wx-card { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; padding:18px 20px; }
.wx-hero { background:linear-gradient(135deg, rgba(14,165,233,0.16), rgba(30,41,59,0.78)); position:relative; overflow:hidden; }
.wx-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(125px, 1fr)); gap:10px; margin-top:16px; }
.wx-kpi { background:rgba(15,23,42,0.45); border:1px solid var(--border); border-radius:11px; padding:10px 12px; }
.wx-kpi-label { font-size:0.74rem; color:var(--text-muted); }
.wx-kpi-val { font-size:1.05rem; font-weight:700; margin-top:3px; }
.wx-map-box { width:100%; height:470px; border-radius:14px; border:1px solid var(--border); overflow:hidden; background:#0b192c; position:relative; }
.wx-day-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(135px, 1fr)); gap:12px; }
.wx-day-card { background:rgba(15,23,42,0.4); border:1px solid var(--border); border-radius:13px; padding:13px; text-align:center; }
.wx-pin-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 7px; border-radius:999px; font-size:11px; font-weight:800; color:#fff; box-shadow:0 3px 10px rgba(0,0,0,0.45); border:1.5px solid rgba(255,255,255,0.85); white-space:nowrap; }
</style>

<div class="wx-wrap">
    <!-- Topbar mit 100% Offline-PLZ/Orts-Suche (23.296 Orte) & Favoriten -->
    <div class="wx-topbar">
        <div class="wx-search-box">
            <input type="text" id="wxSearchInput" class="wx-search-input" placeholder="🔍 PLZ oder Ort in Deutschland suchen (23.296 Orte offline)..." autocomplete="off">
            <div id="wxSearchResults" class="wx-search-results"></div>
        </div>

        <div class="wx-fav-pills">
            <?php foreach ($favorites as $fav):
                $isCurr = (abs((float)$fav['lat'] - $activeLat) < 0.02 && abs((float)$fav['lng'] - $activeLng) < 0.02);
            ?>
                <span class="wx-fav-pill <?= $isCurr ? 'active' : '' ?>">
                    <a href="weather.php?lat=<?= urlencode((string)$fav['lat']) ?>&lng=<?= urlencode((string)$fav['lng']) ?>&name=<?= urlencode((string)$fav['name']) ?>&zip=<?= urlencode((string)$fav['zip']) ?>&state=<?= urlencode((string)$fav['state']) ?>" style="color:inherit;text-decoration:none;">
                        📍 <?= e($fav['name']) ?>
                    </a>
                    <?php if (count($favorites) > 1): ?>
                        <form method="post" style="display:inline;margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_favorite">
                            <input type="hidden" name="fav_id" value="<?= (int)$fav['id'] ?>">
                            <button type="submit" title="Favorit entfernen" style="background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0 2px;font-size:0.78rem;">✕</button>
                        </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>

            <form method="post" style="display:inline;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_favorite">
                <input type="hidden" name="name" value="<?= e($activeName) ?>">
                <input type="hidden" name="zip" value="<?= e($activeZip) ?>">
                <input type="hidden" name="state" value="<?= e($activeState) ?>">
                <input type="hidden" name="lat" value="<?= e((string)$activeLat) ?>">
                <input type="hidden" name="lng" value="<?= e((string)$activeLng) ?>">
                <button type="submit" class="btn btn-sm btn-secondary">⭐ Aktuellen Ort merken</button>
            </form>
            <a href="maps.php" class="btn btn-sm btn-secondary">🗺️ Zum Karten-Modul</a>
        </div>
    </div>

    <?php if ($activeWarnLvl > 0): ?>
        <div class="wx-card" style="border-left:5px solid <?= $activeWarnLvl >= 3 ? '#ef4444' : ($activeWarnLvl === 2 ? '#f59e0b' : '#38bdf8') ?>; background:<?= $activeWarnLvl >= 3 ? 'rgba(239,68,68,0.14)' : 'rgba(245,158,11,0.12)' ?>; padding:12px 18px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
            <div>
                <strong style="font-size:0.95rem;">⚠️ <?= e($activeWarnTxt) ?></strong>
                <div class="text-muted" style="font-size:0.8rem;margin-top:2px;">Standort: <?= e($activeName) ?> (<?= number_format($activeLat, 3, ',', '.') ?>° N, <?= number_format($activeLng, 3, ',', '.') ?>° E)</div>
            </div>
            <span class="badge" style="background:<?= $activeWarnLvl >= 3 ? '#ef4444' : '#f59e0b' ?>;color:#fff;">Warnstufe <?= (int)$activeWarnLvl ?></span>
        </div>
    <?php endif; ?>

    <div class="wx-grid-top">
        <!-- Linke Karte: Aktuelles Wetter-Cockpit + Astronomie -->
        <div class="wx-card wx-hero">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <h2 style="margin:0;font-size:1.55rem;">📍 <?= e($activeName) ?><?= $activeZip !== '' ? ' <span class="text-muted" style="font-size:0.95rem;font-weight:500;">(' . e($activeZip) . ')</span>' : '' ?></h2>
                        <span class="badge" style="background:rgba(56,189,248,0.18);color:#38bdf8;border:1px solid rgba(56,189,248,0.35);font-size:0.74rem;">
                            <?= e($weather['source'] ?? 'DWD / Offline-Hybrid') ?>
                        </span>
                    </div>
                    <div class="text-muted" style="font-size:0.83rem;margin-top:4px;">
                        <?= $activeState !== '' ? e($activeState) . ' · ' : '' ?>Koordinaten: <?= number_format($activeLat, 4, ',', '.') ?>° N, <?= number_format($activeLng, 4, ',', '.') ?>° O · Stand: <?= e(substr((string)($weather['fetched_at'] ?? date('H:i')), 11, 5)) ?> Uhr
                    </div>
                </div>
                <div style="text-align:right;display:flex;align-items:center;gap:12px;">
                    <span style="font-size:3.1rem;line-height:1;"><?= $currMeta['icon'] ?></span>
                    <div>
                        <div style="font-size:2.35rem;font-weight:800;line-height:1;color:<?= e($currMeta['color']) ?>;">
                            <?= number_format((float)$curr['temp'], 1, ',', '.') ?> °C
                        </div>
                        <div class="text-muted" style="font-size:0.82rem;margin-top:4px;">
                            <?= e($currMeta['label']) ?> · Gefühlt <?= number_format((float)$curr['feels_like'], 1, ',', '.') ?> °C
                        </div>
                    </div>
                </div>
            </div>

            <div class="wx-kpi-grid">
                <div class="wx-kpi">
                    <div class="wx-kpi-label">💨 Wind & Richtung</div>
                    <div class="wx-kpi-val"><?= number_format((float)$curr['wind_kmh'], 1, ',', '.') ?> km/h <span class="text-muted" style="font-size:0.8rem;">(<?= e(windDegToCompass((int)$curr['wind_deg'])) ?>)</span></div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">🌪️ Spitzenböen</div>
                    <div class="wx-kpi-val"><?= number_format((float)$curr['gusts_kmh'], 1, ',', '.') ?> km/h</div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">💧 Luftfeuchte</div>
                    <div class="wx-kpi-val"><?= (int)$curr['humidity'] ?> %</div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">🧭 Luftdruck</div>
                    <div class="wx-kpi-val"><?= (int)$curr['pressure'] ?> hPa</div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">🌧️ Niederschlag (1h)</div>
                    <div class="wx-kpi-val"><?= number_format((float)$curr['rain_mm'], 1, ',', '.') ?> mm</div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">☁️ Bewölkung / UV</div>
                    <div class="wx-kpi-val"><?= (int)$curr['clouds'] ?> % · UV <?= number_format((float)$curr['uv_index'], 1, ',', '.') ?></div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label">🌅 Sonnenauf- / -untergang</div>
                    <div class="wx-kpi-val"><?= e($astro['sunrise']) ?> – <?= e($astro['sunset']) ?></div>
                </div>
                <div class="wx-kpi">
                    <div class="wx-kpi-label"><?= $astro['moon_icon'] ?> Mondphase</div>
                    <div class="wx-kpi-val" style="font-size:0.88rem;"><?= e($astro['moon_phase']) ?> (<?= (int)$astro['moon_illum'] ?>%)</div>
                </div>
            </div>
        </div>

        <!-- Rechte Karte: Outdoor- & Einsatz-Ampel + Bauernregel -->
        <div class="wx-card" style="display:flex;flex-direction:column;justify-content:space-between;gap:12px;">
            <div>
                <h3 style="margin:0 0 10px 0;font-size:1.05rem;">🚦 Outdoor-, Freizeit- & Einsatz-Ampel</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <?php foreach ($outdoorScores as $sc): ?>
                        <div style="background:rgba(15,23,42,0.45);border:1px solid var(--border);border-radius:11px;padding:10px 12px;display:flex;flex-direction:column;gap:4px;">
                            <span style="font-size:0.8rem;color:var(--text-muted);"><?= e($sc['title']) ?></span>
                            <span style="font-weight:700;font-size:0.92rem;color:<?= e($sc['color']) ?>;">● <?= e($sc['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="background:rgba(56,189,248,0.08);border:1px dashed rgba(56,189,248,0.35);border-radius:11px;padding:10px 13px;font-size:0.82rem;">
                <strong>🌾 Bauernregel des Monats:</strong>
                <div class="text-muted" style="margin-top:3px;font-style:italic;">„<?= e($currentBauernregel) ?>“</div>
            </div>
        </div>
    </div>

    <!-- Interaktive Deutschland-Wetter-, Landkreis-Warn- & Regenradar-Karte -->
    <div class="wx-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
            <div>
                <h3 style="margin:0;font-size:1.12rem;">🗺️ Interaktive Deutschland-Wetter-, Landkreis- & Regenradar-Karte</h3>
                <div class="text-muted" style="font-size:0.8rem;">100% Intranet-fähig mit lokalen GeoJSON-Landkreisen (`de_landkreise.geojson`), 18 deutschen DWD-/Referenz-Stationen & optionalem Live-Regenradar</div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                <button type="button" class="btn btn-sm btn-primary wx-layer-btn" data-mode="temp" onclick="setWxMapMode('temp')">🌡️ Temperatur & Wetter</button>
                <button type="button" class="btn btn-sm btn-secondary wx-layer-btn" data-mode="wind" onclick="setWxMapMode('wind')">💨 Wind & Spitzenböen</button>
                <button type="button" class="btn btn-sm btn-secondary wx-layer-btn" data-mode="warn" onclick="setWxMapMode('warn')">⚠️ Warnstufen-Karte</button>
                <button type="button" class="btn btn-sm btn-secondary" id="btnToggleRadar" onclick="toggleRainRadar()">🌧️ Live-Regenradar</button>
                <button type="button" class="btn btn-sm btn-secondary" id="btnToggleKreise" onclick="toggleLandkreiseLayer()">🏛️ 400 Landkreise</button>
            </div>
        </div>
        <div id="wxMapCanvas" class="wx-map-box"></div>
    </div>

    <!-- 24-Stunden Temperatur- & Niederschlagsverlauf -->
    <div class="wx-card">
        <h3 style="margin:0 0 12px 0;font-size:1.08rem;">📈 24-Stunden-Trend (Temperatur °C & Niederschlag mm)</h3>
        <div style="width:100%;overflow-x:auto;">
            <canvas id="wxHourlyChart" height="210" style="width:100%;min-width:640px;height:210px;"></canvas>
        </div>
    </div>

    <!-- 7-Tage-Vorhersage -->
    <div class="wx-card">
        <h3 style="margin:0 0 12px 0;font-size:1.08rem;">📅 7-Tage-Wettertrend für <?= e($activeName) ?></h3>
        <div class="wx-day-grid">
            <?php
            $weekdaysDe = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            foreach ($weather['daily'] as $idx => $d):
                $ts = strtotime((string)$d['date']) ?: time();
                $dayLabel = $idx === 0 ? 'Heute' : ($idx === 1 ? 'Morgen' : $weekdaysDe[(int)date('w', $ts)] . ', ' . date('d.m.', $ts));
                $dMeta = getWmoWeatherMeta((int)$d['wmo'], true);
            ?>
                <div class="wx-day-card">
                    <div style="font-weight:700;font-size:0.88rem;"><?= e($dayLabel) ?></div>
                    <div style="font-size:2.1rem;margin:8px 0;"><?= $dMeta['icon'] ?></div>
                    <div style="font-size:0.78rem;color:var(--text-muted);min-height:2.1em;"><?= e($dMeta['label']) ?></div>
                    <div style="margin-top:8px;font-weight:800;font-size:1rem;">
                        <span style="color:#f97316;"><?= number_format((float)$d['tmax'], 1, ',', '.') ?>°</span>
                        <span class="text-muted" style="font-weight:500;"> / <?= number_format((float)$d['tmin'], 1, ',', '.') ?>°</span>
                    </div>
                    <div class="text-muted" style="font-size:0.74rem;margin-top:6px;">
                        🌧️ <?= number_format((float)$d['rain_sum'], 1, ',', '.') ?> mm · 💨 <?= (int)round((float)$d['wind_max']) ?> km/h
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Eigene Intranet-Wetterstation / Lokales Messbuch -->
    <div class="wx-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
            <div>
                <h3 style="margin:0;font-size:1.08rem;">🌡️ Lokales Intranet-Wetter-Messbuch (Eigene Wetterstation / Hof- & Gebäudemessung)</h3>
                <div class="text-muted" style="font-size:0.8rem;">Dokumentiere eigene Thermometer-, Barometer- oder Regenmesser-Werte komplett offline in der Cloud-Datenbank</div>
            </div>
        </div>

        <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(135px, 1fr));gap:10px;align-items:end;margin-bottom:16px;background:rgba(15,23,42,0.35);padding:12px;border-radius:12px;border:1px solid var(--border);">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="add_observation">
            <div>
                <label class="form-label" style="font-size:0.76rem;">Station / Ort</label>
                <input type="text" name="station_name" class="form-control" value="<?= e($activeName) ?>" required>
            </div>
            <div>
                <label class="form-label" style="font-size:0.76rem;">Temperatur (°C)</label>
                <input type="number" step="0.1" name="temp_c" class="form-control" value="<?= e((string)$curr['temp']) ?>" required>
            </div>
            <div>
                <label class="form-label" style="font-size:0.76rem;">Luftfeuchte (%)</label>
                <input type="number" name="humidity" class="form-control" value="<?= (int)$curr['humidity'] ?>">
            </div>
            <div>
                <label class="form-label" style="font-size:0.76rem;">Luftdruck (hPa)</label>
                <input type="number" name="pressure_hpa" class="form-control" value="<?= (int)$curr['pressure'] ?>">
            </div>
            <div>
                <label class="form-label" style="font-size:0.76rem;">Niederschlag (mm)</label>
                <input type="number" step="0.1" name="rain_mm" class="form-control" value="0.0">
            </div>
            <div>
                <label class="form-label" style="font-size:0.76rem;">Notiz / Beobachtung</label>
                <input type="text" name="notes" class="form-control" placeholder="z. B. Morgentau, Böen...">
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="width:100%;">➕ Messwert speichern</button>
            </div>
        </form>

        <?php if (!empty($observations)): ?>
            <div style="overflow-x:auto;">
                <table class="table" style="width:100%;font-size:0.84rem;">
                    <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th>Station</th>
                            <th>Temp.</th>
                            <th>Feuchte</th>
                            <th>Druck</th>
                            <th>Regen</th>
                            <th>Notiz</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($observations as $ob): ?>
                            <tr>
                                <td><?= e(date('d.m.Y H:i', strtotime((string)$ob['observed_at']))) ?></td>
                                <td><strong><?= e((string)$ob['station_name']) ?></strong></td>
                                <td><?= number_format((float)$ob['temp_c'], 1, ',', '.') ?> °C</td>
                                <td><?= (int)$ob['humidity'] ?> %</td>
                                <td><?= (int)$ob['pressure_hpa'] ?> hPa</td>
                                <td><?= number_format((float)$ob['rain_mm'], 1, ',', '.') ?> mm</td>
                                <td class="text-muted"><?= e((string)$ob['notes']) ?></td>
                                <td style="text-align:right;">
                                    <form method="post" style="display:inline;margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_observation">
                                        <input type="hidden" name="obs_id" value="<?= (int)$ob['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 7px;font-size:0.75rem;">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/leaflet/leaflet.js"></script>
<script>
const WX_ACTIVE = {
    name: <?= json_encode($activeName, JSON_UNESCAPED_UNICODE) ?>,
    lat: <?= json_encode((float)$activeLat) ?>,
    lng: <?= json_encode((float)$activeLng) ?>
};
const WX_STATIONS = <?= json_encode($deStations, JSON_UNESCAPED_UNICODE) ?>;
const WX_HOURLY   = <?= json_encode($weather['hourly'] ?? [], JSON_UNESCAPED_UNICODE) ?>;

let wxMap = null;
let wxMarkersLayer = null;
let wxRadarLayer = null;
let wxKreiseLayer = null;
let wxMapMode = 'temp';

function tempColorHex(t) {
    if (t <= -2) return '#3b82f6';
    if (t <= 6)  return '#0ea5e9';
    if (t <= 14) return '#10b981';
    if (t <= 22) return '#f59e0b';
    if (t <= 28) return '#f97316';
    return '#ef4444';
}

function initWxMap() {
    wxMap = L.map('wxMapCanvas', {
        center: [51.16, 10.45],
        zoom: 6,
        minZoom: 4,
        maxZoom: 14
    });

    // Offline-fähige Hybrid-Kacheln über maps.php Proxy (nutzt lokal gebündelte OSM-Kacheln + Overzoom)
    L.tileLayer('maps.php?action=tile&layer=osm&z={z}&x={x}&y={y}', {
        maxZoom: 14,
        attribution: '© OpenStreetMap · MiniCloud Offline-Hybrid'
    }).addTo(wxMap);

    // Bundesländer-Umrisse lokal laden
    fetch('assets/maps/de_bundeslaender.geojson')
        .then(r => r.ok ? r.json() : null)
        .then(geo => {
            if (!geo) return;
            L.geoJSON(geo, {
                style: { color: '#38bdf8', weight: 1.4, fillOpacity: 0.03 }
            }).addTo(wxMap);
        }).catch(() => {});

    wxMarkersLayer = L.layerGroup().addTo(wxMap);
    renderWxStationPins();

    // Klick auf die Karte lädt direkt das Wetter für diese Koordinate
    wxMap.on('click', function(ev) {
        const lat = ev.latlng.lat.toFixed(4);
        const lng = ev.latlng.lng.toFixed(4);
        window.location.href = `weather.php?lat=${lat}&lng=${lng}&name=Koordinate+${lat}%2C+${lng}`;
    });
}

function renderWxStationPins() {
    if (!wxMarkersLayer) return;
    wxMarkersLayer.clearLayers();

    // Aktueller Standort-Pin
    const activeMarker = L.circleMarker([WX_ACTIVE.lat, WX_ACTIVE.lng], {
        radius: 9,
        color: '#ffffff',
        weight: 2.5,
        fillColor: '#38bdf8',
        fillOpacity: 0.95
    }).bindPopup(`<strong>📍 ${WX_ACTIVE.name}</strong><br>Aktuell ausgewählter Standort`);
    wxMarkersLayer.addLayer(activeMarker);

    WX_STATIONS.forEach(st => {
        let badgeBg = tempColorHex(st.temp);
        let badgeTxt = `${st.icon} ${Number(st.temp).toFixed(1)}°`;

        if (wxMapMode === 'wind') {
            badgeBg = st.gusts_kmh >= 65 ? '#ef4444' : (st.gusts_kmh >= 45 ? '#f59e0b' : '#0284c7');
            badgeTxt = `💨 ${Math.round(st.wind_kmh)} / Böen ${Math.round(st.gusts_kmh)} km/h`;
        } else if (wxMapMode === 'warn') {
            badgeBg = st.warn_lvl >= 3 ? '#dc2626' : (st.warn_lvl === 2 ? '#d97706' : (st.warn_lvl === 1 ? '#2563eb' : '#16a34a'));
            badgeTxt = st.warn_lvl > 0 ? `⚠️ Stufe ${st.warn_lvl}` : `✅ Ruhig`;
        }

        const icon = L.divIcon({
            className: 'wx-custom-pin',
            html: `<div class="wx-pin-badge" style="background:${badgeBg};">${badgeTxt}</div>`,
            iconSize: [86, 26],
            iconAnchor: [43, 13]
        });

        const m = L.marker([st.lat, st.lng], { icon: icon });
        m.bindPopup(`
            <div style="min-width:175px;">
                <strong>${st.icon} ${st.name}</strong><br>
                <span style="font-size:0.82rem;color:#64748b;">${st.label}</span>
                <hr style="margin:6px 0;border:none;border-top:1px solid rgba(148,163,184,0.3);">
                🌡️ Temperatur: <strong>${Number(st.temp).toFixed(1)} °C</strong><br>
                💨 Wind / Böen: <strong>${Math.round(st.wind_kmh)} / ${Math.round(st.gusts_kmh)} km/h</strong><br>
                🌧️ Niederschlag: <strong>${Number(st.rain_mm).toFixed(1)} mm</strong><br>
                ${st.warn_lvl > 0 ? `<div style="margin-top:5px;color:#ef4444;font-weight:700;">⚠️ ${st.warn_text}</div>` : ''}
                <div style="margin-top:8px;">
                    <a href="weather.php?lat=${st.lat}&lng=${st.lng}&name=${encodeURIComponent(st.name)}&state=${encodeURIComponent(st.state)}" class="btn btn-sm btn-primary" style="color:#fff;text-decoration:none;padding:3px 8px;font-size:0.75rem;">Details öffnen</a>
                </div>
            </div>
        `);
        wxMarkersLayer.addLayer(m);
    });
}

function setWxMapMode(mode) {
    wxMapMode = mode;
    document.querySelectorAll('.wx-layer-btn').forEach(b => {
        b.classList.toggle('btn-primary', b.dataset.mode === mode);
        b.classList.toggle('btn-secondary', b.dataset.mode !== mode);
    });
    renderWxStationPins();
}

function toggleRainRadar() {
    const btn = document.getElementById('btnToggleRadar');
    if (wxRadarLayer) {
        wxMap.removeLayer(wxRadarLayer);
        wxRadarLayer = null;
        btn.classList.replace('btn-primary', 'btn-secondary');
        return;
    }
    fetch('https://api.rainviewer.com/public/weather-maps.json')
        .then(r => r.json())
        .then(data => {
            const past = (data.radar && data.radar.past) ? data.radar.past : [];
            if (!past.length) return;
            const latest = past[past.length - 1];
            wxRadarLayer = L.tileLayer(`${data.host}${latest.path}/256/{z}/{x}/{y}/2/1_1.png`, {
                opacity: 0.62,
                maxZoom: 14,
                attribution: 'Regenradar: RainViewer'
            }).addTo(wxMap);
            btn.classList.replace('btn-secondary', 'btn-primary');
        })
        .catch(() => {
            alert('Live-Regenradar im reinen Offline-Modus nicht erreichbar – nutze die lokalen Stations-Niederschlagswerte.');
        });
}

function toggleLandkreiseLayer() {
    const btn = document.getElementById('btnToggleKreise');
    if (wxKreiseLayer) {
        wxMap.removeLayer(wxKreiseLayer);
        wxKreiseLayer = null;
        btn.classList.replace('btn-primary', 'btn-secondary');
        return;
    }
    fetch('assets/maps/de_landkreise.geojson')
        .then(r => r.json())
        .then(geo => {
            wxKreiseLayer = L.geoJSON(geo, {
                style: { color: '#f59e0b', weight: 1, fillOpacity: 0.05 },
                onEachFeature: function(feat, layer) {
                    const p = feat.properties || {};
                    const title = p.NAME_3 || p.GEN || p.name || 'Landkreis';
                    const type  = p.TYPE_3 || p.BEZ || '';
                    layer.bindTooltip(`${title} ${type ? '(' + type + ')' : ''}`);
                }
            }).addTo(wxMap);
            btn.classList.replace('btn-secondary', 'btn-primary');
        });
}

// 24h Temperatur- & Niederschlags-Canvas-Chart (100% lokal ohne externe Chart-CDN)
function drawHourlyChart() {
    const canvas = document.getElementById('wxHourlyChart');
    if (!canvas || !WX_HOURLY.length) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width  = rect.width * dpr;
    canvas.height = 210 * dpr;
    ctx.scale(dpr, dpr);

    const w = rect.width;
    const h = 210;
    const padL = 38, padR = 20, padT = 26, padB = 34;
    const plotW = w - padL - padR;
    const plotH = h - padT - padB;

    const temps = WX_HOURLY.map(x => Number(x.temp));
    const minT = Math.floor(Math.min(...temps) - 2);
    const maxT = Math.ceil(Math.max(...temps) + 2);
    const spanT = Math.max(4, maxT - minT);

    // Gitterlinien
    ctx.strokeStyle = 'rgba(148,163,184,0.16)';
    ctx.fillStyle   = '#94a3b8';
    ctx.font        = '11px sans-serif';
    for (let i = 0; i <= 4; i++) {
        const y = padT + (plotH * (i / 4));
        const val = Math.round(maxT - (spanT * (i / 4)));
        ctx.beginPath();
        ctx.moveTo(padL, y);
        ctx.lineTo(w - padR, y);
        ctx.stroke();
        ctx.fillText(`${val}°`, 6, y + 4);
    }

    const stepX = plotW / Math.max(1, WX_HOURLY.length - 1);

    // Niederschlags-Balken
    WX_HOURLY.forEach((item, idx) => {
        const x = padL + idx * stepX;
        const rain = Number(item.rain || 0);
        if (rain > 0) {
            const barH = Math.min(plotH * 0.7, rain * 12);
            ctx.fillStyle = 'rgba(56, 189, 248, 0.38)';
            ctx.fillRect(x - 6, padT + plotH - barH, 12, barH);
        }
    });

    // Temperatur-Kurve
    ctx.beginPath();
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth = 2.6;
    WX_HOURLY.forEach((item, idx) => {
        const x = padL + idx * stepX;
        const y = padT + plotH - (((Number(item.temp) - minT) / spanT) * plotH);
        if (idx === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.stroke();

    // Punkte & Uhrzeiten
    WX_HOURLY.forEach((item, idx) => {
        const x = padL + idx * stepX;
        const y = padT + plotH - (((Number(item.temp) - minT) / spanT) * plotH);
        ctx.fillStyle = '#0ea5e9';
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fill();

        if (idx % 2 === 0) {
            ctx.fillStyle = '#e2e8f0';
            ctx.font = 'bold 10px sans-serif';
            ctx.fillText(`${Number(item.temp).toFixed(0)}°`, x - 8, y - 8);
            ctx.fillStyle = '#94a3b8';
            ctx.font = '10px sans-serif';
            ctx.fillText(item.time, x - 13, h - 10);
        }
    });
}

// Offline-Suche für alle 23.296 deutschen PLZ & Orte
const searchInput = document.getElementById('wxSearchInput');
const searchResults = document.getElementById('wxSearchResults');
let searchTimer = null;

if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length < 2) {
            searchResults.style.display = 'none';
            return;
        }
        searchTimer = setTimeout(() => {
            fetch(`weather.php?action=offline_search&q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    const list = data.results || [];
                    if (!list.length) {
                        searchResults.innerHTML = '<div style="padding:10px 14px;color:var(--text-muted);font-size:0.84rem;">Kein Ort gefunden.</div>';
                        searchResults.style.display = 'block';
                        return;
                    }
                    searchResults.innerHTML = list.map(it => `
                        <a class="wx-search-item" href="weather.php?lat=${it.lat}&lng=${it.lng}&name=${encodeURIComponent(it.name)}&zip=${encodeURIComponent(it.zip)}&state=${encodeURIComponent(it.state)}">
                            <div>
                                <strong>${it.zip} ${it.name}</strong>
                                <div style="font-size:0.75rem;color:var(--text-muted);">${it.kreis ? it.kreis + ' · ' : ''}${it.state}</div>
                            </div>
                            <span style="font-size:0.76rem;color:#38bdf8;">Wetter →</span>
                        </a>
                    `).join('');
                    searchResults.style.display = 'block';
                });
        }, 160);
    });

    document.addEventListener('click', function(ev) {
        if (!searchInput.contains(ev.target) && !searchResults.contains(ev.target)) {
            searchResults.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initWxMap();
    drawHourlyChart();
    window.addEventListener('resize', drawHourlyChart);
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
