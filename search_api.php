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
require_once __DIR__ . '/includes/office_functions.php';

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
    ['title' => 'Große Dateien & Speicher-Analyse','sub' => 'Speicherfresser & Duplikate in allen Ordnern', 'icon' => '📊', 'cat' => 'Funktion','url' => 'index.php?large=1&sort=size&dir=desc', 'kw' => 'große dateien speicherfresser speicherplatz voll aufräumen duplikate doppelte dateien größe'],
    ['title' => 'Passkeys & 2-Faktor-Schutz',     'sub' => 'Fingerabdruck / FaceID (WebAuthn) & TOTP',    'icon' => '🔑', 'cat' => 'Sicherheit', 'url' => 'settings.php#sec-passkeys', 'kw' => 'passkey fingerabdruck faceid touchid windows hello 2fa sicherheit'],
    ['title' => 'Einstellungen & Farbdesign',     'sub' => 'Themes, Passwort, Timeout & Profil',          'icon' => '⚙️', 'cat' => 'System', 'url' => 'settings.php',             'kw' => 'einstellungen design theme darkmode passwort ändern'],
    ['title' => 'Papierkorb',                     'sub' => 'Gelöschte Dateien wiederherstellen',          'icon' => '🗑️', 'cat' => 'System', 'url' => 'trash.php',                'kw' => 'papierkorb gelöscht wiederherstellen'],
];

$matchedModules = [];
$searchTerms    = splitSearchTerms($q, 8);
$searchTermsLow = array_map(static fn(string $t): string => mb_strtolower($t, 'UTF-8'), $searchTerms);

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
        $haystack = mb_strtolower($mod['title'] . ' ' . $mod['sub'] . ' ' . $mod['kw'], 'UTF-8');
        $allModMatched = !empty($searchTermsLow);
        foreach ($searchTermsLow as $termLow) {
            if (mb_strpos($haystack, $termLow) === false) {
                $allModMatched = false;
                break;
            }
        }
        if ($allModMatched && count($matchedModules) < 4) {
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
if ($qLow === '' || empty($searchTerms)) {
    try {
        $stmtRec = $pdo->prepare(
            "SELECT `id`, `original_name`, `file_size`, `folder_path`, `group_folder_id`, `is_encrypted`
             FROM `files`
             WHERE `user_id` = :uid AND `deleted_at` IS NULL
             ORDER BY `upload_date` DESC
             LIMIT 6"
        );
        $stmtRec->execute([':uid' => $userId]);
        foreach ($stmtRec->fetchAll() as $f) {
            $fId  = (int)$f['id'];
            $name = (string)$f['original_name'];
            $fp   = trim((string)($f['folder_path'] ?? ''));
            $gfId = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
            $isEnc = ((int)($f['is_encrypted'] ?? 0) === 1);

            $folderParams = [];
            if ($gfId > 0) {
                $folderParams['group_folder'] = $gfId;
            }
            if ($fp !== '') {
                $folderParams['folder'] = $fp;
            }
            $folderParams['highlight_id'] = $fId;
            $folderUrl = 'index.php?' . http_build_query($folderParams);

            $openParams = $folderParams;
            unset($openParams['highlight_id']);
            $openParams['preview_id'] = $fId;
            $openUrl = 'index.php?' . http_build_query($openParams);

            $officeType = !$isEnc ? getOfficeEditorType($name) : null;
            $officeUrl  = $officeType !== null ? ('') : '';

            $locLabel = $fp !== '' ? (' · 📁 ' . $fp) : ' · 🏠 Hauptverzeichnis';
            $items[] = [
                'icon'         => $isEnc ? '🔒' : '📄',
                'title'        => $name,
                'sub'          => 'Zuletzt hochgeladen · ' . formatBytes((int)$f['file_size']) . $locLabel,
                'cat'          => 'Datei',
                'file_id'      => $fId,
                'url'          => $openUrl,
                'open_url'     => $openUrl,
                'folder_url'   => $folderUrl,
                'office_url'   => $officeUrl,
                'download_url' => !$isEnc ? ('download.php?id=' . $fId) : '',
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

// 2. Ordner durchsuchen (sowohl aus user_folders als auch aus files.folder_path – alle Suchbegriffe per UND verknüpft)
try {
    $foundFolders = [];
    $fldConds     = [];
    $fldParams    = [':uid' => $userId];
    foreach ($searchTerms as $idx => $term) {
        $fldConds[]                 = "`folder_path` LIKE :fq_{$idx}";
        $fldParams[":fq_{$idx}"]    = '%' . $term . '%';
    }
    $fldAndSql = implode(' AND ', $fldConds);

    $stmtFld = $pdo->prepare(
        "SELECT `folder_path`, `group_folder_id`
         FROM `user_folders`
         WHERE `user_id` = :uid AND {$fldAndSql}
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFld->execute($fldParams);
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
         WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `folder_path` != '' AND {$fldAndSql}
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFldFiles->execute($fldParams);
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

// 3. Dateien durchsuchen (eigene Dateien + zugängliche Gruppenordner + optionaler Volltext-Suchindex, alle Begriffe per UND verknüpft)
try {
    $accGfs = getAccessibleGroupFolders($pdo, $userId, isAdmin());
    $gfIds  = array_map(static fn(array $g): int => (int)$g['id'], $accGfs);
    $gfCond = '';
    if (!empty($gfIds)) {
        $gfCond = ' OR f.`group_folder_id` IN (' . implode(',', $gfIds) . ')';
    }

    $seenFileIds     = [];
    $fulltextEnabled = (($appSettings['enable_fulltext_search'] ?? '1') === '1');

    // 3a. Direkte Treffer in Dateiname / Ordnerpfad (jeder eingegebene Begriff muss vorkommen)
    $nameConds  = [];
    $nameParams = [':uid1' => $userId];
    foreach ($searchTerms as $idx => $term) {
        $likeVal                     = '%' . $term . '%';
        $nameConds[]                 = "(f.`original_name` LIKE :fn_{$idx} OR f.`folder_path` LIKE :fp_{$idx})";
        $nameParams[":fn_{$idx}"]    = $likeVal;
        $nameParams[":fp_{$idx}"]    = $likeVal;
    }
    $nameAndSql = implode(' AND ', $nameConds);

    $stmtFil = $pdo->prepare(
        "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                fsi.`content_text`
         FROM `files` f
         LEFT JOIN `file_search_index` fsi ON fsi.`file_id` = f.`id`
         WHERE f.`deleted_at` IS NULL
           AND (f.`user_id` = :uid1{$gfCond})
           AND ({$nameAndSql})
         ORDER BY f.`upload_date` DESC
         LIMIT 10"
    );
    $stmtFil->execute($nameParams);
    $matchedFileRows = $stmtFil->fetchAll();
    foreach ($matchedFileRows as $r) {
        $seenFileIds[(int)$r['id']] = true;
    }

    // 3b. Volltextsuche im Dokumenten-Inhalt (PDF, Word, Excel, PowerPoint, Visio, Text/Markdown, EPUB)
    //     Strikte UND-Verknüpfung: Jeder eingegebene Suchbegriff muss zwingend in Dateiname, Ordnerpfad oder Dokumenttext vorkommen!
    if ($fulltextEnabled && mb_strlen($q, 'UTF-8') >= 2 && count($matchedFileRows) < 14) {
        $ftBoolQuery = buildFulltextBooleanQuery($q);
        $remLimit    = max(4, 14 - count($matchedFileRows));

        $ftTermConds  = [];
        $ftParams     = [':uid1' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                   = '%' . $term . '%';
            $ftTermConds[]             = "(f.`original_name` LIKE :ftn_{$idx} OR f.`folder_path` LIKE :ftfp_{$idx} OR fsi.`content_text` LIKE :ftc_{$idx})";
            $ftParams[":ftn_{$idx}"]   = $likeVal;
            $ftParams[":ftfp_{$idx}"]  = $likeVal;
            $ftParams[":ftc_{$idx}"]   = $likeVal;
        }
        $ftAndTermsSql = implode(' AND ', $ftTermConds);

        if ($ftBoolQuery !== '') {
            $ftParams[':ftq1'] = $ftBoolQuery;
            $stmtFt = $pdo->prepare(
                "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                        fsi.`content_text`,
                        MATCH(fsi.`original_name`, fsi.`content_text`) AGAINST(:ftq1 IN BOOLEAN MODE) AS `ft_score`
                 FROM `file_search_index` fsi
                 INNER JOIN `files` f ON f.`id` = fsi.`file_id`
                 WHERE f.`deleted_at` IS NULL
                   AND f.`is_encrypted` = 0
                   AND (f.`user_id` = :uid1{$gfCond})
                   AND ({$ftAndTermsSql})
                 ORDER BY `ft_score` DESC, f.`upload_date` DESC
                 LIMIT {$remLimit}"
            );
        } else {
            $stmtFt = $pdo->prepare(
                "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                        fsi.`content_text`
                 FROM `file_search_index` fsi
                 INNER JOIN `files` f ON f.`id` = fsi.`file_id`
                 WHERE f.`deleted_at` IS NULL
                   AND f.`is_encrypted` = 0
                   AND (f.`user_id` = :uid1{$gfCond})
                   AND ({$ftAndTermsSql})
                 ORDER BY f.`upload_date` DESC
                 LIMIT {$remLimit}"
            );
        }

        $stmtFt->execute($ftParams);
        foreach ($stmtFt->fetchAll() as $ftRow) {
            $ftId = (int)$ftRow['id'];
            if (!isset($seenFileIds[$ftId])) {
                $seenFileIds[$ftId] = true;
                $matchedFileRows[]  = $ftRow;
            }
        }
    }

    foreach ($matchedFileRows as $f) {
        $fId   = (int)$f['id'];
        $name  = (string)$f['original_name'];
        $fp    = trim((string)($f['folder_path'] ?? ''));
        $gfId  = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
        $isEnc = ((int)($f['is_encrypted'] ?? 0) === 1);
        $cText = (!$isEnc && $fulltextEnabled) ? (string)($f['content_text'] ?? '') : '';

        $folderParams = [];
        if ($gfId > 0) {
            $folderParams['group_folder'] = $gfId;
        }
        if ($fp !== '') {
            $folderParams['folder'] = $fp;
        }
        $folderParams['highlight_id'] = $fId;
        $folderUrl = 'index.php?' . http_build_query($folderParams);

        $openParams = $folderParams;
        unset($openParams['highlight_id']);
        $openParams['q']          = $name;
        $openParams['preview_id'] = $fId;
        $openUrl = 'index.php?' . http_build_query($openParams);

        $officeType = !$isEnc ? getOfficeEditorType($name) : null;
        $officeUrl  = $officeType !== null ? ('') : '';

        $snippet = '';
        if ($cText !== '') {
            $snippet = buildFulltextSnippet($cText, $q, 115);
        }

        $locLabel = $fp !== '' ? ('📁 ' . $fp) : '🏠 Hauptverzeichnis';
        $items[] = [
            'icon'         => $isEnc ? '🔒' : ($snippet !== '' ? '📑' : '📄'),
            'title'        => $name,
            'sub'          => formatBytes((int)$f['file_size']) . ' · ' . $locLabel,
            'snippet'      => $snippet,
            'cat'          => 'Datei',
            'file_id'      => $fId,
            'url'          => $openUrl,
            'open_url'     => $openUrl,
            'folder_url'   => $folderUrl,
            'office_url'   => $officeUrl,
            'download_url' => !$isEnc ? ('download.php?id=' . $fId) : '',
        ];
    }
} catch (Throwable) {
}


// 9. Passende Cloud-Module anhängen
foreach ($matchedModules as $m) {
    $items[] = $m;
}

echo json_encode([
    'ok'      => true,
    'results' => array_slice($items, 0, 24),
], JSON_UNESCAPED_UNICODE);
