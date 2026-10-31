<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📰 WELT-NACHRICHTEN & LIVE-NEWS-ZENTRALE (news.php)
 * ============================================================================
 * Eigenständiges Nachrichten-Modul für die MiniCloud:
 * - Offizielle deutsche & internationale Nachrichten-Feeds (Tagesschau, ZDFheute,
 *   Spiegel, Deutschlandfunk, ZEIT, WDR, DW, BBC World, The Guardian, NYT, Heise, Golem, Sportschau, kicker)
 * - Schneller Parallel-Abruf (cURL Multi) + DSGVO-schonender MariaDB-Server-Cache
 * - 7 Themen-Kategorien: ⚡ Top-Schlagzeilen, 🌍 Welt & International, 📍 NRW & Inland,
 *   💻 Technik & Wissen, 💼 Wirtschaft, ⚽ Sport, ⭐ Eigene RSS-Feeds & 🔖 Merkliste
 * - 🇩🇪 1-Klick Sofort-Übersetzung für fremdsprachige Artikel (BBC, Guardian, NYT)
 * - 🤖 1-Klick KI-Nachrichten-Briefing (kompakte Zusammenfassung der aktuellen Lage)
 * - 🔊 Audio-Nachrichten-Sprecher (liest einzelne Meldungen oder alle Top-Schlagzeilen vor)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();

$appSettings = getAppSettings($pdo);
if (($appSettings['enable_news'] ?? '1') !== '1' && !$isAdmin) {
    setFlash('error', 'Das Modul „Nachrichten“ ist derzeit deaktiviert.');
    header('Location: index.php');
    exit;
}

// ============================================================================
// 1. DATENBANK-SCHEMA INITIALISIEREN (Auto-Migration)
// ============================================================================
function ensureNewsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news_cache (
            cache_key VARCHAR(160) NOT NULL PRIMARY KEY,
            payload MEDIUMTEXT NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news_bookmarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(300) NOT NULL,
            link VARCHAR(600) NOT NULL,
            source_name VARCHAR(120) NOT NULL DEFAULT '',
            summary TEXT NOT NULL,
            image_url VARCHAR(600) NOT NULL DEFAULT '',
            pub_date DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS news_custom_feeds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(500) NOT NULL,
            lang VARCHAR(10) NOT NULL DEFAULT 'de',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureNewsSchema($pdo);

// ============================================================================
// 2. SSRF-SCHUTZ & NEURONALE SOFORT-ÜBERSETZUNG (FÜR INTERNATIONALE NEWS)
// ============================================================================
function isSafeNewsFeedUrl(string $url): bool
{
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $host = strtolower(trim((string)$parts['host'], '[]'));
    $port = isset($parts['port']) ? (int)$parts['port'] : (strtolower((string)($parts['scheme'] ?? 'http')) === 'https' ? 443 : 80);

    $blockedPorts = [21, 22, 23, 25, 53, 110, 143, 445, 3306, 5432, 6379, 9200, 11211, 11434, 27017];
    if (in_array($port, $blockedPorts, true)) {
        return false;
    }
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '169.254.169.254', 'metadata.google.internal'], true)) {
        return false;
    }
    $ip = @gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/**
 * Übersetzt einen Nachrichtentext schnell ins Deutsche (NMT).
 */
function translateNewsTextToGerman(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $url = 'https://translate.google.de/translate_a/single?client=at&dt=t&dj=1&sl=auto&tl=de';
    $ua  = 'GoogleTranslate/6.29.59279 (Linux; U; Android 11; Pixel 5)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['q' => mb_substr($text, 0, 1500)]),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($res) && $code >= 200 && $code < 300) {
            $data = json_decode($res, true);
            $out  = '';
            if (isset($data['sentences']) && is_array($data['sentences'])) {
                foreach ($data['sentences'] as $s) {
                    if (isset($s['trans']) && is_string($s['trans'])) {
                        $out .= $s['trans'];
                    }
                }
            }
            if (trim($out) !== '') {
                return trim($out);
            }
        }
    }

    // Fallback: MyMemory NMT
    $mmUrl = 'https://api.mymemory.translated.net/get?q=' . rawurlencode(mb_substr($text, 0, 480)) . '&langpair=en|de';
    $mmRaw = @file_get_contents($mmUrl);
    if (is_string($mmRaw) && $mmRaw !== '') {
        $mmJson = json_decode($mmRaw, true);
        if (!empty($mmJson['responseData']['translatedText'])) {
            return trim(html_entity_decode((string)$mmJson['responseData']['translatedText'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }

    return $text;
}

// ============================================================================
// 3. AJAX-ENDPUNKTE (1-KLICK-ÜBERSETZUNG & KI-TAGESBRIEFING)
// ============================================================================
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'translate_article' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $rawTitle   = trim((string)($_POST['title'] ?? ''));
    $rawSummary = trim((string)($_POST['summary'] ?? ''));

    $combined   = $rawTitle . "\n\n" . $rawSummary;
    $translated = translateNewsTextToGerman($combined);
    $parts      = preg_split("/\n\s*\n/", $translated, 2) ?: [$translated];

    echo json_encode([
        'ok'      => true,
        'title'   => trim($parts[0] ?? $rawTitle),
        'summary' => trim($parts[1] ?? ($parts[0] ?? $rawSummary)),
    ]);
    exit;
}

if ($action === 'ai_briefing' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $headlinesJson = (string)($_POST['headlines'] ?? '[]');
    $headlines     = json_decode($headlinesJson, true);
    if (!is_array($headlines) || empty($headlines)) {
        echo json_encode(['ok' => false, 'briefing' => 'Keine Schlagzeilen zum Zusammenfassen vorhanden.']);
        exit;
    }

    $topSlice = array_slice($headlines, 0, 10);
    $bulletLines = [];
    foreach ($topSlice as $idx => $hl) {
        $src = trim((string)($hl['source'] ?? 'News'));
        $ttl = trim((string)($hl['title'] ?? ''));
        $sum = trim((string)($hl['summary'] ?? ''));
        if ($ttl === '') {
            continue;
        }
        // Falls englisch, automatisch ins Deutsche übersetzen
        if (($hl['lang'] ?? 'de') !== 'de') {
            $ttl = translateNewsTextToGerman($ttl);
            if ($sum !== '') {
                $sum = translateNewsTextToGerman(mb_substr($sum, 0, 220));
            }
        }
        $shortSum = $sum !== '' ? (' – ' . mb_substr($sum, 0, 160) . (mb_strlen($sum) > 160 ? '…' : '')) : '';
        $bulletLines[] = "• **{$src}:** {$ttl}{$shortSum}";
    }

    // Optionaler Versuch über Gemini API, falls ein Key in den Einstellungen hinterlegt ist
    $geminiKey   = trim((string)($appSettings['assistant_gemini_api_key'] ?? ''));
    $geminiModel = trim((string)($appSettings['assistant_gemini_model'] ?? 'gemini-2.5-flash')) ?: 'gemini-2.5-flash';
    $aiText      = '';

    if ($geminiKey !== '' && function_exists('curl_init')) {
        $prompt = "Fasse die folgenden aktuellen Nachrichten-Schlagzeilen als kurzes, prägnantes Nachrichten-Briefing auf Deutsch (maximal 6 übersichtliche Stichpunkte mit passendem Emoji) zusammen:\n\n" . implode("\n", $bulletLines);
        $gemUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($geminiModel) . ':generateContent?key=' . rawurlencode($geminiKey);
        $ch = curl_init($gemUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
            ]),
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($res) && $code >= 200 && $code < 300) {
            $dec = json_decode($res, true);
            $cand = trim((string)($dec['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            if ($cand !== '') {
                $aiText = $cand;
            }
        }
    }

    if ($aiText === '') {
        $aiText = "📋 **Kompakt-Überblick der wichtigsten Meldungen (" . date('d.m.Y, H:i') . " Uhr):**\n\n" . implode("\n", array_slice($bulletLines, 0, 7));
    }

    echo json_encode(['ok' => true, 'briefing' => $aiText]);
    exit;
}

// ============================================================================
// 4. KURATIERTE NACHRICHTEN-QUELLEN & THEMEN-KATEGORIEN
// ============================================================================
$newsCategories = [
    'top' => [
        'label' => '⚡ Top-Schlagzeilen',
        'desc'  => 'Die wichtigsten aktuellen Meldungen aus Deutschland und der Welt im Überblick',
        'feeds' => [
            ['id' => 'tagesschau_top', 'name' => 'Tagesschau',      'icon' => '🏛️', 'lang' => 'de', 'color' => 'bg-blue-600',   'url' => 'https://www.tagesschau.de/infoservices/alle-meldungen-100~rss2.xml'],
            ['id' => 'zdf_heute',      'name' => 'ZDFheute',        'icon' => '🟠', 'lang' => 'de', 'color' => 'bg-amber-600',  'url' => 'https://www.zdf.de/rss/zdf/nachrichten'],
            ['id' => 'dlf_news',       'name' => 'Deutschlandfunk', 'icon' => '📻', 'lang' => 'de', 'color' => 'bg-sky-700',    'url' => 'https://www.deutschlandfunk.de/nachrichten-100.rss'],
            ['id' => 'spiegel_top',    'name' => 'DER SPIEGEL',     'icon' => '🟥', 'lang' => 'de', 'color' => 'bg-red-600',    'url' => 'https://www.spiegel.de/schlagzeilen/tops/index.rss'],
            ['id' => 'zeit_top',       'name' => 'ZEIT ONLINE',     'icon' => '🗞️', 'lang' => 'de', 'color' => 'bg-slate-800',  'url' => 'https://newsfeed.zeit.de/index'],
        ],
    ],
    'world' => [
        'label' => '🌍 Welt & International',
        'desc'  => 'Internationale Perspektiven & Weltpolitik – englische Meldungen mit 1-Klick-Deutsch-Übersetzung',
        'feeds' => [
            ['id' => 'tagesschau_ausl','name' => 'Tagesschau Ausland', 'icon' => '🌍', 'lang' => 'de', 'color' => 'bg-blue-600',   'url' => 'https://www.tagesschau.de/ausland/index~rss2.xml'],
            ['id' => 'dw_world',       'name' => 'Deutsche Welle',     'icon' => '🌐', 'lang' => 'de', 'color' => 'bg-cyan-700',   'url' => 'https://rss.dw.com/xml/rss-de-all'],
            ['id' => 'bbc_world',      'name' => 'BBC World News',     'icon' => '🇬🇧', 'lang' => 'en', 'color' => 'bg-rose-700',   'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml'],
            ['id' => 'guardian_world', 'name' => 'The Guardian World', 'icon' => '🇬🇧', 'lang' => 'en', 'color' => 'bg-indigo-700', 'url' => 'https://www.theguardian.com/world/rss'],
            ['id' => 'nyt_world',      'name' => 'New York Times',     'icon' => '🇺🇸', 'lang' => 'en', 'color' => 'bg-slate-900',  'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/World.xml'],
        ],
    ],
    'regional' => [
        'label' => '📍 NRW & Deutschland',
        'desc'  => 'Regionale Meldungen aus Nordrhein-Westfalen sowie Innenpolitik & Gesellschaft',
        'feeds' => [
            ['id' => 'wdr_nrw',        'name' => 'WDR Aktuell (NRW)',  'icon' => '📍', 'lang' => 'de', 'color' => 'bg-blue-700',   'url' => 'https://www1.wdr.de/uebersicht-100.feed'],
            ['id' => 'tagesschau_inl', 'name' => 'Tagesschau Inland',  'icon' => '🇩🇪', 'lang' => 'de', 'color' => 'bg-blue-600',   'url' => 'https://www.tagesschau.de/inland/index~rss2.xml'],
            ['id' => 'sz_top',         'name' => 'Süddeutsche Zeitung','icon' => '📰', 'lang' => 'de', 'color' => 'bg-teal-700',   'url' => 'https://rss.sueddeutsche.de/rss/Topthemen'],
        ],
    ],
    'tech' => [
        'label' => '💻 Technik, IT & Wissen',
        'desc'  => 'Digitales, Künstliche Intelligenz, IT-Sicherheit, Forschung & Wissenschaft',
        'feeds' => [
            ['id' => 'heise_top',      'name' => 'heise online',       'icon' => '💻', 'lang' => 'de', 'color' => 'bg-indigo-600', 'url' => 'https://www.heise.de/rss/heise-atom.xml'],
            ['id' => 'golem_top',      'name' => 'Golem.de',           'icon' => '🖥️', 'lang' => 'de', 'color' => 'bg-emerald-700','url' => 'https://rss.golem.de/rss.php?feed=ATOM1.0'],
            ['id' => 'ts_wissen',      'name' => 'Tagesschau Wissen',  'icon' => '🔬', 'lang' => 'de', 'color' => 'bg-purple-600', 'url' => 'https://www.tagesschau.de/wissen/index~rss2.xml'],
        ],
    ],
    'economy' => [
        'label' => '💼 Wirtschaft & Börse',
        'desc'  => 'Wirtschaftsnachrichten, Energie, Unternehmen, Verbraucher & Finanzmärkte',
        'feeds' => [
            ['id' => 'ts_wirtschaft',  'name' => 'Tagesschau Wirtschaft','icon' => '📈', 'lang' => 'de', 'color' => 'bg-emerald-600','url' => 'https://www.tagesschau.de/wirtschaft/index~rss2.xml'],
            ['id' => 'sp_wirtschaft',  'name' => 'SPIEGEL Wirtschaft',   'icon' => '💼', 'lang' => 'de', 'color' => 'bg-red-600',    'url' => 'https://www.spiegel.de/wirtschaft/index.rss'],
        ],
    ],
    'sport' => [
        'label' => '⚽ Sport-News',
        'desc'  => 'Aktuelle Sport-Schlagzeilen zu Fußball, Bundesliga, Eishockey, Formel 1 & mehr',
        'feeds' => [
            ['id' => 'sportschau',     'name' => 'Sportschau',         'icon' => '🏆', 'lang' => 'de', 'color' => 'bg-green-600',  'url' => 'https://www.tagesschau.de/sport/index~rss2.xml'],
            ['id' => 'kicker_news',    'name' => 'kicker',             'icon' => '⚽', 'lang' => 'de', 'color' => 'bg-red-700',    'url' => 'https://newsfeed.kicker.de/news/aktuell'],
            ['id' => 'spiegel_sport',  'name' => 'SPIEGEL Sport',      'icon' => '🥇', 'lang' => 'de', 'color' => 'bg-orange-600', 'url' => 'https://www.spiegel.de/sport/index.rss'],
        ],
    ],
];

// Eigene RSS-Feeds des Benutzers laden
$customFeedsStmt = $pdo->prepare("SELECT * FROM news_custom_feeds WHERE user_id = ? ORDER BY name ASC");
$customFeedsStmt->execute([$userId]);
$myCustomFeeds = $customFeedsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($myCustomFeeds)) {
    $customFeedList = [];
    foreach ($myCustomFeeds as $cf) {
        $customFeedList[] = [
            'id'    => 'custom_' . (int)$cf['id'],
            'name'  => (string)$cf['name'],
            'icon'  => '⭐',
            'lang'  => (string)($cf['lang'] ?: 'de'),
            'color' => 'bg-amber-600',
            'url'   => (string)$cf['feed_url'],
        ];
    }
    $newsCategories['custom'] = [
        'label' => '⭐ Meine RSS-Feeds (' . count($customFeedList) . ')',
        'desc'  => 'Deine persönlich hinzugefügten Nachrichten- und Blog-Feeds',
        'feeds' => $customFeedList,
    ];
}

// Gespeicherte Lesezeichen („Später lesen“) zählen
$bmStmt = $pdo->prepare("SELECT * FROM news_bookmarks WHERE user_id = ? ORDER BY created_at DESC");
$bmStmt->execute([$userId]);
$myBookmarks = $bmStmt->fetchAll(PDO::FETCH_ASSOC);
$bookmarkedUrls = array_column($myBookmarks, 'link');

$activeCategory = strtolower(trim((string)($_GET['cat'] ?? $_POST['cat'] ?? 'top')));
if ($activeCategory !== 'saved' && !isset($newsCategories[$activeCategory])) {
    $activeCategory = 'top';
}

// ============================================================================
// 5. POST-AKTIONEN (LESEZEICHEN & EIGENE RSS-FEEDS)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Ungültiges Sicherheits-Token. Bitte versuche es erneut.');
        header('Location: news.php?cat=' . urlencode($activeCategory));
        exit;
    }

    // 5a) Artikel auf die Merkliste setzen / entfernen
    if ($action === 'toggle_bookmark') {
        $bmTitle  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 290);
        $bmLink   = mb_substr(trim((string)($_POST['link'] ?? '')), 0, 580);
        $bmSource = mb_substr(trim((string)($_POST['source_name'] ?? '')), 0, 110);
        $bmSum    = mb_substr(trim((string)($_POST['summary'] ?? '')), 0, 1500);
        $bmImg    = mb_substr(trim((string)($_POST['image_url'] ?? '')), 0, 580);

        if ($bmLink !== '' && $bmTitle !== '') {
            $chk = $pdo->prepare("SELECT id FROM news_bookmarks WHERE user_id = ? AND link = ? LIMIT 1");
            $chk->execute([$userId, $bmLink]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->prepare("DELETE FROM news_bookmarks WHERE id = ? AND user_id = ?")->execute([(int)$existing['id'], $userId]);
                setFlash('success', 'Artikel aus deiner Merkliste entfernt.');
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO news_bookmarks (user_id, title, link, source_name, summary, image_url, pub_date)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([$userId, $bmTitle, $bmLink, $bmSource, $bmSum, $bmImg]);
                setFlash('success', '🔖 Artikel in deiner Merkliste („Später lesen“) gespeichert!');
            }
        }
        header('Location: news.php?cat=' . urlencode($activeCategory));
        exit;
    }

    // 5b) Eigenen RSS-Feed hinzufügen
    if ($action === 'add_custom_feed') {
        $fName = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 110);
        $fUrl  = mb_substr(trim((string)($_POST['feed_url'] ?? '')), 0, 480);
        $fLang = in_array($_POST['lang'] ?? 'de', ['de', 'en'], true) ? (string)$_POST['lang'] : 'de';

        if ($fName === '' || !isSafeNewsFeedUrl($fUrl)) {
            setFlash('error', 'Bitte gib einen gültigen Namen und eine öffentliche RSS-/Atom-URL (http/https) an.');
        } else {
            $ins = $pdo->prepare("INSERT INTO news_custom_feeds (user_id, name, feed_url, lang) VALUES (?, ?, ?, ?)");
            $ins->execute([$userId, $fName, $fUrl, $fLang]);
            setFlash('success', "⭐ RSS-Feed „{$fName}“ wurde hinzugefügt!");
            header('Location: news.php?cat=custom');
            exit;
        }
        header('Location: news.php?cat=' . urlencode($activeCategory));
        exit;
    }

    // 5c) Eigenen RSS-Feed löschen
    if ($action === 'delete_custom_feed') {
        $feedId = (int)($_POST['feed_id'] ?? 0);
        if ($feedId > 0) {
            $pdo->prepare("DELETE FROM news_custom_feeds WHERE id = ? AND user_id = ?")->execute([$feedId, $userId]);
            setFlash('success', 'Eigener RSS-Feed wurde entfernt.');
        }
        header('Location: news.php?cat=top');
        exit;
    }
}

// ============================================================================
// 6. RSS- & ATOM-FEED-PARSER MIT PARALLEL-ABRUF & MARIADB-CACHE
// ============================================================================
function parseRssOrAtomFeedXml(string $rawXml, array $feedMeta): array
{
    $rawXml = trim($rawXml);
    if ($rawXml === '') {
        return [];
    }

    $xml = @simplexml_load_string($rawXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($xml === false) {
        return [];
    }

    $articles   = [];
    $sourceName = (string)($feedMeta['name'] ?? 'News');
    $sourceId   = (string)($feedMeta['id'] ?? 'news');
    $sourceIcon = (string)($feedMeta['icon'] ?? '📰');
    $sourceLang = (string)($feedMeta['lang'] ?? 'de');
    $sourceCol  = (string)($feedMeta['color'] ?? 'bg-sky-600');

    // Fall A: Klassisches RSS 2.0 (<channel><item>)
    if (isset($xml->channel->item)) {
        $count = 0;
        foreach ($xml->channel->item as $item) {
            if ($count >= 14) {
                break;
            }
            $title = trim(html_entity_decode(strip_tags((string)$item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link  = trim((string)$item->link);
            if ($title === '' || $link === '') {
                continue;
            }

            $rawDesc = (string)($item->description ?? '');
            $contentNs = $item->children('http://purl.org/rss/1.0/modules/content/');
            $mediaNs   = $item->children('http://search.yahoo.com/mrss/');
            $dcNs      = $item->children('http://purl.org/dc/elements/1.1/');

            // Vorschaubild ermitteln
            $img = '';
            if (isset($item->enclosure['url'])) {
                $encType = strtolower((string)($item->enclosure['type'] ?? 'image'));
                if (str_contains($encType, 'image') || preg_match('/\.(jpe?g|png|webp)/i', (string)$item->enclosure['url'])) {
                    $img = trim((string)$item->enclosure['url']);
                }
            }
            if ($img === '' && isset($mediaNs->content)) {
                foreach ($mediaNs->content as $mc) {
                    $attr = $mc->attributes();
                    if (!empty($attr['url'])) {
                        $img = trim((string)$attr['url']);
                        break;
                    }
                }
            }
            if ($img === '' && isset($mediaNs->thumbnail)) {
                $attr = $mediaNs->thumbnail->attributes();
                if (!empty($attr['url'])) {
                    $img = trim((string)$attr['url']);
                }
            }
            if ($img === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawDesc . ' ' . (string)($contentNs->encoded ?? ''), $mImg)) {
                $img = trim($mImg[1]);
            }

            $summary = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
            $pubRaw  = (string)($item->pubDate ?? $dcNs->date ?? '');
            $pubTs   = $pubRaw !== '' ? (strtotime($pubRaw) ?: time()) : time();

            $articles[] = [
                'source_id'   => $sourceId,
                'source_name' => $sourceName,
                'source_icon' => $sourceIcon,
                'source_col'  => $sourceCol,
                'lang'        => $sourceLang,
                'title'       => $title,
                'summary'     => mb_substr($summary, 0, 360),
                'link'        => $link,
                'image'       => $img,
                'timestamp'   => $pubTs,
            ];
            $count++;
        }
        return $articles;
    }

    // Fall B: Atom-Feed (<entry> z.B. Heise, Golem, WDR)
    if (isset($xml->entry)) {
        $count = 0;
        foreach ($xml->entry as $entry) {
            if ($count >= 14) {
                break;
            }
            $title = trim(html_entity_decode(strip_tags((string)$entry->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link  = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $lnk) {
                    $attr = $lnk->attributes();
                    $rel  = (string)($attr['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $link === '') {
                        $link = trim((string)($attr['href'] ?? ''));
                    }
                }
            }
            if ($title === '' || $link === '') {
                continue;
            }

            $rawSum = (string)($entry->summary ?? $entry->content ?? '');
            $img    = '';
            if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawSum, $mImg)) {
                $img = trim($mImg[1]);
            }

            $summary = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($rawSum, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
            $pubRaw  = (string)($entry->updated ?? $entry->published ?? '');
            $pubTs   = $pubRaw !== '' ? (strtotime($pubRaw) ?: time()) : time();

            $articles[] = [
                'source_id'   => $sourceId,
                'source_name' => $sourceName,
                'source_icon' => $sourceIcon,
                'source_col'  => $sourceCol,
                'lang'        => $sourceLang,
                'title'       => $title,
                'summary'     => mb_substr($summary, 0, 360),
                'link'        => $link,
                'image'       => $img,
                'timestamp'   => $pubTs,
            ];
            $count++;
        }
    }

    return $articles;
}

/**
 * Lädt alle Feeds einer Kategorie (mit 5 Minuten Server-Cache und cURL-Multi-Parallelabruf).
 */
function fetchCategoryNewsArticles(PDO $pdo, array $feeds, bool $forceRefresh = false): array
{
    $allArticles = [];
    $toFetch     = [];
    $latestUpdate = date('Y-m-d H:i:s');

    $stmtCache = $pdo->prepare("SELECT payload, updated_at FROM news_cache WHERE cache_key = ?");

    foreach ($feeds as $f) {
        $cacheKey = 'news_feed_' . md5((string)$f['url']);
        $stmtCache->execute([$cacheKey]);
        $row = $stmtCache->fetch(PDO::FETCH_ASSOC);

        if ($row && !$forceRefresh) {
            $age = time() - (int)strtotime((string)$row['updated_at']);
            if ($age >= 0 && $age <= 300) {
                $decoded = json_decode((string)$row['payload'], true);
                if (is_array($decoded)) {
                    $allArticles  = array_merge($allArticles, $decoded);
                    $latestUpdate = (string)$row['updated_at'];
                    continue;
                }
            }
        }
        $toFetch[$cacheKey] = ['meta' => $f, 'fallback' => $row];
    }

    if (!empty($toFetch)) {
        $rawResponses = [];

        if (function_exists('curl_multi_init')) {
            $mh      = curl_multi_init();
            $handles = [];

            foreach ($toFetch as $cKey => $info) {
                $url = (string)$info['meta']['url'];
                if (!isSafeNewsFeedUrl($url)) {
                    continue;
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; MiniCloud-NewsReader/1.0)',
                    CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*'],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$cKey] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running > 0);

            foreach ($handles as $cKey => $ch) {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
                    $rawResponses[$cKey] = $body;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        } else {
            foreach ($toFetch as $cKey => $info) {
                $url = (string)$info['meta']['url'];
                if (!isSafeNewsFeedUrl($url)) {
                    continue;
                }
                $ctx = stream_context_create([
                    'http' => [
                        'method'  => 'GET',
                        'timeout' => 4,
                        'header'  => "User-Agent: MiniCloud-NewsReader/1.0\r\n",
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if (is_string($body) && $body !== '') {
                    $rawResponses[$cKey] = $body;
                }
            }
        }

        $upStmt = $pdo->prepare("
            INSERT INTO news_cache (cache_key, payload, updated_at)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)
        ");
        $nowStr = date('Y-m-d H:i:s');

        foreach ($toFetch as $cKey => $info) {
            if (isset($rawResponses[$cKey])) {
                $parsed = parseRssOrAtomFeedXml($rawResponses[$cKey], $info['meta']);
                if (!empty($parsed)) {
                    $upStmt->execute([$cKey, json_encode($parsed, JSON_UNESCAPED_UNICODE), $nowStr]);
                    $allArticles  = array_merge($allArticles, $parsed);
                    $latestUpdate = $nowStr;
                    continue;
                }
            }
            if (!empty($info['fallback']['payload'])) {
                $fallbackDec = json_decode((string)$info['fallback']['payload'], true);
                if (is_array($fallbackDec)) {
                    $allArticles = array_merge($allArticles, $fallbackDec);
                }
            }
        }
    }

    // Nach Erscheinungsdatum absteigend sortieren (neueste Meldungen zuerst)
    usort($allArticles, static fn(array $a, array $b): int => ((int)($b['timestamp'] ?? 0)) <=> ((int)($a['timestamp'] ?? 0)));

    return [
        'articles'   => $allArticles,
        'updated_at' => $latestUpdate,
    ];
}

/**
 * Formatiert einen Unix-Zeitstempel als lesbare relative Zeitangabe („vor 12 Min.“, „Heute 14:30“).
 */
function formatNewsRelativeTime(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'gerade eben';
    }
    if ($diff < 3600) {
        return 'vor ' . max(1, (int)floor($diff / 60)) . ' Min.';
    }
    if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d')) {
        return 'Heute, ' . date('H:i', $ts) . ' Uhr';
    }
    return date('d.m., H:i', $ts) . ' Uhr';
}

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$articles     = [];
$updatedAt    = date('Y-m-d H:i:s');
$currentFeeds = [];

if ($activeCategory === 'saved') {
    foreach ($myBookmarks as $bm) {
        $articles[] = [
            'source_id'   => 'saved',
            'source_name' => (string)($bm['source_name'] ?: 'Gespeichert'),
            'source_icon' => '🔖',
            'source_col'  => 'bg-amber-600',
            'lang'        => 'de',
            'title'       => (string)$bm['title'],
            'summary'     => (string)$bm['summary'],
            'link'        => (string)$bm['link'],
            'image'       => (string)$bm['image_url'],
            'timestamp'   => strtotime((string)($bm['created_at'] ?? 'now')) ?: time(),
        ];
    }
} else {
    $currentFeeds = $newsCategories[$activeCategory]['feeds'] ?? [];
    $res          = fetchCategoryNewsArticles($pdo, $currentFeeds, $forceRefresh);
    $articles     = $res['articles'];
    $updatedAt    = $res['updated_at'];
}

// Für das KI-Briefing & Vorlesen die Top-12-Schlagzeilen als JSON bereitstellen
$briefingPayload = [];
foreach (array_slice($articles, 0, 12) as $art) {
    $briefingPayload[] = [
        'source'  => $art['source_name'],
        'title'   => $art['title'],
        'summary' => $art['summary'],
        'lang'    => $art['lang'],
    ];
}

$csrfToken = getCsrfToken();
$pageTitle = '📰 Welt-Nachrichten & News-Ticker';
require_once __DIR__ . '/includes/header.php';
?>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <!-- ================================================================== -->
    <!-- 1. KOPFBEREICH & KI-BRIEFING / AUDIO-SPRECHER                      -->
    <!-- ================================================================== -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm p-5 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-sky-500 via-blue-600 to-indigo-700 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                    📰
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                            Welt-Nachrichten &amp; Live-Ticker
                        </h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-sky-100 dark:bg-sky-950/80 text-sky-800 dark:text-sky-300 border border-sky-200 dark:border-sky-800">
                            DE &amp; International • 1-Klick Übersetzer
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Aktuelle Schlagzeilen aus Deutschland, NRW und aller Welt – mit KI-Kurzfassung, Sprachausgabe und Sofort-Übersetzung.
                    </p>
                </div>
            </div>

            <!-- Aktions-Buttons: KI-Briefing, Schlagzeilen vorlesen, Eigener Feed -->
            <div class="flex flex-wrap items-center gap-2">
                <?php if (!empty($articles)): ?>
                    <button type="button" onclick="generateAiNewsBriefing()"
                            id="btnAiBriefing"
                            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-xs font-extrabold shadow-xs cursor-pointer transition-all">
                        <span>🤖</span>
                        <span>KI-Kurzfassung</span>
                    </button>

                    <button type="button" onclick="speakAllTopHeadlines()"
                            id="btnSpeakHeadlines"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-sky-50 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer transition-colors">
                        <span>🔊</span>
                        <span id="lblSpeakHeadlines">Schlagzeilen vorlesen</span>
                    </button>
                <?php endif; ?>

                <button type="button" onclick="document.getElementById('customFeedModalBox').classList.toggle('hidden')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                    <span>➕</span>
                    <span>Eigener RSS-Feed</span>
                </button>
            </div>
        </div>

        <!-- Aufklappbares Formular für eigene RSS-Feeds -->
        <form id="customFeedModalBox" method="post" action="news.php?cat=<?= urlencode($activeCategory) ?>"
              class="hidden p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="add_custom_feed">
            <div class="sm:col-span-4">
                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Quellen-Name *</label>
                <input type="text" name="name" required placeholder="z. B. Lokalzeitung / Tech-Blog"
                       class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
            </div>
            <div class="sm:col-span-5">
                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">RSS- oder Atom-Feed-URL *</label>
                <input type="url" name="feed_url" required placeholder="https://.../rss.xml"
                       class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">Sprache</label>
                <select name="lang" class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs">
                    <option value="de">🇩🇪 Deutsch</option>
                    <option value="en">🌐 Englisch / Fremdsprache</option>
                </select>
            </div>
            <div class="sm:col-span-1">
                <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold cursor-pointer">
                    Hinzufügen
                </button>
            </div>
        </form>

        <!-- KI-Kurzfassungs-Box (wird bei Klick auf „🤖 KI-Kurzfassung“ eingeblendet) -->
        <div id="aiBriefingBox" class="hidden rounded-2xl bg-gradient-to-br from-indigo-950 via-slate-900 to-slate-900 text-white p-5 border border-indigo-500/30 shadow-md space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-extrabold uppercase tracking-wider text-indigo-300 flex items-center gap-1.5">
                    <span>🤖</span>
                    <span>KI-Nachrichten-Briefing (<?= e($newsCategories[$activeCategory]['label'] ?? 'Merkliste') ?>)</span>
                </span>
                <button type="button" onclick="document.getElementById('aiBriefingBox').classList.add('hidden')"
                        class="text-xs text-slate-400 hover:text-white cursor-pointer">✕ Schließen</button>
            </div>
            <div id="aiBriefingContent" class="text-xs sm:text-sm text-slate-100 leading-relaxed whitespace-pre-line"></div>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- 2. KATEGORIEN-TABS, QUELLEN-FILTER & SUCHE                         -->
    <!-- ================================================================== -->
    <section class="space-y-5">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-4 shadow-sm space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <!-- Themen-Kategorien -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach ($newsCategories as $catKey => $catCfg): ?>
                        <?php $isActive = ($catKey === $activeCategory); ?>
                        <a href="news.php?cat=<?= urlencode($catKey) ?>"
                           class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $isActive ? 'bg-sky-600 text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
                            <?= e($catCfg['label']) ?>
                        </a>
                    <?php endforeach; ?>

                    <!-- Merkliste („Später lesen“) -->
                    <a href="news.php?cat=saved"
                       class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $activeCategory === 'saved' ? 'bg-amber-500 text-white shadow-xs' : 'bg-amber-50 dark:bg-amber-950/50 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 hover:bg-amber-100' ?>">
                        🔖 Merkliste (<?= count($myBookmarks) ?>)
                    </a>
                </div>

                <!-- Stand & Aktualisieren -->
                <?php if ($activeCategory !== 'saved'): ?>
                    <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <span>Stand: <?= date('H:i', (int)strtotime($updatedAt)) ?> Uhr</span>
                        <a href="news.php?cat=<?= urlencode($activeCategory) ?>&amp;refresh=1"
                           class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-sky-50 dark:hover:bg-slate-700 text-sky-600 dark:text-sky-400 font-bold">
                            🔄 Aktualisieren
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quellen-Pills & Freitextsuche -->
            <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" onclick="filterNewsBySource('ALL')" data-news-src-btn="ALL"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-900 dark:bg-white text-white dark:text-slate-900 cursor-pointer">
                        Alle Quellen (<?= count($articles) ?>)
                    </button>
                    <?php foreach ($currentFeeds as $fMeta): ?>
                        <button type="button" onclick="filterNewsBySource('<?= e($fMeta['id']) ?>')" data-news-src-btn="<?= e($fMeta['id']) ?>"
                                class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer flex items-center gap-1">
                            <span><?= $fMeta['icon'] ?></span>
                            <span><?= e($fMeta['name']) ?></span>
                            <?php if (($fMeta['lang'] ?? 'de') !== 'de'): ?>
                                <span class="text-[9px] uppercase px-1 rounded bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold"><?= e(strtoupper($fMeta['lang'])) ?></span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="w-full sm:w-64">
                    <input type="text" id="newsSearchInput" oninput="applyNewsFilters()"
                           placeholder="Schlagzeilen oder Stichwort suchen…"
                           class="w-full px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-sky-500">
                </div>
            </div>
        </div>

        <!-- ================================================================== -->
        <!-- 3. NACHRICHTEN-KARTEN RASTER                                       -->
        <!-- ================================================================== -->
        <?php if (empty($articles)): ?>
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-10 text-center space-y-2">
                <div class="text-3xl"><?= $activeCategory === 'saved' ? '🔖' : '📰' ?></div>
                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">
                    <?= $activeCategory === 'saved' ? 'Deine Merkliste ist noch leer.' : 'Momentan konnten keine Meldungen geladen werden.' ?>
                </p>
                <p class="text-xs text-slate-500">
                    <?= $activeCategory === 'saved' ? 'Klicke bei einem Nachrichtenartikel auf „🔖 Merken“, um ihn für später zu speichern.' : 'Bitte klicke oben rechts auf „🔄 Aktualisieren“.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="newsArticlesGrid">
                <?php foreach ($articles as $idx => $art): ?>
                    <?php
                    $isSaved   = in_array($art['link'], $bookmarkedUrls, true);
                    $isForeign = (($art['lang'] ?? 'de') !== 'de');
                    $searchHay = mb_strtolower($art['source_name'] . ' ' . $art['title'] . ' ' . $art['summary'], 'UTF-8');
                    $cardDomId = 'newsCard_' . $idx;
                    ?>
                    <article id="<?= $cardDomId ?>"
                             class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col justify-between transition-all hover:shadow-md"
                             data-news-card
                             data-source="<?= e($art['source_id']) ?>"
                             data-search="<?= e($searchHay) ?>">
                        <div>
                            <!-- Kopfzeile: Quelle + Zeitstempel + Vorlesen -->
                            <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="px-2 py-0.5 rounded-md text-white text-[11px] font-extrabold shrink-0 <?= e($art['source_col']) ?>">
                                        <?= $art['source_icon'] ?> <?= e($art['source_name']) ?>
                                    </span>
                                    <?php if ($isForeign): ?>
                                        <span class="px-1.5 py-0.2 rounded text-[10px] font-bold uppercase bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300">
                                            <?= e(strtoupper($art['lang'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="text-[11px] text-slate-400 font-medium">
                                        <?= e(formatNewsRelativeTime((int)$art['timestamp'])) ?>
                                    </span>
                                    <button type="button"
                                            onclick="speakSingleArticle('<?= $cardDomId ?>', '<?= e($art['lang']) ?>')"
                                            title="Meldung vorlesen"
                                            class="p-1 rounded-lg text-slate-400 hover:text-sky-600 dark:hover:text-sky-400 text-xs cursor-pointer">
                                        🔊
                                    </button>
                                </div>
                            </div>

                            <!-- Vorschaubild (falls im RSS-Feed enthalten) -->
                            <?php if ($art['image'] !== ''): ?>
                                <a href="<?= e($art['link']) ?>" target="_blank" rel="noopener noreferrer" class="block overflow-hidden bg-slate-100 dark:bg-slate-800">
                                    <img src="<?= e($art['image']) ?>" alt=""
                                         loading="lazy" referrerpolicy="no-referrer"
                                         onerror="this.parentElement.style.display='none'"
                                         class="w-full h-40 object-cover hover:scale-105 transition-transform duration-300">
                                </a>
                            <?php endif; ?>

                            <!-- Schlagzeile & Anreißer -->
                            <div class="p-4 space-y-2">
                                <h3 class="text-sm sm:text-base font-extrabold text-slate-900 dark:text-white leading-snug">
                                    <a href="<?= e($art['link']) ?>" target="_blank" rel="noopener noreferrer"
                                       class="hover:text-sky-600 dark:hover:text-sky-400 transition-colors"
                                       data-art-title>
                                        <?= e($art['title']) ?>
                                    </a>
                                </h3>
                                <?php if ($art['summary'] !== ''): ?>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 leading-relaxed line-clamp-4" data-art-summary>
                                        <?= e($art['summary']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Fußzeile: Original-Artikel, 1-Klick-Deutsch-Übersetzer & Merkliste -->
                        <div class="px-4 py-2.5 bg-slate-50/50 dark:bg-slate-800/30 border-t border-slate-100 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <a href="<?= e($art['link']) ?>" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 text-sky-700 dark:text-sky-300 text-[11px] font-bold transition-colors">
                                    <span>📖</span>
                                    <span>Artikel lesen ↗</span>
                                </a>

                                <?php if ($isForeign): ?>
                                    <button type="button"
                                            onclick="translateArticleCard('<?= $cardDomId ?>', this)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 text-[11px] font-bold cursor-pointer transition-colors">
                                        <span>🇩🇪</span>
                                        <span>Auf Deutsch</span>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <!-- Auf Merkliste speichern -->
                            <form method="post" action="news.php?cat=<?= urlencode($activeCategory) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="toggle_bookmark">
                                <input type="hidden" name="title" value="<?= e($art['title']) ?>">
                                <input type="hidden" name="link" value="<?= e($art['link']) ?>">
                                <input type="hidden" name="source_name" value="<?= e($art['source_name']) ?>">
                                <input type="hidden" name="summary" value="<?= e($art['summary']) ?>">
                                <input type="hidden" name="image_url" value="<?= e($art['image']) ?>">
                                <button type="submit"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-semibold cursor-pointer <?= $isSaved ? 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800 hover:bg-amber-50 text-slate-600 dark:text-slate-300' ?>">
                                    <span>🔖</span>
                                    <span><?= $isSaved ? 'Gemerkt' : 'Merken' ?></span>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<script>
const NEWS_CSRF      = <?= json_encode($csrfToken) ?>;
const NEWS_HEADLINES = <?= json_encode($briefingPayload, JSON_UNESCAPED_UNICODE) ?>;

let activeSourceFilter = 'ALL';
let isSpeakingHeadlines = false;

// 1. Quellen- und Textfilter
function filterNewsBySource(sourceId) {
    activeSourceFilter = sourceId;
    document.querySelectorAll('[data-news-src-btn]').forEach((btn) => {
        const isSel = btn.getAttribute('data-news-src-btn') === sourceId;
        btn.className = isSel
            ? 'px-2.5 py-1 rounded-lg text-xs font-bold bg-slate-900 dark:bg-white text-white dark:text-slate-900 cursor-pointer flex items-center gap-1'
            : 'px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 cursor-pointer flex items-center gap-1';
    });
    applyNewsFilters();
}

function applyNewsFilters() {
    const q = (document.getElementById('newsSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('[data-news-card]').forEach((card) => {
        const src = card.getAttribute('data-source') || '';
        const hay = card.getAttribute('data-search') || '';
        const matchSrc = (activeSourceFilter === 'ALL' || src === activeSourceFilter);
        const matchQ   = (q === '' || hay.includes(q));
        card.classList.toggle('hidden', !(matchSrc && matchQ));
    });
}

// 2. 1-Klick Sofort-Übersetzung für englischsprachige Artikel
async function translateArticleCard(cardId, btnEl) {
    const card = document.getElementById(cardId);
    if (!card) return;

    const titleEl   = card.querySelector('[data-art-title]');
    const summaryEl = card.querySelector('[data-art-summary]');
    if (!titleEl) return;

    const origText = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '⏳ Übersetze…';

    try {
        const fd = new FormData();
        fd.append('action', 'translate_article');
        fd.append('csrf_token', NEWS_CSRF);
        fd.append('title', titleEl.textContent.trim());
        fd.append('summary', summaryEl ? summaryEl.textContent.trim() : '');

        const res  = await fetch('news.php?action=translate_article', { method: 'POST', body: fd });
        const data = await res.json();

        if (data && data.ok) {
            titleEl.textContent = data.title;
            if (summaryEl && data.summary) {
                summaryEl.textContent = data.summary;
            }
            btnEl.innerHTML = '✅ Übersetzt (DE)';
        } else {
            btnEl.innerHTML = origText;
            btnEl.disabled = false;
        }
    } catch (e) {
        btnEl.innerHTML = origText;
        btnEl.disabled = false;
    }
}

// 3. 1-Klick KI-Tagesbriefing
async function generateAiNewsBriefing() {
    const box     = document.getElementById('aiBriefingBox');
    const content = document.getElementById('aiBriefingContent');
    if (!box || !content) return;

    box.classList.remove('hidden');
    content.textContent = '⏳ Erstelle kompakte Zusammenfassung der aktuellen Schlagzeilen…';

    try {
        const fd = new FormData();
        fd.append('action', 'ai_briefing');
        fd.append('csrf_token', NEWS_CSRF);
        fd.append('headlines', JSON.stringify(NEWS_HEADLINES));

        const res  = await fetch('news.php?action=ai_briefing', { method: 'POST', body: fd });
        const data = await res.json();
        if (data && data.ok && data.briefing) {
            content.textContent = data.briefing.replace(/\*\*/g, '');
        } else {
            content.textContent = 'Zusammenfassung konnte nicht geladen werden.';
        }
    } catch (e) {
        content.textContent = 'Fehler beim Erstellen der Zusammenfassung.';
    }
}

// 4. Sprachausgabe (Nachrichten-Sprecher per Web Speech API)
function speakSingleArticle(cardId, lang) {
    if (!('speechSynthesis' in window)) return;
    window.speechSynthesis.cancel();

    const card      = document.getElementById(cardId);
    if (!card) return;
    const titleEl   = card.querySelector('[data-art-title]');
    const summaryEl = card.querySelector('[data-art-summary]');
    const text      = (titleEl ? titleEl.textContent : '') + '. ' + (summaryEl ? summaryEl.textContent : '');

    const u = new SpeechSynthesisUtterance(text);
    u.lang  = (lang === 'en') ? 'en-GB' : 'de-DE';
    u.rate  = 1.0;
    window.speechSynthesis.speak(u);
}

function speakAllTopHeadlines() {
    if (!('speechSynthesis' in window)) return;
    const lbl = document.getElementById('lblSpeakHeadlines');

    if (isSpeakingHeadlines) {
        window.speechSynthesis.cancel();
        isSpeakingHeadlines = false;
        if (lbl) lbl.textContent = 'Schlagzeilen vorlesen';
        return;
    }

    window.speechSynthesis.cancel();
    isSpeakingHeadlines = true;
    if (lbl) lbl.textContent = '⏹️ Vorlesen stoppen';

    const topItems = NEWS_HEADLINES.slice(0, 8);
    const fullText = 'Hier sind die aktuellen Schlagzeilen. ' + topItems.map((it) => `${it.source} meldet: ${it.title}.`).join(' ');

    const u  = new SpeechSynthesisUtterance(fullText);
    u.lang   = 'de-DE';
    u.onend  = () => {
        isSpeakingHeadlines = false;
        if (lbl) lbl.textContent = 'Schlagzeilen vorlesen';
    };
    window.speechSynthesis.speak(u);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
