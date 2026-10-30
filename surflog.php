<?php
declare(strict_types=1);

/**
 * MiniCloud – 🏄‍♂️ Surf-Tagebuch, Material-Quiver & GPS-Session-Logbuch (surflog.php)
 *
 * Für Windsurfen, Kitesurfen, Wingfoilen, Wellenreiten & SUP:
 * 1. 📝 Session-Logbuch mit Spot, Wasserdauer, Knoten-↔-Beaufort-Rechner, interaktiver 16er-Windrose, Welle, Gezeiten & Temperatur
 * 2. 📍 Lokaler GPX-Track-Parser & Speed-Heatmap-Zeichner (berechnet automatisch Max-Speed, Distanz & Dauer aus GPS-Uhren)
 * 3. 🏄‍♂️ Material-Schrank (Quiver) für Boards, Segel/Kites/Wings, Foils/Finnen & Neopren inkl. Nutzungszähler & „Cost-per-Session“
 * 4. 📊 Jahres- & Monatsstatistik (Wassertage, Wasserstunden, Top-Spots, meistgefahrenes Material, persönlicher Speed-Rekord)
 * 5. 🖼️ Verknüpfung mit Fotos/Videos aus der MiniCloud & 1-Klick CSV-Export in die Cloud
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');
$isAdmin     = isAdmin();

if (($appSettings['enable_surflog'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('warning', 'Das Modul „Surf-Tagebuch & Session-Logbuch“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Legt die Tabellen `surf_gear` und `surf_sessions` bei Bedarf automatisch an.
 */
function ensureSurflogSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured || !empty($_SESSION['_mc_surflog_schema_ok'])) {
        $ensured = true;
        return;
    }
    $ensured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `surf_gear` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `category` VARCHAR(32) NOT NULL DEFAULT 'board',
            `sport` VARCHAR(32) NOT NULL DEFAULT 'all',
            `brand` VARCHAR(100) NOT NULL DEFAULT '',
            `model` VARCHAR(120) NOT NULL DEFAULT '',
            `size_spec` VARCHAR(60) NOT NULL DEFAULT '',
            `purchase_price` DECIMAL(10,2) DEFAULT NULL,
            `purchase_year` SMALLINT UNSIGNED DEFAULT NULL,
            `notes` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_surf_gear_user` (`user_id`, `category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `surf_sessions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `session_date` DATE NOT NULL,
            `start_time` VARCHAR(10) NOT NULL DEFAULT '',
            `duration_min` INT UNSIGNED NOT NULL DEFAULT 90,
            `sport` VARCHAR(32) NOT NULL DEFAULT 'windsurf',
            `spot_name` VARCHAR(150) NOT NULL,
            `spot_region` VARCHAR(100) NOT NULL DEFAULT '',
            `wind_knots_avg` DECIMAL(5,1) DEFAULT NULL,
            `wind_knots_max` DECIMAL(5,1) DEFAULT NULL,
            `wind_bft` TINYINT UNSIGNED DEFAULT NULL,
            `wind_dir` VARCHAR(10) NOT NULL DEFAULT '',
            `wind_angle` VARCHAR(32) NOT NULL DEFAULT '',
            `wave_height_m` DECIMAL(4,1) DEFAULT NULL,
            `water_condition` VARCHAR(50) NOT NULL DEFAULT '',
            `tide` VARCHAR(40) NOT NULL DEFAULT '',
            `temp_air_c` SMALLINT DEFAULT NULL,
            `temp_water_c` SMALLINT DEFAULT NULL,
            `board_gear_id` INT UNSIGNED DEFAULT NULL,
            `board_text` VARCHAR(150) NOT NULL DEFAULT '',
            `rig_gear_id` INT UNSIGNED DEFAULT NULL,
            `rig_text` VARCHAR(150) NOT NULL DEFAULT '',
            `fin_foil_text` VARCHAR(120) NOT NULL DEFAULT '',
            `wetsuit_text` VARCHAR(100) NOT NULL DEFAULT '',
            `max_speed_kmh` DECIMAL(5,1) DEFAULT NULL,
            `distance_km` DECIMAL(6,2) DEFAULT NULL,
            `maneuvers` VARCHAR(255) NOT NULL DEFAULT '',
            `rating` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `notes` TEXT DEFAULT NULL,
            `media_file_id` INT UNSIGNED DEFAULT NULL,
            `gpx_track_json` MEDIUMTEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_surf_sess_user_date` (`user_id`, `session_date` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_mc_surflog_schema_ok'] = 1;
    }
}

ensureSurflogSchema($pdo);

/**
 * Rechnet Knoten in Beaufort (0..12) um.
 */
function knotsToBeaufort(?float $knots): ?int
{
    if ($knots === null || $knots <= 0) {
        return null;
    }
    if ($knots < 1) return 0;
    if ($knots <= 3) return 1;
    if ($knots <= 6) return 2;
    if ($knots <= 10) return 3;
    if ($knots <= 15) return 4;
    if ($knots <= 21) return 5;
    if ($knots <= 27) return 6;
    if ($knots <= 33) return 7;
    if ($knots <= 40) return 8;
    if ($knots <= 47) return 9;
    if ($knots <= 55) return 10;
    if ($knots <= 63) return 11;
    return 12;
}

$sportCatalog = [
    'windsurf' => ['label' => 'Windsurfen',   'icon' => '🏄‍♂️', 'badge' => 'bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-sky-300'],
    'kitesurf' => ['label' => 'Kitesurfen',   'icon' => '🪁',   'badge' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300'],
    'wingfoil' => ['label' => 'Wingfoilen',   'icon' => '🪽',   'badge' => 'bg-violet-100 dark:bg-violet-950/80 text-violet-800 dark:text-violet-300'],
    'surf'     => ['label' => 'Wellenreiten', 'icon' => '🌊',   'badge' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300'],
    'sup'      => ['label' => 'Stand-Up-SUP', 'icon' => '🛶',   'badge' => 'bg-teal-100 dark:bg-teal-950/80 text-teal-800 dark:text-teal-300'],
];

$gearCategories = [
    'board'          => ['label' => '🏄 Board / Brett',           'placeholder' => 'z. B. 105 L / 232×64 cm'],
    'sail_kite_wing' => ['label' => '⛵ Segel / Kite / Wing',     'placeholder' => 'z. B. 5.3 m² oder 9 m²'],
    'foil_fin'       => ['label' => '🦈 Finne / Foil / Mast',     'placeholder' => 'z. B. 32 cm Freeride / 1250 cm²'],
    'wetsuit'        => ['label' => '🤿 Neopren / Trapez /Zubehör','placeholder' => 'z. B. 5/4 mm Steamer'],
];

// ============================================================================
// POST-AKTIONEN: Session speichern/löschen, Material anlegen/löschen, CSV-Export
// ============================================================================
$reqMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($reqMethod === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte erneut versuchen.');
        header('Location: surflog.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Surf-Session anlegen oder aktualisieren
    if ($action === 'save_session') {
        $editId        = (int)($_POST['session_id'] ?? 0);
        $sessionDate   = trim((string)($_POST['session_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            $sessionDate = date('Y-m-d');
        }
        $startTime     = mb_substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
        $durationMin   = max(5, min(1440, (int)($_POST['duration_min'] ?? 90)));
        $sport         = (string)($_POST['sport'] ?? 'windsurf');
        if (!isset($sportCatalog[$sport])) {
            $sport = 'windsurf';
        }
        $spotName      = mb_substr(trim((string)($_POST['spot_name'] ?? '')), 0, 150);
        $spotRegion    = mb_substr(trim((string)($_POST['spot_region'] ?? '')), 0, 100);

        if ($spotName === '') {
            setFlash('error', 'Bitte gib einen Spot- oder Reviernamen an.');
            header('Location: surflog.php');
            exit;
        }

        $windKnotsAvg  = ($_POST['wind_knots_avg'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['wind_knots_avg'])) : null;
        $windKnotsMax  = ($_POST['wind_knots_max'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['wind_knots_max'])) : null;
        $windBftInput  = ($_POST['wind_bft'] ?? '') !== '' ? max(0, min(12, (int)$_POST['wind_bft'])) : null;
        $windBft       = $windBftInput ?? knotsToBeaufort($windKnotsAvg ?? $windKnotsMax);

        $windDir       = mb_substr(trim((string)($_POST['wind_dir'] ?? '')), 0, 10);
        $windAngle     = mb_substr(trim((string)($_POST['wind_angle'] ?? '')), 0, 32);
        $waveHeightM   = ($_POST['wave_height_m'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['wave_height_m'])) : null;
        $waterCond     = mb_substr(trim((string)($_POST['water_condition'] ?? '')), 0, 50);
        $tide          = mb_substr(trim((string)($_POST['tide'] ?? '')), 0, 40);
        $tempAirC      = ($_POST['temp_air_c'] ?? '') !== '' ? max(-30, min(55, (int)$_POST['temp_air_c'])) : null;
        $tempWaterC    = ($_POST['temp_water_c'] ?? '') !== '' ? max(0, min(40, (int)$_POST['temp_water_c'])) : null;

        $boardGearId   = (int)($_POST['board_gear_id'] ?? 0) ?: null;
        $boardText     = mb_substr(trim((string)($_POST['board_text'] ?? '')), 0, 150);
        $rigGearId     = (int)($_POST['rig_gear_id'] ?? 0) ?: null;
        $rigText       = mb_substr(trim((string)($_POST['rig_text'] ?? '')), 0, 150);
        $finFoilText   = mb_substr(trim((string)($_POST['fin_foil_text'] ?? '')), 0, 120);
        $wetsuitText   = mb_substr(trim((string)($_POST['wetsuit_text'] ?? '')), 0, 100);

        // Falls ein Gerät aus dem Quiver gewählt wurde und das Freitextfeld leer ist, automatisch Label übernehmen
        if ($boardGearId !== null && $boardText === '') {
            $stmtG = $pdo->prepare('SELECT brand, model, size_spec FROM surf_gear WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtG->execute([':id' => $boardGearId, ':uid' => $userId]);
            if ($gRow = $stmtG->fetch()) {
                $boardText = trim($gRow['brand'] . ' ' . $gRow['model'] . ($gRow['size_spec'] !== '' ? ' (' . $gRow['size_spec'] . ')' : ''));
            }
        }
        if ($rigGearId !== null && $rigText === '') {
            $stmtG = $pdo->prepare('SELECT brand, model, size_spec FROM surf_gear WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtG->execute([':id' => $rigGearId, ':uid' => $userId]);
            if ($gRow = $stmtG->fetch()) {
                $rigText = trim($gRow['brand'] . ' ' . $gRow['model'] . ($gRow['size_spec'] !== '' ? ' (' . $gRow['size_spec'] . ')' : ''));
            }
        }

        $maxSpeedKmh   = ($_POST['max_speed_kmh'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['max_speed_kmh'])) : null;
        $distanceKm    = ($_POST['distance_km'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['distance_km'])) : null;
        $maneuvers     = mb_substr(trim((string)($_POST['maneuvers'] ?? '')), 0, 255);
        $rating        = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $notes         = trim((string)($_POST['notes'] ?? ''));
        $mediaFileId   = (int)($_POST['media_file_id'] ?? 0) ?: null;
        $gpxTrackJson  = trim((string)($_POST['gpx_track_json'] ?? ''));
        if ($gpxTrackJson !== '' && strlen($gpxTrackJson) > 350000) {
            $gpxTrackJson = '';
        }

        $params = [
            ':sdate'   => $sessionDate,
            ':stime'   => $startTime,
            ':dur'     => $durationMin,
            ':sport'   => $sport,
            ':spot'    => $spotName,
            ':sreg'    => $spotRegion,
            ':wk_avg'  => $windKnotsAvg,
            ':wk_max'  => $windKnotsMax,
            ':wbft'    => $windBft,
            ':wdir'    => $windDir,
            ':wang'    => $windAngle,
            ':wave'    => $waveHeightM,
            ':wcond'   => $waterCond,
            ':tide'    => $tide,
            ':tair'    => $tempAirC,
            ':twat'    => $tempWaterC,
            ':bgid'    => $boardGearId,
            ':btxt'    => $boardText,
            ':rgid'    => $rigGearId,
            ':rtxt'    => $rigText,
            ':fftxt'   => $finFoilText,
            ':wettxt'  => $wetsuitText,
            ':mspd'    => $maxSpeedKmh,
            ':dist'    => $distanceKm,
            ':man'     => $maneuvers,
            ':rat'     => $rating,
            ':notes'   => $notes,
            ':mfid'    => $mediaFileId,
            ':gpx'     => $gpxTrackJson !== '' ? $gpxTrackJson : null,
        ];

        if ($editId > 0) {
            $params[':id']  = $editId;
            $params[':uid'] = $userId;
            $stmtUpd = $pdo->prepare(
                'UPDATE surf_sessions SET
                    session_date = :sdate, start_time = :stime, duration_min = :dur, sport = :sport,
                    spot_name = :spot, spot_region = :sreg,
                    wind_knots_avg = :wk_avg, wind_knots_max = :wk_max, wind_bft = :wbft,
                    wind_dir = :wdir, wind_angle = :wang, wave_height_m = :wave,
                    water_condition = :wcond, tide = :tide, temp_air_c = :tair, temp_water_c = :twat,
                    board_gear_id = :bgid, board_text = :btxt, rig_gear_id = :rgid, rig_text = :rtxt,
                    fin_foil_text = :fftxt, wetsuit_text = :wettxt,
                    max_speed_kmh = :mspd, distance_km = :dist, maneuvers = :man,
                    rating = :rat, notes = :notes, media_file_id = :mfid,
                    gpx_track_json = COALESCE(:gpx, gpx_track_json)
                 WHERE id = :id AND user_id = :uid'
            );
            $stmtUpd->execute($params);
            setFlash('success', '🏄‍♂️ Surf-Session in „' . $spotName . '“ wurde aktualisiert.');
        } else {
            $params[':uid'] = $userId;
            $stmtIns = $pdo->prepare(
                'INSERT INTO surf_sessions (
                    user_id, session_date, start_time, duration_min, sport, spot_name, spot_region,
                    wind_knots_avg, wind_knots_max, wind_bft, wind_dir, wind_angle, wave_height_m,
                    water_condition, tide, temp_air_c, temp_water_c,
                    board_gear_id, board_text, rig_gear_id, rig_text, fin_foil_text, wetsuit_text,
                    max_speed_kmh, distance_km, maneuvers, rating, notes, media_file_id, gpx_track_json
                 ) VALUES (
                    :uid, :sdate, :stime, :dur, :sport, :spot, :sreg,
                    :wk_avg, :wk_max, :wbft, :wdir, :wang, :wave,
                    :wcond, :tide, :tair, :twat,
                    :bgid, :btxt, :rgid, :rtxt, :fftxt, :wettxt,
                    :mspd, :dist, :man, :rat, :notes, :mfid, :gpx
                 )'
            );
            $stmtIns->execute($params);
            setFlash('success', '🤙 Neue Surf-Session in „' . $spotName . '“ im Tagebuch eingetragen!');
        }
        recordModuleUsage($pdo, 'surflog', true);
        header('Location: surflog.php');
        exit;
    }

    // 2. Session löschen
    if ($action === 'delete_session') {
        $delId = (int)($_POST['session_id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM surf_sessions WHERE id = :id AND user_id = :uid')->execute([
                ':id'  => $delId,
                ':uid' => $userId,
            ]);
            setFlash('success', 'Surf-Session wurde gelöscht.');
        }
        header('Location: surflog.php');
        exit;
    }

    // 3. Material im Quiver anlegen
    if ($action === 'add_gear') {
        $cat   = (string)($_POST['category'] ?? 'board');
        if (!isset($gearCategories[$cat])) {
            $cat = 'board';
        }
        $sport = (string)($_POST['sport'] ?? 'all');
        $brand = mb_substr(trim((string)($_POST['brand'] ?? '')), 0, 100);
        $model = mb_substr(trim((string)($_POST['model'] ?? '')), 0, 120);
        $spec  = mb_substr(trim((string)($_POST['size_spec'] ?? '')), 0, 60);
        $price = ($_POST['purchase_price'] ?? '') !== '' ? max(0.0, (float)str_replace(',', '.', (string)$_POST['purchase_price'])) : null;
        $year  = ($_POST['purchase_year'] ?? '') !== '' ? max(1970, min(2100, (int)$_POST['purchase_year'])) : null;
        $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255);

        if ($brand === '' && $model === '') {
            setFlash('error', 'Bitte gib mindestens Marke oder Modell des Materials an.');
            header('Location: surflog.php?tab=quiver');
            exit;
        }

        $stmtG = $pdo->prepare(
            'INSERT INTO surf_gear (user_id, category, sport, brand, model, size_spec, purchase_price, purchase_year, notes)
             VALUES (:uid, :cat, :sp, :br, :mo, :sz, :pr, :yr, :nt)'
        );
        $stmtG->execute([
            ':uid' => $userId,
            ':cat' => $cat,
            ':sp'  => $sport,
            ':br'  => $brand,
            ':mo'  => $model,
            ':sz'  => $spec,
            ':pr'  => $price,
            ':yr'  => $year,
            ':nt'  => $notes,
        ]);
        setFlash('success', '🏄 Material „' . trim($brand . ' ' . $model) . '“ zum Quiver hinzugefügt.');
        header('Location: surflog.php?tab=quiver');
        exit;
    }

    // 4. Material aus Quiver löschen
    if ($action === 'delete_gear') {
        $gearId = (int)($_POST['gear_id'] ?? 0);
        if ($gearId > 0) {
            $pdo->prepare('DELETE FROM surf_gear WHERE id = :id AND user_id = :uid')->execute([
                ':id'  => $gearId,
                ':uid' => $userId,
            ]);
            setFlash('success', 'Material-Eintrag wurde aus dem Quiver entfernt.');
        }
        header('Location: surflog.php?tab=quiver');
        exit;
    }

    // 5. Gesamtes Surftagebuch als CSV in die MiniCloud exportieren
    if ($action === 'export_csv_cloud') {
        $stmtAll = $pdo->prepare('SELECT * FROM surf_sessions WHERE user_id = :uid ORDER BY session_date DESC, id DESC');
        $stmtAll->execute([':uid' => $userId]);
        $rows = $stmtAll->fetchAll();

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel / Office Light
        fputcsv($fp, [
            'Datum', 'Startzeit', 'Dauer (Min)', 'Sportart', 'Spot', 'Region',
            'Wind Ø (kn)', 'Wind Max (kn)', 'Beaufort (Bft)', 'Windrichtung', 'Windwinkel',
            'Welle (m)', 'Wasserzustand', 'Gezeiten', 'Luft (°C)', 'Wasser (°C)',
            'Board', 'Segel/Kite/Wing', 'Finne/Foil', 'Neopren',
            'Max-Speed (km/h)', 'Distanz (km)', 'Manöver/Highlights', 'Bewertung (1-5)', 'Notizen'
        ], ';');

        $sanitizeCsvCell = static function (string $val): string {
            $trimmed = ltrim($val);
            if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $val;
            }
            return $val;
        };

        foreach ($rows as $r) {
            $spLabel = $sportCatalog[(string)$r['sport']]['label'] ?? (string)$r['sport'];
            fputcsv($fp, [
                (string)$r['session_date'],
                $sanitizeCsvCell((string)$r['start_time']),
                (int)$r['duration_min'],
                $spLabel,
                $sanitizeCsvCell((string)$r['spot_name']),
                $sanitizeCsvCell((string)$r['spot_region']),
                $r['wind_knots_avg'] !== null ? str_replace('.', ',', (string)$r['wind_knots_avg']) : '',
                $r['wind_knots_max'] !== null ? str_replace('.', ',', (string)$r['wind_knots_max']) : '',
                $r['wind_bft'] !== null ? (string)$r['wind_bft'] : '',
                $sanitizeCsvCell((string)$r['wind_dir']),
                $sanitizeCsvCell((string)$r['wind_angle']),
                $r['wave_height_m'] !== null ? str_replace('.', ',', (string)$r['wave_height_m']) : '',
                $sanitizeCsvCell((string)$r['water_condition']),
                $sanitizeCsvCell((string)$r['tide']),
                $r['temp_air_c'] !== null ? (string)$r['temp_air_c'] : '',
                $r['temp_water_c'] !== null ? (string)$r['temp_water_c'] : '',
                $sanitizeCsvCell((string)$r['board_text']),
                $sanitizeCsvCell((string)$r['rig_text']),
                $sanitizeCsvCell((string)$r['fin_foil_text']),
                $sanitizeCsvCell((string)$r['wetsuit_text']),
                $r['max_speed_kmh'] !== null ? str_replace('.', ',', (string)$r['max_speed_kmh']) : '',
                $r['distance_km'] !== null ? str_replace('.', ',', (string)$r['distance_km']) : '',
                $sanitizeCsvCell((string)$r['maneuvers']),
                (int)$r['rating'],
                $sanitizeCsvCell((string)$r['notes']),
            ], ';');
        }
        rewind($fp);
        $csvBytes = (string)stream_get_contents($fp);
        fclose($fp);

        $storage = getUserStorageStats($pdo, $userId);
        if (strlen($csvBytes) > $storage['free']) {
            setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für den CSV-Export.');
            header('Location: surflog.php');
            exit;
        }

        ensureUploadDirReady();
        $storedName  = sprintf('mc_%s_%s.bin', uniqid('', true), bin2hex(random_bytes(8)));
        $destPath    = UPLOAD_DIR . $storedName;
        $cleanFolder = 'Surftagebuch';
        $cleanName   = 'Surftagebuch-Export-' . date('Y-m-d') . '.csv';

        if (@file_put_contents($destPath, $csvBytes) !== false) {
            @chmod($destPath, 0640);
            registerFolderHierarchy($pdo, $userId, $cleanFolder);
            $pdo->prepare(
                "INSERT INTO files (user_id, filename, original_name, folder_path, file_size, mime_type)
                 VALUES (:uid, :fn, :orig, :folder, :sz, 'text/csv')"
            )->execute([
                ':uid'    => $userId,
                ':fn'     => $storedName,
                ':orig'   => $cleanName,
                ':folder' => $cleanFolder,
                ':sz'     => strlen($csvBytes),
            ]);
            setFlash('success', '📊 Surftagebuch wurde als „' . $cleanName . '“ im Cloud-Ordner „/Surftagebuch“ gespeichert!');
        } else {
            setFlash('error', 'CSV-Export konnte nicht gespeichert werden.');
        }
        header('Location: surflog.php');
        exit;
    }
}

// ============================================================================
// DATEN LADEN: Material-Quiver, Sessions, Filter & Statistiken
// ============================================================================
$filterYear  = isset($_GET['year']) ? trim((string)$_GET['year']) : 'all';
$filterSport = isset($_GET['sport']) ? trim((string)$_GET['sport']) : 'all';
$searchSpot  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$activeTab   = isset($_GET['tab']) && in_array($_GET['tab'], ['sessions', 'new', 'quiver', 'stats'], true)
    ? (string)$_GET['tab']
    : 'sessions';

// Material-Quiver des Benutzers inkl. Session-Nutzungszähler laden
$stmtGear = $pdo->prepare(
    'SELECT g.*,
            (SELECT COUNT(*) FROM surf_sessions s
             WHERE s.user_id = g.user_id AND (s.board_gear_id = g.id OR s.rig_gear_id = g.id)) AS usage_count,
            (SELECT COALESCE(SUM(s.duration_min), 0) FROM surf_sessions s
             WHERE s.user_id = g.user_id AND (s.board_gear_id = g.id OR s.rig_gear_id = g.id)) AS usage_minutes
     FROM surf_gear g
     WHERE g.user_id = :uid
     ORDER BY g.category ASC, g.brand ASC, g.model ASC'
);
$stmtGear->execute([':uid' => $userId]);
$userGear = $stmtGear->fetchAll();

$gearBoards = array_values(array_filter($userGear, static fn(array $g): bool => $g['category'] === 'board'));
$gearRigs   = array_values(array_filter($userGear, static fn(array $g): bool => $g['category'] === 'sail_kite_wing'));
$gearFoils  = array_values(array_filter($userGear, static fn(array $g): bool => $g['category'] === 'foil_fin'));
$gearSuits  = array_values(array_filter($userGear, static fn(array $g): bool => $g['category'] === 'wetsuit'));

// Alle Sessions des Benutzers laden (für Jahresliste, Spots & Statistiken)
$stmtAllSess = $pdo->prepare('SELECT * FROM surf_sessions WHERE user_id = :uid ORDER BY session_date DESC, id DESC');
$stmtAllSess->execute([':uid' => $userId]);
$allSessions = $stmtAllSess->fetchAll();

// Vorhandene Jahre & Top-Spots ermitteln
$availableYears = [];
$knownSpots     = [];
foreach ($allSessions as $s) {
    $yr = substr((string)$s['session_date'], 0, 4);
    if ($yr !== '') {
        $availableYears[$yr] = true;
    }
    $spName = trim((string)$s['spot_name']);
    if ($spName !== '') {
        $knownSpots[$spName] = ($knownSpots[$spName] ?? 0) + 1;
    }
}
krsort($availableYears);
arsort($knownSpots);

// Gefilterte Sessions für die Ansicht & Statistik berechnen
$filteredSessions = array_values(array_filter($allSessions, static function (array $s) use ($filterYear, $filterSport, $searchSpot): bool {
    if ($filterYear !== 'all' && substr((string)$s['session_date'], 0, 4) !== $filterYear) {
        return false;
    }
    if ($filterSport !== 'all' && (string)$s['sport'] !== $filterSport) {
        return false;
    }
    if ($searchSpot !== '') {
        $hay = strtolower((string)$s['spot_name'] . ' ' . (string)$s['spot_region'] . ' ' . (string)$s['board_text'] . ' ' . (string)$s['rig_text'] . ' ' . (string)$s['notes']);
        if (!str_contains($hay, strtolower($searchSpot))) {
            return false;
        }
    }
    return true;
}));

// Kennzahlen über die aktuell gefilterten Sessions berechnen
$totalSessionsCount = count($filteredSessions);
$uniqueWaterDays    = [];
$totalWaterMinutes  = 0;
$maxSpeedOverallKmh = 0.0;
$totalDistanceKm    = 0.0;
$windKnotsSum       = 0.0;
$windKnotsCnt       = 0;
$spotCounts         = [];
$monthlyMinutes     = array_fill(1, 12, 0);
$monthlySessions    = array_fill(1, 12, 0);

foreach ($filteredSessions as $s) {
    $uniqueWaterDays[(string)$s['session_date']] = true;
    $dur = (int)$s['duration_min'];
    $totalWaterMinutes += $dur;

    $mNum = (int)substr((string)$s['session_date'], 5, 2);
    if ($mNum >= 1 && $mNum <= 12) {
        $monthlyMinutes[$mNum]  += $dur;
        $monthlySessions[$mNum] += 1;
    }

    if ($s['max_speed_kmh'] !== null && (float)$s['max_speed_kmh'] > $maxSpeedOverallKmh) {
        $maxSpeedOverallKmh = (float)$s['max_speed_kmh'];
    }
    if ($s['distance_km'] !== null) {
        $totalDistanceKm += (float)$s['distance_km'];
    }
    if ($s['wind_knots_avg'] !== null && (float)$s['wind_knots_avg'] > 0) {
        $windKnotsSum += (float)$s['wind_knots_avg'];
        $windKnotsCnt++;
    }
    $sp = (string)$s['spot_name'];
    $spotCounts[$sp] = ($spotCounts[$sp] ?? 0) + 1;
}
arsort($spotCounts);
$favoriteSpotName  = !empty($spotCounts) ? (string)array_key_first($spotCounts) : '—';
$favoriteSpotCount = !empty($spotCounts) ? (int)reset($spotCounts) : 0;
$avgWindKnots      = $windKnotsCnt > 0 ? round($windKnotsSum / $windKnotsCnt, 1) : 0.0;

// Cloud-Fotos & Cloud-Videos des Benutzers für die Session-Verknüpfung laden
$stmtMedia = $pdo->prepare(
    "SELECT id, original_name, folder_path, mime_type
     FROM files
     WHERE user_id = :uid AND deleted_at IS NULL
       AND (
           mime_type LIKE 'image/%'
           OR mime_type LIKE 'video/%'
           OR LOWER(SUBSTRING_INDEX(original_name, '.', -1)) IN ('jpg','jpeg','png','webp','mp4','webm','mov')
       )
     ORDER BY upload_date DESC
     LIMIT 100"
);
$stmtMedia->execute([':uid' => $userId]);
$cloudMediaList = $stmtMedia->fetchAll();
$cloudMediaById = [];
foreach ($cloudMediaList as $cm) {
    $cloudMediaById[(int)$cm['id']] = $cm;
}

$pageTitle = '🏄‍♂️ Surf-Tagebuch & Session-Logbuch';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- KOPFBEREICH & SCHNELLWAHL -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    🏄‍♂️
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">
                            Surf-Tagebuch &amp; Material-Quiver
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-cyan-100 dark:bg-cyan-950/80 text-cyan-800 dark:text-cyan-300 border border-cyan-300/60 dark:border-cyan-800/60">
                            🌬️ Wind · 🪁 Kite · 🪽 Wing · 🌊 Surf · 📍 GPX
                        </span>
                    </div>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                        Dokumentiere deine Tage auf dem Wasser mit Windstärke (`kn` / `Bft`), 16er-Windrose, Wellenhöhe, gefahrenem Board &amp; Segel/Kite, GPS-Track-Import (`.gpx`) und persönlicher Material-Statistik.
                    </p>
                </div>
            </div>

            <!-- Haupt-Reiter-Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onclick="surfSwitchTab('sessions')"
                    id="surfTabBtn-sessions"
                    class="surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer <?= $activeTab === 'sessions' ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span>📖 Session-Logbuch (<?= count($allSessions) ?>)</span>
                </button>
                <button
                    type="button"
                    onclick="surfOpenNewSession()"
                    id="surfTabBtn-new"
                    class="surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer <?= $activeTab === 'new' ? 'bg-brand-600 text-white shadow-xs' : 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs' ?>"
                >
                    <span>➕ Neue Session eintragen</span>
                </button>
                <button
                    type="button"
                    onclick="surfSwitchTab('quiver')"
                    id="surfTabBtn-quiver"
                    class="surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer <?= $activeTab === 'quiver' ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span>🏄 Material-Quiver (<?= count($userGear) ?>)</span>
                </button>
                <button
                    type="button"
                    onclick="surfSwitchTab('stats')"
                    id="surfTabBtn-stats"
                    class="surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer <?= $activeTab === 'stats' ? 'bg-brand-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
                >
                    <span>📊 Jahres-Statistik</span>
                </button>
                <?php if (!empty($allSessions)): ?>
                    <form action="surflog.php" method="post" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="export_csv_cloud">
                        <button
                            type="submit"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors cursor-pointer"
                            title="Komplettes Surftagebuch als CSV-Tabelle in deiner MiniCloud speichern"
                        >
                            <span>☁️ CSV in Cloud</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- 5 KENNZAHLEN-KACHELN ÜBER DEM LOGBUCH -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mt-5 pt-5 border-t border-slate-200/80 dark:border-slate-800">
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">🌊 Wassertage &amp; Sessions</p>
                <p class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white mt-0.5">
                    <?= count($uniqueWaterDays) ?> <span class="text-xs font-semibold text-slate-500">Tage (<?= $totalSessionsCount ?> Sess.)</span>
                </p>
            </div>
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">⏱️ Zeit auf dem Wasser</p>
                <p class="text-lg sm:text-xl font-extrabold text-cyan-600 dark:text-cyan-400 mt-0.5">
                    <?= number_format($totalWaterMinutes / 60, 1, ',', '.') ?> <span class="text-xs font-semibold">Stunden</span>
                </p>
            </div>
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">⚡ Top-Speed &amp; Strecke</p>
                <p class="text-lg sm:text-xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-0.5">
                    <?= $maxSpeedOverallKmh > 0 ? number_format($maxSpeedOverallKmh, 1, ',', '.') . ' km/h' : '—' ?>
                    <?php if ($maxSpeedOverallKmh > 0): ?>
                        <span class="text-[11px] font-semibold text-slate-500">(<?= number_format($maxSpeedOverallKmh / 1.852, 1, ',', '.') ?> kn)</span>
                    <?php endif; ?>
                </p>
                <?php if ($totalDistanceKm > 0): ?>
                    <p class="text-[10px] text-slate-400 mt-0.5">Gesamtstrecke: <?= number_format($totalDistanceKm, 1, ',', '.') ?> km</p>
                <?php endif; ?>
            </div>
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">🌬️ Ø Windstärke</p>
                <p class="text-lg sm:text-xl font-extrabold text-amber-600 dark:text-amber-400 mt-0.5">
                    <?= $avgWindKnots > 0 ? number_format($avgWindKnots, 1, ',', '.') . ' kn' : '—' ?>
                    <?php if ($avgWindKnots > 0): ?>
                        <span class="text-xs font-semibold text-slate-500">(~<?= (int)knotsToBeaufort($avgWindKnots) ?> Bft)</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 col-span-2 sm:col-span-1">
                <p class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">📍 Top-Spot</p>
                <p class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white truncate mt-0.5" title="<?= e($favoriteSpotName) ?>">
                    <?= e($favoriteSpotName) ?>
                </p>
                <?php if ($favoriteSpotCount > 0): ?>
                    <p class="text-[10px] text-slate-400"><?= $favoriteSpotCount ?>× besucht</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- REITER 1: 📖 SESSION-LOGBUCH & FILTER                                 -->
    <!-- ===================================================================== -->
    <div id="surfPanel-sessions" class="surf-tab-panel <?= $activeTab === 'sessions' ? '' : 'hidden' ?> space-y-4">
        <!-- Filterleiste -->
        <form action="surflog.php" method="get" class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 flex flex-wrap items-center gap-3">
            <input type="hidden" name="tab" value="sessions">
            <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-600 dark:text-slate-300">📅 Jahr:</label>
                <select name="year" onchange="this.form.submit()" class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                    <option value="all" <?= $filterYear === 'all' ? 'selected' : '' ?>>Alle Jahre</option>
                    <?php foreach (array_keys($availableYears) as $yr): ?>
                        <option value="<?= e((string)$yr) ?>" <?= $filterYear === (string)$yr ? 'selected' : '' ?>><?= e((string)$yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-600 dark:text-slate-300">🏄 Disziplin:</label>
                <select name="sport" onchange="this.form.submit()" class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                    <option value="all" <?= $filterSport === 'all' ? 'selected' : '' ?>>Alle Sportarten</option>
                    <?php foreach ($sportCatalog as $sKey => $sMeta): ?>
                        <option value="<?= e($sKey) ?>" <?= $filterSport === $sKey ? 'selected' : '' ?>>
                            <?= e($sMeta['icon'] . ' ' . $sMeta['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex-1 min-w-[200px] flex items-center gap-2">
                <input
                    type="text"
                    name="q"
                    value="<?= e($searchSpot) ?>"
                    placeholder="🔍 Spot, Revier, Board, Segel oder Notiz suchen…"
                    class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                >
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold cursor-pointer">
                    Filtern
                </button>
                <?php if ($filterYear !== 'all' || $filterSport !== 'all' || $searchSpot !== ''): ?>
                    <a href="surflog.php" class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-semibold">
                        ✕ Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($filteredSessions)): ?>
            <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-10 text-center space-y-3">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-cyan-50 dark:bg-cyan-950/60 text-cyan-600 flex items-center justify-center text-3xl">
                    🏄‍♂️
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white">
                    Noch keine Surf-Sessions für diese Auswahl eingetragen
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                    Trage deine erste Session mit Spot, Windstärke, Segel-/Kitegröße und Board ein – oder importiere direkt eine `.gpx`-Datei deiner GPS-Uhr!
                </p>
                <div class="pt-2">
                    <button
                        type="button"
                        onclick="surfOpenNewSession()"
                        class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer"
                    >
                        ➕ Erste Surf-Session eintragen
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-3.5">
                <?php foreach ($filteredSessions as $sess):
                    $spInfo = $sportCatalog[(string)$sess['sport']] ?? $sportCatalog['windsurf'];
                    $durH   = floor((int)$sess['duration_min'] / 60);
                    $durM   = (int)$sess['duration_min'] % 60;
                    $durTxt = ($durH > 0 ? $durH . ' Std. ' : '') . ($durM > 0 ? $durM . ' Min.' : '');
                    $mediaItem = ($sess['media_file_id'] !== null && isset($cloudMediaById[(int)$sess['media_file_id']]))
                        ? $cloudMediaById[(int)$sess['media_file_id']]
                        : null;
                    $hasGpx = !empty($sess['gpx_track_json']);
                ?>
                    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-4 sm:p-5 hover:border-brand-300 dark:hover:border-brand-700 transition-colors">
                        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                            <div class="space-y-2.5 flex-1 min-w-0">
                                <!-- Zeile 1: Datum, Sport-Badge, Spot & Sterne-Bewertung -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2.5 py-0.5 rounded-lg text-xs font-extrabold <?= e($spInfo['badge']) ?>">
                                        <?= e($spInfo['icon'] . ' ' . $spInfo['label']) ?>
                                    </span>
                                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400 font-mono">
                                        📅 <?= e(date('d.m.Y', strtotime((string)$sess['session_date']))) ?>
                                        <?= $sess['start_time'] !== '' ? ' · ' . e((string)$sess['start_time']) . ' Uhr' : '' ?>
                                    </span>
                                    <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-[11px] font-semibold">
                                        ⏱️ <?= e($durTxt) ?>
                                    </span>
                                    <span class="text-amber-400 text-xs tracking-tight" title="Session-Bewertung: <?= (int)$sess['rating'] ?>/5">
                                        <?= str_repeat('★', (int)$sess['rating']) . str_repeat('☆', max(0, 5 - (int)$sess['rating'])) ?>
                                    </span>
                                </div>

                                <!-- Zeile 2: Spot-Titel & Bedingungen -->
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <h3 class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                                        📍 <?= e((string)$sess['spot_name']) ?>
                                    </h3>
                                    <?php if ((string)$sess['spot_region'] !== ''): ?>
                                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                                            (<?= e((string)$sess['spot_region']) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Zeile 3: Wind, Welle, Temperatur & Speed Badges -->
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <?php if ($sess['wind_knots_avg'] !== null || $sess['wind_bft'] !== null): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-cyan-50 dark:bg-cyan-950/70 text-cyan-800 dark:text-cyan-200 border border-cyan-200/70 dark:border-cyan-800/70 font-bold">
                                            🌬️
                                            <?php if ($sess['wind_knots_avg'] !== null): ?>
                                                <?= e(number_format((float)$sess['wind_knots_avg'], 1, ',', '.')) ?> kn
                                            <?php endif; ?>
                                            <?php if ($sess['wind_knots_max'] !== null): ?>
                                                (Böen <?= e(number_format((float)$sess['wind_knots_max'], 1, ',', '.')) ?> kn)
                                            <?php endif; ?>
                                            <?php if ($sess['wind_bft'] !== null): ?>
                                                · <strong><?= (int)$sess['wind_bft'] ?> Bft</strong>
                                            <?php endif; ?>
                                            <?php if ((string)$sess['wind_dir'] !== ''): ?>
                                                · 🧭 <?= e((string)$sess['wind_dir']) ?>
                                            <?php endif; ?>
                                            <?php if ((string)$sess['wind_angle'] !== ''): ?>
                                                (<?= e((string)$sess['wind_angle']) ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($sess['wave_height_m'] !== null || (string)$sess['water_condition'] !== ''): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-blue-50 dark:bg-blue-950/70 text-blue-800 dark:text-blue-200 border border-blue-200/70 dark:border-blue-800/70 font-semibold">
                                            🌊
                                            <?= $sess['wave_height_m'] !== null ? e(number_format((float)$sess['wave_height_m'], 1, ',', '.')) . ' m' : '' ?>
                                            <?= (string)$sess['water_condition'] !== '' ? ' ' . e((string)$sess['water_condition']) : '' ?>
                                            <?= (string)$sess['tide'] !== '' ? ' · ' . e((string)$sess['tide']) : '' ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($sess['temp_air_c'] !== null || $sess['temp_water_c'] !== null): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 border border-amber-200/70 dark:border-amber-800/70 font-semibold">
                                            🌡️
                                            <?= $sess['temp_air_c'] !== null ? 'Luft ' . (int)$sess['temp_air_c'] . '°C' : '' ?>
                                            <?= ($sess['temp_air_c'] !== null && $sess['temp_water_c'] !== null) ? ' / ' : '' ?>
                                            <?= $sess['temp_water_c'] !== null ? 'Wasser ' . (int)$sess['temp_water_c'] . '°C' : '' ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($sess['max_speed_kmh'] !== null || $sess['distance_km'] !== null): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-emerald-50 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-200 border border-emerald-200/70 dark:border-emerald-800/70 font-bold">
                                            ⚡
                                            <?php if ($sess['max_speed_kmh'] !== null): ?>
                                                Max <?= e(number_format((float)$sess['max_speed_kmh'], 1, ',', '.')) ?> km/h (<?= e(number_format((float)$sess['max_speed_kmh'] / 1.852, 1, ',', '.')) ?> kn)
                                            <?php endif; ?>
                                            <?php if ($sess['distance_km'] !== null): ?>
                                                · 📏 <?= e(number_format((float)$sess['distance_km'], 1, ',', '.')) ?> km
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Zeile 4: Gefahrenes Material -->
                                <?php if ($sess['board_text'] !== '' || $sess['rig_text'] !== '' || $sess['fin_foil_text'] !== '' || $sess['wetsuit_text'] !== ''): ?>
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-700 dark:text-slate-300 pt-0.5">
                                        <?php if ($sess['board_text'] !== ''): ?>
                                            <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 font-semibold">
                                                🏄 Board: <strong><?= e((string)$sess['board_text']) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($sess['rig_text'] !== ''): ?>
                                            <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 font-semibold">
                                                ⛵ Segel/Kite/Wing: <strong><?= e((string)$sess['rig_text']) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($sess['fin_foil_text'] !== ''): ?>
                                            <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800">
                                                🦈 <?= e((string)$sess['fin_foil_text']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($sess['wetsuit_text'] !== ''): ?>
                                            <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800">
                                                🤿 <?= e((string)$sess['wetsuit_text']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Zeile 5: Manöver & Notizen -->
                                <?php if ($sess['maneuvers'] !== ''): ?>
                                    <p class="text-xs font-semibold text-violet-700 dark:text-violet-300">
                                        🏆 Manöver &amp; Highlights: <?= e((string)$sess['maneuvers']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ((string)$sess['notes'] !== ''): ?>
                                    <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 whitespace-pre-line bg-slate-50/80 dark:bg-slate-800/40 p-3 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                                        <?= e((string)$sess['notes']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Rechts: Verknüpftes Foto/Video, GPX-Map-Button & Bearbeiten/Löschen -->
                            <div class="flex sm:flex-row lg:flex-col items-end justify-between gap-2 shrink-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?php if ($hasGpx): ?>
                                        <button
                                            type="button"
                                            onclick='surfShowGpxModal(<?= json_encode((string)$sess["spot_name"]) ?>, <?= (int)$sess["id"] ?>)'
                                            class="px-2.5 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/70 hover:bg-emerald-600 hover:text-white text-emerald-700 dark:text-emerald-300 border border-emerald-300/70 dark:border-emerald-800/70 text-xs font-bold transition-colors cursor-pointer"
                                        >
                                            📍 GPS-Spur ansehen
                                        </button>
                                        <script id="surfGpxData-<?= (int)$sess['id'] ?>" type="application/json"><?= $sess['gpx_track_json'] ?></script>
                                    <?php endif; ?>

                                    <?php if ($mediaItem !== null): ?>
                                        <a
                                            href="download.php?id=<?= (int)$mediaItem['id'] ?>&amp;inline=1"
                                            target="_blank"
                                            class="px-2.5 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-600 hover:text-white text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-bold transition-colors"
                                        >
                                            🖼️ <?= e(mb_strimwidth((string)$mediaItem['original_name'], 0, 22, '…')) ?>
                                        </a>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        onclick='surfEditSession(<?= json_encode($sess, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                        class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-700 dark:text-slate-200 text-xs font-semibold transition-colors cursor-pointer"
                                        title="Session bearbeiten"
                                    >
                                        ✏️ Bearbeiten
                                    </button>
                                    <form action="surflog.php" method="post" class="m-0" onsubmit="return confirm('Diese Surf-Session wirklich löschen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_session">
                                        <input type="hidden" name="session_id" value="<?= (int)$sess['id'] ?>">
                                        <button
                                            type="submit"
                                            class="px-2.5 py-1.5 rounded-xl bg-red-50 dark:bg-red-950/40 hover:bg-red-600 hover:text-white text-red-600 dark:text-red-400 text-xs font-semibold transition-colors cursor-pointer"
                                            title="Session löschen"
                                        >
                                            🗑️
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===================================================================== -->
    <!-- REITER 2: ➕ NEUE SURF-SESSION EINTRAGEN / BEARBEITEN (+ GPX-IMPORT)   -->
    <!-- ===================================================================== -->
    <div id="surfPanel-new" class="surf-tab-panel <?= $activeTab === 'new' ? '' : 'hidden' ?>">
        <form action="surflog.php" method="post" id="surfSessionForm" class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 sm:p-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="session_id" id="sf_session_id" value="0">
            <input type="hidden" name="gpx_track_json" id="sf_gpx_track_json" value="">

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-200/80 dark:border-slate-800">
                <div>
                    <h2 id="sfFormHeading" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        ➕ Neue Surf-Session ins Tagebuch eintragen
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Tipp: Falls du eine `.gpx`-Datei deiner GPS-Uhr hast, wähle sie rechts aus – Datum, Dauer, Distanz &amp; Top-Speed werden automatisch ausgefüllt!
                    </p>
                </div>

                <!-- GPX-Datei-Direktimport -->
                <div class="flex items-center gap-2">
                    <label class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-xs transition-colors cursor-pointer">
                        <span>📍 GPX-Track von Uhr importieren (.gpx)</span>
                        <input type="file" accept=".gpx,application/gpx+xml,text/xml" class="hidden" onchange="if(this.files[0]) surfParseGpxFile(this.files[0])">
                    </label>
                </div>
            </div>

            <!-- Live-GPX-Vorschau-Box (erscheint automatisch nach GPX-Auswahl) -->
            <div id="sfGpxPreviewBox" class="hidden p-4 rounded-2xl bg-slate-950 border border-emerald-500/50 text-white space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-xs font-bold text-emerald-400">📍 GPS-Track erfolgreich analysiert (Speed-Heatmap)</p>
                        <p id="sfGpxSummaryText" class="text-xs text-slate-300"></p>
                    </div>
                    <button type="button" onclick="surfClearGpxTrack()" class="text-xs text-slate-400 hover:text-red-400 cursor-pointer">✕ GPX entfernen</button>
                </div>
                <canvas id="sfGpxCanvas" width="680" height="240" class="w-full h-48 rounded-xl bg-slate-900 border border-slate-800 object-contain"></canvas>
            </div>

            <!-- 1. Basisdaten: Datum, Uhrzeit, Dauer, Sportart, Spot & Region -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">📅 Datum *</label>
                    <input type="date" name="session_date" id="sf_session_date" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">🕒 Startzeit</label>
                    <input type="time" name="start_time" id="sf_start_time" value="13:30" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">⏱️ Wasserdauer (Min.)</label>
                    <input type="number" name="duration_min" id="sf_duration_min" min="5" max="1440" step="5" value="120" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">🏄‍♂️ Sportart</label>
                    <select name="sport" id="sf_sport" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold">
                        <?php foreach ($sportCatalog as $sKey => $sMeta): ?>
                            <option value="<?= e($sKey) ?>"><?= e($sMeta['icon'] . ' ' . $sMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">📍 Spot / Strand / See *</label>
                    <input type="text" name="spot_name" id="sf_spot_name" list="surfKnownSpotsList" required placeholder="z. B. Fehmarn Gold / Brouwersdam" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold">
                    <datalist id="surfKnownSpotsList">
                        <?php foreach (array_keys($knownSpots) as $sp): ?>
                            <option value="<?= e((string)$sp) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">🗺️ Region / Land</label>
                    <input type="text" name="spot_region" id="sf_spot_region" placeholder="z. B. Ostsee / Zeeland / Gardasee" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                </div>
            </div>

            <!-- 2. Wind, interaktive 16er-Windrose, Welle & Temperaturen -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100">
                        🌬️ Wind, Windrose, Welle &amp; Wetterbedingungen
                    </h3>
                    <span id="sfBftLiveHint" class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-cyan-100 dark:bg-cyan-950 text-cyan-800 dark:text-cyan-300">
                        Knoten eingeben → Beaufort wird automatisch berechnet
                    </span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🌬️ Grundwind Ø (Knoten)</label>
                        <input type="number" step="0.5" min="0" max="80" name="wind_knots_avg" id="sf_wind_knots_avg" oninput="surfAutoCalcBft()" placeholder="z. B. 18" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">💨 Böen Max (Knoten)</label>
                        <input type="number" step="0.5" min="0" max="95" name="wind_knots_max" id="sf_wind_knots_max" oninput="surfAutoCalcBft()" placeholder="z. B. 24" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🚩 Beaufort (0–12 Bft)</label>
                        <input type="number" min="0" max="12" name="wind_bft" id="sf_wind_bft" placeholder="z. B. 5" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🧭 Windrichtung</label>
                        <select name="wind_dir" id="sf_wind_dir" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-bold">
                            <option value="">— wählen —</option>
                            <?php foreach (['N','NNO','NO','ONO','O','OSO','SO','SSO','S','SSW','SW','WSW','W','WNW','NW','NNW'] as $dir): ?>
                                <option value="<?= $dir ?>"><?= $dir ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">📐 Winkel zum Ufer</label>
                        <select name="wind_angle" id="sf_wind_angle" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                            <option value="">— optional —</option>
                            <option value="Sideshore">➡️ Sideshore (parallel)</option>
                            <option value="Side-Onshore">↘️ Side-Onshore (schräg auflandig)</option>
                            <option value="Onshore">⬇️ Onshore (auflandig)</option>
                            <option value="Side-Offshore">↗️ Side-Offshore (schräg ablandig)</option>
                            <option value="Offshore">⬆️ Offshore (ablandig)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🌊 Wellenhöhe (m)</label>
                        <input type="number" step="0.1" min="0" max="15" name="wave_height_m" id="sf_wave_height_m" placeholder="z. B. 1.2" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                    </div>
                </div>

                <!-- 1-Klick Windrichtungs-Kompass-Buttons -->
                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                    <span class="text-[11px] font-semibold text-slate-500 mr-1">🧭 Schnellwahl Windrose:</span>
                    <?php foreach (['N','NO','O','SO','S','SW','W','NW','WNW','WSW','NNW','SSO'] as $qDir): ?>
                        <button
                            type="button"
                            onclick="document.getElementById('sf_wind_dir').value='<?= $qDir ?>'"
                            class="px-2 py-1 rounded-lg bg-white dark:bg-slate-900 hover:bg-brand-600 hover:text-white text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 text-[11px] font-bold transition-colors cursor-pointer"
                        >
                            <?= $qDir ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-1">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">💧 Wasserzustand</label>
                        <select name="water_condition" id="sf_water_condition" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="">— wählen —</option>
                            <option value="Flachwasser / Speed">🪞 Flachwasser / Speed-Piste</option>
                            <option value="Kabbelwelle (Chop)">〰️ Kabbelwelle (Chop)</option>
                            <option value="Dünungswelle (Swell)">🌊 Dünungswelle (Swell)</option>
                            <option value="Saubere Brandungswelle">🏄 Saubere Brandungswelle</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🌙 Gezeiten (Tide)</label>
                        <select name="tide" id="sf_tide" class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                            <option value="">— unabhängig / Binnenrevier —</option>
                            <option value="Auflaufend (Flut)">📈 Auflaufend (Flut)</option>
                            <option value="Hochwasser">🌕 Hochwasser (High Tide)</option>
                            <option value="Ablaufend (Ebbe)">📉 Ablaufend (Ebbe)</option>
                            <option value="Niedrigwasser">🌑 Niedrigwasser (Low Tide)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">☀️ Lufttemperatur (°C)</label>
                        <input type="number" name="temp_air_c" id="sf_temp_air_c" placeholder="z. B. 21" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🌊 Wassertemperatur (°C)</label>
                        <input type="number" name="temp_water_c" id="sf_temp_water_c" placeholder="z. B. 17" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                    </div>
                </div>
            </div>

            <!-- 3. Gefahrenes Material (Auswahl aus eigenem Quiver oder Freitext) -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100">
                        🏄‍♂️ Gefahrenes Material (aus deinem Quiver wählen oder direkt eintippen)
                    </h3>
                    <button type="button" onclick="surfSwitchTab('quiver')" class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer">
                        ⚙️ Eigenen Quiver verwalten &rarr;
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🏄 Board / Brett</label>
                        <?php if (!empty($gearBoards)): ?>
                            <select name="board_gear_id" id="sf_board_gear_id" onchange="if(this.selectedIndex>0) document.getElementById('sf_board_text').value=this.options[this.selectedIndex].dataset.label;" class="w-full mb-1.5 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="">— Aus Quiver wählen —</option>
                                <?php foreach ($gearBoards as $gb):
                                    $lbl = trim($gb['brand'] . ' ' . $gb['model'] . ($gb['size_spec'] !== '' ? ' (' . $gb['size_spec'] . ')' : ''));
                                ?>
                                    <option value="<?= (int)$gb['id'] ?>" data-label="<?= e($lbl) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input type="text" name="board_text" id="sf_board_text" placeholder="z. B. JP FreestyleWave 104 L" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">⛵ Segel / Kite / Wing (m²)</label>
                        <?php if (!empty($gearRigs)): ?>
                            <select name="rig_gear_id" id="sf_rig_gear_id" onchange="if(this.selectedIndex>0) document.getElementById('sf_rig_text').value=this.options[this.selectedIndex].dataset.label;" class="w-full mb-1.5 px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                                <option value="">— Aus Quiver wählen —</option>
                                <?php foreach ($gearRigs as $gr):
                                    $lbl = trim($gr['brand'] . ' ' . $gr['model'] . ($gr['size_spec'] !== '' ? ' (' . $gr['size_spec'] . ')' : ''));
                                ?>
                                    <option value="<?= (int)$gr['id'] ?>" data-label="<?= e($lbl) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <input type="text" name="rig_text" id="sf_rig_text" placeholder="z. B. NeilPryde Combat 5.3 m²" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🦈 Finne / Foil</label>
                        <input type="text" name="fin_foil_text" id="sf_fin_foil_text" list="surfGearFoilsList" placeholder="z. B. 28 cm Wave / 1100er Foil" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                        <datalist id="surfGearFoilsList">
                            <?php foreach ($gearFoils as $gf): ?>
                                <option value="<?= e(trim($gf['brand'] . ' ' . $gf['model'] . ' ' . $gf['size_spec'])) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div>
                        <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">🤿 Neopren / Schuhe / Haube</label>
                        <input type="text" name="wetsuit_text" id="sf_wetsuit_text" list="surfGearSuitsList" placeholder="z. B. 5/4 mm Steamer" class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                        <datalist id="surfGearSuitsList">
                            <option value="5/4 mm Steamer + Haube & Schuhe"></option>
                            <option value="5/4 mm Steamer"></option>
                            <option value="4/3 mm Vollanzug"></option>
                            <option value="3/2 mm Sommer-Steamer"></option>
                            <option value="Shorty 2 mm"></option>
                            <option value="Boardshorts / Lycra"></option>
                            <?php foreach ($gearSuits as $gs): ?>
                                <option value="<?= e(trim($gs['brand'] . ' ' . $gs['model'] . ' ' . $gs['size_spec'])) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>

            <!-- 4. GPS-Messwerte, Manöver, Cloud-Foto/Video & Bewertung -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">⚡ Top-Speed (km/h)</label>
                    <input type="number" step="0.1" min="0" max="120" name="max_speed_kmh" id="sf_max_speed_kmh" placeholder="z. B. 48.5" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">📏 Gefahrene Distanz (km)</label>
                    <input type="number" step="0.1" min="0" max="500" name="distance_km" id="sf_distance_km" placeholder="z. B. 34.2" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono font-bold">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">🖼️ Cloud-Foto oder Video verknüpfen</label>
                    <select name="media_file_id" id="sf_media_file_id" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                        <option value="">— Kein Medium verknüpft —</option>
                        <?php foreach ($cloudMediaList as $cm): ?>
                            <option value="<?= (int)$cm['id'] ?>">
                                <?= str_starts_with((string)$cm['mime_type'], 'video/') ? '🎬' : '📷' ?>
                                <?= e((string)$cm['original_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">⭐ Session-Bewertung</label>
                    <select name="rating" id="sf_rating" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-bold text-amber-500">
                        <option value="5">★★★★★ (5/5 – Legendärer Tag!)</option>
                        <option value="4">★★★★☆ (4/5 – Richtig gute Session)</option>
                        <option value="3">★★★☆☆ (3/5 – Solider Wassertag)</option>
                        <option value="2">★★☆☆☆ (2/5 – Mühsam / Flaute / Böig)</option>
                        <option value="1">★☆☆☆☆ (1/5 – Materialbruch / Reinfall)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">🏆 Gestandene Manöver / Highlights</label>
                    <input type="text" name="maneuvers" id="sf_maneuvers" placeholder="z. B. Durchgeglittene Powerhalsen, Duck Jibe, erste Foil-Flüge…" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">📝 Persönliche Notizen &amp; Erkenntnisse (z. B. Trimm / Mastfußposition)</label>
                    <textarea name="notes" id="sf_notes" rows="2" placeholder="Wie hat das Segel/Board gepasst? Wer war mit auf dem Wasser?" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-200/80 dark:border-slate-800">
                <button type="button" onclick="surfSwitchTab('sessions')" class="px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer">
                    Abbrechen
                </button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer">
                    💾 Surf-Session speichern
                </button>
            </div>
        </form>
    </div>

    <!-- ===================================================================== -->
    <!-- REITER 3: 🏄 MATERIAL-QUIVER (BOARDS, SEGEL/KITES, FOILS & NEOPREN)   -->
    <!-- ===================================================================== -->
    <div id="surfPanel-quiver" class="surf-tab-panel <?= $activeTab === 'quiver' ? '' : 'hidden' ?> space-y-5">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            <!-- Links: Neues Material zum Quiver hinzufügen -->
            <form action="surflog.php" method="post" class="lg:col-span-4 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="add_gear">

                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    ➕ Material zum Quiver hinzufügen
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Einmal angelegt, kannst du deine Boards &amp; Segel/Kites bei jeder Session mit 1 Klick auswählen und siehst genau, wie oft du welches Teil fährst.
                </p>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Kategorie</label>
                    <select name="category" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                        <?php foreach ($gearCategories as $cKey => $cMeta): ?>
                            <option value="<?= e($cKey) ?>"><?= e($cMeta['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Hersteller / Marke</label>
                        <input type="text" name="brand" placeholder="z. B. Duotone / JP / Fanatic" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Modell</label>
                        <input type="text" name="model" placeholder="z. B. Magic Ride / Rebel" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2.5">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Größe / Volumen</label>
                        <input type="text" name="size_spec" placeholder="105 L / 5.3 m²" class="w-full px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Kaufpreis (€)</label>
                        <input type="number" step="1" min="0" name="purchase_price" placeholder="optional" class="w-full px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Modelljahr</label>
                        <input type="number" min="1980" max="2035" name="purchase_year" placeholder="<?= date('Y') ?>" class="w-full px-2.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1">Notiz (z. B. Mastlänge 400 cm / Gabel 160 cm)</label>
                    <input type="text" name="notes" placeholder="Optimale Einstellung, Vorliek/Schothorn-Trimm…" class="w-full px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs">
                </div>

                <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-xs cursor-pointer">
                    🏄 Zum Quiver hinzufügen
                </button>
            </form>

            <!-- Rechts: Übersicht des vorhandenen Materials inkl. Nutzungs- & Cost-per-Session-Auswertung -->
            <div class="lg:col-span-8 mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 space-y-4">
                <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    🏄‍♂️ Dein Material-Schrank (Quiver) &amp; Nutzungsstatistik
                </h2>

                <?php if (empty($userGear)): ?>
                    <p class="text-xs text-slate-500 py-8 text-center">
                        Noch kein Material im Quiver hinterlegt. Füge links deine Boards, Segel, Kites oder Wings hinzu!
                    </p>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php foreach ($userGear as $g):
                            $catMeta = $gearCategories[(string)$g['category']] ?? $gearCategories['board'];
                            $uCnt    = (int)$g['usage_count'];
                            $uHours  = round(((int)$g['usage_minutes']) / 60, 1);
                            $costPerSession = ($g['purchase_price'] !== null && (float)$g['purchase_price'] > 0 && $uCnt > 0)
                                ? ((float)$g['purchase_price'] / $uCnt)
                                : null;
                        ?>
                            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between gap-2">
                                <div>
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400">
                                            <?= e($catMeta['label']) ?>
                                        </span>
                                        <?php if ($g['size_spec'] !== ''): ?>
                                            <span class="px-2 py-0.5 rounded-md bg-cyan-100 dark:bg-cyan-950 text-cyan-800 dark:text-cyan-300 text-xs font-extrabold font-mono">
                                                <?= e((string)$g['size_spec']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm font-extrabold text-slate-900 dark:text-white mt-1">
                                        <?= e(trim((string)$g['brand'] . ' ' . (string)$g['model'])) ?>
                                        <?= $g['purchase_year'] !== null ? ' (' . (int)$g['purchase_year'] . ')' : '' ?>
                                    </p>
                                    <?php if ($g['notes'] !== ''): ?>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5"><?= e((string)$g['notes']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-200/60 dark:border-slate-700/60 text-[11px]">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold text-emerald-700 dark:text-emerald-400">
                                            🌊 <?= $uCnt ?>× gefahren (<?= number_format($uHours, 1, ',', '.') ?> Std.)
                                        </span>
                                        <?php if ($costPerSession !== null): ?>
                                            <span class="text-slate-500" title="Kaufpreis geteilt durch Anzahl der Sessions">
                                                · 💰 <?= number_format($costPerSession, 2, ',', '.') ?> €/Sess.
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <form action="surflog.php" method="post" class="m-0" onsubmit="return confirm('Dieses Material aus dem Quiver entfernen?');">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_gear">
                                        <input type="hidden" name="gear_id" value="<?= (int)$g['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-bold cursor-pointer">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- REITER 4: 📊 JAHRES- & MONATSSTATISTIK                                -->
    <!-- ===================================================================== -->
    <div id="surfPanel-stats" class="surf-tab-panel <?= $activeTab === 'stats' ? '' : 'hidden' ?> space-y-5">
        <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 space-y-4">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                📊 Monatsverteilung der Wasserstunden (Januar – Dezember)
            </h2>
            <?php
            $maxMonthMin = max(60, max($monthlyMinutes));
            $monthLabels = [1=>'Jan',2=>'Feb',3=>'Mär',4=>'Apr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Dez'];
            ?>
            <div class="grid grid-cols-12 gap-2 items-end h-44 pt-4 px-2 bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                <?php foreach ($monthLabels as $mIdx => $mName):
                    $mins = $monthlyMinutes[$mIdx];
                    $hrs  = round($mins / 60, 1);
                    $pct  = min(100, round(($mins / $maxMonthMin) * 100));
                ?>
                    <div class="flex flex-col items-center justify-end h-full gap-1">
                        <span class="text-[10px] font-mono font-bold text-slate-600 dark:text-slate-300">
                            <?= $hrs > 0 ? $hrs . 'h' : '' ?>
                        </span>
                        <div class="w-full max-w-[34px] bg-gradient-to-t from-blue-600 to-cyan-400 rounded-t-lg transition-all" style="height: <?= max($hrs > 0 ? 10 : 2, $pct) ?>%"></div>
                        <span class="text-[10px] font-bold text-slate-500 pb-1"><?= $mName ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- GPX-SPUR LIGHTBOX MODAL -->
<div id="surfGpxModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-4" onclick="if(event.target===this) this.classList.add('hidden')">
    <div class="mc-card w-full max-w-2xl rounded-2xl bg-slate-900 border border-slate-700 p-5 text-white space-y-4">
        <div class="flex items-center justify-between">
            <h3 id="surfGpxModalTitle" class="text-base font-bold">📍 GPS-Track &amp; Speed-Heatmap</h3>
            <button type="button" onclick="document.getElementById('surfGpxModal').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold cursor-pointer">✕</button>
        </div>
        <canvas id="surfGpxModalCanvas" width="680" height="360" class="w-full h-72 rounded-xl bg-slate-950 border border-slate-800"></canvas>
        <div class="flex items-center justify-between text-xs text-slate-400">
            <span>🔵 Langsam / Dümpeln</span>
            <span>🟢 Gleiten</span>
            <span>🟡 Schnell</span>
            <span>🔴 Top-Speed-Run</span>
        </div>
    </div>
</div>

<script>
function surfSwitchTab(tabKey) {
    document.querySelectorAll('.surf-tab-panel').forEach(p => p.classList.add('hidden'));
    const target = document.getElementById('surfPanel-' + tabKey);
    if (target) target.classList.remove('hidden');

    document.querySelectorAll('.surf-tab-btn').forEach(btn => {
        if (btn.id === 'surfTabBtn-new') {
            btn.className = 'surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs';
        } else {
            btn.className = 'surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700';
        }
    });
    const active = document.getElementById('surfTabBtn-' + tabKey);
    if (active) {
        active.className = 'surf-tab-btn inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold transition-colors cursor-pointer bg-brand-600 text-white shadow-xs';
    }
}

function surfOpenNewSession() {
    document.getElementById('surfSessionForm').reset();
    document.getElementById('sf_session_id').value = '0';
    document.getElementById('sf_session_date').value = new Date().toISOString().slice(0, 10);
    document.getElementById('sfFormHeading').textContent = '➕ Neue Surf-Session ins Tagebuch eintragen';
    surfClearGpxTrack();
    surfSwitchTab('new');
}

function surfEditSession(sess) {
    surfSwitchTab('new');
    document.getElementById('sfFormHeading').textContent = '✏️ Surf-Session in „' + (sess.spot_name || '') + '“ bearbeiten';
    document.getElementById('sf_session_id').value       = sess.id || 0;
    document.getElementById('sf_session_date').value     = sess.session_date || '';
    document.getElementById('sf_start_time').value       = sess.start_time || '';
    document.getElementById('sf_duration_min').value     = sess.duration_min || 90;
    document.getElementById('sf_sport').value            = sess.sport || 'windsurf';
    document.getElementById('sf_spot_name').value        = sess.spot_name || '';
    document.getElementById('sf_spot_region').value      = sess.spot_region || '';
    document.getElementById('sf_wind_knots_avg').value   = sess.wind_knots_avg ?? '';
    document.getElementById('sf_wind_knots_max').value   = sess.wind_knots_max ?? '';
    document.getElementById('sf_wind_bft').value         = sess.wind_bft ?? '';
    document.getElementById('sf_wind_dir').value         = sess.wind_dir || '';
    document.getElementById('sf_wind_angle').value       = sess.wind_angle || '';
    document.getElementById('sf_wave_height_m').value    = sess.wave_height_m ?? '';
    document.getElementById('sf_water_condition').value  = sess.water_condition || '';
    document.getElementById('sf_tide').value             = sess.tide || '';
    document.getElementById('sf_temp_air_c').value       = sess.temp_air_c ?? '';
    document.getElementById('sf_temp_water_c').value     = sess.temp_water_c ?? '';
    document.getElementById('sf_board_text').value       = sess.board_text || '';
    document.getElementById('sf_rig_text').value         = sess.rig_text || '';
    document.getElementById('sf_fin_foil_text').value    = sess.fin_foil_text || '';
    document.getElementById('sf_wetsuit_text').value     = sess.wetsuit_text || '';
    document.getElementById('sf_max_speed_kmh').value    = sess.max_speed_kmh ?? '';
    document.getElementById('sf_distance_km').value      = sess.distance_km ?? '';
    document.getElementById('sf_maneuvers').value        = sess.maneuvers || '';
    document.getElementById('sf_rating').value           = sess.rating || 5;
    document.getElementById('sf_notes').value            = sess.notes || '';
    if (document.getElementById('sf_media_file_id')) {
        document.getElementById('sf_media_file_id').value = sess.media_file_id || '';
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function surfAutoCalcBft() {
    const avg = parseFloat(document.getElementById('sf_wind_knots_avg').value);
    const max = parseFloat(document.getElementById('sf_wind_knots_max').value);
    const kn  = !isNaN(avg) && avg > 0 ? avg : (!isNaN(max) && max > 0 ? max : 0);
    if (kn <= 0) return;

    const bftNames = [
        '0 Bft (Windstille)',
        '1 Bft (Leiser Zug)',
        '2 Bft (Leichte Brise)',
        '3 Bft (Schwache Brise · Foil/Leichtwind)',
        '4 Bft (Mäßige Brise · Gleitwind)',
        '5 Bft (Frische Brise · Top-Gleitwind!)',
        '6 Bft (Starker Wind · Starkwind!)',
        '7 Bft (Steifer Wind · kleines Besteck!)',
        '8 Bft (Stürmischer Wind)',
        '9 Bft (Sturm)',
        '10 Bft (Schwerer Sturm)',
        '11 Bft (Orkanartiger Sturm)',
        '12 Bft (Orkan)'
    ];
    let bft = 12;
    if (kn < 1) bft = 0;
    else if (kn <= 3) bft = 1;
    else if (kn <= 6) bft = 2;
    else if (kn <= 10) bft = 3;
    else if (kn <= 15) bft = 4;
    else if (kn <= 21) bft = 5;
    else if (kn <= 27) bft = 6;
    else if (kn <= 33) bft = 7;
    else if (kn <= 40) bft = 8;
    else if (kn <= 47) bft = 9;
    else if (kn <= 55) bft = 10;
    else if (kn <= 63) bft = 11;

    document.getElementById('sf_wind_bft').value = String(bft);
    document.getElementById('sfBftLiveHint').textContent = '🌬️ ' + kn + ' kn = ' + bftNames[bft];
}

// ============================================================================
// 📍 LOKALER GPX-TRACK-PARSER & SPEED-HEATMAP-ZEICHNER
// ============================================================================
function surfHaversineKm(lat1, lon1, lat2, lon2) {
    const R = 6371.0;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

async function surfParseGpxFile(file) {
    try {
        const text = await file.text();
        const xml  = new DOMParser().parseFromString(text, 'application/xml');
        const pts  = Array.from(xml.querySelectorAll('trkpt, rtept'));
        if (pts.length < 2) {
            alert('Die GPX-Datei enthält keine gültigen GPS-Trackpunkte (<trkpt>).');
            return;
        }

        let totalKm = 0;
        let maxSpeedKmh = 0;
        let firstTime = null;
        let lastTime  = null;
        const rawTrack = [];

        for (let i = 0; i < pts.length; i++) {
            const lat = parseFloat(pts[i].getAttribute('lat') || '0');
            const lon = parseFloat(pts[i].getAttribute('lon') || '0');
            const tEl = pts[i].querySelector('time');
            const ts  = tEl ? Date.parse(tEl.textContent.trim()) : NaN;

            if (!isNaN(ts)) {
                if (firstTime === null) firstTime = ts;
                lastTime = ts;
            }

            let segSpeed = 0;
            if (i > 0) {
                const prev = rawTrack[rawTrack.length - 1];
                const dKm  = surfHaversineKm(prev.lat, prev.lon, lat, lon);
                if (dKm < 0.5) {
                    totalKm += dKm;
                    if (!isNaN(ts) && !isNaN(prev.ts) && ts > prev.ts) {
                        const dtHours = (ts - prev.ts) / 3600000;
                        if (dtHours > 0) {
                            segSpeed = dKm / dtHours;
                            if (segSpeed < 95 && segSpeed > maxSpeedKmh) {
                                maxSpeedKmh = segSpeed;
                            }
                        }
                    }
                }
            }
            rawTrack.push({ lat, lon, ts, spd: Math.round(segSpeed * 10) / 10 });
        }

        // Auf max. 350 repräsentative Punkte komprimieren für kompakte DB-Speicherung
        const step = Math.max(1, Math.floor(rawTrack.length / 350));
        const compactPoints = [];
        for (let i = 0; i < rawTrack.length; i += step) {
            const p = rawTrack[i];
            compactPoints.push([Number(p.lat.toFixed(5)), Number(p.lon.toFixed(5)), p.spd]);
        }

        document.getElementById('sf_gpx_track_json').value = JSON.stringify(compactPoints);
        document.getElementById('sf_distance_km').value    = totalKm.toFixed(2);
        if (maxSpeedKmh > 0) {
            document.getElementById('sf_max_speed_kmh').value = maxSpeedKmh.toFixed(1);
        }
        if (firstTime !== null) {
            const dObj = new Date(firstTime);
            document.getElementById('sf_session_date').value = dObj.toISOString().slice(0, 10);
            document.getElementById('sf_start_time').value   = dObj.toTimeString().slice(0, 5);
            if (lastTime !== null && lastTime > firstTime) {
                const durMin = Math.max(5, Math.round((lastTime - firstTime) / 60000));
                document.getElementById('sf_duration_min').value = String(durMin);
            }
        }

        document.getElementById('sfGpxPreviewBox').classList.remove('hidden');
        document.getElementById('sfGpxSummaryText').textContent =
            `${pts.length} GPS-Punkte · Distanz: ${totalKm.toFixed(2)} km · Top-Speed: ${maxSpeedKmh.toFixed(1)} km/h (${(maxSpeedKmh / 1.852).toFixed(1)} kn)`;
        surfDrawGpxTrackOnCanvas(document.getElementById('sfGpxCanvas'), compactPoints);
    } catch (e) {
        alert('GPX-Datei konnte nicht gelesen werden.');
    }
}

function surfClearGpxTrack() {
    document.getElementById('sf_gpx_track_json').value = '';
    document.getElementById('sfGpxPreviewBox').classList.add('hidden');
}

function surfDrawGpxTrackOnCanvas(canvas, points) {
    if (!canvas || !points || points.length < 2) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    let minLat = Infinity, maxLat = -Infinity, minLon = Infinity, maxLon = -Infinity, maxSpd = 10;
    for (const p of points) {
        if (p[0] < minLat) minLat = p[0];
        if (p[0] > maxLat) maxLat = p[0];
        if (p[1] < minLon) minLon = p[1];
        if (p[1] > maxLon) maxLon = p[1];
        if (p[2] > maxSpd) maxSpd = p[2];
    }

    const pad = 24;
    const dLat = Math.max(0.0005, maxLat - minLat);
    const dLon = Math.max(0.0005, maxLon - minLon);

    const toXY = (lat, lon) => ({
        x: pad + ((lon - minLon) / dLon) * (w - pad * 2),
        y: h - pad - ((lat - minLat) / dLat) * (h - pad * 2),
    });

    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    for (let i = 1; i < points.length; i++) {
        const a = toXY(points[i - 1][0], points[i - 1][1]);
        const b = toXY(points[i][0], points[i][1]);
        const ratio = Math.min(1, (points[i][2] || 0) / maxSpd);
        // Farbskala von Blau (200°) über Grün (120°) bis Rot (0°)
        const hue = Math.round((1 - ratio) * 200);
        ctx.strokeStyle = `hsl(${hue}, 90%, 55%)`;
        ctx.beginPath();
        ctx.moveTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.stroke();
    }
}

function surfShowGpxModal(spotName, sessionId) {
    const scriptEl = document.getElementById('surfGpxData-' + sessionId);
    if (!scriptEl) return;
    try {
        const points = JSON.parse(scriptEl.textContent);
        document.getElementById('surfGpxModalTitle').textContent = '📍 GPS-Spur & Speed-Heatmap – ' + spotName;
        document.getElementById('surfGpxModal').classList.remove('hidden');
        surfDrawGpxTrackOnCanvas(document.getElementById('surfGpxModalCanvas'), points);
    } catch (e) {}
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
