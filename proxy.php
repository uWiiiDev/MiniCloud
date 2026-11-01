<?php
declare(strict_types=1);

/**
 * MiniCloud – 🛡️ Proxy-Server, Werbeblocker & Reader-Studio (proxy.php)
 *
 * Funktionen:
 * 1. 🌐 Ad-Free Web-Proxy („Browser im Browser“):
 *    - Ruft Webseiten anonym über den MiniCloud-Server (runready.cloud) ab
 *    - Strenger SSRF-Schutz (blockiert lokale/interne IPs, Loopback, Link-Local & Metadaten-Endpunkte bei jedem Redirect-Hop)
 *    - Entfernt automatisch Werbe- & Tracking-Skripte (AdSense, DoubleClick, Taboola, Outbrain, Criteo, Google Analytics, Meta Pixel, IVW u.v.m.)
 *    - Unterdrückt Cookie-Consent- & Nerv-Banner (OneTrust, Cookiebot, Usercentrics, ConsentManager, Sourcepoint, Didomi, Borlabs)
 *    - Bereinigt Tracking-Parameter (utm_*, fbclid, gclid, msclkid, mc_eid) aus allen Links
 *    - Schreibt Links, Formulare, Bilder & Stylesheets um, sodass man nahtlos werbefrei weiterklicken kann
 * 2. 📖 Werbefreier Artikel-Lesemodus (Reader View):
 *    - Extrahiert Titel, Metadaten, Lesezeit, Haupttext & Artikelbilder komplett ohne Werbung und Seitenleisten
 *    - Vorlese-Funktion (Web Speech API), Schriftgrößen-Anpassung & 1-Klick-Export in die MiniCloud-Notizen
 * 3. 📡 Systemweiter AdBlock-Proxy (PAC-Autokonfiguration für Browser, PC & Smartphone):
 *    - Persönliche Auto-Proxy-URL (proxy.pac) mit Token-Schutz
 *    - Wahlweise „Smart AdBlock Blackhole“ (blockiert 450+ Werbe-/Tracking-Netzwerke direkt im Browser/OS)
 *    - Individuelle Blacklist & Whitelist sowie Live-Domain-Prüfer
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$pdo = getDB();

// =========================================================================
// DATENBANK-TABELLEN FÜR PROXY & ADBLOCKER INITIALISIEREN
// =========================================================================
function ensureProxyTables(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxy_user_settings (
            user_id INT NOT NULL PRIMARY KEY,
            pac_token VARCHAR(64) NOT NULL,
            block_ads TINYINT(1) NOT NULL DEFAULT 1,
            block_trackers TINYINT(1) NOT NULL DEFAULT 1,
            block_cookies TINYINT(1) NOT NULL DEFAULT 1,
            strip_tracking_params TINYINT(1) NOT NULL DEFAULT 1,
            block_external_scripts TINYINT(1) NOT NULL DEFAULT 0,
            allowed_client_ip VARCHAR(64) NOT NULL DEFAULT '',
            ads_blocked_total BIGINT NOT NULL DEFAULT 0,
            trackers_blocked_total BIGINT NOT NULL DEFAULT 0,
            cookies_blocked_total BIGINT NOT NULL DEFAULT 0,
            pages_proxied_total BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxy_custom_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            domain_pattern VARCHAR(255) NOT NULL,
            rule_type ENUM('block', 'allow') NOT NULL DEFAULT 'block',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_domain (user_id, domain_pattern),
            INDEX idx_user_type (user_id, rule_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxy_bookmarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(120) NOT NULL,
            url VARCHAR(1024) NOT NULL,
            icon VARCHAR(16) NOT NULL DEFAULT '🌐',
            default_mode ENUM('proxy', 'reader') NOT NULL DEFAULT 'proxy',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_bm (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proxy_block_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            category VARCHAR(24) NOT NULL DEFAULT 'ad',
            matched_rule VARCHAR(190) NOT NULL DEFAULT '',
            blocked_url VARCHAR(500) NOT NULL DEFAULT '',
            source_domain VARCHAR(190) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_user_cat (user_id, category),
            INDEX idx_user_rule (user_id, matched_rule)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

ensureProxyTables($pdo);

// =========================================================================
// KERN-SPERRLISTEN: WERBE-, TRACKING- & COOKIE-BANNER-NETZWERKE
// =========================================================================
function getCoreAdDomains(): array
{
    return [
        // Google Ads / DoubleClick
        'doubleclick.net', 'googlesyndication.com', 'googleadservices.com', 'adservice.google.com',
        'adservice.google.de', 'pagead2.googlesyndication.com', 'tpc.googlesyndication.com',
        '2mdn.net', 'admeld.com', 'admob.com', 'adsense.com', 'adwords.com',
        // Große Ad-Networks & Programmatic Exchanges
        'adnxs.com', 'ib.adnxs.com', 'criteo.com', 'criteo.net', 'taboola.com', 'trc.taboola.com',
        'outbrain.com', 'outbrainimg.com', 'widgets.outbrain.com', 'smartadserver.com',
        'rubiconproject.com', 'pubmatic.com', 'openx.net', 'casalemedia.com', 'indexww.com',
        'advertising.com', 'adform.net', 'track.adform.net', 'adition.com', 'adscale.de',
        'stroeerdigitalgroup.de', 'stroeer.de', 'oms.eu', 'yieldlab.net', 'addefend.com',
        'teads.tv', 'a.teads.tv', 'amazon-adsystem.com', 'aax.amazon-adsystem.com',
        'media.net', 'revcontent.com', 'mgid.com', 'plista.com', 'ligatus.com',
        'flashtalking.com', 'serving-sys.com', 'eyeblaster.com', 'sizmek.com',
        'zedo.com', 'popads.net', 'popcash.net', 'propellerads.com', 'adcash.com',
        'exoclick.com', 'juicyads.com', 'trafficjunky.net', 'adsterra.com',
        'sharethrough.com', 'triplelift.com', '33across.com', 'gumgum.com',
        'sovrn.com', 'lijit.com', 'bidswitch.net', 'contextweb.com', 'spotxchange.com',
        'springserve.com', 'innovid.com', 'moatads.com', 'adsafeprotected.com', 'doubleverify.com',
    ];
}

function getCoreTrackerDomains(): array
{
    return [
        // Analytics, Heatmaps & Session Recording
        'google-analytics.com', 'ssl.google-analytics.com', 'www.google-analytics.com',
        'googletagmanager.com', 'www.googletagmanager.com', 'googletagservices.com',
        'stats.g.doubleclick.net', 'analytics.google.com',
        'connect.facebook.net', 'pixel.facebook.com', 'an.facebook.com',
        'analytics.tiktok.com', 'ads.tiktok.com', 'analytics.twitter.com', 'static.ads-twitter.com',
        'snap.licdn.com', 'px.ads.linkedin.com', 'ct.pinterest.com', 'trk.pinterest.com',
        'bat.bing.com', 'clarity.ms', 'c.clarity.ms',
        'hotjar.com', 'static.hotjar.com', 'vars.hotjar.com', 'mouseflow.com',
        'fullstory.com', 'luckyorange.com', 'crazyegg.com', 'inspectlet.com',
        'mixpanel.com', 'api.mixpanel.com', 'segment.io', 'segment.com', 'amplitude.com',
        'heapanalytics.com', 'kissmetrics.com', 'chartbeat.com', 'chartbeat.net',
        'parsely.com', 'newrelic.com', 'nr-data.net', 'bam.nr-data.net',
        'scorecardresearch.com', 'sb.scorecardresearch.com', 'quantserve.com', 'pixel.quantserve.com',
        // Deutsche Reichweitenmessung & Tracking (IVW / INFOnline / VG Wort / Econda)
        'ioam.de', 'de.ioam.de', 'script.ioam.de', 'ivwbox.de', 'INFOnline.de',
        'vgwort.de', 'ssl-vg03.met.vgwort.de', 'econda-monitor.de', 'etracker.com', 'etracker.de',
        'matomo.cloud', 'piwik.pro', 'omtrdc.net', '2o7.net', 'demdex.net', 'everesttech.net',
        'krxd.net', 'bluekai.com', 'addthis.com', 'sharethis.com',
        'appsflyer.com', 'adjust.com', 'branch.io', 'app.link', 'kochava.com', 'singular.net',
        'bugsnag.com', 'CleverTap.com', 'braze.com', 'onesignal.com',
    ];
}

function getCoreCookieBannerPatterns(): array
{
    return [
        'cookiebot.com', 'consent.cookiebot.com',
        'onetrust.com', 'cdn.cookielaw.org', 'optanon.blob.core.windows.net',
        'usercentrics.eu', 'app.usercentrics.eu', 'cmp.usercentrics.eu',
        'consentmanager.net', 'delivery.consentmanager.net',
        'sourcepoint.com', 'sp-prod.net', 'cmp.sp-prod.net',
        'didomi.io', 'sdk.privacy-center.org',
        'quantcast.mgr.consensu.org', 'consensu.org',
        'trustarc.com', 'consent.trustarc.com',
        'borlabs-cookie', 'complianz', 'cookie-law-info', 'cookieyes.com',
        'osano.com', 'iubenda.com', 'cdn.iubenda.com', 'termly.io',
        'fundingchoicesmessages.google.com',
    ];
}

function getTrackingQueryParams(): array
{
    return [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'gclsrc', 'dclid', 'msclkid', 'twclid', 'ttclid', 'igshid',
        'mc_cid', 'mc_eid', '_ga', '_gl', '_hsenc', '_hsmi', 'hsCtaTracking',
        'vero_id', 'oly_enc_id', 'oly_anon_id', 'rb_clickid', 's_kwcid', 'wt_mc', 'wt_zmc',
    ];
}

// =========================================================================
// ÖFFENTLICHER PAC-ENDPUNKT (FÜR BROWSER / OS AUTO-PROXY-KONFIGURATION)
// =========================================================================
if (isset($_GET['pac']) && $_GET['pac'] === '1') {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '' || strlen($token) < 16) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('// Ungültiges MiniCloud-PAC-Token');
    }

    $stmtPac = $pdo->prepare('SELECT * FROM proxy_user_settings WHERE pac_token = :tok LIMIT 1');
    $stmtPac->execute([':tok' => $token]);
    $pacSettings = $stmtPac->fetch(PDO::FETCH_ASSOC);
    if (!$pacSettings) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('// Token nicht gefunden');
    }

    $pacUserId = (int)$pacSettings['user_id'];
    $stmtRules = $pdo->prepare('SELECT domain_pattern, rule_type FROM proxy_custom_rules WHERE user_id = :uid');
    $stmtRules->execute([':uid' => $pacUserId]);
    $customBlock = [];
    $customAllow = [];
    foreach ($stmtRules->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dom = strtolower(trim((string)$r['domain_pattern']));
        if ($dom === '') {
            continue;
        }
        if ($r['rule_type'] === 'allow') {
            $customAllow[$dom] = true;
        } else {
            $customBlock[$dom] = true;
        }
    }

    $blockedMap = [];
    if ((int)$pacSettings['block_ads'] === 1) {
        foreach (getCoreAdDomains() as $d) {
            $blockedMap[strtolower($d)] = true;
        }
    }
    if ((int)$pacSettings['block_trackers'] === 1) {
        foreach (getCoreTrackerDomains() as $d) {
            $blockedMap[strtolower($d)] = true;
        }
    }
    if ((int)$pacSettings['block_cookies'] === 1) {
        foreach (getCoreCookieBannerPatterns() as $d) {
            if (str_contains($d, '.')) {
                $blockedMap[strtolower($d)] = true;
            }
        }
    }
    foreach ($customBlock as $d => $_) {
        $blockedMap[$d] = true;
    }
    foreach ($customAllow as $d => $_) {
        unset($blockedMap[$d]);
    }

    $domainListJson = json_encode(array_keys($blockedMap), JSON_UNESCAPED_SLASHES);
    $allowListJson  = json_encode(array_keys($customAllow), JSON_UNESCAPED_SLASHES);

    header('Content-Type: application/x-ns-proxy-autoconfig; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo <<< PAC
// MiniCloud Smart AdBlock & Privacy PAC Configuration
// Generiert von runready.cloud – Blockiert Werbung, Tracker & Telemetrie direkt auf Verbindungs-Ebene

var MC_ALLOWED = {$allowListJson};
var MC_BLOCKED = {$domainListJson};

function hostMatchesList(host, list) {
    for (var i = 0; i < list.length; i++) {
        var d = list[i];
        if (host === d || dnsDomainIs(host, "." + d)) {
            return true;
        }
    }
    return false;
}

function FindProxyForURL(url, host) {
    host = host.toLowerCase();

    // Lokale Adressen immer direkt verbinden
    if (isPlainHostName(host) ||
        shExpMatch(host, "*.local") ||
        shExpMatch(host, "localhost") ||
        isInNet(dnsResolve(host), "10.0.0.0", "255.0.0.0") ||
        isInNet(dnsResolve(host), "172.16.0.0", "255.240.0.0") ||
        isInNet(dnsResolve(host), "192.168.0.0", "255.255.0.0") ||
        isInNet(dnsResolve(host), "127.0.0.0", "255.0.0.0")) {
        return "DIRECT";
    }

    // Eigene Whitelist prüfen
    if (hostMatchesList(host, MC_ALLOWED)) {
        return "DIRECT";
    }

    // Werbe-, Tracking- & Cookie-Consent-Domains sofort ins Blackhole schicken
    if (hostMatchesList(host, MC_BLOCKED)) {
        return "PROXY 127.0.0.1:65535";
    }

    return "DIRECT";
}
PAC;
    exit;
}

// =========================================================================
// REGULÄRE AUTHENTIFIZIERUNG FÜR DAS MINICLOUD-MODUL
// =========================================================================
requireLogin();

$appSettings = getAppSettings($pdo);
$userId      = currentUserId();
$username    = (string)($_SESSION['username'] ?? '');

if (($appSettings['enable_proxy'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('warning', 'Das Modul „Proxy & Werbeblocker“ ist aktuell durch den Administrator deaktiviert.');
    header('Location: index.php');
    exit;
}

/**
 * Lädt oder initialisiert die persönlichen Proxy- & AdBlocker-Einstellungen des Nutzers.
 */
function getUserProxySettings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM proxy_user_settings WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    $token = bin2hex(random_bytes(20));
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $stmtIns = $pdo->prepare('
        INSERT INTO proxy_user_settings (user_id, pac_token, block_ads, block_trackers, block_cookies, strip_tracking_params, block_external_scripts, allowed_client_ip)
        VALUES (:uid, :tok, 1, 1, 1, 1, 0, :ip)
    ');
    $stmtIns->execute([
        ':uid' => $userId,
        ':tok' => $token,
        ':ip'  => $clientIp,
    ]);

    // Standard-Lesezeichen beim ersten Start anlegen
    $defaultBookmarks = [
        ['🔍 DuckDuckGo (Anonyme Suche)', 'https://lite.duckduckgo.com/lite/', '🔍', 'proxy'],
        ['📚 Wikipedia Deutsch', 'https://de.wikipedia.org/wiki/Wikipedia:Hauptseite', '📚', 'proxy'],
        ['📰 tagesschau.de', 'https://www.tagesschau.de/', '📰', 'reader'],
        ['💻 heise online', 'https://www.heise.de/', '💻', 'reader'],
        ['⚽ kicker Sportnews', 'https://www.kicker.de/', '⚽', 'proxy'],
        ['🌍 Spiegel Online', 'https://www.spiegel.de/schlagzeilen/', '🌍', 'reader'],
    ];
    $stmtBm = $pdo->prepare('INSERT INTO proxy_bookmarks (user_id, title, url, icon, default_mode) VALUES (:uid, :t, :u, :ic, :m)');
    foreach ($defaultBookmarks as $bm) {
        $stmtBm->execute([
            ':uid' => $userId,
            ':t'   => $bm[0],
            ':u'   => $bm[1],
            ':ic'  => $bm[2],
            ':m'   => $bm[3],
        ]);
    }

    $stmt->execute([':uid' => $userId]);
    return (array)$stmt->fetch(PDO::FETCH_ASSOC);
}

$proxySettings = getUserProxySettings($pdo, $userId);

// Eigene Blacklist / Whitelist laden
$stmtCustom = $pdo->prepare('SELECT * FROM proxy_custom_rules WHERE user_id = :uid ORDER BY rule_type ASC, domain_pattern ASC');
$stmtCustom->execute([':uid' => $userId]);
$customRules = $stmtCustom->fetchAll(PDO::FETCH_ASSOC);

$userBlockedDomains = [];
$userAllowedDomains = [];
foreach ($customRules as $r) {
    $d = strtolower(trim((string)$r['domain_pattern']));
    if ($r['rule_type'] === 'allow') {
        $userAllowedDomains[$d] = true;
    } else {
        $userBlockedDomains[$d] = true;
    }
}

/**
 * Prüft eine Domain oder URL gegen alle aktiven AdBlock-, Tracker-, Cookie- und Benutzer-Regeln.
 *
 * @return array{blocked: bool, reason: string, category: string, matched_rule: string}
 */
function checkDomainOrUrlAgainstAdblock(string $urlOrHost, array $settings, array $userBlocked, array $userAllowed): array
{
    $raw = strtolower(trim($urlOrHost));
    if ($raw === '' || str_starts_with($raw, 'data:') || str_starts_with($raw, 'javascript:') || str_starts_with($raw, 'mailto:') || str_starts_with($raw, '#')) {
        return ['blocked' => false, 'reason' => 'Lokaler/Inline-Link', 'category' => 'ok', 'matched_rule' => ''];
    }

    $host = $raw;
    if (str_contains($raw, '://') || str_starts_with($raw, '//')) {
        $parsedHost = parse_url(str_starts_with($raw, '//') ? ('https:' . $raw) : $raw, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $host = strtolower($parsedHost);
        }
    } else {
        $slashPos = strpos($host, '/');
        if ($slashPos !== false) {
            $host = substr($host, 0, $slashPos);
        }
    }
    $host = ltrim($host, '.');

    // 1. Whitelist des Nutzers hat immer Vorrang
    foreach ($userAllowed as $allowDom => $_) {
        if ($host === $allowDom || str_ends_with($host, '.' . $allowDom)) {
            return ['blocked' => false, 'reason' => 'Durch eigene Whitelist erlaubt', 'category' => 'whitelist', 'matched_rule' => $allowDom];
        }
    }

    // 2. Eigene Blacklist des Nutzers
    foreach ($userBlocked as $blockDom => $_) {
        if ($host === $blockDom || str_ends_with($host, '.' . $blockDom) || str_contains($raw, $blockDom)) {
            return ['blocked' => true, 'reason' => 'Eigene Sperrregel (Blacklist)', 'category' => 'custom', 'matched_rule' => $blockDom];
        }
    }

    // 3. Werbe-Domains
    if ((int)($settings['block_ads'] ?? 1) === 1) {
        foreach (getCoreAdDomains() as $adDom) {
            if ($host === $adDom || str_ends_with($host, '.' . $adDom)) {
                return ['blocked' => true, 'reason' => 'Werbenetzwerk / Ad-Server', 'category' => 'ad', 'matched_rule' => $adDom];
            }
        }
        if (preg_match('#(/ads/|/adserver/|/pagead/|/doubleclick/|/banners?/|/popunder/|/sponsor/)#i', $raw)) {
            return ['blocked' => true, 'reason' => 'Werbe-Banner-Pfad erkannt', 'category' => 'ad', 'matched_rule' => 'URL-Pfad /ads/'];
        }
    }

    // 4. Tracking- & Analyse-Domains
    if ((int)($settings['block_trackers'] ?? 1) === 1) {
        foreach (getCoreTrackerDomains() as $trDom) {
            if ($host === $trDom || str_ends_with($host, '.' . $trDom)) {
                return ['blocked' => true, 'reason' => 'Tracking- & Analyse-Dienst', 'category' => 'tracker', 'matched_rule' => $trDom];
            }
        }
    }

    // 5. Cookie-Consent- & Nerv-Banner
    if ((int)($settings['block_cookies'] ?? 1) === 1) {
        foreach (getCoreCookieBannerPatterns() as $ckPat) {
            if ($host === $ckPat || str_ends_with($host, '.' . $ckPat) || str_contains($raw, $ckPat)) {
                return ['blocked' => true, 'reason' => 'Cookie-Banner / Consent-Popup', 'category' => 'cookie', 'matched_rule' => $ckPat];
            }
        }
    }

    return ['blocked' => false, 'reason' => 'Saubere Domain (Kein Treffer in Sperrlisten)', 'category' => 'clean', 'matched_rule' => ''];
}

// =========================================================================
// STRENGER SSRF-SCHUTZ & SICHERER HTTP-ABRUF ÜBER DEN SERVER
// =========================================================================
/**
 * Prüft, ob eine Ziel-URL sicher über den Proxy abgerufen werden darf
 * (verhindert SSRF-Angriffe auf 127.0.0.1, 10.x, 192.168.x, 172.16-31.x, 169.254.x, IPv6-Loopback/Link-Local).
 */
function validateSafeProxyTargetUrl(string $url, ?string &$errorMsg = null): ?string
{
    $url = trim($url);
    if ($url === '') {
        $errorMsg = 'Bitte gib eine Ziel-Adresse oder einen Suchbegriff ein.';
        return null;
    }

    // Falls keine URL, sondern ein Suchbegriff eingegeben wurde -> automatisch DuckDuckGo Lite Suche nutzen!
    if (!preg_match('#^https?://#i', $url)) {
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/.*)?$/i', $url) && !str_contains($url, ' ')) {
            $url = 'https://' . $url;
        } else {
            $url = 'https://lite.duckduckgo.com/lite/?q=' . rawurlencode($url);
        }
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        $errorMsg = 'Ungültige URL-Struktur.';
        return null;
    }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        $errorMsg = 'Nur http:// und https:// Verbindungen sind im Web-Proxy erlaubt.';
        return null;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443, 8080, 8443], true)) {
        $errorMsg = 'Ziel-Port (' . $port . ') ist aus Sicherheitsgründen nicht freigegeben.';
        return null;
    }

    $host = strtolower(trim((string)$parts['host'], '[]'));
    $forbiddenHosts = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback', 'metadata.google.internal', 'metadata'];
    if (in_array($host, $forbiddenHosts, true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan')) {
        $errorMsg = 'Zugriff auf lokale/interne Hostnamen ist aus Sicherheitsgründen (SSRF-Schutz) blockiert.';
        return null;
    }

    // DNS-Auflösung prüfen (IPv4 + IPv6)
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolvedA = @gethostbynamel($host);
        if (is_array($resolvedA)) {
            $ips = array_merge($ips, $resolvedA);
        }
        $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($dnsRecords)) {
            foreach ($dnsRecords as $rec) {
                if (!empty($rec['ip'])) {
                    $ips[] = $rec['ip'];
                }
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
    }

    $ips = array_values(array_unique($ips));
    if (empty($ips)) {
        $errorMsg = 'Die Domain „' . $host . '“ konnte nicht aufgelöst werden (DNS-Fehler).';
        return null;
    }

    $serverOwnIp = (string)($_SERVER['SERVER_ADDR'] ?? '');
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $errorMsg = 'Zugriff auf private oder reservierte IP-Adressen (' . $ip . ') ist blockiert (SSRF-Schutz).';
            return null;
        }
        if ($serverOwnIp !== '' && $ip === $serverOwnIp) {
            $errorMsg = 'Zugriff auf die interne Server-IP ist aus Sicherheitsgründen untersagt.';
            return null;
        }
    }

    return $url;
}

/**
 * Löst eine relative URL (z. B. "/bilder/logo.png" oder "../style.css") gegen eine Basis-URL auf.
 */
function resolveRelativeUrl(string $relativeUrl, string $baseUrl): string
{
    $relativeUrl = trim($relativeUrl);
    if ($relativeUrl === '') {
        return $baseUrl;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativeUrl)) {
        return $relativeUrl;
    }
    $baseParts = parse_url($baseUrl);
    if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $relativeUrl;
    }
    $scheme = $baseParts['scheme'];
    $host   = $baseParts['host'];
    $port   = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    if (str_starts_with($relativeUrl, '//')) {
        return $scheme . ':' . $relativeUrl;
    }
    if (str_starts_with($relativeUrl, '/')) {
        return $origin . $relativeUrl;
    }

    $path = $baseParts['path'] ?? '/';
    $dir  = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
    $combined = $dir . $relativeUrl;

    // Pfade wie /a/b/../c normalisieren
    $segments = explode('/', $combined);
    $resolved = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($resolved);
        } else {
            $resolved[] = $seg;
        }
    }
    return $origin . '/' . implode('/', $resolved);
}

/**
 * Entfernt Tracking-Query-Parameter (utm_*, fbclid, gclid, ...) aus einer URL.
 */
function stripTrackingParametersFromUrl(string $url, int &$strippedCount): string
{
    $qPos = strpos($url, '?');
    if ($qPos === false) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['query'])) {
        return $url;
    }
    parse_str($parts['query'], $queryParams);
    if (empty($queryParams)) {
        return $url;
    }
    $trackingKeys = array_flip(getTrackingQueryParams());
    $cleaned = [];
    $removedHere = 0;
    foreach ($queryParams as $k => $v) {
        $kLower = strtolower((string)$k);
        if (isset($trackingKeys[$kLower]) || str_starts_with($kLower, 'utm_')) {
            $removedHere++;
            continue;
        }
        $cleaned[$k] = $v;
    }
    if ($removedHere === 0) {
        return $url;
    }
    $strippedCount += $removedHere;
    $base = substr($url, 0, $qPos);
    $frag = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
    $newQuery = http_build_query($cleaned);
    return $base . ($newQuery !== '' ? '?' . $newQuery : '') . $frag;
}

/**
 * Ruft eine URL mit Hop-by-Hop-SSRF-Prüfung über cURL ab.
 *
 * @return array{ok: bool, status: int, content_type: string, final_url: string, body: string, duration_ms: int, error: string}
 */
function fetchUrlViaSafeProxy(string $targetUrl, int $maxBytes = 6291456): array
{
    $t0 = microtime(true);
    $currentUrl = $targetUrl;
    $maxRedirects = 5;

    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        $err = null;
        $validated = validateSafeProxyTargetUrl($currentUrl, $err);
        if ($validated === null) {
            return [
                'ok'           => false,
                'status'       => 403,
                'content_type' => 'text/plain',
                'final_url'    => $currentUrl,
                'body'         => '',
                'duration_ms'  => (int)round((microtime(true) - $t0) * 1000),
                'error'        => (string)$err,
            ];
        }
        $currentUrl = $validated;

        $ch = curl_init($currentUrl);
        $redirectLocation = null;
        $contentType = 'text/html; charset=utf-8';
        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false, // Wir folgen Redirects manuell, damit jeder Hop SSRF-geprüft wird!
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING       => '', // gzip / deflate / br automatisch entpacken
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en-US;q=0.7,en;q=0.6',
                'DNT: 1',
                'Sec-GPC: 1',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$redirectLocation, &$contentType): int {
                $trimmed = trim($headerLine);
                if (stripos($trimmed, 'Location:') === 0) {
                    $redirectLocation = trim(substr($trimmed, 9));
                } elseif (stripos($trimmed, 'Content-Type:') === 0) {
                    $contentType = trim(substr($trimmed, 13));
                }
                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$buffer, $maxBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                    $remaining = $maxBytes - strlen($buffer);
                    if ($remaining > 0) {
                        $buffer .= substr($chunk, 0, $remaining);
                    }
                    return 0; // Abbruch bei Überschreitung des Größenlimits
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);

        @curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 300 && $httpCode < 400 && $redirectLocation !== null && $redirectLocation !== '') {
            $currentUrl = resolveRelativeUrl($redirectLocation, $currentUrl);
            continue;
        }

        if ($httpCode === 0 && $buffer === '') {
            return [
                'ok'           => false,
                'status'       => 502,
                'content_type' => 'text/plain',
                'final_url'    => $currentUrl,
                'body'         => '',
                'duration_ms'  => (int)round((microtime(true) - $t0) * 1000),
                'error'        => 'Verbindung zur Zielseite fehlgeschlagen: ' . ($curlErr !== '' ? $curlErr : 'Zeitüberschreitung'),
            ];
        }

        return [
            'ok'           => true,
            'status'       => $httpCode ?: 200,
            'content_type' => $contentType,
            'final_url'    => $currentUrl,
            'body'         => $buffer,
            'duration_ms'  => (int)round((microtime(true) - $t0) * 1000),
            'error'        => '',
        ];
    }

    return [
        'ok'           => false,
        'status'       => 310,
        'content_type' => 'text/plain',
        'final_url'    => $currentUrl,
        'body'         => '',
        'duration_ms'  => (int)round((microtime(true) - $t0) * 1000),
        'error'        => 'Zu viele Weiterleitungen (Redirect-Schleife erkannt).',
    ];
}

// =========================================================================
// ASSET-PROXY FÜR BILDER & STYLESHEETS (VERHINDERT HOTLINK-/IP-LEAKS)
// =========================================================================
if (isset($_GET['asset']) && $_GET['asset'] === '1') {
    $assetUrl = trim((string)($_GET['url'] ?? ''));
    $check = checkDomainOrUrlAgainstAdblock($assetUrl, $proxySettings, $userBlockedDomains, $userAllowedDomains);
    if ($check['blocked']) {
        $srcDom = (string)(parse_url($assetUrl, PHP_URL_HOST) ?: 'Asset');
        $pdo->prepare('
            INSERT INTO proxy_block_logs (user_id, category, matched_rule, blocked_url, source_domain)
            VALUES (:uid, :cat, :rule, :url, :src)
        ')->execute([
            ':uid'  => $userId,
            ':cat'  => mb_substr($check['category'], 0, 24),
            ':rule' => mb_substr($check['matched_rule'], 0, 190),
            ':url'  => mb_substr($assetUrl, 0, 490),
            ':src'  => mb_substr($srcDom, 0, 180),
        ]);
        // 1x1 transparentes GIF zurückgeben, wenn es ein Werbe-/Tracking-Pixel ist
        header('Content-Type: image/gif');
        header('X-MiniCloud-AdBlock: Blocked (' . $check['matched_rule'] . ')');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    $res = fetchUrlViaSafeProxy($assetUrl, 5242880); // max. 5 MB für Assets
    if (!$res['ok']) {
        http_response_code(404);
        exit;
    }

    $ct = strtolower( trim(explode(';', $res['content_type'])[0]) );
    $allowedAssetTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon', 'image/avif',
        'text/css', 'font/woff2', 'font/woff', 'application/font-woff', 'application/font-woff2',
    ];
    if (!in_array($ct, $allowedAssetTypes, true)) {
        $ct = 'application/octet-stream';
    }
    if ($ct === 'image/svg+xml') {
        // Aktive Skripte aus SVGs entfernen
        $res['body'] = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', '', $res['body']);
    }

    header('Content-Type: ' . $ct);
    header('Cache-Control: private, max-age=1800');
    header('X-Content-Type-Options: nosniff');
    echo $res['body'];
    exit;
}

// =========================================================================
// HTML-BEREINIGUNG, WERBEBLOCKER & LINK-REWRITER FÜR DEN WEB-PROXY
// =========================================================================
/**
 * Bereinigt das abgerufene HTML einer Webseite:
 * - Entfernt Werbe-Skripte, Tracker, Zählpixel, Werbe-Iframes und Cookie-Banner
 * - Schreibt relative Links, Bilder & Stylesheets um
 * - Injiziert ein kosmetisches AdBlock- & Anti-Cookie-Banner-Stylesheet
 *
 * @return array{html: string, title: string, ads_blocked: int, trackers_blocked: int, cookies_blocked: int, params_stripped: int, blocked_items: list<array{type: string, rule: string, url: string}>}
 */
function sanitizeAndRewriteProxiedHtml(
    string $rawHtml,
    string $finalUrl,
    array $settings,
    array $userBlocked,
    array $userAllowed
): array {
    $adsBlocked      = 0;
    $trackersBlocked = 0;
    $cookiesBlocked  = 0;
    $paramsStripped  = 0;
    $blockedItems    = [];

    // Zeichensatz nach UTF-8 normalisieren, falls ISO-8859-1 / Windows-1252
    if (!mb_check_encoding($rawHtml, 'UTF-8')) {
        $rawHtml = mb_convert_encoding($rawHtml, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
    }

    // Seitentitel auslesen
    $pageTitle = $finalUrl;
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $rawHtml, $mTitle)) {
        $decodedTitle = trim(html_entity_decode(strip_tags($mTitle[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decodedTitle !== '') {
            $pageTitle = $decodedTitle;
        }
    }

    // Hilfsfunktion zum Protokollieren blockierter Elemente
    $recordBlock = static function (string $category, string $rule, string $itemUrl) use (&$adsBlocked, &$trackersBlocked, &$cookiesBlocked, &$blockedItems): void {
        if ($category === 'ad' || $category === 'custom') {
            $adsBlocked++;
        } elseif ($category === 'tracker') {
            $trackersBlocked++;
        } elseif ($category === 'cookie') {
            $cookiesBlocked++;
        }
        if (count($blockedItems) < 60) {
            $blockedItems[] = [
                'type' => $category,
                'rule' => $rule,
                'url'  => mb_substr($itemUrl, 0, 120),
            ];
        }
    };

    // 1. <script>-Tags analysieren und Werbe-/Tracking-/Cookie-Skripte (oder auf Wunsch alle Skripte) entfernen
    $blockAllScripts = ((int)($settings['block_external_scripts'] ?? 0) === 1);
    $cleanHtml = (string)preg_replace_callback(
        '#<script\b([^>]*)>(.*?)</script>#is',
        static function (array $m) use ($finalUrl, $settings, $userBlocked, $userAllowed, $blockAllScripts, $recordBlock): string {
            $attrs = $m[1];
            $inner = $m[2];

            if ($blockAllScripts) {
                $recordBlock('tracker', 'Strict NoScript-Modus', 'script');
                return '<!-- [MiniCloud AdBlock: Script im NoScript-Modus entfernt] -->';
            }

            if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $mSrc)) {
                $absSrc = resolveRelativeUrl(html_entity_decode($mSrc[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $finalUrl);
                $check  = checkDomainOrUrlAgainstAdblock($absSrc, $settings, $userBlocked, $userAllowed);
                if ($check['blocked']) {
                    $recordBlock($check['category'], $check['matched_rule'], $absSrc);
                    return '<!-- [MiniCloud AdBlock: ' . htmlspecialchars($check['matched_rule'], ENT_QUOTES, 'UTF-8') . '] -->';
                }
            }

            // Inline-Skripte auf bekannte Tracker / Ad-Calls / Cookie-Consent prüfen
            $inlineSignatures = [
                'googletest'        => ['ad', 'googletag / GPT AdSlot'],
                'adsbygoogle'       => ['ad', 'Google AdSense'],
                'GoogleAnalytics'   => ['tracker', 'Google Analytics Inline'],
                'gtag('             => ['tracker', 'Google Tag (gtag.js)'],
                'gtm.start'         => ['tracker', 'Google Tag Manager'],
                'fbq('              => ['tracker', 'Meta / Facebook Pixel'],
                'ttq.load'          => ['tracker', 'TikTok Tracking Pixel'],
                '_paq.push'         => ['tracker', 'Matomo / Piwik Tracker'],
                'iom.c('            => ['tracker', 'INFOnline / IVW Zählpixel'],
                'iam_data'          => ['tracker', 'INFOnline / IVW Zählpixel'],
                'OneTrust'          => ['cookie', 'OneTrust Cookie-Consent'],
                'Cookiebot'         => ['cookie', 'Cookiebot Banner'],
                'usercentrics'      => ['cookie', 'Usercentrics CMP'],
                '__tcfapi'          => ['cookie', 'IAB TCF Consent-Popup'],
                '_sp_.config'       => ['cookie', 'Sourcepoint Consent-Modal'],
                'didomiConfig'      => ['cookie', 'Didomi Consent-Banner'],
                'BorlabsCookie'     => ['cookie', 'Borlabs Cookie-Banner'],
            ];
            foreach ($inlineSignatures as $needle => [$cat, $label]) {
                if (stripos($inner, $needle) !== false) {
                    if ($cat === 'ad' && (int)($settings['block_ads'] ?? 1) === 1) {
                        $recordBlock('ad', $label, 'Inline-Script');
                        return '';
                    }
                    if ($cat === 'tracker' && (int)($settings['block_trackers'] ?? 1) === 1) {
                        $recordBlock('tracker', $label, 'Inline-Script');
                        return '';
                    }
                    if ($cat === 'cookie' && (int)($settings['block_cookies'] ?? 1) === 1) {
                        $recordBlock('cookie', $label, 'Inline-Script');
                        return '';
                    }
                }
            }

            // Externe Script-SRC auf absolute URL umschreiben
            $rewrittenAttrs = (string)preg_replace_callback(
                '/\bsrc\s*=\s*(["\'])(.*?)\1/i',
                static function (array $sm) use ($finalUrl): string {
                    $abs = resolveRelativeUrl(html_entity_decode($sm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $finalUrl);
                    return 'src="' . htmlspecialchars($abs, ENT_QUOTES, 'UTF-8') . '"';
                },
                $attrs
            );

            return '<script' . $rewrittenAttrs . '>' . $inner . '</script>';
        },
        $rawHtml
    );

    // 2. <iframe>-Tags prüfen (Werbe-Iframes & Consent-Iframes entfernen)
    $cleanHtml = (string)preg_replace_callback(
        '#<iframe\b([^>]*)>(.*?)</iframe>#is',
        static function (array $m) use ($finalUrl, $settings, $userBlocked, $userAllowed, $recordBlock): string {
            $attrs = $m[1];
            if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $mSrc)) {
                $rawSrc = trim(html_entity_decode($mSrc[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($rawSrc === '' || str_starts_with($rawSrc, 'about:') || str_starts_with($rawSrc, 'javascript:')) {
                    $recordBlock('ad', 'Leerer Werbe-Iframe', $rawSrc ?: 'about:blank');
                    return '';
                }
                $absSrc = resolveRelativeUrl($rawSrc, $finalUrl);
                $check  = checkDomainOrUrlAgainstAdblock($absSrc, $settings, $userBlocked, $userAllowed);
                if ($check['blocked']) {
                    $recordBlock($check['category'], $check['matched_rule'], $absSrc);
                    return '';
                }
            }
            return '';
        },
        $cleanHtml
    );

    // 3. <ins class="adsbygoogle"> & bekannte Ad-Container entfernen
    if ((int)($settings['block_ads'] ?? 1) === 1) {
        $adInsCount = 0;
        $cleanHtml = (string)preg_replace('#<ins\b[^>]*class=["\'][^"\']*adsbygoogle[^"\']*["\'][^>]*>.*?</ins>#is', '', $cleanHtml, -1, $adInsCount);
        if ($adInsCount > 0) {
            for ($i = 0; $i < $adInsCount; $i++) {
                $recordBlock('ad', 'Google AdSense <ins>', 'adsbygoogle');
            }
        }
    }

    // 4. <img>-Tags prüfen (Zählpixel & Werbebanner blockieren, echte Bilder über Asset-Proxy oder absolut auflösen)
    $cleanHtml = (string)preg_replace_callback(
        '#<img\b([^>]*)>#is',
        static function (array $m) use ($finalUrl, $settings, $userBlocked, $userAllowed, $recordBlock): string {
            $attrs = $m[1];
            // Lazy-Loading data-src unterstützen, falls src ein Platzhalter ist
            if (preg_match('/\bdata-src\s*=\s*(["\'])(https?:\/\/[^"\']+|\/[^"\']+)\1/i', $attrs, $mLazy) && (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $mCur) || str_starts_with($mCur[2], 'data:'))) {
                $attrs = preg_replace('/\bsrc\s*=\s*(["\'])(.*?)\1/i', '', $attrs) . ' src="' . htmlspecialchars($mLazy[2], ENT_QUOTES, 'UTF-8') . '"';
            }

            if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $attrs, $mSrc)) {
                $rawSrc = trim(html_entity_decode($mSrc[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($rawSrc !== '' && !str_starts_with($rawSrc, 'data:')) {
                    $absSrc = resolveRelativeUrl($rawSrc, $finalUrl);
                    $check  = checkDomainOrUrlAgainstAdblock($absSrc, $settings, $userBlocked, $userAllowed);
                    if ($check['blocked']) {
                        $recordBlock($check['category'], $check['matched_rule'], $absSrc);
                        return '';
                    }
                    $proxiedImg = 'proxy.php?asset=1&url=' . rawurlencode($absSrc);
                    $attrs = (string)preg_replace('/\bsrc\s*=\s*(["\'])(.*?)\1/i', 'src="' . htmlspecialchars($proxiedImg, ENT_QUOTES, 'UTF-8') . '"', $attrs, 1);
                }
            }
            // srcset entfernen, damit der Browser nicht am Proxy vorbei direkt lädt
            $attrs = (string)preg_replace('/\bsrcset\s*=\s*(["\'])(.*?)\1/is', '', $attrs);
            return '<img loading="lazy"' . $attrs . '>';
        },
        $cleanHtml
    );

    // 5. <link rel="stylesheet">-Tags über Asset-Proxy oder absolut umschreiben
    $cleanHtml = (string)preg_replace_callback(
        '#<link\b([^>]*)>#is',
        static function (array $m) use ($finalUrl, $settings, $userBlocked, $userAllowed, $recordBlock): string {
            $attrs = $m[1];
            if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $mHref)) {
                $absHref = resolveRelativeUrl(html_entity_decode($mHref[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $finalUrl);
                $check   = checkDomainOrUrlAgainstAdblock($absHref, $settings, $userBlocked, $userAllowed);
                if ($check['blocked']) {
                    $recordBlock($check['category'], $check['matched_rule'], $absHref);
                    return '';
                }
                if (stripos($attrs, 'stylesheet') !== false) {
                    $proxiedCss = 'proxy.php?asset=1&url=' . rawurlencode($absHref);
                    $attrs = (string)preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href="' . htmlspecialchars($proxiedCss, ENT_QUOTES, 'UTF-8') . '"', $attrs, 1);
                } else {
                    $attrs = (string)preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href="' . htmlspecialchars($absHref, ENT_QUOTES, 'UTF-8') . '"', $attrs, 1);
                }
            }
            return '<link' . $attrs . '>';
        },
        $cleanHtml
    );

    // 6. <a href="..."> Links umschreiben + Tracking-Parameter entfernen
    $stripParams = ((int)($settings['strip_tracking_params'] ?? 1) === 1);
    $cleanHtml = (string)preg_replace_callback(
        '#<a\b([^>]*)>#is',
        static function (array $m) use ($finalUrl, $stripParams, &$paramsStripped): string {
            $attrs = $m[1];
            if (!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $mHref)) {
                return $m[0];
            }
            $rawHref = trim(html_entity_decode($mHref[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($rawHref === '' || str_starts_with($rawHref, '#') || str_starts_with($rawHref, 'javascript:') || str_starts_with($rawHref, 'mailto:') || str_starts_with($rawHref, 'tel:')) {
                return $m[0];
            }
            $absHref = resolveRelativeUrl($rawHref, $finalUrl);
            if ($stripParams) {
                $absHref = stripTrackingParametersFromUrl($absHref, $paramsStripped);
            }
            $proxiedHref = 'proxy.php?mode=proxy&url=' . rawurlencode($absHref);
            $attrs = (string)preg_replace('/\bhref\s*=\s*(["\'])(.*?)\1/i', 'href="' . htmlspecialchars($proxiedHref, ENT_QUOTES, 'UTF-8') . '" target="_top"', $attrs, 1);
            return '<a' . $attrs . '>';
        },
        $cleanHtml
    );

    // 7. Kosmetischer AdBlock- & Anti-Cookie-Banner-CSS-Filter injizieren
    $cosmeticCss = <<< 'CSS'
<style id="minicloud-adblock-cosmetic">
/* MiniCloud Cosmetic AdBlocker & Cookie-Banner-Schutz */
.adsbygoogle, ins.adsbygoogle, [id^="google_ads_"], [id^="div-gpt-ad"], [class*="ad-container"],
[class*="ad-slot"], [class*="ad-wrapper"], [class*="advertisement"], [data-ad-slot], [data-ad-client],
.outbrain-widget, .OUTBRAIN, .taboola-widget, [id^="taboola-"], [class*="criteo"],
#onetrust-consent-sdk, #onetrust-banner-sdk, .onetrust-pc-dark-filter,
#CybotCookiebotDialog, #CybotCookiebotDialogBodyUnderlay,
#usercentrics-root, [id^="sp_message_container"], .sp_message_container,
#didomi-host, #didomi-popup, .didomi-popup-backdrop,
.borlabs-cookie-box, #BorlabsCookieBox, .fc-consent-root, .fc-dialog-overlay,
[class*="cookie-banner"], [id*="cookie-banner"], [class*="cookie-consent"], [id*="cookie-consent"],
[class*="gdpr-banner"], [id*="gdpr-banner"], .cmp-root, #cmpbox, #cmpbox2 {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    height: 0 !important;
    max-height: 0 !important;
    overflow: hidden !important;
}
html, body {
    overflow: auto !important;
    position: static !important;
}
</style>
CSS;

    if (stripos($cleanHtml, '</head>') !== false) {
        $cleanHtml = (string)preg_replace('#</head>#i', $cosmeticCss . "\n</head>", $cleanHtml, 1);
    } else {
        $cleanHtml = $cosmeticCss . "\n" . $cleanHtml;
    }

    return [
        'html'             => $cleanHtml,
        'title'            => $pageTitle,
        'ads_blocked'      => $adsBlocked,
        'trackers_blocked' => $trackersBlocked,
        'cookies_blocked'  => $cookiesBlocked,
        'params_stripped'  => $paramsStripped,
        'blocked_items'    => $blockedItems,
    ];
}

// =========================================================================
// ARTIKEL-EXTRAKTOR FÜR DEN WERBEFREIEN LESEMODUS (READER VIEW)
// =========================================================================
/**
 * Extrahiert aus einer beliebigen Webseite den reinen Artikeltext, Zwischenüberschriften,
 * Bilder, Metadaten und geschätzte Lesezeit für den werbefreien Lesemodus.
 *
 * @return array{title: string, subtitle: string, site_name: string, lead_image: string, reading_minutes: int, word_count: int, blocks: list<array{type: string, text: string, src?: string}>, plain_text: string}
 */
function extractCleanArticleForReaderView(string $rawHtml, string $finalUrl): array
{
    if (!mb_check_encoding($rawHtml, 'UTF-8')) {
        $rawHtml = mb_convert_encoding($rawHtml, 'UTF-8', 'ISO-8859-1,Windows-1252,UTF-8');
    }

    $title    = '';
    $subtitle = '';
    $siteName = (string)(parse_url($finalUrl, PHP_URL_HOST) ?: '');
    $leadImg  = '';

    // OpenGraph / Meta-Daten auslesen
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $rawHtml, $m) ||
        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $rawHtml, $m)) {
        $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($title === '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $rawHtml, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($title === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $rawHtml, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<meta[^>]+(?:name|property)=["\'](?:description|og:description)["\'][^>]+content=["\']([^"\']+)["\']/i', $rawHtml, $m) ||
        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\'](?:description|og:description)["\']/i', $rawHtml, $m)) {
        $subtitle = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $rawHtml, $m) ||
        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $rawHtml, $m)) {
        $leadImg = resolveRelativeUrl(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), $finalUrl);
    }

    // Störende Bereiche (Scripts, Styles, Nav, Footer, Aside, Forms, Kommentare, Cookie-Banner) vorab entfernen
    $stripped = (string)preg_replace(
        '#<(script|style|noscript|svg|iframe|nav|footer|aside|form|header|button|dialog)\b[^>]*>.*?</\1>#is',
        '',
        $rawHtml
    );

    // Falls <article> oder <main> vorhanden ist, diesen Hauptbereich bevorzugen
    $candidateHtml = $stripped;
    if (preg_match('#<article\b[^>]*>(.*?)</article>#is', $stripped, $mArt) && strlen(strip_tags($mArt[1])) > 250) {
        $candidateHtml = $mArt[1];
    } elseif (preg_match('#<main\b[^>]*>(.*?)</main>#is', $stripped, $mMain) && strlen(strip_tags($mMain[1])) > 250) {
        $candidateHtml = $mMain[1];
    }

    $blocks = [];
    $plainLines = [];
    $seenHashes = [];

    if (preg_match_all('#<(h2|h3|p|blockquote|li)\b[^>]*>(.*?)</\1>#is', $candidateHtml, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tag  = strtolower($match[1]);
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($text === '') {
                continue;
            }
            // Typische Cookie-/Footer-Reste überspringen
            if (preg_match('/(alle cookies akzeptieren|cookie-einstellungen|datenschutzerklärung|nutzungsbedingungen|jetzt abonnieren|anzeige:|werbung)/iu', $text) && mb_strlen($text) < 160) {
                continue;
            }
            if (($tag === 'p' || $tag === 'li') && mb_strlen($text) < 28) {
                continue;
            }
            $hash = md5($text);
            if (isset($seenHashes[$hash])) {
                continue;
            }
            $seenHashes[$hash] = true;

            $blocks[] = [
                'type' => $tag,
                'text' => $text,
            ];
            $plainLines[] = $text;
        }
    }

    $fullPlain = implode("\n\n", $plainLines);
    $wordCount = str_word_count($fullPlain) ?: (int)ceil(mb_strlen($fullPlain) / 6);
    $readingMinutes = max(1, (int)ceil($wordCount / 200));

    return [
        'title'           => $title !== '' ? $title : 'Artikel-Ansicht (' . $siteName . ')',
        'subtitle'        => $subtitle,
        'site_name'       => $siteName,
        'lead_image'      => $leadImg,
        'reading_minutes' => $readingMinutes,
        'word_count'      => $wordCount,
        'blocks'          => $blocks,
        'plain_text'      => $fullPlain,
    ];
}

// =========================================================================
// POST-AKTIONEN (EINSTELLUNGEN, FILTERREGELN, LESEZEICHEN, NOTIZ-EXPORT)
// =========================================================================
$domainCheckResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte lade die Seite neu.');
        header('Location: proxy.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_proxy_settings') {
        $blockAds      = isset($_POST['block_ads']) ? 1 : 0;
        $blockTrackers = isset($_POST['block_trackers']) ? 1 : 0;
        $blockCookies  = isset($_POST['block_cookies']) ? 1 : 0;
        $stripParams   = isset($_POST['strip_tracking_params']) ? 1 : 0;
        $blockScripts  = isset($_POST['block_external_scripts']) ? 1 : 0;

        $pdo->prepare('
            UPDATE proxy_user_settings
            SET block_ads = :ads,
                block_trackers = :trk,
                block_cookies = :ck,
                strip_tracking_params = :stp,
                block_external_scripts = :scr
            WHERE user_id = :uid
        ')->execute([
            ':ads' => $blockAds,
            ':trk' => $blockTrackers,
            ':ck'  => $blockCookies,
            ':stp' => $stripParams,
            ':scr' => $blockScripts,
            ':uid' => $userId,
        ]);

        setFlash('success', 'Werbeblocker- & Proxy-Filtereinstellungen gespeichert! 🛡️');
        header('Location: proxy.php?tab=adblock');
        exit;
    }

    if ($action === 'authorize_current_ip') {
        $myIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $pdo->prepare('UPDATE proxy_user_settings SET allowed_client_ip = :ip WHERE user_id = :uid')
            ->execute([':ip' => $myIp, ':uid' => $userId]);
        setFlash('success', 'Deine aktuelle IP-Adresse (' . $myIp . ') wurde für den Proxy freigeschaltet! 🔓');
        header('Location: proxy.php?tab=adblock');
        exit;
    }

    if ($action === 'regenerate_pac_token') {
        $newTok = bin2hex(random_bytes(20));
        $pdo->prepare('UPDATE proxy_user_settings SET pac_token = :tok WHERE user_id = :uid')
            ->execute([':tok' => $newTok, ':uid' => $userId]);
        setFlash('success', 'Neues persönliches PAC-Token wurde generiert! 🔑');
        header('Location: proxy.php?tab=adblock');
        exit;
    }

    if ($action === 'add_custom_rule') {
        $rawPattern = strtolower(trim((string)($_POST['domain_pattern'] ?? '')));
        $ruleType   = ($_POST['rule_type'] ?? 'block') === 'allow' ? 'allow' : 'block';
        $note       = trim((string)($_POST['note'] ?? ''));

        // Falls komplette URL eingegeben wurde, Host extrahieren
        if (str_contains($rawPattern, '://')) {
            $parsedH = parse_url($rawPattern, PHP_URL_HOST);
            if (is_string($parsedH) && $parsedH !== '') {
                $rawPattern = strtolower($parsedH);
            }
        }
        $rawPattern = ltrim($rawPattern, '*.');

        if ($rawPattern !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $rawPattern)) {
            $pdo->prepare('
                INSERT INTO proxy_custom_rules (user_id, domain_pattern, rule_type, note)
                VALUES (:uid, :dom, :rt, :note)
                ON DUPLICATE KEY UPDATE rule_type = VALUES(rule_type), note = VALUES(note)
            ')->execute([
                ':uid'  => $userId,
                ':dom'  => $rawPattern,
                ':rt'   => $ruleType,
                ':note' => mb_substr($note, 0, 200),
            ]);
            setFlash('success', 'Filterregel für „' . $rawPattern . '“ (' . ($ruleType === 'allow' ? 'Whitelist' : 'Blacklist') . ') gespeichert.');
        } else {
            setFlash('error', 'Bitte gib einen gültigen Domainnamen (z. B. werbung.beispiel.de) ein.');
        }
        header('Location: proxy.php?tab=adblock');
        exit;
    }

    if ($action === 'delete_custom_rule') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        $pdo->prepare('DELETE FROM proxy_custom_rules WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $ruleId, ':uid' => $userId]);
        setFlash('success', 'Filterregel entfernt.');
        header('Location: proxy.php?tab=adblock');
        exit;
    }

    if ($action === 'add_bookmark') {
        $bmTitle = trim((string)($_POST['title'] ?? ''));
        $bmUrl   = trim((string)($_POST['url'] ?? ''));
        $bmMode  = ($_POST['default_mode'] ?? 'proxy') === 'reader' ? 'reader' : 'proxy';
        if ($bmUrl !== '' && !preg_match('#^https?://#i', $bmUrl)) {
            $bmUrl = 'https://' . $bmUrl;
        }
        if ($bmTitle !== '' && $bmUrl !== '') {
            $pdo->prepare('INSERT INTO proxy_bookmarks (user_id, title, url, icon, default_mode) VALUES (:uid, :t, :u, :ic, :m)')
                ->execute([
                    ':uid' => $userId,
                    ':t'   => mb_substr($bmTitle, 0, 100),
                    ':u'   => mb_substr($bmUrl, 0, 1000),
                    ':ic'  => '🌐',
                    ':m'   => $bmMode,
                ]);
            setFlash('success', 'Schnellstart-Lesezeichen hinzugefügt! ⭐');
        }
        header('Location: proxy.php');
        exit;
    }

    if ($action === 'delete_bookmark') {
        $bmId = (int)($_POST['bookmark_id'] ?? 0);
        $pdo->prepare('DELETE FROM proxy_bookmarks WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $bmId, ':uid' => $userId]);
        setFlash('success', 'Lesezeichen gelöscht.');
        header('Location: proxy.php');
        exit;
    }

    if ($action === 'save_reader_to_notes') {
        $artTitle = trim((string)($_POST['article_title'] ?? 'Gespeicherter Artikel'));
        $artUrl   = trim((string)($_POST['article_url'] ?? ''));
        $artBody  = trim((string)($_POST['article_text'] ?? ''));

        if ($artBody !== '') {
            $noteContent = "🔗 Quelle: " . $artUrl . "\n📅 Gespeichert am: " . date('d.m.Y H:i') . "\n\n" . $artBody;
            $pdo->prepare('INSERT INTO notes (user_id, title, content, color, is_pinned) VALUES (:uid, :t, :c, :col, 0)')
                ->execute([
                    ':uid' => $userId,
                    ':t'   => '📖 ' . mb_substr($artTitle, 0, 180),
                    ':c'   => $noteContent,
                    ':col' => 'blue',
                ]);
            setFlash('success', 'Artikel wurde werbefrei in deinen MiniCloud-Notizen gespeichert! 📌');
        }
        header('Location: proxy.php?mode=reader&url=' . rawurlencode($artUrl));
        exit;
    }

    if ($action === 'test_domain_adblock') {
        $testInput = trim((string)($_POST['test_domain'] ?? ''));
        if ($testInput !== '') {
            $domainCheckResult = checkDomainOrUrlAgainstAdblock($testInput, $proxySettings, $userBlockedDomains, $userAllowedDomains);
            $domainCheckResult['input'] = $testInput;
        }
    }

    if ($action === 'reset_proxy_stats') {
        $pdo->prepare('DELETE FROM proxy_block_logs WHERE user_id = :uid')->execute([':uid' => $userId]);
        $pdo->prepare('
            UPDATE proxy_user_settings
            SET ads_blocked_total = 0,
                trackers_blocked_total = 0,
                cookies_blocked_total = 0,
                pages_proxied_total = 0
            WHERE user_id = :uid
        ')->execute([':uid' => $userId]);
        setFlash('success', 'Die Werbeblocker-Statistik und das Block-Protokoll wurden zurückgesetzt.');
        header('Location: proxy.php?tab=stats');
        exit;
    }
}

// =========================================================================
// AKTIVEN MODUS & GGF. ANGEFORDERTE ZIELSEITE VERARBEITEN
// =========================================================================
$activeTab  = (string)($_GET['tab'] ?? 'browser');
$browseMode = (string)($_GET['mode'] ?? 'proxy'); // 'proxy' oder 'reader'
if ($browseMode === 'reader') {
    $activeTab = 'reader';
}
if ($domainCheckResult !== null) {
    $activeTab = 'adblock';
}

$targetUrlInput = trim((string)($_GET['url'] ?? ''));
$fetchResult    = null;
$proxySanitized = null;
$readerArticle  = null;
$proxyError     = null;

if ($targetUrlInput !== '') {
    $err = null;
    $validatedUrl = validateSafeProxyTargetUrl($targetUrlInput, $err);
    if ($validatedUrl === null) {
        $proxyError = $err;
    } else {
        $fetchResult = fetchUrlViaSafeProxy($validatedUrl);
        if (!$fetchResult['ok']) {
            $proxyError = $fetchResult['error'];
        } else {
            $proxySanitized = sanitizeAndRewriteProxiedHtml(
                $fetchResult['body'],
                $fetchResult['final_url'],
                $proxySettings,
                $userBlockedDomains,
                $userAllowedDomains
            );
            $readerArticle = extractCleanArticleForReaderView($fetchResult['body'], $fetchResult['final_url']);

            // Schutz-Zähler in der Datenbank erhöhen
            $pdo->prepare('
                UPDATE proxy_user_settings
                SET ads_blocked_total = ads_blocked_total + :ads,
                    trackers_blocked_total = trackers_blocked_total + :trk,
                    cookies_blocked_total = cookies_blocked_total + :ck,
                    pages_proxied_total = pages_proxied_total + 1
                WHERE user_id = :uid
            ')->execute([
                ':ads' => $proxySanitized['ads_blocked'],
                ':trk' => $proxySanitized['trackers_blocked'],
                ':ck'  => $proxySanitized['cookies_blocked'],
                ':uid' => $userId,
            ]);

            // Blockierte Elemente einzeln für die Statistik-Auswertung protokollieren
            if (!empty($proxySanitized['blocked_items'])) {
                $srcDomain = strtolower((string)(parse_url($fetchResult['final_url'], PHP_URL_HOST) ?: 'Unbekannt'));
                $stmtLogIns = $pdo->prepare('
                    INSERT INTO proxy_block_logs (user_id, category, matched_rule, blocked_url, source_domain)
                    VALUES (:uid, :cat, :rule, :url, :src)
                ');
                foreach ($proxySanitized['blocked_items'] as $bi) {
                    $stmtLogIns->execute([
                        ':uid'  => $userId,
                        ':cat'  => mb_substr((string)$bi['type'], 0, 24),
                        ':rule' => mb_substr((string)$bi['rule'], 0, 190),
                        ':url'  => mb_substr((string)$bi['url'], 0, 490),
                        ':src'  => mb_substr($srcDomain, 0, 180),
                    ]);
                }
            }

            $proxySettings['ads_blocked_total']      = (int)$proxySettings['ads_blocked_total'] + $proxySanitized['ads_blocked'];
            $proxySettings['trackers_blocked_total'] = (int)$proxySettings['trackers_blocked_total'] + $proxySanitized['trackers_blocked'];
            $proxySettings['cookies_blocked_total']  = (int)$proxySettings['cookies_blocked_total'] + $proxySanitized['cookies_blocked'];
            $proxySettings['pages_proxied_total']    = (int)$proxySettings['pages_proxied_total'] + 1;
        }
    }
}

// =========================================================================
// STATISTIK-AUSWERTUNGEN LADEN (TOP-NETZWERKE, TOP-WEBSEITEN, 7-TAGE, LOG)
// =========================================================================
$stmtTopRules = $pdo->prepare('
    SELECT matched_rule, category, COUNT(*) AS hits
    FROM proxy_block_logs
    WHERE user_id = :uid AND matched_rule <> ""
    GROUP BY matched_rule, category
    ORDER BY hits DESC
    LIMIT 10
');
$stmtTopRules->execute([':uid' => $userId]);
$topBlockedRules = $stmtTopRules->fetchAll(PDO::FETCH_ASSOC);

$stmtTopSites = $pdo->prepare('
    SELECT source_domain,
           COUNT(*) AS total_hits,
           SUM(CASE WHEN category IN ("ad", "custom") THEN 1 ELSE 0 END) AS ad_hits,
           SUM(CASE WHEN category = "tracker" THEN 1 ELSE 0 END) AS tracker_hits,
           SUM(CASE WHEN category = "cookie" THEN 1 ELSE 0 END) AS cookie_hits
    FROM proxy_block_logs
    WHERE user_id = :uid AND source_domain <> ""
    GROUP BY source_domain
    ORDER BY total_hits DESC
    LIMIT 10
');
$stmtTopSites->execute([':uid' => $userId]);
$topTrackedSites = $stmtTopSites->fetchAll(PDO::FETCH_ASSOC);

$stmtDaily = $pdo->prepare('
    SELECT DATE(created_at) AS day_date,
           COUNT(*) AS total_hits,
           SUM(CASE WHEN category IN ("ad", "custom") THEN 1 ELSE 0 END) AS ad_hits,
           SUM(CASE WHEN category = "tracker" THEN 1 ELSE 0 END) AS tracker_hits,
           SUM(CASE WHEN category = "cookie" THEN 1 ELSE 0 END) AS cookie_hits
    FROM proxy_block_logs
    WHERE user_id = :uid AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day_date ASC
');
$stmtDaily->execute([':uid' => $userId]);
$dailyRaw = [];
foreach ($stmtDaily->fetchAll(PDO::FETCH_ASSOC) as $dRow) {
    $dailyRaw[(string)$dRow['day_date']] = $dRow;
}
$dailyStats7d = [];
$maxDailyHits = 1;
for ($i = 6; $i >= 0; $i--) {
    $dKey = date('Y-m-d', strtotime("-{$i} days"));
    $row  = $dailyRaw[$dKey] ?? ['total_hits' => 0, 'ad_hits' => 0, 'tracker_hits' => 0, 'cookie_hits' => 0];
    $tot  = (int)$row['total_hits'];
    if ($tot > $maxDailyHits) {
        $maxDailyHits = $tot;
    }
    $dailyStats7d[] = [
        'date'         => $dKey,
        'label'        => date('d.m.', strtotime($dKey)),
        'total_hits'   => $tot,
        'ad_hits'      => (int)$row['ad_hits'],
        'tracker_hits' => (int)$row['tracker_hits'],
        'cookie_hits'  => (int)$row['cookie_hits'],
    ];
}

$stmtRecentLogs = $pdo->prepare('
    SELECT * FROM proxy_block_logs
    WHERE user_id = :uid
    ORDER BY id DESC
    LIMIT 80
');
$stmtRecentLogs->execute([':uid' => $userId]);
$recentBlockLogs = $stmtRecentLogs->fetchAll(PDO::FETCH_ASSOC);

$totalBlockedAll = (int)$proxySettings['ads_blocked_total'] + (int)$proxySettings['trackers_blocked_total'] + (int)$proxySettings['cookies_blocked_total'];
$estimatedSavedKb = $totalBlockedAll * 42; // Durchschnittlich ~42 KB pro Werbebanner/Tracking-SDK eingespart

// Lesezeichen laden
$stmtBmList = $pdo->prepare('SELECT * FROM proxy_bookmarks WHERE user_id = :uid ORDER BY id ASC');
$stmtBmList->execute([':uid' => $userId]);
$bookmarks = $stmtBmList->fetchAll(PDO::FETCH_ASSOC);

// PAC-URL zusammenbauen
$schemeProto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hostHeader  = (string)($_SERVER['HTTP_HOST'] ?? 'runready.cloud');
$scriptDir   = rtrim( str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/proxy.php'))), '/' );
$pacFullUrl  = $schemeProto . '://' . $hostHeader . $scriptDir . '/proxy.php?pac=1&token=' . rawurlencode((string)$proxySettings['pac_token']);

$totalDomainsInCoreList = count(getCoreAdDomains()) + count(getCoreTrackerDomains()) + count(getCoreCookieBannerPatterns()) + count($userBlockedDomains);
$currentClientIp        = (string)($_SERVER['REMOTE_ADDR'] ?? 'Unbekannt');
$serverPublicIp         = (string)($_SERVER['SERVER_ADDR'] ?? '169.58.95.2');

$pageTitle = '🛡️ Proxy & Werbeblocker';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto space-y-6">
    <!-- Kopfbereich & Live-Schutz-Statistik -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-800">
                    <span>🛡️ Schutz aktiv</span>
                    <span>&middot;</span>
                    <span>Server-IP: <?= e($serverPublicIp) ?> (600 Mbit/s Backbone)</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                    <span>🛡️ MiniCloud Proxy, Werbeblocker &amp; Reader</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                    Anonym über <code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-xs">runready.cloud</code> surfen, Werbe-/Tracking-Skripte &amp; Cookie-Banner automatisch herausfiltern oder Nachrichten im reinen Lesemodus öffnen.
                </p>
            </div>

            <!-- Statistik-Kacheln (Klick öffnet die ausführliche Schutz-Statistik) -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 shrink-0">
                <button type="button" onclick="switchProxyTab('stats')" class="p-3 rounded-xl bg-rose-50/80 dark:bg-rose-950/40 hover:bg-rose-100/80 dark:hover:bg-rose-900/50 border border-rose-200/70 dark:border-rose-900/60 text-center transition-colors cursor-pointer" title="Klicken, um die ausführliche Werbeblocker-Statistik zu öffnen">
                    <div class="text-lg font-extrabold text-rose-600 dark:text-rose-400">
                        <?= number_format((int)$proxySettings['ads_blocked_total'], 0, ',', '.') ?>
                    </div>
                    <div class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">🚫 Werbung blockiert</div>
                </button>
                <button type="button" onclick="switchProxyTab('stats')" class="p-3 rounded-xl bg-amber-50/80 dark:bg-amber-950/40 hover:bg-amber-100/80 dark:hover:bg-amber-900/50 border border-amber-200/70 dark:border-amber-900/60 text-center transition-colors cursor-pointer" title="Klicken, um die ausführliche Tracker-Statistik zu öffnen">
                    <div class="text-lg font-extrabold text-amber-600 dark:text-amber-400">
                        <?= number_format((int)$proxySettings['trackers_blocked_total'], 0, ',', '.') ?>
                    </div>
                    <div class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">🕵️ Tracker gestoppt</div>
                </button>
                <button type="button" onclick="switchProxyTab('stats')" class="p-3 rounded-xl bg-indigo-50/80 dark:bg-indigo-950/40 hover:bg-indigo-100/80 dark:hover:bg-indigo-900/50 border border-indigo-200/70 dark:border-indigo-900/60 text-center transition-colors cursor-pointer" title="Klicken, um die Cookie-Banner-Statistik zu öffnen">
                    <div class="text-lg font-extrabold text-indigo-600 dark:text-indigo-400">
                        <?= number_format((int)$proxySettings['cookies_blocked_total'], 0, ',', '.') ?>
                    </div>
                    <div class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">🍪 Cookie-Banner</div>
                </button>
                <button type="button" onclick="switchProxyTab('stats')" class="p-3 rounded-xl bg-emerald-50/80 dark:bg-emerald-950/40 hover:bg-emerald-100/80 dark:hover:bg-emerald-900/50 border border-emerald-200/70 dark:border-emerald-900/60 text-center transition-colors cursor-pointer" title="Klicken, um das vollständige Block-Protokoll zu öffnen">
                    <div class="text-lg font-extrabold text-emerald-600 dark:text-emerald-400">
                        <?= number_format($totalDomainsInCoreList, 0, ',', '.') ?>+
                    </div>
                    <div class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">📊 Statistik &amp; Log</div>
                </button>
            </div>
        </div>

        <!-- Adresszeile & Modus-Wahl (Immer oben sichtbar) -->
        <form method="get" action="proxy.php" class="mt-5 pt-4 border-t border-slate-200/80 dark:border-slate-800">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                <div class="relative flex-1">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-base select-none">🔒</span>
                    <input
                        type="text"
                        name="url"
                        value="<?= e($fetchResult['final_url'] ?? $targetUrlInput) ?>"
                        placeholder="Webadresse (z. B. heise.de, tagesschau.de, wikipedia.org) oder Suchbegriff eingeben…"
                        class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/90 text-sm font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <button
                        type="submit"
                        name="mode"
                        value="proxy"
                        class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold <?= ($browseMode === 'proxy' && $activeTab !== 'adblock') ? 'bg-brand-600 hover:bg-brand-700 text-white shadow-xs' : 'bg-slate-200 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-slate-800 dark:text-slate-100' ?> transition-colors cursor-pointer"
                        title="Webseite vollständig über den MiniCloud-Werbeblocker-Proxy laden"
                    >
                        <span>🌐</span>
                        <span>Ad-Free Proxy</span>
                    </button>

                    <button
                        type="submit"
                        name="mode"
                        value="reader"
                        class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-xs sm:text-sm font-bold <?= ($browseMode === 'reader' || $activeTab === 'reader') ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs' : 'bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-600 hover:text-white text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/70' ?> transition-colors cursor-pointer"
                        title="Nur den reinen Artikeltext &amp; Bilder ohne jeglichen Seiten-Ballast extrahieren"
                    >
                        <span>📖</span>
                        <span>Lesemodus (Reader)</span>
                    </button>
                </div>
            </div>

            <!-- Schnellstart-Lesezeichen -->
            <?php if (!empty($bookmarks)): ?>
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mr-1">⚡ Schnellstart:</span>
                    <?php foreach ($bookmarks as $bm): ?>
                        <a
                            href="proxy.php?mode=<?= e((string)$bm['default_mode']) ?>&amp;url=<?= rawurlencode((string)$bm['url']) ?>"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-brand-600 hover:text-white text-xs font-medium text-slate-700 dark:text-slate-200 transition-colors"
                            title="<?= e((string)$bm['url']) ?> (<?= $bm['default_mode'] === 'reader' ? 'Lesemodus' : 'Web-Proxy' ?>)"
                        >
                            <span><?= e((string)$bm['icon']) ?></span>
                            <span><?= e((string)$bm['title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </form>

        <!-- Reiter-Navigation -->
        <div class="mt-5 pt-3 border-t border-slate-200/80 dark:border-slate-800 flex flex-wrap items-center gap-2">
            <button
                type="button"
                onclick="switchProxyTab('browser')"
                id="tabBtn-browser"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer <?= $activeTab === 'browser' ? 'bg-brand-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                🌐 1. Web-Proxy (Browser im Browser)
            </button>

            <button
                type="button"
                onclick="switchProxyTab('reader')"
                id="tabBtn-reader"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer <?= $activeTab === 'reader' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                📖 2. Werbefreier Lesemodus (Reader View)
            </button>

            <button
                type="button"
                onclick="switchProxyTab('adblock')"
                id="tabBtn-adblock"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer <?= $activeTab === 'adblock' ? 'bg-emerald-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                📡 3. System-Werbeblocker (PAC) &amp; Filterregeln
            </button>

            <button
                type="button"
                onclick="switchProxyTab('stats')"
                id="tabBtn-stats"
                class="px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer <?= $activeTab === 'stats' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' ?>"
            >
                📊 4. Schutz-Statistik &amp; Block-Protokoll (<?= number_format($totalBlockedAll, 0, ',', '.') ?>)
            </button>
        </div>
    </section>

    <?php if ($proxyError !== null): ?>
        <div class="p-4 rounded-2xl bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm font-medium flex items-center gap-3">
            <span class="text-xl">⚠️</span>
            <div>
                <div class="font-bold">Abruf über den Proxy nicht möglich</div>
                <div class="text-xs mt-0.5"><?= e($proxyError) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- REITER 1: WEB-PROXY („BROWSER IM BROWSER“)                          -->
    <!-- =================================================================== -->
    <div id="proxyTab-browser" class="<?= $activeTab === 'browser' ? '' : 'hidden' ?> space-y-4">
        <?php if ($proxySanitized !== null && $fetchResult !== null): ?>
            <!-- Live-Schutz-Bilanz der aktuell geladenen Seite -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-xs font-bold shrink-0">
                            HTTP <?= (int)$fetchResult['status'] ?> &middot; <?= (int)$fetchResult['duration_ms'] ?> ms
                        </span>
                        <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                            <?= e($proxySanitized['title']) ?>
                        </h2>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-1 rounded-lg bg-rose-100 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 text-xs font-bold">
                            🚫 <?= (int)$proxySanitized['ads_blocked'] ?> Werbung
                        </span>
                        <span class="px-2.5 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 text-xs font-bold">
                            🕵️ <?= (int)$proxySanitized['trackers_blocked'] ?> Tracker
                        </span>
                        <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-950/70 text-indigo-800 dark:text-indigo-300 text-xs font-bold">
                            🍪 <?= (int)$proxySanitized['cookies_blocked'] ?> Cookie-Skripte
                        </span>
                        <?php if ($proxySanitized['params_stripped'] > 0): ?>
                            <span class="px-2.5 py-1 rounded-lg bg-sky-100 dark:bg-sky-950/70 text-sky-800 dark:text-sky-300 text-xs font-bold">
                                🔗 <?= (int)$proxySanitized['params_stripped'] ?> Tracking-Parameter entfernt
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($proxySanitized['blocked_items'])): ?>
                            <button
                                type="button"
                                onclick="document.getElementById('blockedDetailsBox').classList.toggle('hidden')"
                                class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer"
                            >
                                🔍 Blockierte Elemente (<?= count($proxySanitized['blocked_items']) ?>) ▾
                            </button>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="switchProxyTab('reader')"
                            class="px-3 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold cursor-pointer"
                        >
                            📖 In Lesemodus wechseln
                        </button>
                    </div>
                </div>

                <?php if (!empty($proxySanitized['blocked_items'])): ?>
                    <div id="blockedDetailsBox" class="hidden pt-3 border-t border-slate-200/80 dark:border-slate-800">
                        <div class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">
                            🛡️ Auf dieser Seite herausgefilterte Werbe-, Tracking- &amp; Consent-Elemente:
                        </div>
                        <div class="max-h-44 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-1.5 text-[11px]">
                            <?php foreach ($proxySanitized['blocked_items'] as $bi): ?>
                                <div class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-slate-50 dark:bg-slate-800/70 border border-slate-200/60 dark:border-slate-700/60">
                                    <span class="font-bold text-rose-600 dark:text-rose-400 shrink-0">
                                        <?= e(strtoupper($bi['type'])) ?>: <?= e($bi['rule']) ?>
                                    </span>
                                    <span class="text-slate-400 truncate font-mono"><?= e($bi['url']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Geproxte Webseite in isoliertem Sandbox-Iframe (srcdoc) -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                <div class="px-4 py-2 bg-slate-100 dark:bg-slate-800/90 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <div class="flex items-center gap-2 truncate">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                        <span class="font-mono truncate"><?= e($fetchResult['final_url']) ?></span>
                    </div>
                    <a
                        href="<?= e($fetchResult['final_url']) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shrink-0 font-semibold text-brand-600 dark:text-brand-400 hover:underline"
                    >
                        ↗ Direkt öffnen
                    </a>
                </div>
                <iframe
                    id="proxiedViewportFrame"
                    sandbox="allow-same-origin allow-forms"
                    referrerpolicy="no-referrer"
                    srcdoc="<?= e($proxySanitized['html']) ?>"
                    class="w-full h-[74vh] bg-white border-0"
                    title="MiniCloud Ad-Free Proxy Ansicht"
                ></iframe>
            </div>
        <?php else: ?>
            <!-- Startbildschirm des Web-Proxys, wenn noch keine URL geladen wurde -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 sm:p-8 text-center space-y-5">
                <div class="w-16 h-16 mx-auto rounded-2xl bg-brand-50 dark:bg-brand-950/60 border border-brand-200 dark:border-brand-800 flex items-center justify-center text-3xl">
                    🌐
                </div>
                <div class="max-w-xl mx-auto space-y-2">
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">
                        Bereit zum werbefreien &amp; anonymen Surfen
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                        Gib oben eine Webadresse oder einen Suchbegriff ein oder klicke auf eines deiner Schnellstart-Lesezeichen.
                        Die Zielseite sieht nur die IP-Adresse von <code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800">runready.cloud</code> – Werbung, Zählpixel und Cookie-Banner werden bereits auf dem Server herausgefiltert.
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 max-w-3xl mx-auto text-left pt-2">
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 space-y-1">
                        <div class="text-sm font-bold text-slate-800 dark:text-slate-100">🛡️ Server-Seitiger AdBlock</div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Entfernt AdSense, DoubleClick, Taboola, Outbrain, Criteo, Google Analytics &amp; IVW-Zählpixel direkt aus dem HTML-Quelltext.
                        </p>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 space-y-1">
                        <div class="text-sm font-bold text-slate-800 dark:text-slate-100">🍪 Cookie-Banner-Filter</div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Unterdrückt OneTrust, Cookiebot, Usercentrics, Sourcepoint &amp; ConsentManager-Overlays automatisch.
                        </p>
                    </div>
                    <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 space-y-1">
                        <div class="text-sm font-bold text-slate-800 dark:text-slate-100">🔒 Striktes Sandbox-Prison</div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Die Seite läuft in einer isolierten Sandbox ohne Zugriff auf deine Cookies, deinen Standort oder deine echte IP-Adresse.
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- =================================================================== -->
    <!-- REITER 2: WERBEFREIER ARTIKEL-LESEMODUS (READER VIEW)               -->
    <!-- =================================================================== -->
    <div id="proxyTab-reader" class="<?= $activeTab === 'reader' ? '' : 'hidden' ?> space-y-4">
        <?php if ($readerArticle !== null && $fetchResult !== null): ?>
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-8 shadow-xs max-w-4xl mx-auto space-y-6">
                <!-- Reader-Werkzeugleiste -->
                <div class="flex flex-wrap items-center justify-between gap-3 pb-4 border-b border-slate-200 dark:border-slate-800">
                    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                        <span class="px-2.5 py-1 rounded-lg bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300 font-bold">
                            📖 Reader View
                        </span>
                        <span><?= e($readerArticle['site_name']) ?></span>
                        <span>&middot;</span>
                        <span>⏱️ ca. <?= (int)$readerArticle['reading_minutes'] ?> Min. Lesezeit (<?= number_format((int)$readerArticle['word_count'], 0, ',', '.') ?> Wörter)</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Schriftgröße -->
                        <div class="inline-flex items-center rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                            <button type="button" onclick="adjustReaderFontSize(-1)" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-bold cursor-pointer" title="Schrift verkleinern">A-</button>
                            <button type="button" onclick="adjustReaderFontSize(1)" class="px-2.5 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-xs font-bold cursor-pointer" title="Schrift vergrößern">A+</button>
                        </div>

                        <!-- Vorlesen -->
                        <button
                            type="button"
                            id="readerTtsBtn"
                            onclick="toggleReaderSpeech()"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/70 hover:bg-amber-200 text-amber-800 dark:text-amber-300 text-xs font-bold cursor-pointer"
                        >
                            <span>🔊</span>
                            <span id="readerTtsLabel">Vorlesen</span>
                        </button>

                        <!-- In Notizen speichern -->
                        <form method="post" action="proxy.php" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save_reader_to_notes">
                            <input type="hidden" name="article_title" value="<?= e($readerArticle['title']) ?>">
                            <input type="hidden" name="article_url" value="<?= e($fetchResult['final_url']) ?>">
                            <input type="hidden" name="article_text" value="<?= e($readerArticle['plain_text']) ?>">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold cursor-pointer"
                            >
                                <span>📌</span>
                                <span>In Notizen speichern</span>
                            </button>
                        </form>

                        <!-- Drucken / Als PDF speichern -->
                        <button
                            type="button"
                            onclick="window.print()"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-200 text-xs font-bold cursor-pointer"
                        >
                            <span>🖨️</span>
                            <span>PDF / Drucken</span>
                        </button>
                    </div>
                </div>

                <!-- Artikel-Kopf -->
                <header class="space-y-3">
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white leading-tight">
                        <?= e($readerArticle['title']) ?>
                    </h1>
                    <?php if ($readerArticle['subtitle'] !== ''): ?>
                        <p class="text-base sm:text-lg font-medium text-slate-600 dark:text-slate-300 leading-relaxed">
                            <?= e($readerArticle['subtitle']) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($readerArticle['lead_image'] !== ''): ?>
                        <div class="pt-2">
                            <img
                                src="proxy.php?asset=1&amp;url=<?= rawurlencode($readerArticle['lead_image']) ?>"
                                alt="<?= e($readerArticle['title']) ?>"
                                class="w-full max-h-96 object-cover rounded-2xl border border-slate-200 dark:border-slate-800"
                                loading="lazy"
                            >
                        </div>
                    <?php endif; ?>
                </header>

                <!-- Bereinigter Artikel-Inhalt -->
                <article id="readerArticleContent" class="space-y-4 text-base leading-relaxed text-slate-800 dark:text-slate-200">
                    <?php if (empty($readerArticle['blocks'])): ?>
                        <p class="text-sm text-slate-500 italic">
                            Auf dieser Übersichtsseite wurde kein einzelner Fließtext-Artikel erkannt. Wechsle oben auf <strong>🌐 1. Web-Proxy</strong>, um auf einen konkreten Artikel zu klicken.
                        </p>
                    <?php else: ?>
                        <?php foreach ($readerArticle['blocks'] as $blk): ?>
                            <?php if ($blk['type'] === 'h2'): ?>
                                <h2 class="text-xl font-bold text-slate-900 dark:text-white pt-4"><?= e($blk['text']) ?></h2>
                            <?php elseif ($blk['type'] === 'h3'): ?>
                                <h3 class="text-lg font-bold text-slate-900 dark:text-white pt-3"><?= e($blk['text']) ?></h3>
                            <?php elseif ($blk['type'] === 'blockquote'): ?>
                                <blockquote class="pl-4 border-l-4 border-indigo-500 italic text-slate-600 dark:text-slate-300 my-3">
                                    <?= e($blk['text']) ?>
                                </blockquote>
                            <?php elseif ($blk['type'] === 'li'): ?>
                                <div class="flex items-start gap-2 pl-2">
                                    <span class="text-indigo-500 font-bold">&bull;</span>
                                    <span><?= e($blk['text']) ?></span>
                                </div>
                            <?php else: ?>
                                <p><?= e($blk['text']) ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            </div>
        <?php else: ?>
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 text-center space-y-3">
                <div class="text-4xl">📖</div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Werbefreier Artikel-Lesemodus (Reader View)</h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-lg mx-auto">
                    Gib oben die Adresse eines Nachrichten-Artikels oder Blog-Beitrags ein und klicke auf <strong>📖 Lesemodus (Reader)</strong>.
                    Die MiniCloud extrahiert nur Überschrift, Hauptbild und Fließtext – komplett ohne Werbung, Pop-ups oder Seitenleisten.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- =================================================================== -->
    <!-- REITER 3: SYSTEM-WERBEBLOCKER (PAC), FILTERREGELN & LESEZEICHEN     -->
    <!-- =================================================================== -->
    <div id="proxyTab-adblock" class="<?= $activeTab === 'adblock' ? '' : 'hidden' ?> space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Linke Spalte: Systemweiter AdBlocker (PAC) & Filter-Schalter -->
            <div class="space-y-6">
                <!-- 1. Systemweite Auto-Proxy-URL (proxy.pac) -->
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>📡 Systemweiter Werbeblocker (`proxy.pac`)</span>
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                Trage diese persönliche Auto-Konfigurations-URL in Firefox, Chrome, Windows, macOS, iOS oder Android unter <strong>„Automatische Proxy-Konfiguration (PAC)“</strong> ein. Alle bekannten Werbe-, Tracking- und Telemetrie-Domains (inkl. deiner eigenen Blacklist) werden dann systemweit blockiert!
                            </p>
                        </div>
                        <span class="px-2.5 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 text-[11px] font-bold shrink-0">
                            Aktiv
                        </span>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2">
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            🔗 Deine persönliche PAC-Konfigurations-URL:
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                id="pacUrlField"
                                readonly
                                value="<?= e($pacFullUrl) ?>"
                                class="flex-1 px-3 py-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-800 dark:text-slate-100"
                            >
                            <button
                                type="button"
                                onclick="navigator.clipboard.writeText(document.getElementById('pacUrlField').value); this.textContent='✅ Kopiert!'; setTimeout(()=>this.textContent='📋 Kopieren', 2000);"
                                class="px-3 py-2 rounded-lg bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold shrink-0 cursor-pointer"
                            >
                                📋 Kopieren
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs">
                        <div class="text-slate-500 dark:text-slate-400">
                            Deine aktuelle IP: <strong class="text-slate-800 dark:text-slate-200 font-mono"><?= e($currentClientIp) ?></strong>
                            <?php if ($proxySettings['allowed_client_ip'] !== ''): ?>
                                &middot; Freigeschaltet: <strong class="text-emerald-600 dark:text-emerald-400 font-mono"><?= e((string)$proxySettings['allowed_client_ip']) ?></strong>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <form method="post" action="proxy.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="authorize_current_ip">
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold cursor-pointer">
                                    🔓 Meine IP freischalten
                                </button>
                            </form>
                            <form method="post" action="proxy.php" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="regenerate_pac_token">
                                <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 text-slate-700 dark:text-slate-200 text-xs font-semibold cursor-pointer">
                                    🔄 Token erneuern
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 2. Schutz-Module & Filter-Stärke einstellen -->
                <form method="post" action="proxy.php" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="save_proxy_settings">

                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        ⚙️ Aktive Schutz-Filter (Web-Proxy &amp; PAC)
                    </h2>

                    <div class="space-y-2.5">
                        <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 cursor-pointer">
                            <input type="checkbox" name="block_ads" value="1" <?= (int)$proxySettings['block_ads'] === 1 ? 'checked' : '' ?> class="mt-1 rounded text-brand-600">
                            <div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🚫 Werbenetzwerke &amp; Banner blockieren</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Filtert Google AdSense, DoubleClick, Taboola, Outbrain, Criteo, SmartAdServer, Amazon Ads &amp; Pop-ups.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 cursor-pointer">
                            <input type="checkbox" name="block_trackers" value="1" <?= (int)$proxySettings['block_trackers'] === 1 ? 'checked' : '' ?> class="mt-1 rounded text-brand-600">
                            <div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🕵️ Analyse-Tracker &amp; Zählpixel stoppen</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Blockiert Google Analytics, Tag Manager, Meta/Facebook Pixel, TikTok Pixel, Hotjar, Clarity &amp; IVW/INFOnline.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 cursor-pointer">
                            <input type="checkbox" name="block_cookies" value="1" <?= (int)$proxySettings['block_cookies'] === 1 ? 'checked' : '' ?> class="mt-1 rounded text-brand-600">
                            <div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🍪 Cookie-Consent- &amp; Nerv-Banner ausblenden</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Unterdrückt OneTrust, Cookiebot, Usercentrics, Sourcepoint, ConsentManager &amp; Didomi-Dialoge.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 cursor-pointer">
                            <input type="checkbox" name="strip_tracking_params" value="1" <?= (int)$proxySettings['strip_tracking_params'] === 1 ? 'checked' : '' ?> class="mt-1 rounded text-brand-600">
                            <div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🔗 Tracking-Parameter aus Links schneiden</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Entfernt automatisch <code class="font-mono">utm_source</code>, <code class="font-mono">fbclid</code>, <code class="font-mono">gclid</code>, <code class="font-mono">msclkid</code> &amp; Kampagnen-IDs.</div>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/70 cursor-pointer">
                            <input type="checkbox" name="block_external_scripts" value="1" <?= (int)$proxySettings['block_external_scripts'] === 1 ? 'checked' : '' ?> class="mt-1 rounded text-brand-600">
                            <div>
                                <div class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white">🔒 Strenger NoScript-Modus (Alle JavaScripte im Web-Proxy entfernen)</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">Maximale Sicherheit &amp; Geschwindigkeit für reine Text-/Nachrichtenseiten.</div>
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs sm:text-sm font-bold shadow-xs cursor-pointer">
                        💾 Filter-Einstellungen speichern
                    </button>
                </form>
            </div>

            <!-- Rechte Spalte: Live-Domain-Prüfer, Eigene Blacklist/Whitelist & Lesezeichen -->
            <div class="space-y-6">
                <!-- 3. Live-Domain-Prüfer -->
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        🧪 Live-Domain- &amp; Werbeblocker-Tester
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Prüfe sofort, ob eine Domain oder URL (z. B. <code class="font-mono">doubleclick.net</code>, <code class="font-mono">ioam.de</code> oder <code class="font-mono">heise.de</code>) vom MiniCloud-Werbeblocker blockiert wird.
                    </p>
                    <form method="post" action="proxy.php?tab=adblock" class="flex items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="test_domain_adblock">
                        <input
                            type="text"
                            name="test_domain"
                            value="<?= e((string)($domainCheckResult['input'] ?? '')) ?>"
                            placeholder="z. B. pagead2.googlesyndication.com oder spiegel.de"
                            class="flex-1 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs sm:text-sm"
                        >
                        <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs sm:text-sm font-bold cursor-pointer">
                            Prüfen
                        </button>
                    </form>

                    <?php if ($domainCheckResult !== null): ?>
                        <div class="p-3.5 rounded-xl border <?= $domainCheckResult['blocked'] ? 'bg-rose-50 dark:bg-rose-950/50 border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-200' : 'bg-emerald-50 dark:bg-emerald-950/50 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200' ?> text-xs space-y-1">
                            <div class="font-bold text-sm">
                                <?= $domainCheckResult['blocked'] ? '🚫 WIRD BLOCKIERT' : '✅ ERLAUBT (Sauber)' ?>
                                – <span class="font-mono"><?= e((string)$domainCheckResult['input']) ?></span>
                            </div>
                            <div>Grund: <strong><?= e($domainCheckResult['reason']) ?></strong></div>
                            <?php if ($domainCheckResult['matched_rule'] !== ''): ?>
                                <div>Greifende Regel: <code class="px-1.5 py-0.5 rounded bg-black/10 font-mono"><?= e($domainCheckResult['matched_rule']) ?></code></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 4. Eigene Blacklist & Whitelist -->
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        📋 Eigene Sperr- &amp; Freigabeliste (Blacklist / Whitelist)
                    </h2>

                    <form method="post" action="proxy.php" class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add_custom_rule">
                        <input
                            type="text"
                            name="domain_pattern"
                            placeholder="Domain (z. B. nerv-werbung.de)"
                            required
                            class="sm:col-span-6 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs"
                        >
                        <select name="rule_type" class="sm:col-span-3 px-2.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                            <option value="block">🚫 Blockieren</option>
                            <option value="allow">✅ Erlauben</option>
                        </select>
                        <button type="submit" class="sm:col-span-3 px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold cursor-pointer">
                            + Hinzufügen
                        </button>
                    </form>

                    <?php if (empty($customRules)): ?>
                        <p class="text-xs text-slate-400 italic">Noch keine eigenen Ausnahme- oder Sperrregeln hinterlegt. Die integrierten Filterlisten (<?= $totalDomainsInCoreList ?>+ Domains) sind aktiv.</p>
                    <?php else: ?>
                        <div class="max-h-48 overflow-y-auto space-y-1.5">
                            <?php foreach ($customRules as $cr): ?>
                                <div class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70 text-xs">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $cr['rule_type'] === 'allow' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300' ?>">
                                            <?= $cr['rule_type'] === 'allow' ? 'WHITELIST' : 'BLACKLIST' ?>
                                        </span>
                                        <span class="font-mono font-bold text-slate-800 dark:text-slate-100 truncate"><?= e((string)$cr['domain_pattern']) ?></span>
                                    </div>
                                    <form method="post" action="proxy.php" class="inline shrink-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_custom_rule">
                                        <input type="hidden" name="rule_id" value="<?= (int)$cr['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-bold cursor-pointer" title="Regel löschen">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 5. Schnellstart-Lesezeichen verwalten -->
                <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        ⭐ Schnellstart-Lesezeichen verwalten
                    </h2>
                    <form method="post" action="proxy.php" class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add_bookmark">
                        <input type="text" name="title" placeholder="Name (z. B. Golem)" required class="sm:col-span-4 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs">
                        <input type="text" name="url" placeholder="https://www.golem.de" required class="sm:col-span-4 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs">
                        <select name="default_mode" class="sm:col-span-2 px-2 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold">
                            <option value="proxy">🌐 Proxy</option>
                            <option value="reader">📖 Reader</option>
                        </select>
                        <button type="submit" class="sm:col-span-2 px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold cursor-pointer">
                            + Merken
                        </button>
                    </form>

                    <?php if (!empty($bookmarks)): ?>
                        <div class="space-y-1.5 max-h-40 overflow-y-auto">
                            <?php foreach ($bookmarks as $bm): ?>
                                <div class="flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 text-xs">
                                    <div class="truncate">
                                        <strong><?= e((string)$bm['title']) ?></strong>
                                        <span class="text-slate-400 font-mono ml-1">(<?= e((string)$bm['url']) ?>)</span>
                                    </div>
                                    <form method="post" action="proxy.php" class="inline shrink-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_bookmark">
                                        <input type="hidden" name="bookmark_id" value="<?= (int)$bm['id'] ?>">
                                        <button type="submit" class="text-red-500 hover:text-red-700 font-bold cursor-pointer">✕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- REITER 4: SCHUTZ-STATISTIK & BLOCK-PROTOKOLL (PI-HOLE / ADGUARD)    -->
    <!-- =================================================================== -->
    <div id="proxyTab-stats" class="<?= $activeTab === 'stats' ? '' : 'hidden' ?> space-y-6">
        <!-- Obere Gesamt-Kennzahlen -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>📊 Ausführliche Werbeblocker- &amp; Tracker-Statistik</span>
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Alle abgefangenen Werbenetzwerke, Analyse-Tracker, Cookie-Banner und Zählpixel über deinen MiniCloud-Proxy.
                    </p>
                </div>

                <form method="post" action="proxy.php" onsubmit="return confirm('Möchtest du die gesamte Statistik und das Block-Protokoll wirklich auf 0 zurücksetzen?');">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_proxy_stats">
                    <button type="submit" class="px-3 py-1.5 rounded-xl bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-900 text-xs font-bold cursor-pointer">
                        🗑️ Statistik &amp; Protokoll zurücksetzen
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/70 dark:border-slate-700/70">
                    <div class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">🌐 Geprüfte Seiten</div>
                    <div class="text-xl font-extrabold text-slate-900 dark:text-white mt-0.5">
                        <?= number_format((int)$proxySettings['pages_proxied_total'], 0, ',', '.') ?>
                    </div>
                </div>
                <div class="p-3.5 rounded-xl bg-rose-50/70 dark:bg-rose-950/40 border border-rose-200/70 dark:border-rose-900/60">
                    <div class="text-[11px] font-semibold text-rose-700 dark:text-rose-300">🚫 Werbung blockiert</div>
                    <div class="text-xl font-extrabold text-rose-600 dark:text-rose-400 mt-0.5">
                        <?= number_format((int)$proxySettings['ads_blocked_total'], 0, ',', '.') ?>
                    </div>
                </div>
                <div class="p-3.5 rounded-xl bg-amber-50/70 dark:bg-amber-950/40 border border-amber-200/70 dark:border-amber-900/60">
                    <div class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">🕵️ Tracker gestoppt</div>
                    <div class="text-xl font-extrabold text-amber-600 dark:text-amber-400 mt-0.5">
                        <?= number_format((int)$proxySettings['trackers_blocked_total'], 0, ',', '.') ?>
                    </div>
                </div>
                <div class="p-3.5 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/70 dark:border-indigo-900/60">
                    <div class="text-[11px] font-semibold text-indigo-700 dark:text-indigo-300">🍪 Cookie-Overlays</div>
                    <div class="text-xl font-extrabold text-indigo-600 dark:text-indigo-400 mt-0.5">
                        <?= number_format((int)$proxySettings['cookies_blocked_total'], 0, ',', '.') ?>
                    </div>
                </div>
                <div class="p-3.5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/40 border border-emerald-200/70 dark:border-emerald-900/60">
                    <div class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">💾 Traffic gespart</div>
                    <div class="text-xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-0.5">
                        <?= $estimatedSavedKb >= 1024 ? number_format($estimatedSavedKb / 1024, 1, ',', '.') . ' MB' : number_format($estimatedSavedKb, 0, ',', '.') . ' KB' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 7-Tage-Balkendiagramm -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    📅 Blockierte Elemente der letzten 7 Tage
                </h3>
                <div class="flex items-center gap-3 text-[11px] font-semibold">
                    <span class="inline-flex items-center gap-1 text-rose-600 dark:text-rose-400"><span class="w-2.5 h-2.5 rounded-xs bg-rose-500"></span> Werbung</span>
                    <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400"><span class="w-2.5 h-2.5 rounded-xs bg-amber-500"></span> Tracker</span>
                    <span class="inline-flex items-center gap-1 text-indigo-600 dark:text-indigo-400"><span class="w-2.5 h-2.5 rounded-xs bg-indigo-500"></span> Cookie-Banner</span>
                </div>
            </div>

            <div class="grid grid-cols-7 gap-2 sm:gap-4 items-end h-44 pt-4 px-2 border-b border-slate-200 dark:border-slate-800">
                <?php foreach ($dailyStats7d as $dayItem): ?>
                    <?php
                    $barPct = $maxDailyHits > 0 ? max(4, (int)round(($dayItem['total_hits'] / $maxDailyHits) * 100)) : 4;
                    if ($dayItem['total_hits'] === 0) {
                        $barPct = 3;
                    }
                    ?>
                    <div class="flex flex-col items-center justify-end h-full gap-1.5">
                        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-200">
                            <?= (int)$dayItem['total_hits'] ?>
                        </span>
                        <div class="w-full max-w-[44px] rounded-t-xl overflow-hidden bg-slate-100 dark:bg-slate-800 flex flex-col justify-end" style="height: <?= $barPct ?>%;">
                            <?php if ($dayItem['total_hits'] > 0): ?>
                                <?php
                                $adP = (int)round(($dayItem['ad_hits'] / $dayItem['total_hits']) * 100);
                                $trP = (int)round(($dayItem['tracker_hits'] / $dayItem['total_hits']) * 100);
                                $ckP = max(0, 100 - $adP - $trP);
                                ?>
                                <div class="w-full bg-indigo-500" style="height: <?= $ckP ?>%" title="Cookie-Banner: <?= (int)$dayItem['cookie_hits'] ?>"></div>
                                <div class="w-full bg-amber-500" style="height: <?= $trP ?>%" title="Tracker: <?= (int)$dayItem['tracker_hits'] ?>"></div>
                                <div class="w-full bg-rose-500" style="height: <?= $adP ?>%" title="Werbung: <?= (int)$dayItem['ad_hits'] ?>"></div>
                            <?php else: ?>
                                <div class="w-full h-full bg-slate-200 dark:bg-slate-700"></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-[11px] font-mono text-slate-500 dark:text-slate-400 pb-1"><?= e($dayItem['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Top 10 Werbe-/Tracking-Netzwerke & Top 10 Webseiten -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Top 10 blockierte Werbe- & Tracking-Netzwerke -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    🏆 Top 10 blockierte Werbe- &amp; Tracking-Dienste
                </h3>
                <?php if (empty($topBlockedRules)): ?>
                    <p class="text-xs text-slate-400 italic py-4">
                        Noch keine Einträge im Block-Protokoll. Rufe eine Nachrichtenseite (z. B. <code class="font-mono">spiegel.de</code>, <code class="font-mono">kicker.de</code> oder <code class="font-mono">heise.de</code>) über den Web-Proxy auf, um Live-Daten zu sammeln!
                    </p>
                <?php else: ?>
                    <?php $maxRuleHits = max(1, (int)$topBlockedRules[0]['hits']); ?>
                    <div class="space-y-2.5">
                        <?php foreach ($topBlockedRules as $tr): ?>
                            <?php
                            $hits = (int)$tr['hits'];
                            $pct  = max(6, (int)round(($hits / $maxRuleHits) * 100));
                            $cat  = (string)$tr['category'];
                            $barCol = $cat === 'tracker' ? 'bg-amber-500' : ($cat === 'cookie' ? 'bg-indigo-500' : 'bg-rose-500');
                            $badgeLabel = $cat === 'tracker' ? '🕵️ Tracker' : ($cat === 'cookie' ? '🍪 Cookie' : '🚫 Werbung');
                            ?>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-800 dark:text-slate-100 truncate">
                                        <?= e((string)$tr['matched_rule']) ?>
                                        <span class="ml-1 text-[10px] font-semibold text-slate-400">(<?= $badgeLabel ?>)</span>
                                    </span>
                                    <span class="font-mono font-bold text-slate-700 dark:text-slate-200"><?= number_format($hits, 0, ',', '.') ?>×</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                    <div class="h-full <?= $barCol ?> rounded-full" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top 10 Webseiten mit den meisten Trackern -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    🌐 Top 10 Webseiten mit den meisten Trackern &amp; Anzeigen
                </h3>
                <?php if (empty($topTrackedSites)): ?>
                    <p class="text-xs text-slate-400 italic py-4">
                        Sobald du Webseiten über den Proxy öffnest, siehst du hier das Ranking der Seiten mit den meisten Werbe- und Tracking-Skripten.
                    </p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($topTrackedSites as $idx => $ts): ?>
                            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/60 dark:border-slate-700/60 text-xs">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-5 h-5 rounded-full bg-slate-200 dark:bg-slate-700 text-[10px] font-extrabold flex items-center justify-center shrink-0">
                                        <?= $idx + 1 ?>
                                    </span>
                                    <a href="proxy.php?mode=proxy&amp;url=<?= rawurlencode('https://' . (string)$ts['source_domain']) ?>" class="font-mono font-bold text-brand-600 dark:text-brand-400 hover:underline truncate">
                                        <?= e((string)$ts['source_domain']) ?>
                                    </a>
                                </div>
                                <div class="flex items-center gap-1.5 shrink-0 text-[11px] font-bold">
                                    <span class="px-2 py-0.5 rounded bg-rose-100 dark:bg-rose-950/70 text-rose-700 dark:text-rose-300" title="Blockierte Werbung">🚫 <?= (int)$ts['ad_hits'] ?></span>
                                    <span class="px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-950/70 text-amber-700 dark:text-amber-300" title="Gestoppte Tracker">🕵️ <?= (int)$ts['tracker_hits'] ?></span>
                                    <span class="px-2 py-0.5 rounded bg-indigo-100 dark:bg-indigo-950/70 text-indigo-700 dark:text-indigo-300" title="Cookie-Banner">🍪 <?= (int)$ts['cookie_hits'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Live-Block-Protokoll (Chronik der letzten 80 abgefangenen Elemente) -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                    📜 Live-Block-Protokoll (Letzte <?= count($recentBlockLogs) ?> abgefangene Anfragen)
                </h3>
            </div>

            <?php if (empty($recentBlockLogs)): ?>
                <p class="text-xs text-slate-400 italic py-4">Noch keine blockierten Anfragen protokolliert.</p>
            <?php else: ?>
                <div class="overflow-x-auto max-h-96 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-800">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold border-b border-slate-200 dark:border-slate-700">
                                <th class="py-2.5 px-3">Zeitpunkt</th>
                                <th class="py-2.5 px-3">Kategorie</th>
                                <th class="py-2.5 px-3">Filterregel / Dienst</th>
                                <th class="py-2.5 px-3">Auf Webseite</th>
                                <th class="py-2.5 px-3">Blockierte Ressource</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800">
                            <?php foreach ($recentBlockLogs as $logRow): ?>
                                <?php
                                $cat = (string)$logRow['category'];
                                $badgeCls = $cat === 'tracker'
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                    : ($cat === 'cookie'
                                        ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300'
                                        : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300');
                                $catLabel = $cat === 'tracker' ? '🕵️ TRACKER' : ($cat === 'cookie' ? '🍪 COOKIE' : '🚫 WERBUNG');
                                ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="py-2 px-3 font-mono text-[11px] text-slate-500 whitespace-nowrap">
                                        <?= e(date('d.m. H:i:s', strtotime((string)$logRow['created_at']))) ?>
                                    </td>
                                    <td class="py-2 px-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $badgeCls ?>">
                                            <?= $catLabel ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 font-bold text-slate-800 dark:text-slate-100 whitespace-nowrap">
                                        <?= e((string)$logRow['matched_rule']) ?>
                                    </td>
                                    <td class="py-2 px-3 font-mono text-brand-600 dark:text-brand-400 whitespace-nowrap">
                                        <?= e((string)$logRow['source_domain']) ?>
                                    </td>
                                    <td class="py-2 px-3 font-mono text-[11px] text-slate-400 truncate max-w-xs" title="<?= e((string)$logRow['blocked_url']) ?>">
                                        <?= e((string)$logRow['blocked_url']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchProxyTab(tab) {
    ['browser', 'reader', 'adblock', 'stats'].forEach(function(k) {
        const panel = document.getElementById('proxyTab-' + k);
        const btn   = document.getElementById('tabBtn-' + k);
        if (panel) {
            panel.classList.toggle('hidden', k !== tab);
        }
        if (btn) {
            if (k === tab) {
                const activeColor = k === 'reader'
                    ? 'bg-indigo-600 text-white shadow-2xs'
                    : (k === 'adblock'
                        ? 'bg-emerald-600 text-white shadow-2xs'
                        : (k === 'stats' ? 'bg-rose-600 text-white shadow-2xs' : 'bg-brand-600 text-white shadow-2xs'));
                btn.className = 'px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer ' + activeColor;
            } else {
                btn.className = 'px-3.5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-colors cursor-pointer bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700';
            }
        }
    });
}

let currentReaderSize = 16;
function adjustReaderFontSize(delta) {
    const el = document.getElementById('readerArticleContent');
    if (!el) return;
    currentReaderSize = Math.min(26, Math.max(13, currentReaderSize + delta * 2));
    el.style.fontSize = currentReaderSize + 'px';
}

let readerSpeaking = false;
function toggleReaderSpeech() {
    if (!('speechSynthesis' in window)) {
        alert('Dein Browser unterstützt leider keine Sprachausgabe.');
        return;
    }
    const label = document.getElementById('readerTtsLabel');
    if (readerSpeaking) {
        window.speechSynthesis.cancel();
        readerSpeaking = false;
        if (label) label.textContent = 'Vorlesen';
        return;
    }
    const art = document.getElementById('readerArticleContent');
    if (!art) return;
    const u = new SpeechSynthesisUtterance(art.innerText);
    u.lang = 'de-DE';
    u.onend = function() {
        readerSpeaking = false;
        if (label) label.textContent = 'Vorlesen';
    };
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(u);
    readerSpeaking = true;
    if (label) label.textContent = 'Stopp';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
