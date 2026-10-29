<?php
declare(strict_types=1);

/**
 * MiniCloud – Globale Spotlight-Schnellsuche API (search_api.php)
 * Durchsucht per Tastenkürzel (Strg+K / Cmd+K) in Echtzeit:
 * 1. Cloud-Module & Einstellungen
 * 2. Dateien & Ordner
 * 3. Kollaborative Whiteboards
 * 4. Notizen & Pinnwand
 * 5. Aufgaben & Projekte
 * 6. Kalender-Termine
 * 7. Adressbuch-Kontakte
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

$userId      = (int)$_SESSION['user_id'];
$pdo         = getDB();
$appSettings = getAppSettings($pdo);
session_write_close();

$q     = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$qLow  = mb_strtolower($q, 'UTF-8');
$items = [];

// 1. Aktive Cloud-Module & Direkt-Funktionen
$catalog = [
    ['title' => 'Dateien & Ordner',               'sub' => 'Cloud-Dateimanager & Freigaben',              'icon' => '📁', 'cat' => 'Modul', 'url' => 'index.php',                'kw' => 'dateien ordner upload download cloud import dropbox nextcloud'],
    ['title' => 'Mein Tag (Cockpit)',             'sub' => 'Tagesübersicht, Termine & Geburtstage',       'icon' => '🏠', 'cat' => 'Modul', 'url' => 'dashboard.php',            'kw' => 'cockpit dashboard mein tag übersicht', 'setting' => 'enable_dashboard'],
    ['title' => 'Whiteboard & Skizzenbuch',       'sub' => 'Kollaboratives Zeichnen, Formen & Haftnotizen','icon' => '🎨', 'cat' => 'Modul', 'url' => 'whiteboard.php',           'kw' => 'whiteboard zeichnen skizze excalidraw miro tafel malen', 'setting' => 'enable_whiteboard'],
    ['title' => 'Office Light & Bild-Studio',     'sub' => 'Word, Excel, PowerPoint, Visio & Foto-Editor','icon' => '📝', 'cat' => 'Modul', 'url' => 'office.php',               'kw' => 'office word excel powerpoint visio bild foto editor', 'setting' => 'enable_office'],
    ['title' => 'PDF & Scan-Studio',              'sub' => 'PDF zusammenfügen, teilen & Kamera-Scanner',  'icon' => '📄', 'cat' => 'Modul', 'url' => 'pdf_tools.php',            'kw' => 'pdf scan zusammenfügen trennen komprimieren', 'setting' => 'enable_pdf_tools'],
    ['title' => 'Kalender & Termine',             'sub' => 'Monatsansicht, Serientermine & ICS',          'icon' => '📅', 'cat' => 'Modul', 'url' => 'calendar.php',             'kw' => 'kalender termine geburtstage ics caldav', 'setting' => 'enable_calendar'],
    ['title' => 'Team-Chat & Sprachräume',        'sub' => 'Direktnachrichten & Live-Audio',              'icon' => '💬', 'cat' => 'Modul', 'url' => 'messages.php',             'kw' => 'chat nachrichten telefonie sprachraum', 'setting' => 'enable_chat'],
    ['title' => 'Aufgaben & Kanban-Boards',       'sub' => 'To-Do-Listen, Fälligkeiten & Kanban',         'icon' => '📋', 'cat' => 'Modul', 'url' => 'tasks.php',                'kw' => 'aufgaben todo kanban erledigen', 'setting' => 'enable_tasks'],
    ['title' => 'Projekte & Meilensteine',        'sub' => 'Projektplanung, Gantt & Zeiterfassung',       'icon' => '🚀', 'cat' => 'Modul', 'url' => 'projects.php',             'kw' => 'projekte gantt meilensteine zeiterfassung', 'setting' => 'enable_projects'],
    ['title' => 'Notizen & Pinnwand',             'sub' => 'Farbige Haftnotizen & Checklisten',           'icon' => '📌', 'cat' => 'Modul', 'url' => 'notes.php',                'kw' => 'notizen pinnwand ideen', 'setting' => 'enable_notes'],
    ['title' => 'Kontakte & Adressbuch',          'sub' => 'Telefonnummern, E-Mail & vCard',              'icon' => '📇', 'cat' => 'Modul', 'url' => 'contacts.php',             'kw' => 'kontakte adressbuch telefonbuch vcard', 'setting' => 'enable_contacts'],
    ['title' => 'Schlüsselbund & Passwörter',     'sub' => 'Verschlüsselter Passwort-Tresor',             'icon' => '🔐', 'cat' => 'Modul', 'url' => 'passwords.php',            'kw' => 'passwörter schlüsselbund tresor zugangsdaten', 'setting' => 'enable_passwords'],
    ['title' => 'Passkeys & 2-Faktor-Schutz',     'sub' => 'Fingerabdruck / FaceID (WebAuthn) & TOTP',    'icon' => '🔑', 'cat' => 'Sicherheit', 'url' => 'settings.php#sec-passkeys', 'kw' => 'passkey fingerabdruck faceid touchid windows hello 2fa sicherheit'],
    ['title' => 'Einstellungen & Farbdesign',     'sub' => 'Themes, Passwort, Timeout & Profil',          'icon' => '⚙️', 'cat' => 'System', 'url' => 'settings.php',             'kw' => 'einstellungen design theme darkmode passwort ändern'],
    ['title' => 'Gummigeruch Kart-Cup',           'sub' => 'Rennspiel mit 6 Strecken & Ghost-Duellen',    'icon' => '🏎️', 'cat' => 'Spiel',  'url' => 'games.php?game=gummigeruch','kw' => 'gummigeruch kart autorennen rennspiel ghost duell', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Traffic-Racer (Highway)',  'sub' => 'Autobahn-Raser mit Gegenverkehr & Tuning',    'icon' => '🚘', 'cat' => 'Spiel',  'url' => 'games.php?game=trafficracer','kw' => 'traffic racer autobahn highway auto überholen gegenverkehr polizei tuning', 'setting' => 'enable_games'],
    ['title' => 'Cloud-PacMan (Arcade)',          'sub' => 'Neon-Labyrinth mit 4 Geistern & Power-Pillen','icon' => '🟡', 'cat' => 'Spiel',  'url' => 'games.php?game=pacman',    'kw' => 'pacman pac-man geister blinky pinky inky clyde arcade', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Lemmings (1991 Strategie)','sub' => 'Zerstörbares Pixel-Gelände & 8 Fähigkeiten', 'icon' => '🐹', 'cat' => 'Spiel',  'url' => 'games.php?game=lemmings',  'kw' => 'lemmings cloud lemmings digger builder blocker basher floater bomber 1991', 'setting' => 'enable_games'],
    ['title' => 'The Last Ninja (1987 C64)',     'sub' => 'Isometrisches Ninja-Adventure auf Lin Fen',   'icon' => '🥷', 'cat' => 'Spiel',  'url' => 'games.php?game=lastninja', 'kw' => 'the last ninja armakuni kunitoki c64 katana shuriken nunchaku adventure 1987', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Canabalt (Parkour-Runner)','sub' => 'Ein-Tasten-Flucht über einstürzende Dächer',  'icon' => '🏃‍♂️', 'cat' => 'Spiel',  'url' => 'games.php?game=canabalt',  'kw' => 'canabalt cloud canabalt runner parkour dächer wolkenkratzer tauben fenster', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Invaders (1978 Arcade)',  'sub' => '55 Pixel-Aliens, 4 Schutzbunker & Mystery-UFO','icon' => '👾', 'cat' => 'Spiel',  'url' => 'games.php?game=invaders',  'kw' => 'space invaders cloud invaders aliens ufo laser schutzbunker arcade 1978', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Kong (1981 Arcade)',      'sub' => 'Stahlträger, Leitern, Fässer & Power-Hammer', 'icon' => '🦍', 'cat' => 'Spiel',  'url' => 'games.php?game=kong',      'kw' => 'donkey kong cloud kong fässer leitern hammer pauline mario jumpman arcade 1981', 'setting' => 'enable_games'],
    ['title' => 'Commodore VC-20 Blitz (1981)',   'sub' => 'Retro-Flieger: Hochhäuser per Bombe abtragen','icon' => '✈️', 'cat' => 'Spiel',  'url' => 'games.php?game=blitz',     'kw' => 'blitz vc20 vic-20 commodore flugzeug hochhäuser bombe retro 1981', 'setting' => 'enable_games'],
    ['title' => 'World of MiniCloud (RPG)',       'sub' => 'Co-Op Fantasy-Rollenspiel & Boss-Raids',      'icon' => '⚔️', 'cat' => 'Spiel',  'url' => 'games.php?game=wow',       'kw' => 'wow world of minicloud rpg rollenspiel', 'setting' => 'enable_games'],
    ['title' => 'Spiele-Zentrale & Pausenraum',   'sub' => '17 Browserspiele (inkl. Vollbild) & Highscores','icon' => '🎮', 'cat' => 'Modul', 'url' => 'games.php',                'kw' => 'spiele games vollbild traffic racer lemmings last ninja canabalt space invaders kong donkey kong blitz pacman sudoku tetris snake minesweeper', 'setting' => 'enable_games'],
    ['title' => 'Mind-Map-Studio',                'sub' => 'Ideennetze & Strukturdiagramme',              'icon' => '🧠', 'cat' => 'Modul', 'url' => 'mindmap.php',              'kw' => 'mindmap ideen struktur', 'setting' => 'enable_mindmap'],
    ['title' => 'Haushaltsplaner & Finanzen',     'sub' => 'Einnahmen, Ausgaben, Abos & Sparziele',       'icon' => '💰', 'cat' => 'Modul', 'url' => 'budget.php',               'kw' => 'haushalt finanzen budget geld ausgaben', 'setting' => 'enable_budget'],
    ['title' => 'Vereinsverwaltung',              'sub' => 'Mitglieder, Beiträge & Vorstands-Portal',     'icon' => '🏛️', 'cat' => 'Modul', 'url' => 'club.php',                 'kw' => 'verein mitglieder beiträge kassenwart', 'setting' => 'enable_club'],
    ['title' => 'Foto-Alben & Galerie',           'sub' => 'Fotowand, Diashow & Gäste-Alben',             'icon' => '🖼️', 'cat' => 'Modul', 'url' => 'gallery.php',              'kw' => 'galerie fotos bilder alben diashow', 'setting' => 'enable_gallery'],
    ['title' => 'Online-Formulare',               'sub' => 'Anmeldebögen, Helferlisten & QR-Code',        'icon' => '📝', 'cat' => 'Modul', 'url' => 'forms.php',                'kw' => 'formulare anmeldung event umfrage', 'setting' => 'enable_forms'],
    ['title' => 'E-Book-Reader (.epub)',          'sub' => 'Digitale Bücherbibliothek',                   'icon' => '📚', 'cat' => 'Modul', 'url' => 'ebooks.php',               'kw' => 'ebooks bücher lesen epub', 'setting' => 'enable_ebooks'],
    ['title' => 'Musik- & Hörbuch-Studio',        'sub' => 'Playlists, Equalizer & Audio-Player',         'icon' => '🎵', 'cat' => 'Modul', 'url' => 'music.php',                'kw' => 'musik player mp3 hörbuch', 'setting' => 'enable_music'],
    ['title' => 'Taschenrechner & Einheiten',     'sub' => 'Wissenschaftlicher Rechner & Finanzrechner',  'icon' => '🧮', 'cat' => 'Modul', 'url' => 'calculator.php',           'kw' => 'rechner taschenrechner prozent zinsen', 'setting' => 'enable_calculator'],
    ['title' => 'Alltags-Werkzeuge & Toolbox',    'sub' => 'WLAN-QR, GiroCode, Glücksrad, Stoppuhr & Diff','icon' => '🧰', 'cat' => 'Modul', 'url' => 'toolbox.php',              'kw' => 'toolbox werkzeuge qr code wlan girocode sepa glückrad auslosung würfel stoppuhr timer weltzeit diff textvergleich farben kontrast passwort generator uuid', 'setting' => 'enable_toolbox'],
    ['title' => 'Umfragen & Terminfinder',        'sub' => 'Abstimmungen im Doodle-Stil',                 'icon' => '📊', 'cat' => 'Modul', 'url' => 'polls.php',                'kw' => 'umfragen abstimmung doodle terminfinder', 'setting' => 'enable_polls'],
    ['title' => 'Live-Mentimeter',                'sub' => 'Interaktive Wortwolken & Live-Votings',       'icon' => '🎤', 'cat' => 'Modul', 'url' => 'menti.php',                'kw' => 'mentimeter präsentation wortwolke', 'setting' => 'enable_menti'],
    ['title' => 'Papierkorb',                     'sub' => 'Gelöschte Dateien wiederherstellen',          'icon' => '🗑️', 'cat' => 'System', 'url' => 'trash.php',                'kw' => 'papierkorb gelöscht wiederherstellen'],
];

$matchedModules = [];
foreach ($catalog as $mod) {
    if (isset($mod['setting']) && ($appSettings[$mod['setting']] ?? '1') !== '1') {
        continue;
    }
    if ($qLow === '') {
        if (count($matchedModules) < 5) {
            $matchedModules[] = [
                'icon'  => $mod['icon'],
                'title' => $mod['title'],
                'sub'   => $mod['sub'],
                'cat'   => $mod['cat'],
                'url'   => $mod['url'],
            ];
        }
    } else {
        $haystack = mb_strtolower($mod['title'] . ' ' . $mod['kw'], 'UTF-8');
        if (mb_strpos($haystack, $qLow) !== false && count($matchedModules) < 4) {
            $matchedModules[] = [
                'icon'  => $mod['icon'],
                'title' => $mod['title'],
                'sub'   => $mod['sub'],
                'cat'   => $mod['cat'],
                'url'   => $mod['url'],
            ];
        }
    }
}

// Wenn kein Suchbegriff eingegeben wurde: Zuletzt hochgeladene Dateien + Schnellzugriff-Module anzeigen
if ($qLow === '') {
    try {
        $stmtRec = $pdo->prepare(
            "SELECT `id`, `original_name`, `file_size`, `folder_path`, `group_folder_id`
             FROM `files`
             WHERE `user_id` = :uid AND `deleted_at` IS NULL
             ORDER BY `upload_date` DESC
             LIMIT 6"
        );
        $stmtRec->execute([':uid' => $userId]);
        foreach ($stmtRec->fetchAll() as $f) {
            $fp   = trim((string)($f['folder_path'] ?? ''));
            $gfId = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
            $qUrl = [];
            if ($gfId > 0) {
                $qUrl['group_folder'] = $gfId;
            }
            if ($fp !== '') {
                $qUrl['folder'] = $fp;
            }
            $locLabel = $fp !== '' ? (' · 📁 ' . $fp) : ' · 🏠 Hauptverzeichnis';
            $items[] = [
                'icon'  => '📄',
                'title' => (string)$f['original_name'],
                'sub'   => 'Zuletzt hochgeladen · ' . formatBytes((int)$f['file_size']) . $locLabel,
                'cat'   => 'Datei',
                'url'   => 'index.php' . (!empty($qUrl) ? '?' . http_build_query($qUrl) : ''),
            ];
        }
    } catch (Throwable) {
    }

    foreach ($matchedModules as $m) {
        $items[] = $m;
    }

    echo json_encode(['ok' => true, 'results' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';

// 2. Ordner durchsuchen (sowohl aus user_folders als auch aus files.folder_path)
try {
    $foundFolders = [];

    $stmtFld = $pdo->prepare(
        "SELECT `folder_path`, `group_folder_id`
         FROM `user_folders`
         WHERE `user_id` = :uid AND `folder_path` LIKE :q
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFld->execute([':uid' => $userId, ':q' => $like]);
    foreach ($stmtFld->fetchAll() as $fld) {
        $fp   = trim((string)$fld['folder_path']);
        $gfId = !empty($fld['group_folder_id']) ? (int)$fld['group_folder_id'] : 0;
        if ($fp !== '') {
            $foundFolders[$gfId . ':' . $fp] = ['fp' => $fp, 'gf_id' => $gfId];
        }
    }

    $stmtFldFiles = $pdo->prepare(
        "SELECT DISTINCT `folder_path`, `group_folder_id`
         FROM `files`
         WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `folder_path` != '' AND `folder_path` LIKE :q
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFldFiles->execute([':uid' => $userId, ':q' => $like]);
    foreach ($stmtFldFiles->fetchAll() as $fld) {
        $fp   = trim((string)$fld['folder_path']);
        $gfId = !empty($fld['group_folder_id']) ? (int)$fld['group_folder_id'] : 0;
        if ($fp !== '') {
            $foundFolders[$gfId . ':' . $fp] = ['fp' => $fp, 'gf_id' => $gfId];
        }
    }

    foreach (array_slice(array_values($foundFolders), 0, 6) as $fldInfo) {
        $fp   = $fldInfo['fp'];
        $gfId = $fldInfo['gf_id'];
        $qUrl = [];
        if ($gfId > 0) {
            $qUrl['group_folder'] = $gfId;
        }
        $qUrl['folder'] = $fp;
        $items[] = [
            'icon'  => '📁',
            'title' => $fp,
            'sub'   => 'Ordner im Dateimanager öffnen',
            'cat'   => 'Ordner',
            'url'   => 'index.php?' . http_build_query($qUrl),
        ];
    }
} catch (Throwable) {
}

// 3. Dateien durchsuchen
try {
    $stmtFil = $pdo->prepare(
        "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`
         FROM `files` f
         WHERE f.`deleted_at` IS NULL
           AND f.`user_id` = :uid1
           AND (f.`original_name` LIKE :q1 OR f.`folder_path` LIKE :q2)
         ORDER BY f.`upload_date` DESC
         LIMIT 12"
    );
    $stmtFil->execute([
        ':uid1' => $userId,
        ':q1'   => $like,
        ':q2'   => $like,
    ]);
    foreach ($stmtFil->fetchAll() as $f) {
        $fp   = trim((string)($f['folder_path'] ?? ''));
        $gfId = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
        $qUrl = [];
        if ($gfId > 0) {
            $qUrl['group_folder'] = $gfId;
        }
        if ($fp !== '') {
            $qUrl['folder'] = $fp;
        }
        $qUrl['q'] = (string)$f['original_name'];
        $locLabel  = $fp !== '' ? ('📁 ' . $fp) : '🏠 Hauptverzeichnis';
        $items[] = [
            'icon'  => '📄',
            'title' => (string)$f['original_name'],
            'sub'   => formatBytes((int)$f['file_size']) . ' · ' . $locLabel,
            'cat'   => 'Datei',
            'url'   => 'index.php?' . http_build_query($qUrl),
        ];
    }
} catch (Throwable) {
}

// 4. Whiteboards durchsuchen
if (($appSettings['enable_whiteboard'] ?? '1') === '1') {
    try {
        $stmtWb = $pdo->prepare(
            "SELECT `id`, `title`, `is_shared`
             FROM `whiteboards`
             WHERE (`user_id` = :uid OR `is_shared` = 1) AND `title` LIKE :q
             ORDER BY `updated_at` DESC
             LIMIT 5"
        );
        $stmtWb->execute([':uid' => $userId, ':q' => $like]);
        foreach ($stmtWb->fetchAll() as $wb) {
            $items[] = [
                'icon'  => '🎨',
                'title' => (string)$wb['title'],
                'sub'   => ((int)$wb['is_shared'] === 1 ? 'Team-Whiteboard' : 'Privates Whiteboard'),
                'cat'   => 'Whiteboard',
                'url'   => 'whiteboard.php?id=' . (int)$wb['id'],
            ];
        }
    } catch (Throwable) {
    }
}

// 5. Notizen durchsuchen
if (($appSettings['enable_notes'] ?? '1') === '1') {
    try {
        $stmtNt = $pdo->prepare(
            "SELECT `id`, `title`, `content`
             FROM `notes`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND (`title` LIKE :q1 OR `content` LIKE :q2)
             ORDER BY `updated_at` DESC
             LIMIT 5"
        );
        $stmtNt->execute([':u1' => $userId, ':u2' => $userId, ':q1' => $like, ':q2' => $like]);
        foreach ($stmtNt->fetchAll() as $nt) {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$nt['content'])) ?: '', 0, 65);
            $items[] = [
                'icon'  => '📌',
                'title' => trim((string)$nt['title']) !== '' ? (string)$nt['title'] : 'Notiz #' . (int)$nt['id'],
                'sub'   => $snippet !== '' ? $snippet : 'Notiz öffnen',
                'cat'   => 'Notiz',
                'url'   => 'notes.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 6. Aufgaben durchsuchen
if (($appSettings['enable_tasks'] ?? '1') === '1') {
    try {
        $stmtTk = $pdo->prepare(
            "SELECT `id`, `title`, `status`, `due_date`
             FROM `tasks`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND (`title` LIKE :q1 OR `description` LIKE :q2)
             ORDER BY `created_at` DESC
             LIMIT 5"
        );
        $stmtTk->execute([':u1' => $userId, ':u2' => $userId, ':q1' => $like, ':q2' => $like]);
        foreach ($stmtTk->fetchAll() as $tk) {
            $due = !empty($tk['due_date']) ? ' · Fällig: ' . date('d.m.Y', strtotime((string)$tk['due_date']) ?: time()) : '';
            $items[] = [
                'icon'  => $tk['status'] === 'done' ? '✅' : '📋',
                'title' => (string)$tk['title'],
                'sub'   => ($tk['status'] === 'done' ? 'Erledigt' : 'Offene Aufgabe') . $due,
                'cat'   => 'Aufgabe',
                'url'   => 'tasks.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 7. Kalender-Termine durchsuchen
if (($appSettings['enable_calendar'] ?? '1') === '1') {
    try {
        $stmtEv = $pdo->prepare(
            "SELECT `id`, `title`, `start_at`, `location`
             FROM `calendar_events`
             WHERE (`user_id` = :u1 OR (`shared_user_id` = :u2 AND `is_private` = 0))
               AND (`title` LIKE :q1 OR `location` LIKE :q2 OR `description` LIKE :q3)
             ORDER BY `start_at` DESC
             LIMIT 5"
        );
        $stmtEv->execute([':u1' => $userId, ':u2' => $userId, ':q1' => $like, ':q2' => $like, ':q3' => $like]);
        foreach ($stmtEv->fetchAll() as $ev) {
            $dtStr = date('d.m.Y H:i', strtotime((string)$ev['start_at']) ?: time());
            $loc   = trim((string)($ev['location'] ?? '')) !== '' ? ' · 📍 ' . $ev['location'] : '';
            $items[] = [
                'icon'  => '📅',
                'title' => (string)$ev['title'],
                'sub'   => $dtStr . $loc,
                'cat'   => 'Termin',
                'url'   => 'calendar.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 8. Kontakte durchsuchen
if (($appSettings['enable_contacts'] ?? '1') === '1') {
    try {
        $stmtCt = $pdo->prepare(
            "SELECT `id`, `first_name`, `last_name`, `organization`, `email`, `phone`
             FROM `contacts`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND (`first_name` LIKE :q1 OR `last_name` LIKE :q2 OR `organization` LIKE :q3 OR `email` LIKE :q4 OR `phone` LIKE :q5)
             LIMIT 5"
        );
        $stmtCt->execute([
            ':u1' => $userId, ':u2' => $userId,
            ':q1' => $like,   ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like,
        ]);
        foreach ($stmtCt->fetchAll() as $ct) {
            $name = trim($ct['first_name'] . ' ' . $ct['last_name']);
            if ($name === '') {
                $name = (string)$ct['organization'];
            }
            $meta = array_filter([(string)($ct['email'] ?? ''), (string)($ct['phone'] ?? '')]);
            $items[] = [
                'icon'  => '📇',
                'title' => $name,
                'sub'   => !empty($meta) ? implode(' · ', $meta) : 'Kontakt im Adressbuch',
                'cat'   => 'Kontakt',
                'url'   => 'contacts.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 9. Passende Cloud-Module anhängen
foreach ($matchedModules as $m) {
    $items[] = $m;
}

echo json_encode([
    'ok'      => true,
    'results' => array_slice($items, 0, 24),
], JSON_UNESCAPED_UNICODE);
