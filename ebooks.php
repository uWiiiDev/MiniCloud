<?php
declare(strict_types=1);

/**
 * MiniCloud – E-Book-Reader & Digitale Bibliothek (ebooks.php)
 * 100 % Strato-, Intranet- & DSGVO-tauglicher EPUB-Reader auf Basis von PHP ZipArchive & XML.
 * - Automatisches Bücherregal über alle eigenen, geteilten und Gruppenordner-EPUBs
 * - Extrahiert Buchcover, Titel, Autor, Sprache, Klappentext und Inhaltsverzeichnis direkt aus der .epub-Datei
 * - Speichert den persönlichen Lesefortschritt (Kapitel & Scroll-Prozent) sowie Lese-Einstellungen (Schrift, Sepia/Nachtmodus)
 * - Unterstützt persönliche Lesezeichen mit Notizen und eine Volltextsuche über alle Kapitel eines Buches
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_ebooks'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das E-Book-Reader-Modul ist derzeit durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

/**
 * Stellt sicher, dass die Tabellen für Lesefortschritt, Lesezeichen und Metadaten-Cache existieren.
 */
function ensureEbooksSchema(PDO $pdo): void
{
    static $done = false;
    if ($done || (!empty($_SESSION['_mc_ebooks_schema_ok']) && $_SESSION['_mc_ebooks_schema_ok'] === 1)) {
        $done = true;
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ebook_progress` (
                `user_id` INT UNSIGNED NOT NULL,
                `file_id` INT UNSIGNED NOT NULL,
                `chapter_index` INT UNSIGNED NOT NULL DEFAULT 0,
                `scroll_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `total_chapters` INT UNSIGNED NOT NULL DEFAULT 1,
                `progress_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `reader_prefs` TEXT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`, `file_id`),
                KEY `idx_user_updated` (`user_id`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ebook_bookmarks` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `file_id` INT UNSIGNED NOT NULL,
                `chapter_index` INT UNSIGNED NOT NULL DEFAULT 0,
                `chapter_title` VARCHAR(190) NOT NULL DEFAULT '',
                `scroll_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `note` VARCHAR(255) NOT NULL DEFAULT '',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_file` (`user_id`, `file_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ebook_metadata_cache` (
                `file_id` INT UNSIGNED NOT NULL,
                `file_mtime` INT UNSIGNED NOT NULL DEFAULT 0,
                `title` VARCHAR(255) NOT NULL DEFAULT '',
                `author` VARCHAR(255) NOT NULL DEFAULT '',
                `language` VARCHAR(40) NOT NULL DEFAULT '',
                `description` TEXT NULL,
                `chapter_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `cover_data_uri` MEDIUMTEXT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`file_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_mc_ebooks_schema_ok'] = 1;
        }
    } catch (Throwable) {
        // Ignorieren falls DB-Rechte eingeschränkt
    }
}

ensureEbooksSchema($pdo);

/**
 * Löst einen relativen Pfad innerhalb eines EPUB-ZIP-Archivs auf (z.B. "OEBPS" + "../Images/cover.jpg" -> "Images/cover.jpg").
 */
function resolveEpubZipPath(string $baseDir, string $relativeHref): string
{
    $cleanHref = explode('#', $relativeHref, 2)[0];
    $cleanHref = rawurldecode(trim($cleanHref));
    $cleanHref = str_replace('\\', '/', $cleanHref);

    if ($cleanHref === '') {
        return '';
    }

    if (str_starts_with($cleanHref, '/')) {
        $combined = ltrim($cleanHref, '/');
    } elseif ($baseDir !== '' && $baseDir !== '.') {
        $combined = rtrim($baseDir, '/') . '/' . $cleanHref;
    } else {
        $combined = $cleanHref;
    }

    $parts = explode('/', $combined);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($resolved);
        } else {
            $resolved[] = $part;
        }
    }

    return implode('/', $resolved);
}

/**
 * Liest einen Eintrag aus einem geöffneten ZipArchive (case-insensitive Fallback falls Pfade im EPUB abweichen).
 */
function getZipEntryContents(ZipArchive $zip, string $entryPath): string|false
{
    if ($entryPath === '') {
        return false;
    }
    $direct = $zip->getFromName($entryPath);
    if ($direct !== false) {
        return $direct;
    }
    $idx = $zip->locateName($entryPath, ZipArchive::FL_NOCASE);
    if ($idx !== false) {
        return $zip->getFromIndex($idx);
    }
    return false;
}

/**
 * Parst die Struktur einer physischen .epub-Datei (Metadaten, Cover, Spine-Kapitel & Inhaltsverzeichnis).
 *
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   title: string,
 *   author: string,
 *   language: string,
 *   description: string,
 *   cover_path: string,
 *   cover_mime: string,
 *   chapters: array<int, array{index: int, id: string, path: string, title: string}>
 * }
 */
function inspectEpubArchive(string $filePath, string $fallbackFilename = 'Unbenanntes Buch.epub'): array
{
    $defaultTitle = pathinfo($fallbackFilename, PATHINFO_FILENAME) ?: 'Unbenanntes E-Book';
    $emptyResult  = [
        'ok'          => false,
        'title'       => $defaultTitle,
        'author'      => '',
        'language'    => '',
        'description' => '',
        'cover_path'  => '',
        'cover_mime'  => '',
        'chapters'    => [],
    ];

    if (!class_exists('ZipArchive')) {
        $emptyResult['error'] = 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht verfügbar.';
        return $emptyResult;
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        $emptyResult['error'] = 'Die EPUB-Datei konnte auf dem Server nicht gelesen werden.';
        return $emptyResult;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        $emptyResult['error'] = 'Das EPUB-Archiv ist beschädigt oder verschlüsselt (z. B. im Datei-Tresor).';
        return $emptyResult;
    }

    // 1. META-INF/container.xml suchen, um den Pfad der .opf-Datei zu ermitteln
    $opfPath = '';
    $containerXml = getZipEntryContents($zip, 'META-INF/container.xml');
    if (is_string($containerXml) && $containerXml !== '') {
        if (preg_match('/full-path\s*=\s*["\']([^"\']+\.opf)["\']/i', $containerXml, $mOpf)) {
            $opfPath = trim($mOpf[1]);
        }
    }

    // Fallback: Erste .opf-Datei im ZIP suchen
    if ($opfPath === '') {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $statName = (string)$zip->getNameIndex($i);
            if (str_ends_with(strtolower($statName), '.opf')) {
                $opfPath = $statName;
                break;
            }
        }
    }

    if ($opfPath === '') {
        $zip->close();
        $emptyResult['error'] = 'Keine gültige OPF-Paketdatei in diesem EPUB gefunden.';
        return $emptyResult;
    }

    $opfRaw = getZipEntryContents($zip, $opfPath);
    if (!is_string($opfRaw) || $opfRaw === '') {
        $zip->close();
        $emptyResult['error'] = 'Die OPF-Paketdatei des EPUBs konnte nicht gelesen werden.';
        return $emptyResult;
    }

    $opfDir = dirname(str_replace('\\', '/', $opfPath));
    if ($opfDir === '.') {
        $opfDir = '';
    }

    $dom = new DOMDocument();
    @$dom->loadXML($opfRaw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($dom);

    // 2. Metadaten (dc:title, dc:creator, dc:language, dc:description) auslesen
    $extractNodeText = static function (string $localName) use ($xpath): string {
        $nodes = $xpath->query('//*[local-name()="' . $localName . '"]');
        if ($nodes !== false && $nodes->length > 0) {
            foreach ($nodes as $n) {
                $val = trim((string)$n->textContent);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    };

    $title       = $extractNodeText('title') ?: $defaultTitle;
    $author      = $extractNodeText('creator');
    $language    = $extractNodeText('language');
    $rawDesc     = $extractNodeText('description');
    $description = trim(strip_tags(html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    // 3. Manifest auslesen (id -> [href, media-type, properties])
    $manifest = [];
    $manifestByPath = [];
    $itemNodes = $xpath->query('//*[local-name()="manifest"]/*[local-name()="item"]');
    if ($itemNodes !== false) {
        foreach ($itemNodes as $itemEl) {
            if (!($itemEl instanceof DOMElement)) {
                continue;
            }
            $id    = trim($itemEl->getAttribute('id'));
            $href  = trim($itemEl->getAttribute('href'));
            $mime  = strtolower(trim($itemEl->getAttribute('media-type')));
            $props = strtolower(trim($itemEl->getAttribute('properties')));
            if ($id === '' || $href === '') {
                continue;
            }
            $resolvedPath = resolveEpubZipPath($opfDir, $href);
            $manifest[$id] = [
                'id'         => $id,
                'href'       => $href,
                'path'       => $resolvedPath,
                'mime'       => $mime,
                'properties' => $props,
            ];
            $manifestByPath[$resolvedPath] = $manifest[$id];
        }
    }

    // 4. Cover-Bild ermitteln
    $coverPath = '';
    $coverMime = '';

    // 4a) EPUB 3: properties="cover-image"
    foreach ($manifest as $mItem) {
        if (str_contains($mItem['properties'], 'cover-image') && str_starts_with($mItem['mime'], 'image/')) {
            $coverPath = $mItem['path'];
            $coverMime = $mItem['mime'];
            break;
        }
    }

    // 4b) EPUB 2: <meta name="cover" content="cover-id"/>
    if ($coverPath === '') {
        $metaNodes = $xpath->query('//*[local-name()="metadata"]/*[local-name()="meta"]');
        if ($metaNodes !== false) {
            foreach ($metaNodes as $metaEl) {
                if (!($metaEl instanceof DOMElement)) {
                    continue;
                }
                if (strtolower(trim($metaEl->getAttribute('name'))) === 'cover') {
                    $coverId = trim($metaEl->getAttribute('content'));
                    if ($coverId !== '' && isset($manifest[$coverId]) && str_starts_with($manifest[$coverId]['mime'], 'image/')) {
                        $coverPath = $manifest[$coverId]['path'];
                        $coverMime = $manifest[$coverId]['mime'];
                        break;
                    }
                }
            }
        }
    }

    // 4c) Fallback: Bild im Manifest mit "cover" in ID oder Dateiname
    if ($coverPath === '') {
        foreach ($manifest as $mItem) {
            if (str_starts_with($mItem['mime'], 'image/') && (stripos($mItem['id'], 'cover') !== false || stripos($mItem['href'], 'cover') !== false)) {
                $coverPath = $mItem['path'];
                $coverMime = $mItem['mime'];
                break;
            }
        }
    }

    // 5. Inhaltsverzeichnis-Titel (TOC) aus EPUB3 nav oder EPUB2 NCX sammeln (path -> title)
    $tocTitlesByPath = [];

    // 5a) EPUB 3 Navigation Document
    foreach ($manifest as $mItem) {
        if (str_contains($mItem['properties'], 'nav')) {
            $navRaw = getZipEntryContents($zip, $mItem['path']);
            if (is_string($navRaw) && $navRaw !== '') {
                $navDir = dirname($mItem['path']);
                if ($navDir === '.') {
                    $navDir = '';
                }
                $navDom = new DOMDocument();
                @$navDom->loadHTML('<?xml encoding="utf-8" ?>' . $navRaw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
                $navLinks = $navDom->getElementsByTagName('a');
                foreach ($navLinks as $aEl) {
                    $href = trim($aEl->getAttribute('href'));
                    $lbl  = trim(preg_replace('/\s+/u', ' ', $aEl->textContent) ?? '');
                    if ($href !== '' && $lbl !== '') {
                        $p = resolveEpubZipPath($navDir, $href);
                        if ($p !== '' && !isset($tocTitlesByPath[$p])) {
                            $tocTitlesByPath[$p] = mb_substr($lbl, 0, 140);
                        }
                    }
                }
            }
            break;
        }
    }

    // 5b) EPUB 2 NCX (falls noch leer)
    if (empty($tocTitlesByPath)) {
        foreach ($manifest as $mItem) {
            if ($mItem['mime'] === 'application/x-dtbncx+xml' || str_ends_with(strtolower($mItem['path']), '.ncx')) {
                $ncxRaw = getZipEntryContents($zip, $mItem['path']);
                if (is_string($ncxRaw) && $ncxRaw !== '') {
                    $ncxDir = dirname($mItem['path']);
                    if ($ncxDir === '.') {
                        $ncxDir = '';
                    }
                    $ncxDom = new DOMDocument();
                    @$ncxDom->loadXML($ncxRaw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
                    $ncxXpath = new DOMXPath($ncxDom);
                    $navPoints = $ncxXpath->query('//*[local-name()="navPoint"]');
                    if ($navPoints !== false) {
                        foreach ($navPoints as $np) {
                            $txtNodes = $ncxXpath->query('./*[local-name()="navLabel"]/*[local-name()="text"]', $np);
                            $srcNodes = $ncxXpath->query('./*[local-name()="content"]', $np);
                            $lbl = ($txtNodes !== false && $txtNodes->length > 0) ? trim((string)$txtNodes->item(0)?->textContent) : '';
                            $src = ($srcNodes !== false && $srcNodes->length > 0 && $srcNodes->item(0) instanceof DOMElement)
                                ? trim($srcNodes->item(0)->getAttribute('src'))
                                : '';
                            if ($lbl !== '' && $src !== '') {
                                $p = resolveEpubZipPath($ncxDir, $src);
                                if ($p !== '' && !isset($tocTitlesByPath[$p])) {
                                    $tocTitlesByPath[$p] = mb_substr($lbl, 0, 140);
                                }
                            }
                        }
                    }
                }
                break;
            }
        }
    }

    // 6. Spine (Lesereihenfolge der Kapitel) auslesen
    $chapters = [];
    $spineRefs = $xpath->query('//*[local-name()="spine"]/*[local-name()="itemref"]');
    if ($spineRefs !== false) {
        foreach ($spineRefs as $refEl) {
            if (!($refEl instanceof DOMElement)) {
                continue;
            }
            $idref = trim($refEl->getAttribute('idref'));
            if ($idref === '' || !isset($manifest[$idref])) {
                continue;
            }
            $mItem = $manifest[$idref];
            // Nur HTML/XHTML-Dokumente in die Lesereihenfolge aufnehmen
            if (
                !str_contains($mItem['mime'], 'html')
                && !str_contains($mItem['mime'], 'xml')
                && !preg_match('/\.(x?html?|xml)$/i', $mItem['path'])
            ) {
                continue;
            }

            $chapIdx   = count($chapters);
            $chapTitle = $tocTitlesByPath[$mItem['path']] ?? '';

            // Falls kein TOC-Eintrag vorhanden: Überschrift <h1>/<h2>/<title> direkt aus der Kapiteldatei lesen
            if ($chapTitle === '') {
                $chapRaw = getZipEntryContents($zip, $mItem['path']);
                if (is_string($chapRaw) && $chapRaw !== '') {
                    if (preg_match('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/isu', $chapRaw, $mH)) {
                        $cand = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($mH[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                        if ($cand !== '') {
                            $chapTitle = mb_substr($cand, 0, 120);
                        }
                    }
                    if ($chapTitle === '' && preg_match('/<title[^>]*>(.*?)<\/title>/isu', $chapRaw, $mT)) {
                        $cand = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($mT[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                        if ($cand !== '' && strtolower($cand) !== 'unknown' && strtolower($cand) !== 'untitled') {
                            $chapTitle = mb_substr($cand, 0, 120);
                        }
                    }
                }
            }

            if ($chapTitle === '') {
                $chapTitle = 'Abschnitt ' . ($chapIdx + 1);
            }

            $chapters[] = [
                'index' => $chapIdx,
                'id'    => $idref,
                'path'  => $mItem['path'],
                'title' => $chapTitle,
            ];
        }
    }

    $zip->close();

    if (empty($chapters)) {
        $emptyResult['error'] = 'Dieses EPUB enthält keine lesbaren Textkapitel.';
        return $emptyResult;
    }

    return [
        'ok'          => true,
        'title'       => mb_substr($title, 0, 250),
        'author'      => mb_substr($author, 0, 250),
        'language'    => mb_substr($language, 0, 40),
        'description' => mb_substr($description, 0, 1500),
        'cover_path'  => $coverPath,
        'cover_mime'  => $coverMime,
        'chapters'    => $chapters,
    ];
}

/**
 * Liefert gecachte Buch-Metadaten (Titel, Autor, Cover-Data-URI, Kapitelanzahl) für das Bücherregal,
 * damit die Übersicht blitzschnell lädt.
 *
 * @return array{title: string, author: string, language: string, description: string, chapter_count: int, cover_data_uri: string}
 */
function getCachedEpubMetadata(PDO $pdo, int $fileId, string $physicalPath, string $originalName): array
{
    $mtime = is_file($physicalPath) ? (int)filemtime($physicalPath) : 0;

    try {
        $stmt = $pdo->prepare("SELECT * FROM `ebook_metadata_cache` WHERE `file_id` = :fid LIMIT 1");
        $stmt->execute([':fid' => $fileId]);
        $cached = $stmt->fetch();
        if ($cached && (int)$cached['file_mtime'] === $mtime && $cached['title'] !== '') {
            return [
                'title'          => (string)$cached['title'],
                'author'         => (string)$cached['author'],
                'language'       => (string)$cached['language'],
                'description'    => (string)($cached['description'] ?? ''),
                'chapter_count'  => max(1, (int)$cached['chapter_count']),
                'cover_data_uri' => (string)($cached['cover_data_uri'] ?? ''),
            ];
        }
    } catch (Throwable) {
    }

    $info = inspectEpubArchive($physicalPath, $originalName);
    $coverDataUri = '';

    if ($info['ok'] && $info['cover_path'] !== '' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($physicalPath) === true) {
            $rawImg = getZipEntryContents($zip, $info['cover_path']);
            $zip->close();
            // Bis zu 750 KB als Data-URI cachen (größere Cover über den Asset-Endpunkt ausliefern)
            if (is_string($rawImg) && strlen($rawImg) > 0 && strlen($rawImg) <= 768000) {
                $mime = $info['cover_mime'] !== '' ? $info['cover_mime'] : 'image/jpeg';
                $coverDataUri = 'data:' . $mime . ';base64,' . base64_encode($rawImg);
            } elseif (is_string($rawImg) && strlen($rawImg) > 768000) {
                $coverDataUri = 'ebooks.php?action=asset&id=' . $fileId . '&path=' . rawurlencode($info['cover_path']);
            }
        }
    }

    $meta = [
        'title'          => $info['title'],
        'author'         => $info['author'],
        'language'       => $info['language'],
        'description'    => $info['description'],
        'chapter_count'  => max(1, count($info['chapters'])),
        'cover_data_uri' => $coverDataUri,
    ];

    try {
        $stmtUp = $pdo->prepare(
            "INSERT INTO `ebook_metadata_cache`
                (`file_id`, `file_mtime`, `title`, `author`, `language`, `description`, `chapter_count`, `cover_data_uri`)
             VALUES (:fid, :mt, :title, :author, :lang, :desc, :cnt, :cover)
             ON DUPLICATE KEY UPDATE
                `file_mtime`     = VALUES(`file_mtime`),
                `title`          = VALUES(`title`),
                `author`         = VALUES(`author`),
                `language`       = VALUES(`language`),
                `description`    = VALUES(`description`),
                `chapter_count`  = VALUES(`chapter_count`),
                `cover_data_uri` = VALUES(`cover_data_uri`)"
        );
        $stmtUp->execute([
            ':fid'    => $fileId,
            ':mt'     => $mtime,
            ':title'  => $meta['title'],
            ':author' => $meta['author'],
            ':lang'   => $meta['language'],
            ':desc'   => $meta['description'],
            ':cnt'    => $meta['chapter_count'],
            ':cover'  => $meta['cover_data_uri'],
        ]);
    } catch (Throwable) {
    }

    return $meta;
}

/**
 * Bereitet das HTML eines einzelnen EPUB-Kapitels XSS-sicher für den Browser-Reader auf
 * und schreibt interne Bild- und Kapitel-Links um.
 *
 * @param array<int, array{index: int, id: string, path: string, title: string}> $allChapters
 */
function renderEpubChapterSafeHtml(string $physicalPath, int $fileId, array $allChapters, int $chapterIndex): string
{
    if (!isset($allChapters[$chapterIndex]) || !class_exists('ZipArchive')) {
        return '<p class="text-slate-500 italic">Kapitel konnte nicht geladen werden.</p>';
    }

    $chapterMeta = $allChapters[$chapterIndex];
    $chapPath    = $chapterMeta['path'];
    $chapDir     = dirname($chapPath);
    if ($chapDir === '.') {
        $chapDir = '';
    }

    $zip = new ZipArchive();
    if ($zip->open($physicalPath) !== true) {
        return '<p class="text-slate-500 italic">EPUB-Archiv konnte nicht geöffnet werden.</p>';
    }

    $rawHtml = getZipEntryContents($zip, $chapPath);
    $zip->close();

    if (!is_string($rawHtml) || trim($rawHtml) === '') {
        return '<p class="text-slate-400 italic">Dieser Abschnitt enthält keinen sichtbaren Text.</p>';
    }

    // Nur den <body>-Inhalt extrahieren, falls vorhanden
    if (preg_match('/<body[^>]*>(.*)<\/body>/isu', $rawHtml, $mBody)) {
        $bodyHtml = $mBody[1];
    } else {
        $bodyHtml = $rawHtml;
    }

    // Unsichere Tags (<script>, <iframe>, <object>, <embed>, <style>, <link>, <meta>, <form>, <base>) entfernen
    $bodyHtml = preg_replace('#<(script|iframe|object|embed|style|link|meta|head|form|base)\b[^>]*>.*?</\1>#is', '', $bodyHtml) ?? $bodyHtml;
    $bodyHtml = preg_replace('#<(script|iframe|object|embed|link|meta|form|base)\b[^>]*/?>#is', '', $bodyHtml) ?? $bodyHtml;

    // Inline Event-Handler (onclick, onload, onerror ...) und javascript:-URIs entfernen
    $bodyHtml = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $bodyHtml) ?? $bodyHtml;
    $bodyHtml = preg_replace('/(href|src|xlink:href|action|formaction)\s*=\s*(["\']?)\s*(?:javascript|vbscript|data\s*:\s*text\/html)\s*:[^"\'\s>]*/iu', '$1=$2#', $bodyHtml) ?? $bodyHtml;

    // Zuordnung von ZIP-Pfad -> Kapitel-Index für interne Buch-Links
    $pathToChapterIdx = [];
    foreach ($allChapters as $c) {
        $pathToChapterIdx[$c['path']] = (int)$c['index'];
    }

    // Bilder (<img src="..."> und SVG <image xlink:href="..." / href="...">) auf Asset-Endpunkt umschreiben
    $bodyHtml = preg_replace_callback(
        '/\b(src|xlink:href)\s*=\s*(["\'])([^"\']+)\2/iu',
        static function (array $m) use ($chapDir, $fileId): string {
            $attr = $m[1];
            $q    = $m[2];
            $val  = trim($m[3]);
            if ($val === '' || str_starts_with($val, 'data:') || preg_match('#^https?://#i', $val)) {
                return $m[0];
            }
            $resolved = resolveEpubZipPath($chapDir, $val);
            if ($resolved === '') {
                return $m[0];
            }
            $assetUrl = 'ebooks.php?action=asset&id=' . $fileId . '&path=' . rawurlencode($resolved);
            return $attr . '=' . $q . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . $q;
        },
        $bodyHtml
    ) ?? $bodyHtml;

    // Interne Kapitel-Links (<a href="chap02.xhtml#anchor">) auf Reader-Kapitel umschreiben
    $bodyHtml = preg_replace_callback(
        '/<a\b([^>]*?)\bhref\s*=\s*(["\'])([^"\']+)\2([^>]*)>/iu',
        static function (array $m) use ($chapDir, $fileId, $pathToChapterIdx): string {
            $before = $m[1];
            $q      = $m[2];
            $href   = trim($m[3]);
            $after  = $m[4];

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                return $m[0];
            }
            if (preg_match('#^https?://#i', $href)) {
                return '<a' . $before . 'href=' . $q . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . $q . ' target="_blank" rel="noopener noreferrer"' . $after . '>';
            }

            $parts = explode('#', $href, 2);
            $resolved = resolveEpubZipPath($chapDir, $parts[0]);
            if ($resolved !== '' && isset($pathToChapterIdx[$resolved])) {
                $targetCh = $pathToChapterIdx[$resolved];
                $newUrl   = 'ebooks.php?id=' . $fileId . '&ch=' . $targetCh . (!empty($parts[1]) ? '#' . rawurlencode($parts[1]) : '');
                return '<a' . $before . 'href=' . $q . htmlspecialchars($newUrl, ENT_QUOTES, 'UTF-8') . $q . $after . '>';
            }

            return $m[0];
        },
        $bodyHtml
    ) ?? $bodyHtml;

    return $bodyHtml;
}

// =============================================================================
// 1. ASSET-ENDPUNKT: BILDER INNERHALB EINES EPUBS AUSLIEFERN
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'asset') {
    $fileId    = (int)($_GET['id'] ?? 0);
    $assetPath = ltrim(str_replace('\\', '/', trim((string)($_GET['path'] ?? ''))), '/');

    if ($fileId <= 0 || $assetPath === '' || str_contains($assetPath, '..')) {
        http_response_code(400);
        exit;
    }

    $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
    if (!$authFile) {
        http_response_code(403);
        exit;
    }

    $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
    if (!is_file($physicalPath) || !class_exists('ZipArchive')) {
        http_response_code(404);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($physicalPath) !== true) {
        http_response_code(404);
        exit;
    }

    $rawAsset = getZipEntryContents($zip, $assetPath);
    $zip->close();

    if (!is_string($rawAsset) || $rawAsset === '') {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
        'svg'          => 'image/svg+xml',
        'jpg', 'jpeg'  => 'image/jpeg',
        default        => 'application/octet-stream',
    };

    if ($ext === 'svg') {
        $rawAsset = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $rawAsset) ?? $rawAsset;
        $rawAsset = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $rawAsset) ?? $rawAsset;
        $rawAsset = preg_replace('/(href|xlink:href)\s*=\s*(["\']?)\s*javascript:[^"\'\s>]*/iu', '$1=$2#', $rawAsset) ?? $rawAsset;
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . strlen($rawAsset));
    echo $rawAsset;
    exit;
}

// =============================================================================
// 2. AJAX- & FORMULAR-AKTIONEN (POST)
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    $csrf       = (string)($_POST['csrf_token'] ?? '');

    if (!verifyCsrfToken($csrf)) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Ungültiges CSRF-Token.']);
            exit;
        }
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuchen Sie es erneut.');
        header('Location: ebooks.php');
        exit;
    }

    // 2a) Lesefortschritt & Lese-Einstellungen automatisch speichern (AJAX)
    if ($postAction === 'save_progress') {
        header('Content-Type: application/json; charset=UTF-8');
        $fileId        = (int)($_POST['file_id'] ?? 0);
        $chapterIdx    = max(0, (int)($_POST['chapter_index'] ?? 0));
        $scrollPct     = max(0.0, min(100.0, (float)($_POST['scroll_pct'] ?? 0)));
        $totalChapters = max(1, (int)($_POST['total_chapters'] ?? 1));
        $prefsJson     = trim((string)($_POST['reader_prefs'] ?? ''));

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
            exit;
        }

        $overallPct = round((($chapterIdx + ($scrollPct / 100.0)) / $totalChapters) * 100.0, 1);
        $overallPct = max(0.0, min(100.0, $overallPct));

        $stmt = $pdo->prepare(
            "INSERT INTO `ebook_progress`
                (`user_id`, `file_id`, `chapter_index`, `scroll_pct`, `total_chapters`, `progress_pct`, `reader_prefs`)
             VALUES (:uid, :fid, :ch, :sp, :tc, :pp, :prefs)
             ON DUPLICATE KEY UPDATE
                `chapter_index`  = VALUES(`chapter_index`),
                `scroll_pct`     = VALUES(`scroll_pct`),
                `total_chapters` = VALUES(`total_chapters`),
                `progress_pct`   = VALUES(`progress_pct`),
                `reader_prefs`   = COALESCE(NULLIF(VALUES(`reader_prefs`), ''), `reader_prefs`)"
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':fid'   => $fileId,
            ':ch'    => $chapterIdx,
            ':sp'    => $scrollPct,
            ':tc'    => $totalChapters,
            ':pp'    => $overallPct,
            ':prefs' => $prefsJson,
        ]);

        echo json_encode(['ok' => true, 'progress_pct' => $overallPct]);
        exit;
    }

    // 2b) Lesezeichen hinzufügen (AJAX)
    if ($postAction === 'add_bookmark') {
        header('Content-Type: application/json; charset=UTF-8');
        $fileId       = (int)($_POST['file_id'] ?? 0);
        $chapterIdx   = max(0, (int)($_POST['chapter_index'] ?? 0));
        $chapterTitle = mb_substr(trim((string)($_POST['chapter_title'] ?? '')), 0, 180);
        $scrollPct    = max(0.0, min(100.0, (float)($_POST['scroll_pct'] ?? 0)));
        $note         = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 240);

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO `ebook_bookmarks` (`user_id`, `file_id`, `chapter_index`, `chapter_title`, `scroll_pct`, `note`)
             VALUES (:uid, :fid, :ch, :title, :sp, :note)"
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':fid'   => $fileId,
            ':ch'    => $chapterIdx,
            ':title' => $chapterTitle ?: ('Kapitel ' . ($chapterIdx + 1)),
            ':sp'    => $scrollPct,
            ':note'  => $note,
        ]);

        echo json_encode([
            'ok' => true,
            'id' => (int)$pdo->lastInsertId(),
        ]);
        exit;
    }

    // 2c) Lesezeichen löschen (AJAX)
    if ($postAction === 'delete_bookmark') {
        header('Content-Type: application/json; charset=UTF-8');
        $bmId = (int)($_POST['bookmark_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM `ebook_bookmarks` WHERE `id` = :id AND `user_id` = :uid");
        $stmt->execute([':id' => $bmId, ':uid' => $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 2d) Volltextsuche über alle Kapitel eines EPUBs (AJAX)
    if ($postAction === 'search_book') {
        header('Content-Type: application/json; charset=UTF-8');
        $fileId = (int)($_POST['file_id'] ?? 0);
        $query  = trim((string)($_POST['query'] ?? ''));

        if ($fileId <= 0 || mb_strlen($query) < 2) {
            echo json_encode(['ok' => true, 'matches' => []]);
            exit;
        }

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
            exit;
        }

        $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
        $info = inspectEpubArchive($physicalPath, (string)$authFile['original_name']);
        if (!$info['ok'] || !class_exists('ZipArchive')) {
            echo json_encode(['ok' => false, 'error' => 'Buch konnte nicht durchsucht werden.']);
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($physicalPath) !== true) {
            echo json_encode(['ok' => false, 'error' => 'Archivfehler.']);
            exit;
        }

        $matches = [];
        foreach ($info['chapters'] as $ch) {
            $raw = getZipEntryContents($zip, $ch['path']);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
            $pos   = mb_stripos($plain, $query, 0, 'UTF-8');
            if ($pos !== false) {
                $start   = max(0, $pos - 45);
                $snippet = ($start > 0 ? '…' : '') . mb_substr($plain, $start, 130, 'UTF-8') . '…';
                $matches[] = [
                    'chapter_index' => (int)$ch['index'],
                    'chapter_title' => $ch['title'],
                    'snippet'       => $snippet,
                ];
                if (count($matches) >= 20) {
                    break;
                }
            }
        }
        $zip->close();

        echo json_encode(['ok' => true, 'matches' => $matches]);
        exit;
    }

    // 2e) Neue .epub-Datei direkt ins Bücherregal hochladen
    if ($postAction === 'upload_epub') {
        if (!isset($_FILES['epub_file']) || !is_array($_FILES['epub_file'])) {
            setFlash('error', 'Bitte wählen Sie eine .epub-Datei zum Hochladen aus.');
            header('Location: ebooks.php');
            exit;
        }

        $upErr = (int)($_FILES['epub_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upErr !== UPLOAD_ERR_OK) {
            setFlash('error', getUploadErrorMessage($upErr));
            header('Location: ebooks.php');
            exit;
        }

        $origName = basename(trim((string)($_FILES['epub_file']['name'] ?? 'Buch.epub')));
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'epub') {
            setFlash('error', 'Der E-Book-Reader unterstützt ausschließlich Dateien im Format .epub.');
            header('Location: ebooks.php');
            exit;
        }

        $tmpFile  = (string)$_FILES['epub_file']['tmp_name'];
        $fileSize = (int)($_FILES['epub_file']['size'] ?? 0);
        $stats    = getUserStorageStats($pdo, $userId);

        if ($fileSize <= 0 || $fileSize > $stats['free']) {
            setFlash('error', 'Nicht genügend freier Cloud-Speicherplatz für dieses E-Book vorhanden.');
            header('Location: ebooks.php');
            exit;
        }

        if (!is_dir(UPLOAD_DIR)) {
            @mkdir(UPLOAD_DIR, 0750, true);
        }

        do {
            $storedFilename = generateSafeStoredFilename();
            $destPath       = UPLOAD_DIR . $storedFilename;
        } while (file_exists($destPath));

        if (!move_uploaded_file($tmpFile, $destPath)) {
            setFlash('error', 'Das E-Book konnte auf dem Server nicht gespeichert werden.');
            header('Location: ebooks.php');
            exit;
        }
        @chmod($destPath, 0640);

        $rawTargetFolder = trim((string)($_POST['target_folder'] ?? 'E-Books'));
        $targetFolder    = $rawTargetFolder !== ''
            ? extractSanitizedFolderPath($rawTargetFolder . '/placeholder')
            : 'E-Books';
        if ($targetFolder !== '') {
            registerFolderHierarchy($pdo, $userId, $targetFolder, null);
        }

        $origName = sanitizeOriginalFilename($origName);
        $stmtIns = $pdo->prepare(
            "INSERT INTO `files` (`user_id`, `group_folder_id`, `filename`, `original_name`, `folder_path`, `file_size`, `mime_type`, `is_encrypted`)
             VALUES (:uid, NULL, :fn, :orig, :fp, :sz, 'application/epub+zip', 0)"
        );
        $stmtIns->execute([
            ':uid'  => $userId,
            ':fn'   => $storedFilename,
            ':orig' => $origName,
            ':fp'   => $targetFolder,
            ':sz'   => $fileSize,
        ]);

        $newId = (int)$pdo->lastInsertId();
        recordModuleUsage($pdo, 'ebooks', true);
        setFlash('success', 'E-Book „' . $origName . '“ wurde erfolgreich in Ihre Bibliothek aufgenommen.');
        header('Location: ebooks.php?id=' . $newId);
        exit;
    }
}

$csrfToken = getCsrfToken();
$bookFileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// =============================================================================
// ANSICHT A: LESE-MODUS FÜR EIN AUSGEWÄHLTES BUCH (`ebooks.php?id=X`)
// =============================================================================
if ($bookFileId > 0) {
    $authFile = getAuthorizedFileForDownload($pdo, $userId, $bookFileId, $isAdmin);
    if (!$authFile) {
        setFlash('error', 'Dieses E-Book wurde nicht gefunden oder Sie haben keine Leseberechtigung.');
        header('Location: ebooks.php');
        exit;
    }

    $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
    if (isVaultEncryptedFile($physicalPath)) {
        setFlash('error', 'Dieses E-Book ist im Zero-Knowledge-Tresor verschlüsselt. Bitte entschlüsseln Sie es zuerst im Datei-Manager.');
        header('Location: index.php');
        exit;
    }

    $epubInfo = inspectEpubArchive($physicalPath, (string)$authFile['original_name']);
    if (!$epubInfo['ok']) {
        setFlash('error', $epubInfo['error'] ?? 'Das E-Book konnte nicht geöffnet werden.');
        header('Location: ebooks.php');
        exit;
    }

    // Gespeicherten Lesefortschritt & Lese-Einstellungen des Benutzers laden
    $stmtProg = $pdo->prepare("SELECT * FROM `ebook_progress` WHERE `user_id` = :uid AND `file_id` = :fid LIMIT 1");
    $stmtProg->execute([':uid' => $userId, ':fid' => $bookFileId]);
    $progRow = $stmtProg->fetch();

    $totalChapters = count($epubInfo['chapters']);
    $currentChIdx  = isset($_GET['ch'])
        ? max(0, min($totalChapters - 1, (int)$_GET['ch']))
        : ($progRow ? max(0, min($totalChapters - 1, (int)$progRow['chapter_index'])) : 0);

    $initialScrollPct = isset($_GET['scroll'])
        ? max(0.0, min(100.0, (float)$_GET['scroll']))
        : ($progRow && !isset($_GET['ch']) ? (float)$progRow['scroll_pct'] : 0.0);

    $savedPrefs = [
        'theme'      => 'sepia',
        'fontSize'   => 18,
        'fontFamily' => 'serif',
        'lineHeight' => '1.75',
        'maxWidth'   => 'max-w-3xl',
    ];
    if ($progRow && !empty($progRow['reader_prefs'])) {
        $decodedPrefs = json_decode((string)$progRow['reader_prefs'], true);
        if (is_array($decodedPrefs)) {
            $savedPrefs = array_merge($savedPrefs, $decodedPrefs);
        }
    }

    // Lesezeichen für dieses Buch laden
    $stmtBms = $pdo->prepare(
        "SELECT * FROM `ebook_bookmarks`
         WHERE `user_id` = :uid AND `file_id` = :fid
         ORDER BY `chapter_index` ASC, `scroll_pct` ASC, `id` DESC"
    );
    $stmtBms->execute([':uid' => $userId, ':fid' => $bookFileId]);
    $bookmarks = $stmtBms->fetchAll();

    $chapterHtml  = renderEpubChapterSafeHtml($physicalPath, $bookFileId, $epubInfo['chapters'], $currentChIdx);
    $chapterTitle = $epubInfo['chapters'][$currentChIdx]['title'] ?? ('Kapitel ' . ($currentChIdx + 1));
    $overallPct   = round((($currentChIdx + ($initialScrollPct / 100.0)) / $totalChapters) * 100.0, 1);

    $pageTitle = '📖 ' . $epubInfo['title'] . ' – E-Book-Reader';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <style>
        /* Leseflächen-Themes für den EPUB-Reader */
        .epub-surface-paper { background-color: #faf8f5; color: #1e293b; }
        .epub-surface-sepia { background-color: #f4ecd8; color: #3b2f2f; }
        .epub-surface-dusk  { background-color: #263140; color: #e2e8f0; }
        .epub-surface-night { background-color: #11161f; color: #cbd5e1; }

        .epub-font-serif { font-family: Georgia, 'Times New Roman', Cambria, serif; }
        .epub-font-sans  { font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .epub-font-mono  { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }

        #epubChapterContent img,
        #epubChapterContent svg {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
            margin: 1rem auto;
            display: block;
        }
        #epubChapterContent h1,
        #epubChapterContent h2,
        #epubChapterContent h3 {
            font-weight: 800;
            line-height: 1.3;
            margin-top: 1.4em;
            margin-bottom: 0.6em;
        }
        #epubChapterContent h1 { font-size: 1.55em; }
        #epubChapterContent h2 { font-size: 1.3em; }
        #epubChapterContent h3 { font-size: 1.15em; }
        #epubChapterContent p {
            margin-bottom: 1em;
            text-align: justify;
            hyphens: auto;
        }
        #epubChapterContent a {
            text-decoration: underline;
            color: #2563eb;
        }
        .epub-surface-dusk #epubChapterContent a,
        .epub-surface-night #epubChapterContent a {
            color: #60a5fa;
        }
        #epubChapterContent blockquote {
            border-left: 3px solid rgba(100,116,139,0.4);
            padding-left: 1rem;
            margin: 1rem 0;
            font-style: italic;
        }
    </style>

    <div class="max-w-6xl mx-auto px-3 sm:px-6 py-4 sm:py-6 space-y-4" id="epubReaderWrapper">
        <!-- OBERE READER-WERKZEUGLEISTE -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 px-3.5 py-2.5 sm:px-5 sm:py-3 shadow-xs flex flex-wrap items-center justify-between gap-2.5 sticky top-2 z-30">
            <div class="flex items-center gap-2.5 min-w-0">
                <a href="ebooks.php"
                   class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors shrink-0">
                    &larr; <span class="hidden sm:inline">Bibliothek</span>
                </a>
                <div class="min-w-0">
                    <h1 class="text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white truncate max-w-[180px] sm:max-w-md" title="<?= e($epubInfo['title']) ?>">
                        📖 <?= e($epubInfo['title']) ?>
                    </h1>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                        <?php if ($epubInfo['author'] !== ''): ?>
                            <?= e($epubInfo['author']) ?> &middot;
                        <?php endif; ?>
                        <span>Kapitel <?= $currentChIdx + 1 ?> / <?= $totalChapters ?></span>
                        &middot; <strong class="text-brand-600 dark:text-brand-400" id="epubHeaderPctLabel"><?= number_format($overallPct, 0, ',', '.') ?> %</strong>
                    </p>
                </div>
            </div>

            <!-- Aktions-Buttons rechts -->
            <div class="flex items-center gap-1.5">
                <button type="button" onclick="toggleEpubDrawer('epubTocDrawer')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                        title="Inhaltsverzeichnis &amp; Kapitel">
                    <span>📑</span>
                    <span class="hidden md:inline">Kapitel (<?= $totalChapters ?>)</span>
                </button>

                <button type="button" onclick="toggleEpubDrawer('epubSearchDrawer')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                        title="Im gesamten Buch suchen">
                    <span>🔎</span>
                    <span class="hidden md:inline">Suche</span>
                </button>

                <button type="button" onclick="toggleEpubDrawer('epubBookmarkDrawer')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                        title="Lesezeichen &amp; Notizen">
                    <span>🔖</span>
                    <span class="hidden md:inline">Lesezeichen</span>
                    <span id="epubBookmarkCountBadge" class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 <?= count($bookmarks) > 0 ? '' : 'hidden' ?>">
                        <?= count($bookmarks) ?>
                    </span>
                </button>

                <button type="button" onclick="toggleEpubDrawer('epubPrefsDrawer')"
                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl bg-brand-50 dark:bg-brand-950/60 hover:bg-brand-100 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 text-xs font-bold cursor-pointer"
                        title="Schriftgröße, Schriftart &amp; Lesefarbe anpassen">
                    <span>Aa</span>
                    <span class="hidden sm:inline">Ansicht</span>
                </button>
            </div>
        </div>

        <!-- AUSKLAPPBARE WERKZEUG-PANELS (Kapitel / Suche / Lesezeichen / Ansicht) -->
        <!-- 1. INHALTSVERZEICHNIS -->
        <div id="epubTocDrawer" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-lg space-y-2.5">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">📑 Inhaltsverzeichnis (<?= $totalChapters ?> Abschnitte)</h2>
                <button type="button" onclick="toggleEpubDrawer('epubTocDrawer')" class="text-xs font-bold text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
            </div>
            <div class="max-h-72 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-1.5 pr-1">
                <?php foreach ($epubInfo['chapters'] as $chItem): ?>
                    <?php $isCurrentCh = ((int)$chItem['index'] === $currentChIdx); ?>
                    <a href="ebooks.php?id=<?= $bookFileId ?>&amp;ch=<?= (int)$chItem['index'] ?>"
                       class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-xs font-medium transition-colors <?= $isCurrentCh ? 'bg-brand-600 text-white font-bold shadow-2xs' : 'bg-slate-50 dark:bg-slate-800/70 hover:bg-brand-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200' ?>">
                        <span class="truncate"><?= (int)$chItem['index'] + 1 ?>. <?= e($chItem['title']) ?></span>
                        <?php if ($isCurrentCh): ?>
                            <span class="text-[10px] uppercase font-bold shrink-0">Aktuell</span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 2. VOLLTEXTSUCHE IM BUCH -->
        <div id="epubSearchDrawer" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-lg space-y-3">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🔎 Volltextsuche im gesamten Buch</h2>
                <button type="button" onclick="toggleEpubDrawer('epubSearchDrawer')" class="text-xs font-bold text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
            </div>
            <form onsubmit="runEpubBookSearch(event)" class="flex items-center gap-2">
                <input type="text" id="epubSearchInput" placeholder="Suchbegriff oder Name im Buch suchen…"
                       class="flex-1 px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <button type="submit" id="epubSearchBtn"
                        class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                    Suchen
                </button>
            </form>
            <div id="epubSearchResults" class="max-h-64 overflow-y-auto space-y-1.5 text-xs"></div>
        </div>

        <!-- 3. LESEZEICHEN & NOTIZEN -->
        <div id="epubBookmarkDrawer" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-lg space-y-3">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🔖 Meine Lesezeichen in diesem Buch</h2>
                <button type="button" onclick="toggleEpubDrawer('epubBookmarkDrawer')" class="text-xs font-bold text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
            </div>
            <form onsubmit="addEpubBookmarkNow(event)" class="flex flex-wrap sm:flex-nowrap items-center gap-2">
                <input type="text" id="epubBookmarkNoteInput" placeholder="Optionale Kurznotiz für die aktuelle Stelle…"
                       class="flex-1 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white">
                <button type="submit"
                        class="px-3.5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold shadow-2xs cursor-pointer shrink-0">
                    🔖 Aktuelle Stelle merken
                </button>
            </form>
            <div id="epubBookmarksList" class="max-h-56 overflow-y-auto space-y-1.5 text-xs">
                <?php if (empty($bookmarks)): ?>
                    <p class="text-slate-400 italic py-2">Noch keine Lesezeichen für dieses Buch gespeichert.</p>
                <?php else: ?>
                    <?php foreach ($bookmarks as $bm): ?>
                        <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700" data-bm-row="<?= (int)$bm['id'] ?>">
                            <a href="ebooks.php?id=<?= $bookFileId ?>&amp;ch=<?= (int)$bm['chapter_index'] ?>&amp;scroll=<?= e((string)$bm['scroll_pct']) ?>"
                               class="flex-1 min-w-0 hover:text-brand-600 dark:hover:text-brand-400">
                                <p class="font-bold text-slate-900 dark:text-white truncate">
                                    🔖 <?= e((string)$bm['chapter_title']) ?> (<?= number_format((float)$bm['scroll_pct'], 0) ?> %)
                                </p>
                                <?php if (trim((string)$bm['note']) !== ''): ?>
                                    <p class="text-slate-500 dark:text-slate-400 truncate"><?= e((string)$bm['note']) ?></p>
                                <?php endif; ?>
                            </a>
                            <button type="button" onclick="deleteEpubBookmark(<?= (int)$bm['id'] ?>)"
                                    class="p-1 text-slate-400 hover:text-rose-600 cursor-pointer" title="Lesezeichen löschen">
                                🗑️
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4. LESE-DARSTELLUNG (Schriftgröße, Schriftart, Farbschema) -->
        <div id="epubPrefsDrawer" class="hidden mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-lg space-y-3.5 text-xs">
            <div class="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🔤 Lese-Darstellung &amp; Augenkomfort</h2>
                <button type="button" onclick="toggleEpubDrawer('epubPrefsDrawer')" class="text-xs font-bold text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Farbschema -->
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">Hintergrundfarbe</label>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button type="button" onclick="updateEpubPref('theme', 'paper')" class="px-2.5 py-2 rounded-xl border border-slate-300 bg-[#faf8f5] text-slate-900 font-semibold cursor-pointer">☀️ Papier</button>
                        <button type="button" onclick="updateEpubPref('theme', 'sepia')" class="px-2.5 py-2 rounded-xl border border-amber-300 bg-[#f4ecd8] text-[#3b2f2f] font-semibold cursor-pointer">📜 Sepia</button>
                        <button type="button" onclick="updateEpubPref('theme', 'dusk')"  class="px-2.5 py-2 rounded-xl border border-slate-600 bg-[#263140] text-slate-100 font-semibold cursor-pointer">🌆 Schiefer</button>
                        <button type="button" onclick="updateEpubPref('theme', 'night')" class="px-2.5 py-2 rounded-xl border border-slate-800 bg-[#11161f] text-slate-300 font-semibold cursor-pointer">🌙 Nacht</button>
                    </div>
                </div>

                <!-- Schriftgröße -->
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">Schriftgröße (<span id="epubFontSizeLabel"><?= (int)$savedPrefs['fontSize'] ?></span> px)</label>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="stepEpubFontSize(-2)" class="flex-1 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">A-</button>
                        <button type="button" onclick="stepEpubFontSize(2)"  class="flex-1 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-bold text-sm cursor-pointer">A+</button>
                    </div>
                </div>

                <!-- Schriftart -->
                <div>
                    <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1.5">Schriftart</label>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button type="button" onclick="updateEpubPref('fontFamily', 'serif')" class="px-2 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-serif font-semibold cursor-pointer">Serifen</button>
                        <button type="button" onclick="updateEpubPref('fontFamily', 'sans')"  class="px-2 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-sans font-semibold cursor-pointer">Sans</button>
                        <button type="button" onclick="updateEpubPref('fontFamily', 'mono')"  class="px-2 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 font-mono font-semibold cursor-pointer">Mono</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- BUCHSEITEN-FLÄCHE -->
        <article id="epubPageSurface"
                 class="rounded-3xl border border-slate-200/90 dark:border-slate-800 shadow-md px-5 sm:px-12 md:px-16 py-8 sm:py-12 transition-colors epub-surface-<?= e((string)$savedPrefs['theme']) ?> epub-font-<?= e((string)$savedPrefs['fontFamily']) ?>">
            <div class="max-w-3xl mx-auto">
                <div class="pb-4 mb-6 border-b border-current/10 flex items-center justify-between text-xs opacity-70">
                    <span class="font-semibold truncate"><?= e($chapterTitle) ?></span>
                    <span class="shrink-0">Kapitel <?= $currentChIdx + 1 ?> von <?= $totalChapters ?></span>
                </div>

                <div id="epubChapterContent"
                     style="font-size: <?= (int)$savedPrefs['fontSize'] ?>px; line-height: <?= e((string)$savedPrefs['lineHeight']) ?>;">
                    <?= $chapterHtml ?>
                </div>
            </div>
        </article>

        <!-- UNTERE KAPITEL-NAVIGATION & FORTSCHRITTSBALKEN -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-3">
            <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                <div id="epubBottomProgressBar" class="h-full bg-brand-600 transition-all duration-300" style="width: <?= max(2, (float)$overallPct) ?>%;"></div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <?php if ($currentChIdx > 0): ?>
                    <a id="epubPrevChapterLink"
                       href="ebooks.php?id=<?= $bookFileId ?>&amp;ch=<?= $currentChIdx - 1 ?>"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-100 transition-colors">
                        &larr; Vorheriges Kapitel
                    </a>
                <?php else: ?>
                    <span class="text-xs text-slate-400 italic">Buchanfang</span>
                <?php endif; ?>

                <span class="text-xs font-semibold text-slate-500 dark:text-slate-400 text-center">
                    Tipp: Mit Pfeiltasten <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-[10px]">&larr;</kbd> <kbd class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-[10px]">&rarr;</kbd> umblättern
                </span>

                <?php if ($currentChIdx < $totalChapters - 1): ?>
                    <a id="epubNextChapterLink"
                       href="ebooks.php?id=<?= $bookFileId ?>&amp;ch=<?= $currentChIdx + 1 ?>"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors">
                        Nächstes Kapitel &rarr;
                    </a>
                <?php else: ?>
                    <span class="px-3 py-1.5 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-xs font-bold">
                        🎉 Ende des Buches
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    const EPUB_FILE_ID        = <?= (int)$bookFileId ?>;
    const EPUB_CHAPTER_INDEX  = <?= (int)$currentChIdx ?>;
    const EPUB_CHAPTER_TITLE  = <?= json_encode($chapterTitle) ?>;
    const EPUB_TOTAL_CHAPTERS = <?= (int)$totalChapters ?>;
    const EPUB_INITIAL_SCROLL = <?= (float)$initialScrollPct ?>;
    const EPUB_CSRF           = <?= json_encode($csrfToken) ?>;

    let epubPrefs = <?= json_encode($savedPrefs) ?>;
    let saveProgressTimer = null;

    function toggleEpubDrawer(id) {
        const ids = ['epubTocDrawer', 'epubSearchDrawer', 'epubBookmarkDrawer', 'epubPrefsDrawer'];
        ids.forEach((otherId) => {
            const el = document.getElementById(otherId);
            if (!el) return;
            if (otherId === id) {
                el.classList.toggle('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
    }

    function getCurrentScrollPercent() {
        const doc = document.documentElement;
        const maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);
        return Math.max(0, Math.min(100, Math.round((window.scrollY / maxScroll) * 1000) / 10));
    }

    function applyEpubPrefsToDom() {
        const surface = document.getElementById('epubPageSurface');
        const content = document.getElementById('epubChapterContent');
        const sizeLbl = document.getElementById('epubFontSizeLabel');
        if (surface) {
            surface.classList.remove('epub-surface-paper', 'epub-surface-sepia', 'epub-surface-dusk', 'epub-surface-night');
            surface.classList.remove('epub-font-serif', 'epub-font-sans', 'epub-font-mono');
            surface.classList.add('epub-surface-' + (epubPrefs.theme || 'sepia'));
            surface.classList.add('epub-font-' + (epubPrefs.fontFamily || 'serif'));
        }
        if (content) {
            content.style.fontSize = (epubPrefs.fontSize || 18) + 'px';
        }
        if (sizeLbl) {
            sizeLbl.textContent = String(epubPrefs.fontSize || 18);
        }
    }

    function updateEpubPref(key, val) {
        epubPrefs[key] = val;
        applyEpubPrefsToDom();
        saveEpubProgressNow();
    }

    function stepEpubFontSize(delta) {
        const current = parseInt(epubPrefs.fontSize || 18, 10);
        epubPrefs.fontSize = Math.max(13, Math.min(28, current + delta));
        applyEpubPrefsToDom();
        saveEpubProgressNow();
    }

    async function saveEpubProgressNow() {
        const scrollPct = getCurrentScrollPercent();
        const fd = new FormData();
        fd.append('action', 'save_progress');
        fd.append('ajax', '1');
        fd.append('csrf_token', EPUB_CSRF);
        fd.append('file_id', String(EPUB_FILE_ID));
        fd.append('chapter_index', String(EPUB_CHAPTER_INDEX));
        fd.append('scroll_pct', String(scrollPct));
        fd.append('total_chapters', String(EPUB_TOTAL_CHAPTERS));
        fd.append('reader_prefs', JSON.stringify(epubPrefs));

        try {
            const r = await fetch('ebooks.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d && d.ok && typeof d.progress_pct === 'number') {
                const pctLbl = document.getElementById('epubHeaderPctLabel');
                const bar    = document.getElementById('epubBottomProgressBar');
                if (pctLbl) pctLbl.textContent = Math.round(d.progress_pct) + ' %';
                if (bar) bar.style.width = Math.max(2, d.progress_pct) + '%';
            }
        } catch (e) {}
    }

    async function addEpubBookmarkNow(ev) {
        ev.preventDefault();
        const noteInp = document.getElementById('epubBookmarkNoteInput');
        const note    = noteInp ? noteInp.value.trim() : '';
        const scrollPct = getCurrentScrollPercent();

        const fd = new FormData();
        fd.append('action', 'add_bookmark');
        fd.append('ajax', '1');
        fd.append('csrf_token', EPUB_CSRF);
        fd.append('file_id', String(EPUB_FILE_ID));
        fd.append('chapter_index', String(EPUB_CHAPTER_INDEX));
        fd.append('chapter_title', EPUB_CHAPTER_TITLE);
        fd.append('scroll_pct', String(scrollPct));
        fd.append('note', note);

        try {
            const r = await fetch('ebooks.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d && d.ok) {
                window.location.reload();
            }
        } catch (e) {}
    }

    async function deleteEpubBookmark(bmId) {
        const fd = new FormData();
        fd.append('action', 'delete_bookmark');
        fd.append('ajax', '1');
        fd.append('csrf_token', EPUB_CSRF);
        fd.append('bookmark_id', String(bmId));

        try {
            const r = await fetch('ebooks.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d && d.ok) {
                const row = document.querySelector(`[data-bm-row="${bmId}"]`);
                if (row) row.remove();
            }
        } catch (e) {}
    }

    async function runEpubBookSearch(ev) {
        ev.preventDefault();
        const inp = document.getElementById('epubSearchInput');
        const box = document.getElementById('epubSearchResults');
        if (!inp || !box) return;
        const q = inp.value.trim();
        if (q.length < 2) return;

        box.innerHTML = '<p class="text-slate-400 italic py-2">Durchsuche alle Kapitel…</p>';

        const fd = new FormData();
        fd.append('action', 'search_book');
        fd.append('ajax', '1');
        fd.append('csrf_token', EPUB_CSRF);
        fd.append('file_id', String(EPUB_FILE_ID));
        fd.append('query', q);

        try {
            const r = await fetch('ebooks.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d || !d.ok || !Array.isArray(d.matches) || d.matches.length === 0) {
                box.innerHTML = '<p class="text-slate-400 italic py-2">Keine Treffer im Buch gefunden.</p>';
                return;
            }
            box.innerHTML = d.matches.map((m) => `
                <a href="ebooks.php?id=${EPUB_FILE_ID}&ch=${m.chapter_index}"
                   class="block p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition-colors">
                    <p class="font-bold text-brand-600 dark:text-brand-400">${m.chapter_index + 1}. ${m.chapter_title}</p>
                    <p class="text-slate-600 dark:text-slate-300 mt-0.5">${m.snippet}</p>
                </a>
            `).join('');
        } catch (e) {
            box.innerHTML = '<p class="text-rose-600 py-2">Fehler bei der Buchsuche.</p>';
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        if (EPUB_INITIAL_SCROLL > 1) {
            setTimeout(() => {
                const doc = document.documentElement;
                const maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);
                window.scrollTo({ top: (EPUB_INITIAL_SCROLL / 100) * maxScroll, behavior: 'smooth' });
            }, 150);
        }
        // Beim Öffnen des Kapitels Fortschritt direkt einmal sichern
        saveEpubProgressNow();
    });

    window.addEventListener('scroll', () => {
        if (saveProgressTimer) clearTimeout(saveProgressTimer);
        saveProgressTimer = setTimeout(saveEpubProgressNow, 1200);
    }, { passive: true });

    // Tastatur-Blättern mit Pfeiltasten links/rechts
    window.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName || '')) return;
        if (e.key === 'ArrowLeft') {
            const prev = document.getElementById('epubPrevChapterLink');
            if (prev) window.location.href = prev.href;
        } else if (e.key === 'ArrowRight') {
            const next = document.getElementById('epubNextChapterLink');
            if (next) window.location.href = next.href;
        }
    });
    </script>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// =============================================================================
// ANSICHT B: BÜCHERREGAL / E-BOOK-BIBLIOTHEK (`ebooks.php`)
// =============================================================================

// 1. Alle für den Benutzer zugänglichen .epub-Dateien laden (eigene & direkt geteilte)
$stmtEpubs = $pdo->prepare(
    "SELECT f.*, u.username AS owner_username, '' AS group_folder_name,
            CASE
                WHEN f.user_id = :uid1 THEN 'own'
                ELSE 'shared'
            END AS source_kind
     FROM `files` f
     LEFT JOIN `users` u ON u.id = f.user_id
     WHERE f.deleted_at IS NULL
       AND f.group_folder_id IS NULL
       AND LOWER(SUBSTRING_INDEX(f.original_name, '.', -1)) = 'epub'
       AND (
           f.user_id = :uid2
           OR EXISTS (
               SELECT 1 FROM `user_shares` us
               WHERE us.shared_with_user_id = :uid3
                 AND (
                     us.file_id = f.id
                     OR (us.folder_path IS NOT NULL AND us.owner_id = f.user_id AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, '/%')))
                 )
           )
       )
     ORDER BY f.upload_date DESC, f.id DESC"
);
$stmtEpubs->execute([
    ':uid1' => $userId,
    ':uid2' => $userId,
    ':uid3' => $userId,
]);
$rawEpubFiles = $stmtEpubs->fetchAll();

// 2. Gespeicherten Lesefortschritt des Benutzers für alle Bücher abrufen
$progressByFileId = [];
try {
    $stmtAllProg = $pdo->prepare("SELECT * FROM `ebook_progress` WHERE `user_id` = :uid ORDER BY `updated_at` DESC");
    $stmtAllProg->execute([':uid' => $userId]);
    foreach ($stmtAllProg->fetchAll() as $pRow) {
        $progressByFileId[(int)$pRow['file_id']] = $pRow;
    }
} catch (Throwable) {
}

// 3. Buch-Katalog mit gecachten Metadaten & Fortschritt zusammenbauen
$books = [];
$lastReadBook = null;
$lastReadTs   = 0;

$spineColors = [
    'from-amber-700 to-orange-900',
    'from-indigo-700 to-slate-900',
    'from-emerald-700 to-teal-950',
    'from-rose-700 to-red-950',
    'from-sky-700 to-blue-950',
    'from-purple-700 to-indigo-950',
];

foreach ($rawEpubFiles as $idx => $fRow) {
    $fid          = (int)$fRow['id'];
    $physicalPath = UPLOAD_DIR . (string)$fRow['filename'];
    $meta         = getCachedEpubMetadata($pdo, $fid, $physicalPath, (string)$fRow['original_name']);
    $prog         = $progressByFileId[$fid] ?? null;
    $progPct      = $prog ? (float)$prog['progress_pct'] : 0.0;
    $chapIdx      = $prog ? (int)$prog['chapter_index'] : 0;
    $updatedAt    = $prog ? (string)$prog['updated_at'] : null;

    if ($progPct >= 98.0) {
        $statusKey = 'finished';
    } elseif ($prog !== null && $progPct > 0.0) {
        $statusKey = 'reading';
    } else {
        $statusKey = 'unread';
    }

    $bookEntry = [
        'id'                => $fid,
        'original_name'     => (string)$fRow['original_name'],
        'folder_path'       => (string)($fRow['folder_path'] ?? ''),
        'file_size'         => (int)$fRow['file_size'],
        'uploaded_at'       => (string)($fRow['upload_date'] ?? ''),
        'source_kind'       => (string)$fRow['source_kind'],
        'owner_username'    => (string)($fRow['owner_username'] ?? ''),
        'group_folder_name' => (string)($fRow['group_folder_name'] ?? ''),
        'title'             => $meta['title'],
        'author'            => $meta['author'],
        'language'          => $meta['language'],
        'description'       => $meta['description'],
        'chapter_count'     => $meta['chapter_count'],
        'cover_data_uri'    => $meta['cover_data_uri'],
        'progress_pct'      => $progPct,
        'chapter_index'     => $chapIdx,
        'last_read_at'      => $updatedAt,
        'status_key'        => $statusKey,
        'gradient'          => $spineColors[$fid % count($spineColors)],
    ];

    $books[] = $bookEntry;

    if ($updatedAt !== null) {
        $ts = strtotime($updatedAt) ?: 0;
        if ($ts > $lastReadTs) {
            $lastReadTs   = $ts;
            $lastReadBook = $bookEntry;
        }
    }
}

$pageTitle = '📚 E-Book-Reader & Bibliothek';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">
    <!-- KOPFBEREICH & DIREKT-UPLOAD -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                📚
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 dark:text-white">
                        E-Book-Bibliothek &amp; EPUB-Reader
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                        <?= count($books) ?> <?= count($books) === 1 ? 'Buch' : 'Bücher' ?>
                    </span>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Liest alle <code class="font-mono">.epub</code>-Bücher deiner Cloud inklusive Buchcover, Inhaltsverzeichnis, Volltextsuche und synchronisiertem Lesefortschritt.
                </p>
            </div>
        </div>

        <!-- Direkt-Upload für ein neues .epub -->
        <form action="ebooks.php" method="post" enctype="multipart/form-data" class="flex items-center gap-2">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="upload_epub">
            <input type="hidden" name="target_folder" value="E-Books">
            <input type="file" name="epub_file" id="epubUploadInput" accept=".epub,application/epub+zip" class="hidden" onchange="this.form.submit()">
            <button type="button" onclick="document.getElementById('epubUploadInput').click()"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors cursor-pointer">
                <span>📤</span>
                <span>.epub Buch hochladen</span>
            </button>
        </form>
    </div>

    <?php if ($lastReadBook !== null): ?>
        <!-- HERO-KARTE: ZULETZT GELESEN / DIREKT WEITERLESEN -->
        <section class="mc-card bg-gradient-to-r from-amber-50/90 via-white to-orange-50/60 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800/80 rounded-2xl border border-amber-200/80 dark:border-slate-700 p-5 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <?php if ($lastReadBook['cover_data_uri'] !== ''): ?>
                    <img src="<?= e($lastReadBook['cover_data_uri']) ?>" alt="<?= e($lastReadBook['title']) ?>"
                         class="w-16 h-24 rounded-xl object-cover shadow-md border border-slate-200 dark:border-slate-700 shrink-0">
                <?php else: ?>
                    <div class="w-16 h-24 rounded-xl bg-gradient-to-br <?= e($lastReadBook['gradient']) ?> text-white p-2 flex flex-col justify-between shadow-md shrink-0">
                        <span class="text-[9px] uppercase tracking-wider opacity-80 truncate"><?= e($lastReadBook['author'] ?: 'EPUB') ?></span>
                        <span class="text-[10px] font-bold leading-tight line-clamp-3"><?= e($lastReadBook['title']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="min-w-0 space-y-1">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                        🔖 Zuletzt gelesen
                    </span>
                    <h2 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white truncate">
                        <?= e($lastReadBook['title']) ?>
                    </h2>
                    <?php if ($lastReadBook['author'] !== ''): ?>
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-300 truncate">von <?= e($lastReadBook['author']) ?></p>
                    <?php endif; ?>
                    <div class="flex items-center gap-2.5 pt-1">
                        <div class="w-36 sm:w-48 h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                            <div class="h-full bg-amber-500" style="width: <?= max(3, (float)$lastReadBook['progress_pct']) ?>%;"></div>
                        </div>
                        <span class="text-xs font-bold text-amber-700 dark:text-amber-300">
                            <?= number_format((float)$lastReadBook['progress_pct'], 0) ?> % (Kapitel <?= (int)$lastReadBook['chapter_index'] + 1 ?> / <?= (int)$lastReadBook['chapter_count'] ?>)
                        </span>
                    </div>
                </div>
            </div>

            <a href="ebooks.php?id=<?= (int)$lastReadBook['id'] ?>"
               class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors shrink-0">
                📖 An letzter Stelle weiterlesen &rarr;
            </a>
        </section>
    <?php endif; ?>

    <!-- FILTER-LEISTE & LIVE-SUCHE -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5" role="group">
            <button type="button" onclick="filterEbooksShelf('all')" data-ebook-tab="all"
                    class="px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 text-white shadow-2xs cursor-pointer">
                📚 Alle (<?= count($books) ?>)
            </button>
            <button type="button" onclick="filterEbooksShelf('reading')" data-ebook-tab="reading"
                    class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">
                ⏳ Am Lesen
            </button>
            <button type="button" onclick="filterEbooksShelf('unread')" data-ebook-tab="unread"
                    class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">
                🆕 Ungelesen
            </button>
            <button type="button" onclick="filterEbooksShelf('finished')" data-ebook-tab="finished"
                    class="px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer">
                ✅ Gelesen
            </button>
        </div>

        <div class="relative w-full sm:w-72">
            <input type="text" id="ebookShelfSearchInput" oninput="applyEbooksShelfFilters()"
                   placeholder="Buchtitel, Autor oder Thema suchen…"
                   class="w-full px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        </div>
    </div>

    <!-- BÜCHERREGAL-RASTER -->
    <?php if (empty($books)): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-3">
            <div class="text-4xl">📚</div>
            <h2 class="text-base font-bold text-slate-900 dark:text-white">Noch keine E-Books (.epub) in deiner Cloud gefunden</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                Lade oben über den Button <strong>„📤 .epub Buch hochladen“</strong> oder im Datei-Manager deine erste <code class="font-mono">.epub</code>-Datei hoch, um sie hier im E-Book-Reader zu lesen.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" id="ebookShelfGrid">
            <?php foreach ($books as $b): ?>
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs hover:shadow-md transition-all flex gap-4"
                     data-ebook-card
                     data-status="<?= e($b['status_key']) ?>"
                     data-search="<?= e(mb_strtolower($b['title'] . ' ' . $b['author'] . ' ' . $b['description'] . ' ' . $b['original_name'], 'UTF-8')) ?>">
                    <!-- Buch-Cover -->
                    <a href="ebooks.php?id=<?= (int)$b['id'] ?>" class="shrink-0 group">
                        <?php if ($b['cover_data_uri'] !== ''): ?>
                            <img src="<?= e($b['cover_data_uri']) ?>" alt="<?= e($b['title']) ?>"
                                 class="w-24 h-36 rounded-xl object-cover shadow-md border border-slate-200 dark:border-slate-700 group-hover:scale-[1.03] transition-transform">
                        <?php else: ?>
                            <div class="w-24 h-36 rounded-xl bg-gradient-to-br <?= e($b['gradient']) ?> text-white p-3 flex flex-col justify-between shadow-md border border-white/15 group-hover:scale-[1.03] transition-transform">
                                <span class="text-[10px] uppercase tracking-wider opacity-80 truncate"><?= e($b['author'] ?: 'E-Book') ?></span>
                                <span class="text-xs font-extrabold leading-snug line-clamp-4"><?= e($b['title']) ?></span>
                                <span class="text-[9px] font-mono opacity-75">EPUB</span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <!-- Buch-Details & Aktionen -->
                    <div class="flex-1 min-w-0 flex flex-col justify-between">
                        <div class="space-y-1">
                            <div class="flex items-center justify-between gap-1.5">
                                <?php if ($b['source_kind'] === 'group'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 truncate">
                                        👥 <?= e($b['group_folder_name'] ?: 'Gruppenordner') ?>
                                    </span>
                                <?php elseif ($b['source_kind'] === 'shared'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 truncate">
                                        🔗 von <?= e($b['owner_username']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 truncate">
                                        📂 <?= e($b['folder_path'] !== '' ? $b['folder_path'] : 'Hauptverzeichnis') ?>
                                    </span>
                                <?php endif; ?>
                                <span class="text-[10px] text-slate-400 shrink-0"><?= e(formatBytes($b['file_size'])) ?></span>
                            </div>

                            <h3 class="text-sm font-extrabold text-slate-900 dark:text-white line-clamp-2 leading-snug" title="<?= e($b['title']) ?>">
                                <a href="ebooks.php?id=<?= (int)$b['id'] ?>" class="hover:text-brand-600 dark:hover:text-brand-400">
                                    <?= e($b['title']) ?>
                                </a>
                            </h3>

                            <?php if ($b['author'] !== ''): ?>
                                <p class="text-xs font-semibold text-slate-600 dark:text-slate-300 truncate">
                                    ✍️ <?= e($b['author']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($b['description'] !== ''): ?>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 line-clamp-2">
                                    <?= e($b['description']) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="pt-3 space-y-2">
                            <!-- Fortschrittsbalken -->
                            <div>
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="text-slate-500 dark:text-slate-400"><?= (int)$b['chapter_count'] ?> Kapitel</span>
                                    <span class="font-bold <?= $b['progress_pct'] > 0 ? 'text-brand-600 dark:text-brand-400' : 'text-slate-400' ?>">
                                        <?= $b['progress_pct'] > 0 ? (number_format((float)$b['progress_pct'], 0) . ' % gelesen') : 'Neu' ?>
                                    </span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full bg-brand-600" style="width: <?= (float)$b['progress_pct'] ?>%;"></div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-2 pt-0.5">
                                <a href="ebooks.php?id=<?= (int)$b['id'] ?>"
                                   class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-2xs transition-colors">
                                    📖 <?= $b['progress_pct'] > 0 ? 'Weiterlesen' : 'Jetzt lesen' ?>
                                </a>
                                <a href="download.php?id=<?= (int)$b['id'] ?>"
                                   class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-600 dark:text-slate-300 text-xs font-semibold"
                                   title=".epub herunterladen">
                                    ⬇️
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
let currentEbookTab = 'all';

function filterEbooksShelf(tabKey) {
    currentEbookTab = tabKey;
    document.querySelectorAll('[data-ebook-tab]').forEach((btn) => {
        const active = btn.getAttribute('data-ebook-tab') === tabKey;
        btn.className = active
            ? 'px-3 py-1.5 rounded-xl text-xs font-bold bg-brand-600 text-white shadow-2xs cursor-pointer'
            : 'px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 cursor-pointer';
    });
    applyEbooksShelfFilters();
}

function applyEbooksShelfFilters() {
    const q = (document.getElementById('ebookShelfSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('[data-ebook-card]').forEach((card) => {
        const st = card.getAttribute('data-status') || 'unread';
        const hay = card.getAttribute('data-search') || '';
        const matchTab = currentEbookTab === 'all' || st === currentEbookTab;
        const matchSearch = q === '' || hay.includes(q);
        card.classList.toggle('hidden', !(matchTab && matchSearch));
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
