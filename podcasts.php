<?php
declare(strict_types=1);

/**
 * MiniCloud - Podcast-Studio & Offline-Episoden-Catcher (podcasts.php)
 *
 * Features:
 *  - Globale Podcast-Verzeichnissuche (Deutsch & International) + direkter RSS-Feed-Import
 *  - Schneller RSS/XML-Episoden-Parser mit lokalem Feed-Cache
 *  - 1-Klick Offline-Download von Episoden (.mp3 / .m4a) direkt auf den Cloud-Server (uploads/podcasts/episodes/)
 *  - Komfort-Audio-Player mit Sekundengedächtnis (Weiterschauen/-hören), -15s / +30s Sprungtasten & Tempo-Wahl (0.8x–2.0x)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$appSettings = getAppSettings($pdo);
if (($appSettings['enable_podcasts'] ?? '1') !== '1') {
    setFlash('error', 'Das Podcast-Studio ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

// ----------------------------------------------------------------------------
// 1. DATENBANK-SCHEMA FÜR ABONNIERTE PODCASTS & OFFLINE-EPISODEN
// ----------------------------------------------------------------------------
function ensurePodcastsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `podcast_feeds` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(180) NOT NULL,
            `author` VARCHAR(140) NOT NULL DEFAULT '',
            `feed_url` VARCHAR(500) NOT NULL,
            `artwork_url` VARCHAR(500) NOT NULL DEFAULT '',
            `category` VARCHAR(80) NOT NULL DEFAULT 'Nachrichten & Wissen',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_pod_feed` (`user_id`, `feed_url`(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `podcast_downloads` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `feed_title` VARCHAR(180) NOT NULL DEFAULT '',
            `episode_title` VARCHAR(240) NOT NULL,
            `episode_guid` VARCHAR(190) NOT NULL,
            `local_filename` VARCHAR(240) NOT NULL,
            `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_ep_dl` (`user_id`, `episode_guid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

ensurePodcastsSchema($pdo);

// Standard-Podcasts anlegen, falls der Benutzer noch keine abonniert hat
$stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM `podcast_feeds` WHERE `user_id` = :u');
$stmtCnt->execute([':u' => $userId]);
if ((int)$stmtCnt->fetchColumn() === 0) {
    $defaults = [
        [
            'tagesschau in 100 Sekunden (Audio)',
            'ARD / NDR',
            'https://www.tagesschau.de/multimedia/sendung/tagesschau_in_100_sekunden/podcast-ts100-audio-100~podcast.xml',
            '',
            'Nachrichten',
        ],
        [
            'Deutschlandfunk – Der Tag',
            'Deutschlandfunk',
            'https://www.deutschlandfunk.de/der-tag-102.xml',
            '',
            'Politik & Hintergrund',
        ],
        [
            'Geschichten aus der Geschichte',
            'Richard Hemmer & Daniel Meßner',
            'https://feeds.acast.com/public/shows/geschichten-aus-der-geschichte',
            '',
            'Geschichte',
        ],
        [
            'Forschung aktuell – Deutschlandfunk',
            'Deutschlandfunk',
            'https://www.deutschlandfunk.de/forschung-aktuell-102.xml',
            '',
            'Wissenschaft & Technik',
        ],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO `podcast_feeds` (`user_id`, `title`, `author`, `feed_url`, `artwork_url`, `category`) VALUES (:u, :t, :a, :f, :art, :c)');
    foreach ($defaults as $df) {
        $ins->execute([':u' => $userId, ':t' => $df[0], ':a' => $df[1], ':f' => $df[2], ':art' => $df[3], ':c' => $df[4]]);
    }
}

$podBaseDir = __DIR__ . '/uploads/podcasts';
$podCacheDir = $podBaseDir . '/cache';
$podEpDir = $podBaseDir . '/episodes';
foreach ([$podBaseDir, $podCacheDir, $podEpDir] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

// ----------------------------------------------------------------------------
// 2. RSS-FEED-PARSER & VERZEICHNIS-SUCHE
// ----------------------------------------------------------------------------
function isSafeExternalPodcastUrl(string $url): bool
{
    if (!preg_match('/^https?:\/\/[^\s"\'<>]+$/i', $url)) {
        return false;
    }
    $parts = @parse_url($url);
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '169.254.169.254'], true)) {
        return false;
    }
    return true;
}

function fetchAndParsePodcastFeed(string $feedUrl, string $cacheDir): array
{
    if (!isSafeExternalPodcastUrl($feedUrl)) {
        return ['title' => 'Podcast', 'desc' => '', 'episodes' => []];
    }

    $cacheFile = $cacheDir . '/feed_' . md5($feedUrl) . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 600) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['episodes'])) {
            return $cached;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 6.0,
            'ignore_errors' => true,
            'header'        => "Accept: application/rss+xml, application/xml, text/xml\r\nUser-Agent: MiniCloud-PodcastStudio/1.0\r\n",
        ],
    ]);

    $rawXml = @file_get_contents($feedUrl, false, $ctx);
    if (!is_string($rawXml) || trim($rawXml) === '') {
        return ['title' => 'Podcast', 'desc' => '', 'episodes' => []];
    }

    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();
    if (!$xml || !isset($xml->channel)) {
        return ['title' => 'Podcast', 'desc' => '', 'episodes' => []];
    }

    $channelTitle = trim((string)($xml->channel->title ?? 'Podcast'));
    $channelDesc  = trim(strip_tags((string)($xml->channel->description ?? '')));
    $episodes     = [];

    foreach ($xml->channel->item as $item) {
        $audioUrl = '';
        if (isset($item->enclosure['url'])) {
            $audioUrl = trim((string)$item->enclosure['url']);
        }
        if ($audioUrl === '') {
            continue;
        }

        $epTitle = trim((string)($item->title ?? 'Episode'));
        $guid    = trim((string)($item->guid ?? $audioUrl));
        $pubRaw  = trim((string)($item->pubDate ?? ''));
        $pubTs   = $pubRaw !== '' ? strtotime($pubRaw) : time();
        $desc    = trim(strip_tags((string)($item->description ?? '')));

        $itunes  = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
        $dur     = isset($itunes->duration) ? trim((string)$itunes->duration) : '';

        $episodes[] = [
            'guid'      => substr(md5($guid), 0, 24),
            'title'     => $epTitle,
            'audio_url' => $audioUrl,
            'date_fmt'  => $pubTs ? date('d.m.Y', (int)$pubTs) : '',
            'duration'  => $dur,
            'desc'      => mb_substr($desc, 0, 280),
        ];

        if (count($episodes) >= 25) {
            break;
        }
    }

    $result = [
        'title'    => $channelTitle,
        'desc'     => mb_substr($channelDesc, 0, 320),
        'episodes' => $episodes,
    ];

    if (!empty($episodes)) {
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    return $result;
}

function searchPodcastDirectory(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $url = 'https://itunes.apple.com/search?term=' . rawurlencode($query) . '&country=DE&media=podcast&limit=8';
    $ctx = stream_context_create(['http' => ['timeout' => 5.0, 'ignore_errors' => true, 'user_agent' => 'MiniCloud/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = is_string($raw) ? @json_decode($raw, true) : null;

    $results = [];
    if (is_array($data) && !empty($data['results']) && is_array($data['results'])) {
        foreach ($data['results'] as $r) {
            $feedUrl = trim((string)($r['feedUrl'] ?? ''));
            if ($feedUrl === '') {
                continue;
            }
            $results[] = [
                'title'    => trim((string)($r['collectionName'] ?? 'Podcast')),
                'author'   => trim((string)($r['artistName'] ?? '')),
                'feed_url' => $feedUrl,
                'artwork'  => trim((string)($r['artworkUrl100'] ?? '')),
                'genre'    => trim((string)($r['primaryGenreName'] ?? 'Podcast')),
            ];
        }
    }
    return $results;
}


// ----------------------------------------------------------------------------
// 3. LOKALE OFFLINE-EPISODE STREAMEN (?action=stream_local&id=...)
//    ODER EXTERNE PODCAST-EPISODE ÜBER PROXY STREAMEN (?action=proxy_stream&url=...)
//    ODER HINTERGRUND-DOWNLOAD-STATUS ABFRAGEN (?action=download_status)
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_status') {
    @session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $pattern = $podCacheDir . '/dl_status_u' . $userId . '_*.json';
    $files   = glob($pattern) ?: [];
    $now     = time();
    $list    = [];

    foreach ($files as $sf) {
        $raw = @file_get_contents($sf);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }
        $updatedAt = (int)($data['updated_at'] ?? filemtime($sf));
        $status    = (string)($data['status'] ?? '');

        // Abgeschlossene oder fehlgeschlagene Statusdateien nach 90 Sekunden aufräumen
        if (in_array($status, ['done', 'error'], true) && ($now - $updatedAt) > 90) {
            @unlink($sf);
            continue;
        }
        // Verwaiste Downloads nach 15 Minuten aufräumen
        if ($status === 'downloading' && ($now - $updatedAt) > 900) {
            @unlink($sf);
            continue;
        }
        $list[] = $data;
    }

    echo json_encode(['ok' => true, 'downloads' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'stream_local') {
    @session_write_close();
    $dlId = (int)($_GET['id'] ?? 0);
    $stmtDl = $pdo->prepare('SELECT * FROM `podcast_downloads` WHERE `id` = :id AND `user_id` = :u LIMIT 1');
    $stmtDl->execute([':id' => $dlId, ':u' => $userId]);
    $dlRow = $stmtDl->fetch(PDO::FETCH_ASSOC);
    if ($dlRow) {
        $fPath = $podEpDir . '/' . basename((string)$dlRow['local_filename']);
        if (is_file($fPath)) {
            header('Content-Type: audio/mpeg');
            header('Content-Length: ' . filesize($fPath));
            header('Accept-Ranges: bytes');
            readfile($fPath);
            exit;
        }
    }
    http_response_code(404);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'proxy_stream') {
    @session_write_close();
    @set_time_limit(0);
    $remoteUrl = trim((string)($_GET['url'] ?? ''));
    if (!isSafeExternalPodcastUrl($remoteUrl)) {
        http_response_code(400);
        exit;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($remoteUrl);
        $reqHeaders = ['User-Agent: MiniCloud-PodcastStudio/1.0'];
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=\d*-\d*(,\d*-\d*)*$/', (string)$_SERVER['HTTP_RANGE'])) {
            $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        $headersSent = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 8,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headersSent): int {
                $trim = trim($headerLine);
                if ( stripos($trim, 'HTTP/') === 0 ) {
                    if (preg_match('/HTTP\/\d+(?:\.\d+)?\s+(200|206)\b/i', $trim, $mCode)) {
                        http_response_code((int)$mCode[1]);
                    }
                } elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Accept-Ranges):/i', $trim)) {
                    header($trim, true);
                    $headersSent = true;
                }
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $data) use (&$headersSent): int {
                if (!$headersSent) {
                    header('Content-Type: audio/mpeg');
                    $headersSent = true;
                }
                echo $data;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        exit;
    }

    header('Location: ' . $remoteUrl);
    exit;
}

// ----------------------------------------------------------------------------
// 4. POST-AKTIONEN (FEED ABONNIEREN, LÖSCHEN, EPISODE OFFLINE SPEICHERN)
// ----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiger Sicherheits-Token.');
        header('Location: podcasts.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'subscribe_feed') {
        $fUrl = trim((string)($_POST['feed_url'] ?? ''));
        $fTitle = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 180);
        $fAuthor = mb_substr(trim((string)($_POST['author'] ?? '')), 0, 140);
        $fCat = mb_substr(trim((string)($_POST['category'] ?? 'Podcast')), 0, 80);

        if (isSafeExternalPodcastUrl($fUrl)) {
            if ($fTitle === '') {
                $parsed = fetchAndParsePodcastFeed($fUrl, $podCacheDir);
                $fTitle = $parsed['title'] ?: 'Neuer Podcast';
            }
            $stmt = $pdo->prepare('INSERT IGNORE INTO `podcast_feeds` (`user_id`, `title`, `author`, `feed_url`, `category`) VALUES (:u, :t, :a, :f, :c)');
            $stmt->execute([':u' => $userId, ':t' => $fTitle, ':a' => $fAuthor, ':f' => $fUrl, ':c' => $fCat]);
            setFlash('success', 'Podcast „' . $fTitle . '“ abonniert.');
        } else {
            setFlash('error', 'Ungültige RSS-Feed-URL.');
        }
        header('Location: podcasts.php');
        exit;
    }

    if ($action === 'delete_feed') {
        $fid = (int)($_POST['feed_id'] ?? 0);
        $pdo->prepare('DELETE FROM `podcast_feeds` WHERE `id` = :id AND `user_id` = :u')->execute([':id' => $fid, ':u' => $userId]);
        setFlash('success', 'Podcast-Abo entfernt.');
        header('Location: podcasts.php');
        exit;
    }

    if ($action === 'download_episode') {
        $isAjax    = (!empty($_POST['ajax']) && $_POST['ajax'] === '1')
            || (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');
        $audioUrl  = trim((string)($_POST['audio_url'] ?? ''));
        $epTitle   = mb_substr(trim((string)($_POST['episode_title'] ?? 'Episode')), 0, 240);
        $epGuid    = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['episode_guid'] ?? '')) ?: substr(md5($audioUrl), 0, 24);
        $feedTitle = mb_substr(trim((string)($_POST['feed_title'] ?? 'Podcast')), 0, 180);

        // WICHTIG: PHP-Session-Sperre sofort freigeben und Abbruch bei Seitenwechsel ignorieren!
        // Dadurch bleibt die gesamte MiniCloud während des Downloads zu 100 % reaktionsfähig
        // und der Download läuft auf dem Server selbst dann weiter, wenn der Nutzer das Modul wechselt.
        @ignore_user_abort(true);
        @set_time_limit(0);
        @session_write_close();

        if (!isSafeExternalPodcastUrl($audioUrl)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Ungültige Audio-URL.']);
                exit;
            }
            header('Location: podcasts.php');
            exit;
        }

        $localName  = 'pod_u' . $userId . '_' . $epGuid . '.mp3';
        $destPath   = $podEpDir . '/' . $localName;
        $tempPath   = $destPath . '.part';
        $statusFile = $podCacheDir . '/dl_status_u' . $userId . '_' . $epGuid . '.json';

        $writeStatus = static function (array $state) use ($statusFile, $epGuid, $epTitle, $feedTitle): void {
            $payload = array_merge([
                'guid'       => $epGuid,
                'title'      => $epTitle,
                'feed_title' => $feedTitle,
                'updated_at' => time(),
            ], $state);
            @file_put_contents($statusFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
        };

        $writeStatus([
            'status'     => 'downloading',
            'downloaded' => 0,
            'total'      => 0,
            'percent'    => 0,
        ]);

        $maxBytes = 250 * 1024 * 1024; // Bis zu 250 MB pro Podcast-Episode erlauben
        $downloadSuccess = false;
        $errorMsg = 'Download der Episode fehlgeschlagen.';

        if (function_exists('curl_init')) {
            $fp = @fopen($tempPath, 'wb');
            if ($fp !== false) {
                $lastStatusWrite = 0.0;
                $ch = curl_init($audioUrl);
                curl_setopt_array($ch, [
                    CURLOPT_FILE             => $fp,
                    CURLOPT_FOLLOWLOCATION   => true,
                    CURLOPT_MAXREDIRS        => 8,
                    CURLOPT_TIMEOUT          => 600,
                    CURLOPT_CONNECTTIMEOUT   => 15,
                    CURLOPT_USERAGENT        => 'MiniCloud-PodcastStudio/1.0',
                    CURLOPT_SSL_VERIFYPEER   => false,
                    CURLOPT_SSL_VERIFYHOST   => 0,
                    CURLOPT_NOPROGRESS       => false,
                    CURLOPT_PROGRESSFUNCTION => static function ($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastStatusWrite, $writeStatus, $maxBytes): int {
                        $dlTotal = (int)$dlTotal;
                        $dlNow   = (int)$dlNow;
                        if ($dlNow > $maxBytes || ($dlTotal > $maxBytes)) {
                            return 1; // Abbruch falls > 250 MB
                        }
                        $nowMicro = microtime(true);
                        if (($nowMicro - $lastStatusWrite) >= 0.45) {
                            $lastStatusWrite = $nowMicro;
                            $pct = $dlTotal > 0 ? min(99, (int)round(($dlNow / $dlTotal) * 100)) : 0;
                            $writeStatus([
                                'status'     => 'downloading',
                                'downloaded' => $dlNow,
                                'total'      => $dlTotal,
                                'percent'    => $pct,
                            ]);
                        }
                        return 0;
                    },
                ]);
                $ok   = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($fp);

                if ($ok && $code >= 200 && $code < 400 && is_file($tempPath) && filesize($tempPath) > 1024) {
                    @rename($tempPath, $destPath);
                    $downloadSuccess = true;
                } else {
                    @unlink($tempPath);
                }
            }
        } else {
            $ctx = stream_context_create([
                'http' => ['timeout' => 300.0, 'ignore_errors' => true, 'user_agent' => 'MiniCloud-PodcastStudio/1.0'],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $in  = @fopen($audioUrl, 'rb', false, $ctx);
            $out = $in ? @fopen($tempPath, 'wb') : false;
            if ($in && $out) {
                $copied = 0;
                $lastStatusWrite = 0.0;
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($out, $chunk);
                    $copied += strlen($chunk);
                    if ($copied > $maxBytes) {
                        break;
                    }
                    $nowMicro = microtime(true);
                    if (($nowMicro - $lastStatusWrite) >= 0.5) {
                        $lastStatusWrite = $nowMicro;
                        $writeStatus([
                            'status'     => 'downloading',
                            'downloaded' => $copied,
                            'total'      => 0,
                            'percent'    => 0,
                        ]);
                    }
                }
                fclose($in);
                fclose($out);
                if ($copied > 1024 && $copied <= $maxBytes) {
                    @rename($tempPath, $destPath);
                    $downloadSuccess = true;
                } else {
                    @unlink($tempPath);
                }
            }
        }

        if ($downloadSuccess && is_file($destPath)) {
            $fSize = (int)filesize($destPath);
            $pdo   = getDB();
            $stmt  = $pdo->prepare(
                'INSERT INTO `podcast_downloads` (`user_id`, `feed_title`, `episode_title`, `episode_guid`, `local_filename`, `file_size`)
                 VALUES (:u, :ft, :et, :eg, :lf, :fs)
                 ON DUPLICATE KEY UPDATE `file_size` = VALUES(`file_size`), `local_filename` = VALUES(`local_filename`)'
            );
            $stmt->execute([':u' => $userId, ':ft' => $feedTitle, ':et' => $epTitle, ':eg' => $epGuid, ':lf' => $localName, ':fs' => $fSize]);

            $idStmt = $pdo->prepare('SELECT `id` FROM `podcast_downloads` WHERE `user_id` = :u AND `episode_guid` = :eg LIMIT 1');
            $idStmt->execute([':u' => $userId, ':eg' => $epGuid]);
            $dlId = (int)$idStmt->fetchColumn();

            $streamUrl = 'podcasts.php?action=stream_local&id=' . $dlId;
            $sizeFmt   = formatBytes($fSize);

            $writeStatus([
                'status'     => 'done',
                'downloaded' => $fSize,
                'total'      => $fSize,
                'percent'    => 100,
                'dl_id'      => $dlId,
                'size_fmt'   => $sizeFmt,
                'stream_url' => $streamUrl,
            ]);

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'         => true,
                    'status'     => 'done',
                    'guid'       => $epGuid,
                    'title'      => $epTitle,
                    'feed_title' => $feedTitle,
                    'dl_id'      => $dlId,
                    'size_fmt'   => $sizeFmt,
                    'stream_url' => $streamUrl,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            $writeStatus([
                'status' => 'error',
                'error'  => $errorMsg,
            ]);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'guid' => $epGuid, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        header('Location: podcasts.php');
        exit;
    }

    if ($action === 'delete_download') {
        $dlId = (int)($_POST['dl_id'] ?? 0);
        $stmtDl = $pdo->prepare('SELECT * FROM `podcast_downloads` WHERE `id` = :id AND `user_id` = :u LIMIT 1');
        $stmtDl->execute([':id' => $dlId, ':u' => $userId]);
        $dlRow = $stmtDl->fetch(PDO::FETCH_ASSOC);
        if ($dlRow) {
            $fPath = $podEpDir . '/' . basename((string)$dlRow['local_filename']);
            if (is_file($fPath)) {
                @unlink($fPath);
            }
            $pdo->prepare('DELETE FROM `podcast_downloads` WHERE `id` = :id AND `user_id` = :u')->execute([':id' => $dlId, ':u' => $userId]);
            setFlash('success', 'Offline-Episode gelöscht.');
        }
        header('Location: podcasts.php');
        exit;
    }
}

// ----------------------------------------------------------------------------
// 5. DATEN FÜR ANSICHT LADEN
// ----------------------------------------------------------------------------
$stmtFeeds = $pdo->prepare('SELECT * FROM `podcast_feeds` WHERE `user_id` = :u ORDER BY `id` ASC');
$stmtFeeds->execute([':u' => $userId]);
$myFeeds = $stmtFeeds->fetchAll(PDO::FETCH_ASSOC);

$activeFeedId = isset($_GET['feed_id']) ? (int)$_GET['feed_id'] : (int)($myFeeds[0]['id'] ?? 0);
$activeFeed = $myFeeds[0] ?? null;
foreach ($myFeeds as $mf) {
    if ((int)$mf['id'] === $activeFeedId) {
        $activeFeed = $mf;
        break;
    }
}

$parsedFeed = $activeFeed ? fetchAndParsePodcastFeed((string)$activeFeed['feed_url'], $podCacheDir) : ['title' => '', 'desc' => '', 'episodes' => []];

$searchQuery = trim((string)($_GET['search_q'] ?? ''));
$searchResults = $searchQuery !== '' ? searchPodcastDirectory($searchQuery) : [];

$stmtDls = $pdo->prepare('SELECT * FROM `podcast_downloads` WHERE `user_id` = :u ORDER BY `id` DESC');
$stmtDls->execute([':u' => $userId]);
$offlineEpisodes = $stmtDls->fetchAll(PDO::FETCH_ASSOC);
$offlineByGuid = [];
foreach ($offlineEpisodes as $oe) {
    $offlineByGuid[(string)$oe['episode_guid']] = $oe;
}

$pageTitle = 'Podcast-Studio & Offline-Catcher';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.pod-wrap { max-width: 1440px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 18px; }
.pod-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 18px; box-shadow: 0 4px 18px rgba(0,0,0,0.06); }
.pod-player-bar { background: linear-gradient(135deg, #1e1b4b, #0f172a); border: 1px solid rgba(139,92,246,0.55); border-radius: 16px; padding: 16px 20px; color: #f8fafc; position: sticky; top: 4.5rem; z-index: 20; box-shadow: 0 10px 28px rgba(0,0,0,0.28); }
@media (max-width: 639px) { .pod-player-bar { top: 3.9rem; } }
.pod-grid { display: grid; grid-template-columns: 340px 1fr; gap: 18px; }
.pod-ep-card { padding: 13px 15px; border-radius: 12px; background: rgba(15,23,42,0.5); border: 1px solid var(--border); transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease; }
.pod-ep-card:hover { border-color: rgba(139,92,246,0.5); }
.pod-ep-card.pod-ep-active { border-color: #8b5cf6 !important; background: rgba(139,92,246,0.14) !important; box-shadow: 0 0 0 2px rgba(139,92,246,0.2); }
.pod-ep-title-btn { background: none; border: none; padding: 0; margin: 0; color: inherit; font: inherit; font-weight: 800; font-size: 0.95rem; text-align: left; cursor: pointer; transition: color 0.15s ease; }
.pod-ep-title-btn:hover { color: #a78bfa; }
@media (max-width: 1024px) { .pod-grid { grid-template-columns: 1fr; } }
</style>

<div class="pod-wrap">
    <!-- STUDIO-PODCAST-PLAYER MIT SEKUNDENGEDÄCHTNIS & SPRUNGMARKEN -->
    <div class="pod-player-bar">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px;">
            <div style="flex:1;min-width:260px;">
                <div style="font-size:0.74rem;text-transform:uppercase;letter-spacing:0.06em;color:#c4b5fd;">🎙️ Podcast-Studio Player · Sekundengenaues Weiterhören</div>
                <div id="podNowPlayingTitle" style="font-size:1.08rem;font-weight:800;margin-top:3px;">Wähle unten eine Episode zum Abspielen aus</div>
                <div id="podNowPlayingShow" style="font-size:0.8rem;color:#94a3b8;margin-top:2px;">Pausiert laufendes Webradio automatisch bei Wiedergabestart</div>
            </div>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <button type="button" onclick="skipPodcastSeconds(-15)" class="btn btn-sm btn-secondary" title="15 Sekunden zurück">⏪ -15s</button>
                <button type="button" onclick="skipPodcastSeconds(30)" class="btn btn-sm btn-secondary" title="30 Sekunden vor">⏩ +30s</button>
                <select id="podSpeedSelect" onchange="setPodcastSpeed(this.value)" class="form-control" style="width:auto;padding:5px 10px;font-size:0.82rem;">
                    <option value="0.8">0.8x Tempo</option>
                    <option value="1.0" selected>1.0x Normal</option>
                    <option value="1.25">1.25x Schneller</option>
                    <option value="1.5">1.5x Schnell</option>
                    <option value="2.0">2.0x Turbo</option>
                </select>
            </div>
        </div>
        <audio id="podAudioElement" controls style="width:100%;margin-top:12px;height:40px;"></audio>
    </div>

    <!-- PODCAST-VERZEICHNISSUCHE & RSS-DIREKT-ABO -->
    <div class="pod-card">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
            <form method="get" action="podcasts.php" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;min-width:260px;margin:0;">
                <input type="text" name="search_q" value="<?= e($searchQuery) ?>" class="form-control" style="flex:1;" placeholder="Podcast im Verzeichnis suchen (z. B. Tagesschau, Lanz & Precht, c't uplink, Wissen)...">
                <button type="submit" class="btn btn-primary">🔍 Podcasts suchen</button>
            </form>

            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="subscribe_feed">
                <input type="url" name="feed_url" class="form-control" style="width:250px;" placeholder="Oder RSS-Feed-URL direkt einfügen..." required>
                <button type="submit" class="btn btn-secondary">➕ RSS abonnieren</button>
            </form>
        </div>

        <?php if (!empty($searchResults)): ?>
            <div style="margin-top:14px;display:grid;grid-template-columns:repeat(auto-fill, minmax(270px, 1fr));gap:10px;">
                <?php foreach ($searchResults as $sr): ?>
                    <div style="padding:11px 13px;border-radius:12px;background:rgba(15,23,42,0.5);border:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px;">
                        <div style="min-width:0;">
                            <div style="font-weight:700;font-size:0.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($sr['title']) ?></div>
                            <div class="text-muted" style="font-size:0.75rem;"><?= e($sr['author']) ?> · <?= e($sr['genre']) ?></div>
                        </div>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="subscribe_feed">
                            <input type="hidden" name="title" value="<?= e($sr['title']) ?>">
                            <input type="hidden" name="author" value="<?= e($sr['author']) ?>">
                            <input type="hidden" name="feed_url" value="<?= e($sr['feed_url']) ?>">
                            <input type="hidden" name="category" value="<?= e($sr['genre']) ?>">
                            <button type="submit" class="btn btn-sm btn-primary">+ Abo</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- HAUPTBEREICH: ABONNIERTE SHOWS (LINKS) + EPISODENLISTE (RECHTS) -->
    <div class="pod-grid">
        <div class="pod-card">
            <h3 style="margin:0 0 12px 0;font-size:1.05rem;">📻 Meine abonnierten Podcasts (<?= count($myFeeds) ?>)</h3>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($myFeeds as $mf): ?>
                    <?php $isAct = ($activeFeed && (int)$activeFeed['id'] === (int)$mf['id']); ?>
                    <div style="padding:11px 13px;border-radius:12px;background:<?= $isAct ? 'rgba(139,92,246,0.2)' : 'rgba(15,23,42,0.45)' ?>;border:1px solid <?= $isAct ? '#8b5cf6' : 'var(--border)' ?>;display:flex;justify-content:space-between;align-items:center;gap:8px;">
                        <a href="podcasts.php?feed_id=<?= (int)$mf['id'] ?>" style="text-decoration:none;color:var(--text-main);flex:1;min-width:0;">
                            <div style="font-weight:800;font-size:0.9rem;"><?= e($mf['title']) ?></div>
                            <div class="text-muted" style="font-size:0.75rem;"><?= e($mf['author']) ?> · <?= e($mf['category']) ?></div>
                        </a>
                        <?php if (count($myFeeds) > 1): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_feed">
                                <input type="hidden" name="feed_id" value="<?= (int)$mf['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.78rem;">✕</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($offlineEpisodes)): ?>
                <h3 style="margin:20px 0 10px 0;font-size:1.0rem;color:#34d399;">💾 Offline gespeicherte Episoden (<?= count($offlineEpisodes) ?>)</h3>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($offlineEpisodes as $oe): ?>
                        <?php $oeStreamUrl = 'podcasts.php?action=stream_local&id=' . (int)$oe['id']; ?>
                        <div class="pod-ep-card" data-ep-card-guid="<?= e((string)$oe['episode_guid']) ?>" style="padding:10px 12px;border-radius:10px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.35);">
                            <button type="button" class="pod-ep-title-btn" style="font-size:0.84rem;"
                                    data-audio-url="<?= e($oeStreamUrl) ?>"
                                    data-ep-title="<?= e((string)$oe['episode_title']) ?>"
                                    data-feed-title="<?= e((string)$oe['feed_title'] . ' (Offline)') ?>"
                                    data-ep-guid="<?= e((string)$oe['episode_guid']) ?>"
                                    onclick="playPodcastFromEl(this)">
                                🎧 <?= e($oe['episode_title']) ?>
                            </button>
                            <div class="text-muted" style="font-size:0.72rem;"><?= e($oe['feed_title']) ?> · <?= formatBytes((int)$oe['file_size']) ?></div>
                            <div style="display:flex;gap:6px;margin-top:6px;">
                                <button type="button" class="btn btn-sm btn-primary" style="padding:3px 9px;font-size:0.75rem;"
                                        data-pod-play-btn="<?= e((string)$oe['episode_guid']) ?>"
                                        data-audio-url="<?= e($oeStreamUrl) ?>"
                                        data-ep-title="<?= e((string)$oe['episode_title']) ?>"
                                        data-feed-title="<?= e((string)$oe['feed_title'] . ' (Offline)') ?>"
                                        data-ep-guid="<?= e((string)$oe['episode_guid']) ?>"
                                        onclick="playPodcastFromEl(this)">
                                    ▶️ Offline abspielen
                                </button>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete_download">
                                    <input type="hidden" name="dl_id" value="<?= (int)$oe['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding:3px 8px;font-size:0.73rem;">🗑️</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="pod-card">
            <?php if ($activeFeed): ?>
                <div style="margin-bottom:14px;">
                    <h3 style="margin:0;font-size:1.15rem;">🎙️ <?= e($activeFeed['title']) ?></h3>
                    <?php if ($parsedFeed['desc'] !== ''): ?>
                        <div class="text-muted" style="font-size:0.82rem;margin-top:4px;"><?= e($parsedFeed['desc']) ?></div>
                    <?php endif; ?>
                </div>

                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php if (empty($parsedFeed['episodes'])): ?>
                        <div class="text-muted" style="padding:20px;text-align:center;">Keine Episoden im RSS-Feed gefunden oder Feed vorübergehend nicht erreichbar.</div>
                    <?php endif; ?>
                    <?php foreach ($parsedFeed['episodes'] as $ep): ?>
                        <?php
                        $offlineRow  = $offlineByGuid[$ep['guid']] ?? null;
                        $epStreamUrl = $offlineRow
                            ? ('podcasts.php?action=stream_local&id=' . (int)$offlineRow['id'])
                            : (string)$ep['audio_url'];
                        ?>
                        <div class="pod-ep-card" data-ep-card-guid="<?= e((string)$ep['guid']) ?>">
                            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;">
                                <button type="button" class="pod-ep-title-btn"
                                        data-audio-url="<?= e($epStreamUrl) ?>"
                                        data-ep-title="<?= e((string)$ep['title']) ?>"
                                        data-feed-title="<?= e((string)$activeFeed['title']) ?>"
                                        data-ep-guid="<?= e((string)$ep['guid']) ?>"
                                        onclick="playPodcastFromEl(this)"
                                        title="Klicken zum Abspielen">
                                    ▶️ <?= e($ep['title']) ?>
                                </button>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <?php if ($ep['date_fmt'] !== ''): ?>
                                        <span class="text-muted" style="font-size:0.76rem;">📅 <?= e($ep['date_fmt']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($ep['duration'] !== ''): ?>
                                        <span class="badge" style="background:rgba(139,92,246,0.2);color:#c4b5fd;font-size:0.72rem;">⏱️ <?= e($ep['duration']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($ep['desc'] !== ''): ?>
                                <div class="text-muted" style="font-size:0.8rem;margin-top:6px;line-height:1.4;"><?= e($ep['desc']) ?></div>
                            <?php endif; ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;align-items:center;">
                                <button type="button" class="btn btn-sm btn-primary"
                                        data-pod-play-btn="<?= e((string)$ep['guid']) ?>"
                                        data-audio-url="<?= e($epStreamUrl) ?>"
                                        data-ep-title="<?= e((string)$ep['title']) ?>"
                                        data-feed-title="<?= e((string)$activeFeed['title']) ?>"
                                        data-ep-guid="<?= e((string)$ep['guid']) ?>"
                                        onclick="playPodcastFromEl(this)">
                                    ▶️ Episode abspielen
                                </button>
                                <span data-pod-dl-slot="<?= e((string)$ep['guid']) ?>">
                                <?php if ($offlineRow): ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;font-size:0.75rem;">✅ Offline auf Server gespeichert</span>
                                <?php else: ?>
                                    <form method="post" style="margin:0;display:inline;" onsubmit="return startPodcastOfflineDownloadForm(event, this);">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="download_episode">
                                        <input type="hidden" name="audio_url" value="<?= e($ep['audio_url']) ?>">
                                        <input type="hidden" name="episode_title" value="<?= e($ep['title']) ?>">
                                        <input type="hidden" name="episode_guid" value="<?= e($ep['guid']) ?>">
                                        <input type="hidden" name="feed_title" value="<?= e($activeFeed['title']) ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" data-pod-dl-btn="<?= e((string)$ep['guid']) ?>">💾 Offline in Cloud speichern</button>
                                    </form>
                                <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let currentPodGuid = null;
let currentPodRawUrl = '';
let currentPodProxyTried = false;
const podAudio = document.getElementById('podAudioElement');

function playPodcastFromEl(el) {
    if (!el) return;
    const url      = el.getAttribute('data-audio-url') || '';
    const title    = el.getAttribute('data-ep-title') || 'Episode';
    const showName = el.getAttribute('data-feed-title') || 'Podcast';
    const guid     = el.getAttribute('data-ep-guid') || '';
    playPodcastEpisode(url, title, showName, guid);
}

function updatePodcastActiveCards() {
    const isPlaying = podAudio && !podAudio.paused && currentPodGuid;
    document.querySelectorAll('[data-ep-card-guid]').forEach((card) => {
        const g = card.getAttribute('data-ep-card-guid');
        card.classList.toggle('pod-ep-active', g === currentPodGuid);
    });
    document.querySelectorAll('[data-pod-play-btn]').forEach((btn) => {
        const g = btn.getAttribute('data-pod-play-btn');
        if (g === currentPodGuid) {
            btn.textContent = isPlaying ? '⏸️ Pausieren (Läuft)' : '▶️ Weiterhören';
        } else {
            const isOffline = (btn.getAttribute('data-audio-url') || '').includes('action=stream_local');
            btn.textContent = isOffline ? '▶️ Offline abspielen' : '▶️ Episode abspielen';
        }
    });
}

function playPodcastEpisode(url, title, showName, guid) {
    if (!podAudio || !url) return;

    // Falls dieselbe Episode bereits aktiv ist -> zwischen Play & Pause umschalten
    if (currentPodGuid === guid && podAudio.src) {
        if (podAudio.paused) {
            podAudio.play().catch(() => {});
        } else {
            podAudio.pause();
        }
        updatePodcastActiveCards();
        return;
    }

    // Laufendes Webradio automatisch pausieren
    if (window.McGlobalRadio && typeof window.McGlobalRadio.pause === 'function') {
        try { window.McGlobalRadio.pause(); } catch (e) {}
    }

    currentPodGuid = guid;
    currentPodRawUrl = url;
    currentPodProxyTried = false;

    document.getElementById('podNowPlayingTitle').textContent = '🔊 ' + title;
    document.getElementById('podNowPlayingShow').textContent = showName;

    podAudio.src = url;
    const spd = parseFloat(document.getElementById('podSpeedSelect').value || '1.0');
    podAudio.playbackRate = spd;

    const restoreSavedPosition = () => {
        podAudio.removeEventListener('loadedmetadata', restoreSavedPosition);
        try {
            const savedSec = parseFloat(localStorage.getItem('mc_pod_pos_' + guid) || '0');
            if (savedSec > 5 && (!podAudio.duration || savedSec < podAudio.duration - 5)) {
                podAudio.currentTime = savedSec;
            }
        } catch (e) {}
    };
    podAudio.addEventListener('loadedmetadata', restoreSavedPosition);

    updatePodcastActiveCards();
    podAudio.play().catch(() => {});
}

if (podAudio) {
    podAudio.addEventListener('play', updatePodcastActiveCards);
    podAudio.addEventListener('pause', updatePodcastActiveCards);
    podAudio.addEventListener('ended', updatePodcastActiveCards);

    // Automatischer Server-Proxy-Fallback, falls ein Browser-Adblocker/Tracking-Schutz die externe Podcast-URL blockiert
    podAudio.addEventListener('error', () => {
        if (!currentPodProxyTried && currentPodRawUrl && /^https?:\/\//i.test(currentPodRawUrl)) {
            currentPodProxyTried = true;
            podAudio.src = 'podcasts.php?action=proxy_stream&url=' + encodeURIComponent(currentPodRawUrl);
            const spd = parseFloat(document.getElementById('podSpeedSelect').value || '1.0');
            podAudio.playbackRate = spd;
            podAudio.play().catch(() => {});
        }
    });

    podAudio.addEventListener('timeupdate', () => {
        if (currentPodGuid && podAudio.currentTime > 3) {
            try {
                localStorage.setItem('mc_pod_pos_' + currentPodGuid, String(Math.floor(podAudio.currentTime)));
            } catch (e) {}
        }
    });
}

function skipPodcastSeconds(delta) {
    if (!podAudio || !podAudio.src) return;
    podAudio.currentTime = Math.max(0, podAudio.currentTime + delta);
}

function setPodcastSpeed(val) {
    if (!podAudio) return;
    podAudio.playbackRate = parseFloat(val) || 1.0;
}

// =========================================================================
// 🚀 HINTERGRUND-DOWNLOAD FÜR OFFLINE-EPISODEN (NICHT-BLOCKIEREND)
// =========================================================================
let podBgPollTimer = null;
const completedPodGuids = new Set();

function formatPodBytes(bytes) {
    const b = Math.max(0, Number(bytes) || 0);
    if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
}

function ensurePodBgToast() {
    let toast = document.getElementById('podBgDownloadToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'podBgDownloadToast';
        toast.style.cssText = 'position:fixed;bottom:18px;right:18px;z-index:35;max-width:360px;width:calc(100vw - 36px);background:rgba(15,23,42,0.96);border:1px solid rgba(139,92,246,0.6);border-radius:14px;padding:12px 15px;color:#f8fafc;box-shadow:0 12px 28px rgba(0,0,0,0.4);backdrop-filter:blur(8px);display:none;font-size:0.8rem;';
        document.body.appendChild(toast);
    }
    return toast;
}

function markEpisodeOfflineReady(guid, streamUrl, sizeFmt) {
    completedPodGuids.add(guid);
    const slot = document.querySelector(`[data-pod-dl-slot="${guid}"]`);
    if (slot) {
        slot.innerHTML = `<span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;font-size:0.75rem;">✅ Offline gespeichert${sizeFmt ? ' (' + sizeFmt + ')' : ''}</span>`;
    }
    if (streamUrl) {
        document.querySelectorAll(`[data-ep-card-guid="${guid}"] [data-audio-url]`).forEach(el => {
            el.setAttribute('data-audio-url', streamUrl);
        });
        updatePodcastActiveCards();
    }
}

function updatePodDownloadUi(dlList) {
    const toast = ensurePodBgToast();
    const activeDls = [];

    for (const item of (dlList || [])) {
        const guid = String(item.guid || '');
        if (!guid) continue;

        if (item.status === 'downloading' && !completedPodGuids.has(guid)) {
            activeDls.push(item);
            const btn = document.querySelector(`[data-pod-dl-btn="${guid}"]`);
            const pct = Number(item.percent || 0);
            const dlBytes = Number(item.downloaded || 0);
            const label = pct > 0
                ? `⏳ Im Hintergrund: ${pct}% (${formatPodBytes(dlBytes)})`
                : `⏳ Lädt im Hintergrund… (${formatPodBytes(dlBytes)})`;
            if (btn) {
                btn.disabled = true;
                btn.textContent = label;
            }
        } else if (item.status === 'done') {
            markEpisodeOfflineReady(guid, item.stream_url || '', item.size_fmt || '');
        } else if (item.status === 'error') {
            const btn = document.querySelector(`[data-pod-dl-btn="${guid}"]`);
            if (btn) {
                btn.disabled = false;
                btn.textContent = '⚠️ Erneut versuchen';
            }
        }
    }

    if (activeDls.length > 0) {
        const first = activeDls[0];
        const pct = Number(first.percent || 0);
        const dlInfo = first.total > 0
            ? `${formatPodBytes(first.downloaded)} / ${formatPodBytes(first.total)} (${pct}%)`
            : `${formatPodBytes(first.downloaded)} geladen`;
        toast.style.display = 'block';
        toast.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
                <strong style="color:#c4b5fd;">📥 Offline-Download im Hintergrund (${activeDls.length})</strong>
                <span style="font-size:0.72rem;color:#34d399;">MiniCloud frei nutzbar</span>
            </div>
            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;margin-bottom:4px;">${first.title || 'Episode'}</div>
            <div style="width:100%;height:6px;background:rgba(255,255,255,0.12);border-radius:999px;overflow:hidden;margin-bottom:4px;">
                <div style="height:100%;width:${pct > 0 ? pct : 35}%;background:linear-gradient(90deg,#8b5cf6,#10b981);transition:width 0.3s ease;"></div>
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;">${dlInfo} · Läuft auf dem Server weiter, auch bei Modulwechsel</div>
        `;
    } else {
        if (toast.style.display === 'block') {
            toast.innerHTML = `<div style="color:#34d399;font-weight:700;">✅ Offline-Download im Hintergrund abgeschlossen!</div>`;
            setTimeout(() => { toast.style.display = 'none'; }, 3500);
        }
        if (podBgPollTimer) {
            clearInterval(podBgPollTimer);
            podBgPollTimer = null;
        }
    }
}

async function pollPodcastDownloads() {
    try {
        const res = await fetch('podcasts.php?action=download_status', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (data && data.ok && Array.isArray(data.downloads)) {
            updatePodDownloadUi(data.downloads);
            return data.downloads;
        }
    } catch (e) {}
    return [];
}

function ensurePodBgPolling() {
    if (!podBgPollTimer) {
        podBgPollTimer = setInterval(pollPodcastDownloads, 1200);
    }
}

function startPodcastOfflineDownloadForm(ev, formEl) {
    if (ev) ev.preventDefault();
    if (!formEl) return false;

    const fd = new FormData(formEl);
    fd.append('ajax', '1');
    const rawGuid = String(fd.get('episode_guid') || '');
    const guid = rawGuid.replace(/[^a-zA-Z0-9_-]/g, '');
    const title = String(fd.get('episode_title') || 'Episode');

    const btn = formEl.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.setAttribute('data-pod-dl-btn', guid);
        btn.textContent = '⏳ Startet im Hintergrund…';
    }
    const slot = formEl.closest('[data-pod-dl-slot]');
    if (slot && guid) {
        slot.setAttribute('data-pod-dl-slot', guid);
    }

    updatePodDownloadUi([{ guid: guid, title: title, status: 'downloading', downloaded: 0, total: 0, percent: 0 }]);
    ensurePodBgPolling();

    fetch('podcasts.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: fd
    }).then(r => r.json()).then(d => {
        if (d && d.ok && d.status === 'done') {
            markEpisodeOfflineReady(d.guid || guid, d.stream_url || '', d.size_fmt || '');
            pollPodcastDownloads();
        } else if (d && !d.ok) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = '⚠️ Fehler – Erneut versuchen';
            }
        }
    }).catch(() => {
        // Selbst wenn der Nutzer zwischendurch die Seite wechselt, läuft der Server-Download weiter
    });

    return false;
}

// Beim Öffnen der Seite prüfen, ob bereits ein Hintergrund-Download auf dem Server läuft
document.addEventListener('DOMContentLoaded', async () => {
    const list = await pollPodcastDownloads();
    if (list.some(it => it.status === 'downloading')) {
        ensurePodBgPolling();
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
