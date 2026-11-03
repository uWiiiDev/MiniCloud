<?php
declare(strict_types=1);

/**
 * MiniCloud – 🗺️ Karten, Offline-Atlas & Intranet-Lagepläne (maps.php)
 * 100 % Intranet- & Air-Gapped-fähiger Kartendienst mit:
 *  - Lokaler Leaflet-Engine (assets/leaflet/) ohne externe CDNs
 *  - Integrierter Offline-Vektorkarte (Welt, Europa, Deutschland, 16 Bundesländer, Flüsse & Autobahnen)
 *  - Stufenlos & nachträglich änderbarem Detailgrad (Vektor-Detailstufe 1–5 & Kachel-Zoomstufe 1–18)
 *  - Lokalem Tile-Server mit .mbtiles-Support (SQLite), Festplatten-Cache & Air-Gap-Lock (Null Außenverkehr)
 *  - Offline-Karten-Manager zum gezielten Nachladen höherer Detailgrade (Zoomstufen) für Regionen
 *  - Eigenen Intranet-Lageplänen / Gebäude-Grundrissen (Indoor-Maps) mit interaktiven Markern
 *  - Eigenen POIs/Favoriten, GPX/GeoJSON-Touren mit Höhenprofil, Foto-Weltkarte (EXIF-GPS), Kontakten & Kalender-Orten
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$isAdminUser = isAdmin();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_maps'] ?? '1') !== '1' && !$isAdminUser) {
    setFlash('error', 'Das Karten-Modul ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// =========================================================================
// 1. VERZEICHNISSE & DATENBANK-TABELLEN INITIALISIEREN
// =========================================================================
$mapsBaseDir   = __DIR__ . '/uploads/maps';
$tilesCacheDir = __DIR__ . '/uploads/map_tiles';
$floorplansDir = __DIR__ . '/uploads/maps/floorplans';

foreach ([$mapsBaseDir, $tilesCacheDir, $floorplansDir] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS map_places (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'favorite',
            lat DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
            lng DECIMAL(10, 7) NOT NULL DEFAULT 0.0000000,
            floorplan_id INT UNSIGNED DEFAULT NULL,
            address VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT DEFAULT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
            icon VARCHAR(16) NOT NULL DEFAULT '📍',
            min_detail_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
            is_shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_map_places_user (user_id),
            INDEX idx_map_places_shared (is_shared),
            INDEX idx_map_places_fp (floorplan_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS map_tracks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            track_type VARCHAR(24) NOT NULL DEFAULT 'gpx',
            color VARCHAR(20) NOT NULL DEFAULT '#10b981',
            distance_km DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            ascent_m INT NOT NULL DEFAULT 0,
            descent_m INT NOT NULL DEFAULT 0,
            points_json MEDIUMTEXT NOT NULL,
            is_shared TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_map_tracks_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS map_floorplans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            building_name VARCHAR(120) NOT NULL DEFAULT '',
            floor_label VARCHAR(40) NOT NULL DEFAULT 'EG',
            stored_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(80) NOT NULL DEFAULT 'image/png',
            width_px INT UNSIGNED NOT NULL DEFAULT 1600,
            height_px INT UNSIGNED NOT NULL DEFAULT 1000,
            scale_meters DECIMAL(10, 2) NOT NULL DEFAULT 100.00,
            is_shared TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_map_fp_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // Tabellen existieren bereits oder DB im Read-Only-Modus
}

// =========================================================================
// 2. KONFIGURATION: AIR-GAP-LOCK & STANDARD-DETAILGRAD
// =========================================================================
$airGapMode = ($appSettings['maps_airgap_only'] ?? '1') === '1'; // Standard: 100 % Intranet Air-Gapped!
$defaultDetailLevel = max(1, min(5, (int)($appSettings['maps_default_detail'] ?? 4)));
$maxCachedZoomLevel = max(4, min(18, (int)($appSettings['maps_max_zoom_detail'] ?? 14)));

// =========================================================================
// 3. LOKALE OFFLINE-ORTS- & PLZ-DATENBANK (DACH + EUROPA)
// =========================================================================
function getOfflineGazetteer(): array
{
    return [
        // Metropolen & Landeshauptstädte (Detailstufe 1)
        ['name' => 'Berlin',              'zip' => '10115', 'state' => 'Berlin',                 'lat' => 52.5200, 'lng' => 13.4050, 'pop' => 3755000, 'lvl' => 1],
        ['name' => 'Hamburg',             'zip' => '20095', 'state' => 'Hamburg',                'lat' => 53.5511, 'lng' => 9.9937,  'pop' => 1906000, 'lvl' => 1],
        ['name' => 'München',             'zip' => '80331', 'state' => 'Bayern',                 'lat' => 48.1351, 'lng' => 11.5820, 'pop' => 1512000, 'lvl' => 1],
        ['name' => 'Köln',                'zip' => '50667', 'state' => 'Nordrhein-Westfalen',    'lat' => 50.9375, 'lng' => 6.9603,  'pop' => 1084000, 'lvl' => 1],
        ['name' => 'Frankfurt am Main',   'zip' => '60311', 'state' => 'Hessen',                 'lat' => 50.1109, 'lng' => 8.6821,  'pop' => 773000,  'lvl' => 1],
        ['name' => 'Stuttgart',           'zip' => '70173', 'state' => 'Baden-Württemberg',      'lat' => 48.7758, 'lng' => 9.1829,  'pop' => 632000,  'lvl' => 1],
        ['name' => 'Düsseldorf',          'zip' => '40213', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.2277, 'lng' => 6.7735,  'pop' => 629000,  'lvl' => 1],
        ['name' => 'Leipzig',             'zip' => '04109', 'state' => 'Sachsen',                'lat' => 51.3397, 'lng' => 12.3731, 'pop' => 616000,  'lvl' => 1],
        ['name' => 'Dortmund',            'zip' => '44135', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.5136, 'lng' => 7.4653,  'pop' => 593000,  'lvl' => 1],
        ['name' => 'Essen',               'zip' => '45127', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.4556, 'lng' => 7.0116,  'pop' => 584000,  'lvl' => 1],
        ['name' => 'Bremen',              'zip' => '28195', 'state' => 'Bremen',                 'lat' => 53.0793, 'lng' => 8.8017,  'pop' => 569000,  'lvl' => 1],
        ['name' => 'Dresden',             'zip' => '01067', 'state' => 'Sachsen',                'lat' => 51.0504, 'lng' => 13.7373, 'pop' => 563000,  'lvl' => 1],
        ['name' => 'Hannover',            'zip' => '30159', 'state' => 'Niedersachsen',          'lat' => 52.3759, 'lng' => 9.7320,  'pop' => 545000,  'lvl' => 1],
        ['name' => 'Nürnberg',            'zip' => '90402', 'state' => 'Bayern',                 'lat' => 49.4521, 'lng' => 11.0767, 'pop' => 523000,  'lvl' => 1],
        ['name' => 'Wien',                'zip' => '1010',  'state' => 'Österreich',             'lat' => 48.2082, 'lng' => 16.3738, 'pop' => 1982000, 'lvl' => 1],
        ['name' => 'Zürich',              'zip' => '8001',  'state' => 'Schweiz',                'lat' => 47.3769, 'lng' => 8.5417,  'pop' => 428000,  'lvl' => 1],

        // Weitere Landeshauptstädte & Großstädte (Detailstufe 2)
        ['name' => 'Duisburg',            'zip' => '47051', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.4344, 'lng' => 6.7623,  'pop' => 502000,  'lvl' => 2],
        ['name' => 'Bochum',              'zip' => '44787', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.4818, 'lng' => 7.2162,  'pop' => 365000,  'lvl' => 2],
        ['name' => 'Wuppertal',           'zip' => '42103', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.2562, 'lng' => 7.1508,  'pop' => 358000,  'lvl' => 2],
        ['name' => 'Bielefeld',           'zip' => '33602', 'state' => 'Nordrhein-Westfalen',    'lat' => 52.0302, 'lng' => 8.5325,  'pop' => 338000,  'lvl' => 2],
        ['name' => 'Bonn',                'zip' => '53111', 'state' => 'Nordrhein-Westfalen',    'lat' => 50.7374, 'lng' => 7.0982,  'pop' => 336000,  'lvl' => 2],
        ['name' => 'Münster',             'zip' => '48143', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.9607, 'lng' => 7.6261,  'pop' => 320000,  'lvl' => 2],
        ['name' => 'Mannheim',            'zip' => '68159', 'state' => 'Baden-Württemberg',      'lat' => 49.4875, 'lng' => 8.4660,  'pop' => 315000,  'lvl' => 2],
        ['name' => 'Karlsruhe',           'zip' => '76133', 'state' => 'Baden-Württemberg',      'lat' => 49.0069, 'lng' => 8.4037,  'pop' => 308000,  'lvl' => 2],
        ['name' => 'Augsburg',            'zip' => '86150', 'state' => 'Bayern',                 'lat' => 48.3705, 'lng' => 10.8978, 'pop' => 301000,  'lvl' => 2],
        ['name' => 'Wiesbaden',           'zip' => '65183', 'state' => 'Hessen',                 'lat' => 50.0782, 'lng' => 8.2398,  'pop' => 283000,  'lvl' => 2],
        ['name' => 'Mainz',               'zip' => '55116', 'state' => 'Rheinland-Pfalz',        'lat' => 49.9929, 'lng' => 8.2473,  'pop' => 220000,  'lvl' => 2],
        ['name' => 'Saarbrücken',         'zip' => '66111', 'state' => 'Saarland',               'lat' => 49.2402, 'lng' => 6.9969,  'pop' => 181000,  'lvl' => 2],
        ['name' => 'Kiel',                'zip' => '24103', 'state' => 'Schleswig-Holstein',     'lat' => 54.3233, 'lng' => 10.1228, 'pop' => 247000,  'lvl' => 2],
        ['name' => 'Magdeburg',           'zip' => '39104', 'state' => 'Sachsen-Anhalt',         'lat' => 52.1205, 'lng' => 11.6276, 'pop' => 239000,  'lvl' => 2],
        ['name' => 'Erfurt',              'zip' => '99084', 'state' => 'Thüringen',              'lat' => 50.9848, 'lng' => 11.0299, 'pop' => 214000,  'lvl' => 2],
        ['name' => 'Potsdam',             'zip' => '14467', 'state' => 'Brandenburg',            'lat' => 52.3906, 'lng' => 13.0645, 'pop' => 185000,  'lvl' => 2],
        ['name' => 'Schwerin',            'zip' => '19053', 'state' => 'Mecklenburg-Vorpommern', 'lat' => 53.6355, 'lng' => 11.4012, 'pop' => 98000,   'lvl' => 2],
        ['name' => 'Freiburg im Breisgau','zip' => '79098', 'state' => 'Baden-Württemberg',      'lat' => 47.9990, 'lng' => 7.8421,  'pop' => 236000,  'lvl' => 2],
        ['name' => 'Aachen',              'zip' => '52062', 'state' => 'Nordrhein-Westfalen',    'lat' => 50.7753, 'lng' => 6.0839,  'pop' => 252000,  'lvl' => 2],
        ['name' => 'Braunschweig',        'zip' => '38100', 'state' => 'Niedersachsen',          'lat' => 52.2689, 'lng' => 10.5268, 'pop' => 251000,  'lvl' => 2],
        ['name' => 'Chemnitz',            'zip' => '09111', 'state' => 'Sachsen',                'lat' => 50.8278, 'lng' => 12.9214, 'pop' => 248000,  'lvl' => 2],
        ['name' => 'Halle (Saale)',       'zip' => '06108', 'state' => 'Sachsen-Anhalt',         'lat' => 51.4969, 'lng' => 11.9688, 'pop' => 242000,  'lvl' => 2],
        ['name' => 'Kassel',              'zip' => '34117', 'state' => 'Hessen',                 'lat' => 51.3127, 'lng' => 9.4797,  'pop' => 204000,  'lvl' => 2],
        ['name' => 'Rostock',             'zip' => '18055', 'state' => 'Mecklenburg-Vorpommern', 'lat' => 54.0924, 'lng' => 12.0991, 'pop' => 209000,  'lvl' => 2],
        ['name' => 'Lübeck',              'zip' => '23552', 'state' => 'Schleswig-Holstein',     'lat' => 53.8655, 'lng' => 10.6866, 'pop' => 218000,  'lvl' => 2],

        // Mittelstädte & Regionale Zentren (Detailstufe 3)
        ['name' => 'Mönchengladbach',     'zip' => '41061', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.1805, 'lng' => 6.4428,  'pop' => 262000,  'lvl' => 3],
        ['name' => 'Gelsenkirchen',       'zip' => '45879', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.5177, 'lng' => 7.0857,  'pop' => 263000,  'lvl' => 3],
        ['name' => 'Krefeld',             'zip' => '47798', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.3388, 'lng' => 6.5853,  'pop' => 228000,  'lvl' => 3],
        ['name' => 'Oberhausen',          'zip' => '46045', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.4963, 'lng' => 6.8638,  'pop' => 210000,  'lvl' => 3],
        ['name' => 'Hagen',               'zip' => '58095', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.3671, 'lng' => 7.4633,  'pop' => 189000,  'lvl' => 3],
        ['name' => 'Hamm',                'zip' => '59065', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.6739, 'lng' => 7.8160,  'pop' => 180000,  'lvl' => 3],
        ['name' => 'Mülheim an der Ruhr', 'zip' => '45468', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.4272, 'lng' => 6.8825,  'pop' => 172000,  'lvl' => 3],
        ['name' => 'Leverkusen',          'zip' => '51373', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.0459, 'lng' => 7.0192,  'pop' => 165000,  'lvl' => 3],
        ['name' => 'Solingen',            'zip' => '42651', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.1652, 'lng' => 7.0671,  'pop' => 160000,  'lvl' => 3],
        ['name' => 'Herne',               'zip' => '44623', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.5369, 'lng' => 7.2009,  'pop' => 157000,  'lvl' => 3],
        ['name' => 'Neuss',               'zip' => '41460', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.2042, 'lng' => 6.6879,  'pop' => 154000,  'lvl' => 3],
        ['name' => 'Paderborn',           'zip' => '33098', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.7189, 'lng' => 8.7575,  'pop' => 154000,  'lvl' => 3],
        ['name' => 'Heidelberg',          'zip' => '69117', 'state' => 'Baden-Württemberg',      'lat' => 49.3988, 'lng' => 8.6724,  'pop' => 162000,  'lvl' => 3],
        ['name' => 'Darmstadt',           'zip' => '64283', 'state' => 'Hessen',                 'lat' => 49.8728, 'lng' => 8.6512,  'pop' => 162000,  'lvl' => 3],
        ['name' => 'Ulm',                 'zip' => '89073', 'state' => 'Baden-Württemberg',      'lat' => 48.4011, 'lng' => 9.9876,  'pop' => 129000,  'lvl' => 3],
        ['name' => 'Regensburg',          'zip' => '93047', 'state' => 'Bayern',                 'lat' => 49.0134, 'lng' => 12.1016, 'pop' => 157000,  'lvl' => 3],
        ['name' => 'Würzburg',            'zip' => '97070', 'state' => 'Bayern',                 'lat' => 49.7913, 'lng' => 9.9534,  'pop' => 127000,  'lvl' => 3],
        ['name' => 'Ingolstadt',          'zip' => '85049', 'state' => 'Bayern',                 'lat' => 48.7665, 'lng' => 11.4257, 'pop' => 141000,  'lvl' => 3],
        ['name' => 'Heilbronn',           'zip' => '74072', 'state' => 'Baden-Württemberg',      'lat' => 49.1427, 'lng' => 9.2109,  'pop' => 128000,  'lvl' => 3],
        ['name' => 'Pforzheim',           'zip' => '75172', 'state' => 'Baden-Württemberg',      'lat' => 48.8922, 'lng' => 8.6946,  'pop' => 127000,  'lvl' => 3],
        ['name' => 'Reutlingen',          'zip' => '72764', 'state' => 'Baden-Württemberg',      'lat' => 48.4914, 'lng' => 9.2043,  'pop' => 117000,  'lvl' => 3],
        ['name' => 'Tübingen',            'zip' => '72070', 'state' => 'Baden-Württemberg',      'lat' => 48.5216, 'lng' => 9.0576,  'pop' => 92000,   'lvl' => 3],
        ['name' => 'Konstanz',            'zip' => '78462', 'state' => 'Baden-Württemberg',      'lat' => 47.6779, 'lng' => 9.1732,  'pop' => 85000,   'lvl' => 3],
        ['name' => 'Ludwigsburg',         'zip' => '71638', 'state' => 'Baden-Württemberg',      'lat' => 48.8973, 'lng' => 9.1916,  'pop' => 94000,   'lvl' => 3],
        ['name' => 'Esslingen am Neckar', 'zip' => '73728', 'state' => 'Baden-Württemberg',      'lat' => 48.7406, 'lng' => 9.3108,  'pop' => 95000,   'lvl' => 3],
        ['name' => 'Osnabrück',           'zip' => '49074', 'state' => 'Niedersachsen',          'lat' => 52.2799, 'lng' => 8.0472,  'pop' => 166000,  'lvl' => 3],
        ['name' => 'Oldenburg',           'zip' => '26122', 'state' => 'Niedersachsen',          'lat' => 53.1435, 'lng' => 8.2146,  'pop' => 172000,  'lvl' => 3],
        ['name' => 'Göttingen',           'zip' => '37073', 'state' => 'Niedersachsen',          'lat' => 51.5413, 'lng' => 9.9158,  'pop' => 118000,  'lvl' => 3],
        ['name' => 'Wolfsburg',           'zip' => '38440', 'state' => 'Niedersachsen',          'lat' => 52.4227, 'lng' => 10.7865, 'pop' => 125000,  'lvl' => 3],
        ['name' => 'Koblenz',             'zip' => '56068', 'state' => 'Rheinland-Pfalz',        'lat' => 50.3569, 'lng' => 7.5890,  'pop' => 115000,  'lvl' => 3],
        ['name' => 'Trier',               'zip' => '54290', 'state' => 'Rheinland-Pfalz',        'lat' => 49.7499, 'lng' => 6.6371,  'pop' => 112000,  'lvl' => 3],
        ['name' => 'Kaiserslautern',      'zip' => '67655', 'state' => 'Rheinland-Pfalz',        'lat' => 49.4401, 'lng' => 7.7491,  'pop' => 101000,  'lvl' => 3],
        ['name' => 'Jena',                'zip' => '07743', 'state' => 'Thüringen',              'lat' => 50.9271, 'lng' => 11.5892, 'pop' => 111000,  'lvl' => 3],
        ['name' => 'Cottbus',             'zip' => '03046', 'state' => 'Brandenburg',            'lat' => 51.7563, 'lng' => 14.3329, 'pop' => 100000,  'lvl' => 3],

        // Weitere Städte & Kreisstädte (Detailstufe 4–5)
        ['name' => 'Siegen',              'zip' => '57072', 'state' => 'Nordrhein-Westfalen',    'lat' => 50.8748, 'lng' => 8.0243,  'pop' => 102000,  'lvl' => 4],
        ['name' => 'Gütersloh',           'zip' => '33330', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.9032, 'lng' => 8.3858,  'pop' => 102000,  'lvl' => 4],
        ['name' => 'Düren',               'zip' => '52349', 'state' => 'Nordrhein-Westfalen',    'lat' => 50.8031, 'lng' => 6.4820,  'pop' => 93000,   'lvl' => 4],
        ['name' => 'Ratingen',            'zip' => '40878', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.2964, 'lng' => 6.8486,  'pop' => 87000,   'lvl' => 4],
        ['name' => 'Bocholt',             'zip' => '46395', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.8388, 'lng' => 6.6153,  'pop' => 72000,   'lvl' => 4],
        ['name' => 'Detmold',             'zip' => '32756', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.9384, 'lng' => 8.8788,  'pop' => 75000,   'lvl' => 4],
        ['name' => 'Arnsberg',            'zip' => '59755', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.3967, 'lng' => 8.0645,  'pop' => 74000,   'lvl' => 4],
        ['name' => 'Kleve',               'zip' => '47533', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.7893, 'lng' => 6.1381,  'pop' => 53000,   'lvl' => 4],
        ['name' => 'Wesel',               'zip' => '46483', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.6587, 'lng' => 6.6170,  'pop' => 61000,   'lvl' => 4],
        ['name' => 'Viersen',             'zip' => '41747', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.2558, 'lng' => 6.3941,  'pop' => 78000,   'lvl' => 4],
        ['name' => 'Recklinghausen',      'zip' => '45657', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.6141, 'lng' => 7.1979,  'pop' => 111000,  'lvl' => 4],
        ['name' => 'Minden',              'zip' => '32423', 'state' => 'Nordrhein-Westfalen',    'lat' => 52.2889, 'lng' => 8.9168,  'pop' => 83000,   'lvl' => 4],
        ['name' => 'Offenburg',           'zip' => '77652', 'state' => 'Baden-Württemberg',      'lat' => 48.4735, 'lng' => 7.9449,  'pop' => 62000,   'lvl' => 4],
        ['name' => 'Baden-Baden',         'zip' => '76530', 'state' => 'Baden-Württemberg',      'lat' => 48.7606, 'lng' => 8.2398,  'pop' => 57000,   'lvl' => 4],
        ['name' => 'Friedrichshafen',     'zip' => '88045', 'state' => 'Baden-Württemberg',      'lat' => 47.6542, 'lng' => 9.4792,  'pop' => 63000,   'lvl' => 4],
        ['name' => 'Ravensburg',          'zip' => '88212', 'state' => 'Baden-Württemberg',      'lat' => 47.7819, 'lng' => 9.6136,  'pop' => 51000,   'lvl' => 4],
        ['name' => 'Aalen',               'zip' => '73430', 'state' => 'Baden-Württemberg',      'lat' => 48.8378, 'lng' => 10.0933, 'pop' => 68000,   'lvl' => 4],
        ['name' => 'Göppingen',           'zip' => '73033', 'state' => 'Baden-Württemberg',      'lat' => 48.7035, 'lng' => 9.6526,  'pop' => 59000,   'lvl' => 4],
        ['name' => 'Sindelfingen',        'zip' => '71063', 'state' => 'Baden-Württemberg',      'lat' => 48.7093, 'lng' => 9.0032,  'pop' => 65000,   'lvl' => 4],
        ['name' => 'Böblingen',           'zip' => '71032', 'state' => 'Baden-Württemberg',      'lat' => 48.6855, 'lng' => 9.0153,  'pop' => 51000,   'lvl' => 4],
        ['name' => 'Bamberg',             'zip' => '96047', 'state' => 'Bayern',                 'lat' => 49.8988, 'lng' => 10.9028, 'pop' => 79000,   'lvl' => 4],
        ['name' => 'Bayreuth',            'zip' => '95444', 'state' => 'Bayern',                 'lat' => 49.9456, 'lng' => 11.5713, 'pop' => 74000,   'lvl' => 4],
        ['name' => 'Passau',              'zip' => '94032', 'state' => 'Bayern',                 'lat' => 48.5667, 'lng' => 13.4319, 'pop' => 54000,   'lvl' => 4],
        ['name' => 'Kempten (Allgäu)',    'zip' => '87435', 'state' => 'Bayern',                 'lat' => 47.7267, 'lng' => 10.3168, 'pop' => 70000,   'lvl' => 4],
        ['name' => 'Rosenheim',           'zip' => '83022', 'state' => 'Bayern',                 'lat' => 47.8571, 'lng' => 12.1281, 'pop' => 64000,   'lvl' => 4],
        ['name' => 'Fulda',               'zip' => '36037', 'state' => 'Hessen',                 'lat' => 50.5558, 'lng' => 9.6808,  'pop' => 70000,   'lvl' => 4],
        ['name' => 'Gießen',              'zip' => '35390', 'state' => 'Hessen',                 'lat' => 50.5841, 'lng' => 8.6784,  'pop' => 94000,   'lvl' => 4],
        ['name' => 'Marburg',             'zip' => '35037', 'state' => 'Hessen',                 'lat' => 50.8022, 'lng' => 8.7668,  'pop' => 78000,   'lvl' => 4],
        ['name' => 'Flensburg',           'zip' => '24937', 'state' => 'Schleswig-Holstein',     'lat' => 54.7937, 'lng' => 9.4469,  'pop' => 92000,   'lvl' => 4],
        ['name' => 'Lüneburg',            'zip' => '21335', 'state' => 'Niedersachsen',          'lat' => 53.2464, 'lng' => 10.4115, 'pop' => 77000,   'lvl' => 4],
        ['name' => 'Celle',               'zip' => '29221', 'state' => 'Niedersachsen',          'lat' => 52.6226, 'lng' => 10.0805, 'pop' => 70000,   'lvl' => 4],
        ['name' => 'Emden',               'zip' => '26721', 'state' => 'Niedersachsen',          'lat' => 53.3675, 'lng' => 7.2061,  'pop' => 50000,   'lvl' => 4],
        ['name' => 'Cuxhaven',            'zip' => '27472', 'state' => 'Niedersachsen',          'lat' => 53.8618, 'lng' => 8.6942,  'pop' => 48000,   'lvl' => 5],
        ['name' => 'Sylt (Westerland)',   'zip' => '25980', 'state' => 'Schleswig-Holstein',     'lat' => 54.9079, 'lng' => 8.3093,  'pop' => 13000,   'lvl' => 5],
        ['name' => 'Fehmarn',             'zip' => '23769', 'state' => 'Schleswig-Holstein',     'lat' => 54.4375, 'lng' => 11.1936, 'pop' => 13000,   'lvl' => 5],
        ['name' => 'Garmisch-Partenkirchen','zip' => '82467','state' => 'Bayern',                'lat' => 47.4917, 'lng' => 11.0955, 'pop' => 27000,   'lvl' => 5],
        ['name' => 'Berchtesgaden',       'zip' => '83471', 'state' => 'Bayern',                 'lat' => 47.6301, 'lng' => 13.0001, 'pop' => 7700,    'lvl' => 5],
        ['name' => 'Winterberg',          'zip' => '59955', 'state' => 'Nordrhein-Westfalen',    'lat' => 51.1950, 'lng' => 8.5300,  'pop' => 12500,   'lvl' => 5],
    ];
}

// =========================================================================
// 4. HILFSFUNKTIONEN: GPX/GEOJSON-PARSER, EXIF-GPS & MBTILES-READER
// =========================================================================
function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    return $r * (2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a))));
}

/**
 * Parst GPX-, KML- oder GeoJSON-Inhalte sicher (ohne XXE) zu einem Array aus Koordinaten + Statistik.
 */
function parseTrackContent(string $rawContent, string $ext = 'gpx'): array
{
    $points = [];
    $ext = strtolower(trim($ext, '.'));

    if ($ext === 'geojson' || $ext === 'json' || str_starts_with(ltrim($rawContent), '{')) {
        $decoded = @json_decode($rawContent, true);
        if (is_array($decoded)) {
            $extractCoords = static function ($coords) use (&$extractCoords, &$points): void {
                if (!is_array($coords) || empty($coords)) {
                    return;
                }
                if (isset($coords[0], $coords[1]) && is_numeric($coords[0]) && is_numeric($coords[1])) {
                    $lng = (float)$coords[0];
                    $lat = (float)$coords[1];
                    $ele = isset($coords[2]) && is_numeric($coords[2]) ? round((float)$coords[2], 1) : null;
                    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                        $points[] = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'ele' => $ele];
                    }
                    return;
                }
                foreach ($coords as $sub) {
                    $extractCoords($sub);
                }
            };
            if (isset($decoded['features']) && is_array($decoded['features'])) {
                foreach ($decoded['features'] as $feat) {
                    if (isset($feat['geometry']['coordinates'])) {
                        $extractCoords($feat['geometry']['coordinates']);
                    }
                }
            } elseif (isset($decoded['coordinates'])) {
                $extractCoords($decoded['coordinates']);
            }
        }
    } else {
        $prevUseErrors = libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($rawContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if ($xml !== false) {
            if (preg_match_all('/<(?:trkpt|rtept|wpt)\b[^>]*lat=["\']([0-9.+-]+)["\'][^>]*lon=["\']([0-9.+-]+)["\'][^>]*>(.*?)<\/(?:trkpt|rtept|wpt)>/is', $rawContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $lat = (float)$m[1];
                    $lng = (float)$m[2];
                    $ele = null;
                    if (preg_match('/<ele>([0-9.+-]+)<\/ele>/i', $m[3], $em)) {
                        $ele = round((float)$em[1], 1);
                    }
                    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                        $points[] = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'ele' => $ele];
                    }
                }
            }
            if (empty($points) && preg_match_all('/<coordinates>(.*?)<\/coordinates>/is', $rawContent, $kmlMatches)) {
                foreach ($kmlMatches[1] as $coordBlock) {
                    $tuples = preg_split('/\s+/', trim($coordBlock)) ?: [];
                    foreach ($tuples as $tuple) {
                        $parts = explode(',', trim($tuple));
                        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                            $lng = (float)$parts[0];
                            $lat = (float)$parts[1];
                            $ele = isset($parts[2]) && is_numeric($parts[2]) ? round((float)$parts[2], 1) : null;
                            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                                $points[] = ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'ele' => $ele];
                            }
                        }
                    }
                }
            }
        }
    }

    // Auf maximal 1.500 Stützpunkte ausdünnen, um DB & Browser-Performance im Intranet optimal zu halten
    $totalRaw = count($points);
    if ($totalRaw > 1500) {
        $step = $totalRaw / 1500;
        $sampled = [];
        for ($i = 0; $i < 1500; $i++) {
            $sampled[] = $points[(int)floor($i * $step)];
        }
        $sampled[] = $points[$totalRaw - 1];
        $points = $sampled;
    }

    $distKm = 0.0;
    $ascent = 0.0;
    $descent = 0.0;
    for ($i = 1, $n = count($points); $i < $n; $i++) {
        $distKm += haversineDistanceKm($points[$i - 1]['lat'], $points[$i - 1]['lng'], $points[$i]['lat'], $points[$i]['lng']);
        if ($points[$i - 1]['ele'] !== null && $points[$i]['ele'] !== null) {
            $diff = (float)$points[$i]['ele'] - (float)$points[$i - 1]['ele'];
            if ($diff > 0.5) {
                $ascent += $diff;
            } elseif ($diff < -0.5) {
                $descent += abs($diff);
            }
        }
    }

    return [
        'points'      => $points,
        'distance_km' => round($distKm, 2),
        'ascent_m'    => (int)round($ascent),
        'descent_m'   => (int)round($descent),
    ];
}

/**
 * Liest eine Kartenkachel aus einer lokalen .mbtiles SQLite-Datei (falls in uploads/maps/ vorhanden).
 */
function readTileFromLocalMbtiles(string $mapsBaseDir, int $z, int $x, int $y): ?array
{
    if (!extension_loaded('pdo_sqlite')) {
        return null;
    }
    $mbtilesFiles = glob($mapsBaseDir . '/*.mbtiles') ?: [];
    if (empty($mbtilesFiles)) {
        return null;
    }
    // TMS Y-Koordinate in MBTiles ist umgekehrt: (2^z - 1) - y
    $tmsY = ((1 << $z) - 1) - $y;
    foreach ($mbtilesFiles as $mbFile) {
        if (!is_readable($mbFile)) {
            continue;
        }
        try {
            $sqlite = new PDO('sqlite:' . $mbFile, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $stmt = $sqlite->prepare('SELECT tile_data FROM tiles WHERE zoom_level = :z AND tile_column = :x AND tile_row = :y LIMIT 1');
            if ($stmt && $stmt->execute([':z' => $z, ':x' => $x, ':y' => $tmsY])) {
                $blob = $stmt->fetchColumn();
                if (is_string($blob) && $blob !== '') {
                    $mime = 'image/png';
                    if (str_starts_with($blob, "\xFF\xD8\xFF")) {
                        $mime = 'image/jpeg';
                    } elseif (str_starts_with($blob, "RIFF") && substr($blob, 8, 4) === 'WEBP') {
                        $mime = 'image/webp';
                    }
                    return ['data' => $blob, 'mime' => $mime];
                }
            }
        } catch (Throwable) {
            continue;
        }
    }
    return null;
}

/**
 * Erzeugt im 100 % abgeschotteten Intranet-Modus eine saubere, hochauflösende SVG-Kartengrundkachel
 * mit Koordinatennetz, Maßstabs-Indikator und nahtlosem Übergang zum lokalen Vektor-Overlay.
 */
function renderOfflineVectorFallbackTileSvg(int $z, int $x, int $y, string $style = 'intranet_light'): string
{
    $n = max(1, 1 << $z);
    $lonLeft  = ($x / $n) * 360.0 - 180.0;
    $latTopRad = atan(sinh(M_PI * (1.0 - 2.0 * $y / $n)));
    $latTop   = rad2deg($latTopRad);

    $isDark = ($style === 'intranet_dark' || $style === 'topo_dark');
    $bg     = $isDark ? '#0f172a' : ($style === 'topo' ? '#ebe7dc' : '#e5eef5');
    $grid   = $isDark ? '#1e293b' : ($style === 'topo' ? '#d8d1c0' : '#cddbe7');
    $subGrid = $isDark ? '#162032' : ($style === 'topo' ? '#e2dccd' : '#dae6f0');
    $txt    = $isDark ? '#475569' : '#64748b';

    $coordLabel = sprintf('Z%d · %s°N %s°E', $z, number_format($latTop, 2, '.', ''), number_format($lonLeft, 2, '.', ''));

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">
  <rect width="256" height="256" fill="{$bg}"/>
  <path d="M64 0v256M128 0v256M192 0v256M0 64h256M0 128h256M0 192h256" stroke="{$subGrid}" stroke-width="1" stroke-dasharray="3,3"/>
  <rect width="256" height="256" fill="none" stroke="{$grid}" stroke-width="1"/>
  <text x="8" y="18" fill="{$txt}" font-family="system-ui, sans-serif" font-size="9" opacity="0.65">{$coordLabel}</text>
</svg>
SVG;
}

// =========================================================================
// 5. DIREKTE ENDPUNKTE: LOKALER TILE-SERVER, LAGEPLAN-BILD & OFFLINE-SUCHE
// =========================================================================

// 5a) Lokaler Kachel-Endpunkt (?action=tile&style=osm&z=6&x=33&y=21)
if (isset($_GET['action']) && $_GET['action'] === 'tile') {
    @session_write_close();
    $style = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['style'] ?? 'osm')) ?: 'osm';
    $z = max(0, min(19, (int)($_GET['z'] ?? 6)));
    $maxCoord = max(1, (1 << $z) - 1);
    $x = max(0, min($maxCoord, (int)($_GET['x'] ?? 0)));
    $y = max(0, min($maxCoord, (int)($_GET['y'] ?? 0)));
    $forceAirGap = isset($_GET['airgap']) ? ($_GET['airgap'] === '1') : $airGapMode;

    // 1. Zuerst in lokalen .mbtiles Kartenpaketen suchen (100 % Offline)
    $mbTile = readTileFromLocalMbtiles($mapsBaseDir, $z, $x, $y);
    if ($mbTile !== null) {
        header('Content-Type: ' . $mbTile['mime']);
        header('Cache-Control: public, max-age=604800');
        header('X-MiniCloud-Map-Source: local-mbtiles');
        echo $mbTile['data'];
        exit;
    }

    // 2. Im lokalen Festplatten-Tile-Cache (uploads/map_tiles/{style}/{z}/{x}/{y}.png)
    //    sowie im mitgelieferten Offline-Basispaket (assets/maps/tiles_osm/{z}/{x}/{y}.png) suchen
    $cacheStyle = in_array($style, ['osm', 'topo', 'cyclosm'], true) ? $style : 'osm';
    $tilePath = sprintf('%s/%s/%d/%d/%d.png', $tilesCacheDir, $cacheStyle, $z, $x, $y);
    $bundledTilePath = sprintf('%s/assets/maps/tiles_osm/%d/%d/%d.png', __DIR__, $z, $x, $y);

    if (is_file($tilePath) && filesize($tilePath) > 64) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=604800');
        header('X-MiniCloud-Map-Source: local-disk-cache');
        readfile($tilePath);
        exit;
    }
    if ($cacheStyle === 'osm' && is_file($bundledTilePath) && filesize($bundledTilePath) > 64) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=604800');
        header('X-MiniCloud-Map-Source: bundled-offline-osm');
        readfile($bundledTilePath);
        exit;
    }

    // 3. Falls NICHT im strikten Air-Gap-Modus und Stile = osm/topo: On-Demand über Server cachen
    if (!$forceAirGap && in_array($style, ['osm', 'topo'], true)) {
        $remoteUrl = $style === 'topo'
            ? sprintf('https://a.tile.opentopomap.org/%d/%d/%d.png', $z, $x, $y)
            : sprintf('https://tile.openstreetmap.org/%d/%d/%d.png', $z, $x, $y);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3.5,
                'header'  => "User-Agent: MiniCloud-Intranet-TileCache/1.0\r\nAccept: image/png,image/*\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $imgData = @file_get_contents($remoteUrl, false, $ctx);
        if (is_string($imgData) && strlen($imgData) > 100 && str_starts_with($imgData, "\x89PNG")) {
            $tileDir = dirname($tilePath);
            if (!is_dir($tileDir)) {
                @mkdir($tileDir, 0750, true);
            }
            @file_put_contents($tilePath, $imgData, LOCK_EX);
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=604800');
            header('X-MiniCloud-Map-Source: server-proxy-cached');
            echo $imgData;
            exit;
        }
    }

    // 4. Smart Offline-Overzoom: Falls für höhere Zoomstufe keine eigene Kachel vorliegt,
    //    aus der nächsthöheren lokal vorhandenen Eltern-Kachel (z.B. Zoom 8..14) per GD ausschneiden & skalieren!
    if (in_array($style, ['osm', 'topo'], true) && function_exists('imagecreatetruecolor') && $z > 5) {
        for ($pz = $z - 1; $pz >= 5; $pz--) {
            $shift = $z - $pz;
            if ($shift > 6) {
                break;
            }
            $px = $x >> $shift;
            $py = $y >> $shift;
            $parentCache = sprintf('%s/%s/%d/%d/%d.png', $tilesCacheDir, $cacheStyle, $pz, $px, $py);
            $parentBundled = sprintf('%s/assets/maps/tiles_osm/%d/%d/%d.png', __DIR__, $pz, $px, $py);
            $srcFile = null;
            if (is_file($parentCache) && filesize($parentCache) > 64) {
                $srcFile = $parentCache;
            } elseif ($cacheStyle === 'osm' && is_file($parentBundled) && filesize($parentBundled) > 64) {
                $srcFile = $parentBundled;
            }
            if ($srcFile !== null) {
                $srcImg = @imagecreatefrompng($srcFile);
                if ($srcImg !== false) {
                    $subDiv = 1 << $shift;
                    $subSize = 256 / $subDiv;
                    $srcX = (int)round(($x % $subDiv) * $subSize);
                    $srcY = (int)round(($y % $subDiv) * $subSize);
                    $srcW = max(1, (int)round($subSize));
                    $dstImg = imagecreatetruecolor(256, 256);
                    imagecopyresampled($dstImg, $srcImg, 0, 0, $srcX, $srcY, 256, 256, $srcW, $srcW);
                    header('Content-Type: image/png');
                    header('Cache-Control: public, max-age=86400');
                    header('X-MiniCloud-Map-Source: offline-overzoom-z' . $pz);
                    imagepng($dstImg);
                    imagedestroy($srcImg);
                    imagedestroy($dstImg);
                    exit;
                }
            }
        }
    }

    // 5. 100 % Abgeschotteter Intranet-Fallback: Saubere SVG-Kachel ohne Außenverbindung liefern
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    header('X-MiniCloud-Map-Source: airgap-vector-tile');
    echo renderOfflineVectorFallbackTileSvg($z, $x, $y, $style);
    exit;
}

// 5a-2) Vollständige Offline-Suche in allen ~23.300 deutschen PLZ, Städten, Gemeinden & Landkreisen (?action=offline_search&q=...)
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
        $rawJson = @file_get_contents($plzFile);
        $entries = is_string($rawJson) ? @json_decode($rawJson, true) : null;
        if (is_array($entries)) {
            $exactMatches = [];
            $partialMatches = [];
            foreach ($entries as $row) {
                // Format: [0 => zip, 1 => name, 2 => state, 3 => kreis, 4 => lat, 5 => lng]
                $zip   = (string)($row[0] ?? '');
                $name  = (string)($row[1] ?? '');
                $state = (string)($row[2] ?? '');
                $kreis = (string)($row[3] ?? '');
                $nameLower = mb_strtolower($name);
                $kreisLower = mb_strtolower($kreis);

                if (str_starts_with($zip, $q) || str_starts_with($nameLower, $q)) {
                    $exactMatches[] = [
                        'zip'   => $zip,
                        'name'  => $name,
                        'state' => $state,
                        'kreis' => $kreis,
                        'lat'   => (float)$row[4],
                        'lng'   => (float)$row[5],
                    ];
                    if (count($exactMatches) >= 18) {
                        break;
                    }
                } elseif (count($partialMatches) < 15 && (str_contains($nameLower, $q) || str_contains($kreisLower, $q))) {
                    $partialMatches[] = [
                        'zip'   => $zip,
                        'name'  => $name,
                        'state' => $state,
                        'kreis' => $kreis,
                        'lat'   => (float)$row[4],
                        'lng'   => (float)$row[5],
                    ];
                }
            }
            $results = array_slice(array_merge($exactMatches, $partialMatches), 0, 18);
        }
    }
    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5b) Eigenes Lageplan-/Gebäudeplan-Bild ausliefern (?action=floorplan_img&id=...)
if (isset($_GET['action']) && $_GET['action'] === 'floorplan_img') {
    @session_write_close();
    $fpId = (int)($_GET['id'] ?? 0);
    $stmtFp = $pdo->prepare('SELECT * FROM map_floorplans WHERE id = :id AND (user_id = :uid OR is_shared = 1) LIMIT 1');
    $stmtFp->execute([':id' => $fpId, ':uid' => $userId]);
    $fpRow = $stmtFp->fetch();
    if ($fpRow) {
        $fpFile = $floorplansDir . '/' . basename((string)$fpRow['stored_filename']);
        if (is_file($fpFile)) {
            header('Content-Type: ' . ((string)$fpRow['mime_type'] ?: 'image/png'));
            header('Cache-Control: private, max-age=3600');
            readfile($fpFile);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

// 5c) Live-Regenradar Metadaten (RainViewer Radar-Zeitleiste + Open-Meteo Live-Wetter für DACH-Städte) (?action=rain_radar_meta)
if (isset($_GET['action']) && $_GET['action'] === 'rain_radar_meta') {
    @session_write_close();
    header('Content-Type: application/json; charset=UTF-8');

    $radarCacheDir = $tilesCacheDir . '/radar';
    if (!is_dir($radarCacheDir)) {
        @mkdir($radarCacheDir, 0775, true);
    }

    // 1. RainViewer Radar-Zeitstempel laden (3 Minuten Cache)
    $metaCacheFile = $radarCacheDir . '/weather_maps_meta.json';
    $rawMeta = null;
    if (is_file($metaCacheFile) && (time() - (int)@filemtime($metaCacheFile)) < 180) {
        $rawMeta = @file_get_contents($metaCacheFile);
    }
    if (!is_string($rawMeta) || $rawMeta === '') {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 5.0,
                'header'  => "User-Agent: MiniCloud-RadarProxy/1.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $fetched = @file_get_contents('https://api.rainviewer.com/public/weather-maps.json', false, $ctx);
        if (is_string($fetched) && $fetched !== '') {
            $rawMeta = $fetched;
            @file_put_contents($metaCacheFile, $rawMeta);
        } elseif (is_file($metaCacheFile)) {
            $rawMeta = @file_get_contents($metaCacheFile);
        }
    }

    $frames = [];
    $radarHost = 'https://tilecache.rainviewer.com';
    if (is_string($rawMeta) && $rawMeta !== '') {
        $parsed = @json_decode($rawMeta, true);
        if (is_array($parsed)) {
            if (!empty($parsed['host']) && is_string($parsed['host'])) {
                $radarHost = rtrim($parsed['host'], '/');
            }
            $past = $parsed['radar']['past'] ?? [];
            $nowcast = $parsed['radar']['nowcast'] ?? [];
            if (is_array($past)) {
                foreach ($past as $f) {
                    if (!empty($f['time']) && !empty($f['path'])) {
                        $ts = (int)$f['time'];
                        $frames[] = [
                            'time'     => $ts,
                            'path'     => (string)$f['path'],
                            'label'    => date('H:i', $ts) . ' Uhr',
                            'forecast' => false,
                        ];
                    }
                }
            }
            if (is_array($nowcast)) {
                foreach ($nowcast as $f) {
                    if (!empty($f['time']) && !empty($f['path'])) {
                        $ts = (int)$f['time'];
                        $diffMin = max(5, (int)round(($ts - time()) / 60));
                        $frames[] = [
                            'time'     => $ts,
                            'path'     => (string)$f['path'],
                            'label'    => date('H:i', $ts) . ' Uhr (+' . $diffMin . ' Min. Prognose)',
                            'forecast' => true,
                        ];
                    }
                }
            }
        }
    }

    // 2. Live-Wetter-Pins (Temperatur, Wetterzustand, Wind & Niederschlag) für 14 DACH-Stationen (10 Min. Cache)
    $stations = [
        ['name' => 'Hamburg',   'lat' => 53.5511, 'lng' => 9.9937],
        ['name' => 'Berlin',    'lat' => 52.5200, 'lng' => 13.4050],
        ['name' => 'Hannover',  'lat' => 52.3759, 'lng' => 9.7320],
        ['name' => 'Köln',      'lat' => 50.9375, 'lng' => 6.9603],
        ['name' => 'Frankfurt', 'lat' => 50.1109, 'lng' => 8.6821],
        ['name' => 'Leipzig',   'lat' => 51.3397, 'lng' => 12.3731],
        ['name' => 'Dresden',   'lat' => 51.0504, 'lng' => 13.7373],
        ['name' => 'Nürnberg',  'lat' => 49.4521, 'lng' => 11.0767],
        ['name' => 'Stuttgart', 'lat' => 48.7758, 'lng' => 9.1829],
        ['name' => 'Freiburg',  'lat' => 47.9990, 'lng' => 7.8421],
        ['name' => 'München',   'lat' => 48.1351, 'lng' => 11.5820],
        ['name' => 'Rostock',   'lat' => 54.0924, 'lng' => 12.0991],
        ['name' => 'Wien',      'lat' => 48.2082, 'lng' => 16.3738],
        ['name' => 'Zürich',    'lat' => 47.3769, 'lng' => 8.5417],
    ];

    $wxCacheFile = $radarCacheDir . '/cities_live_wx.json';
    $cityWeather = [];
    if (is_file($wxCacheFile) && (time() - (int)@filemtime($wxCacheFile)) < 600) {
        $cachedWx = @json_decode((string)@file_get_contents($wxCacheFile), true);
        if (is_array($cachedWx)) {
            $cityWeather = $cachedWx;
        }
    }

    if (empty($cityWeather)) {
        $lats = implode(',', array_map(static fn($s) => (string)$s['lat'], $stations));
        $lngs = implode(',', array_map(static fn($s) => (string)$s['lng'], $stations));
        $wxUrl = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lats
               . '&longitude=' . $lngs
               . '&current=temperature_2m,precipitation,weather_code,wind_speed_10m&timezone=Europe%2FBerlin';
        $ctxWx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 4.5, 'header' => "User-Agent: MiniCloud-Weather/1.0\r\n"],
        ]);
        $rawWx = @file_get_contents($wxUrl, false, $ctxWx);
        $wxArr = is_string($rawWx) ? @json_decode($rawWx, true) : null;
        if (is_array($wxArr)) {
            $wxList = isset($wxArr[0]) ? $wxArr : [$wxArr];
            foreach ($stations as $i => $st) {
                $cur = $wxList[$i]['current'] ?? [];
                $wCode = (int)($cur['weather_code'] ?? 0);
                $icon = match (true) {
                    $wCode === 0 => '☀️',
                    in_array($wCode, [1, 2], true) => '🌤️',
                    $wCode === 3 => '☁️',
                    in_array($wCode, [45, 48], true) => '🌫️',
                    in_array($wCode, [51, 53, 55, 56, 57], true) => '🌦️',
                    in_array($wCode, [61, 63, 65, 66, 67, 80, 81, 82], true) => '🌧️',
                    in_array($wCode, [71, 73, 75, 77, 85, 86], true) => '❄️',
                    $wCode >= 95 => '⛈️',
                    default => '⛅',
                };
                $desc = match (true) {
                    $wCode === 0 => 'Klar & sonnig',
                    in_array($wCode, [1, 2], true) => 'Leicht bewölkt',
                    $wCode === 3 => 'Bedeckt',
                    in_array($wCode, [45, 48], true) => 'Nebel',
                    in_array($wCode, [51, 53, 55], true) => 'Nieselregen',
                    in_array($wCode, [61, 63, 80, 81], true) => 'Regen',
                    in_array($wCode, [65, 82], true) => 'Starkregen',
                    in_array($wCode, [71, 73, 75, 85, 86], true) => 'Schneefall',
                    $wCode >= 95 => 'Gewitter / Sturm',
                    default => 'Wechselhaft',
                };
                $cityWeather[] = [
                    'name'   => $st['name'],
                    'lat'    => $st['lat'],
                    'lng'    => $st['lng'],
                    'temp'   => isset($cur['temperature_2m']) ? round((float)$cur['temperature_2m'], 1) : null,
                    'precip' => isset($cur['precipitation']) ? round((float)$cur['precipitation'], 1) : 0.0,
                    'wind'   => isset($cur['wind_speed_10m']) ? round((float)$cur['wind_speed_10m'], 0) : 0,
                    'icon'   => $icon,
                    'desc'   => $desc,
                ];
            }
            @file_put_contents($wxCacheFile, json_encode($cityWeather, JSON_UNESCAPED_UNICODE));
        }
    }

    echo json_encode([
        'ok'     => !empty($frames),
        'host'   => $radarHost,
        'frames' => $frames,
        'cities' => $cityWeather,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5d) Datenschutzfreundlicher Regenradar-Kachel-Proxy mit lokalem Kurzzeit-Cache (?action=rain_radar_tile&path=...&z=...&x=...&y=...)
if (isset($_GET['action']) && $_GET['action'] === 'rain_radar_tile') {
    @session_write_close();
    $path = trim((string)($_GET['path'] ?? ''));
    $z = max(0, min(10, (int)($_GET['z'] ?? 6)));
    $maxCoord = max(1, (1 << $z) - 1);
    $x = max(0, min($maxCoord, (int)($_GET['x'] ?? 0)));
    $y = max(0, min($maxCoord, (int)($_GET['y'] ?? 0)));

    // 1x1 transparentes PNG als Fallback bei wolken-/niederschlagsfreien Kacheln
    $transparentPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    if (!preg_match('#^/v2/radar/[a-zA-Z0-9_/-]+$#', $path)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        echo $transparentPng;
        exit;
    }

    $radarCacheDir = $tilesCacheDir . '/radar';
    if (!is_dir($radarCacheDir)) {
        @mkdir($radarCacheDir, 0775, true);
    }

    // Gelegentliche Bereinigung alter Radar-Kacheln (> 3 Stunden)
    if (random_int(1, 80) === 1) {
        $nowTs = time();
        foreach (glob($radarCacheDir . '/rt_*.png') ?: [] as $oldTile) {
            if (($nowTs - (int)@filemtime($oldTile)) > 10800) {
                @unlink($oldTile);
            }
        }
    }

    $cacheKey = 'rt_' . substr(md5($path), 0, 12) . '_' . $z . '_' . $x . '_' . $y . '.png';
    $cacheFile = $radarCacheDir . '/' . $cacheKey;

    if (is_file($cacheFile) && filesize($cacheFile) > 40) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=1800');
        header('X-MiniCloud-Radar-Cache: HIT');
        readfile($cacheFile);
        exit;
    }

    // RainViewer Tile-URL: {host}{path}/256/{z}/{x}/{y}/2/1_1.png (Farbschema 2 = Universal Blue, 1_1 = Glättung + Schnee)
    $remoteUrl = 'https://tilecache.rainviewer.com' . $path . '/256/' . $z . '/' . $x . '/' . $y . '/2/1_1.png';
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 4.5,
            'header'  => "User-Agent: MiniCloud-RadarProxy/1.0\r\nAccept: image/png,image/*\r\n",
        ],
    ]);
    $imgData = @file_get_contents($remoteUrl, false, $ctx);
    if (is_string($imgData) && strlen($imgData) > 60 && str_starts_with($imgData, "\x89PNG")) {
        @file_put_contents($cacheFile, $imgData);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=1800');
        header('X-MiniCloud-Radar-Cache: MISS');
        echo $imgData;
        exit;
    }

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=600');
    echo $transparentPng;
    exit;
}

// 5e) Live-Umkreis-Finder rund um Standort / Kartenmitte (?action=poi_radius_search&lat=...&lng=...&radius=...&category=...)
if (isset($_GET['action']) && $_GET['action'] === 'poi_radius_search') {
    @session_write_close();
    header('Content-Type: application/json; charset=UTF-8');

    $lat = max(-85.0, min(85.0, (float)($_GET['lat'] ?? 51.1657)));
    $lng = max(-180.0, min(180.0, (float)($_GET['lng'] ?? 10.4515)));
    $radiusKm = max(1.0, min(30.0, (float)($_GET['radius'] ?? 5.0)));
    $radiusMeters = (int)round($radiusKm * 1000);
    $category = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_GET['category'] ?? 'fuel'))) ?: 'fuel';

    $catMeta = [
        'fuel'        => ['label' => 'Tankstellen',              'icon' => '⛽', 'color' => '#ea580c', 'place_cat' => 'car'],
        'charging'    => ['label' => 'E-Ladesäulen',             'icon' => '🔌', 'color' => '#16a34a', 'place_cat' => 'car'],
        'parking'     => ['label' => 'Parkplätze & Parkhäuser',  'icon' => '🅿️', 'color' => '#2563eb', 'place_cat' => 'car'],
        'atm'         => ['label' => 'Geldautomaten & Banken',   'icon' => '🏧', 'color' => '#0284c7', 'place_cat' => 'office'],
        'supermarket' => ['label' => 'Supermärkte & Bäckereien', 'icon' => '🛒', 'color' => '#059669', 'place_cat' => 'favorite'],
        'pharmacy'    => ['label' => 'Apotheken & Kliniken',     'icon' => '💊', 'color' => '#dc2626', 'place_cat' => 'emergency'],
        'aed'         => ['label' => 'Defibrillatoren (AED)',    'icon' => '❤️', 'color' => '#e11d48', 'place_cat' => 'emergency'],
        'food'        => ['label' => 'Restaurants & Cafés',      'icon' => '🍽️', 'color' => '#d97706', 'place_cat' => 'food'],
    ];
    if (!isset($catMeta[$category])) {
        $category = 'fuel';
    }
    $meta = $catMeta[$category];

    $poiCacheDir = $tilesCacheDir . '/poi_cache';
    if (!is_dir($poiCacheDir)) {
        @mkdir($poiCacheDir, 0775, true);
    }

    $cacheFile = sprintf(
        '%s/poi_v2_%s_%.3f_%.3f_%d.json',
        $poiCacheDir,
        $category,
        round($lat, 3),
        round($lng, 3),
        $radiusMeters
    );

    $rawHits = null;
    $sourceLabel = 'OpenStreetMap Live (MiniCloud Proxy)';

    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 900) {
        $cachedJson = @file_get_contents($cacheFile);
        $decoded = is_string($cachedJson) ? @json_decode($cachedJson, true) : null;
        if (is_array($decoded)) {
            $rawHits = $decoded;
            $sourceLabel = 'Lokaler MiniCloud POI-Cache';
        }
    }

    if ($rawHits === null) {
        // Bounding-Box für Umkreis berechnen (1° Breitengrad ≈ 111,32 km)
        $latDelta = $radiusKm / 111.32;
        $cosLat   = max(0.15, abs(cos(deg2rad($lat))));
        $lngDelta = $radiusKm / (111.32 * $cosLat);

        $latTop    = min(85.0, $lat + $latDelta);
        $latBottom = max(-85.0, $lat - $latDelta);
        $lonLeft   = max(-180.0, $lng - $lngDelta);
        $lonRight  = min(180.0, $lng + $lngDelta);
        $viewbox   = sprintf('%.5f,%.5f,%.5f,%.5f', $lonLeft, $latTop, $lonRight, $latBottom);

        $phraseTags = match ($category) {
            'fuel'        => ['[fuel]'],
            'charging'    => ['[charging_station]'],
            'parking'     => ['[parking]'],
            'atm'         => ['[atm]', '[bank]'],
            'supermarket' => ['[supermarket]', '[bakery]'],
            'pharmacy'    => ['[pharmacy]', '[hospital]'],
            'aed'         => ['[defibrillator]'],
            'food'        => ['[restaurant]', '[cafe]'],
            default       => ['[fuel]'],
        };

        $collected = [];
        $ctxNom = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 4.5,
                'header'  => "User-Agent: MiniCloud-Maps-Umkreisfinder/1.0 (https://runready.cloud)\r\nAccept: application/json\r\nAccept-Language: de\r\n",
            ],
        ]);

        foreach ($phraseTags as $tagQ) {
            $nomUrl = 'https://nominatim.openstreetmap.org/search?format=jsonv2'
                    . '&q=' . rawurlencode($tagQ)
                    . '&viewbox=' . rawurlencode($viewbox)
                    . '&bounded=1&addressdetails=1&extratags=1&limit=45';
            $resp = @file_get_contents($nomUrl, false, $ctxNom);
            if (is_string($resp) && $resp !== '') {
                $arr = @json_decode($resp, true);
                if (is_array($arr)) {
                    foreach ($arr as $item) {
                        if (is_array($item) && isset($item['lat'], $item['lon'])) {
                            $collected[] = $item;
                        }
                    }
                }
            }
        }

        if (!empty($collected)) {
            $rawHits = $collected;
            @file_put_contents($cacheFile, json_encode($rawHits, JSON_UNESCAPED_UNICODE));
        }
    }

    // Himmelsrichtung berechnen (N, NO, O, SO, S, SW, W, NW)
    $calcCompass = static function (float $lat1, float $lon1, float $lat2, float $lon2): string {
        $dLon = deg2rad($lon2 - $lon1);
        $y = sin($dLon) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
        $brng = fmod((rad2deg(atan2($y, $x)) + 360.0), 360.0);
        $dirs = ['N', 'NO', 'O', 'SO', 'S', 'SW', 'W', 'NW'];
        return $dirs[(int)round($brng / 45.0) % 8];
    };

    $pois = [];
    $seenCoords = [];

    if (is_array($rawHits)) {
        foreach ($rawHits as $el) {
            $pLat = isset($el['lat']) ? (float)$el['lat'] : null;
            $pLng = isset($el['lon']) ? (float)$el['lon'] : null;
            if ($pLat === null || $pLng === null) {
                continue;
            }
            $coordKey = sprintf('%.4f_%.4f', $pLat, $pLng);
            if (isset($seenCoords[$coordKey])) {
                continue;
            }
            $seenCoords[$coordKey] = true;

            $distKm = haversineDistanceKm($lat, $lng, $pLat, $pLng);
            if ($distKm > $radiusKm * 1.15) {
                continue;
            }

            $tags = is_array($el['extratags'] ?? null) ? $el['extratags'] : [];
            $addr = is_array($el['address'] ?? null) ? $el['address'] : [];
            $elType = (string)($el['type'] ?? '');

            $name = trim((string)($el['name'] ?? $tags['brand'] ?? $tags['operator'] ?? ''));
            if ($name === '') {
                $name = match ($category) {
                    'fuel'        => 'Tankstelle',
                    'charging'    => 'E-Ladestation',
                    'parking'     => (!empty($tags['parking']) && $tags['parking'] === 'multi-storey') ? 'Parkhaus' : 'Parkplatz',
                    'atm'         => ($elType === 'bank') ? 'Bankfiliale' : 'Geldautomat (ATM)',
                    'supermarket' => ($elType === 'bakery') ? 'Bäckerei' : 'Supermarkt',
                    'pharmacy'    => ($elType === 'hospital') ? 'Krankenhaus / Klinik' : 'Apotheke',
                    'aed'         => 'Defibrillator (AED)',
                    'food'        => ($elType === 'cafe') ? 'Café' : 'Restaurant',
                    default       => $meta['label'],
                };
            }

            $brand = trim((string)($tags['brand'] ?? $tags['operator'] ?? ''));
            if ($brand !== '' && stripos($name, $brand) === false) {
                $name .= ' (' . $brand . ')';
            }

            // Adresse aus Nominatim-Adressfeldern zusammensetzen
            $street = trim((string)($addr['road'] ?? $addr['pedestrian'] ?? '') . ' ' . (string)($addr['house_number'] ?? ''));
            $city   = trim((string)($addr['postcode'] ?? '') . ' ' . (string)($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? ''));
            $addrParts = array_values(array_filter([$street, $city]));
            $address = implode(', ', $addrParts);
            if ($address === '' && !empty($tags['defibrillator:location'])) {
                $address = (string)$tags['defibrillator:location'];
            }

            // Zusatzinfos (Kraftstoffe, Ladeleistung, Küche, Öffnungszeiten, Gebühren)
            $extras = [];
            if ($category === 'fuel') {
                if (($tags['fuel:diesel'] ?? '') === 'yes') $extras[] = 'Diesel';
                if (($tags['fuel:e10'] ?? '') === 'yes' || ($tags['fuel:octane_95'] ?? '') === 'yes') $extras[] = 'Super E5/E10';
                if (($tags['fuel:lpg'] ?? '') === 'yes') $extras[] = 'LPG';
            } elseif ($category === 'charging') {
                if (!empty($tags['capacity'])) $extras[] = 'Plätze: ' . $tags['capacity'];
                if (!empty($tags['socket:type2'])) $extras[] = 'Typ 2';
                if (!empty($tags['socket:type2_combo'])) $extras[] = 'CCS Schnelllader';
            } elseif ($category === 'parking') {
                if (!empty($tags['capacity'])) $extras[] = $tags['capacity'] . ' Stellplätze';
                if (($tags['fee'] ?? '') === 'no') $extras[] = 'Kostenlos';
                elseif (($tags['fee'] ?? '') === 'yes') $extras[] = 'Gebührenpflichtig';
            } elseif ($category === 'food' && !empty($tags['cuisine'])) {
                $extras[] = 'Küche: ' . str_replace(';', ', ', (string)$tags['cuisine']);
            }
            if (!empty($tags['opening_hours'])) {
                $extras[] = '🕒 ' . mb_substr((string)$tags['opening_hours'], 0, 50);
            }

            $pois[] = [
                'title'      => $name,
                'address'    => $address,
                'info'       => implode(' · ', $extras),
                'phone'      => (string)($tags['phone'] ?? $tags['contact:phone'] ?? ''),
                'website'    => (string)($tags['website'] ?? $tags['contact:website'] ?? ''),
                'lat'        => round($pLat, 6),
                'lng'        => round($pLng, 6),
                'dist_km'    => round($distKm, 2),
                'direction'  => $calcCompass($lat, $lng, $pLat, $pLng),
                'icon'       => $meta['icon'],
                'color'      => $meta['color'],
                'place_cat'  => $meta['place_cat'],
            ];
        }
    }

    // Offline-Fallback: Falls Air-Gap / kein Internet verfügbar ist, auch gespeicherte Orte aus der DB im Umkreis einbeziehen
    if (empty($pois)) {
        $sourceLabel = 'Lokales Offline-Verzeichnis (Intranet-Fallback)';
        try {
            $stmtLocalPl = $pdo->prepare('SELECT * FROM map_places WHERE (user_id = :uid OR is_shared = 1) AND floorplan_id = 0');
            $stmtLocalPl->execute([':uid' => $userId]);
            foreach ($stmtLocalPl->fetchAll() as $pl) {
                $d = haversineDistanceKm($lat, $lng, (float)$pl['lat'], (float)$pl['lng']);
                if ($d <= $radiusKm) {
                    $pois[] = [
                        'title'     => (string)$pl['title'],
                        'address'   => (string)$pl['address'],
                        'info'      => 'Eigener gespeicherter Ort',
                        'phone'     => '',
                        'website'   => '',
                        'lat'       => (float)$pl['lat'],
                        'lng'       => (float)$pl['lng'],
                        'dist_km'   => round($d, 2),
                        'direction' => $calcCompass($lat, $lng, (float)$pl['lat'], (float)$pl['lng']),
                        'icon'      => (string)$pl['icon'],
                        'color'     => (string)$pl['color'],
                        'place_cat' => (string)$pl['category'],
                    ];
                }
            }
        } catch (Throwable) {
        }
    }

    usort($pois, static fn(array $a, array $b): int => $a['dist_km'] <=> $b['dist_km']);
    $pois = array_slice($pois, 0, 80);

    echo json_encode([
        'ok'       => true,
        'category' => $category,
        'label'    => $meta['label'],
        'icon'     => $meta['icon'],
        'color'    => $meta['color'],
        'radius'   => $radiusKm,
        'source'   => $sourceLabel,
        'count'    => count($pois),
        'results'  => $pois,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5e_2) Flächendeckendes Stromtankstellen- & E-Ladesäulen-Radar (?action=ev_charging_bbox)
if (isset($_GET['action']) && $_GET['action'] === 'ev_charging_bbox') {
    @session_write_close();
    header('Content-Type: application/json; charset=UTF-8');

    $south = max(-85.0, min(85.0, (float)($_GET['south'] ?? 47.2)));
    $north = max(-85.0, min(85.0, (float)($_GET['north'] ?? 55.1)));
    $west  = max(-180.0, min(180.0, (float)($_GET['west'] ?? 5.8)));
    $east  = max(-180.0, min(180.0, (float)($_GET['east'] ?? 15.1)));
    $zoom  = max(4, min(18, (int)($_GET['zoom'] ?? 6)));

    if ($south > $north) {
        [$south, $north] = [$north, $south];
    }
    if ($west > $east) {
        [$west, $east] = [$east, $west];
    }

    $evCacheDir = $tilesCacheDir . '/ev_cache';
    if (!is_dir($evCacheDir)) {
        @mkdir($evCacheDir, 0775, true);
    }

    // Bundesweites Basis-Verzeichnis großer Schnellladeparks (HPC) & Lade-Hubs (immer verfügbar, auch bei Zoom 4–8 & Offline)
    $nationwideHubs = [
        ['title' => 'EnBW HyperHub Berlin-Tempelhof',       'operator' => 'EnBW mobility+',     'city' => 'Berlin',              'lat' => 52.4732, 'lng' => 13.3852, 'kw' => 300, 'cap' => 16, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'IONITY Ladepark Berlin-Mitte / Alex',  'operator' => 'IONITY / Stadtwerke', 'city' => 'Berlin',              'lat' => 52.5219, 'lng' => 13.4132, 'kw' => 350, 'cap' => 12, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'Tesla Supercharger Berlin-EUREF',      'operator' => 'Tesla Supercharger',  'city' => 'Berlin',              'lat' => 52.4815, 'lng' => 13.3568, 'kw' => 250, 'cap' => 20, 'sockets' => 'CCS V3 (250 kW)'],
        ['title' => 'Hamburg Stromnetz Lade-Hub HafenCity', 'operator' => 'Hamburg Energie',     'city' => 'Hamburg',             'lat' => 53.5413, 'lng' => 9.9964,  'kw' => 150, 'cap' => 12, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'Aral pulse Schnellladepark Hamburg',   'operator' => 'Aral pulse',          'city' => 'Hamburg',             'lat' => 53.5652, 'lng' => 9.9810,  'kw' => 300, 'cap' => 10, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'SWM Schnellladepark München-Olympia',  'operator' => 'Stadtwerke München',  'city' => 'München',             'lat' => 48.1731, 'lng' => 11.5466, 'kw' => 300, 'cap' => 18, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW HyperNetz München-Ost',           'operator' => 'EnBW mobility+',     'city' => 'München',             'lat' => 48.1372, 'lng' => 11.6625, 'kw' => 300, 'cap' => 16, 'sockets' => 'CCS (300 kW), CHAdeMO'],
        ['title' => 'TankE Schnellladezentrum Köln-Deutz',  'operator' => 'RheinEnergie TankE',  'city' => 'Köln',                'lat' => 50.9413, 'lng' => 6.9748,  'kw' => 150, 'cap' => 14, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW Schnellladepark Köln-Frechen',    'operator' => 'EnBW mobility+',     'city' => 'Köln / Frechen',      'lat' => 50.9182, 'lng' => 6.8435,  'kw' => 300, 'cap' => 20, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'Mainova Lade-Hub Frankfurt Messe',     'operator' => 'Mainova / IONITY',    'city' => 'Frankfurt am Main',   'lat' => 50.1115, 'lng' => 8.6485,  'kw' => 300, 'cap' => 16, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW Flagship-Park Stuttgart-Zuffenh.','operator' => 'EnBW mobility+',     'city' => 'Stuttgart',           'lat' => 48.8342, 'lng' => 9.1518,  'kw' => 400, 'cap' => 16, 'sockets' => 'CCS (400 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW HyperHub Kamener Kreuz (A1/A2)',  'operator' => 'EnBW mobility+',     'city' => 'Kamen / Dortmund',    'lat' => 51.5880, 'lng' => 7.6852,  'kw' => 300, 'cap' => 52, 'sockets' => 'CCS (300 kW, 52 Ladepunkte)'],
        ['title' => 'Ladepark Kreuz Hilden (Seed & Greet)', 'operator' => 'Tesla / Fastned',     'city' => 'Hilden / Düsseldorf', 'lat' => 51.1811, 'lng' => 6.9125,  'kw' => 300, 'cap' => 62, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'Audi charging hub Nürnberg Messe',     'operator' => 'Audi / HPC',          'city' => 'Nürnberg',            'lat' => 49.4162, 'lng' => 11.1165, 'kw' => 320, 'cap' => 6,  'sockets' => 'CCS (320 kW Lounge)'],
        ['title' => 'Leipziger Stadtwerke Schnellladepark', 'operator' => 'Stadtwerke Leipzig',  'city' => 'Leipzig',             'lat' => 51.3402, 'lng' => 12.3812, 'kw' => 300, 'cap' => 14, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'SachsenEnergie Lade-Hub Dresden',      'operator' => 'SachsenEnergie',      'city' => 'Dresden',             'lat' => 51.0518, 'lng' => 13.7385, 'kw' => 150, 'cap' => 12, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'enercity Schnellladepark Hannover',    'operator' => 'enercity',            'city' => 'Hannover',            'lat' => 52.3755, 'lng' => 9.7382,  'kw' => 300, 'cap' => 16, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'EWE Go Hypercharger Bremen-Flughafen', 'operator' => 'EWE Go',              'city' => 'Bremen',              'lat' => 53.0522, 'lng' => 8.7885,  'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'EnBW Ladepark Rutesheim (A8)',         'operator' => 'EnBW mobility+',     'city' => 'Rutesheim',           'lat' => 48.8095, 'lng' => 8.9522,  'kw' => 300, 'cap' => 16, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'Sortimo Innovationspark Zusmarshausen','operator' => 'Sortimo (A8)',        'city' => 'Zusmarshausen',       'lat' => 48.4038, 'lng' => 10.6052, 'kw' => 350, 'cap' => 72, 'sockets' => 'CCS (350 kW, 72 Ladepunkte)'],
        ['title' => 'IONITY Ladepark Lutterberg (A7)',      'operator' => 'IONITY',              'city' => 'Kassel / Lutterberg', 'lat' => 51.3712, 'lng' => 9.6125,  'kw' => 350, 'cap' => 18, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'IONITY Ladepark Brohltal (A61)',       'operator' => 'IONITY',              'city' => 'Koblenz / Brohltal',  'lat' => 50.4485, 'lng' => 7.2115,  'kw' => 350, 'cap' => 12, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'IONITY Ladepark Hohenwarsleben (A2)',  'operator' => 'IONITY / Allego',     'city' => 'Magdeburg',           'lat' => 52.1815, 'lng' => 11.5012, 'kw' => 350, 'cap' => 16, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'EnBW Schnellladepark Erfurt-Bindersl.','operator' => 'EnBW mobility+',     'city' => 'Erfurt',              'lat' => 50.9785, 'lng' => 10.9620, 'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW), Typ 2'],
        ['title' => 'Stadtwerke Kiel Lade-Hub Förde',       'operator' => 'Stadtwerke Kiel',     'city' => 'Kiel',                'lat' => 54.3233, 'lng' => 10.1394, 'kw' => 150, 'cap' => 10, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'Stadtwerke Rostock Schnelllader',      'operator' => 'SW Rostock / EWE Go', 'city' => 'Rostock',             'lat' => 54.0924, 'lng' => 12.0991, 'kw' => 150, 'cap' => 10, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'badenova Schnellladepark Freiburg',    'operator' => 'badenova / EnBW',     'city' => 'Freiburg im Breisgau','lat' => 47.9990, 'lng' => 7.8421,  'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'Pfalzwerke Lade-Hub Mannheim/Ludwigsh.','operator'=> 'Pfalzwerke',          'city' => 'Mannheim',            'lat' => 49.4875, 'lng' => 8.4660,  'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'Stadtwerke Münster Schnellladepark',   'operator' => 'Stadtwerke Münster',  'city' => 'Münster',             'lat' => 51.9607, 'lng' => 7.6261,  'kw' => 150, 'cap' => 12, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'SWB Lade-Hub Bielefeld (A2)',          'operator' => 'Stadtwerke Bielefeld','city' => 'Bielefeld',           'lat' => 52.0302, 'lng' => 8.5325,  'kw' => 300, 'cap' => 10, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'EWE Go Ladepark Oldenburg',            'operator' => 'EWE Go',              'city' => 'Oldenburg',           'lat' => 53.1435, 'lng' => 8.2146,  'kw' => 300, 'cap' => 10, 'sockets' => 'CCS (300 kW)'],
        ['title' => 'STAWAG Schnellladepark Aachener Kreuz','operator' => 'STAWAG / Fastned',    'city' => 'Aachen',              'lat' => 50.7753, 'lng' => 6.0839,  'kw' => 300, 'cap' => 14, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'Stadtwerke Trier Lade-Hub Mosel',      'operator' => 'SWT Trier',           'city' => 'Trier',               'lat' => 49.7499, 'lng' => 6.6371,  'kw' => 150, 'cap' => 8,  'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'energis Schnellladepark Saarbrücken',  'operator' => 'energis / IONITY',    'city' => 'Saarbrücken',         'lat' => 49.2402, 'lng' => 6.9969,  'kw' => 300, 'cap' => 10, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'WVV Schnellladepark Würzburg (A3/A7)', 'operator' => 'WVV / IONITY',        'city' => 'Würzburg',            'lat' => 49.7913, 'lng' => 9.9534,  'kw' => 350, 'cap' => 16, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'REWAG Lade-Hub Regensburg (A3/A93)',   'operator' => 'REWAG / EnBW',        'city' => 'Regensburg',          'lat' => 49.0134, 'lng' => 12.1016, 'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'SWU Schnellladepark Ulm-Merklingen',   'operator' => 'SWU / EnBW (A8)',     'city' => 'Ulm',                 'lat' => 48.5125, 'lng' => 9.7540,  'kw' => 300, 'cap' => 24, 'sockets' => 'CCS (300 kW, 24 Ladepunkte)'],
        ['title' => 'BS Energy Lade-Hub Braunschweig',      'operator' => 'BS Energy',           'city' => 'Braunschweig',        'lat' => 52.2689, 'lng' => 10.5268, 'kw' => 150, 'cap' => 10, 'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW Ladepark Cottbus / Spreewald',    'operator' => 'EnBW mobility+',     'city' => 'Cottbus',             'lat' => 51.7563, 'lng' => 14.3329, 'kw' => 300, 'cap' => 8,  'sockets' => 'CCS (300 kW)'],
        ['title' => 'Stadtwerke Flensburg Lade-Hub Nord',   'operator' => 'SW Flensburg (A7)',   'city' => 'Flensburg',           'lat' => 54.7937, 'lng' => 9.4469,  'kw' => 300, 'cap' => 12, 'sockets' => 'CCS (300 kW), Typ 2 (22 kW)'],
        ['title' => 'EnBW Ladepark Passau-Süd (A3)',        'operator' => 'EnBW / IONITY',       'city' => 'Passau',              'lat' => 48.5667, 'lng' => 13.4319, 'kw' => 350, 'cap' => 12, 'sockets' => 'CCS (350 kW)'],
        ['title' => 'Stadtwerke Konstanz Lade-Hub Bodensee','operator' => 'SW Konstanz',         'city' => 'Konstanz',            'lat' => 47.6779, 'lng' => 9.1732,  'kw' => 150, 'cap' => 8,  'sockets' => 'CCS (150 kW), Typ 2 (22 kW)'],
    ];

    $stations = [];
    $seenKeys = [];

    $addStation = static function (array $st) use (&$stations, &$seenKeys, $south, $north, $west, $east): void {
        $lat = (float)($st['lat'] ?? 0.0);
        $lng = (float)($st['lng'] ?? 0.0);
        if ($lat < $south || $lat > $north || $lng < $west || $lng > $east) {
            return;
        }
        $key = sprintf('%.4f_%.4f', $lat, $lng);
        if (isset($seenKeys[$key])) {
            return;
        }
        $seenKeys[$key] = true;
        $kw = (int)($st['max_kw'] ?? 22);
        $class = $kw >= 150 ? 'hpc' : ($kw >= 50 ? 'fast' : 'ac');
        $color = $class === 'hpc' ? '#9333ea' : ($class === 'fast' ? '#059669' : '#0284c7');
        $icon  = $class === 'hpc' ? '⚡' : ($class === 'fast' ? '🔋' : '🔌');

        $stations[] = [
            'title'         => (string)($st['title'] ?? 'E-Ladestation'),
            'operator'      => (string)($st['operator'] ?? ''),
            'address'       => (string)($st['address'] ?? ''),
            'lat'           => round($lat, 6),
            'lng'           => round($lng, 6),
            'max_kw'        => $kw,
            'charger_class' => $class,
            'color'         => $color,
            'icon'          => $icon,
            'capacity'      => (int)($st['capacity'] ?? 2),
            'sockets'       => (string)($st['sockets'] ?? 'Typ 2 / CCS'),
            'opening_hours' => (string)($st['opening_hours'] ?? '24/7'),
        ];
    };

    // 1. Bundesweite Hubs immer einbeziehen, falls im Ausschnitt
    foreach ($nationwideHubs as $h) {
        $addStation([
            'title'         => $h['title'],
            'operator'      => $h['operator'],
            'address'       => $h['city'],
            'lat'           => $h['lat'],
            'lng'           => $h['lng'],
            'max_kw'        => $h['kw'],
            'capacity'      => $h['cap'],
            'sockets'       => $h['sockets'],
            'opening_hours' => '24/7 durchgehend geöffnet',
        ]);
    }

    // 2. Autobahn-Ladesäulen des Bundes (A1–A96) aus Cache oder Live-API laden
    $autobahnEvCache = $evCacheDir . '/autobahn_ev_stations_v1.json';
    $autobahnEvList  = null;
    if (is_file($autobahnEvCache) && (time() - (int)@filemtime($autobahnEvCache)) < 7200) {
        $decodedAb = @json_decode((string)@file_get_contents($autobahnEvCache), true);
        if (is_array($decodedAb)) {
            $autobahnEvList = $decodedAb;
        }
    }

    if ($autobahnEvList === null && !$airGapMode) {
        $autobahnEvList = [];
        $roads = ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10', 'A14', 'A20', 'A24', 'A31', 'A40', 'A45', 'A61', 'A81', 'A96'];
        $ctxAb = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 2.2,
                'header'  => "User-Agent: MiniCloud-Maps-EVRadar/1.0\r\nAccept: application/json\r\n",
            ],
        ]);
        foreach ($roads as $rd) {
            $abUrl = 'https://verkehr.autobahn.de/o/autobahn/' . rawurlencode($rd) . '/services/electric_charging_station';
            $abRaw = @file_get_contents($abUrl, false, $ctxAb);
            if (!is_string($abRaw) || $abRaw === '') {
                continue;
            }
            $abData = @json_decode($abRaw, true);
            $items  = $abData['electric_charging_station'] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $it) {
                $cLat = (float)($it['coordinate']['lat'] ?? 0.0);
                $cLng = (float)($it['coordinate']['long'] ?? 0.0);
                if ($cLat === 0.0 && $cLng === 0.0) {
                    continue;
                }
                $descLines = is_array($it['description'] ?? null) ? implode(' | ', $it['description']) : (string)($it['description'] ?? '');
                $maxKw = 150;
                if (preg_match_all('/(\d+)\s*kW/i', $descLines, $mKw) && !empty($mKw[1])) {
                    $maxKw = max(array_map('intval', $mKw[1]));
                }
                $autobahnEvList[] = [
                    'title'    => trim((string)($it['title'] ?? ($rd . ' Schnellladestation'))),
                    'operator' => 'Autobahn-Schnelllader (' . $rd . ')',
                    'address'  => trim((string)($it['subtitle'] ?? $rd)),
                    'lat'      => $cLat,
                    'lng'      => $cLng,
                    'max_kw'   => max(50, $maxKw),
                    'capacity' => 4,
                    'sockets'  => 'CCS / Typ 2 (' . max(50, $maxKw) . ' kW)',
                ];
            }
        }
        if (!empty($autobahnEvList)) {
            @file_put_contents($autobahnEvCache, json_encode($autobahnEvList, JSON_UNESCAPED_UNICODE));
        }
    }

    if (is_array($autobahnEvList)) {
        foreach ($autobahnEvList as $abSt) {
            $addStation($abSt);
        }
    }

    // 3. Ab Zoom >= 9 (oder kleinem Kartenausschnitt): Alle regionalen & städtischen Stromtankstellen via OpenStreetMap Overpass API abrufen
    $latSpan = abs($north - $south);
    $lngSpan = abs($east - $west);
    $sourceLabel = 'Bundesnetz & Autobahn-Ladesäulen';

    if ($zoom >= 9 && $latSpan <= 2.2 && $lngSpan <= 3.5) {
        $bboxCacheFile = sprintf(
            '%s/ev_bbox_v1_%.2f_%.2f_%.2f_%.2f.json',
            $evCacheDir,
            round($south, 2),
            round($west, 2),
            round($north, 2),
            round($east, 2)
        );

        $osmElements = null;
        if (is_file($bboxCacheFile) && (time() - (int)@filemtime($bboxCacheFile)) < 1800) {
            $decOsm = @json_decode((string)@file_get_contents($bboxCacheFile), true);
            if (is_array($decOsm)) {
                $osmElements = $decOsm;
                $sourceLabel = 'OpenStreetMap Ladesäulen-Register (Cache)';
            }
        }

        if ($osmElements === null) {
            $overpassQuery = sprintf(
                '[out:json][timeout:10];(node["amenity"="charging_station"](%.4f,%.4f,%.4f,%.4f);way["amenity"="charging_station"](%.4f,%.4f,%.4f,%.4f););out center 350;',
                $south,
                $west,
                $north,
                $east,
                $south,
                $west,
                $north,
                $east
            );

            $mirrors = [
                'https://overpass-api.de/api/interpreter',
                'https://overpass.kumi.systems/api/interpreter',
                'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
            ];

            foreach ($mirrors as $mirrorUrl) {
                $ctxOv = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'timeout' => 7.0,
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: MiniCloud-Maps-EVRadar/1.0\r\n",
                        'content' => 'data=' . rawurlencode($overpassQuery),
                    ],
                ]);
                $ovRaw = @file_get_contents($mirrorUrl, false, $ctxOv);
                if (is_string($ovRaw) && $ovRaw !== '') {
                    $ovJson = @json_decode($ovRaw, true);
                    if (is_array($ovJson['elements'] ?? null)) {
                        $osmElements = $ovJson['elements'];
                        $sourceLabel = 'OpenStreetMap Ladesäulen-Register (Live)';
                        @file_put_contents($bboxCacheFile, json_encode($osmElements, JSON_UNESCAPED_UNICODE));
                        break;
                    }
                }
            }
        }

        if (is_array($osmElements)) {
            foreach ($osmElements as $el) {
                $eLat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : 0.0);
                $eLng = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : 0.0);
                if ($eLat === 0.0 && $eLng === 0.0) {
                    continue;
                }
                $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];

                // Ladeleistung (kW) aus OSM-Tags ermitteln
                $maxKw = 22;
                $kwCandidates = [
                    $tags['socket:type2_combo:output'] ?? '',
                    $tags['socket:chademo:output'] ?? '',
                    $tags['socket:type2:output'] ?? '',
                    $tags['charging_station:output'] ?? '',
                    $tags['maxpower'] ?? '',
                    $tags['output'] ?? '',
                ];
                foreach ($kwCandidates as $cand) {
                    if ($cand !== '' && preg_match('/(\d+(?:[.,]\d+)?)/', (string)$cand, $m)) {
                        $val = (int)round((float)str_replace(',', '.', $m[1]));
                        if ($val > $maxKw && $val <= 1000) {
                            $maxKw = $val;
                        }
                    }
                }
                if (!empty($tags['socket:type2_combo']) && $maxKw < 50) {
                    $maxKw = 150;
                }

                // Steckertypen zusammenstellen
                $socketList = [];
                if (!empty($tags['socket:type2_combo'])) {
                    $socketList[] = 'CCS (' . $tags['socket:type2_combo'] . 'x)';
                }
                if (!empty($tags['socket:type2']) || !empty($tags['socket:type2_cable'])) {
                    $cnt2 = (int)($tags['socket:type2'] ?? $tags['socket:type2_cable'] ?? 1);
                    $socketList[] = 'Typ 2 (' . $cnt2 . 'x)';
                }
                if (!empty($tags['socket:chademo'])) {
                    $socketList[] = 'CHAdeMO';
                }
                if (empty($socketList)) {
                    $socketList[] = $maxKw >= 50 ? 'CCS / Typ 2' : 'Typ 2 (' . $maxKw . ' kW)';
                }

                $op   = trim((string)($tags['operator'] ?? $tags['brand'] ?? $tags['network'] ?? ''));
                $name = trim((string)($tags['name'] ?? ''));
                if ($name === '') {
                    $name = $op !== '' ? ('Ladesäule ' . $op) : 'Öffentliche Stromtankstelle';
                } elseif ($op !== '' && stripos($name, $op) === false) {
                    $name .= ' (' . $op . ')';
                }

                $street = trim(((string)($tags['addr:street'] ?? '')) . ' ' . ((string)($tags['addr:housenumber'] ?? '')));
                $city   = trim(((string)($tags['addr:postcode'] ?? '')) . ' ' . ((string)($tags['addr:city'] ?? '')));
                $addrStr = implode(', ', array_filter([$street, $city]));

                $addStation([
                    'title'         => $name,
                    'operator'      => $op,
                    'address'       => $addrStr,
                    'lat'           => $eLat,
                    'lng'           => $eLng,
                    'max_kw'        => $maxKw,
                    'capacity'      => max(1, (int)($tags['capacity'] ?? 2)),
                    'sockets'       => implode(', ', $socketList) . ' · max. ' . $maxKw . ' kW',
                    'opening_hours' => (string)($tags['opening_hours'] ?? '24/7'),
                ]);
            }
        }
    }

    echo json_encode([
        'ok'       => true,
        'zoom'     => $zoom,
        'source'   => $sourceLabel,
        'count'    => count($stations),
        'stations' => array_slice($stations, 0, 400),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5f) Sicherheits-Landkarte: Geo-IP-Herkunft aller Logins, Sitzungen & abgewehrten Login-Fehlversuche (?action=security_map_data)
if (isset($_GET['action']) && $_GET['action'] === 'security_map_data') {
    @session_write_close();
    header('Content-Type: application/json; charset=UTF-8');

    $canSeeAllSecurity = isAdmin() || (function_exists('hasAdminPermission') && hasAdminPermission('system'));

    // 1. Audit-Logs aus der Datenbank auslesen (Logins, Fehlversuche & sicherheitsrelevante Zugriffe)
    $ipBuckets = [];
    try {
        if ($canSeeAllSecurity) {
            $stmtSec = $pdo->query(
                "SELECT `username`, `event_type`, `description`, `ip_address`, `created_at`
                 FROM `audit_logs`
                 ORDER BY `id` DESC
                 LIMIT 600"
            );
        } else {
            $stmtSec = $pdo->prepare(
                "SELECT `username`, `event_type`, `description`, `ip_address`, `created_at`
                 FROM `audit_logs`
                 WHERE `user_id` = :uid OR `username` = :uname
                 ORDER BY `id` DESC
                 LIMIT 300"
            );
            $stmtSec->execute([
                ':uid'   => $userId,
                ':uname' => (string)($_SESSION['username'] ?? ''),
            ]);
        }

        foreach ($stmtSec->fetchAll() as $row) {
            $ipRaw = trim((string)($row['ip_address'] ?? ''));
            if ($ipRaw === '') {
                $ipRaw = 'lokal';
            }
            if (!isset($ipBuckets[$ipRaw])) {
                $ipBuckets[$ipRaw] = [
                    'masked_ip'     => $ipRaw,
                    'success_count' => 0,
                    'failed_count'  => 0,
                    'other_count'   => 0,
                    'users'         => [],
                    'first_seen'    => (string)$row['created_at'],
                    'last_seen'     => (string)$row['created_at'],
                    'events'        => [],
                ];
            }
            $etype = strtolower((string)$row['event_type']);
            if ($etype === 'login_success') {
                $ipBuckets[$ipRaw]['success_count']++;
            } elseif (str_contains($etype, 'fail') || str_contains($etype, 'block') || str_contains($etype, 'lock')) {
                $ipBuckets[$ipRaw]['failed_count']++;
            } else {
                $ipBuckets[$ipRaw]['other_count']++;
            }

            $uName = trim((string)($row['username'] ?? 'Gast'));
            if ($uName !== '' && !in_array($uName, $ipBuckets[$ipRaw]['users'], true)) {
                $ipBuckets[$ipRaw]['users'][] = $uName;
            }
            $ipBuckets[$ipRaw]['first_seen'] = (string)$row['created_at'];
            if (count($ipBuckets[$ipRaw]['events']) < 6) {
                $ipBuckets[$ipRaw]['events'][] = [
                    'type' => $etype,
                    'desc' => (string)$row['description'],
                    'user' => $uName,
                    'time' => date('d.m.Y H:i', strtotime((string)$row['created_at']) ?: time()),
                ];
            }
        }
    } catch (Throwable) {
    }

    // 2. Aktuelle Sitzungs-IP des eingeloggten Benutzers immer als Live-Sitzung einbinden
    $currentMaskedIp = getClientIp();
    if (!isset($ipBuckets[$currentMaskedIp])) {
        $ipBuckets[$currentMaskedIp] = [
            'masked_ip'     => $currentMaskedIp,
            'success_count' => 1,
            'failed_count'  => 0,
            'other_count'   => 0,
            'users'         => [(string)($_SESSION['username'] ?? 'Ich')],
            'first_seen'    => date('Y-m-d H:i:s'),
            'last_seen'     => date('Y-m-d H:i:s'),
            'events'        => [[
                'type' => 'login_success',
                'desc' => 'Aktive Cloud-Sitzung (aktuell verbunden)',
                'user' => (string)($_SESSION['username'] ?? 'Ich'),
                'time' => date('d.m.Y H:i'),
            ]],
        ];
    }

    // 3. Für Administratoren optional auch abgewehrte SSH-/Web-Bruteforce-IPs aus /var/log/auth.log einbeziehen (falls lesbar)
    if ($canSeeAllSecurity && is_readable('/var/log/auth.log')) {
        $authTail = @file_get_contents('/var/log/auth.log', false, null, max(0, (int)@filesize('/var/log/auth.log') - 65536));
        if (is_string($authTail) && $authTail !== '') {
            if (preg_match_all('/(?:Failed password|Invalid user).*?from\s+([0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', $authTail, $mIps)) {
                $countsByAuthIp = array_count_values($mIps[1]);
                arsort($countsByAuthIp);
                foreach (array_slice($countsByAuthIp, 0, 18, true) as $atkIp => $cnt) {
                    $parts = explode('.', (string)$atkIp);
                    $parts[3] = 'x';
                    $maskedAtk = implode('.', $parts);
                    if (!isset($ipBuckets[$maskedAtk])) {
                        $ipBuckets[$maskedAtk] = [
                            'masked_ip'     => $maskedAtk,
                            'success_count' => 0,
                            'failed_count'  => (int)$cnt,
                            'other_count'   => 0,
                            'users'         => ['root / SSH'],
                            'first_seen'    => date('Y-m-d H:i:s'),
                            'last_seen'     => date('Y-m-d H:i:s'),
                            'events'        => [[
                                'type' => 'login_failed',
                                'desc' => sprintf('%d abgewehrte Server-/SSH-Anmeldeversuche', (int)$cnt),
                                'user' => 'SSH-Guard',
                                'time' => date('d.m.Y H:i'),
                            ]],
                        ];
                    } else {
                        $ipBuckets[$maskedAtk]['failed_count'] += (int)$cnt;
                    }
                }
            }
        }
    }

    // 4. Maskierte IPs (z.B. 84.152.31.x -> 84.152.31.1) in auflösbare Subnetz-IPs umwandeln & lokal cachen
    $toProbeIp = static function (string $masked): ?string {
        if ($masked === 'lokal' || $masked === '127.0.0.1' || $masked === '::1') {
            return null;
        }
        $candidate = str_ends_with($masked, '.x')
            ? substr($masked, 0, -2) . '.1'
            : (str_ends_with($masked, '::x') ? substr($masked, 0, -3) . '::1' : $masked);
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return $candidate;
        }
        return null;
    };

    $geoCacheFile = $mapsBaseDir . '/geoip_cache.json';
    $geoCache = [];
    if (is_file($geoCacheFile)) {
        $loaded = @json_decode((string)@file_get_contents($geoCacheFile), true);
        if (is_array($loaded)) {
            $geoCache = $loaded;
        }
    }

    // Server-IP (runready.cloud) bestimmen
    $serverIp = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
    if ($serverIp === '' || filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        $resolvedHost = @gethostbyname('runready.cloud');
        if (is_string($resolvedHost) && filter_var($resolvedHost, FILTER_VALIDATE_IP) !== false) {
            $serverIp = $resolvedHost;
        }
    }

    $ipsToLookup = [];
    if ($serverIp !== '' && filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false && !isset($geoCache[$serverIp])) {
        $ipsToLookup[] = $serverIp;
    }
    foreach ($ipBuckets as $maskedKey => $b) {
        $probe = $toProbeIp($maskedKey);
        if ($probe !== null && !isset($geoCache[$probe])) {
            $ipsToLookup[] = $probe;
        }
    }
    $ipsToLookup = array_values(array_unique($ipsToLookup));

    if (!empty($ipsToLookup)) {
        $batchSlice = array_slice($ipsToLookup, 0, 80);
        $ctxGeo = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 5.0,
                'header'  => "Content-Type: application/json\r\nUser-Agent: MiniCloud-SecurityMap/1.0\r\n",
                'content' => json_encode($batchSlice),
            ],
        ]);
        $batchResp = @file_get_contents('http://ip-api.com/batch?fields=status,query,country,countryCode,regionName,city,lat,lon,isp,org&lang=de', false, $ctxGeo);
        if (is_string($batchResp) && $batchResp !== '') {
            $decodedBatch = @json_decode($batchResp, true);
            if (is_array($decodedBatch)) {
                foreach ($decodedBatch as $item) {
                    if (is_array($item) && ($item['status'] ?? '') === 'success' && !empty($item['query'])) {
                        $geoCache[(string)$item['query']] = [
                            'lat'         => (float)($item['lat'] ?? 51.1657),
                            'lng'         => (float)($item['lon'] ?? 10.4515),
                            'city'        => (string)($item['city'] ?? 'Unbekannt'),
                            'region'      => (string)($item['regionName'] ?? ''),
                            'country'     => (string)($item['country'] ?? 'Deutschland'),
                            'countryCode' => (string)($item['countryCode'] ?? 'DE'),
                            'isp'         => (string)($item['isp'] ?: ($item['org'] ?? 'Unbekannter Provider')),
                            'cached_at'   => time(),
                        ];
                    }
                }
                @file_put_contents($geoCacheFile, json_encode($geoCache, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    // Server-Standort für Verbindungsbögen vorbereiten
    $serverGeo = ($serverIp !== '' && isset($geoCache[$serverIp])) ? $geoCache[$serverIp] : [
        'lat'         => 50.1109,
        'lng'         => 8.6821,
        'city'        => 'Frankfurt / Rechenzentrum',
        'country'     => 'Deutschland',
        'countryCode' => 'DE',
        'isp'         => 'MiniCloud Host (runready.cloud)',
    ];

    $markers = [];
    $countriesSet = [];
    $totalSuccess = 0;
    $totalFailed  = 0;

    $idxOffset = 0;
    foreach ($ipBuckets as $maskedKey => $b) {
        $probe = $toProbeIp($maskedKey);
        if ($probe !== null && isset($geoCache[$probe])) {
            $g = $geoCache[$probe];
        } else {
            // Lokales Netzwerk / Intranet-Zugriff nah beim Server platzieren
            $g = [
                'lat'         => $serverGeo['lat'] + 0.06,
                'lng'         => $serverGeo['lng'] - 0.06,
                'city'        => 'Intranet / Lokales Netz',
                'region'      => 'Lokal',
                'country'     => 'Deutschland',
                'countryCode' => 'DE',
                'isp'         => 'Lokales Netzwerk / Direktzugriff',
            ];
        }

        // Kleiner Versatz falls mehrere Subnetze in exakt derselben Stadt landen, damit alle Pins anklickbar bleiben
        $jitterLat = $g['lat'] + (($idxOffset % 5) - 2) * 0.008;
        $jitterLng = $g['lng'] + ((int)floor($idxOffset / 5) % 5 - 2) * 0.008;
        $idxOffset++;

        $totalSuccess += (int)$b['success_count'];
        $totalFailed  += (int)$b['failed_count'];
        $countriesSet[$g['country']] = true;

        $status = 'info';
        if ($b['failed_count'] > 0 && $b['success_count'] === 0) {
            $status = 'danger';
        } elseif ($b['failed_count'] > 0 && $b['success_count'] > 0) {
            $status = 'mixed';
        } elseif ($b['success_count'] > 0) {
            $status = 'success';
        }

        $markers[] = [
            'masked_ip'     => $b['masked_ip'],
            'is_current'    => ($b['masked_ip'] === $currentMaskedIp),
            'status'        => $status,
            'lat'           => round($jitterLat, 5),
            'lng'           => round($jitterLng, 5),
            'city'          => $g['city'],
            'region'        => $g['region'] ?? '',
            'country'       => $g['country'],
            'country_code'  => $g['countryCode'],
            'isp'           => $g['isp'],
            'success_count' => (int)$b['success_count'],
            'failed_count'  => (int)$b['failed_count'],
            'other_count'   => (int)$b['other_count'],
            'users'         => $b['users'],
            'last_seen'     => date('d.m.Y H:i', strtotime($b['last_seen']) ?: time()),
            'events'        => $b['events'],
        ];
    }

    echo json_encode([
        'ok'       => true,
        'is_admin' => $canSeeAllSecurity,
        'server'   => [
            'host'    => (string)($_SERVER['HTTP_HOST'] ?? 'runready.cloud'),
            'lat'     => $serverGeo['lat'],
            'lng'     => $serverGeo['lng'],
            'city'    => $serverGeo['city'],
            'country' => $serverGeo['country'],
            'isp'     => $serverGeo['isp'],
        ],
        'stats' => [
            'unique_ips'    => count($markers),
            'countries'     => count($countriesSet),
            'total_success' => $totalSuccess,
            'total_failed'  => $totalFailed,
        ],
        'markers' => $markers,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// 6. AJAX & POST AKTIONEN (POIs, DETAILGRAD, OFFLINE-KACHEL-MANAGER, GPX, LAGEPLÄNE)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF).']);
            exit;
        }
        setFlash('error', 'Ungültige Anfrage (CSRF-Schutz).');
        header('Location: maps.php');
        exit;
    }

    // 6a) Detailgrad & Air-Gap-Modus dauerhaft speichern
    if ($postAction === 'save_map_config') {
        $newAirGap   = isset($_POST['airgap_only']) && $_POST['airgap_only'] === '1' ? '1' : '0';
        $newDetail   = max(1, min(5, (int)($_POST['default_detail'] ?? 4)));
        $newMaxZoom  = max(4, min(18, (int)($_POST['max_zoom_detail'] ?? 14)));
        saveAppSetting($pdo, 'maps_airgap_only', $newAirGap);
        saveAppSetting($pdo, 'maps_default_detail', (string)$newDetail);
        saveAppSetting($pdo, 'maps_max_zoom_detail', (string)$newMaxZoom);

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok'              => true,
                'airgap_only'     => $newAirGap === '1',
                'default_detail'  => $newDetail,
                'max_zoom_detail' => $newMaxZoom,
            ]);
            exit;
        }
        setFlash('success', 'Karteneinstellungen & Detailgrad gespeichert.');
        header('Location: maps.php');
        exit;
    }

    // 6b) Eigenen Ort / POI speichern oder aktualisieren
    if ($postAction === 'save_place') {
        $placeId = (int)($_POST['place_id'] ?? 0);
        $title   = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $cat     = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['category'] ?? 'favorite')) ?: 'favorite';
        $lat     = max(-90.0, min(90.0, (float)str_replace(',', '.', (string)($_POST['lat'] ?? '51.1657'))));
        $lng     = max(-180.0, min(180.0, (float)str_replace(',', '.', (string)($_POST['lng'] ?? '10.4515'))));
        $fpId    = !empty($_POST['floorplan_id']) ? (int)$_POST['floorplan_id'] : null;
        $address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
        $desc    = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
        $color   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#2563eb';
        $icon    = mb_substr(trim((string)($_POST['icon'] ?? '📍')), 0, 8) ?: '📍';
        $minLvl  = max(1, min(5, (int)($_POST['min_detail_level'] ?? 1)));
        $shared  = isset($_POST['is_shared']) && $_POST['is_shared'] === '1' ? 1 : 0;

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Namen für den Ort ein.');
            header('Location: maps.php');
            exit;
        }

        if ($placeId > 0) {
            $stmt = $pdo->prepare('
                UPDATE map_places
                   SET title = :title, category = :cat, lat = :lat, lng = :lng, floorplan_id = :fpid,
                       address = :addr, description = :descr, color = :col, icon = :ico,
                       min_detail_level = :minlvl, is_shared = :sh
                 WHERE id = :id AND (user_id = :uid OR :is_adm = 1)
            ');
            $stmt->execute([
                ':title'  => $title,
                ':cat'    => $cat,
                ':lat'    => $lat,
                ':lng'    => $lng,
                ':fpid'   => $fpId,
                ':addr'   => $address,
                ':descr'  => $desc,
                ':col'    => $color,
                ':ico'    => $icon,
                ':minlvl' => $minLvl,
                ':sh'     => $shared,
                ':id'     => $placeId,
                ':uid'    => $userId,
                ':is_adm' => $isAdminUser ? 1 : 0,
            ]);
            setFlash('success', 'Standort „' . $title . '“ wurde aktualisiert.');
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO map_places (user_id, title, category, lat, lng, floorplan_id, address, description, color, icon, min_detail_level, is_shared)
                VALUES (:uid, :title, :cat, :lat, :lng, :fpid, :addr, :descr, :col, :ico, :minlvl, :sh)
            ');
            $stmt->execute([
                ':uid'    => $userId,
                ':title'  => $title,
                ':cat'    => $cat,
                ':lat'    => $lat,
                ':lng'    => $lng,
                ':fpid'   => $fpId,
                ':addr'   => $address,
                ':descr'  => $desc,
                ':col'    => $color,
                ':ico'    => $icon,
                ':minlvl' => $minLvl,
                ':sh'     => $shared,
            ]);
            setFlash('success', 'Neuer Ort „' . $title . '“ auf der Karte gespeichert.');
        }
        header('Location: maps.php' . ($fpId ? '?floorplan=' . $fpId : ''));
        exit;
    }

    // 6c) Eigenen Ort löschen
    if ($postAction === 'delete_place') {
        $placeId = (int)($_POST['place_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM map_places WHERE id = :id AND (user_id = :uid OR :is_adm = 1)');
        $stmt->execute([':id' => $placeId, ':uid' => $userId, ':is_adm' => $isAdminUser ? 1 : 0]);
        setFlash('success', 'Standort wurde entfernt.');
        header('Location: maps.php');
        exit;
    }

    // 6d) GPX / KML / GeoJSON Route hochladen oder aus Cloud-Datei importieren
    if ($postAction === 'upload_track') {
        $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#10b981';
        $shared = isset($_POST['is_shared']) && $_POST['is_shared'] === '1' ? 1 : 0;
        $cloudFileId = (int)($_POST['cloud_file_id'] ?? 0);

        $rawContent = '';
        $ext = 'gpx';

        if ($cloudFileId > 0) {
            $stmtF = $pdo->prepare('SELECT original_name, stored_name FROM files WHERE id = :id AND user_id = :uid AND deleted_at IS NULL LIMIT 1');
            $stmtF->execute([':id' => $cloudFileId, ':uid' => $userId]);
            $fRow = $stmtF->fetch();
            if ($fRow) {
                $fullPath = __DIR__ . '/uploads/' . basename((string)$fRow['stored_name']);
                if (is_file($fullPath) && filesize($fullPath) <= 15 * 1024 * 1024) {
                    $rawContent = (string)@file_get_contents($fullPath);
                    $ext = strtolower((string)pathinfo((string)$fRow['original_name'], PATHINFO_EXTENSION));
                    if ($title === '') {
                        $title = (string)pathinfo((string)$fRow['original_name'], PATHINFO_FILENAME);
                    }
                }
            }
        } elseif (!empty($_FILES['track_file']['tmp_name']) && is_uploaded_file($_FILES['track_file']['tmp_name'])) {
            $origName = (string)($_FILES['track_file']['name'] ?? 'route.gpx');
            $ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, ['gpx', 'kml', 'geojson', 'json'], true) && (int)$_FILES['track_file']['size'] <= 15 * 1024 * 1024) {
                $rawContent = (string)@file_get_contents($_FILES['track_file']['tmp_name']);
                if ($title === '') {
                    $title = (string)pathinfo($origName, PATHINFO_FILENAME);
                }
            }
        }

        if ($rawContent === '') {
            setFlash('error', 'Keine gültige GPX-, KML- oder GeoJSON-Datei ausgewählt.');
            header('Location: maps.php');
            exit;
        }

        $parsed = parseTrackContent($rawContent, $ext);
        if (count($parsed['points']) < 2) {
            setFlash('error', 'In der Datei wurden keine verwertbaren GPS-Wegpunkte gefunden.');
            header('Location: maps.php');
            exit;
        }

        $stmt = $pdo->prepare('
            INSERT INTO map_tracks (user_id, title, track_type, color, distance_km, ascent_m, descent_m, points_json, is_shared)
            VALUES (:uid, :title, :type, :col, :dist, :asc, :desc, :pts, :sh)
        ');
        $stmt->execute([
            ':uid'   => $userId,
            ':title' => $title !== '' ? $title : 'GPS-Tour',
            ':type'  => $ext,
            ':col'   => $color,
            ':dist'  => $parsed['distance_km'],
            ':asc'   => $parsed['ascent_m'],
            ':desc'  => $parsed['descent_m'],
            ':pts'   => json_encode($parsed['points'], JSON_UNESCAPED_UNICODE),
            ':sh'    => $shared,
        ]);
        setFlash('success', sprintf('Tour „%s“ (%s km, +%d hm) erfolgreich geladen.', $title ?: 'GPS-Tour', number_format($parsed['distance_km'], 2, ',', '.'), $parsed['ascent_m']));
        header('Location: maps.php');
        exit;
    }

    // 6e) GPX-Route löschen
    if ($postAction === 'delete_track') {
        $trackId = (int)($_POST['track_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM map_tracks WHERE id = :id AND (user_id = :uid OR :is_adm = 1)');
        $stmt->execute([':id' => $trackId, ':uid' => $userId, ':is_adm' => $isAdminUser ? 1 : 0]);
        setFlash('success', 'GPS-Tour wurde gelöscht.');
        header('Location: maps.php');
        exit;
    }

    // 6f) Eigenen Intranet-Lageplan / Gebäude-Grundriss hochladen
    if ($postAction === 'upload_floorplan') {
        $title    = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $building = mb_substr(trim((string)($_POST['building_name'] ?? '')), 0, 120);
        $floor    = mb_substr(trim((string)($_POST['floor_label'] ?? 'EG')), 0, 40);
        $scaleM   = max(5.0, min(10000.0, (float)str_replace(',', '.', (string)($_POST['scale_meters'] ?? '100'))));
        $shared   = isset($_POST['is_shared']) && $_POST['is_shared'] === '1' ? 1 : 0;

        if (!empty($_FILES['floorplan_file']['tmp_name']) && is_uploaded_file($_FILES['floorplan_file']['tmp_name'])) {
            $tmpFile = $_FILES['floorplan_file']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmpFile);
            $allowedMimes = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];
            if (!isset($allowedMimes[$mime])) {
                setFlash('error', 'Bitte lade den Lageplan als PNG-, JPG- oder WebP-Bild hoch.');
                header('Location: maps.php');
                exit;
            }
            $imgInfo = @getimagesize($tmpFile);
            $wPx = isset($imgInfo[0]) && $imgInfo[0] > 0 ? (int)$imgInfo[0] : 1600;
            $hPx = isset($imgInfo[1]) && $imgInfo[1] > 0 ? (int)$imgInfo[1] : 1000;
            $storedName = 'fp_' . bin2hex(random_bytes(8)) . '.' . $allowedMimes[$mime];
            if (@move_uploaded_file($tmpFile, $floorplansDir . '/' . $storedName)) {
                if ($title === '') {
                    $title = (string)pathinfo((string)($_FILES['floorplan_file']['name'] ?? 'Lageplan'), PATHINFO_FILENAME);
                }
                $stmt = $pdo->prepare('
                    INSERT INTO map_floorplans (user_id, title, building_name, floor_label, stored_filename, mime_type, width_px, height_px, scale_meters, is_shared)
                    VALUES (:uid, :title, :bld, :flr, :fn, :mime, :w, :h, :sc, :sh)
                ');
                $stmt->execute([
                    ':uid'   => $userId,
                    ':title' => $title,
                    ':bld'   => $building,
                    ':flr'   => $floor,
                    ':fn'    => $storedName,
                    ':mime'  => $mime,
                    ':w'     => $wPx,
                    ':h'     => $hPx,
                    ':sc'    => $scaleM,
                    ':sh'    => $shared,
                ]);
                $newFpId = (int)$pdo->lastInsertId();
                setFlash('success', 'Intranet-Lageplan „' . $title . '“ wurde angelegt.');
                header('Location: maps.php?floorplan=' . $newFpId);
                exit;
            }
        }
        setFlash('error', 'Lageplan konnte nicht hochgeladen werden.');
        header('Location: maps.php');
        exit;
    }

    // 6g) Lageplan löschen
    if ($postAction === 'delete_floorplan') {
        $fpId = (int)($_POST['floorplan_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT stored_filename FROM map_floorplans WHERE id = :id AND (user_id = :uid OR :is_adm = 1) LIMIT 1');
        $stmt->execute([':id' => $fpId, ':uid' => $userId, ':is_adm' => $isAdminUser ? 1 : 0]);
        $fpRow = $stmt->fetch();
        if ($fpRow) {
            $fpPath = $floorplansDir . '/' . basename((string)$fpRow['stored_filename']);
            if (is_file($fpPath)) {
                @unlink($fpPath);
            }
            $pdo->prepare('DELETE FROM map_floorplans WHERE id = :id')->execute([':id' => $fpId]);
            $pdo->prepare('DELETE FROM map_places WHERE floorplan_id = :id')->execute([':id' => $fpId]);
            setFlash('success', 'Lageplan wurde gelöscht.');
        }
        header('Location: maps.php');
        exit;
    }

    // 6h) AJAX: Offline-Kacheln für aktuellen Kartenausschnitt & gewählten Detailgrad (Zoomstufe) vorab auf Server laden
    if ($postAction === 'prefetch_offline_tiles') {
        header('Content-Type: application/json; charset=UTF-8');
        $style = in_array((string)($_POST['style'] ?? 'osm'), ['osm', 'topo'], true) ? (string)$_POST['style'] : 'osm';
        $zoom  = max(4, min(17, (int)($_POST['zoom'] ?? 10)));
        $n     = 1 << $zoom;

        $latMin = max(-85.0, min(85.0, (float)($_POST['lat_min'] ?? 47.2)));
        $latMax = max(-85.0, min(85.0, (float)($_POST['lat_max'] ?? 55.0)));
        $lngMin = max(-180.0, min(180.0, (float)($_POST['lng_min'] ?? 5.8)));
        $lngMax = max(-180.0, min(180.0, (float)($_POST['lng_max'] ?? 15.1)));

        $lonToX = static fn(float $lon, int $nTiles): int => (int)floor((($lon + 180.0) / 360.0) * $nTiles);
        $latToY = static function (float $lat, int $nTiles): int {
            $rad = deg2rad($lat);
            return (int)floor((1.0 - log(tan($rad) + 1.0 / cos($rad)) / M_PI) / 2.0 * $nTiles);
        };

        $xStart = max(0, min($n - 1, $lonToX(min($lngMin, $lngMax), $n)));
        $xEnd   = max(0, min($n - 1, $lonToX(max($lngMin, $lngMax), $n)));
        $yStart = max(0, min($n - 1, $latToY(max($latMin, $latMax), $n)));
        $yEnd   = max(0, min($n - 1, $latToY(min($latMin, $latMax), $n)));

        $fetched = 0;
        $alreadyCached = 0;
        $maxBatch = 36; // Pro Anfrage max. 36 Kacheln laden, damit kein Timeout entsteht
        $count = 0;

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 2.5,
                'header'  => "User-Agent: MiniCloud-Intranet-TileManager/1.0\r\nAccept: image/png\r\n",
            ],
        ]);

        for ($x = $xStart; $x <= $xEnd; $x++) {
            for ($y = $yStart; $y <= $yEnd; $y++) {
                $count++;
                $tilePath = sprintf('%s/%s/%d/%d/%d.png', $tilesCacheDir, $style, $zoom, $x, $y);
                if (is_file($tilePath) && filesize($tilePath) > 64) {
                    $alreadyCached++;
                    continue;
                }
                if ($fetched >= $maxBatch) {
                    break 2;
                }
                $remoteUrl = $style === 'topo'
                    ? sprintf('https://a.tile.opentopomap.org/%d/%d/%d.png', $zoom, $x, $y)
                    : sprintf('https://tile.openstreetmap.org/%d/%d/%d.png', $zoom, $x, $y);
                $img = @file_get_contents($remoteUrl, false, $ctx);
                if (is_string($img) && strlen($img) > 100 && str_starts_with($img, "\x89PNG")) {
                    $tDir = dirname($tilePath);
                    if (!is_dir($tDir)) {
                        @mkdir($tDir, 0750, true);
                    }
                    @file_put_contents($tilePath, $img, LOCK_EX);
                    $fetched++;
                }
            }
        }

        // Höchste geladene Detailstufe in den Einstellungen aktualisieren, falls höher
        if ($zoom > $maxCachedZoomLevel && ($fetched > 0 || $alreadyCached > 0)) {
            saveAppSetting($pdo, 'maps_max_zoom_detail', (string)$zoom);
        }

        echo json_encode([
            'ok'             => true,
            'zoom'           => $zoom,
            'fetched'        => $fetched,
            'already_cached' => $alreadyCached,
            'total_in_box'   => ($xEnd - $xStart + 1) * ($yEnd - $yStart + 1),
        ]);
        exit;
    }
}

// =========================================================================
// 7. DATEN FÜR DIE KARTE LADEN (POIs, TOUREN, LAGEPLÄNE, FOTOS, KONTAKTE, TERMINE)
// =========================================================================
$activeFloorplanId = isset($_GET['floorplan']) ? max(0, (int)$_GET['floorplan']) : 0;

// 7a) Intranet-Lagepläne laden
$floorplans = [];
try {
    $stmtFpList = $pdo->prepare('SELECT * FROM map_floorplans WHERE user_id = :uid OR is_shared = 1 ORDER BY building_name ASC, floor_label ASC, id DESC');
    $stmtFpList->execute([':uid' => $userId]);
    $floorplans = $stmtFpList->fetchAll();
} catch (Throwable) {
    $floorplans = [];
}

$activeFloorplan = null;
foreach ($floorplans as $fpItem) {
    if ((int)$fpItem['id'] === $activeFloorplanId) {
        $activeFloorplan = $fpItem;
        break;
    }
}
if ($activeFloorplan === null) {
    $activeFloorplanId = 0;
}

// 7b) Gespeicherte Orte / POIs laden
$places = [];
try {
    if ($activeFloorplanId > 0) {
        $stmtPl = $pdo->prepare('SELECT * FROM map_places WHERE floorplan_id = :fpid AND (user_id = :uid OR is_shared = 1) ORDER BY title ASC');
        $stmtPl->execute([':fpid' => $activeFloorplanId, ':uid' => $userId]);
    } else {
        $stmtPl = $pdo->prepare('SELECT * FROM map_places WHERE (floorplan_id IS NULL OR floorplan_id = 0) AND (user_id = :uid OR is_shared = 1) ORDER BY title ASC');
        $stmtPl->execute([':uid' => $userId]);
    }
    $places = $stmtPl->fetchAll();
} catch (Throwable) {
    $places = [];
}

// 7c) GPX-/GeoJSON-Touren laden + verfügbare GPX/KML/GeoJSON-Dateien aus der Cloud
$tracks = [];
try {
    $stmtTr = $pdo->prepare('SELECT * FROM map_tracks WHERE user_id = :uid OR is_shared = 1 ORDER BY created_at DESC');
    $stmtTr->execute([':uid' => $userId]);
    $tracks = $stmtTr->fetchAll();
} catch (Throwable) {
    $tracks = [];
}

$cloudTrackFiles = [];
try {
    $stmtCf = $pdo->prepare("
        SELECT id, original_name, file_size
          FROM files
         WHERE user_id = :uid
           AND deleted_at IS NULL
           AND (LOWER(original_name) LIKE '%.gpx' OR LOWER(original_name) LIKE '%.kml' OR LOWER(original_name) LIKE '%.geojson')
         ORDER BY uploaded_at DESC
         LIMIT 50
    ");
    $stmtCf->execute([':uid' => $userId]);
    $cloudTrackFiles = $stmtCf->fetchAll();
} catch (Throwable) {
    $cloudTrackFiles = [];
}

// 7d) Kontakte & Kalender-Orte lokal über das Offline-Ortsverzeichnis geokodieren (100 % Offline!)
$gazetteer = getOfflineGazetteer();
$matchCityCoords = static function (string $text) use ($gazetteer): ?array {
    $textTrim = trim($text);
    if ($textTrim === '') {
        return null;
    }
    $textLower = mb_strtolower($textTrim);
    foreach ($gazetteer as $city) {
        $cName = mb_strtolower($city['name']);
        if (($city['zip'] !== '' && str_contains($textLower, strtolower($city['zip']))) || str_contains($textLower, $cName)) {
            return ['lat' => $city['lat'], 'lng' => $city['lng'], 'city' => $city['name']];
        }
    }
    return null;
};

$contactPins = [];
try {
    $stmtCt = $pdo->prepare('SELECT id, name, company, address, phone, email FROM contacts WHERE user_id = :uid AND address IS NOT NULL AND address != "" LIMIT 120');
    $stmtCt->execute([':uid' => $userId]);
    foreach ($stmtCt->fetchAll() as $ct) {
        $matched = $matchCityCoords((string)$ct['address']);
        if ($matched !== null) {
            // Leichter deterministischer Versatz bei mehreren Kontakten in derselben Stadt
            $hashOffsetLat = ((crc32('lat_' . $ct['id']) % 200) - 100) * 0.00018;
            $hashOffsetLng = ((crc32('lng_' . $ct['id']) % 200) - 100) * 0.00025;
            $contactPins[] = [
                'id'      => (int)$ct['id'],
                'name'    => (string)$ct['name'],
                'company' => (string)($ct['company'] ?? ''),
                'address' => (string)$ct['address'],
                'phone'   => (string)($ct['phone'] ?? ''),
                'lat'     => round($matched['lat'] + $hashOffsetLat, 6),
                'lng'     => round($matched['lng'] + $hashOffsetLng, 6),
            ];
        }
    }
} catch (Throwable) {
    $contactPins = [];
}

$calendarPins = [];
try {
    $stmtEv = $pdo->prepare('
        SELECT id, title, event_date, event_time, location
          FROM calendar_events
         WHERE user_id = :uid
           AND location IS NOT NULL
           AND location != ""
           AND event_date >= CURDATE()
         ORDER BY event_date ASC
         LIMIT 60
    ');
    $stmtEv->execute([':uid' => $userId]);
    foreach ($stmtEv->fetchAll() as $ev) {
        $matched = $matchCityCoords((string)$ev['location']);
        if ($matched !== null) {
            $calendarPins[] = [
                'id'       => (int)$ev['id'],
                'title'    => (string)$ev['title'],
                'date'     => (string)$ev['event_date'],
                'time'     => substr((string)($ev['event_time'] ?? ''), 0, 5),
                'location' => (string)$ev['location'],
                'lat'      => $matched['lat'],
                'lng'      => $matched['lng'],
            ];
        }
    }
} catch (Throwable) {
    $calendarPins = [];
}

// 7e) Fotos mit EXIF-GPS-Koordinaten aus den eigenen Cloud-Bildern auslesen
$photoPins = [];
if (function_exists('exif_read_data')) {
    try {
        $stmtImg = $pdo->prepare("
            SELECT id, original_name, stored_name, uploaded_at
              FROM files
             WHERE user_id = :uid
               AND deleted_at IS NULL
               AND is_encrypted = 0
               AND mime_type IN ('image/jpeg', 'image/jpg')
             ORDER BY uploaded_at DESC
             LIMIT 80
        ");
        $stmtImg->execute([':uid' => $userId]);
        $exifGpsToFloat = static function ($coord, string $hemisphere): ?float {
            if (!is_array($coord) || count($coord) < 3) {
                return null;
            }
            $toFloat = static function ($part): float {
                $p = explode('/', (string)$part);
                if (count($p) === 2 && (float)$p[1] != 0.0) {
                    return (float)$p[0] / (float)$p[1];
                }
                return (float)$part;
            };
            $deg = $toFloat($coord[0]);
            $min = $toFloat($coord[1]);
            $sec = $toFloat($coord[2]);
            $val = $deg + ($min / 60.0) + ($sec / 3600.0);
            return in_array(strtoupper($hemisphere), ['S', 'W'], true) ? -$val : $val;
        };

        foreach ($stmtImg->fetchAll() as $imgRow) {
            $imgPath = __DIR__ . '/uploads/' . basename((string)$imgRow['stored_name']);
            if (!is_file($imgPath)) {
                continue;
            }
            $exif = @exif_read_data($imgPath, 'GPS', true);
            if (is_array($exif) && isset($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLongitude'])) {
                $lat = $exifGpsToFloat($exif['GPS']['GPSLatitude'], (string)($exif['GPS']['GPSLatitudeRef'] ?? 'N'));
                $lng = $exifGpsToFloat($exif['GPS']['GPSLongitude'], (string)($exif['GPS']['GPSLongitudeRef'] ?? 'E'));
                if ($lat !== null && $lng !== null && ($lat != 0.0 || $lng != 0.0)) {
                    $photoPins[] = [
                        'id'    => (int)$imgRow['id'],
                        'name'  => (string)$imgRow['original_name'],
                        'date'  => substr((string)$imgRow['uploaded_at'], 0, 10),
                        'lat'   => round($lat, 6),
                        'lng'   => round($lng, 6),
                        'thumb' => 'thumbnail.php?id=' . (int)$imgRow['id'],
                    ];
                }
            }
        }
    } catch (Throwable) {
        $photoPins = [];
    }
}

// 7f) Statistik über lokal gespeicherte Offline-Kacheln & .mbtiles Pakete ermitteln
$mbtilesPackages = [];
foreach (glob($mapsBaseDir . '/*.mbtiles') ?: [] as $mbFile) {
    $mbtilesPackages[] = [
        'name' => basename($mbFile),
        'size' => formatBytes((int)@filesize($mbFile)),
    ];
}

$cachedTileCount = 0;
$cachedZoomLevelsFound = [];
foreach (['osm', 'topo'] as $stKey) {
    $stDir = $tilesCacheDir . '/' . $stKey;
    if (is_dir($stDir)) {
        foreach (scandir($stDir) ?: [] as $zDir) {
            if ($zDir === '.' || $zDir === '..' || !ctype_digit($zDir)) {
                continue;
            }
            $cachedZoomLevelsFound[(int)$zDir] = true;
            $subFiles = glob($stDir . '/' . $zDir . '/*/*.png') ?: [];
            $cachedTileCount += count($subFiles);
        }
    }
}
ksort($cachedZoomLevelsFound);
$cachedZoomRangeStr = !empty($cachedZoomLevelsFound)
    ? ('Stufe ' . min(array_keys($cachedZoomLevelsFound)) . '–' . max(array_keys($cachedZoomLevelsFound)))
    : 'Basis-Vektorkarte (Stufe 1–18)';

$categoriesConfig = [
    'favorite'  => ['label' => 'Favorit / Merkliste',   'icon' => '⭐', 'color' => '#eab308'],
    'office'    => ['label' => 'Standort / Dienststelle','icon' => '🏢', 'color' => '#2563eb'],
    'infra'     => ['label' => 'IT / Technik / Server',  'icon' => '🖥️', 'color' => '#6366f1'],
    'emergency' => ['label' => 'Notfall / Sammelpunkt',  'icon' => '🚨', 'color' => '#dc2626'],
    'club'      => ['label' => 'Verein / Treffpunkt',    'icon' => '🏛️', 'color' => '#059669'],
    'parking'   => ['label' => 'Parkplatz / Zufahrt',    'icon' => '🅿️', 'color' => '#0284c7'],
    'food'      => ['label' => 'Gastronomie / Pause',    'icon' => '🍴', 'color' => '#ea580c'],
    'outdoor'   => ['label' => 'Natur / Ausflugsziel',   'icon' => '🏕️', 'color' => '#16a34a'],
];

$hasGermanyPmtiles = is_file(__DIR__ . '/assets/maps/germany_detail.pmtiles') || is_file($mapsBaseDir . '/tiles/germany_detail.pmtiles');

$pageTitle = 'Karten & Intranet-Atlas';
require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/leaflet/protomaps-leaflet.js"></script>

<style>
    #mcMapCanvas {
        height: calc(100vh - 230px);
        min-height: 540px;
        width: 100%;
        border-radius: 1rem;
        z-index: 10;
        background: #dbe8f4;
    }
    html.dark #mcMapCanvas {
        background: #0f172a;
    }
    .mc-map-pin {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 9999px;
        border: 2.5px solid #ffffff;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.32);
        font-size: 15px;
        transition: transform 0.15s ease;
    }
    .mc-map-pin:hover {
        transform: scale(1.18);
    }
    .mc-city-label {
        background: rgba(15, 23, 42, 0.82);
        color: #f8fafc;
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 6px;
        padding: 1px 6px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 2px 6px rgba(0,0,0,0.22);
        pointer-events: auto;
    }
    .mc-state-label {
        color: #334155;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        text-shadow: 0 0 4px rgba(255,255,255,0.9), 0 0 8px rgba(255,255,255,0.9);
        pointer-events: none;
        white-space: nowrap;
    }
    html.dark .mc-state-label {
        color: #94a3b8;
        text-shadow: 0 0 4px rgba(15,23,42,0.95), 0 0 8px rgba(15,23,42,0.95);
    }
</style>

<div class="space-y-4">
    <!-- KOPFLEISTE: TITEL, AIR-GAP-STATUS, DETAILGRAD-REGLER & SCHNELLAKTIONEN -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-xs">
        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3">
            <div class="flex items-start sm:items-center gap-3">
                <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white flex items-center justify-center text-2xl shadow-xs shrink-0">
                    🗺️
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Karten, Offline-Atlas &amp; Intranet-Lagepläne
                        </h1>
                        <button
                            type="button"
                            id="airGapStatusBadge"
                            onclick="toggleAirGapModeLive()"
                            title="Klicken, um zwischen 100 % abgeschottetem Intranet-Modus (Null Außenverkehr) und Server-Tile-Caching umzuschalten"
                            class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold cursor-pointer transition-colors <?= $airGapMode ? 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800' : 'bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-sky-300 border border-sky-300 dark:border-sky-800' ?>"
                        >
                            <span id="airGapBadgeIcon"><?= $airGapMode ? '🔒' : '🛡️' ?></span>
                            <span id="airGapBadgeText"><?= $airGapMode ? '100 % Intranet Air-Gapped (Null Außenverkehr)' : 'Lokaler Tile-Cache + Server-Proxy' ?></span>
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Autarke Offline-Vektorkarte, nachträglich änderbarer Detailgrad (Stufe 1–5 / Zoom 1–18), <code class="font-mono">.mbtiles</code>-Support, GPX-Touren &amp; eigene Gebäude-/Geländepläne.
                    </p>
                </div>
            </div>

            <!-- RECHTE AKTIONEN & LIVE-DETAILGRAD-STEUERUNG -->
            <div class="flex flex-wrap items-center gap-2">
                <!-- Live Detailgrad-Regler (Stufe 1 bis 5) -->
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80">
                    <span class="text-xs font-bold text-slate-700 dark:text-slate-200 whitespace-nowrap" title="Steuert sofort die angezeigte Informationsdichte (Städte, Straßen, Grenzen, POIs & Koordinatennetz)">
                        🔍 Detailgrad:
                    </span>
                    <input
                        type="range"
                        id="mapDetailSlider"
                        min="1"
                        max="5"
                        step="1"
                        value="<?= (int)$defaultDetailLevel ?>"
                        oninput="onDetailLevelChange(this.value)"
                        class="w-24 accent-brand-600 cursor-pointer"
                    >
                    <span id="mapDetailBadge" class="px-2 py-0.5 rounded-lg bg-brand-600 text-white text-[11px] font-bold min-w-[72px] text-center">
                        Stufe <?= (int)$defaultDetailLevel ?> / 5
                    </span>
                </div>

                <button
                    type="button"
                    onclick="openMapModal('offlineDetailModal')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/80 hover:bg-indigo-100 transition-colors cursor-pointer"
                    title="Detailgrad der Offline-Kartenkacheln nachträglich ändern oder Regionen für das Intranet speichern"
                >
                    <span>📦</span>
                    <span>Detailgrad &amp; Offline-Pakete</span>
                </button>

                <button
                    type="button"
                    onclick="openNewPlaceModal()"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold bg-brand-600 hover:bg-brand-700 text-white shadow-2xs transition-colors cursor-pointer"
                >
                    <span>📍</span>
                    <span>Ort / Pin setzen</span>
                </button>

                <button
                    type="button"
                    onclick="openMapModal('trackUploadModal')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                >
                    <span>🥾</span>
                    <span>GPX / Route</span>
                </button>

                <button
                    type="button"
                    onclick="openMapModal('floorplanModal')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                >
                    <span>🏢</span>
                    <span>Lageplan / Gebäude</span>
                </button>
            </div>
        </div>

        <!-- ZWEITE ZEILE: MODUS-WAHL (ATLAS VS. LAGEPLAN), OFFLINE-SUCHE, KARTEN-STIL & WERKZEUGE -->
        <div class="mt-3 pt-3 border-t border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center justify-between gap-2.5">
            <!-- Links: Umschalter Atlas vs. Intranet-Lagepläne + Offline-Ortssuche -->
            <div class="flex flex-wrap items-center gap-2 flex-1 min-w-[280px]">
                <div class="inline-flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200/70 dark:border-slate-700/70">
                    <a
                        href="maps.php"
                        class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors <?= $activeFloorplanId === 0 ? 'bg-brand-600 text-white shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>"
                    >
                        🌍 Welt- &amp; Deutschland-Atlas
                    </a>
                    <?php foreach ($floorplans as $fpNav): ?>
                        <a
                            href="maps.php?floorplan=<?= (int)$fpNav['id'] ?>"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors <?= $activeFloorplanId === (int)$fpNav['id'] ? 'bg-brand-600 text-white shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white' ?>"
                        >
                            🏢 <?= e((string)$fpNav['title']) ?> (<?= e((string)$fpNav['floor_label']) ?>)
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- 100 % Lokale Offline-Suche (Städte, PLZ, eigene POIs, Kontakte, Koordinaten) -->
                <div class="relative flex-1 max-w-md min-w-[220px]">
                    <input
                        type="text"
                        id="offlineMapSearchInput"
                        oninput="onOfflineMapSearch(this.value)"
                        placeholder="🔎 Offline-Suche: Stadt, PLZ, eigener Ort, Kontakt oder 51.16, 10.45…"
                        class="w-full pl-3 pr-8 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <div
                        id="offlineMapSearchResults"
                        class="hidden absolute left-0 right-0 top-full mt-1 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl z-50 max-h-72 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800"
                    ></div>
                </div>
            </div>

            <!-- Rechts: Kartenstil, Schnell-Regionen & Messwerkzeuge -->
            <div class="flex flex-wrap items-center gap-1.5">
                <?php if ($activeFloorplanId === 0): ?>
                    <select
                        id="mapBaseStyleSelect"
                        onchange="switchMapBaseStyle(this.value)"
                        class="px-2.5 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold cursor-pointer"
                        title="Kartendarstellung wählen"
                    >
                        <option value="intranet_vector">🗺️ Intranet-Vektor-Atlas (100 % Offline)</option>
                        <option value="osm">🏙️ Straßenkarte (Lokaler Cache / .mbtiles)</option>
                        <option value="topo">⛰️ Topografie &amp; Gelände (Cache / .mbtiles)</option>
                        <option value="intranet_dark">🌙 Nacht- / Leitstellen-Karte (Offline)</option>
                    </select>

                    <select
                         onchange="flyToQuickRegion(this.value); this.selectedIndex=0;"
                        class="px-2.5 py-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-semibold cursor-pointer"
                    >
                        <option value="">🎯 Schnell-Sprung…</option>
                        <option value="51.1657,10.4515,6">🇩🇪 Ganz Deutschland</option>
                        <option value="51.45,7.45,8">🏭 Nordrhein-Westfalen</option>
                        <option value="48.66,9.35,8">🦁 Baden-Württemberg</option>
                        <option value="48.79,11.49,8">🏔️ Bayern</option>
                        <option value="52.52,13.405,10">🐻 Berlin / Brandenburg</option>
                        <option value="53.55,9.99,10">⚓ Hamburg &amp; Norden</option>
                        <option value="50.11,8.68,9">🏦 Rhein-Main / Hessen</option>
                        <option value="48.20,16.37,7">🇦🇹 Österreich &amp; Alpen</option>
                        <option value="46.81,8.22,8">🇨🇭 Schweiz</option>
                        <option value="50.0,10.0,4">🌍 ganz Europa</option>
                    </select>

                    <button
                        type="button"
                        id="btnRainRadar"
                        onclick="toggleRainRadar()"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-sky-50 dark:bg-sky-950/70 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800/80 transition-colors cursor-pointer"
                        title="Live-Regenradar &amp; Wetter-Overlay mit 2-Stunden-Rückblick &amp; 30-Min.-Prognose einblenden"
                    >
                        <span>⛈️</span>
                        <span id="btnRainRadarLabel">Regenradar</span>
                    </button>

                    <button
                        type="button"
                        id="btnEvCharging"
                        onclick="toggleEvChargingLayer()"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-purple-50 dark:bg-purple-950/70 hover:bg-purple-100 dark:hover:bg-purple-900/70 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/80 transition-colors cursor-pointer"
                        title="Alle Stromtankstellen &amp; E-Ladesäulen (HPC Ultra-Schnelllader, DC-Schnelllader &amp; AC-Typ-2) auf der Karte anzeigen"
                    >
                        <span>⚡</span>
                        <span id="btnEvChargingLabel">Stromtankstellen</span>
                    </button>

                    <button
                        type="button"
                        id="btnPoiFinder"
                        onclick="openRadiusFinderPanel()"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-emerald-50 dark:bg-emerald-950/70 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80 transition-colors cursor-pointer"
                        title="Umkreis-Finder: Tankstellen, E-Ladesäulen, Parkplätze, Geldautomaten, Apotheken &amp; Restaurants im Umkreis suchen"
                    >
                        <span>⛽</span>
                        <span>Umkreis-Finder</span>
                    </button>

                    <button
                        type="button"
                        id="btnSecurityMap"
                        onclick="openSecurityMapPanel()"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-rose-50 dark:bg-rose-950/70 hover:bg-rose-100 dark:hover:bg-rose-900/70 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/80 transition-colors cursor-pointer"
                        title="Sicherheits-Landkarte: Geo-IP-Herkunft aller Logins, Sitzungen &amp; abgewehrten Fehlversuche visualisieren"
                    >
                        <span>🛡️</span>
                        <span>Sicherheits-Karte</span>
                    </button>
                <?php endif; ?>

                <button
                    type="button"
                    id="btnMeasureTool"
                    onclick="toggleMeasureTool()"
                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-amber-950/60 text-slate-700 dark:text-slate-200 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Distanz, Fahrzeit &amp; Fläche zwischen beliebigen Punkten auf der Karte messen"
                >
                    <span>📏</span>
                    <span id="measureBtnLabel">Distanz &amp; Route messen</span>
                </button>
            </div>
        </div>
    </div>

    <!-- HAUPTBEREICH: KARTE (LINKS) + SEITENLEISTE MIT MARKERN, TOUREN & SCHICHTEN (RECHTS) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <!-- KARTEN-FLÄCHE (9 SPALTEN) -->
        <div class="lg:col-span-8 xl:col-span-9 space-y-3">
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-2.5 shadow-xs relative">
                <!-- SCHICHT-SCHALTER (LAYER-TOGGLES) ÜBER DER KARTE -->
                <div class="flex flex-wrap items-center justify-between gap-2 pb-2 mb-2 border-b border-slate-100 dark:border-slate-800 px-1 text-xs">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                            <input type="checkbox" id="chkLayerPlaces" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                            <span class="font-semibold">📍 Eigene Orte (<?= count($places) ?>)</span>
                        </label>
                        <?php if ($activeFloorplanId === 0): ?>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerCities" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">🏙️ Städte &amp; Grenzen</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerInfra" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">🛣️ Autobahnen &amp; Flüsse</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerTracks" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">🥾 GPX-Touren (<?= count($tracks) ?>)</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerContacts" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">📇 Kontakte (<?= count($contactPins) ?>)</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerCalendar" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">📅 Termine (<?= count($calendarPins) ?>)</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" id="chkLayerPhotos" checked onchange="syncMapLayersVisibility()" class="rounded text-brand-600">
                                <span class="font-semibold">📸 Foto-GPS (<?= count($photoPins) ?>)</span>
                            </label>
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none px-2 py-0.5 rounded-lg bg-purple-50 dark:bg-purple-950/60 border border-purple-200/80 dark:border-purple-800/80 text-purple-800 dark:text-purple-200">
                                <input type="checkbox" id="chkLayerEvCharging" onchange="toggleEvChargingLayer(this.checked)" class="rounded text-purple-600">
                                <span class="font-bold">⚡ Stromtankstellen</span>
                                <span id="evChargingCountBadge" class="hidden px-1.5 py-0.2 rounded-full bg-purple-600 text-white text-[10px] font-bold">0</span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-2 text-[11px] font-mono text-slate-500 dark:text-slate-400">
                        <span id="liveCursorCoords" title="Aktuelle Maus-Koordinaten">51.1657° N, 10.4515° E</span>
                        <span>·</span>
                        <span id="liveZoomBadge" class="font-bold text-brand-600 dark:text-brand-400">Zoom 6</span>
                    </div>
                </div>

                <!-- LIVE-REGENRADAR & WETTER-STEUERLEISTE (WENN AKTIV) -->
                <div id="rainRadarControlBar" class="hidden mb-2 p-2.5 rounded-xl bg-sky-50/90 dark:bg-sky-950/60 border border-sky-200 dark:border-sky-800/80 space-y-2 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-extrabold text-sky-900 dark:text-sky-200 flex items-center gap-1">
                                <span>⛈️</span>
                                <span>Live-Regenradar &amp; Wetter</span>
                            </span>
                            <button
                                type="button"
                                id="btnRadarPlayPause"
                                onclick="toggleRainRadarAnimation()"
                                class="px-2.5 py-1 rounded-lg bg-sky-600 hover:bg-sky-700 text-white font-bold cursor-pointer shadow-2xs"
                            >
                                ▶️ Abspielen
                            </button>
                            <input
                                type="range"
                                id="radarTimeSlider"
                                min="0"
                                max="12"
                                step="1"
                                value="0"
                                oninput="onRadarFrameSlide(this.value)"
                                class="w-36 sm:w-52 accent-sky-600 cursor-pointer"
                            >
                            <span id="radarTimeBadge" class="px-2 py-0.5 rounded-lg bg-white dark:bg-slate-800 border border-sky-200 dark:border-sky-700 font-bold text-sky-800 dark:text-sky-300">
                                Lade Radar…
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <label class="inline-flex items-center gap-1.5 cursor-pointer select-none" title="Live-Temperatur &amp; Wind der Wetterstationen ein-/ausblenden">
                                <input type="checkbox" id="chkRadarWeatherPins" checked onchange="toggleRadarWeatherPins(this.checked)" class="rounded text-sky-600">
                                <span class="font-semibold">🌡️ Live-Temperaturen</span>
                            </label>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[11px] text-slate-500">Deckkraft:</span>
                                <input
                                    type="range"
                                    id="radarOpacitySlider"
                                    min="25"
                                    max="95"
                                    value="72"
                                    oninput="onRadarOpacityChange(this.value)"
                                    class="w-16 accent-sky-600 cursor-pointer"
                                >
                            </div>
                            <button type="button" onclick="toggleRainRadar()" class="px-2 py-0.5 rounded-lg text-slate-500 hover:text-slate-900 dark:hover:text-white font-bold cursor-pointer">
                                ✕
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[10px] text-slate-500 dark:text-slate-400 pt-1 border-t border-sky-200/60 dark:border-sky-800/60">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">Niederschlags-Intensität:</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-sky-300 inline-block"></span> Leicht</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-blue-600 inline-block"></span> Mäßig</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-amber-500 inline-block"></span> Starkregen</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-xs bg-red-600 inline-block"></span> Unwetter / Hagel</span>
                        </div>
                        <span>🛡️ Datenschutz-Proxy über MiniCloud-Server (keine IP-Weitergabe)</span>
                    </div>
                </div>

                <!-- STROMTANKSTELLEN & E-LADESÄULEN STEUERLEISTE (WENN AKTIV) -->
                <div id="evChargingControlBar" class="hidden mb-2 p-2.5 rounded-xl bg-purple-50/90 dark:bg-purple-950/60 border border-purple-200 dark:border-purple-800/80 space-y-2 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-extrabold text-purple-900 dark:text-purple-200 flex items-center gap-1">
                                <span>⚡</span>
                                <span>Stromtankstellen &amp; E-Ladesäulen</span>
                            </span>
                            <span id="evChargingStatusBadge" class="px-2 py-0.5 rounded-lg bg-white dark:bg-slate-800 border border-purple-200 dark:border-purple-700 font-bold text-purple-800 dark:text-purple-300">
                                Lade Stationen…
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" id="evFilterBtn_all" onclick="setEvChargingFilter('all')" class="px-2 py-1 rounded-lg bg-purple-600 text-white font-bold cursor-pointer text-[11px]">
                                Alle
                            </button>
                            <button type="button" id="evFilterBtn_hpc" onclick="setEvChargingFilter('hpc')" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-purple-200 dark:border-purple-700 font-bold cursor-pointer text-[11px]" title="Ultra-Schnelllader ab 150 kW bis 400 kW (HPC)">
                                ⚡ HPC ≥150 kW
                            </button>
                            <button type="button" id="evFilterBtn_fast" onclick="setEvChargingFilter('fast')" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-purple-200 dark:border-purple-700 font-bold cursor-pointer text-[11px]" title="DC-Schnelllader 50 bis 149 kW">
                                🔋 Schnell 50–149 kW
                            </button>
                            <button type="button" id="evFilterBtn_ac" onclick="setEvChargingFilter('ac')" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-purple-200 dark:border-purple-700 font-bold cursor-pointer text-[11px]" title="AC-Normallader 11 bis 43 kW (Typ 2)">
                                🔌 AC Typ 2
                            </button>
                            <button type="button" onclick="loadEvChargingStationsInView(true)" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-purple-100 text-slate-700 dark:text-slate-200 font-bold cursor-pointer text-[11px]" title="Aktuellen Kartenausschnitt neu abfragen">
                                🔄
                            </button>
                            <button type="button" onclick="toggleEvChargingLayer(false)" class="px-2 py-0.5 rounded-lg text-slate-500 hover:text-slate-900 dark:hover:text-white font-bold cursor-pointer">
                                ✕
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 text-[10px] text-slate-500 dark:text-slate-400 pt-1 border-t border-purple-200/60 dark:border-purple-800/60">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-purple-600 inline-block"></span> ⚡ HPC Ultra-Schnelllader (150–400 kW)</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-emerald-600 inline-block"></span> 🔋 DC-Schnelllader (50–149 kW)</span>
                            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span> 🔌 AC-Ladesäule (11–43 kW Typ 2)</span>
                        </div>
                        <span>💡 Zoome näher an eine Stadt heran (ab Zoom 9), um alle lokalen Straßen-Ladesäulen nachzuladen</span>
                    </div>
                </div>

                <!-- MESS- & ROUTEN-INFOBAR (WENN AKTIV) -->
                <div id="measureInfoBar" class="hidden mb-2 p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-bold text-amber-800 dark:text-amber-300">📏 Mess- &amp; Routen-Modus aktiv:</span>
                        <span>Klicke Punkte auf der Karte an!</span>
                        <span id="measureStatsOutput" class="font-extrabold text-slate-900 dark:text-white">0,00 km · 🚗 0 Min. · 🚲 0 Min. · 🚶 0 Min.</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="clearMeasurements()" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-700 text-xs font-semibold cursor-pointer">
                            Zurücksetzen
                        </button>
                        <button type="button" onclick="toggleMeasureTool()" class="px-2.5 py-1 rounded-lg bg-amber-600 text-white text-xs font-bold cursor-pointer">
                            Beenden
                        </button>
                    </div>
                </div>

                <!-- DIE LEAFLET / VEKTOR-KARTENFLÄCHE -->
                <div id="mcMapCanvas"></div>

                <!-- HÖHENPROFIL-SCHUBLADE FÜR GPX-TOUREN -->
                <div id="elevationDrawer" class="hidden mt-2.5 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200/80 dark:border-slate-700">
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-slate-900 dark:text-white" id="elevationTrackTitle">🥾 Höhenprofil</span>
                            <span class="text-xs text-emerald-600 dark:text-emerald-400 font-bold" id="elevationTrackStats"></span>
                        </div>
                        <button type="button" onclick="document.getElementById('elevationDrawer').classList.add('hidden')" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-white cursor-pointer">✕ Schließen</button>
                    </div>
                    <canvas id="elevationCanvas" height="90" class="w-full h-[90px] rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700"></canvas>
                </div>
            </div>
        </div>

        <!-- RECHTE SEITENLEISTE (3 SPALTEN): ORTE, UMKREIS-FINDER, SICHERHEITS-KARTE, TOUREN & CLOUD -->
        <div class="lg:col-span-4 xl:col-span-3 space-y-3">
            <!-- DETAILGRAD-ERKLÄRUNG & OFFLINE-STATUS-BOX -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-3.5 shadow-xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-extrabold uppercase tracking-wider text-slate-500 dark:text-slate-400">📡 Intranet-Kartenstatus</span>
                    <button type="button" onclick="openMapModal('offlineDetailModal')" class="text-[11px] font-bold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer">
                        Detailgrad ändern &rarr;
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] text-slate-400">Vektor-Detailstufe</p>
                        <p class="font-bold text-slate-800 dark:text-slate-100" id="sideDetailLabel">Stufe <?= (int)$defaultDetailLevel ?> von 5</p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/60 dark:border-slate-700/60">
                        <p class="text-[10px] text-slate-400">Lokale Kacheln</p>
                        <p class="font-bold text-slate-800 dark:text-slate-100"><?= number_format($cachedTileCount, 0, ',', '.') ?> + <?= count($mbtilesPackages) ?> Pakete</p>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug">
                    💡 <strong>Tipp:</strong> Klicke mit der <strong>rechten Maustaste</strong> (oder langes Tippen) auf eine beliebige Stelle der Karte, um dort sofort einen neuen Standort-Pin zu speichern!
                </p>
            </div>

            <!-- SIDEBAR-TABS: ORTE / UMKREIS / SICHERHEIT / TOUREN / CLOUD -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-3.5 shadow-xs space-y-3">
                <div class="grid grid-cols-5 gap-1 p-1 rounded-xl bg-slate-100 dark:bg-slate-800 text-[11px] font-bold">
                    <button type="button" id="tabBtnPlaces" onclick="switchSidebarTab('places')" class="py-1.5 rounded-lg bg-brand-600 text-white cursor-pointer truncate" title="Gespeicherte Orte (<?= count($places) ?>)">
                        📍 Orte
                    </button>
                    <button type="button" id="tabBtnPoi" onclick="switchSidebarTab('poi')" class="py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer truncate" title="Umkreis-Finder (Tankstellen, Ladesäulen, Parken, Geld, Apotheken)">
                        ⛽ Umkreis
                    </button>
                    <button type="button" id="tabBtnSecurity" onclick="switchSidebarTab('security')" class="py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer truncate" title="Sicherheits-Landkarte (Login- &amp; Zugriffs-Herkunft)">
                        🛡️ Schutz
                    </button>
                    <button type="button" id="tabBtnTracks" onclick="switchSidebarTab('tracks')" class="py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer truncate" title="GPX-Touren (<?= count($tracks) ?>)">
                        🥾 Touren
                    </button>
                    <button type="button" id="tabBtnCloud" onclick="switchSidebarTab('cloud')" class="py-1.5 rounded-lg text-slate-600 dark:text-slate-300 cursor-pointer truncate" title="Cloud-Elemente (Kontakte, Termine, Fotos)">
                        🔗 Cloud
                    </button>
                </div>

                <!-- TAB 1: GESPEICHERTE ORTE & POIs -->
                <div id="sideTabPlaces" class="space-y-2 max-h-[430px] overflow-y-auto pr-1">
                    <?php if (empty($places)): ?>
                        <div class="text-center py-6 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700">
                            <p class="text-2xl mb-1">📍</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Noch keine eigenen Orte gespeichert</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Klicke auf „📍 Ort / Pin setzen“ oder rechtsklicke direkt auf die Karte.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($places as $pl): ?>
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-slate-700/70 transition-colors">
                                <div class="flex items-start justify-between gap-2">
                                    <button
                                        type="button"
                                        onclick="focusPlaceOnMap(<?= (float)$pl['lat'] ?>, <?= (float)$pl['lng'] ?>, <?= (int)$pl['id'] ?>)"
                                        class="flex items-start gap-2 text-left min-w-0 flex-1 cursor-pointer"
                                    >
                                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-sm shrink-0 text-white shadow-2xs" style="background-color: <?= e((string)$pl['color']) ?>">
                                            <?= e((string)$pl['icon']) ?>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-slate-900 dark:text-white truncate">
                                                <?= e((string)$pl['title']) ?>
                                                <?php if ((int)$pl['is_shared'] === 1): ?>
                                                    <span class="ml-1 px-1.5 py-0.2 rounded text-[9px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">Team</span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ((string)$pl['address'] !== ''): ?>
                                                <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?= e((string)$pl['address']) ?></p>
                                            <?php else: ?>
                                                <p class="text-[10px] font-mono text-slate-400"><?= number_format((float)$pl['lat'], 4, '.', '') ?>, <?= number_format((float)$pl['lng'], 4, '.', '') ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button
                                            type="button"
                                            onclick='editExistingPlace(<?= json_encode([
                                                "id"          => (int)$pl["id"],
                                                "title"       => (string)$pl["title"],
                                                "category"    => (string)$pl["category"],
                                                "lat"         => (float)$pl["lat"],
                                                "lng"         => (float)$pl["lng"],
                                                "address"     => (string)$pl["address"],
                                                "description" => (string)($pl["description"] ?? ""),
                                                "color"       => (string)$pl["color"],
                                                "icon"        => (string)$pl["icon"],
                                                "min_detail_level" => (int)$pl["min_detail_level"],
                                                "is_shared"   => (int)$pl["is_shared"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="p-1 rounded-lg text-slate-400 hover:text-brand-600 cursor-pointer"
                                            title="Bearbeiten"
                                        >✏️</button>
                                        <form method="post" action="maps.php" class="m-0 inline" onsubmit="return confirm('Standort wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_place">
                                            <input type="hidden" name="place_id" value="<?= (int)$pl['id'] ?>">
                                            <button type="submit" class="p-1 rounded-lg text-slate-400 hover:text-red-600 cursor-pointer" title="Löschen">🗑️</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: GPX / KML / GEOJSON TOUREN -->
                <div id="sideTabTracks" class="hidden space-y-2 max-h-[430px] overflow-y-auto pr-1">
                    <?php if (empty($tracks)): ?>
                        <div class="text-center py-6 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700">
                            <p class="text-2xl mb-1">🥾</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Noch keine GPX-/GeoJSON-Routen</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Importiere Wander-, Rad-, Einsatz- oder Fahrstrecken inkl. Höhenprofil.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tracks as $idx => $tr): ?>
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-between gap-2">
                                <button type="button" onclick="focusTrackByIndex(<?= (int)$idx ?>)" class="text-left min-w-0 flex-1 cursor-pointer">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-3 h-3 rounded-full shrink-0" style="background-color: <?= e((string)$tr['color']) ?>"></span>
                                        <p class="text-xs font-bold text-slate-900 dark:text-white truncate"><?= e((string)$tr['title']) ?></p>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                        📏 <?= number_format((float)$tr['distance_km'], 2, ',', '.') ?> km · ⛰️ +<?= (int)$tr['ascent_m'] ?> m / -<?= (int)$tr['descent_m'] ?> m
                                    </p>
                                </button>
                                <form method="post" action="maps.php" class="m-0 shrink-0" onsubmit="return confirm('GPS-Tour löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_track">
                                    <input type="hidden" name="track_id" value="<?= (int)$tr['id'] ?>">
                                    <button type="submit" class="p-1 text-slate-400 hover:text-red-600 cursor-pointer" title="Löschen">🗑️</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- TAB 3: CLOUD-VERKNÜPFUNGEN (KONTAKTE, KALENDER, FOTO-EXIF) -->
                <div id="sideTabCloud" class="hidden space-y-2 max-h-[430px] overflow-y-auto pr-1 text-xs">
                    <?php if (empty($contactPins) && empty($calendarPins) && empty($photoPins)): ?>
                        <p class="text-xs text-slate-500 py-4 text-center">Keine Kontakte mit Ortsangabe, Termine mit Ort oder GPS-Fotos gefunden.</p>
                    <?php endif; ?>
                    <?php foreach ($calendarPins as $cp): ?>
                        <button type="button" onclick="flyToMapCoord(<?= (float)$cp['lat'] ?>, <?= (float)$cp['lng'] ?>, 12)" class="w-full text-left p-2 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800/60 cursor-pointer">
                            <p class="font-bold text-slate-900 dark:text-white truncate">📅 <?= e($cp['title']) ?></p>
                            <p class="text-[11px] text-slate-500 truncate"><?= e($cp['date']) ?> · <?= e($cp['location']) ?></p>
                        </button>
                    <?php endforeach; ?>
                    <?php foreach ($contactPins as $ctp): ?>
                        <button type="button" onclick="flyToMapCoord(<?= (float)$ctp['lat'] ?>, <?= (float)$ctp['lng'] ?>, 12)" class="w-full text-left p-2 rounded-xl bg-sky-50/70 dark:bg-sky-950/30 border border-sky-200/70 dark:border-sky-800/60 cursor-pointer">
                            <p class="font-bold text-slate-900 dark:text-white truncate">📇 <?= e($ctp['name']) ?></p>
                            <p class="text-[11px] text-slate-500 truncate"><?= e($ctp['address']) ?></p>
                        </button>
                    <?php endforeach; ?>
                    <?php foreach ($photoPins as $pp): ?>
                        <button type="button" onclick="flyToMapCoord(<?= (float)$pp['lat'] ?>, <?= (float)$pp['lng'] ?>, 13)" class="w-full text-left p-2 rounded-xl bg-purple-50/70 dark:bg-purple-950/30 border border-purple-200/70 dark:border-purple-800/60 cursor-pointer">
                            <p class="font-bold text-slate-900 dark:text-white truncate">📸 <?= e($pp['name']) ?></p>
                            <p class="text-[11px] text-slate-500"><?= e($pp['date']) ?> · GPS-Foto</p>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- TAB 4: UMKREIS-FINDER (TANKSTELLEN, E-LADESÄULEN, PARKPLÄTZE, GELDAUTOMATEN, APOTHEKEN, GASTRONOMIE) -->
                <div id="sideTabPoi" class="hidden space-y-3 text-xs">
                    <div class="p-2.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 border border-emerald-200/80 dark:border-emerald-800/70 space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-extrabold text-emerald-900 dark:text-emerald-200">⛽ Umkreis-Finder</span>
                            <button
                                type="button"
                                onclick="useMyGpsForPoiSearch()"
                                class="px-2 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold cursor-pointer shadow-2xs"
                                title="Eigenen GPS-Standort des Geräts bestimmen und als Suchmittelpunkt verwenden"
                            >
                                📡 Mein GPS
                            </button>
                        </div>

                        <!-- Suchradius-Auswahl -->
                        <div class="flex items-center justify-between gap-1">
                            <span class="text-[11px] text-slate-600 dark:text-slate-300 font-semibold">Suchradius:</span>
                            <div class="inline-flex rounded-lg bg-white dark:bg-slate-800 p-0.5 border border-slate-200 dark:border-slate-700">
                                <?php foreach ([2 => '2 km', 5 => '5 km', 10 => '10 km', 25 => '25 km'] as $rKm => $rLbl): ?>
                                    <button
                                        type="button"
                                        id="poiRadBtn_<?= $rKm ?>"
                                        onclick="setPoiSearchRadius(<?= $rKm ?>)"
                                        class="px-2 py-0.5 rounded-md text-[11px] font-bold cursor-pointer <?= $rKm === 5 ? 'bg-emerald-600 text-white' : 'text-slate-600 dark:text-slate-300' ?>"
                                    >
                                        <?= $rLbl ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 8 Schnell-Kategorien -->
                        <div class="grid grid-cols-2 gap-1.5">
                            <button type="button" onclick="runPoiRadiusSearch('fuel')" id="poiCatBtn_fuel" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-orange-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>⛽</span><span class="truncate">Tankstellen</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('charging')" id="poiCatBtn_charging" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-emerald-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>🔌</span><span class="truncate">E-Ladesäulen</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('parking')" id="poiCatBtn_parking" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>🅿️</span><span class="truncate">Parkplätze</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('atm')" id="poiCatBtn_atm" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-sky-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>🏧</span><span class="truncate">Geld / Banken</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('supermarket')" id="poiCatBtn_supermarket" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-emerald-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>🛒</span><span class="truncate">Supermärkte</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('pharmacy')" id="poiCatBtn_pharmacy" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>💊</span><span class="truncate">Apotheke/Arzt</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('aed')" id="poiCatBtn_aed" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>❤️</span><span class="truncate">Defibrillator</span>
                            </button>
                            <button type="button" onclick="runPoiRadiusSearch('food')" id="poiCatBtn_food" class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-amber-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 font-bold text-left flex items-center gap-1.5 cursor-pointer">
                                <span>🍽️</span><span class="truncate">Gastronomie</span>
                            </button>
                        </div>

                        <div class="flex items-center justify-between text-[10px] text-slate-500 dark:text-slate-400">
                            <span id="poiSearchStatusText">Wähle oben eine Kategorie rund um die Kartenmitte.</span>
                            <button type="button" onclick="clearPoiSearchResults()" class="text-slate-400 hover:text-red-500 font-bold cursor-pointer">Leeren</button>
                        </div>
                    </div>

                    <div id="poiResultsList" class="space-y-2 max-h-[310px] overflow-y-auto pr-1">
                        <div class="text-center py-5 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700">
                            <p class="text-xl mb-1">🧭</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Bereit für Umkreissuche</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Zoome auf eine Stadt oder klicke auf „📡 Mein GPS“ und wähle eine Kategorie.</p>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: SICHERHEITS-LANDKARTE (GEO-IP LOGIN- & ZUGRIFFS-HERKUNFT) -->
                <div id="sideTabSecurity" class="hidden space-y-3 text-xs">
                    <div class="p-2.5 rounded-xl bg-rose-50/70 dark:bg-rose-950/40 border border-rose-200/80 dark:border-rose-800/70 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-extrabold text-rose-900 dark:text-rose-200 flex items-center gap-1">
                                <span>🛡️</span>
                                <span>Sicherheits-Landkarte</span>
                            </span>
                            <button
                                type="button"
                                onclick="loadSecurityMapData(true)"
                                class="px-2 py-0.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-[11px] font-bold cursor-pointer"
                            >
                                🔄 Aktualisieren
                            </button>
                        </div>
                        <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-snug">
                            Visualisiert erfolgreiche Cloud-Anmeldungen (🟢), abgewehrte Login-/Bruteforce-Versuche (🔴) und Verbindungswege zum Server.
                        </p>

                        <div class="grid grid-cols-3 gap-1.5 text-center pt-1">
                            <div class="p-1.5 rounded-lg bg-white dark:bg-slate-800 border border-emerald-200 dark:border-emerald-800/70">
                                <p class="text-[10px] text-slate-400">Logins OK</p>
                                <p class="text-sm font-extrabold text-emerald-600 dark:text-emerald-400" id="secStatSuccess">–</p>
                            </div>
                            <div class="p-1.5 rounded-lg bg-white dark:bg-slate-800 border border-rose-200 dark:border-rose-800/70">
                                <p class="text-[10px] text-slate-400">Abgewehrt</p>
                                <p class="text-sm font-extrabold text-rose-600 dark:text-rose-400" id="secStatFailed">–</p>
                            </div>
                            <div class="p-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
                                <p class="text-[10px] text-slate-400">IP-Netze</p>
                                <p class="text-sm font-extrabold text-slate-800 dark:text-slate-100" id="secStatIps">–</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-1 pt-1">
                            <div class="inline-flex rounded-lg bg-white dark:bg-slate-800 p-0.5 border border-slate-200 dark:border-slate-700 w-full">
                                <button type="button" id="secFilter_all" onclick="filterSecurityMap('all')" class="flex-1 py-1 rounded-md text-[11px] font-bold bg-rose-600 text-white cursor-pointer">Alle</button>
                                <button type="button" id="secFilter_success" onclick="filterSecurityMap('success')" class="flex-1 py-1 rounded-md text-[11px] font-bold text-slate-600 dark:text-slate-300 cursor-pointer">🟢 Logins</button>
                                <button type="button" id="secFilter_danger" onclick="filterSecurityMap('danger')" class="flex-1 py-1 rounded-md text-[11px] font-bold text-slate-600 dark:text-slate-300 cursor-pointer">🔴 Angriffe</button>
                            </div>
                        </div>
                    </div>

                    <div id="securityMapList" class="space-y-2 max-h-[310px] overflow-y-auto pr-1">
                        <div class="text-center py-5 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700">
                            <p class="text-xl mb-1">🛡️</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Sicherheits-Analyse bereit</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Klicke auf „🔄 Aktualisieren“, um alle Login-Standorte auf der Karte anzuzeigen.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODALS: ORT SPEICHERN, GPX-UPLOAD, LAGEPLAN-UPLOAD & OFFLINE-DETAILGRAD
     ========================================================================= -->

<!-- 1. MODAL: ORT / PIN SPEICHERN -->
<div id="placeModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="modal-box bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-md w-full p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-extrabold text-slate-900 dark:text-white" id="placeModalTitle">📍 Neuen Ort / Pin speichern</h3>
            <button type="button" onclick="closeMapModal('placeModal')" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">✕</button>
        </div>
        <form method="post" action="maps.php" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_place">
            <input type="hidden" name="place_id" id="placeFormId" value="0">
            <input type="hidden" name="floorplan_id" value="<?= (int)$activeFloorplanId ?>">

            <div>
                <label class="block font-bold mb-1">Bezeichnung / Standortname *</label>
                <input type="text" name="title" id="placeFormTitle" required maxlength="160" placeholder="z.B. Hauptgebäude, Serverraum B2, Treffpunkt…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block font-bold mb-1">Kategorie</label>
                    <select name="category" id="placeFormCategory" onchange="onPlaceCategoryPick(this.value)" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                        <?php foreach ($categoriesConfig as $cKey => $cMeta): ?>
                            <option value="<?= e($cKey) ?>" data-icon="<?= e($cMeta['icon']) ?>" data-color="<?= e($cMeta['color']) ?>">
                                <?= e($cMeta['icon'] . ' ' . $cMeta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-bold mb-1">Sichtbar ab Detailgrad</label>
                    <select name="min_detail_level" id="placeFormMinDetail" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                        <option value="1">Stufe 1 (Immer sichtbar)</option>
                        <option value="2">Ab Stufe 2 (Regional)</option>
                        <option value="3">Ab Stufe 3 (Stadt-Zoom)</option>
                        <option value="4">Ab Stufe 4 (Detail)</option>
                        <option value="5">Ab Stufe 5 (Maximal)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block font-bold mb-1">Breitengrad (Lat / Y)</label>
                    <input type="text" name="lat" id="placeFormLat" required value="51.1657" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 font-mono">
                </div>
                <div>
                    <label class="block font-bold mb-1">Längengrad (Lng / X)</label>
                    <input type="text" name="lng" id="placeFormLng" required value="10.4515" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 font-mono">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2.5">
                <div>
                    <label class="block font-bold mb-1">Symbol</label>
                    <input type="text" name="icon" id="placeFormIcon" value="⭐" maxlength="4" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-center text-sm">
                </div>
                <div>
                    <label class="block font-bold mb-1">Pin-Farbe</label>
                    <input type="color" name="color" id="placeFormColor" value="#2563eb" class="w-full h-9 rounded-xl border border-slate-300 dark:border-slate-700 cursor-pointer">
                </div>
                <div class="flex items-end pb-1">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" name="is_shared" id="placeFormShared" value="1" checked class="rounded text-brand-600">
                        <span class="font-bold">Im Team teilen</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block font-bold mb-1">Adresse / Raum / Zusatzinfo</label>
                <input type="text" name="address" id="placeFormAddress" maxlength="255" placeholder="Straße, Ort oder Raumnummer…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
            </div>

            <div>
                <label class="block font-bold mb-1">Notizen / Beschreibung</label>
                <textarea name="description" id="placeFormDesc" rows="2" placeholder="Zufahrtshinweise, Ansprechpartner, Öffnungszeiten…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeMapModal('placeModal')" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold cursor-pointer">💾 Speichern</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MODAL: GPX / KML / GEOJSON ROUTE LADEN -->
<div id="trackUploadModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="modal-box bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-md w-full p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-extrabold text-slate-900 dark:text-white">🥾 GPX / KML / GeoJSON Tour laden</h3>
            <button type="button" onclick="closeMapModal('trackUploadModal')" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">✕</button>
        </div>
        <form method="post" action="maps.php" enctype="multipart/form-data" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload_track">

            <div>
                <label class="block font-bold mb-1">Name der Route (optional)</label>
                <input type="text" name="title" maxlength="160" placeholder="Wird sonst automatisch aus dem Dateinamen übernommen" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
            </div>

            <div>
                <label class="block font-bold mb-1">Datei vom Gerät wählen (.gpx, .kml, .geojson)</label>
                <input type="file" name="track_file" accept=".gpx,.kml,.geojson,.json" class="w-full text-xs">
            </div>

            <?php if (!empty($cloudTrackFiles)): ?>
                <div>
                    <label class="block font-bold mb-1">Oder direkt aus deinen MiniCloud-Dateien wählen:</label>
                    <select name="cloud_file_id" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                        <option value="0">— Eigene Cloud-Datei auswählen —</option>
                        <?php foreach ($cloudTrackFiles as $cf): ?>
                            <option value="<?= (int)$cf['id'] ?>"><?= e((string)$cf['original_name']) ?> (<?= e(formatBytes((int)$cf['file_size'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="flex items-center justify-between pt-1">
                <div class="flex items-center gap-2">
                    <label class="font-bold">Linienfarbe:</label>
                    <input type="color" name="color" value="#10b981" class="w-10 h-8 rounded border border-slate-300 cursor-pointer">
                </div>
                <label class="inline-flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" name="is_shared" value="1" checked class="rounded text-brand-600">
                    <span class="font-bold">Für Team sichtbar</span>
                </label>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeMapModal('trackUploadModal')" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold cursor-pointer">🥾 Route importieren</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. MODAL: EIGENEN INTRANET-LAGEPLAN / GEBÄUDEPLAN HOCHLADEN -->
<div id="floorplanModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="modal-box bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-md w-full p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-extrabold text-slate-900 dark:text-white">🏢 Intranet-Lageplan / Gebäude-Grundriss</h3>
            <button type="button" onclick="closeMapModal('floorplanModal')" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">✕</button>
        </div>
        <form method="post" action="maps.php" enctype="multipart/form-data" class="space-y-3 text-xs">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="upload_floorplan">

            <div>
                <label class="block font-bold mb-1">Bezeichnung des Lageplans *</label>
                <input type="text" name="title" required maxlength="160" placeholder="z.B. Werksgelände Nord, Hauptgebäude, Sporthalle…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block font-bold mb-1">Etage / Bereich</label>
                    <input type="text" name="floor_label" value="EG" maxlength="40" placeholder="EG, 1. OG, Außen…" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                </div>
                <div>
                    <label class="block font-bold mb-1">Breite in Metern (Maßstab)</label>
                    <input type="number" name="scale_meters" value="100" min="5" max="10000" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                </div>
            </div>

            <div>
                <label class="block font-bold mb-1">Plan-Bilddatei (PNG, JPG oder WebP) *</label>
                <input type="file" name="floorplan_file" required accept="image/png,image/jpeg,image/webp" class="w-full text-xs">
            </div>

            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                <input type="checkbox" name="is_shared" value="1" checked class="rounded text-brand-600">
                <span class="font-bold">Im gesamten Intranet / Team bereitstellen</span>
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeMapModal('floorplanModal')" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 font-semibold cursor-pointer">Abbrechen</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold cursor-pointer">🏢 Lageplan anlegen</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. MODAL: DETAILGRAD NACHTRÄGLICH ÄNDERN & OFFLINE-KACHEL-PAKETE -->
<div id="offlineDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="modal-box bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl max-w-lg w-full p-5 space-y-4 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-extrabold text-slate-900 dark:text-white">📦 Detailgrad &amp; Offline-Karten-Manager</h3>
            <button type="button" onclick="closeMapModal('offlineDetailModal')" class="text-slate-400 hover:text-slate-600 dark:hover:text-white cursor-pointer">✕</button>
        </div>

        <form method="post" action="maps.php" class="space-y-3 text-xs border-b border-slate-200 dark:border-slate-800 pb-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_map_config">

            <div class="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/80 space-y-2">
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" name="airgap_only" value="1" <?= $airGapMode ? 'checked' : '' ?> class="mt-0.5 rounded text-emerald-600">
                    <div>
                        <p class="font-extrabold text-emerald-900 dark:text-emerald-200">🔒 Strikt abgeschotteter Intranet-Modus (Air-Gap-Lock)</p>
                        <p class="text-[11px] text-emerald-800/80 dark:text-emerald-300/80 mt-0.5">
                            Wenn aktiviert, arbeitet das Kartenmodul zu <strong>100 % autark offline</strong> (eingebaute Vektorkarte + lokale <code class="font-mono">.mbtiles</code> + lokaler Platten-Cache) ohne jegliche Verbindung ins Internet.
                        </p>
                    </div>
                </label>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold mb-1">Standard Vektor-Detailstufe (1–5)</label>
                    <select name="default_detail" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                        <option value="1" <?= $defaultDetailLevel === 1 ? 'selected' : '' ?>>Stufe 1 – Nur Metropolen &amp; Grenzen</option>
                        <option value="2" <?= $defaultDetailLevel === 2 ? 'selected' : '' ?>>Stufe 2 – Großstädte &amp; Hauptflüsse</option>
                        <option value="3" <?= $defaultDetailLevel === 3 ? 'selected' : '' ?>>Stufe 3 – Mittelstädte &amp; Autobahnen</option>
                        <option value="4" <?= $defaultDetailLevel === 4 ? 'selected' : '' ?>>Stufe 4 – Alle Städte &amp; Verkehrsnetz</option>
                        <option value="5" <?= $defaultDetailLevel === 5 ? 'selected' : '' ?>>Stufe 5 – Maximaler Detailgrad</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold mb-1">Max. Kachel-Detailstufe (Overzoom)</label>
                    <select name="max_zoom_detail" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                        <?php foreach ([8 => 'Zoom 8 (Bundesland)', 10 => 'Zoom 10 (Landkreis)', 12 => 'Zoom 12 (Stadtgebiet)', 14 => 'Zoom 14 (Straßen)', 16 => 'Zoom 16 (Gebäude)', 18 => 'Zoom 18 (Maximal)'] as $zVal => $zLbl): ?>
                            <option value="<?= $zVal ?>" <?= $maxCachedZoomLevel === $zVal ? 'selected' : '' ?>><?= e($zLbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold cursor-pointer">
                    💾 Standard-Detailgrad speichern
                </button>
            </div>
        </form>

        <!-- NACHTRÄGLICHES HERUNTERLADEN / ERHÖHEN DES KACHEL-DETAILGRADS -->
        <div class="space-y-2.5 text-xs">
            <p class="font-extrabold text-slate-900 dark:text-white">📥 Offline-Straßenkacheln für Bundesland oder Ausschnitt vorab speichern</p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                Bereits ab Werk integriert sind <strong>alle 23.296 deutschen PLZ/Orte</strong>, <strong>alle 400 Landkreise &amp; 16 Bundesländer (Hochauflösendes GeoJSON)</strong> sowie <strong>97 echte OpenStreetMap-Basiskacheln für ganz Deutschland (bis Zoom 8)</strong>. Hier kannst du für beliebige Regionen tiefere Straßen-Detailstufen lokal in <code class="font-mono">uploads/map_tiles/</code> nachladen:
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <select id="prefetchRegionSelect" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                    <option value="view">🎯 Aktuell sichtbarer Kartenausschnitt</option>
                    <option value="47.2,55.0,5.8,15.1">🇩🇪 Ganz Deutschland</option>
                    <option value="50.3,52.5,5.8,9.5">🏭 Nordrhein-Westfalen</option>
                    <option value="47.5,49.8,7.5,10.5">🦁 Baden-Württemberg</option>
                    <option value="47.2,50.6,9.0,13.9">🏔️ Bayern</option>
                    <option value="49.4,51.7,7.7,10.3">🏦 Hessen</option>
                    <option value="51.3,53.9,6.6,11.6">🐎 Niedersachsen &amp; Bremen</option>
                    <option value="51.3,53.6,11.2,14.8">🐻 Berlin &amp; Brandenburg</option>
                    <option value="48.9,50.9,6.1,8.5">🍷 Rheinland-Pfalz &amp; Saarland</option>
                    <option value="50.1,51.7,11.8,15.0">🏰 Sachsen</option>
                    <option value="53.3,55.1,8.2,11.4">⚓ Schleswig-Holstein &amp; Hamburg</option>
                </select>
                <select id="prefetchZoomSelect" class="px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700">
                    <option value="7">Detailstufe Zoom 7 (Länder-Überblick)</option>
                    <option value="8">Detailstufe Zoom 8 (Bundesland-Basis)</option>
                    <option value="9">Detailstufe Zoom 9 (Regionen &amp; Landkreise)</option>
                    <option value="10" selected>Detailstufe Zoom 10 (Kreisstädte &amp; Straßen)</option>
                    <option value="12">Detailstufe Zoom 12 (Stadtplan-Übersicht)</option>
                    <option value="14">Detailstufe Zoom 14 (Einzelne Straßen)</option>
                    <option value="16">Detailstufe Zoom 16 (Gebäude &amp; Wege)</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="prefetchCurrentViewTiles()" class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold cursor-pointer">
                    📥 Region / Detailstufe jetzt lokal speichern
                </button>
            </div>
            <p id="prefetchStatusMsg" class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400"></p>
        </div>
    </div>
</div>

<script>
// =========================================================================
// 8. INTRANET-VEKTOR-ATLAS & LEAFLET KARTEN-ENGINE (100 % OFFLINE-FÄHIG)
// =========================================================================
const MC_MAP_CSRF       = <?= json_encode(getCsrfToken()) ?>;
const MC_FLOORPLAN      = <?= json_encode($activeFloorplan, JSON_UNESCAPED_UNICODE) ?>;
const MC_GAZETTEER      = <?= json_encode($gazetteer, JSON_UNESCAPED_UNICODE) ?>;
const MC_PLACES         = <?= json_encode(array_map(static fn($r) => [
    'id'          => (int)$r['id'],
    'title'       => (string)$r['title'],
    'category'    => (string)$r['category'],
    'lat'         => (float)$r['lat'],
    'lng'         => (float)$r['lng'],
    'address'     => (string)$r['address'],
    'description' => (string)($r['description'] ?? ''),
    'color'       => (string)$r['color'],
    'icon'        => (string)$r['icon'],
    'min_lvl'     => (int)$r['min_detail_level'],
    'shared'      => (int)$r['is_shared'] === 1,
], $places), JSON_UNESCAPED_UNICODE) ?>;
const MC_TRACKS         = <?= json_encode(array_map(static fn($t) => [
    'id'          => (int)$t['id'],
    'title'       => (string)$t['title'],
    'color'       => (string)$t['color'],
    'distance_km' => (float)$t['distance_km'],
    'ascent_m'    => (int)$t['ascent_m'],
    'descent_m'   => (int)$t['descent_m'],
    'points'      => json_decode((string)$t['points_json'], true) ?: [],
], $tracks), JSON_UNESCAPED_UNICODE) ?>;
const MC_CONTACTS       = <?= json_encode($contactPins, JSON_UNESCAPED_UNICODE) ?>;
const MC_CALENDAR       = <?= json_encode($calendarPins, JSON_UNESCAPED_UNICODE) ?>;
const MC_PHOTOS         = <?= json_encode($photoPins, JSON_UNESCAPED_UNICODE) ?>;

let mcAirGapOnly        = <?= $airGapMode ? 'true' : 'false' ?>;
let mcHasGermanyPmtiles = <?= $hasGermanyPmtiles ? 'true' : 'false' ?>;
let mcCurrentDetailLvl  = <?= (int)$defaultDetailLevel ?>;
let mcMaxZoomDetail     = <?= (int)$maxCachedZoomLevel ?>;
let mcMap               = null;
let mcTileLayer         = null;
let mcPmtilesLayer      = null;
let mcPlaceMarkersById  = {};

// Schicht-Gruppen (LayerGroups)
const layerGroupBaseVector  = L.layerGroup();
const layerGroupInfra       = L.layerGroup();
const layerGroupCities      = L.layerGroup();
const layerGroupPlaces      = L.layerGroup();
const layerGroupTracks      = L.layerGroup();
const layerGroupContacts    = L.layerGroup();
const layerGroupCalendar    = L.layerGroup();
const layerGroupPhotos      = L.layerGroup();
const layerGroupMeasure     = L.layerGroup();
const layerGroupRainWeather = L.layerGroup();
const layerGroupPoiSearch   = L.layerGroup();
const layerGroupSecurityMap = L.layerGroup();
const layerGroupEvCharging  = L.layerGroup();

// Eingebettete Offline-Vektordaten (Deutschland-Umriss, Nachbarländer, Bundesländer, Hauptflüsse & Autobahnen)
const OFFLINE_GERMANY_POLY = [
    [54.91,8.32],[54.80,9.45],[54.32,10.15],[54.44,11.20],[54.00,10.75],[54.15,12.10],[54.45,13.40],[53.90,14.22],
    [53.25,14.40],[52.35,14.55],[51.50,14.98],[51.02,14.95],[50.85,14.60],[50.73,13.75],[50.42,12.90],[50.20,12.18],
    [49.55,12.55],[49.10,13.20],[48.56,13.80],[48.55,13.43],[48.12,12.85],[47.63,13.00],[47.70,12.15],[47.49,11.10],
    [47.27,10.18],[47.55,9.68],[47.66,9.17],[47.58,8.20],[47.56,7.59],[48.00,7.58],[48.58,7.80],[49.00,8.22],
    [48.97,7.95],[49.18,7.00],[49.48,6.38],[50.12,6.13],[50.77,6.05],[51.05,5.90],[51.83,6.12],[51.88,6.85],
    [52.45,6.70],[53.18,7.20],[53.68,7.15],[53.75,8.15],[53.55,8.55],[53.88,8.68],[54.15,8.85],[54.91,8.32]
];

const OFFLINE_BUNDESLAENDER = [
    { name: 'Nordrhein-Westfalen', lat: 51.48, lng: 7.55, poly: [[50.35,6.10],[51.05,5.90],[51.83,6.12],[51.88,6.85],[52.45,7.60],[52.32,8.95],[51.85,9.35],[51.40,8.85],[50.80,8.15],[50.60,7.25],[50.35,6.10]] },
    { name: 'Baden-Württemberg',   lat: 48.55, lng: 9.05, poly: [[47.56,7.59],[48.58,7.80],[49.00,8.22],[49.50,8.48],[49.78,9.50],[49.10,10.20],[48.40,10.00],[47.65,9.60],[47.56,7.59]] },
    { name: 'Bayern',              lat: 48.95, lng: 11.45, poly: [[47.27,10.18],[48.40,10.00],[49.78,9.50],[50.05,9.00],[50.52,10.10],[50.32,11.95],[49.55,12.55],[48.56,13.80],[47.63,13.00],[47.49,11.10],[47.27,10.18]] },
    { name: 'Niedersachsen',       lat: 52.75, lng: 9.65, poly: [[53.68,7.15],[53.88,8.68],[53.55,9.95],[53.15,11.15],[52.42,10.85],[51.54,9.92],[52.28,8.05],[52.45,6.70],[53.68,7.15]] },
    { name: 'Hessen',              lat: 50.60, lng: 9.05, poly: [[49.50,8.48],[50.08,7.90],[50.80,8.15],[51.48,9.48],[51.20,10.10],[50.52,10.10],[50.05,9.00],[49.50,8.48]] },
    { name: 'Rheinland-Pfalz',     lat: 49.95, lng: 7.35, poly: [[48.97,7.95],[49.00,8.22],[50.00,8.25],[50.60,7.25],[50.35,6.10],[49.48,6.38],[49.45,7.30],[48.97,7.95]] },
    { name: 'Sachsen',             lat: 51.05, lng: 13.35, poly: [[50.20,12.18],[51.35,12.20],[51.50,14.98],[50.85,14.60],[50.42,12.90],[50.20,12.18]] },
    { name: 'Brandenburg',         lat: 52.20, lng: 13.60, poly: [[51.50,14.98],[53.25,14.40],[53.15,11.50],[51.90,12.50],[51.50,14.98]] },
    { name: 'Schleswig-Holstein',  lat: 54.18, lng: 9.80, poly: [[54.91,8.32],[54.80,9.45],[54.44,11.20],[53.55,10.40],[53.88,8.68],[54.91,8.32]] },
    { name: 'Mecklenburg-Vorp.',   lat: 53.75, lng: 12.60, poly: [[53.85,10.70],[54.45,13.40],[53.25,14.40],[53.15,11.15],[53.85,10.70]] },
    { name: 'Sachsen-Anhalt',      lat: 51.95, lng: 11.70, poly: [[53.00,11.15],[51.90,12.50],[51.15,11.80],[51.65,10.65],[53.00,11.15]] },
    { name: 'Thüringen',           lat: 50.85, lng: 11.05, poly: [[51.45,10.15],[51.15,12.35],[50.32,11.95],[50.52,10.10],[51.45,10.15]] }
];

const OFFLINE_RIVERS = [
    { name: 'Rhein',  pts: [[47.66,9.17],[47.56,7.59],[49.00,8.25],[49.99,8.25],[50.36,7.59],[50.94,6.96],[51.23,6.77],[51.83,6.12]] },
    { name: 'Donau',  pts: [[47.95,8.50],[48.40,9.99],[48.77,11.43],[49.01,12.10],[48.57,13.43],[48.21,16.37]] },
    { name: 'Elbe',   pts: [[50.90,14.20],[51.05,13.74],[51.85,12.25],[52.12,11.63],[53.55,9.99],[53.86,8.70]] },
    { name: 'Main',   pts: [[50.05,11.55],[49.90,10.90],[49.79,9.95],[50.11,8.68],[49.99,8.25]] },
    { name: 'Mosel',  pts: [[49.45,6.36],[49.75,6.64],[50.15,7.16],[50.36,7.59]] },
    { name: 'Neckar', pts: [[48.15,8.62],[48.52,9.06],[48.78,9.18],[49.14,9.21],[49.40,8.67],[49.49,8.47]] },
    { name: 'Weser',  pts: [[51.42,9.65],[52.29,8.92],[53.08,8.80],[53.54,8.58]] }
];

const OFFLINE_AUTOBAHNEN = [
    { name: 'A1', pts: [[54.32,10.13],[53.87,10.69],[53.55,9.99],[53.08,8.80],[52.28,8.05],[51.96,7.63],[51.51,7.47],[50.94,6.96],[49.75,6.64],[49.24,7.00]] },
    { name: 'A2', pts: [[51.45,6.85],[51.51,7.47],[52.03,8.53],[52.38,9.73],[52.27,10.53],[52.12,11.63],[52.35,13.00]] },
    { name: 'A3', pts: [[51.83,6.15],[51.43,6.76],[50.94,6.96],[50.11,8.68],[49.79,9.95],[49.45,11.08],[49.01,12.10],[48.57,13.43]] },
    { name: 'A5', pts: [[50.85,9.55],[50.11,8.68],[49.40,8.67],[49.01,8.40],[48.00,7.84],[47.58,7.59]] },
    { name: 'A7', pts: [[54.79,9.45],[53.55,9.99],[52.38,9.73],[51.31,9.48],[49.79,9.95],[48.40,9.99],[47.55,10.30]] },
    { name: 'A8', pts: [[49.01,8.40],[48.78,9.18],[48.40,9.99],[48.37,10.90],[48.14,11.58],[47.85,12.13],[47.78,12.95]] },
    { name: 'A9', pts: [[52.35,13.00],[51.34,12.37],[50.32,11.90],[49.45,11.08],[48.77,11.43],[48.14,11.58]] }
];

function initMiniCloudMap() {
    if (MC_FLOORPLAN) {
        // INTRANET-LAGEPLAN / GEBÄUDE-GRUNDRISS MODUS (CRS.Simple)
        const w = Number(MC_FLOORPLAN.width_px) || 1600;
        const h = Number(MC_FLOORPLAN.height_px) || 1000;
        const bounds = [[0, 0], [h, w]];

        mcMap = L.map('mcMapCanvas', {
            crs: L.CRS.Simple,
            minZoom: -2,
            maxZoom: 4,
            zoomSnap: 0.25
        });

        L.imageOverlay('maps.php?action=floorplan_img&id=' + MC_FLOORPLAN.id, bounds).addTo(mcMap);
        mcMap.fitBounds(bounds);
        layerGroupPlaces.addTo(mcMap);
        layerGroupMeasure.addTo(mcMap);
        renderPlacesLayer();
    } else {
        // WELT- & DEUTSCHLAND-ATLAS MODUS
        mcMap = L.map('mcMapCanvas', {
            center: [51.1657, 10.4515],
            zoom: 6,
            minZoom: 2,
            maxZoom: 18
        });

        L.control.scale({ metric: true, imperial: false, position: 'bottomleft' }).addTo(mcMap);

        layerGroupBaseVector.addTo(mcMap);
        layerGroupInfra.addTo(mcMap);
        layerGroupTracks.addTo(mcMap);
        layerGroupCities.addTo(mcMap);
        layerGroupContacts.addTo(mcMap);
        layerGroupCalendar.addTo(mcMap);
        layerGroupPhotos.addTo(mcMap);
        layerGroupPlaces.addTo(mcMap);
        layerGroupPoiSearch.addTo(mcMap);
        layerGroupSecurityMap.addTo(mcMap);
        layerGroupRainWeather.addTo(mcMap);
        layerGroupEvCharging.addTo(mcMap);
        layerGroupMeasure.addTo(mcMap);

        switchMapBaseStyle(mcAirGapOnly ? 'intranet_vector' : 'osm');
        const styleSel = document.getElementById('mapBaseStyleSelect');
        if (styleSel) {
            styleSel.value = mcAirGapOnly ? 'intranet_vector' : 'osm';
        }

        renderOfflineVectorAtlas();
        renderPlacesLayer();
        renderTracksLayer();
        renderCloudLayers();

        if (new URLSearchParams(window.location.search).get('ev') === '1') {
            setTimeout(() => toggleEvChargingLayer(true), 200);
        }
    }

    mcMap.on('mousemove', function(e) {
        const el = document.getElementById('liveCursorCoords');
        if (!el) return;
        if (MC_FLOORPLAN) {
            el.textContent = `Plan-Y: ${Math.round(e.latlng.lat)} px · Plan-X: ${Math.round(e.latlng.lng)} px`;
        } else {
            el.textContent = `${e.latlng.lat.toFixed(4)}° N, ${e.latlng.lng.toFixed(4)}° E`;
        }
    });

    mcMap.on('zoomend', function() {
        const zb = document.getElementById('liveZoomBadge');
        if (zb) zb.textContent = 'Zoom ' + mcMap.getZoom();
        if (!MC_FLOORPLAN) {
            renderOfflineVectorAtlas();
        }
    });

    mcMap.on('moveend', function() {
        if (!MC_FLOORPLAN && typeof evChargingLayerActive !== 'undefined' && evChargingLayerActive) {
            scheduleEvChargingReload();
        }
    });

    mcMap.on('contextmenu', function(e) {
        openNewPlaceModal(e.latlng.lat, e.latlng.lng);
    });

    mcMap.on('click', function(e) {
        if (measureActive) {
            addMeasurePoint(e.latlng);
        }
    });
}

function switchMapBaseStyle(style) {
    if (!mcMap || MC_FLOORPLAN) return;
    if (mcTileLayer) {
        mcMap.removeLayer(mcTileLayer);
        mcTileLayer = null;
    }
    if (mcPmtilesLayer) {
        mcMap.removeLayer(mcPmtilesLayer);
        mcPmtilesLayer = null;
    }

    const tileStyleParam = (style === 'intranet_dark') ? 'intranet_dark' : (style === 'topo' ? 'topo' : 'osm');
    const airParam = (style === 'intranet_vector' || style === 'intranet_dark' || mcAirGapOnly) ? '1' : '0';

    mcTileLayer = L.tileLayer(
        `maps.php?action=tile&style=${encodeURIComponent(tileStyleParam)}&airgap=${airParam}&z={z}&x={x}&y={y}`,
        {
            maxZoom: 18,
            maxNativeZoom: mcMaxZoomDetail,
            attribution: 'MiniCloud Intranet-Atlas & Offline-Tile-Server'
        }
    );
    mcTileLayer.addTo(mcMap);
    mcTileLayer.bringToBack();

    // Wenn das 7,2 GB Deutschland-Vektor-Archiv (germany_detail.pmtiles, 1,16 Mio. Kacheln bis Hausnummern-Ebene) installiert ist:
    if (mcHasGermanyPmtiles && typeof protomapsL !== 'undefined' && protomapsL.leafletLayer) {
        const isDarkTheme = document.documentElement.classList.contains('dark');
        let pmFlavor = 'light';
        if (style === 'intranet_dark') {
            pmFlavor = 'dark';
        } else if (style === 'topo') {
            pmFlavor = 'grayscale';
        } else if (style === 'intranet_vector' && isDarkTheme) {
            pmFlavor = 'dark';
        }
        mcPmtilesLayer = protomapsL.leafletLayer({
            url: 'assets/maps/germany_detail.pmtiles',
            flavor: pmFlavor,
            lang: 'de',
            maxDataZoom: 15,
            maxZoom: 18,
            attribution: '🇩🇪 Offline-Deutschland-Vollkarte (1,16 Mio. Vektorkacheln, Zoom 0–18) © OpenStreetMap'
        });
        mcPmtilesLayer.addTo(mcMap);
    }
}

let mcGeoJsonEurope = null;
let mcGeoJsonStates = null;
let mcGeoJsonKreise = null;
let mcGeoJsonLoading = false;

async function loadHighResOfflineGeoJson() {
    if (mcGeoJsonLoading || MC_FLOORPLAN) return;
    mcGeoJsonLoading = true;
    try {
        const [resEu, resSt, resKr] = await Promise.all([
            fetch('assets/maps/europe.geojson').then(r => r.ok ? r.json() : null).catch(() => null),
            fetch('assets/maps/de_bundeslaender.geojson').then(r => r.ok ? r.json() : null).catch(() => null),
            fetch('assets/maps/de_landkreise.geojson').then(r => r.ok ? r.json() : null).catch(() => null)
        ]);
        mcGeoJsonEurope = resEu;
        mcGeoJsonStates = resSt;
        mcGeoJsonKreise = resKr;
        renderOfflineVectorAtlas();
    } catch (e) {}
}

function renderOfflineVectorAtlas() {
    if (!mcMap || MC_FLOORPLAN) return;
    if (!mcGeoJsonLoading) {
        loadHighResOfflineGeoJson();
    }
    layerGroupBaseVector.clearLayers();
    layerGroupInfra.clearLayers();
    layerGroupCities.clearLayers();

    const isDark = document.documentElement.classList.contains('dark');
    const baseFillOpacity = mcHasGermanyPmtiles ? 0.0 : (mcAirGapOnly ? 0.55 : 0.06);

    // 0. Europäische Nachbarländer (Hochauflösendes GeoJSON)
    if (mcGeoJsonEurope && !mcHasGermanyPmtiles) {
        L.geoJSON(mcGeoJsonEurope, {
            style: {
                color: isDark ? '#334155' : '#94a3b8',
                weight: 1.0,
                fillColor: isDark ? '#0f172a' : '#f1f5f9',
                fillOpacity: mcAirGapOnly ? 0.35 : 0.03
            },
            interactive: false
        }).addTo(layerGroupBaseVector);
    }

    // 1. Deutschland-Fläche & Grenze (Hochauflösend über de_bundeslaender oder Fallback-Polygon)
    if (mcGeoJsonStates) {
        L.geoJSON(mcGeoJsonStates, {
            style: {
                color: isDark ? '#38bdf8' : '#0284c7',
                weight: mcMap.getZoom() >= 11 ? 1.0 : (mcCurrentDetailLvl >= 2 ? 1.8 : 2.2),
                fillColor: isDark ? '#1e293b' : '#f8fafc',
                fillOpacity: baseFillOpacity
            },
            interactive: false
        }).addTo(layerGroupBaseVector);
    } else {
        L.polygon(OFFLINE_GERMANY_POLY, {
            color: isDark ? '#38bdf8' : '#0284c7',
            weight: 2.2,
            fillColor: isDark ? '#1e293b' : '#f8fafc',
            fillOpacity: baseFillOpacity,
            interactive: false
        }).addTo(layerGroupBaseVector);
    }

    // 2. Bundesländer-Namen (ab Detailgrad 2) & alle 400 Landkreise (ab Detailgrad 4)
    if (mcCurrentDetailLvl >= 2) {
        OFFLINE_BUNDESLAENDER.forEach(bl => {
            if (!mcGeoJsonStates) {
                L.polygon(bl.poly, {
                    color: isDark ? '#475569' : '#94a3b8',
                    weight: 1.2,
                    dashArray: '4,4',
                    fillOpacity: 0.02,
                    interactive: false
                }).addTo(layerGroupBaseVector);
            }

            if (mcMap.getZoom() <= 8) {
                L.marker([bl.lat, bl.lng], {
                    interactive: false,
                    icon: L.divIcon({
                        className: 'mc-state-label',
                        html: bl.name,
                        iconSize: [120, 18],
                        iconAnchor: [60, 9]
                    })
                }).addTo(layerGroupBaseVector);
            }
        });
    }

    // 2b. Alle 400 deutschen Landkreise & kreisfreien Städte (Hochauflösendes GeoJSON ab Detailgrad 4)
    if (mcCurrentDetailLvl >= 4 && mcGeoJsonKreise) {
        L.geoJSON(mcGeoJsonKreise, {
            style: {
                color: isDark ? '#475569' : '#64748b',
                weight: mcMap.getZoom() >= 9 ? 1.2 : 0.65,
                dashArray: '3,3',
                fillColor: '#3b82f6',
                fillOpacity: 0.01
            },
            onEachFeature: function(feature, layer) {
                const p = feature.properties || {};
                const kName = p.NAME_3 || p.GEN || p.name || '';
                const kType = p.TYPE_3 || p.BEZ || '';
                const bLand = p.NAME_1 || '';
                if (kName) {
                    layer.bindTooltip(`🏛️ ${kType ? kType + ' ' : ''}${kName}${bLand ? ' (' + bLand + ')' : ''}`, { sticky: true });
                }
            }
        }).addTo(layerGroupBaseVector);
    }

    // 3. Flüsse (ab Detailgrad 2) & Autobahnen (ab Detailgrad 3) – nur wenn kein Voll-PMTiles aktiv oder im Übersichts-Zoom <= 8
    if (mcCurrentDetailLvl >= 2 && (!mcHasGermanyPmtiles || mcMap.getZoom() <= 7)) {
        OFFLINE_RIVERS.forEach(rv => {
            L.polyline(rv.pts, {
                color: '#0ea5e9',
                weight: 2.2,
                opacity: 0.75
            }).bindTooltip('🌊 ' + rv.name).addTo(layerGroupInfra);
        });
    }
    if (mcCurrentDetailLvl >= 3 && (!mcHasGermanyPmtiles || mcMap.getZoom() <= 7)) {
        OFFLINE_AUTOBAHNEN.forEach(ab => {
            L.polyline(ab.pts, {
                color: '#f59e0b',
                weight: 2.4,
                opacity: 0.8,
                dashArray: '6,3'
            }).bindTooltip('🛣️ Autobahn ' + ab.name).addTo(layerGroupInfra);
        });
    }

    // 4. Städte gemäß gewähltem Detailgrad (Stufe 1 bis 5)
    MC_GAZETTEER.forEach(city => {
        if (city.lvl > mcCurrentDetailLvl) return;
        const radius = city.lvl === 1 ? 5.5 : (city.lvl === 2 ? 4.2 : 3.2);
        const col = city.lvl === 1 ? '#ef4444' : (city.lvl === 2 ? '#2563eb' : '#0f766e');

        const dot = L.circleMarker([city.lat, city.lng], {
            radius: radius,
            color: '#ffffff',
            weight: 1.5,
            fillColor: col,
            fillOpacity: 0.92
        });

        dot.bindPopup(`
            <div class="text-xs space-y-1">
                <p class="font-extrabold text-sm">🏙️ ${escapeMapHtml(city.name)}</p>
                <p class="text-slate-500">PLZ ${escapeMapHtml(city.zip)} · ${escapeMapHtml(city.state)}</p>
                <p class="font-mono text-[11px]">${city.lat.toFixed(4)}° N, ${city.lng.toFixed(4)}° E</p>
                <button type="button" onclick="openNewPlaceModal(${city.lat}, ${city.lng}, '${escapeMapHtml(city.name)}')" class="mt-1 px-2.5 py-1 rounded-lg bg-brand-600 text-white font-bold cursor-pointer">
                    📍 Hier Ort speichern
                </button>
            </div>
        `);
        dot.addTo(layerGroupCities);

        if (city.lvl <= 2 || mcMap.getZoom() >= 8 || mcCurrentDetailLvl === 5) {
            L.marker([city.lat, city.lng], {
                interactive: false,
                icon: L.divIcon({
                    className: '',
                    html: `<span class="mc-city-label">${escapeMapHtml(city.name)}</span>`,
                    iconAnchor: [-8, 10]
                })
            }).addTo(layerGroupCities);
        }
    });
}

function renderPlacesLayer() {
    layerGroupPlaces.clearLayers();
    mcPlaceMarkersById = {};
    MC_PLACES.forEach(pl => {
        if (pl.min_lvl > mcCurrentDetailLvl) return;
        const marker = L.marker([pl.lat, pl.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:${escapeMapHtml(pl.color)}">${escapeMapHtml(pl.icon)}</div>`,
                iconSize: [32, 32],
                iconAnchor: [16, 16],
                popupAnchor: [0, -14]
            })
        });
        marker.bindPopup(`
            <div class="text-xs space-y-1 min-w-[180px]">
                <p class="font-extrabold text-sm">${escapeMapHtml(pl.icon)} ${escapeMapHtml(pl.title)}</p>
                ${pl.address ? `<p class="text-slate-600">📫 ${escapeMapHtml(pl.address)}</p>` : ''}
                ${pl.description ? `<p class="text-slate-500">${escapeMapHtml(pl.description)}</p>` : ''}
                <p class="font-mono text-[10px] text-slate-400">${pl.lat.toFixed(5)}, ${pl.lng.toFixed(5)}</p>
            </div>
        `);
        marker.addTo(layerGroupPlaces);
        mcPlaceMarkersById[pl.id] = marker;
    });
}

function renderTracksLayer() {
    layerGroupTracks.clearLayers();
    MC_TRACKS.forEach((tr, idx) => {
        if (!Array.isArray(tr.points) || tr.points.length < 2) return;
        const latlngs = tr.points.map(p => [p.lat, p.lng]);
        const poly = L.polyline(latlngs, {
            color: tr.color || '#10b981',
            weight: 4,
            opacity: 0.9
        });
        poly.bindPopup(`
            <div class="text-xs space-y-1">
                <p class="font-extrabold text-sm">🥾 ${escapeMapHtml(tr.title)}</p>
                <p>📏 Distanz: <strong>${tr.distance_km.toFixed(2)} km</strong></p>
                <p>⛰️ Aufstieg: +${tr.ascent_m} m · Abstieg: -${tr.descent_m} m</p>
                <button type="button" onclick="drawTrackElevation(${idx})" class="mt-1 px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-bold cursor-pointer">
                    📈 Höhenprofil anzeigen
                </button>
            </div>
        `);
        poly.on('click', () => drawTrackElevation(idx));
        poly.addTo(layerGroupTracks);
    });
}

function renderCloudLayers() {
    layerGroupContacts.clearLayers();
    layerGroupCalendar.clearLayers();
    layerGroupPhotos.clearLayers();

    MC_CONTACTS.forEach(ct => {
        L.marker([ct.lat, ct.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:#0284c7">📇</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            })
        }).bindPopup(`
            <div class="text-xs space-y-1">
                <p class="font-extrabold">📇 ${escapeMapHtml(ct.name)}</p>
                ${ct.company ? `<p class="text-slate-500">🏢 ${escapeMapHtml(ct.company)}</p>` : ''}
                <p>📫 ${escapeMapHtml(ct.address)}</p>
                <a href="contacts.php" class="text-brand-600 font-bold hover:underline">Im Adressbuch öffnen &rarr;</a>
            </div>
        `).addTo(layerGroupContacts);
    });

    MC_CALENDAR.forEach(ev => {
        L.marker([ev.lat, ev.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:#d97706">📅</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            })
        }).bindPopup(`
            <div class="text-xs space-y-1">
                <p class="font-extrabold">📅 ${escapeMapHtml(ev.title)}</p>
                <p>🕒 ${escapeMapHtml(ev.date)} ${escapeMapHtml(ev.time)}</p>
                <p>📍 ${escapeMapHtml(ev.location)}</p>
                <a href="calendar.php" class="text-brand-600 font-bold hover:underline">Im Kalender öffnen &rarr;</a>
            </div>
        `).addTo(layerGroupCalendar);
    });

    MC_PHOTOS.forEach(ph => {
        L.marker([ph.lat, ph.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:#9333ea">📸</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            })
        }).bindPopup(`
            <div class="text-xs space-y-1.5">
                <p class="font-extrabold">📸 ${escapeMapHtml(ph.name)}</p>
                <img src="${escapeMapHtml(ph.thumb)}" class="w-40 h-28 object-cover rounded-lg border" alt="">
                <p class="text-[11px] text-slate-500">Aufgenommen: ${escapeMapHtml(ph.date)}</p>
            </div>
        `).addTo(layerGroupPhotos);
    });
}

// =========================================================================
// 9. INTERAKTIVE FUNKTIONEN: DETAILGRAD, MESSUNG, HÖHENPROFIL & OFFLINE-SUCHE
// =========================================================================
function onDetailLevelChange(val) {
    mcCurrentDetailLvl = Math.max(1, Math.min(5, Number(val) || 4));
    const badge = document.getElementById('mapDetailBadge');
    if (badge) badge.textContent = `Stufe ${mcCurrentDetailLvl} / 5`;
    const sideLbl = document.getElementById('sideDetailLabel');
    if (sideLbl) sideLbl.textContent = `Stufe ${mcCurrentDetailLvl} von 5`;
    renderOfflineVectorAtlas();
    renderPlacesLayer();
}

function toggleAirGapModeLive() {
    mcAirGapOnly = !mcAirGapOnly;
    const badge = document.getElementById('airGapStatusBadge');
    const icon  = document.getElementById('airGapBadgeIcon');
    const txt   = document.getElementById('airGapBadgeText');
    if (icon) icon.textContent = mcAirGapOnly ? '🔒' : '🛡️';
    if (txt)  txt.textContent  = mcAirGapOnly ? '100 % Intranet Air-Gapped (Null Außenverkehr)' : 'Lokaler Tile-Cache + Server-Proxy';
    if (badge) {
        badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold cursor-pointer transition-colors ' + (
            mcAirGapOnly
                ? 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800'
                : 'bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-slate-200 border border-sky-300 dark:border-sky-800'
        );
    }

    const fd = new URLSearchParams();
    fd.set('action', 'save_map_config');
    fd.set('ajax', '1');
    fd.set('csrf_token', MC_MAP_CSRF);
    fd.set('airgap_only', mcAirGapOnly ? '1' : '0');
    fd.set('default_detail', String(mcCurrentDetailLvl));
    fd.set('max_zoom_detail', String(mcMaxZoomDetail));
    fetch('maps.php', { method: 'POST', body: fd }).catch(() => {});

    const sel = document.getElementById('mapBaseStyleSelect');
    switchMapBaseStyle(sel ? sel.value : 'intranet_vector');
    renderOfflineVectorAtlas();
}

function syncMapLayersVisibility() {
    if (!mcMap) return;
    const toggle = (id, grp) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.checked) {
            if (!mcMap.hasLayer(grp)) mcMap.addLayer(grp);
        } else {
            if (mcMap.hasLayer(grp)) mcMap.removeLayer(grp);
        }
    };
    toggle('chkLayerPlaces', layerGroupPlaces);
    toggle('chkLayerCities', layerGroupCities);
    toggle('chkLayerInfra', layerGroupInfra);
    toggle('chkLayerTracks', layerGroupTracks);
    toggle('chkLayerContacts', layerGroupContacts);
    toggle('chkLayerCalendar', layerGroupCalendar);
    toggle('chkLayerPhotos', layerGroupPhotos);
}

// Mess- & Routen-Werkzeug
let measureActive = false;
let measurePoints = [];

function toggleMeasureTool() {
    measureActive = !measureActive;
    const bar = document.getElementById('measureInfoBar');
    const lbl = document.getElementById('measureBtnLabel');
    if (bar) bar.classList.toggle('hidden', !measureActive);
    if (lbl) lbl.textContent = measureActive ? 'Messung aktiv' : 'Distanz & Route messen';
    if (!measureActive) {
        clearMeasurements();
    }
}

function clearMeasurements() {
    measurePoints = [];
    layerGroupMeasure.clearLayers();
    const out = document.getElementById('measureStatsOutput');
    if (out) out.textContent = '0,00 km · 🚗 0 Min. · 🚲 0 Min. · 🚶 0 Min.';
}

function addMeasurePoint(latlng) {
    measurePoints.push(latlng);
    layerGroupMeasure.clearLayers();
    measurePoints.forEach((pt, i) => {
        L.circleMarker(pt, { radius: 5, color: '#d97706', fillColor: '#fbbf24', fillOpacity: 1 })
            .bindTooltip('Punkt ' + (i + 1))
            .addTo(layerGroupMeasure);
    });
    if (measurePoints.length >= 2) {
        L.polyline(measurePoints, { color: '#d97706', weight: 3.5, dashArray: '6,4' }).addTo(layerGroupMeasure);
        let distMeters = 0;
        for (let i = 1; i < measurePoints.length; i++) {
            distMeters += mcMap.distance(measurePoints[i - 1], measurePoints[i]);
        }
        const km = distMeters / 1000.0;
        const carMin  = Math.round((km / 65.0) * 60);
        const bikeMin = Math.round((km / 18.0) * 60);
        const walkMin = Math.round((km / 4.8) * 60);
        const out = document.getElementById('measureStatsOutput');
        if (out) {
            out.textContent = `${km.toFixed(2).replace('.', ',')} km · 🚗 ~${carMin} Min. · 🚲 ~${bikeMin} Min. · 🚶 ~${walkMin} Min.`;
        }
    }
}

function drawQuickRadiusPrompt() {
    if (!mcMap || MC_FLOORPLAN) return;
    const val = prompt('Umkreis-Radius in Kilometern (km) um die aktuelle Kartenmitte eingeben:', '10');
    if (!val) return;
    const km = parseFloat(val.replace(',', '.'));
    if (isNaN(km) || km <= 0) return;
    const center = mcMap.getCenter();
    L.circle(center, {
        radius: km * 1000,
        color: '#2563eb',
        weight: 2,
        fillColor: '#3b82f6',
        fillOpacity: 0.12
    }).bindPopup(`⭕ Umkreis-Radius: <strong>${km} km</strong>`).addTo(layerGroupMeasure).openPopup();
}

// Offline-Suche über alle 23.296 deutschen PLZ/Orte/Landkreise, eigene Orte, Kontakte & Koordinaten
let offlineSearchTimer = null;
function onOfflineMapSearch(query) {
    const box = document.getElementById('offlineMapSearchResults');
    if (!box) return;
    const q = (query || '').trim().toLowerCase();
    if (q.length < 2) {
        box.classList.add('hidden');
        box.innerHTML = '';
        return;
    }

    const hits = [];

    // 1. Direkte Koordinaten-Eingabe (z.B. "51.16, 10.45")
    const coordMatch = q.match(/^(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)$/);
    if (coordMatch) {
        const lat = parseFloat(coordMatch[1].replace(',', '.'));
        const lng = parseFloat(coordMatch[2].replace(',', '.'));
        if (lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
            hits.push({ icon: '🧭', title: `Koordinate ${lat.toFixed(4)}, ${lng.toFixed(4)}`, sub: 'Direkt-Sprung', lat, lng, zoom: 13 });
        }
    }

    // 2. Eigene gespeicherte Orte
    MC_PLACES.forEach(p => {
        if (p.title.toLowerCase().includes(q) || (p.address && p.address.toLowerCase().includes(q))) {
            hits.push({ icon: p.icon, title: p.title, sub: p.address || 'Eigener Standort', lat: p.lat, lng: p.lng, zoom: 14 });
        }
    });

    // 3. Kontakte
    MC_CONTACTS.forEach(ct => {
        if (ct.name.toLowerCase().includes(q) || ct.address.toLowerCase().includes(q)) {
            hits.push({ icon: '📇', title: ct.name, sub: ct.address, lat: ct.lat, lng: ct.lng, zoom: 13 });
        }
    });

    // 4. Vollständiges deutsches Offline-Orts- & PLZ-Verzeichnis (23.296 Einträge) abfragen
    if (offlineSearchTimer) clearTimeout(offlineSearchTimer);
    offlineSearchTimer = setTimeout(async () => {
        try {
            const res = await fetch('maps.php?action=offline_search&q=' + encodeURIComponent(q));
            const d   = await res.json();
            if (d && d.ok && Array.isArray(d.results)) {
                d.results.forEach(r => {
                    hits.push({
                        icon: '🏙️',
                        title: `${r.name} (${r.zip})`,
                        sub: `${r.kreis ? r.kreis + ' · ' : ''}${r.state}`,
                        lat: r.lat,
                        lng: r.lng,
                        zoom: 12
                    });
                });
            }
        } catch (e) {}

        if (hits.length === 0) {
            box.innerHTML = `<div class="p-3 text-xs text-slate-500">Kein Offline-Treffer für „${escapeMapHtml(query)}“.</div>`;
            box.classList.remove('hidden');
            return;
        }

        box.innerHTML = hits.slice(0, 16).map(h => `
            <button type="button" onclick="flyToMapCoord(${h.lat}, ${h.lng}, ${h.zoom}); document.getElementById('offlineMapSearchResults').classList.add('hidden');" class="w-full text-left px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-800 flex items-center justify-between gap-2 text-xs cursor-pointer">
                <span class="font-bold text-slate-900 dark:text-white truncate">${escapeMapHtml(h.icon)} ${escapeMapHtml(h.title)}</span>
                <span class="text-[11px] text-slate-400 truncate shrink-0">${escapeMapHtml(h.sub)}</span>
            </button>
        `).join('');
        box.classList.remove('hidden');
    }, 90);
}

function flyToMapCoord(lat, lng, zoom = 12) {
    if (!mcMap) return;
    mcMap.setView([lat, lng], zoom, { animate: true });
}

function flyToQuickRegion(val) {
    if (!val || !mcMap) return;
    const p = val.split(',').map(Number);
    if (p.length === 3) {
        mcMap.setView([p[0], p[1]], p[2], { animate: true });
    }
}

function focusPlaceOnMap(lat, lng, id) {
    if (!mcMap) return;
    mcMap.setView([lat, lng], MC_FLOORPLAN ? 1 : 13, { animate: true });
    if (mcPlaceMarkersById[id]) {
        mcPlaceMarkersById[id].openPopup();
    }
}

function focusTrackByIndex(idx) {
    const tr = MC_TRACKS[idx];
    if (!tr || !Array.isArray(tr.points) || tr.points.length < 2 || !mcMap) return;
    const bounds = L.latLngBounds(tr.points.map(p => [p.lat, p.lng]));
    mcMap.fitBounds(bounds, { padding: [30, 30] });
    drawTrackElevation(idx);
}

function drawTrackElevation(idx) {
    const tr = MC_TRACKS[idx];
    const drawer = document.getElementById('elevationDrawer');
    const canvas = document.getElementById('elevationCanvas');
    if (!tr || !drawer || !canvas) return;
    drawer.classList.remove('hidden');
    document.getElementById('elevationTrackTitle').textContent = '🥾 ' + tr.title;
    document.getElementById('elevationTrackStats').textContent = `${tr.distance_km.toFixed(2)} km · +${tr.ascent_m} m / -${tr.descent_m} m`;

    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.clientWidth || 600;
    const h = canvas.height = 90;
    ctx.clearRect(0, 0, w, h);

    const eles = tr.points.map(p => p.ele !== null ? Number(p.ele) : 0);
    const minE = Math.min(...eles);
    const maxE = Math.max(...eles, minE + 10);

    ctx.beginPath();
    ctx.moveTo(0, h);
    eles.forEach((eVal, i) => {
        const x = (i / Math.max(1, eles.length - 1)) * w;
        const y = h - 10 - ((eVal - minE) / (maxE - minE)) * (h - 22);
        ctx.lineTo(x, y);
    });
    ctx.lineTo(w, h);
    ctx.closePath();
    ctx.fillStyle = 'rgba(16, 185, 129, 0.25)';
    ctx.fill();

    ctx.beginPath();
    eles.forEach((eVal, i) => {
        const x = (i / Math.max(1, eles.length - 1)) * w;
        const y = h - 10 - ((eVal - minE) / (maxE - minE)) * (h - 22);
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = tr.color || '#10b981';
    ctx.lineWidth = 2.2;
    ctx.stroke();
}

function switchSidebarTab(tab) {
    ['places', 'poi', 'security', 'tracks', 'cloud'].forEach(t => {
        const cap = t.charAt(0).toUpperCase() + t.slice(1);
        const box = document.getElementById('sideTab' + cap);
        const btn = document.getElementById('tabBtn' + cap);
        if (box) box.classList.toggle('hidden', t !== tab);
        if (btn) {
            btn.className = 'py-1.5 rounded-lg cursor-pointer truncate ' + (
                t === tab ? 'bg-brand-600 text-white' : 'text-slate-600 dark:text-slate-300'
            );
        }
    });
}

// =========================================================================
// 10. LIVE-REGENRADAR & WETTER-OVERLAY (RAINVIEWER + OPEN-METEO PROXY)
// =========================================================================
let rainRadarActive      = false;
let rainRadarFrames      = [];
let rainRadarCities      = [];
let rainRadarCurrentIdx  = 0;
let rainRadarTileLayer   = null;
let rainRadarAnimTimer   = null;
let rainRadarOpacity     = 0.72;
let rainRadarShowPins    = true;

async function toggleRainRadar() {
    if (!mcMap || MC_FLOORPLAN) return;
    rainRadarActive = !rainRadarActive;
    const bar = document.getElementById('rainRadarControlBar');
    const btn = document.getElementById('btnRainRadar');
    const lbl = document.getElementById('btnRainRadarLabel');

    if (bar) bar.classList.toggle('hidden', !rainRadarActive);
    if (lbl) lbl.textContent = rainRadarActive ? 'Regenradar aktiv' : 'Regenradar';
    if (btn) {
        btn.className = rainRadarActive
            ? 'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-sky-600 text-white shadow-xs transition-colors cursor-pointer'
            : 'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-sky-50 dark:bg-sky-950/70 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800/80 transition-colors cursor-pointer';
    }

    if (!rainRadarActive) {
        stopRainRadarAnimation();
        if (rainRadarTileLayer && mcMap.hasLayer(rainRadarTileLayer)) {
            mcMap.removeLayer(rainRadarTileLayer);
        }
        rainRadarTileLayer = null;
        layerGroupRainWeather.clearLayers();
        return;
    }

    await loadRainRadarMeta();
}

async function loadRainRadarMeta() {
    const badge = document.getElementById('radarTimeBadge');
    if (badge) badge.textContent = '⏳ Lade Radar-Daten…';
    try {
        const res = await fetch('maps.php?action=rain_radar_meta');
        const d   = await res.json();
        if (d && Array.isArray(d.frames) && d.frames.length > 0) {
            rainRadarFrames = d.frames;
            rainRadarCities = Array.isArray(d.cities) ? d.cities : [];
            const slider = document.getElementById('radarTimeSlider');
            // Standardmäßig das aktuellste Live-Radarbild (letztes Nicht-Prognose-Bild) wählen
            let liveIdx = rainRadarFrames.length - 1;
            for (let i = 0; i < rainRadarFrames.length; i++) {
                if (!rainRadarFrames[i].forecast) {
                    liveIdx = i;
                }
            }
            rainRadarCurrentIdx = liveIdx;
            if (slider) {
                slider.min   = '0';
                slider.max   = String(rainRadarFrames.length - 1);
                slider.value = String(rainRadarCurrentIdx);
            }
            showRadarFrame(rainRadarCurrentIdx);
            renderRadarWeatherPins();
        } else {
            if (badge) badge.textContent = '⚠️ Keine Radar-Daten verfügbar';
        }
    } catch (e) {
        if (badge) badge.textContent = '⚠️ Radar-Verbindungsfehler';
    }
}

function showRadarFrame(idx) {
    if (!mcMap || !rainRadarFrames.length) return;
    const clamped = Math.max(0, Math.min(rainRadarFrames.length - 1, Number(idx) || 0));
    rainRadarCurrentIdx = clamped;
    const frame = rainRadarFrames[clamped];
    const slider = document.getElementById('radarTimeSlider');
    const badge  = document.getElementById('radarTimeBadge');
    if (slider) slider.value = String(clamped);
    if (badge) {
        badge.textContent = (frame.forecast ? '🔮 ' : '📡 ') + frame.label;
        badge.className = 'px-2 py-0.5 rounded-lg border font-bold ' + (
            frame.forecast
                ? 'bg-amber-50 dark:bg-amber-950/80 border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-300'
                : 'bg-white dark:bg-slate-800 border-sky-200 dark:border-sky-700 text-sky-800 dark:text-sky-300'
        );
    }

    const nextLayer = L.tileLayer(
        `maps.php?action=rain_radar_tile&path=${encodeURIComponent(frame.path)}&z={z}&x={x}&y={y}`,
        {
            opacity: rainRadarOpacity,
            maxNativeZoom: 7,
            maxZoom: 18,
            zIndex: 450,
            attribution: '⛈️ Regenradar via MiniCloud Radar-Proxy (RainViewer)'
        }
    );
    nextLayer.addTo(mcMap);
    if (rainRadarTileLayer && mcMap.hasLayer(rainRadarTileLayer)) {
        mcMap.removeLayer(rainRadarTileLayer);
    }
    rainRadarTileLayer = nextLayer;
}

function onRadarFrameSlide(val) {
    stopRainRadarAnimation();
    showRadarFrame(Number(val));
}

function onRadarOpacityChange(val) {
    rainRadarOpacity = Math.max(0.2, Math.min(0.95, (Number(val) || 72) / 100.0));
    if (rainRadarTileLayer) {
        rainRadarTileLayer.setOpacity(rainRadarOpacity);
    }
}

function toggleRainRadarAnimation() {
    if (rainRadarAnimTimer) {
        stopRainRadarAnimation();
        return;
    }
    if (!rainRadarFrames.length) return;
    const btn = document.getElementById('btnRadarPlayPause');
    if (btn) btn.textContent = '⏸️ Pause';
    rainRadarAnimTimer = setInterval(() => {
        const nextIdx = (rainRadarCurrentIdx + 1) % rainRadarFrames.length;
        showRadarFrame(nextIdx);
    }, 850);
}

function stopRainRadarAnimation() {
    if (rainRadarAnimTimer) {
        clearInterval(rainRadarAnimTimer);
        rainRadarAnimTimer = null;
    }
    const btn = document.getElementById('btnRadarPlayPause');
    if (btn) btn.textContent = '▶️ Abspielen';
}

function toggleRadarWeatherPins(checked) {
    rainRadarShowPins = Boolean(checked);
    renderRadarWeatherPins();
}

function renderRadarWeatherPins() {
    layerGroupRainWeather.clearLayers();
    if (!rainRadarActive || !rainRadarShowPins || !Array.isArray(rainRadarCities)) return;

    rainRadarCities.forEach(st => {
        const tempStr = st.temp !== null ? `${st.temp.toFixed(1).replace('.', ',')} °C` : '';
        const marker = L.marker([st.lat, st.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="px-2 py-0.5 rounded-full bg-slate-900/90 text-white border border-sky-400/70 shadow-md text-[11px] font-bold whitespace-nowrap flex items-center gap-1">
                    <span>${escapeMapHtml(st.icon)}</span>
                    <span>${escapeMapHtml(tempStr)}</span>
                </div>`,
                iconAnchor: [34, 12]
            })
        });
        marker.bindPopup(`
            <div class="text-xs space-y-1 min-w-[165px]">
                <p class="font-extrabold text-sm">${escapeMapHtml(st.icon)} Wetter ${escapeMapHtml(st.name)}</p>
                <p>🌡️ Temperatur: <strong>${escapeMapHtml(tempStr)}</strong> (${escapeMapHtml(st.desc)})</p>
                <p>💨 Wind: <strong>${Number(st.wind || 0)} km/h</strong></p>
                <p>💧 Niederschlag aktuell: <strong>${Number(st.precip || 0).toFixed(1).replace('.', ',')} mm</strong></p>
            </div>
        `);
        marker.addTo(layerGroupRainWeather);
    });
}

// =========================================================================
// 11. UMKREIS-FINDER (TANKSTELLEN, E-LADESÄULEN, PARKPLÄTZE, BANKEN, APOTHEKEN)
// =========================================================================
let poiSearchRadiusKm = 5;
let poiSearchCustomOrigin = null; // null = aktuelle Kartenmitte, sonst {lat, lng}
let poiCurrentMarkers = [];
let poiLastResults = [];
let poiActiveCategory = 'fuel';

function openRadiusFinderPanel() {
    switchSidebarTab('poi');
    if (poiLastResults.length === 0) {
        runPoiRadiusSearch('fuel');
    }
}

function setPoiSearchRadius(km) {
    poiSearchRadiusKm = Number(km) || 5;
    [2, 5, 10, 25].forEach(r => {
        const btn = document.getElementById('poiRadBtn_' + r);
        if (btn) {
            btn.className = 'px-2 py-0.5 rounded-md text-[11px] font-bold cursor-pointer ' + (
                r === poiSearchRadiusKm ? 'bg-emerald-600 text-white' : 'text-slate-600 dark:text-slate-300'
            );
        }
    });
    if (poiActiveCategory) {
        runPoiRadiusSearch(poiActiveCategory);
    }
}

function useMyGpsForPoiSearch() {
    const statusEl = document.getElementById('poiSearchStatusText');
    if (!navigator.geolocation) {
        if (statusEl) statusEl.textContent = '⚠️ Browser unterstützt keine GPS-Ortung.';
        return;
    }
    if (statusEl) statusEl.textContent = '📡 Ermittle deinen GPS-Standort…';
    navigator.geolocation.getCurrentPosition(
        pos => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            poiSearchCustomOrigin = { lat, lng };
            if (mcMap) {
                mcMap.setView([lat, lng], 13, { animate: true });
            }
            if (statusEl) statusEl.textContent = `📡 GPS aktiv (${lat.toFixed(4)}, ${lng.toFixed(4)})`;
            runPoiRadiusSearch(poiActiveCategory || 'fuel', true);
        },
        () => {
            if (statusEl) statusEl.textContent = '⚠️ GPS-Freigabe abgelehnt – nutze aktuelle Kartenmitte.';
            poiSearchCustomOrigin = null;
            runPoiRadiusSearch(poiActiveCategory || 'fuel', false);
        },
        { enableHighAccuracy: true, timeout: 7000 }
    );
}

async function runPoiRadiusSearch(category, useStoredGps = false) {
    if (!mcMap || MC_FLOORPLAN) return;
    poiActiveCategory = category || 'fuel';

    ['fuel', 'charging', 'parking', 'atm', 'supermarket', 'pharmacy', 'aed', 'food'].forEach(cat => {
        const b = document.getElementById('poiCatBtn_' + cat);
        if (b) {
            b.className = 'px-2.5 py-1.5 rounded-xl font-bold text-left flex items-center gap-1.5 cursor-pointer border ' + (
                cat === poiActiveCategory
                    ? 'bg-emerald-600 text-white border-emerald-600 shadow-2xs'
                    : 'bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 border-slate-200 dark:border-slate-700'
            );
        }
    });

    const center = (useStoredGps && poiSearchCustomOrigin) ? poiSearchCustomOrigin : mcMap.getCenter();
    const statusEl = document.getElementById('poiSearchStatusText');
    const listEl   = document.getElementById('poiResultsList');

    if (statusEl) statusEl.textContent = `⏳ Suche im Umkreis von ${poiSearchRadiusKm} km…`;
    if (listEl) {
        listEl.innerHTML = `<div class="p-4 text-center text-xs text-slate-500">⏳ Durchsuche Umkreis (${poiSearchRadiusKm} km)…</div>`;
    }

    try {
        const url = `maps.php?action=poi_radius_search&lat=${encodeURIComponent(center.lat)}&lng=${encodeURIComponent(center.lng)}&radius=${encodeURIComponent(poiSearchRadiusKm)}&category=${encodeURIComponent(poiActiveCategory)}`;
        const res = await fetch(url);
        const d   = await res.json();

        layerGroupPoiSearch.clearLayers();
        poiCurrentMarkers = [];
        poiLastResults    = [];

        // Suchradius-Kreis und Zentrum auf der Karte einzeichnen
        const radiusCircle = L.circle([center.lat, center.lng], {
            radius: poiSearchRadiusKm * 1000,
            color: (d && d.color) ? d.color : '#10b981',
            weight: 2,
            dashArray: '6,4',
            fillColor: (d && d.color) ? d.color : '#10b981',
            fillOpacity: 0.07
        }).addTo(layerGroupPoiSearch);

        L.circleMarker([center.lat, center.lng], {
            radius: 5,
            color: '#ffffff',
            weight: 2,
            fillColor: '#0f172a',
            fillOpacity: 1
        }).bindTooltip('🎯 Suchmittelpunkt').addTo(layerGroupPoiSearch);

        if (!d || !d.ok || !Array.isArray(d.results) || d.results.length === 0) {
            if (statusEl) statusEl.textContent = `Keine Treffer im ${poiSearchRadiusKm}-km-Umkreis gefunden.`;
            if (listEl) {
                listEl.innerHTML = `
                    <div class="text-center py-5 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700">
                        <p class="text-xl mb-1">🔍</p>
                        <p class="text-xs font-bold text-slate-700 dark:text-slate-200">Keine Treffer im ${poiSearchRadiusKm}-km-Radius</p>
                        <p class="text-[11px] text-slate-500 mt-0.5">Wähle oben einen größeren Radius (z. B. 10 km oder 25 km) oder verschiebe den Kartenausschnitt.</p>
                    </div>`;
            }
            return;
        }

        poiLastResults = d.results;
        if (statusEl) {
            statusEl.textContent = `✅ ${d.results.length} ${d.label} im ${poiSearchRadiusKm}-km-Umkreis`;
        }

        d.results.forEach((item, idx) => {
            const marker = L.marker([item.lat, item.lng], {
                icon: L.divIcon({
                    className: '',
                    html: `<div class="mc-map-pin" style="background-color:${escapeMapHtml(item.color)}">${escapeMapHtml(item.icon)}</div>`,
                    iconSize: [32, 32],
                    iconAnchor: [16, 16],
                    popupAnchor: [0, -14]
                })
            });

            marker.bindPopup(`
                <div class="text-xs space-y-1.5 min-w-[200px]">
                    <p class="font-extrabold text-sm">${escapeMapHtml(item.icon)} ${escapeMapHtml(item.title)}</p>
                    <p class="font-bold text-emerald-600">📏 ${item.dist_km.toFixed(2).replace('.', ',')} km Richtung ${escapeMapHtml(item.direction)}</p>
                    ${item.address ? `<p class="text-slate-600">📫 ${escapeMapHtml(item.address)}</p>` : ''}
                    ${item.info ? `<p class="text-slate-500">${escapeMapHtml(item.info)}</p>` : ''}
                    ${item.phone ? `<p class="text-slate-500">📞 ${escapeMapHtml(item.phone)}</p>` : ''}
                    <div class="pt-1 flex items-center gap-1.5">
                        <button type="button" onclick="savePoiAsMyPlace(${idx})" class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-bold cursor-pointer">
                            💾 Als eigenen Ort speichern
                        </button>
                    </div>
                </div>
            `);
            marker.addTo(layerGroupPoiSearch);
            poiCurrentMarkers[idx] = marker;
        });

        mcMap.fitBounds(radiusCircle.getBounds(), { padding: [24, 24], maxZoom: 14 });

        if (listEl) {
            listEl.innerHTML = d.results.map((item, idx) => `
                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-slate-700/70 transition-colors">
                    <div class="flex items-start justify-between gap-2">
                        <button type="button" onclick="focusPoiResult(${idx})" class="text-left min-w-0 flex-1 cursor-pointer">
                            <div class="flex items-center gap-1.5">
                                <span>${escapeMapHtml(item.icon)}</span>
                                <p class="text-xs font-bold text-slate-900 dark:text-white truncate">${escapeMapHtml(item.title)}</p>
                            </div>
                            ${item.address ? `<p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">📫 ${escapeMapHtml(item.address)}</p>` : ''}
                            ${item.info ? `<p class="text-[10px] text-slate-400 truncate mt-0.5">${escapeMapHtml(item.info)}</p>` : ''}
                        </button>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="px-1.5 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 text-[10px] font-extrabold">
                                ${item.dist_km.toFixed(1).replace('.', ',')} km ${escapeMapHtml(item.direction)}
                            </span>
                            <button type="button" onclick="savePoiAsMyPlace(${idx})" class="text-[10px] font-bold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer" title="In meine eigenen Orte übernehmen">
                                💾 Speichern
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) {
        if (statusEl) statusEl.textContent = '⚠️ Fehler bei der Umkreis-Abfrage.';
    }
}

function focusPoiResult(idx) {
    const item = poiLastResults[idx];
    const marker = poiCurrentMarkers[idx];
    if (!item || !mcMap) return;
    mcMap.setView([item.lat, item.lng], 15, { animate: true });
    if (marker) marker.openPopup();
}

function clearPoiSearchResults() {
    layerGroupPoiSearch.clearLayers();
    poiCurrentMarkers = [];
    poiLastResults = [];
    const statusEl = document.getElementById('poiSearchStatusText');
    const listEl   = document.getElementById('poiResultsList');
    if (statusEl) statusEl.textContent = 'Umkreis-Markierungen ausgeblendet.';
    if (listEl) {
        listEl.innerHTML = `<div class="text-center py-5 px-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-dashed border-slate-200 dark:border-slate-700 text-xs text-slate-500">Wähle oben eine Kategorie aus.</div>`;
    }
}

function savePoiAsMyPlace(idx) {
    const item = poiLastResults[idx];
    if (!item) return;
    openNewPlaceModal(item.lat, item.lng, item.title);
    document.getElementById('placeFormAddress').value  = item.address || '';
    document.getElementById('placeFormDesc').value     = item.info || '';
    document.getElementById('placeFormCategory').value = item.place_cat || 'favorite';
    document.getElementById('placeFormIcon').value     = item.icon || '📍';
    document.getElementById('placeFormColor').value    = item.color || '#2563eb';
}

// =========================================================================
// 12. SICHERHEITS-LANDKARTE (GEO-IP LOGIN- & ANGRIFFS-HERKUNFT)
// =========================================================================
let securityMapData      = null;
let securityMapFilter    = 'all';
let securityMapMarkers   = [];

function openSecurityMapPanel() {
    switchSidebarTab('security');
    loadSecurityMapData(false);
}

async function loadSecurityMapData(forceReload = false) {
    if (!mcMap || MC_FLOORPLAN) return;
    if (securityMapData && !forceReload) {
        renderSecurityMapMarkers();
        return;
    }

    const listEl = document.getElementById('securityMapList');
    if (listEl) {
        listEl.innerHTML = `<div class="p-4 text-center text-xs text-slate-500">🛡️ Ermittle Geo-IP-Standorte aus dem Sicherheitsprotokoll…</div>`;
    }

    try {
        const res = await fetch('maps.php?action=security_map_data');
        const d   = await res.json();
        if (d && d.ok) {
            securityMapData = d;
            const sSucc = document.getElementById('secStatSuccess');
            const sFail = document.getElementById('secStatFailed');
            const sIps  = document.getElementById('secStatIps');
            if (sSucc) sSucc.textContent = String(d.stats.total_success || 0);
            if (sFail) sFail.textContent = String(d.stats.total_failed || 0);
            if (sIps)  sIps.textContent  = `${d.stats.unique_ips || 0} (${d.stats.countries || 1} L.)`;
            renderSecurityMapMarkers();
        }
    } catch (e) {
        if (listEl) {
            listEl.innerHTML = `<div class="p-3 text-xs text-rose-600">Fehler beim Laden der Sicherheits-Landkarte.</div>`;
        }
    }
}

function filterSecurityMap(mode) {
    securityMapFilter = mode || 'all';
    ['all', 'success', 'danger'].forEach(m => {
        const btn = document.getElementById('secFilter_' + m);
        if (btn) {
            btn.className = 'flex-1 py-1 rounded-md text-[11px] font-bold cursor-pointer ' + (
                m === securityMapFilter ? 'bg-rose-600 text-white' : 'text-slate-600 dark:text-slate-300'
            );
        }
    });
    renderSecurityMapMarkers();
}

function renderSecurityMapMarkers() {
    if (!mcMap || !securityMapData) return;
    layerGroupSecurityMap.clearLayers();
    securityMapMarkers = [];

    const srv = securityMapData.server || { lat: 50.1109, lng: 8.6821, host: 'runready.cloud', city: 'Rechenzentrum' };

    // 1. Zentraler MiniCloud-Server-Knoten (Zielpunkt aller Verbindungen)
    const srvMarker = L.marker([srv.lat, srv.lng], {
        icon: L.divIcon({
            className: '',
            html: `<div class="mc-map-pin" style="background-color:#4f46e5;width:36px;height:36px;font-size:17px;">🖥️</div>`,
            iconSize: [36, 36],
            iconAnchor: [18, 18]
        })
    });
    srvMarker.bindPopup(`
        <div class="text-xs space-y-1 min-w-[190px]">
            <p class="font-extrabold text-sm">🖥️ MiniCloud Server (${escapeMapHtml(srv.host)})</p>
            <p class="text-slate-600">📍 Standort: ${escapeMapHtml(srv.city)}, ${escapeMapHtml(srv.country || 'Deutschland')}</p>
            <p class="text-slate-500">🏢 Host: ${escapeMapHtml(srv.isp || '')}</p>
        </div>
    `);
    srvMarker.addTo(layerGroupSecurityMap);

    const allMarkers = Array.isArray(securityMapData.markers) ? securityMapData.markers : [];
    const filtered = allMarkers.filter(m => {
        if (securityMapFilter === 'success') return m.success_count > 0;
        if (securityMapFilter === 'danger')  return m.failed_count > 0;
        return true;
    });

    const boundsPts = [[srv.lat, srv.lng]];

    filtered.forEach((m, idx) => {
        const isDanger = m.status === 'danger';
        const isMixed  = m.status === 'mixed';
        const pinColor = isDanger ? '#e11d48' : (isMixed ? '#d97706' : '#10b981');
        const pinIcon  = isDanger ? '🚨' : (m.is_current ? '🟢' : (isMixed ? '⚠️' : '🛡️'));

        // Verbindungslinie zwischen Ursprungs-IP und MiniCloud-Server zeichnen
        L.polyline([[m.lat, m.lng], [srv.lat, srv.lng]], {
            color: pinColor,
            weight: isDanger ? 2.2 : 1.8,
            opacity: 0.65,
            dashArray: isDanger ? '4,6' : '6,4'
        }).addTo(layerGroupSecurityMap);

        const evHtml = (Array.isArray(m.events) ? m.events : []).map(ev => `
            <li class="border-t border-slate-100 pt-1">
                <span class="font-semibold">${escapeMapHtml(ev.time)}</span> ·
                <span class="font-bold">${escapeMapHtml(ev.user)}</span>:
                <span>${escapeMapHtml(ev.desc)}</span>
            </li>
        `).join('');

        const marker = L.marker([m.lat, m.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:${pinColor}">${pinIcon}</div>`,
                iconSize: [32, 32],
                iconAnchor: [16, 16],
                popupAnchor: [0, -14]
            })
        });

        marker.bindPopup(`
            <div class="text-xs space-y-1.5 min-w-[220px] max-w-[280px]">
                <div class="flex items-center justify-between gap-2">
                    <p class="font-extrabold text-sm">${pinIcon} ${escapeMapHtml(m.city)} (${escapeMapHtml(m.country_code)})</p>
                    ${m.is_current ? '<span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">Deine Sitzung</span>' : ''}
                </div>
                <p class="font-mono text-[11px] text-slate-600">🌐 IP-Netz: <strong>${escapeMapHtml(m.masked_ip)}</strong></p>
                <p class="text-slate-500">📡 Provider: ${escapeMapHtml(m.isp)}</p>
                <p class="text-slate-500">👤 Konten: <strong>${escapeMapHtml((m.users || []).join(', '))}</strong></p>
                <div class="flex items-center gap-2 pt-0.5">
                    <span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-bold">✅ ${m.success_count} Logins</span>
                    <span class="px-2 py-0.5 rounded bg-rose-50 text-rose-700 font-bold">🚫 ${m.failed_count} Fehlversuche</span>
                </div>
                ${evHtml ? `<ul class="text-[10px] text-slate-500 space-y-1 max-h-28 overflow-y-auto pt-1">${evHtml}</ul>` : ''}
            </div>
        `);

        marker.addTo(layerGroupSecurityMap);
        securityMapMarkers[idx] = marker;
        boundsPts.push([m.lat, m.lng]);
    });

    if (boundsPts.length >= 2) {
        mcMap.fitBounds(L.latLngBounds(boundsPts), { padding: [36, 36], maxZoom: 10 });
    }

    const listEl = document.getElementById('securityMapList');
    if (listEl) {
        if (filtered.length === 0) {
            listEl.innerHTML = `<div class="p-4 text-center text-xs text-slate-500">Keine Einträge für diesen Filter vorhanden.</div>`;
            return;
        }
        listEl.innerHTML = filtered.map((m, idx) => {
            const isDanger = m.status === 'danger';
            const badgeCls = isDanger
                ? 'bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300'
                : 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300';
            return `
                <button type="button" onclick="focusSecurityMarker(${idx}, ${m.lat}, ${m.lng})" class="w-full text-left p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-slate-700/70 transition-colors cursor-pointer">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-900 dark:text-white truncate">
                                ${isDanger ? '🚨' : (m.is_current ? '🟢' : '🛡️')} ${escapeMapHtml(m.city)}, ${escapeMapHtml(m.country)}
                            </p>
                            <p class="text-[11px] font-mono text-slate-500 dark:text-slate-400 truncate">
                                ${escapeMapHtml(m.masked_ip)} · ${escapeMapHtml((m.users || []).join(', '))}
                            </p>
                            <p class="text-[10px] text-slate-400 truncate">${escapeMapHtml(m.isp)} · ${escapeMapHtml(m.last_seen)}</p>
                        </div>
                        <span class="px-1.5 py-0.5 rounded-md text-[10px] font-extrabold shrink-0 ${badgeCls}">
                            ${isDanger ? `🚫 ${m.failed_count}` : `✅ ${m.success_count}`}
                        </span>
                    </div>
                </button>
            `;
        }).join('');
    }
}

function focusSecurityMarker(idx, lat, lng) {
    if (!mcMap) return;
    mcMap.setView([lat, lng], 10, { animate: true });
    if (securityMapMarkers[idx]) {
        securityMapMarkers[idx].openPopup();
    }
}

function openMapModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}

function closeMapModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}

function openNewPlaceModal(lat, lng, suggestedTitle = '') {
    const c = mcMap ? mcMap.getCenter() : { lat: 51.1657, lng: 10.4515 };
    document.getElementById('placeModalTitle').textContent = '📍 Neuen Ort / Pin speichern';
    document.getElementById('placeFormId').value = '0';
    document.getElementById('placeFormTitle').value = suggestedTitle;
    document.getElementById('placeFormLat').value = (lat !== undefined ? lat : c.lat).toFixed(5);
    document.getElementById('placeFormLng').value = (lng !== undefined ? lng : c.lng).toFixed(5);
    document.getElementById('placeFormAddress').value = '';
    document.getElementById('placeFormDesc').value = '';
    openMapModal('placeModal');
}

function editExistingPlace(pl) {
    document.getElementById('placeModalTitle').textContent = '✏️ Standort bearbeiten';
    document.getElementById('placeFormId').value = String(pl.id);
    document.getElementById('placeFormTitle').value = pl.title || '';
    document.getElementById('placeFormCategory').value = pl.category || 'favorite';
    document.getElementById('placeFormLat').value = Number(pl.lat).toFixed(5);
    document.getElementById('placeFormLng').value = Number(pl.lng).toFixed(5);
    document.getElementById('placeFormAddress').value = pl.address || '';
    document.getElementById('placeFormDesc').value = pl.description || '';
    document.getElementById('placeFormColor').value = pl.color || '#2563eb';
    document.getElementById('placeFormIcon').value = pl.icon || '📍';
    document.getElementById('placeFormMinDetail').value = String(pl.min_detail_level || 1);
    document.getElementById('placeFormShared').checked = Number(pl.is_shared) === 1;
    openMapModal('placeModal');
}

function onPlaceCategoryPick(cat) {
    const sel = document.getElementById('placeFormCategory');
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    const ico = opt.getAttribute('data-icon');
    const col = opt.getAttribute('data-color');
    if (ico) document.getElementById('placeFormIcon').value = ico;
    if (col) document.getElementById('placeFormColor').value = col;
}

async function prefetchCurrentViewTiles() {
    if (!mcMap || MC_FLOORPLAN) return;
    const zoom = Number(document.getElementById('prefetchZoomSelect').value) || 10;
    const regEl = document.getElementById('prefetchRegionSelect');
    const regVal = regEl ? regEl.value : 'view';
    const msg  = document.getElementById('prefetchStatusMsg');

    let latMin, latMax, lngMin, lngMax;
    if (regVal && regVal !== 'view' && regVal.includes(',')) {
        const parts = regVal.split(',').map(Number);
        latMin = parts[0];
        latMax = parts[1];
        lngMin = parts[2];
        lngMax = parts[3];
    } else {
        const b = mcMap.getBounds();
        latMin = b.getSouth();
        latMax = b.getNorth();
        lngMin = b.getWest();
        lngMax = b.getEast();
    }

    let totalFetched = 0;
    let lastCached = 0;
    for (let batch = 1; batch <= 5; batch++) {
        if (msg) {
            msg.textContent = `⏳ Lade Offline-Kacheln (Detailstufe Zoom ${zoom}, Durchgang ${batch})…`;
        }
        const fd = new URLSearchParams();
        fd.set('action', 'prefetch_offline_tiles');
        fd.set('csrf_token', MC_MAP_CSRF);
        fd.set('zoom', String(zoom));
        fd.set('lat_min', String(latMin));
        fd.set('lat_max', String(latMax));
        fd.set('lng_min', String(lngMin));
        fd.set('lng_max', String(lngMax));

        try {
            const res = await fetch('maps.php', { method: 'POST', body: fd });
            const d   = await res.json();
            if (d && d.ok) {
                totalFetched += Number(d.fetched || 0);
                lastCached    = Number(d.already_cached || 0);
                mcMaxZoomDetail = Math.max(mcMaxZoomDetail, zoom);
                if (Number(d.fetched || 0) < 36) {
                    break;
                }
            } else {
                break;
            }
        } catch (e) {
            break;
        }
    }

    if (msg) {
        msg.textContent = `✅ Detailstufe Zoom ${zoom} lokal gespeichert: ${totalFetched} neue Kacheln geladen (${lastCached + totalFetched} Kacheln in dieser Region offline verfügbar).`;
    }
}

// =========================================================================
// 13. STROMTANKSTELLEN & E-LADESÄULEN-RADAR (BUNDESWEIT + OSM OVERPASS LIVE)
// =========================================================================
let evChargingLayerActive = false;
let evChargingStations    = [];
let evChargingFilterMode  = 'all';
let evReloadTimer         = null;

function toggleEvChargingLayer(forceState) {
    if (!mcMap || MC_FLOORPLAN) return;
    evChargingLayerActive = (typeof forceState === 'boolean') ? forceState : !evChargingLayerActive;

    const chk   = document.getElementById('chkLayerEvCharging');
    const bar   = document.getElementById('evChargingControlBar');
    const btn   = document.getElementById('btnEvCharging');
    const lbl   = document.getElementById('btnEvChargingLabel');
    const badge = document.getElementById('evChargingCountBadge');

    if (chk) chk.checked = evChargingLayerActive;
    if (bar) bar.classList.toggle('hidden', !evChargingLayerActive);
    if (lbl) lbl.textContent = evChargingLayerActive ? 'Stromtankstellen aktiv' : 'Stromtankstellen';
    if (btn) {
        btn.className = evChargingLayerActive
            ? 'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-purple-600 text-white shadow-xs transition-colors cursor-pointer'
            : 'inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-bold bg-purple-50 dark:bg-purple-950/70 hover:bg-purple-100 dark:hover:bg-purple-900/70 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/80 transition-colors cursor-pointer';
    }

    if (!evChargingLayerActive) {
        layerGroupEvCharging.clearLayers();
        if (badge) badge.classList.add('hidden');
        return;
    }

    loadEvChargingStationsInView(false);
}

function scheduleEvChargingReload() {
    if (!evChargingLayerActive) return;
    if (evReloadTimer) clearTimeout(evReloadTimer);
    evReloadTimer = setTimeout(() => {
        loadEvChargingStationsInView(false);
    }, 380);
}

async function loadEvChargingStationsInView(forceRefresh = false) {
    if (!mcMap || !evChargingLayerActive || MC_FLOORPLAN) return;
    const statusBadge = document.getElementById('evChargingStatusBadge');
    if (statusBadge) {
        statusBadge.textContent = '⏳ Lade Stromtankstellen im Ausschnitt…';
    }

    try {
        const b    = mcMap.getBounds();
        const zoom = mcMap.getZoom();
        const url  = `maps.php?action=ev_charging_bbox&south=${encodeURIComponent(b.getSouth())}&west=${encodeURIComponent(b.getWest())}&north=${encodeURIComponent(b.getNorth())}&east=${encodeURIComponent(b.getEast())}&zoom=${encodeURIComponent(zoom)}${forceRefresh ? '&t=' + Date.now() : ''}`;
        const res  = await fetch(url);
        const d    = await res.json();

        if (d && d.ok && Array.isArray(d.stations)) {
            evChargingStations = d.stations;
            renderEvChargingMarkers(d.source || '');
        } else {
            if (statusBadge) statusBadge.textContent = '⚠️ Keine Ladesäulen im Ausschnitt gefunden';
        }
    } catch (e) {
        if (statusBadge) statusBadge.textContent = '⚠️ Fehler beim Abruf der Ladesäulen';
    }
}

function setEvChargingFilter(mode) {
    evChargingFilterMode = mode || 'all';
    ['all', 'hpc', 'fast', 'ac'].forEach(m => {
        const b = document.getElementById('evFilterBtn_' + m);
        if (b) {
            b.className = m === evChargingFilterMode
                ? 'px-2 py-1 rounded-lg bg-purple-600 text-white font-bold cursor-pointer text-[11px]'
                : 'px-2 py-1 rounded-lg bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-purple-200 dark:border-purple-700 font-bold cursor-pointer text-[11px]';
        }
    });
    renderEvChargingMarkers();
}

function renderEvChargingMarkers(sourceNote = '') {
    layerGroupEvCharging.clearLayers();
    if (!evChargingLayerActive) return;

    const filtered = evChargingStations.filter(st => {
        if (evChargingFilterMode === 'all') return true;
        return st.charger_class === evChargingFilterMode;
    });

    const statusBadge = document.getElementById('evChargingStatusBadge');
    const countBadge  = document.getElementById('evChargingCountBadge');

    if (statusBadge) {
        const zoom = mcMap ? mcMap.getZoom() : 6;
        const zoomHint = zoom < 9 ? ' (HPC- & Autobahn-Netz · für alle Straßen-Säulen näher heranzoomen)' : '';
        statusBadge.textContent = `⚡ ${filtered.length} Stromtankstellen im Ausschnitt${zoomHint}`;
    }
    if (countBadge) {
        countBadge.textContent = String(filtered.length);
        countBadge.classList.remove('hidden');
    }

    filtered.forEach((st, idx) => {
        const kwBadge = st.max_kw ? `${st.max_kw} kW` : '';
        const marker = L.marker([st.lat, st.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div class="mc-map-pin" style="background-color:${escapeMapHtml(st.color)}" title="${escapeMapHtml(st.title)} (${escapeMapHtml(kwBadge)})">${escapeMapHtml(st.icon)}</div>`,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
                popupAnchor: [0, -14]
            })
        });

        const classLabel = st.charger_class === 'hpc'
            ? '⚡ HPC Ultra-Schnelllader (≥ 150 kW)'
            : (st.charger_class === 'fast' ? '🔋 DC-Schnelllader (50–149 kW)' : '🔌 AC-Normallader (Typ 2)');

        marker.bindPopup(`
            <div class="text-xs space-y-1.5 min-w-[215px]">
                <p class="font-extrabold text-sm">${escapeMapHtml(st.icon)} ${escapeMapHtml(st.title)}</p>
                <p class="font-bold" style="color:${escapeMapHtml(st.color)}">${escapeMapHtml(classLabel)} · max. ${Number(st.max_kw || 22)} kW</p>
                ${st.operator ? `<p class="text-slate-600">🏢 Betreiber: <strong>${escapeMapHtml(st.operator)}</strong></p>` : ''}
                ${st.sockets ? `<p class="text-slate-600">🔌 Anschlüsse: <strong>${escapeMapHtml(st.sockets)}</strong></p>` : ''}
                ${st.capacity ? `<p class="text-slate-500">🚗 Ladepunkte: <strong>${Number(st.capacity)}</strong></p>` : ''}
                ${st.address ? `<p class="text-slate-500">📫 ${escapeMapHtml(st.address)}</p>` : ''}
                ${st.opening_hours ? `<p class="text-slate-400 text-[11px]">🕒 ${escapeMapHtml(st.opening_hours)}</p>` : ''}
                <div class="pt-1 flex items-center gap-1.5">
                    <button type="button" onclick="saveEvStationAsMyPlace(${idx})" class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-bold cursor-pointer">
                        💾 Als Favorit speichern
                    </button>
                </div>
            </div>
        `);

        marker.addTo(layerGroupEvCharging);
    });
}

function saveEvStationAsMyPlace(idx) {
    const filtered = evChargingStations.filter(st => evChargingFilterMode === 'all' || st.charger_class === evChargingFilterMode);
    const st = filtered[idx];
    if (!st) return;
    openNewPlaceModal(st.lat, st.lng, st.title);
    document.getElementById('placeFormAddress').value  = st.address || '';
    document.getElementById('placeFormDesc').value     = `${st.sockets || ''} · Betreiber: ${st.operator || 'Öffentlich'}`;
    document.getElementById('placeFormCategory').value = 'favorite';
    document.getElementById('placeFormIcon').value     = st.icon || '⚡';
    document.getElementById('placeFormColor').value    = st.color || '#9333ea';
}

function escapeMapHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', initMiniCloudMap);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
