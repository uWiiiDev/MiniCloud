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

/**
 * Normalisiert den Sprachcode aus den EPUB-Metadaten (z. B. "de-DE", "ger", "en-US") auf einen 2-stelligen ISO-Code.
 */
function normalizeEpubTtsLanguage(string $rawLang): string
{
    $clean = strtolower(trim($rawLang));
    if ($clean === '') {
        return 'de';
    }
    $primary = preg_split('/[-_]/', $clean)[0] ?? 'de';
    return match ($primary) {
        'de', 'deu', 'ger' => 'de',
        'en', 'eng'        => 'en',
        'fr', 'fra', 'fre' => 'fr',
        'es', 'spa'        => 'es',
        'it', 'ita'        => 'it',
        'nl', 'nld', 'dut' => 'nl',
        'pl', 'pol'        => 'pl',
        'pt', 'por'        => 'pt',
        'ru', 'rus'        => 'ru',
        'tr', 'tur'        => 'tr',
        default            => (strlen($primary) === 2 ? $primary : 'de'),
    };
}

/**
 * Extrahiert den bereinigten Vorlesetext (Absätze & Gesamttext) eines EPUB-Kapitels für den Hörbuch-Modus.
 *
 * @param array<int, array{index: int, id: string, path: string, title: string}> $allChapters
 * @return array{ok: bool, chapter_index: int, chapter_title: string, paragraphs: array<int, string>, full_text: string, char_count: int}
 */
function extractEpubChapterPlainText(string $physicalPath, array $allChapters, int $chapterIndex): array
{
    $fallbackTitle = 'Kapitel ' . ($chapterIndex + 1);
    if (!isset($allChapters[$chapterIndex]) || !class_exists('ZipArchive')) {
        return [
            'ok'            => false,
            'chapter_index' => $chapterIndex,
            'chapter_title' => $fallbackTitle,
            'paragraphs'    => [],
            'full_text'     => '',
            'char_count'    => 0,
        ];
    }

    $chapMeta  = $allChapters[$chapterIndex];
    $chapTitle = trim((string)($chapMeta['title'] ?? '')) ?: $fallbackTitle;

    $zip = new ZipArchive();
    if ($zip->open($physicalPath) !== true) {
        return [
            'ok'            => false,
            'chapter_index' => $chapterIndex,
            'chapter_title' => $chapTitle,
            'paragraphs'    => [],
            'full_text'     => '',
            'char_count'    => 0,
        ];
    }

    $rawHtml = getZipEntryContents($zip, (string)$chapMeta['path']);
    $zip->close();

    if (!is_string($rawHtml) || trim($rawHtml) === '') {
        return [
            'ok'            => true,
            'chapter_index' => $chapterIndex,
            'chapter_title' => $chapTitle,
            'paragraphs'    => [],
            'full_text'     => '',
            'char_count'    => 0,
        ];
    }

    if (preg_match('/<body[^>]*>(.*)<\/body>/isu', $rawHtml, $mBody)) {
        $html = $mBody[1];
    } else {
        $html = $rawHtml;
    }

    // Unsichtbare Elemente entfernen
    $html = preg_replace('#<(script|style|head|svg|math|noscript|iframe|object|embed)\b[^>]*>.*?</\1>#isu', ' ', $html) ?? $html;
    // Block-Tags in Absatzumbrüche umwandeln
    $html = preg_replace('#</(p|div|h[1-6]|li|blockquote|section|article|tr)>#iu', "\n\n", $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#iu', "\n", $html) ?? $html;

    $decoded = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines   = preg_split('/\R{1,}/u', $decoded) ?: [];

    $paragraphs = [];
    foreach ($lines as $line) {
        $cleanLine = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        if ($cleanLine !== '' && mb_strlen($cleanLine, 'UTF-8') >= 2) {
            $paragraphs[] = $cleanLine;
        }
    }

    $fullText = implode("\n\n", $paragraphs);

    return [
        'ok'            => true,
        'chapter_index' => $chapterIndex,
        'chapter_title' => $chapTitle,
        'paragraphs'    => $paragraphs,
        'full_text'     => $fullText,
        'char_count'    => mb_strlen($fullText, 'UTF-8'),
    ];
}

/**
 * Zerlegt einen Kapiteltext an Satz- und Wortgrenzen in kompakte Sprachsynthese-Segmente.
 *
 * @return array<int, string>
 */
function splitTextIntoTtsChunks(string $text, int $maxChars = 185): array
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($normalized === '') {
        return [];
    }

    // Zuerst an Satzenden (. ! ? ; :) aufteilen
    $sentences = preg_split('/(?<=[.!?;:])\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [$normalized];
    $chunks    = [];
    $current   = '';

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }

        if (mb_strlen($sentence, 'UTF-8') <= $maxChars) {
            $candidate = $current === '' ? $sentence : ($current . ' ' . $sentence);
            if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $sentence;
            }
            continue;
        }

        // Überlanger Satz: zuerst bisherigen Puffer abschließen, dann an Kommas/Leerzeichen teilen
        if ($current !== '') {
            $chunks[] = $current;
            $current  = '';
        }

        $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current  = '';
                }
                $parts = mb_str_split($word, $maxChars, 'UTF-8');
                foreach ($parts as $p) {
                    $chunks[] = $p;
                }
                continue;
            }
            $cand = $current === '' ? $word : ($current . ' ' . $word);
            if (mb_strlen($cand, 'UTF-8') <= $maxChars) {
                $current = $cand;
            } else {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $word;
            }
        }
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

/**
 * Entfernt einen führenden ID3v2-Header aus einem MP3-Segment, damit aneinandergefügte
 * MP3-Segmente nahtlos als durchgehender Audio-Stream abgespielt werden.
 */
function stripLeadingId3Tag(string $mp3Bytes): string
{
    if (strlen($mp3Bytes) > 10 && substr($mp3Bytes, 0, 3) === 'ID3') {
        $b6 = ord($mp3Bytes[6]) & 0x7F;
        $b7 = ord($mp3Bytes[7]) & 0x7F;
        $b8 = ord($mp3Bytes[8]) & 0x7F;
        $b9 = ord($mp3Bytes[9]) & 0x7F;
        $id3Size = ($b6 << 21) | ($b7 << 14) | ($b8 << 7) | $b9;
        $totalHeader = 10 + $id3Size;
        if (strlen($mp3Bytes) > $totalHeader) {
            return substr($mp3Bytes, $totalHeader);
        }
    }
    return $mp3Bytes;
}

/**
 * Lädt ein einzelnes kurzes Textsegment (<= 190 Zeichen) als MP3-Audiodaten
 * mit mehrstufigem Fallback (googleapis gtx -> google tw-ob -> stream_context).
 */
function fetchSingleTtsMp3Segment(string $chunkText, string $langCode): string|false
{
    $urls = [
        'https://translate.googleapis.com/translate_tts?ie=UTF-8&client=gtx&tl=' . rawurlencode($langCode) . '&q=' . rawurlencode($chunkText),
        'https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=' . rawurlencode($langCode) . '&q=' . rawurlencode($chunkText),
    ];

    if (function_exists('curl_init')) {
        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && is_string($body) && strlen($body) > 128) {
                return $body;
            }
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    foreach ($urls as $url) {
        $body = @file_get_contents($url, false, $ctx);
        if (is_string($body) && strlen($body) > 128) {
            return $body;
        }
    }

    return false;
}

/**
 * Erzeugt aus einem Text (beliebiger Länge) eine zusammenhängende MP3-Audiodatei
 * mittels paralleler Chunk-Synthese (cURL Multi mit Fallback auf Einzelabruf & Stream-Context).
 */
function synthesizeTtsMp3FromText(string $text, string $lang = 'de'): string|false
{
    $langCode = normalizeEpubTtsLanguage($lang);
    $chunks   = splitTextIntoTtsChunks($text, 185);
    if (empty($chunks)) {
        return false;
    }

    // Direktpfad für einzelne Sätze im Live-Hörbuch-Player (kein curl_multi-Overhead, reines Einzel-MP3)
    if (count($chunks) === 1) {
        return fetchSingleTtsMp3Segment($chunks[0], $langCode);
    }

    // Sicherheitslimit pro Kapitel (max. 240 Segmente ~ ca. 42.000 Zeichen pro Kapitel)
    if (count($chunks) > 240) {
        $chunks = array_slice($chunks, 0, 240);
    }

    $mp3Parts  = [];
    $batchSize = 6;
    $batches   = array_chunk($chunks, $batchSize, true);

    foreach ($batches as $batch) {
        if (function_exists('curl_multi_init') && function_exists('curl_init') && count($batch) > 1) {
            $mh      = curl_multi_init();
            $handles = [];

            foreach ($batch as $idx => $chunkText) {
                $url = 'https://translate.googleapis.com/translate_tts?ie=UTF-8&client=gtx&tl='
                    . rawurlencode($langCode) . '&q=' . rawurlencode($chunkText);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_CONNECTTIMEOUT => 8,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = $ch;
            }

            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $idx => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($code === 200 && is_string($body) && strlen($body) > 128) {
                    $mp3Parts[$idx] = $idx > 0 ? stripLeadingId3Tag($body) : $body;
                } else {
                    $fbBody = fetchSingleTtsMp3Segment($batch[$idx], $langCode);
                    if (is_string($fbBody) && strlen($fbBody) > 128) {
                        $mp3Parts[$idx] = $idx > 0 ? stripLeadingId3Tag($fbBody) : $fbBody;
                    }
                }
            }
            curl_multi_close($mh);
        } else {
            foreach ($batch as $idx => $chunkText) {
                $body = fetchSingleTtsMp3Segment($chunkText, $langCode);
                if (is_string($body) && strlen($body) > 128) {
                    $mp3Parts[$idx] = $idx > 0 ? stripLeadingId3Tag($body) : $body;
                }
            }
        }
    }

    if (empty($mp3Parts)) {
        return false;
    }

    ksort($mp3Parts);
    return implode('', $mp3Parts);
}

/**
 * Speichert eine generierte Hörbuch-Kapiteldatei (.mp3 oder .wav) direkt im MiniCloud-Ordner
 * "Hörbücher/<Buchtitel>/" des Benutzers (inkl. Duplikat-Aktualisierung bei erneutem Export).
 *
 * @return array{ok: bool, error?: string, file_id?: int, filename?: string, folder_path?: string, file_size?: int, file_size_formatted?: string}
 */
function saveGeneratedAudiobookFileToCloud(
    PDO $pdo,
    int $userId,
    string $bookTitle,
    int $chapterIndex,
    int $totalChapters,
    string $chapterTitle,
    string $audioBinary,
    string $ext = 'mp3',
    string $mimeType = 'audio/mpeg'
): array {
    $audioSize = strlen($audioBinary);
    if ($audioSize <= 0) {
        return ['ok' => false, 'error' => 'Leere Audiodatei.'];
    }

    $stats = getUserStorageStats($pdo, $userId);
    if ($audioSize > $stats['free']) {
        return ['ok' => false, 'error' => 'Nicht genügend freier Cloud-Speicherplatz für diese Hörbuch-Datei vorhanden.'];
    }

    $cleanBookFolder = trim(preg_replace('/[^\p{L}\p{N}\s\-_()]/u', '', $bookTitle) ?? '');
    $cleanBookFolder = trim(preg_replace('/\s+/u', ' ', $cleanBookFolder) ?? '');
    if ($cleanBookFolder === '') {
        $cleanBookFolder = 'Hörbuch';
    }
    $cleanBookFolder = mb_substr($cleanBookFolder, 0, 70, 'UTF-8');

    $targetFolder = extractSanitizedFolderPath('Hörbücher/' . $cleanBookFolder . '/placeholder');
    if ($targetFolder === '') {
        $targetFolder = 'Hörbücher';
    }
    registerFolderHierarchy($pdo, $userId, $targetFolder, null);

    $cleanChapTitle = trim(preg_replace('/[^\p{L}\p{N}\s\-_()]/u', '', $chapterTitle) ?? '');
    $cleanChapTitle = trim(preg_replace('/\s+/u', ' ', $cleanChapTitle) ?? '');
    if ($cleanChapTitle === '') {
        $cleanChapTitle = 'Kapitel ' . ($chapterIndex + 1);
    }
    $cleanChapTitle = mb_substr($cleanChapTitle, 0, 80, 'UTF-8');

    $padLen   = max(2, strlen((string)$totalChapters));
    $origName = sprintf('Kapitel %0' . $padLen . 'd - %s.%s', $chapterIndex + 1, $cleanChapTitle, $ext);
    $origName = sanitizeOriginalFilename($origName);

    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0750, true);
    }

    // Prüfen, ob genau diese Kapitel-Audiodatei im Zielordner bereits existiert -> überschreiben statt Duplikat anlegen
    $stmtEx = $pdo->prepare(
        "SELECT `id`, `filename` FROM `files`
         WHERE `user_id` = :uid
           AND `group_folder_id` IS NULL
           AND `folder_path` = :fp
           AND `original_name` = :orig
           AND `deleted_at` IS NULL
         LIMIT 1"
    );
    $stmtEx->execute([
        ':uid'  => $userId,
        ':fp'   => $targetFolder,
        ':orig' => $origName,
    ]);
    $existing = $stmtEx->fetch();

    if ($existing) {
        $savedFileId    = (int)$existing['id'];
        $storedFilename = (string)$existing['filename'];
        $destPath       = UPLOAD_DIR . $storedFilename;
        if (@file_put_contents($destPath, $audioBinary) === false) {
            return ['ok' => false, 'error' => 'Audiodatei konnte auf dem Server nicht aktualisiert werden.'];
        }
        @chmod($destPath, 0640);

        $stmtUpd = $pdo->prepare(
            "UPDATE `files`
             SET `file_size` = :sz, `mime_type` = :mime, `upload_date` = NOW()
             WHERE `id` = :id"
        );
        $stmtUpd->execute([
            ':sz'   => $audioSize,
            ':mime' => $mimeType,
            ':id'   => $savedFileId,
        ]);
    } else {
        do {
            $storedFilename = generateSafeStoredFilename();
            $destPath       = UPLOAD_DIR . $storedFilename;
        } while (file_exists($destPath));

        if (@file_put_contents($destPath, $audioBinary) === false) {
            return ['ok' => false, 'error' => 'Audiodatei konnte auf dem Server nicht gespeichert werden.'];
        }
        @chmod($destPath, 0640);

        $stmtIns = $pdo->prepare(
            "INSERT INTO `files` (`user_id`, `group_folder_id`, `filename`, `original_name`, `folder_path`, `file_size`, `mime_type`, `is_encrypted`)
             VALUES (:uid, NULL, :fn, :orig, :fp, :sz, :mime, 0)"
        );
        $stmtIns->execute([
            ':uid'  => $userId,
            ':fn'   => $storedFilename,
            ':orig' => $origName,
            ':fp'   => $targetFolder,
            ':sz'   => $audioSize,
            ':mime' => $mimeType,
        ]);
        $savedFileId = (int)$pdo->lastInsertId();
    }

    return [
        'ok'                  => true,
        'file_id'             => $savedFileId,
        'filename'            => $origName,
        'folder_path'         => $targetFolder,
        'file_size'           => $audioSize,
        'file_size_formatted' => formatBytes($audioSize),
    ];
}

// =============================================================================
// 1b. AUDIO-STREAM-ENDPUNKT FÜR EINZELNE ABSÄTZE IM LIVE-HÖRBUCH-PLAYER (GET)
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'tts_chunk') {
    @session_write_close();
    $chunkText = trim((string)($_GET['text'] ?? ''));
    $lang      = normalizeEpubTtsLanguage((string)($_GET['lang'] ?? 'de'));

    if ($chunkText === '') {
        http_response_code(400);
        exit;
    }

    $chunkText = mb_substr($chunkText, 0, 500, 'UTF-8');

    // Kurzzeit-Cache im Temp-Ordner (für Safari/iOS Range-Requests & Preloading ohne Doppel-Abruf)
    $cacheKey  = 'mc_tts_' . md5($lang . '|' . $chunkText) . '.mp3';
    $cacheFile = rtrim(sys_get_temp_dir(), '/\\') . '/' . $cacheKey;
    $mp3Data   = false;

    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 900 && filesize($cacheFile) > 128) {
        $mp3Data = @file_get_contents($cacheFile);
    }

    if (!is_string($mp3Data) || $mp3Data === '') {
        $mp3Data = synthesizeTtsMp3FromText($chunkText, $lang);
        if (is_string($mp3Data) && strlen($mp3Data) > 128) {
            @file_put_contents($cacheFile, $mp3Data);
        }
    }

    if (!is_string($mp3Data) || $mp3Data === '') {
        http_response_code(502);
        exit;
    }

    $totalLen = strlen($mp3Data);
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end   = ($m[2] !== '') ? min($totalLen - 1, (int)$m[2]) : ($totalLen - 1);
        if ($start >= 0 && $start <= $end && $end < $totalLen) {
            $partLen = ($end - $start) + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$totalLen}");
            header('Content-Length: ' . $partLen);
            echo substr($mp3Data, $start, $partLen);
            exit;
        }
    }

    header('Content-Length: ' . $totalLen);
    echo $mp3Data;
    exit;
}

// =============================================================================
// 1. ASSET-ENDPUNKT: BILDER INNERHALB EINES EPUBS AUSLIEFERN
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'asset') {
    @session_write_close();
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
        @session_write_close();
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
        @session_write_close();
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
        @session_write_close();
        $bmId = (int)($_POST['bookmark_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM `ebook_bookmarks` WHERE `id` = :id AND `user_id` = :uid");
        $stmt->execute([':id' => $bmId, ':uid' => $userId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 2d) Volltextsuche über alle Kapitel eines EPUBs (AJAX)
    if ($postAction === 'search_book') {
        header('Content-Type: application/json; charset=UTF-8');
        @session_write_close();
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

    // 2d-2) Kapiteltext & Segmente für den Hörbuch-Generator abrufen (AJAX)
    if ($postAction === 'get_chapter_tts_text') {
        header('Content-Type: application/json; charset=UTF-8');
        @session_write_close();
        $fileId     = (int)($_POST['file_id'] ?? 0);
        $chapterIdx = max(0, (int)($_POST['chapter_index'] ?? 0));

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für dieses Buch.']);
            exit;
        }

        $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
        $info = inspectEpubArchive($physicalPath, (string)$authFile['original_name']);
        if (!$info['ok'] || !isset($info['chapters'][$chapterIdx])) {
            echo json_encode(['ok' => false, 'error' => 'Kapitel konnte nicht gelesen werden.']);
            exit;
        }

        $extracted = extractEpubChapterPlainText($physicalPath, $info['chapters'], $chapterIdx);
        $chunks    = splitTextIntoTtsChunks($extracted['full_text'], 210);

        echo json_encode([
            'ok'             => true,
            'book_title'     => $info['title'],
            'language'       => normalizeEpubTtsLanguage($info['language']),
            'chapter_index'  => $chapterIdx,
            'total_chapters' => count($info['chapters']),
            'chapter_title'  => $extracted['chapter_title'],
            'paragraphs'     => $extracted['paragraphs'],
            'chunks'         => $chunks,
            'char_count'     => $extracted['char_count'],
        ]);
        exit;
    }

    // 2d-3) Kapitel als .mp3-Hörbuch synthetisieren & optional direkt in der MiniCloud speichern (AJAX)
    if ($postAction === 'generate_chapter_mp3') {
        header('Content-Type: application/json; charset=UTF-8');
        @session_write_close();
        @set_time_limit(180);

        $fileId      = (int)($_POST['file_id'] ?? 0);
        $chapterIdx  = max(0, (int)($_POST['chapter_index'] ?? 0));
        $saveToCloud = ((string)($_POST['save_to_cloud'] ?? '0') === '1');
        $langOverride = trim((string)($_POST['lang'] ?? ''));

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für dieses Buch.']);
            exit;
        }

        $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
        $info = inspectEpubArchive($physicalPath, (string)$authFile['original_name']);
        if (!$info['ok'] || !isset($info['chapters'][$chapterIdx])) {
            echo json_encode(['ok' => false, 'error' => 'Das gewählte Kapitel wurde im E-Book nicht gefunden.']);
            exit;
        }

        $totalChapters = count($info['chapters']);
        $extracted     = extractEpubChapterPlainText($physicalPath, $info['chapters'], $chapterIdx);
        $chapTitle     = $extracted['chapter_title'];
        $langCode      = normalizeEpubTtsLanguage($langOverride !== '' ? $langOverride : $info['language']);

        // Falls ein Abschnitt nur ein Cover-Bild ohne Text enthält, beim Komplett-Export sauber überspringen
        if ($extracted['char_count'] < 3) {
            echo json_encode([
                'ok'            => true,
                'skipped'       => true,
                'chapter_index' => $chapterIdx,
                'chapter_title' => $chapTitle,
                'reason'        => 'Abschnitt enthält keinen vorlesbaren Text (z. B. reines Buchcover).',
            ]);
            exit;
        }

        $spokenIntro = ($langCode === 'en' ? 'Chapter ' : 'Kapitel ') . ($chapterIdx + 1) . ': ' . $chapTitle . ".\n\n";
        $spokenText  = $spokenIntro . $extracted['full_text'];

        $mp3Binary = synthesizeTtsMp3FromText($spokenText, $langCode);
        if ($mp3Binary === false || $mp3Binary === '') {
            echo json_encode([
                'ok'    => false,
                'error' => 'Die MP3-Sprachsynthese konnte für dieses Kapitel nicht durchgeführt werden (bitte prüfen Sie die Internetverbindung des Servers oder nutzen Sie die lokale Browser-KI-Stimme).',
            ]);
            exit;
        }

        $padLen       = max(2, strlen((string)$totalChapters));
        $cleanChapLbl = trim(preg_replace('/[^\p{L}\p{N}\s\-_()]/u', '', $chapTitle) ?? '') ?: ('Kapitel ' . ($chapterIdx + 1));
        $downloadName = sanitizeOriginalFilename(sprintf('Kapitel %0' . $padLen . 'd - %s.mp3', $chapterIdx + 1, mb_substr($cleanChapLbl, 0, 75, 'UTF-8')));

        if ($saveToCloud) {
            $saveRes = saveGeneratedAudiobookFileToCloud(
                $pdo,
                $userId,
                $info['title'],
                $chapterIdx,
                $totalChapters,
                $chapTitle,
                $mp3Binary,
                'mp3',
                'audio/mpeg'
            );
            if (!$saveRes['ok']) {
                echo json_encode($saveRes);
                exit;
            }

            echo json_encode([
                'ok'                  => true,
                'saved_to_cloud'      => true,
                'file_id'             => $saveRes['file_id'],
                'filename'            => $saveRes['filename'],
                'folder_path'         => $saveRes['folder_path'],
                'file_size_formatted' => $saveRes['file_size_formatted'],
                'download_url'        => 'download.php?id=' . (int)$saveRes['file_id'],
                'stream_url'          => 'download.php?id=' . (int)$saveRes['file_id'] . '&inline=1',
                'folder_url'          => 'index.php?folder=' . rawurlencode((string)$saveRes['folder_path']),
            ]);
            exit;
        }

        echo json_encode([
            'ok'                  => true,
            'saved_to_cloud'      => false,
            'filename'            => $downloadName,
            'file_size_formatted' => formatBytes(strlen($mp3Binary)),
            'audio_base64'        => base64_encode($mp3Binary),
            'mime_type'           => 'audio/mpeg',
        ]);
        exit;
    }

    // 2d-4) Im Browser per lokaler KI-Stimme (Piper WebAssembly) erzeugte Audiodatei (.wav/.mp3) in der MiniCloud speichern (AJAX)
    if ($postAction === 'save_browser_audiobook') {
        header('Content-Type: application/json; charset=UTF-8');
        @session_write_close();

        $fileId     = (int)($_POST['file_id'] ?? 0);
        $chapterIdx = max(0, (int)($_POST['chapter_index'] ?? 0));
        $ext        = strtolower(trim((string)($_POST['ext'] ?? 'wav'))) === 'mp3' ? 'mp3' : 'wav';
        $mimeType   = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';

        $authFile = getAuthorizedFileForDownload($pdo, $userId, $fileId, $isAdmin);
        if (!$authFile) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für dieses Buch.']);
            exit;
        }

        if (!isset($_FILES['audio_blob']) || !is_array($_FILES['audio_blob']) || (int)($_FILES['audio_blob']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Keine gültige Audiodatei empfangen.']);
            exit;
        }

        $tmpPath     = (string)$_FILES['audio_blob']['tmp_name'];
        $audioBinary = @file_get_contents($tmpPath);
        if (!is_string($audioBinary) || $audioBinary === '') {
            echo json_encode(['ok' => false, 'error' => 'Audiodaten konnten nicht gelesen werden.']);
            exit;
        }

        $physicalPath = UPLOAD_DIR . (string)$authFile['filename'];
        $info = inspectEpubArchive($physicalPath, (string)$authFile['original_name']);
        $bookTitle     = $info['ok'] ? $info['title'] : (pathinfo((string)$authFile['original_name'], PATHINFO_FILENAME) ?: 'Hörbuch');
        $totalChapters = $info['ok'] ? max(1, count($info['chapters'])) : 1;
        $chapTitle     = ($info['ok'] && isset($info['chapters'][$chapterIdx]))
            ? (string)$info['chapters'][$chapterIdx]['title']
            : ('Kapitel ' . ($chapterIdx + 1));

        $saveRes = saveGeneratedAudiobookFileToCloud(
            $pdo,
            $userId,
            $bookTitle,
            $chapterIdx,
            $totalChapters,
            $chapTitle,
            $audioBinary,
            $ext,
            $mimeType
        );
        if (!$saveRes['ok']) {
            echo json_encode($saveRes);
            exit;
        }

        echo json_encode([
            'ok'                  => true,
            'saved_to_cloud'      => true,
            'file_id'             => $saveRes['file_id'],
            'filename'            => $saveRes['filename'],
            'folder_path'         => $saveRes['folder_path'],
            'file_size_formatted' => $saveRes['file_size_formatted'],
            'download_url'        => 'download.php?id=' . (int)$saveRes['file_id'],
            'stream_url'          => 'download.php?id=' . (int)$saveRes['file_id'] . '&inline=1',
            'folder_url'          => 'index.php?folder=' . rawurlencode((string)$saveRes['folder_path']),
        ]);
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
    $bookLangCode = normalizeEpubTtsLanguage($epubInfo['language']);
    $autoPlayAudio   = isset($_GET['autoplay']) && $_GET['autoplay'] === '1';
    $openAudioStudio = isset($_GET['audiostudio']) && $_GET['audiostudio'] === '1';

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

        /* Hervorhebung des aktuell vorgelesenen Absatzes im Hörbuch-Modus */
        .epub-tts-block {
            transition: background-color 0.25s ease, box-shadow 0.25s ease, transform 0.2s ease;
            border-radius: 0.6rem;
        }
        .epub-tts-clickable .epub-tts-block:hover {
            background-color: rgba(59, 130, 246, 0.08);
            cursor: pointer;
        }
        .epub-tts-active-block {
            background-color: rgba(245, 158, 11, 0.22) !important;
            box-shadow: -4px 0 0 0 #d97706, 0 0 0 6px rgba(245, 158, 11, 0.14);
        }
        .epub-surface-dusk .epub-tts-active-block,
        .epub-surface-night .epub-tts-active-block {
            background-color: rgba(56, 189, 248, 0.18) !important;
            box-shadow: -4px 0 0 0 #38bdf8, 0 0 0 6px rgba(56, 189, 248, 0.14);
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
                <button type="button" onclick="toggleEpubDrawer('epubAudioDrawer')" id="epubAudioToolbarBtn"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/70 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-bold cursor-pointer transition-colors"
                        title="Hörbuch-Player &amp; Hörbuch-Generator (MP3 / WAV)">
                    <span id="epubAudioToolbarIcon">🎧</span>
                    <span class="hidden sm:inline">Hörbuch</span>
                    <span id="epubAudioPlayingDot" class="hidden w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                </button>

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

        <!-- KOMPAKTE LIVE-HÖRBUCH-STEUERLEISTE (Erscheint automatisch während des Vorlesens) -->
        <div id="epubLivePlayerBar" class="hidden mc-card bg-gradient-to-r from-amber-50 via-white to-orange-50 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800 rounded-2xl border border-amber-300/80 dark:border-amber-800/80 px-4 py-2.5 shadow-md flex flex-wrap items-center justify-between gap-3 sticky top-16 z-20">
            <div class="flex items-center gap-2">
                <button type="button" onclick="epubTtsPrevBlock()"
                        class="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-xs font-bold cursor-pointer"
                        title="Vorheriger Absatz">
                    ⏮
                </button>
                <button type="button" onclick="epubTtsTogglePlay()" id="epubMiniPlayBtn"
                        class="px-3.5 py-1.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-extrabold flex items-center gap-1.5 shadow-2xs cursor-pointer">
                    <span id="epubMiniPlayIcon">⏸</span>
                    <span id="epubMiniPlayLabel">Pause</span>
                </button>
                <button type="button" onclick="epubTtsNextBlock()"
                        class="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 hover:bg-amber-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-xs font-bold cursor-pointer"
                        title="Nächster Absatz">
                    ⏭
                </button>
                <button type="button" onclick="epubTtsStop()"
                        class="w-8 h-8 rounded-xl bg-white dark:bg-slate-800 hover:bg-rose-50 dark:hover:bg-rose-950/60 border border-slate-200 dark:border-slate-700 text-rose-600 flex items-center justify-center text-xs font-bold cursor-pointer"
                        title="Vorlesen beenden">
                    ⏹
                </button>
            </div>

            <div class="flex-1 min-w-[180px]">
                <div class="flex items-center justify-between text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                    <span id="epubLiveStatusText" class="truncate">🎧 Hörbuch-Wiedergabe aktiv…</span>
                    <span id="epubLiveBlockCounter" class="text-amber-700 dark:text-amber-400 font-bold shrink-0 ml-2">0 / 0</span>
                </div>
                <div class="w-full h-1.5 rounded-full bg-amber-200/70 dark:bg-slate-700 overflow-hidden mt-1">
                    <div id="epubLiveBlockProgressBar" class="h-full bg-amber-600 transition-all duration-300" style="width: 0%;"></div>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span id="epubSleepTimerBadge" class="hidden px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300"></span>
                <button type="button" onclick="toggleEpubDrawer('epubAudioDrawer')"
                        class="px-2.5 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-[11px] font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                    ⚙️ Studio
                </button>
            </div>
        </div>

        <!-- AUSKLAPPBARE WERKZEUG-PANELS (Hörbuch / Kapitel / Suche / Lesezeichen / Ansicht) -->
        <!-- 0. HÖRBUCH-PLAYER (VARIANTE 1) & HÖRBUCH-DATEI-GENERATOR (VARIANTE 2) -->
        <div id="epubAudioDrawer" class="<?= $openAudioStudio ? '' : 'hidden' ?> mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-slate-800 p-4 sm:p-5 shadow-lg space-y-4 text-xs">
            <div class="flex items-center justify-between pb-2.5 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="text-lg">🎧</span>
                    <div>
                        <h2 class="text-xs sm:text-sm font-extrabold text-slate-900 dark:text-white">
                            Hörbuch-Player &amp; Hörbuch-Generator (MP3 / KI-WAV)
                        </h2>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            Lass dir das Buch live vorlesen (Variante 1) oder wandle Kapitel in echte Hörbuch-Dateien für deine Cloud um (Variante 2).
                        </p>
                    </div>
                </div>
                <button type="button" onclick="toggleEpubDrawer('epubAudioDrawer')" class="text-xs font-bold text-slate-400 hover:text-slate-700 cursor-pointer">✕</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- VARIANTE 1: LIVE-HÖRBUCH-PLAYER IM READER -->
                <div class="p-4 rounded-2xl bg-amber-50/50 dark:bg-slate-800/60 border border-amber-200/80 dark:border-slate-700 space-y-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-amber-500 text-white">
                            ▶️ Variante 1: Live-Vorleser
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">Sofort ohne Wartezeit anhören</span>
                    </div>

                    <!-- Haupt-Steuerbuttons -->
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="epubTtsTogglePlay()" id="epubDrawerPlayBtn"
                                class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs sm:text-sm font-extrabold shadow-xs transition-colors cursor-pointer">
                            <span id="epubDrawerPlayIcon">▶️</span>
                            <span id="epubDrawerPlayLabel">Kapitel jetzt vorlesen</span>
                        </button>
                        <button type="button" onclick="epubTtsPrevBlock()"
                                class="px-3 py-2.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 border border-slate-200 dark:border-slate-700 font-bold cursor-pointer"
                                title="Vorheriger Absatz">
                            ⏮
                        </button>
                        <button type="button" onclick="epubTtsNextBlock()"
                                class="px-3 py-2.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 border border-slate-200 dark:border-slate-700 font-bold cursor-pointer"
                                title="Nächster Absatz">
                            ⏭
                        </button>
                        <button type="button" onclick="epubTtsStop()"
                                class="px-3 py-2.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-rose-50 border border-slate-200 dark:border-slate-700 text-rose-600 font-bold cursor-pointer"
                                title="Stopp">
                            ⏹
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <!-- Stimmen-Quelle -->
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Vorlese-Stimme</label>
                            <select id="epubLiveEngineSelect" onchange="onEpubLiveEngineChange()"
                                    class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                                <option value="auto" selected>🎧 Automatisch (Sofort-Start + Cloud-Fallback)</option>
                                <option value="cloud_mp3">🎙️ Natürliche Cloud-Stimme (MP3-Stream)</option>
                                <option value="webspeech">🗣️ Geräte-/Browser-Stimme (Offline)</option>
                            </select>
                        </div>
                        <audio id="epubLiveTtsAudioElement" class="hidden" preload="auto"></audio>

                        <!-- Lesetempo -->
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Lesegeschwindigkeit</label>
                            <select id="epubLiveRateSelect" onchange="onEpubLiveRateChange()"
                                    class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                                <option value="0.8">0.8x (Entspannt)</option>
                                <option value="0.9">0.9x (Ruhig)</option>
                                <option value="1" selected>1.0x (Normal)</option>
                                <option value="1.15">1.15x (Flüssig)</option>
                                <option value="1.25">1.25x (Schnell)</option>
                                <option value="1.5">1.5x (Sehr schnell)</option>
                                <option value="1.75">1.75x (Express)</option>
                                <option value="2">2.0x (Doppeltes Tempo)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Optionale Browser-Stimmenauswahl (wenn Geräte-Stimme gewählt) -->
                    <div id="epubWebSpeechVoiceRow" class="hidden">
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Installierte Systemstimme</label>
                        <select id="epubWebSpeechVoiceSelect"
                                class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                        </select>
                    </div>

                    <!-- Schlaf-Timer & Komfort-Optionen -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-1">
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">🌙 Schlaf-Timer</label>
                            <select id="epubSleepTimerSelect" onchange="onEpubSleepTimerChange()"
                                    class="w-full px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                                <option value="0">Aus (Durchgehend lesen)</option>
                                <option value="chapter_end">Am Kapitelende stoppen</option>
                                <option value="15">Nach 15 Minuten stoppen</option>
                                <option value="30">Nach 30 Minuten stoppen</option>
                                <option value="45">Nach 45 Minuten stoppen</option>
                                <option value="60">Nach 60 Minuten stoppen</option>
                            </select>
                        </div>
                        <div class="flex flex-col justify-end gap-1.5">
                            <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="epubAutoNextChapterChk" checked class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                <span class="font-semibold text-slate-700 dark:text-slate-300">Am Ende automatisch nächstes Kapitel</span>
                            </label>
                            <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" id="epubAutoScrollTtsChk" checked class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                <span class="font-semibold text-slate-700 dark:text-slate-300">Automatisch mitscrollen &amp; markieren</span>
                            </label>
                        </div>
                    </div>

                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        💡 <strong>Tipp:</strong> Während der Vorleser läuft, kannst du im Buchtext auf jeden beliebigen Absatz klicken, um genau ab dieser Stelle weiterzuhören.
                    </p>
                </div>

                <!-- VARIANTE 2: HÖRBUCH-DATEI-GENERATOR & CLOUD-EXPORT (.MP3 / .WAV) -->
                <div class="p-4 rounded-2xl bg-indigo-50/50 dark:bg-slate-800/60 border border-indigo-200/80 dark:border-slate-700 space-y-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-indigo-600 text-white">
                            💾 Variante 2: Hörbuch-Datei erzeugen
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">Echte .mp3 / .wav Dateien</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <!-- Umfang -->
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Umfang der Umwandlung</label>
                            <select id="epubExportScopeSelect"
                                    class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                                <option value="current">📄 Nur aktuelles Kapitel (Kapitel <?= $currentChIdx + 1 ?>)</option>
                                <option value="all">📚 Komplettes Buch (Alle <?= $totalChapters ?> Kapitel)</option>
                            </select>
                        </div>

                        <!-- Synthese-Engine -->
                        <div>
                            <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Sprach-Generator (Engine)</label>
                            <select id="epubExportEngineSelect" onchange="onEpubExportEngineChange()"
                                    class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white font-semibold">
                                <option value="server_mp3">⚡ Express-MP3-Synthese (.mp3 Datei)</option>
                                <option value="piper_wasm">🤖 Lokale Piper KI-Stimme im Browser (.wav Datei)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Auswahl der lokalen Piper-KI-Stimme (falls piper_wasm aktiv) -->
                    <div id="epubPiperVoiceRow" class="hidden">
                        <label class="block font-bold text-slate-700 dark:text-slate-300 mb-1">Neuronales KI-Sprachmodell (WebAssembly im Browser)</label>
                        <select id="epubPiperVoiceSelect"
                                class="w-full px-2.5 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white">
                            <option value="de_DE-thorsten-medium" <?= $bookLangCode === 'de' ? 'selected' : '' ?>>🇩🇪 Deutsch – Thorsten (Männlich, natürlich – Medium)</option>
                            <option value="de_DE-kerstin-low">🇩🇪 Deutsch – Kerstin (Weiblich, kompakt &amp; schnell)</option>
                            <option value="en_US-hfc_female-medium" <?= $bookLangCode === 'en' ? 'selected' : '' ?>>🇺🇸 Englisch – HFC Female (Weiblich, natürlich)</option>
                            <option value="en_US-hfc_male-medium">🇺🇸 Englisch – HFC Male (Männlich, natürlich)</option>
                        </select>
                    </div>

                    <!-- Aktions-Buttons: In MiniCloud speichern ODER Direkt herunterladen -->
                    <div class="flex flex-wrap items-center gap-2 pt-0.5">
                        <button type="button" id="epubGenSaveCloudBtn" onclick="startAudiobookGeneration(true)"
                                class="flex-1 inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold shadow-xs transition-colors cursor-pointer">
                            <span>☁️</span>
                            <span>In MiniCloud speichern (Hörbücher/…)</span>
                        </button>
                        <button type="button" id="epubGenDownloadBtn" onclick="startAudiobookGeneration(false)"
                                class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 border border-slate-300 dark:border-slate-700 font-bold transition-colors cursor-pointer">
                            <span>⬇️</span>
                            <span>Direkt herunterladen</span>
                        </button>
                        <button type="button" id="epubGenCancelBtn" onclick="cancelAudiobookGeneration()"
                                class="hidden px-3 py-2.5 rounded-xl bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 font-bold cursor-pointer">
                            Abbrechen
                        </button>
                    </div>

                    <!-- Fortschritt & Ergebnisse der Hörbuch-Generierung -->
                    <div id="epubGenProgressBox" class="hidden p-3 rounded-xl bg-white dark:bg-slate-900 border border-indigo-200 dark:border-slate-700 space-y-2">
                        <div class="flex items-center justify-between text-[11px] font-bold">
                            <span id="epubGenStatusLabel" class="text-indigo-700 dark:text-indigo-300 truncate">Bereite Hörbuch-Synthese vor…</span>
                            <span id="epubGenPercentLabel" class="text-slate-600 dark:text-slate-400 shrink-0 ml-2">0 %</span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                            <div id="epubGenProgressBar" class="h-full bg-indigo-600 transition-all duration-300" style="width: 0%;"></div>
                        </div>
                    </div>

                    <!-- Vorschau-Player & Links zu gespeicherten Hörbuch-Dateien -->
                    <div id="epubGenResultBox" class="hidden p-3 rounded-xl bg-emerald-50/80 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/80 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span id="epubGenResultTitle" class="font-bold text-emerald-800 dark:text-emerald-300 truncate">✅ Hörbuch-Datei bereit!</span>
                            <a id="epubGenFolderLink" href="index.php" class="hidden px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold shrink-0">
                                📂 Im Datei-Manager öffnen
                            </a>
                        </div>
                        <audio id="epubGenPreviewAudio" controls class="w-full h-9"></audio>
                        <div id="epubGenFilesList" class="max-h-36 overflow-y-auto space-y-1 text-[11px]"></div>
                    </div>
                </div>
            </div>
        </div>

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
    const EPUB_BOOK_TITLE     = <?= json_encode($epubInfo['title']) ?>;
    const EPUB_BOOK_AUTHOR    = <?= json_encode($epubInfo['author']) ?>;
    const EPUB_BOOK_LANG      = <?= json_encode($bookLangCode) ?>;
    let   EPUB_CHAPTER_INDEX  = <?= (int)$currentChIdx ?>;
    let   EPUB_CHAPTER_TITLE  = <?= json_encode($chapterTitle) ?>;
    const EPUB_TOTAL_CHAPTERS = <?= (int)$totalChapters ?>;
    const EPUB_INITIAL_SCROLL = <?= (float)$initialScrollPct ?>;
    const EPUB_AUTOPLAY       = <?= $autoPlayAudio ? 'true' : 'false' ?>;
    const EPUB_CSRF           = <?= json_encode($csrfToken) ?>;

    let epubPrefs = <?= json_encode($savedPrefs) ?>;
    let saveProgressTimer = null;

    function toggleEpubDrawer(id) {
        const ids = ['epubAudioDrawer', 'epubTocDrawer', 'epubSearchDrawer', 'epubBookmarkDrawer', 'epubPrefsDrawer'];
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

    // =========================================================================
    // VARIANTE 1: LIVE-HÖRBUCH-PLAYER IM E-BOOK-READER (CLOUD-MP3 & WEB SPEECH)
    // =========================================================================
    let epubTtsBlocks = [];
    let epubTtsCurrentIdx = 0;
    let epubTtsCurrentSubIdx = 0;
    let epubTtsIsPlaying = false;
    let epubTtsIsPaused = false;
    let epubTtsAudioEl = null;
    let epubTtsPreloadAudioEl = null;
    let epubTtsPreloadUrl = '';
    let epubSleepTimerId = null;
    let epubSleepEndTime = 0;
    let epubWebSpeechVoices = [];

    /**
     * Zerlegt einen längeren Absatz in satzweise Einzel-Segmente (max. 175 Zeichen),
     * damit jeder Audio-Stream-Abruf (tts_chunk) blitzschnell als einzelnes MP3 geladen wird
     * und auch lange Absätze vollständig ohne Abschneiden vorgelesen werden.
     */
    function splitTextIntoClientSentences(rawText, maxChars = 175) {
        const clean = (rawText || '').replace(/\s+/g, ' ').trim();
        if (!clean) return [];
        if (clean.length <= maxChars) return [clean];

        const rawParts = clean.split(/(?<=[.!?…;:])\s+/u);
        const chunks = [];
        let current = '';

        for (const part of rawParts) {
            const p = part.trim();
            if (!p) continue;
            const candidate = current ? (current + ' ' + p) : p;
            if (candidate.length <= maxChars) {
                current = candidate;
                continue;
            }
            if (current) {
                chunks.push(current);
                current = '';
            }
            if (p.length <= maxChars) {
                current = p;
                continue;
            }
            const words = p.split(/\s+/u);
            for (const w of words) {
                if (!w) continue;
                const wCand = current ? (current + ' ' + w) : w;
                if (wCand.length <= maxChars) {
                    current = wCand;
                } else {
                    if (current) chunks.push(current);
                    current = w.slice(0, maxChars);
                }
            }
        }
        if (current) chunks.push(current);
        return chunks.length > 0 ? chunks : [clean.slice(0, maxChars)];
    }

    function initEpubTtsBlocks() {
        const container = document.getElementById('epubChapterContent');
        if (!container) return;

        let candidates = Array.from(container.querySelectorAll('h1, h2, h3, h4, h5, h6, p, li, blockquote, div'));
        if (candidates.length === 0) {
            candidates = [container];
        }

        // Nur Blatt-Blockelemente mit vorlesbarem Text behalten (keine verschachtelten Duplikate)
        epubTtsBlocks = [];
        candidates.forEach((el) => {
            const hasChildCandidate = candidates.some((other) => {
                if (other === el || !el.contains(other)) return false;
                const otherTxt = (other.innerText || other.textContent || '').replace(/\s+/g, ' ').trim();
                return otherTxt.length >= 2;
            });
            if (hasChildCandidate) return;
            const txt = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
            if (txt.length >= 2) {
                el.classList.add('epub-tts-block');
                const blockIndex = epubTtsBlocks.length;
                el.dataset.ttsBlockIdx = String(blockIndex);
                if (!el.dataset.ttsClickBound) {
                    el.dataset.ttsClickBound = '1';
                    el.addEventListener('click', (ev) => {
                        if (ev.target.closest('a')) return;
                        if (epubTtsIsPlaying || epubTtsIsPaused) {
                            epubTtsPlayBlock(blockIndex);
                        }
                    });
                }
                epubTtsBlocks.push({
                    el,
                    text: txt,
                    sentences: splitTextIntoClientSentences(txt, 175),
                });
            }
        });

        // Falls das aktuelle Kapitel nur ein Buchcover / Bild ohne Text ist (z. B. Kapitel 1 = Cover):
        // Buchtitel + Kapiteltitel vorlesen, damit danach automatisch ins erste Textkapitel geblättert wird!
        if (epubTtsBlocks.length === 0) {
            const authorPart = EPUB_BOOK_AUTHOR ? ` von ${EPUB_BOOK_AUTHOR}` : '';
            const fallbackText = `${EPUB_BOOK_TITLE}${authorPart}. ${EPUB_CHAPTER_TITLE}.`;
            epubTtsBlocks.push({
                el: container,
                text: fallbackText,
                sentences: splitTextIntoClientSentences(fallbackText, 175),
            });
        }

        updateEpubLiveUiState();
    }

    function initWebSpeechVoices() {
        if (!('speechSynthesis' in window)) return;
        const populate = () => {
            epubWebSpeechVoices = window.speechSynthesis.getVoices() || [];
            const sel = document.getElementById('epubWebSpeechVoiceSelect');
            if (!sel) return;
            if (epubWebSpeechVoices.length === 0) {
                sel.innerHTML = '<option value="">System-Standardstimme verwenden</option>';
                return;
            }
            const sorted = [...epubWebSpeechVoices].sort((a, b) => {
                const aMatch = a.lang.toLowerCase().startsWith(EPUB_BOOK_LANG) ? 0 : 1;
                const bMatch = b.lang.toLowerCase().startsWith(EPUB_BOOK_LANG) ? 0 : 1;
                if (aMatch !== bMatch) return aMatch - bMatch;
                return a.name.localeCompare(b.name);
            });
            sel.innerHTML = '<option value="">System-Standardstimme (' + EPUB_BOOK_LANG.toUpperCase() + ')</option>' +
                sorted.map((v) =>
                    `<option value="${v.name.replace(/"/g, '&quot;')}">${v.name} (${v.lang})</option>`
                ).join('');
        };
        populate();
        if (window.speechSynthesis.onvoiceschanged !== undefined) {
            window.speechSynthesis.onvoiceschanged = populate;
        }
    }

    function onEpubLiveEngineChange() {
        const eng = document.getElementById('epubLiveEngineSelect')?.value || 'auto';
        const voiceRow = document.getElementById('epubWebSpeechVoiceRow');
        if (voiceRow) {
            voiceRow.classList.toggle('hidden', eng === 'cloud_mp3');
        }
        if (epubTtsIsPlaying) {
            epubTtsPlayBlock(epubTtsCurrentIdx);
        }
    }

    function onEpubLiveRateChange() {
        const rate = parseFloat(document.getElementById('epubLiveRateSelect')?.value || '1') || 1.0;
        if (epubTtsAudioEl) {
            epubTtsAudioEl.playbackRate = rate;
        }
        if (epubTtsIsPlaying && (document.getElementById('epubLiveEngineSelect')?.value === 'webspeech')) {
            epubTtsPlayBlock(epubTtsCurrentIdx);
        }
    }

    function onEpubSleepTimerChange() {
        const val = document.getElementById('epubSleepTimerSelect')?.value || '0';
        const badge = document.getElementById('epubSleepTimerBadge');
        if (epubSleepTimerId) {
            clearInterval(epubSleepTimerId);
            epubSleepTimerId = null;
        }
        epubSleepEndTime = 0;

        if (val === '0') {
            if (badge) badge.classList.add('hidden');
            return;
        }
        if (val === 'chapter_end') {
            if (badge) {
                badge.textContent = '🌙 Stopp am Kapitelende';
                badge.classList.remove('hidden');
            }
            return;
        }

        const mins = parseInt(val, 10) || 0;
        if (mins > 0) {
            epubSleepEndTime = Date.now() + mins * 60 * 1000;
            const updateBadge = () => {
                const remSec = Math.max(0, Math.round((epubSleepEndTime - Date.now()) / 1000));
                if (remSec <= 0) {
                    clearInterval(epubSleepTimerId);
                    epubSleepTimerId = null;
                    if (badge) badge.classList.add('hidden');
                    epubTtsStop();
                    return;
                }
                const m = Math.floor(remSec / 60);
                const s = remSec % 60;
                if (badge) {
                    badge.textContent = `🌙 ${m}:${s < 10 ? '0' : ''}${s}`;
                    badge.classList.remove('hidden');
                }
            };
            updateBadge();
            epubSleepTimerId = setInterval(updateBadge, 1000);
        }
    }

    function highlightEpubTtsBlock(idx) {
        epubTtsBlocks.forEach((b, i) => {
            if (i === idx && (epubTtsIsPlaying || epubTtsIsPaused)) {
                b.el.classList.add('epub-tts-active-block');
            } else {
                b.el.classList.remove('epub-tts-active-block');
            }
        });

        const content = document.getElementById('epubChapterContent');
        if (content) {
            content.classList.toggle('epub-tts-clickable', epubTtsIsPlaying || epubTtsIsPaused);
        }

        const autoScroll = document.getElementById('epubAutoScrollTtsChk')?.checked ?? true;
        if (autoScroll && epubTtsBlocks[idx] && (epubTtsIsPlaying || epubTtsIsPaused)) {
            epubTtsBlocks[idx].el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function updateEpubLiveUiState() {
        const bar = document.getElementById('epubLivePlayerBar');
        const dot = document.getElementById('epubAudioPlayingDot');
        const miniIcon = document.getElementById('epubMiniPlayIcon');
        const miniLbl  = document.getElementById('epubMiniPlayLabel');
        const drwIcon  = document.getElementById('epubDrawerPlayIcon');
        const drwLbl   = document.getElementById('epubDrawerPlayLabel');
        const statusTxt = document.getElementById('epubLiveStatusText');
        const counter   = document.getElementById('epubLiveBlockCounter');
        const progBar   = document.getElementById('epubLiveBlockProgressBar');

        const active = epubTtsIsPlaying || epubTtsIsPaused;
        if (bar) bar.classList.toggle('hidden', !active);
        if (dot) dot.classList.toggle('hidden', !epubTtsIsPlaying);

        if (epubTtsIsPlaying) {
            if (miniIcon) miniIcon.textContent = '⏸';
            if (miniLbl)  miniLbl.textContent  = 'Pause';
            if (drwIcon)  drwIcon.textContent  = '⏸️';
            if (drwLbl)   drwLbl.textContent   = 'Vorlesen pausieren';
        } else if (epubTtsIsPaused) {
            if (miniIcon) miniIcon.textContent = '▶️';
            if (miniLbl)  miniLbl.textContent  = 'Weiter';
            if (drwIcon)  drwIcon.textContent  = '▶️';
            if (drwLbl)   drwLbl.textContent   = 'Weiter vorlesen';
        } else {
            if (drwIcon)  drwIcon.textContent  = '▶️';
            if (drwLbl)   drwLbl.textContent   = 'Kapitel jetzt vorlesen';
        }

        const total = epubTtsBlocks.length;
        const curr  = total > 0 ? Math.min(total, epubTtsCurrentIdx + 1) : 0;
        if (counter) counter.textContent = `${curr} / ${total}`;
        if (progBar) progBar.style.width = total > 0 ? `${Math.round((curr / total) * 100)}%` : '0%';

        if (statusTxt && epubTtsBlocks[epubTtsCurrentIdx]) {
            const snippet = epubTtsBlocks[epubTtsCurrentIdx].text;
            statusTxt.textContent = (epubTtsIsPaused ? '⏸ Pausiert: ' : '🔊 ') + snippet;
        }
    }

    function updateMediaSessionMetadata() {
        if (!('mediaSession' in navigator)) return;
        try {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: EPUB_CHAPTER_TITLE,
                artist: EPUB_BOOK_AUTHOR || 'MiniCloud Hörbuch',
                album: EPUB_BOOK_TITLE,
            });
            navigator.mediaSession.setActionHandler('play', () => epubTtsTogglePlay());
            navigator.mediaSession.setActionHandler('pause', () => epubTtsTogglePlay());
            navigator.mediaSession.setActionHandler('previoustrack', () => epubTtsPrevBlock());
            navigator.mediaSession.setActionHandler('nexttrack', () => epubTtsNextBlock());
            navigator.mediaSession.setActionHandler('stop', () => epubTtsStop());
        } catch (e) {}
    }

    function buildChunkTtsUrl(text) {
        return `ebooks.php?action=tts_chunk&lang=${encodeURIComponent(EPUB_BOOK_LANG)}&text=${encodeURIComponent(text.slice(0, 220))}`;
    }

    function preloadNextEpubTtsSentence(blockIdx, subIdx) {
        const block = epubTtsBlocks[blockIdx];
        if (!block) return;
        let nextSentence = '';
        if (Array.isArray(block.sentences) && subIdx < block.sentences.length) {
            nextSentence = block.sentences[subIdx];
        } else if (epubTtsBlocks[blockIdx + 1]) {
            const nb = epubTtsBlocks[blockIdx + 1];
            nextSentence = (nb.sentences && nb.sentences[0]) ? nb.sentences[0] : nb.text;
        }
        if (!nextSentence) return;
        const nextUrl = buildChunkTtsUrl(nextSentence);
        if (epubTtsPreloadUrl === nextUrl) return;
        epubTtsPreloadUrl = nextUrl;
        epubTtsPreloadAudioEl = new Audio();
        epubTtsPreloadAudioEl.preload = 'auto';
        epubTtsPreloadAudioEl.src = nextUrl;
        epubTtsPreloadAudioEl.load();
    }

    let epubSpeechWatchdog = null;

    function stopCurrentTtsOutputs() {
        if (epubSpeechWatchdog) {
            clearTimeout(epubSpeechWatchdog);
            epubSpeechWatchdog = null;
        }
        if (epubTtsAudioEl) {
            epubTtsAudioEl.onended = null;
            epubTtsAudioEl.onerror = null;
            try { epubTtsAudioEl.pause(); } catch (e) {}
        }
        if ('speechSynthesis' in window) {
            try {
                if (window.speechSynthesis.speaking || window.speechSynthesis.pending || window.speechSynthesis.paused) {
                    window.speechSynthesis.cancel();
                }
            } catch (e) {}
        }
    }

    async function onEpubChapterTtsFinished() {
        const sleepVal = document.getElementById('epubSleepTimerSelect')?.value || '0';
        const autoNext = document.getElementById('epubAutoNextChapterChk')?.checked ?? true;
        const nextCh   = EPUB_CHAPTER_INDEX + 1;
        if (sleepVal === 'chapter_end' || !autoNext || nextCh >= EPUB_TOTAL_CHAPTERS) {
            epubTtsStop();
            return;
        }

        // Nahtloser Kapitelwechsel im Hintergrund per fetch(), damit das <audio>-Element seine Browser-Freigabe behält!
        const nextUrl = `ebooks.php?id=${EPUB_FILE_ID}&ch=${nextCh}`;
        try {
            const statusTxt = document.getElementById('epubLiveStatusText');
            if (statusTxt) statusTxt.textContent = `📖 Lade Kapitel ${nextCh + 1} von ${EPUB_TOTAL_CHAPTERS}…`;

            const resp = await fetch(nextUrl, { credentials: 'same-origin' });
            if (resp.ok) {
                const html = await resp.text();
                const doc  = new DOMParser().parseFromString(html, 'text/html');
                const newContent = doc.getElementById('epubChapterContent');
                const curContent = document.getElementById('epubChapterContent');
                if (newContent && curContent) {
                    curContent.innerHTML = newContent.innerHTML;
                    EPUB_CHAPTER_INDEX = nextCh;

                    const newSurfaceHeader = doc.querySelector('#epubPageSurface .pb-4.mb-6');
                    const curSurfaceHeader = document.querySelector('#epubPageSurface .pb-4.mb-6');
                    if (newSurfaceHeader && curSurfaceHeader) {
                        curSurfaceHeader.innerHTML = newSurfaceHeader.innerHTML;
                        const titleSpan = newSurfaceHeader.querySelector('span');
                        if (titleSpan) EPUB_CHAPTER_TITLE = titleSpan.textContent.trim();
                    }

                    try { window.history.replaceState(null, '', nextUrl); } catch (e) {}
                    initEpubTtsBlocks();
                    epubTtsPlayBlock(0);
                    return;
                }
            }
        } catch (e) {}

        // Fallback bei Netzwerkfehler
        window.location.href = `${nextUrl}&autoplay=1`;
    }

    function playBlockViaCloudMp3(idx, text, rate, allowWebSpeechFallback, subIdx = 0) {
        const block = epubTtsBlocks[idx];
        const sentences = (block && Array.isArray(block.sentences) && block.sentences.length > 0)
            ? block.sentences
            : splitTextIntoClientSentences(text, 175);

        if (subIdx >= sentences.length) {
            if (epubTtsIsPlaying) {
                epubTtsPlayBlock(idx + 1);
            }
            return;
        }

        epubTtsCurrentSubIdx = subIdx;
        const sentenceText = sentences[subIdx];
        const domAudio = document.getElementById('epubLiveTtsAudioElement');
        epubTtsAudioEl = domAudio || new Audio();
        epubTtsAudioEl.src = buildChunkTtsUrl(sentenceText);
        epubTtsAudioEl.playbackRate = rate;

        epubTtsAudioEl.onended = () => {
            if (!epubTtsIsPlaying) return;
            if (subIdx + 1 < sentences.length) {
                playBlockViaCloudMp3(idx, text, rate, allowWebSpeechFallback, subIdx + 1);
            } else {
                epubTtsPlayBlock(idx + 1);
            }
        };
        epubTtsAudioEl.onerror = () => {
            if (allowWebSpeechFallback && ('speechSynthesis' in window)) {
                playBlockViaWebSpeech(idx, text, rate, false);
            } else {
                const statusTxt = document.getElementById('epubLiveStatusText');
                if (statusTxt) {
                    statusTxt.textContent = '⚠️ Keine Sprachausgabe auf diesem Gerät verfügbar.';
                }
            }
        };

        const playPromise = epubTtsAudioEl.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                if (allowWebSpeechFallback && ('speechSynthesis' in window)) {
                    playBlockViaWebSpeech(idx, text, rate, false);
                }
            });
        }
        preloadNextEpubTtsSentence(idx, subIdx + 1);
    }

    function playBlockViaWebSpeech(idx, text, rate, allowCloudFallback) {
        if (!('speechSynthesis' in window)) {
            if (allowCloudFallback) {
                playBlockViaCloudMp3(idx, text, rate, false);
            }
            return;
        }

        const startUtterance = () => {
            const utter = new SpeechSynthesisUtterance(text);
            // Globale Referenz halten, damit der Garbage Collector in Chrome/Safari das Event nicht abbricht
            window._activeEpubUtterance = utter;

            const fullLang = EPUB_BOOK_LANG === 'de' ? 'de-DE' : (EPUB_BOOK_LANG === 'en' ? 'en-US' : EPUB_BOOK_LANG);
            utter.lang = fullLang;
            utter.rate = rate;

            const voices = window.speechSynthesis.getVoices() || epubWebSpeechVoices || [];
            const wantedVoice = document.getElementById('epubWebSpeechVoiceSelect')?.value || '';
            if (wantedVoice && voices.length > 0) {
                const found = voices.find((v) => v.name === wantedVoice);
                if (found) utter.voice = found;
            } else if (voices.length > 0) {
                const bestLangVoice = voices.find((v) => v.lang && v.lang.toLowerCase().startsWith(EPUB_BOOK_LANG));
                if (bestLangVoice) utter.voice = bestLangVoice;
            }

            let startedSpeaking = false;
            utter.onstart = () => {
                startedSpeaking = true;
                if (epubSpeechWatchdog) {
                    clearTimeout(epubSpeechWatchdog);
                    epubSpeechWatchdog = null;
                }
            };
            utter.onend = () => {
                if (epubSpeechWatchdog) {
                    clearTimeout(epubSpeechWatchdog);
                    epubSpeechWatchdog = null;
                }
                if (epubTtsIsPlaying) {
                    epubTtsPlayBlock(idx + 1);
                }
            };
            utter.onerror = (ev) => {
                if (epubSpeechWatchdog) {
                    clearTimeout(epubSpeechWatchdog);
                    epubSpeechWatchdog = null;
                }
                if (ev && (ev.error === 'interrupted' || ev.error === 'canceled')) {
                    return;
                }
                if (allowCloudFallback && epubTtsIsPlaying) {
                    playBlockViaCloudMp3(idx, text, rate, false);
                }
            };

            try {
                window.speechSynthesis.resume();
                window.speechSynthesis.speak(utter);
            } catch (e) {
                if (allowCloudFallback) {
                    playBlockViaCloudMp3(idx, text, rate, false);
                }
                return;
            }

            // Watchdog: Falls das Betriebssystem/der Browser trotz Web-Speech-API nach 1,8 Sek. keinen Ton startet
            // (z. B. Linux ohne speech-dispatcher), automatisch auf den Cloud-MP3-Stream umschalten!
            if (allowCloudFallback) {
                epubSpeechWatchdog = setTimeout(() => {
                    if (epubTtsIsPlaying && !startedSpeaking && !window.speechSynthesis.speaking) {
                        try { window.speechSynthesis.cancel(); } catch (e) {}
                        playBlockViaCloudMp3(idx, text, rate, false);
                    }
                }, 1800);
            }
        };

        if (window.speechSynthesis.speaking || window.speechSynthesis.pending) {
            try { window.speechSynthesis.cancel(); } catch (e) {}
            setTimeout(startUtterance, 40);
        } else {
            startUtterance();
        }
    }

    function epubTtsPlayBlock(idx) {
        if (epubTtsBlocks.length === 0) {
            initEpubTtsBlocks();
        }
        if (epubTtsBlocks.length === 0) return;

        if (idx < 0) idx = 0;
        if (idx >= epubTtsBlocks.length) {
            onEpubChapterTtsFinished();
            return;
        }

        stopCurrentTtsOutputs();
        epubTtsCurrentIdx = idx;
        epubTtsIsPlaying  = true;
        epubTtsIsPaused   = false;

        highlightEpubTtsBlock(idx);
        updateEpubLiveUiState();
        updateMediaSessionMetadata();
        if (saveProgressTimer) clearTimeout(saveProgressTimer);
        saveProgressTimer = setTimeout(saveEpubProgressNow, 2000);

        const engine = document.getElementById('epubLiveEngineSelect')?.value || 'auto';
        const rate   = parseFloat(document.getElementById('epubLiveRateSelect')?.value || '1') || 1.0;
        const text   = epubTtsBlocks[idx].text;

        if (engine === 'cloud_mp3') {
            playBlockViaCloudMp3(idx, text, rate, true, 0);
        } else if (engine === 'webspeech') {
            playBlockViaWebSpeech(idx, text, rate, false);
        } else {
            // 'auto': Zuerst Cloud-MP3 versuchen (natürliche Stimme auf allen Systemen) und bei Fehler sofort auf Web Speech umschalten
            playBlockViaCloudMp3(idx, text, rate, true, 0);
        }
    }

    function epubTtsTogglePlay() {
        if (epubTtsBlocks.length === 0) {
            initEpubTtsBlocks();
        }
        if (epubTtsIsPlaying) {
            epubTtsIsPlaying = false;
            epubTtsIsPaused  = true;
            if (epubTtsAudioEl) {
                epubTtsAudioEl.pause();
            }
            if ('speechSynthesis' in window) {
                window.speechSynthesis.pause();
            }
            updateEpubLiveUiState();
            return;
        }
        if (epubTtsIsPaused) {
            epubTtsIsPlaying = true;
            epubTtsIsPaused  = false;
            const engine = document.getElementById('epubLiveEngineSelect')?.value || 'cloud_mp3';
            if (engine === 'webspeech' && ('speechSynthesis' in window) && window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
                updateEpubLiveUiState();
                return;
            }
            if (epubTtsAudioEl) {
                epubTtsAudioEl.play().catch(() => epubTtsPlayBlock(epubTtsCurrentIdx));
                updateEpubLiveUiState();
                return;
            }
        }
        epubTtsPlayBlock(epubTtsCurrentIdx);
    }

    function epubTtsPrevBlock() {
        epubTtsPlayBlock(Math.max(0, epubTtsCurrentIdx - 1));
    }

    function epubTtsNextBlock() {
        epubTtsPlayBlock(epubTtsCurrentIdx + 1);
    }

    function epubTtsStop() {
        epubTtsIsPlaying = false;
        epubTtsIsPaused  = false;
        stopCurrentTtsOutputs();
        highlightEpubTtsBlock(-1);
        updateEpubLiveUiState();
    }

    // =========================================================================
    // VARIANTE 2: HÖRBUCH-DATEI-GENERATOR (.MP3 SERVER & .WAV LOKALE KI PIPER)
    // =========================================================================
    let epubGenAbortFlag = false;

    function onEpubExportEngineChange() {
        const eng = document.getElementById('epubExportEngineSelect')?.value || 'server_mp3';
        const piperRow = document.getElementById('epubPiperVoiceRow');
        if (piperRow) {
            piperRow.classList.toggle('hidden', eng !== 'piper_wasm');
        }
    }

    function setEpubGenProgress(statusText, pct) {
        const box = document.getElementById('epubGenProgressBox');
        const lbl = document.getElementById('epubGenStatusLabel');
        const pLbl = document.getElementById('epubGenPercentLabel');
        const bar = document.getElementById('epubGenProgressBar');
        if (box) box.classList.remove('hidden');
        if (lbl) lbl.textContent = statusText;
        const clamped = Math.max(0, Math.min(100, Math.round(pct)));
        if (pLbl) pLbl.textContent = clamped + ' %';
        if (bar) bar.style.width = clamped + '%';
    }

    function cancelAudiobookGeneration() {
        epubGenAbortFlag = true;
        setEpubGenProgress('🛑 Hörbuch-Generierung wird abgebrochen…', 0);
    }

    function triggerBlobDownload(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        return url;
    }

    function base64ToBlob(b64, mimeType) {
        const bin = atob(b64);
        const len = bin.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = bin.charCodeAt(i);
        }
        return new Blob([bytes], { type: mimeType });
    }

    /**
     * Fügt mehrere 16-Bit PCM WAV-Blobs (aus Piper WebAssembly TTS) inklusive kurzer Sprechpausen
     * zu einer einzigen durchgehenden .wav-Audiodatei zusammen.
     */
    async function mergePiperWavBlobs(blobs, pauseMs = 220) {
        if (!blobs || blobs.length === 0) return null;
        const buffers = [];
        let sampleRate = 22050;
        let numChannels = 1;
        let bitsPerSample = 16;

        for (let i = 0; i < blobs.length; i++) {
            const ab = await blobs[i].arrayBuffer();
            if (ab.byteLength <= 44) continue;
            const dv = new DataView(ab);
            if (i === 0) {
                numChannels   = dv.getUint16(22, true) || 1;
                sampleRate    = dv.getUint32(24, true) || 22050;
                bitsPerSample = dv.getUint16(34, true) || 16;
            }
            buffers.push(new Uint8Array(ab, 44));
        }

        const bytesPerSample = (bitsPerSample / 8) * numChannels;
        const pauseBytesLen = Math.floor((sampleRate * (pauseMs / 1000)) * bytesPerSample);
        const alignedPauseLen = pauseBytesLen - (pauseBytesLen % bytesPerSample);
        const silenceChunk = new Uint8Array(alignedPauseLen);

        let totalDataLen = 0;
        buffers.forEach((b, idx) => {
            totalDataLen += b.byteLength;
            if (idx < buffers.length - 1) totalDataLen += silenceChunk.byteLength;
        });

        const outBuffer = new ArrayBuffer(44 + totalDataLen);
        const view = new DataView(outBuffer);
        const writeStr = (offset, str) => {
            for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
        };

        writeStr(0, 'RIFF');
        view.setUint32(4, 36 + totalDataLen, true);
        writeStr(8, 'WAVE');
        writeStr(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true); // PCM
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * bytesPerSample, true);
        view.setUint16(32, bytesPerSample, true);
        view.setUint16(34, bitsPerSample, true);
        writeStr(36, 'data');
        view.setUint32(40, totalDataLen, true);

        const outBytes = new Uint8Array(outBuffer, 44);
        let offset = 0;
        buffers.forEach((b, idx) => {
            outBytes.set(b, offset);
            offset += b.byteLength;
            if (idx < buffers.length - 1 && silenceChunk.byteLength > 0) {
                outBytes.set(silenceChunk, offset);
                offset += silenceChunk.byteLength;
            }
        });

        return new Blob([outBuffer], { type: 'audio/wav' });
    }

    function appendGeneratedFileRow(filename, sizeFormatted, downloadUrl, streamUrl) {
        const resBox = document.getElementById('epubGenResultBox');
        const list   = document.getElementById('epubGenFilesList');
        const audio  = document.getElementById('epubGenPreviewAudio');
        if (resBox) resBox.classList.remove('hidden');
        if (audio && streamUrl) {
            audio.src = streamUrl;
        }
        if (list) {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-2 p-2 rounded-lg bg-white dark:bg-slate-900 border border-emerald-200/70 dark:border-slate-700';
            row.innerHTML = `
                <span class="font-semibold text-slate-800 dark:text-slate-200 truncate">🎵 ${filename} <span class="text-slate-400 font-normal">(${sizeFormatted})</span></span>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" class="px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 font-bold cursor-pointer"
                            onclick="const a=document.getElementById('epubGenPreviewAudio'); if(a){a.src='${streamUrl}'; a.play();}">▶️</button>
                    <a href="${downloadUrl}" download="${filename}" class="px-2 py-0.5 rounded bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold">⬇️</a>
                </div>
            `;
            list.prepend(row);
        }
    }

    async function startAudiobookGeneration(saveToCloud) {
        epubGenAbortFlag = false;
        const scope  = document.getElementById('epubExportScopeSelect')?.value || 'current';
        const engine = document.getElementById('epubExportEngineSelect')?.value || 'server_mp3';

        const btnCloud  = document.getElementById('epubGenSaveCloudBtn');
        const btnDl     = document.getElementById('epubGenDownloadBtn');
        const btnCancel = document.getElementById('epubGenCancelBtn');
        const list      = document.getElementById('epubGenFilesList');

        if (btnCloud)  btnCloud.disabled = true;
        if (btnDl)     btnDl.disabled = true;
        if (btnCancel) btnCancel.classList.remove('hidden');
        if (list)      list.innerHTML = '';

        const chaptersToProcess = [];
        if (scope === 'all') {
            for (let c = 0; c < EPUB_TOTAL_CHAPTERS; c++) chaptersToProcess.push(c);
        } else {
            chaptersToProcess.push(EPUB_CHAPTER_INDEX);
        }

        try {
            if (engine === 'server_mp3') {
                for (let i = 0; i < chaptersToProcess.length; i++) {
                    if (epubGenAbortFlag) break;
                    const chIdx = chaptersToProcess[i];
                    const basePct = (i / chaptersToProcess.length) * 100;
                    setEpubGenProgress(
                        `🎙️ Synthetisiere Kapitel ${chIdx + 1} von ${EPUB_TOTAL_CHAPTERS} als .mp3…`,
                        basePct + (40 / chaptersToProcess.length)
                    );

                    const fd = new FormData();
                    fd.append('action', 'generate_chapter_mp3');
                    fd.append('ajax', '1');
                    fd.append('csrf_token', EPUB_CSRF);
                    fd.append('file_id', String(EPUB_FILE_ID));
                    fd.append('chapter_index', String(chIdx));
                    fd.append('save_to_cloud', saveToCloud ? '1' : '0');
                    fd.append('lang', EPUB_BOOK_LANG);

                    const r = await fetch('ebooks.php', { method: 'POST', body: fd });
                    const d = await r.json();

                    if (!d || !d.ok) {
                        alert(d?.error || `Fehler bei Kapitel ${chIdx + 1}.`);
                        break;
                    }

                    if (d.skipped) {
                        if (scope === 'current' && (chIdx + 1) < EPUB_TOTAL_CHAPTERS && !chaptersToProcess.includes(chIdx + 1)) {
                            chaptersToProcess.push(chIdx + 1);
                        }
                        continue;
                    }

                    if (saveToCloud && d.saved_to_cloud) {
                        const folderLink = document.getElementById('epubGenFolderLink');
                        if (folderLink && d.folder_url) {
                            folderLink.href = d.folder_url;
                            folderLink.classList.remove('hidden');
                        }
                        appendGeneratedFileRow(d.filename, d.file_size_formatted, d.download_url, d.stream_url);
                    } else if (d.audio_base64) {
                        const blob = base64ToBlob(d.audio_base64, d.mime_type || 'audio/mpeg');
                        const objUrl = triggerBlobDownload(blob, d.filename);
                        appendGeneratedFileRow(d.filename, d.file_size_formatted, objUrl, objUrl);
                    }
                }
            } else {
                // Lokale Piper KI-Stimme im Browser (WebAssembly)
                setEpubGenProgress('🤖 Lade lokales Piper KI-Sprachmodul (WebAssembly)…', 3);
                if (!window._piperTtsModule) {
                    window._piperTtsModule = await import('https://cdn.jsdelivr.net/npm/@diffusionstudio/vits-web@1.0.3/+esm');
                }
                const tts = window._piperTtsModule;
                const voiceId = document.getElementById('epubPiperVoiceSelect')?.value || 'de_DE-thorsten-medium';

                for (let i = 0; i < chaptersToProcess.length; i++) {
                    if (epubGenAbortFlag) break;
                    const chIdx = chaptersToProcess[i];

                    const fdText = new FormData();
                    fdText.append('action', 'get_chapter_tts_text');
                    fdText.append('ajax', '1');
                    fdText.append('csrf_token', EPUB_CSRF);
                    fdText.append('file_id', String(EPUB_FILE_ID));
                    fdText.append('chapter_index', String(chIdx));

                    const rText = await fetch('ebooks.php', { method: 'POST', body: fdText });
                    const dText = await rText.json();
                    if (!dText || !dText.ok || !Array.isArray(dText.chunks) || dText.chunks.length === 0) {
                        continue;
                    }

                    const chunks = [`Kapitel ${chIdx + 1}: ${dText.chapter_title}.`, ...dText.chunks];
                    const wavBlobs = [];

                    for (let s = 0; s < chunks.length; s++) {
                        if (epubGenAbortFlag) break;
                        const overallPct = ((i + (s / chunks.length)) / chaptersToProcess.length) * 100;
                        setEpubGenProgress(
                            `🤖 KI-Stimme (${voiceId}): Kapitel ${chIdx + 1}, Satz ${s + 1} / ${chunks.length}…`,
                            overallPct
                        );
                        const wav = await tts.predict({ text: chunks[s], voiceId });
                        if (wav) wavBlobs.push(wav);
                    }

                    if (epubGenAbortFlag || wavBlobs.length === 0) break;

                    const mergedWav = await mergePiperWavBlobs(wavBlobs, 220);
                    if (!mergedWav) continue;

                    const sizeMb = (mergedWav.size / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
                    const safeChap = (dText.chapter_title || `Kapitel ${chIdx + 1}`).replace(/[^\p{L}\p{N}\s\-_()]/gu, '').trim().slice(0, 70);
                    const wavFilename = `Kapitel ${String(chIdx + 1).padStart(2, '0')} - ${safeChap}.wav`;

                    if (saveToCloud) {
                        setEpubGenProgress(`☁️ Speichere ${wavFilename} in deiner MiniCloud…`, 96);
                        const fdUp = new FormData();
                        fdUp.append('action', 'save_browser_audiobook');
                        fdUp.append('ajax', '1');
                        fdUp.append('csrf_token', EPUB_CSRF);
                        fdUp.append('file_id', String(EPUB_FILE_ID));
                        fdUp.append('chapter_index', String(chIdx));
                        fdUp.append('ext', 'wav');
                        fdUp.append('audio_blob', mergedWav, wavFilename);

                        const rUp = await fetch('ebooks.php', { method: 'POST', body: fdUp });
                        const dUp = await rUp.json();
                        if (dUp && dUp.ok) {
                            const folderLink = document.getElementById('epubGenFolderLink');
                            if (folderLink && dUp.folder_url) {
                                folderLink.href = dUp.folder_url;
                                folderLink.classList.remove('hidden');
                            }
                            appendGeneratedFileRow(dUp.filename, dUp.file_size_formatted, dUp.download_url, dUp.stream_url);
                        }
                    } else {
                        const objUrl = triggerBlobDownload(mergedWav, wavFilename);
                        appendGeneratedFileRow(wavFilename, sizeMb, objUrl, objUrl);
                    }
                }
            }

            if (!epubGenAbortFlag) {
                setEpubGenProgress(
                    saveToCloud
                        ? '✅ Hörbuch erfolgreich im Ordner „Hörbücher/…“ deiner MiniCloud gespeichert!'
                        : '✅ Hörbuch-Audiodatei erfolgreich erstellt & heruntergeladen!',
                    100
                );
            }
        } catch (err) {
            alert('Hinweis bei der Hörbuch-Erstellung: ' + (err?.message || err));
        } finally {
            if (btnCloud)  btnCloud.disabled = false;
            if (btnDl)     btnDl.disabled = false;
            if (btnCancel) btnCancel.classList.add('hidden');
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        initEpubTtsBlocks();
        initWebSpeechVoices();

        if (EPUB_INITIAL_SCROLL > 1 && !EPUB_AUTOPLAY) {
            setTimeout(() => {
                const doc = document.documentElement;
                const maxScroll = Math.max(1, doc.scrollHeight - window.innerHeight);
                window.scrollTo({ top: (EPUB_INITIAL_SCROLL / 100) * maxScroll, behavior: 'smooth' });
            }, 150);
        }
        // Beim Öffnen des Kapitels Fortschritt direkt einmal sichern
        saveEpubProgressNow();

        // Falls automatischer Kapitelwechsel im Hörbuch-Modus (&autoplay=1) aktiv ist -> sofort weiterlesen
        if (EPUB_AUTOPLAY) {
            setTimeout(() => {
                epubTtsPlayBlock(0);
            }, 350);
        }
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

// 1. Alle für den Benutzer zugänglichen .epub-Dateien laden (inkl. leichtgewichtiger Text-Metadaten ohne Cover-Blob)
$stmtEpubs = $pdo->prepare(
    "SELECT f.*, u.username AS owner_username, '' AS group_folder_name,
            emc.title AS cached_title,
            emc.author AS cached_author,
            emc.language AS cached_language,
            emc.description AS cached_description,
            emc.chapter_count AS cached_chapter_count,
            CASE
                WHEN f.user_id = :uid1 THEN 'own'
                ELSE 'shared'
            END AS source_kind
     FROM `files` f
     LEFT JOIN `users` u ON u.id = f.user_id
     LEFT JOIN `ebook_metadata_cache` emc ON emc.file_id = f.id
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

// 3. Filter-, Such- und Paginierungsparameter (6 Bücher pro Seite)
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($activeTab, ['all', 'reading', 'unread', 'finished'], true)) {
    $activeTab = 'all';
}
$searchQuery = trim((string)($_GET['q'] ?? ''));
$searchLower = $searchQuery !== '' ? mb_strtolower($searchQuery, 'UTF-8') : '';
$perPage     = 6;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

$spineColors = [
    'from-amber-700 to-orange-900',
    'from-indigo-700 to-slate-900',
    'from-emerald-700 to-teal-950',
    'from-rose-700 to-red-950',
    'from-sky-700 to-blue-950',
    'from-purple-700 to-indigo-950',
];

// 4. Leichtgewichtigen Katalog aufbauen & filtern (ohne ZIP/Cover-Laden für alle Bücher!)
$totalBooksCount = count($rawEpubFiles);
$tabCounts = [
    'all'      => 0,
    'reading'  => 0,
    'unread'   => 0,
    'finished' => 0,
];
$filteredRows   = [];
$lastReadRow    = null;
$lastReadTs     = 0;

foreach ($rawEpubFiles as $fRow) {
    $fid       = (int)$fRow['id'];
    $prog      = $progressByFileId[$fid] ?? null;
    $progPct   = $prog ? (float)$prog['progress_pct'] : 0.0;
    $updatedAt = $prog ? (string)$prog['updated_at'] : null;

    if ($progPct >= 98.0) {
        $statusKey = 'finished';
    } elseif ($prog !== null && $progPct > 0.0) {
        $statusKey = 'reading';
    } else {
        $statusKey = 'unread';
    }

    if ($updatedAt !== null) {
        $ts = strtotime($updatedAt) ?: 0;
        if ($ts > $lastReadTs) {
            $lastReadTs  = $ts;
            $lastReadRow = $fRow;
        }
    }

    // Suchfilter prüfen (über Dateiname + gecachten Titel/Autor/Beschreibung/Ordner)
    if ($searchLower !== '') {
        $haystack = mb_strtolower(
            (string)($fRow['cached_title'] ?? '') . ' ' .
            (string)($fRow['cached_author'] ?? '') . ' ' .
            (string)($fRow['cached_description'] ?? '') . ' ' .
            (string)($fRow['original_name'] ?? '') . ' ' .
            (string)($fRow['folder_path'] ?? ''),
            'UTF-8'
        );
        if (!str_contains($haystack, $searchLower)) {
            continue;
        }
    }

    $tabCounts['all']++;
    $tabCounts[$statusKey]++;

    if ($activeTab === 'all' || $statusKey === $activeTab) {
        $fRow['_status_key'] = $statusKey;
        $filteredRows[]      = $fRow;
    }
}

$filteredCount = count($filteredRows);
$totalPages    = max(1, (int)ceil($filteredCount / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset    = ($currentPage - 1) * $perPage;
$pagedRows = array_slice($filteredRows, $offset, $perPage);

/**
 * Hilfsfunktion: Baut einen vollständigen Buch-Eintrag inkl. Cover & Metadaten (nur für die 6 sichtbaren Bücher!).
 */
$hydrateBookEntry = static function (array $fRow) use ($pdo, $progressByFileId, $spineColors): array {
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

    return [
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
};

$books = [];
foreach ($pagedRows as $pRow) {
    $books[] = $hydrateBookEntry($pRow);
}

$lastReadBook = $lastReadRow !== null ? $hydrateBookEntry($lastReadRow) : null;

$buildShelfUrl = static function (array $overrides = []) use ($activeTab, $searchQuery, $currentPage): string {
    $params = [
        'tab'  => $overrides['tab'] ?? $activeTab,
        'q'    => $overrides['q'] ?? $searchQuery,
        'page' => $overrides['page'] ?? $currentPage,
    ];
    if ($params['tab'] === 'all') {
        unset($params['tab']);
    }
    if ($params['q'] === '') {
        unset($params['q']);
    }
    if ((int)$params['page'] <= 1) {
        unset($params['page']);
    }
    $qs = http_build_query($params);
    return 'ebooks.php' . ($qs !== '' ? '?' . $qs : '');
};

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
                        <?= $totalBooksCount ?> <?= $totalBooksCount === 1 ? 'Buch' : 'Bücher' ?>
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

            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="ebooks.php?id=<?= (int)$lastReadBook['id'] ?>"
                   class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors">
                    📖 Weiterlesen &rarr;
                </a>
                <a href="ebooks.php?id=<?= (int)$lastReadBook['id'] ?>&amp;autoplay=1"
                   class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-amber-50 dark:hover:bg-slate-700 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-slate-600 text-xs sm:text-sm font-bold shadow-2xs transition-colors"
                   title="Als Hörbuch an letzter Stelle weiterhören">
                    🎧 Weiterhören
                </a>
            </div>
        </section>
    <?php endif; ?>

    <!-- FILTER-LEISTE & SUCHE -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5" role="group">
            <?php
            $tabDefs = [
                'all'      => '📚 Alle (' . $tabCounts['all'] . ')',
                'reading'  => '⏳ Am Lesen (' . $tabCounts['reading'] . ')',
                'unread'   => '🆕 Ungelesen (' . $tabCounts['unread'] . ')',
                'finished' => '✅ Gelesen (' . $tabCounts['finished'] . ')',
            ];
            foreach ($tabDefs as $tKey => $tLabel):
                $isTabActive = ($activeTab === $tKey);
            ?>
                <a href="<?= e($buildShelfUrl(['tab' => $tKey, 'page' => 1])) ?>"
                   class="px-3 py-1.5 rounded-xl text-xs transition-colors <?= $isTabActive ? 'font-bold bg-brand-600 text-white shadow-2xs' : 'font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                    <?= e($tLabel) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="ebooks.php" class="flex items-center gap-2 w-full sm:w-auto">
            <?php if ($activeTab !== 'all'): ?>
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <?php endif; ?>
            <div class="relative flex-1 sm:w-72">
                <input type="text" name="q" value="<?= e($searchQuery) ?>"
                       placeholder="Buchtitel, Autor oder Dateiname suchen…"
                       class="w-full pl-3.5 pr-8 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <?php if ($searchQuery !== ''): ?>
                    <a href="<?= e($buildShelfUrl(['q' => '', 'page' => 1])) ?>"
                       title="Suche zurücksetzen"
                       class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xs font-bold">✕</a>
                <?php endif; ?>
            </div>
            <button type="submit"
                    class="px-3.5 py-2 rounded-xl bg-slate-900 dark:bg-slate-700 hover:bg-slate-800 text-white text-xs font-bold shrink-0 cursor-pointer">
                🔎 Suchen
            </button>
        </form>
    </div>

    <!-- BÜCHERREGAL-RASTER (6 Bücher pro Seite) -->
    <?php if ($totalBooksCount === 0): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-3">
            <div class="text-4xl">📚</div>
            <h2 class="text-base font-bold text-slate-900 dark:text-white">Noch keine E-Books (.epub) in deiner Cloud gefunden</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                Lade oben über den Button <strong>„📤 .epub Buch hochladen“</strong> oder im Datei-Manager deine erste <code class="font-mono">.epub</code>-Datei hoch, um sie hier im E-Book-Reader zu lesen.
            </p>
        </div>
    <?php elseif (empty($books)): ?>
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center space-y-2">
            <div class="text-3xl">🔎</div>
            <h2 class="text-sm font-bold text-slate-900 dark:text-white">Keine passenden Bücher gefunden</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Für deine aktuelle Auswahl oder Suche wurden keine E-Books gefunden.
            </p>
            <div class="pt-2">
                <a href="ebooks.php" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-brand-600 text-white text-xs font-bold">
                    Alle Bücher anzeigen
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" id="ebookShelfGrid">
            <?php foreach ($books as $b): ?>
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs hover:shadow-md transition-all flex gap-4">
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

                            <div class="flex items-center justify-between gap-1.5 pt-0.5">
                                <a href="ebooks.php?id=<?= (int)$b['id'] ?>"
                                   class="flex-1 inline-flex items-center justify-center gap-1 px-2.5 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-2xs transition-colors">
                                    📖 <?= $b['progress_pct'] > 0 ? 'Weiterlesen' : 'Lesen' ?>
                                </a>
                                <a href="ebooks.php?id=<?= (int)$b['id'] ?>&amp;autoplay=1"
                                   class="px-2.5 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/80 hover:bg-amber-200 text-amber-800 dark:text-amber-300 text-xs font-bold transition-colors"
                                   title="Als Hörbuch vorlesen lassen (Variante 1)">
                                    🎧
                                </a>
                                <a href="ebooks.php?id=<?= (int)$b['id'] ?>&amp;audiostudio=1"
                                   class="px-2.5 py-1.5 rounded-xl bg-indigo-100 dark:bg-indigo-950/80 hover:bg-indigo-200 text-indigo-700 dark:text-indigo-300 text-xs font-bold transition-colors"
                                   title="In .mp3 / .wav Hörbuch-Datei umwandeln (Variante 2)">
                                    💾
                                </a>
                                <a href="download.php?id=<?= (int)$b['id'] ?>"
                                   class="px-2 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-600 dark:text-slate-300 text-xs font-semibold"
                                   title=".epub herunterladen">
                                    ⬇️
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- BLÄTTER-NAVIGATION (PAGINATION) -->
        <?php if ($totalPages > 1): ?>
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Zeige Bücher <strong class="text-slate-800 dark:text-slate-200"><?= $offset + 1 ?>–<?= min($filteredCount, $offset + count($books)) ?></strong>
                    von <strong class="text-slate-800 dark:text-slate-200"><?= $filteredCount ?></strong>
                    (Seite <?= $currentPage ?> von <?= $totalPages ?>)
                </p>

                <div class="flex flex-wrap items-center gap-1.5">
                    <!-- Zurück -->
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= e($buildShelfUrl(['page' => $currentPage - 1])) ?>"
                           class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 transition-colors">
                            &larr; Zurück
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 rounded-xl bg-slate-100/60 dark:bg-slate-800/40 text-xs font-bold text-slate-400 dark:text-slate-600 cursor-not-allowed">
                            &larr; Zurück
                        </span>
                    <?php endif; ?>

                    <!-- Seitenzahlen (kompakt mit Auslassungspunkten) -->
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage   = min($totalPages, $currentPage + 2);
                    if ($startPage > 1):
                    ?>
                        <a href="<?= e($buildShelfUrl(['page' => 1])) ?>"
                           class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 inline-flex items-center justify-center">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="px-1 text-xs text-slate-400">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                        <a href="<?= e($buildShelfUrl(['page' => $p])) ?>"
                           class="w-8 h-8 rounded-xl text-xs font-bold inline-flex items-center justify-center transition-colors <?= $p === $currentPage ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="px-1 text-xs text-slate-400">…</span>
                        <?php endif; ?>
                        <a href="<?= e($buildShelfUrl(['page' => $totalPages])) ?>"
                           class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 inline-flex items-center justify-center"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <!-- Weiter -->
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= e($buildShelfUrl(['page' => $currentPage + 1])) ?>"
                           class="px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-xs font-bold text-white shadow-2xs transition-colors">
                            Weiter &rarr;
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1.5 rounded-xl bg-slate-100/60 dark:bg-slate-800/40 text-xs font-bold text-slate-400 dark:text-slate-600 cursor-not-allowed">
                            Weiter &rarr;
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
