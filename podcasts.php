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
// ----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'stream_local') {
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
        $audioUrl  = trim((string)($_POST['audio_url'] ?? ''));
        $epTitle   = mb_substr(trim((string)($_POST['episode_title'] ?? 'Episode')), 0, 240);
        $epGuid    = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['episode_guid'] ?? '')) ?: substr(md5($audioUrl), 0, 24);
        $feedTitle = mb_substr(trim((string)($_POST['feed_title'] ?? 'Podcast')), 0, 180);

        if (isSafeExternalPodcastUrl($audioUrl)) {
            $localName = 'pod_u' . $userId . '_' . $epGuid . '.mp3';
            $destPath  = $podEpDir . '/' . $localName;

            $ctx = stream_context_create(['http' => ['timeout' => 25.0, 'ignore_errors' => true, 'user_agent' => 'MiniCloud/1.0']]);
            $bytes = @file_get_contents($audioUrl, false, $ctx);
            if (is_string($bytes) && strlen($bytes) > 1024 && strlen($bytes) <= 80 * 1024 * 1024) {
                @file_put_contents($destPath, $bytes);
                $fSize = (int)filesize($destPath);
                $stmt = $pdo->prepare(
                    'INSERT INTO `podcast_downloads` (`user_id`, `feed_title`, `episode_title`, `episode_guid`, `local_filename`, `file_size`)
                     VALUES (:u, :ft, :et, :eg, :lf, :fs)
                     ON DUPLICATE KEY UPDATE `file_size` = VALUES(`file_size`)'
                );
                $stmt->execute([':u' => $userId, ':ft' => $feedTitle, ':et' => $epTitle, ':eg' => $epGuid, ':lf' => $localName, ':fs' => $fSize]);
                setFlash('success', 'Episode „' . $epTitle . '“ für den Offline-Betrieb auf dem Server gespeichert.');
            } else {
                setFlash('error', 'Download der Episode fehlgeschlagen oder Datei zu groß (> 80 MB).');
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
.pod-player-bar { background: linear-gradient(135deg, #1e1b4b, #0f172a); border: 1px solid rgba(139,92,246,0.45); border-radius: 16px; padding: 16px 20px; color: #f8fafc; }
.pod-grid { display: grid; grid-template-columns: 340px 1fr; gap: 18px; }
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
                        <div style="padding:10px 12px;border-radius:10px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.35);">
                            <div style="font-weight:700;font-size:0.84rem;"><?= e($oe['episode_title']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?= e($oe['feed_title']) ?> · <?= formatBytes((int)$oe['file_size']) ?></div>
                            <div style="display:flex;gap:6px;margin-top:6px;">
                                <button type="button" class="btn btn-sm btn-primary" style="padding:3px 9px;font-size:0.75rem;"
                                        onclick="playPodcastEpisode(<?= json_encode('podcasts.php?action=stream_local&id=' . (int)$oe['id']) ?>, <?= json_encode($oe['episode_title']) ?>, <?= json_encode($oe['feed_title'] . ' (Offline)') ?>, <?= json_encode($oe['episode_guid']) ?>)">
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
                        <?php $offlineRow = $offlineByGuid[$ep['guid']] ?? null; ?>
                        <div style="padding:13px 15px;border-radius:12px;background:rgba(15,23,42,0.5);border:1px solid var(--border);">
                            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;">
                                <strong style="font-size:0.95rem;"><?= e($ep['title']) ?></strong>
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
                                        onclick="playPodcastEpisode(<?= json_encode($offlineRow ? ('podcasts.php?action=stream_local&id=' . (int)$offlineRow['id']) : $ep['audio_url']) ?>, <?= json_encode($ep['title']) ?>, <?= json_encode($activeFeed['title']) ?>, <?= json_encode($ep['guid']) ?>)">
                                    ▶️ Episode abspielen
                                </button>
                                <?php if ($offlineRow): ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;font-size:0.75rem;">✅ Offline auf Server gespeichert</span>
                                <?php else: ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="download_episode">
                                        <input type="hidden" name="audio_url" value="<?= e($ep['audio_url']) ?>">
                                        <input type="hidden" name="episode_title" value="<?= e($ep['title']) ?>">
                                        <input type="hidden" name="episode_guid" value="<?= e($ep['guid']) ?>">
                                        <input type="hidden" name="feed_title" value="<?= e($activeFeed['title']) ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary">💾 Offline in Cloud speichern</button>
                                    </form>
                                <?php endif; ?>
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
const podAudio = document.getElementById('podAudioElement');

function playPodcastEpisode(url, title, showName, guid) {
    if (!podAudio) return;
    // Laufendes Webradio automatisch pausieren
    if (window.McGlobalRadio && typeof window.McGlobalRadio.pause === 'function') {
        try { window.McGlobalRadio.pause(); } catch (e) {}
    }

    currentPodGuid = guid;
    document.getElementById('podNowPlayingTitle').textContent = title;
    document.getElementById('podNowPlayingShow').textContent = showName;

    podAudio.src = url;
    const spd = parseFloat(document.getElementById('podSpeedSelect').value || '1.0');
    podAudio.playbackRate = spd;

    // Gespeicherte Position wiederherstellen
    try {
        const savedSec = parseFloat(localStorage.getItem('mc_pod_pos_' + guid) || '0');
        if (savedSec > 5) {
            podAudio.currentTime = savedSec;
        }
    } catch (e) {}

    podAudio.play().catch(() => {});
}

if (podAudio) {
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
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
