<?php
/**
 * uWiiiMiniCloud – Nativer 2-Wege-CalDAV-Server-Endpunkt (caldav.php, RFC 4791)
 * Ermöglicht die direkte 2-Wege-Synchronisation des Kalenders mit:
 * - iPhone / iPad (iOS Kalender -> CalDAV-Account)
 * - macOS Kalender
 * - Mozilla Thunderbird & GNOME Kalender
 * - Android (z. B. über DAVx⁵)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_calendar'] ?? '1') !== '1') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Das Kalender- & CalDAV-Modul ist durch den Administrator deaktiviert.';
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// CalDAV-fähige DAV-Header senden
header('DAV: 1, 2, 3, calendar-access');
header('MS-Author-Via: DAV');
header('X-Content-Type-Options: nosniff');

if ($method === 'OPTIONS') {
    header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, REPORT');
    http_response_code(200);
    exit;
}

// HTTP Basic Auth auslesen (inkl. FastCGI/FPM Fallback)
$authUser = $_SERVER['PHP_AUTH_USER'] ?? null;
$authPass = $_SERVER['PHP_AUTH_PW'] ?? null;

if ($authUser === null) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$authUser, $authPass] = explode(':', $decoded, 2);
        }
    }
}

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/caldav.php';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/caldav.php', PHP_URL_PATH) ?: '/caldav.php';
$rawRelPath = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $rawRelPath = (string)$_SERVER['PATH_INFO'];
} elseif (str_starts_with($requestUri, $scriptName)) {
    $rawRelPath = substr($requestUri, strlen($scriptName));
}
$rawRelPath = trim(rawurldecode($rawRelPath), '/');

// Freundliche Info-Seite bei direktem Aufruf im Webbrowser
if ($method === 'GET' && $rawRelPath === '' && $authUser === null && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
    $caldavUrl = getBaseUrl() . '/caldav.php/';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>CalDAV-Endpunkt – ' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</title>';
    echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}.box{max-width:580px;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.75rem}code{background:#0f172a;padding:.25rem .5rem;border-radius:6px;color:#38bdf8}a{color:#38bdf8}</style></head><body>';
    echo '<div class="box"><h2 style="margin-top:0">📅 CalDAV-Synchronisations-Endpunkt aktiv</h2>';
    echo '<p>Dies ist der native <strong>2-Wege-CalDAV-Server</strong> von <strong>' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</strong> für iPhone/iPad, macOS Kalender, Mozilla Thunderbird, GNOME Kalender und Android (DAVx⁵).</p>';
    echo '<p>Server-Adresse für dein Gerät:</p>';
    echo '<p><code>' . e($caldavUrl) . '</code></p>';
    echo '<p style="margin-bottom:0"><a href="calendar.php">← Zurück zum Kalender</a></p></div></body></html>';
    exit;
}

if ($authUser === null || $authPass === null || trim($authUser) === '') {
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud CalDAV"');
    http_response_code(401);
    echo 'CalDAV-Authentifizierung erforderlich.';
    exit;
}

$clientIp = getClientIp();
if (isLoginRateLimited($pdo, $clientIp)) {
    http_response_code(429);
    echo 'Zu viele fehlgeschlagene Anmeldeversuche.';
    exit;
}

$userRow = verifyDavBasicAuth($pdo, trim($authUser), (string)$authPass, 'CalDAV');

if (!$userRow) {
    recordLoginFailure($pdo, $clientIp, trim($authUser));
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud CalDAV"');
    http_response_code(401);
    echo 'Ungültige Zugangsdaten.';
    exit;
}

$userId   = (int)$userRow['id'];
$username = (string)$userRow['username'];
$baseHref = rtrim($scriptName, '/');

$principalHref = $baseHref . '/principals/' . rawurlencode($username) . '/';
$homeSetHref   = $baseHref . '/calendars/' . rawurlencode($username) . '/';
$calendarHref  = $baseHref . '/calendars/' . rawurlencode($username) . '/default/';

/**
 * Berechnet das ETag eines Kalendertermins.
 *
 * @param array<string, mixed> $ev
 */
function getCalEventEtag(array $ev): string
{
    $id  = (int)($ev['id'] ?? 0);
    $upd = (string)($ev['updated_at'] ?? $ev['created_at'] ?? '');
    return '"' . md5('calevent-' . $id . '-' . $upd . '-' . ($ev['title'] ?? '') . '-' . ($ev['start_at'] ?? '') . '-' . ($ev['end_at'] ?? '')) . '"';
}

/**
 * Liefert den Dateinamen (event-<id>.ics) für einen Termin innerhalb des CalDAV-Kalenders.
 *
 * @param array<string, mixed> $ev
 */
function getCalEventResourceName(array $ev): string
{
    return 'event-' . (int)$ev['id'] . '.ics';
}

/**
 * Sucht einen für den Benutzer sichtbaren Termin anhand eines Dateinamens (z.B. "event-12.ics" oder "<uid>.ics").
 *
 * @param array<int, array<string, mixed>> $events
 * @return array<string, mixed>|null
 */
function findCalEventByResourceName(array $events, string $resName): ?array
{
    $base = basename(trim($resName, '/'));
    if (preg_match('/^event-(\d+)\.ics$/i', $base, $m)) {
        $targetId = (int)$m[1];
        foreach ($events as $ev) {
            if ((int)$ev['id'] === $targetId) {
                return $ev;
            }
        }
    }
    if (preg_match('/^minicloud-event-(\d+)(?:-|\.|@)/i', $base, $m)) {
        $targetId = (int)$m[1];
        foreach ($events as $ev) {
            if ((int)$ev['id'] === $targetId) {
                return $ev;
            }
        }
    }
    $uidCandidate = preg_replace('/\.ics$/i', '', $base) ?? $base;
    foreach ($events as $ev) {
        if (!empty($ev['ical_uid']) && strcasecmp((string)$ev['ical_uid'], $uidCandidate) === 0) {
            return $ev;
        }
    }
    return null;
}

$allEvents = getAccessibleCalendarEventsForDav($pdo, $userId);
$calDisplayName = ($appSettings['app_title'] ?? 'uWiiiMiniCloud') . ' Kalender (' . $username . ')';

// CTag des gesamten Kalenders berechnen
$ctagSeed = 'count:' . count($allEvents);
foreach ($allEvents as $ev) {
    $ctagSeed .= '|' . $ev['id'] . ':' . ($ev['updated_at'] ?? $ev['created_at'] ?? '');
}
$calendarCtag = '"' . md5($ctagSeed) . '"';

// 1. PROPPATCH (Clients setzen gerne Kalenderfarbe oder Anzeigenamen -> 200 OK bestätigen)
if ($method === 'PROPPATCH') {
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
       . '<D:multistatus xmlns:D="DAV:" xmlns:CAL="urn:ietf:params:xml:ns:caldav">'
       . '<D:response><D:href>' . htmlspecialchars($requestUri, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:href>'
       . '<D:propstat><D:prop><D:displayname/></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
       . '</D:response></D:multistatus>';
    exit;
}

// 2. PROPFIND (Auto-Discovery für Principal, Calendar-Home-Set, Kalender & .ics-Einträge)
if ($method === 'PROPFIND') {
    $depthHeader = strtolower(trim($_SERVER['HTTP_DEPTH'] ?? '1'));
    $depth       = ($depthHeader === '0') ? 0 : 1;

    if (str_ends_with(strtolower($rawRelPath), '.ics')) {
        $matched = findCalEventByResourceName($allEvents, $rawRelPath);
        if ($matched === null) {
            http_response_code(404);
            exit;
        }
        $etag  = getCalEventEtag($matched);
        $mtime = gmdate('D, d M Y H:i:s', strtotime((string)($matched['updated_at'] ?? $matched['created_at'] ?? 'now')) ?: time()) . ' GMT';
        $href  = $calendarHref . getCalEventResourceName($matched);
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
           . '<D:multistatus xmlns:D="DAV:" xmlns:CAL="urn:ietf:params:xml:ns:caldav">' . "\n"
           . '  <D:response>' . "\n"
           . '    <D:href>' . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:href>' . "\n"
           . '    <D:propstat>' . "\n"
           . '      <D:prop>' . "\n"
           . '        <D:resourcetype/>' . "\n"
           . '        <D:getetag>' . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:getetag>' . "\n"
           . '        <D:getcontenttype>text/calendar; charset=utf-8; component=VEVENT</D:getcontenttype>' . "\n"
           . '        <D:getlastmodified>' . $mtime . '</D:getlastmodified>' . "\n"
           . '      </D:prop>' . "\n"
           . '      <D:status>HTTP/1.1 200 OK</D:status>' . "\n"
           . '    </D:propstat>' . "\n"
           . '  </D:response>' . "\n"
           . '</D:multistatus>';
        exit;
    }

    $isCalendarCollection = (
        str_ends_with($rawRelPath, '/default')
        || $rawRelPath === 'default'
        || str_starts_with($rawRelPath, 'calendars/' . $username . '/default')
    );
    $isHomeSetCollection = (
        $rawRelPath === 'calendars/' . $username
        || $rawRelPath === 'calendars'
    );

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
         . '<D:multistatus xmlns:D="DAV:" xmlns:CAL="urn:ietf:params:xml:ns:caldav" xmlns:CS="http://calendarserver.org/ns/" xmlns:ICAL="http://apple.com/ns/ical/">' . "\n";

    $buildCalendarNode = static function (string $href) use ($calDisplayName, $principalHref, $homeSetHref, $calendarCtag): string {
        return "  <D:response>\n"
             . "    <D:href>" . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
             . "    <D:propstat>\n"
             . "      <D:prop>\n"
             . "        <D:resourcetype><D:collection/><CAL:calendar/></D:resourcetype>\n"
             . "        <D:displayname>" . htmlspecialchars($calDisplayName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:displayname>\n"
             . "        <ICAL:calendar-color>#2563EBFF</ICAL:calendar-color>\n"
             . "        <D:current-user-principal><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:current-user-principal>\n"
             . "        <CAL:calendar-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></CAL:calendar-home-set>\n"
             . "        <CS:getctag>" . htmlspecialchars($calendarCtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</CS:getctag>\n"
             . "        <CAL:supported-calendar-component-set><CAL:comp name=\"VEVENT\"/></CAL:supported-calendar-component-set>\n"
             . "        <D:supported-report-set>\n"
             . "          <D:supported-report><D:report><CAL:calendar-multiget/></D:report></D:supported-report>\n"
             . "          <D:supported-report><D:report><CAL:calendar-query/></D:report></D:supported-report>\n"
             . "        </D:supported-report-set>\n"
             . "      </D:prop>\n"
             . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
             . "    </D:propstat>\n"
             . "  </D:response>\n";
    };

    if ($isCalendarCollection) {
        $xml .= $buildCalendarNode($calendarHref);

        if ($depth >= 1) {
            foreach ($allEvents as $ev) {
                $eHref = $calendarHref . getCalEventResourceName($ev);
                $eEtag = getCalEventEtag($ev);
                $eTime = gmdate('D, d M Y H:i:s', strtotime((string)($ev['updated_at'] ?? $ev['created_at'] ?? 'now')) ?: time()) . ' GMT';
                $xml .= "  <D:response>\n"
                      . "    <D:href>" . htmlspecialchars($eHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
                      . "    <D:propstat>\n"
                      . "      <D:prop>\n"
                      . "        <D:resourcetype/>\n"
                      . "        <D:getetag>" . htmlspecialchars($eEtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getetag>\n"
                      . "        <D:getcontenttype>text/calendar; charset=utf-8; component=VEVENT</D:getcontenttype>\n"
                      . "        <D:getlastmodified>{$eTime}</D:getlastmodified>\n"
                      . "      </D:prop>\n"
                      . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
                      . "    </D:propstat>\n"
                      . "  </D:response>\n";
            }
        }
    } elseif ($isHomeSetCollection) {
        $xml .= "  <D:response>\n"
              . "    <D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
              . "    <D:propstat>\n"
              . "      <D:prop>\n"
              . "        <D:resourcetype><D:collection/></D:resourcetype>\n"
              . "        <D:current-user-principal><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:current-user-principal>\n"
              . "        <CAL:calendar-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></CAL:calendar-home-set>\n"
              . "      </D:prop>\n"
              . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
              . "    </D:propstat>\n"
              . "  </D:response>\n";
        if ($depth >= 1) {
            $xml .= $buildCalendarNode($calendarHref);
        }
    } else {
        $selfHref = $rawRelPath === '' ? ($baseHref . '/') : ($baseHref . '/' . $rawRelPath . '/');
        $xml .= "  <D:response>\n"
              . "    <D:href>" . htmlspecialchars($selfHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
              . "    <D:propstat>\n"
              . "      <D:prop>\n"
              . "        <D:resourcetype><D:collection/><D:principal/></D:resourcetype>\n"
              . "        <D:displayname>" . htmlspecialchars($username, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:displayname>\n"
              . "        <D:current-user-principal><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:current-user-principal>\n"
              . "        <D:principal-URL><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:principal-URL>\n"
              . "        <CAL:calendar-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></CAL:calendar-home-set>\n"
              . "      </D:prop>\n"
              . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
              . "    </D:propstat>\n"
              . "  </D:response>\n";

        if ($depth >= 1) {
            $xml .= $buildCalendarNode($calendarHref);
        }
    }

    $xml .= '</D:multistatus>';
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
    exit;
}

// 3. REPORT (calendar-multiget & calendar-query für das Abrufen der VEVENT-Daten)
if ($method === 'REPORT') {
    $rawBody = (string)file_get_contents('php://input', false, null, 0, 524288);
    $requestedHrefs = [];
    if (preg_match_all('/<[a-zA-Z0-9_:-]*href[^>]*>(.*?)<\/[a-zA-Z0-9_:-]*href>/si', $rawBody, $mHrefs)) {
        foreach ($mHrefs[1] as $hRaw) {
            $requestedHrefs[] = rawurldecode(trim(html_entity_decode($hRaw, ENT_QUOTES | ENT_XML1, 'UTF-8')));
        }
    }

    $eventsToReturn = [];
    if (!empty($requestedHrefs)) {
        foreach ($requestedHrefs as $reqHref) {
            $evMatch = findCalEventByResourceName($allEvents, $reqHref);
            if ($evMatch !== null) {
                $eventsToReturn[(int)$evMatch['id']] = $evMatch;
            }
        }
    } else {
        foreach ($allEvents as $ev) {
            $eventsToReturn[(int)$ev['id']] = $ev;
        }
    }

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
         . '<D:multistatus xmlns:D="DAV:" xmlns:CAL="urn:ietf:params:xml:ns:caldav">' . "\n";

    foreach ($eventsToReturn as $ev) {
        $eHref = $calendarHref . getCalEventResourceName($ev);
        $eEtag = getCalEventEtag($ev);
        $ics   = buildSingleIcsVevent($ev, $calDisplayName);
        $xml .= "  <D:response>\n"
              . "    <D:href>" . htmlspecialchars($eHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
              . "    <D:propstat>\n"
              . "      <D:prop>\n"
              . "        <D:getetag>" . htmlspecialchars($eEtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getetag>\n"
              . "        <CAL:calendar-data>" . htmlspecialchars($ics, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</CAL:calendar-data>\n"
              . "      </D:prop>\n"
              . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
              . "    </D:propstat>\n"
              . "  </D:response>\n";
    }

    $xml .= '</D:multistatus>';
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
    exit;
}

// 4. GET / HEAD (Einzelne .ics-Ressource oder kompletter Kalender)
if ($method === 'GET' || $method === 'HEAD') {
    if (str_ends_with(strtolower($rawRelPath), '.ics')) {
        $matched = findCalEventByResourceName($allEvents, $rawRelPath);
        if ($matched === null) {
            http_response_code(404);
            exit;
        }
        $ics = buildSingleIcsVevent($matched, $calDisplayName);
        header('Content-Type: text/calendar; charset=utf-8; component=VEVENT');
        header('Content-Length: ' . strlen($ics));
        header('ETag: ' . getCalEventEtag($matched));
        if ($method === 'HEAD') {
            http_response_code(200);
            exit;
        }
        echo $ics;
        exit;
    }

    // Kompletter Kalender als .ics-Feed
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//uWiiiMiniCloud//CalDAV 1.0//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . icsEscapeText($calDisplayName),
    ];
    foreach ($allEvents as $ev) {
        $single = buildSingleIcsVevent($ev, $calDisplayName);
        if (preg_match('/BEGIN:VEVENT.*?END:VEVENT/s', $single, $mVev)) {
            $lines[] = $mVev[0];
        }
    }
    $lines[] = 'END:VCALENDAR';
    $allIcs  = implode("\r\n", $lines) . "\r\n";

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Length: ' . strlen($allIcs));
    if ($method === 'HEAD') {
        http_response_code(200);
        exit;
    }
    echo $allIcs;
    exit;
}

// 5. PUT (Termin über iPhone / macOS / Thunderbird / Android DAVx⁵ neu anlegen oder aktualisieren)
if ($method === 'PUT') {
    $rawIcs = (string)file_get_contents('php://input', false, null, 0, 524288);
    $parsedEvents = parseIcsContentToEvents($rawIcs);
    if (empty($parsedEvents)) {
        http_response_code(400);
        echo 'Ungültige iCalendar-Daten.';
        exit;
    }

    $evData   = $parsedEvents[0];
    $existing = findCalEventByResourceName($allEvents, $rawRelPath);

    $resBase = basename($rawRelPath, '.ics');
    $icalUid = $evData['ical_uid'] !== ''
        ? $evData['ical_uid']
        : ($resBase !== '' ? $resBase : ('minicloud-event-' . bin2hex(random_bytes(8)) . '@minicloud'));

    if ($existing !== null) {
        $canEdit = ((int)$existing['user_id'] === $userId);
        if (!$canEdit) {
            http_response_code(403);
            echo 'Schreibzugriff auf fremden Termin verweigert.';
            exit;
        }

        $stmtUpd = $pdo->prepare(
            "UPDATE `calendar_events`
             SET `title`       = :title,
                 `description` = :descr,
                 `location`    = :loc,
                 `start_at`    = :start_at,
                 `end_at`      = :end_at,
                 `all_day`     = :all_day,
                 `recurrence`  = :rec,
                 `ical_uid`    = :uid
             WHERE `id` = :id AND `user_id` = :owner_id"
        );
        $stmtUpd->execute([
            ':title'    => mb_substr((string)$evData['title'], 0, 190),
            ':descr'    => (string)$evData['description'],
            ':loc'      => mb_substr((string)$evData['location'], 0, 190),
            ':start_at' => (string)$evData['start_at'],
            ':end_at'   => (string)$evData['end_at'],
            ':all_day'  => (int)$evData['all_day'],
            ':rec'      => (string)$evData['recurrence'],
            ':uid'      => mb_substr($icalUid, 0, 190),
            ':id'       => (int)$existing['id'],
            ':owner_id' => $userId,
        ]);

        $updatedRow = array_merge($existing, $evData, ['ical_uid' => $icalUid, 'updated_at' => date('Y-m-d H:i:s')]);
        header('ETag: ' . getCalEventEtag($updatedRow));
        http_response_code(204);
        exit;
    }

    $stmtIns = $pdo->prepare(
        "INSERT INTO `calendar_events`
         (`user_id`, `title`, `description`, `location`, `start_at`, `end_at`, `all_day`, `recurrence`, `color`, `ical_uid`)
         VALUES (:uid, :title, :descr, :loc, :start_at, :end_at, :all_day, :rec, '#2563eb', :ical_uid)"
    );
    $stmtIns->execute([
        ':uid'      => $userId,
        ':title'    => mb_substr((string)$evData['title'], 0, 190),
        ':descr'    => (string)$evData['description'],
        ':loc'      => mb_substr((string)$evData['location'], 0, 190),
        ':start_at' => (string)$evData['start_at'],
        ':end_at'   => (string)$evData['end_at'],
        ':all_day'  => (int)$evData['all_day'],
        ':rec'      => (string)$evData['recurrence'],
        ':ical_uid' => mb_substr($icalUid, 0, 190),
    ]);

    $newId  = (int)$pdo->lastInsertId();
    $newRow = array_merge($evData, ['id' => $newId, 'ical_uid' => $icalUid, 'updated_at' => date('Y-m-d H:i:s')]);
    header('ETag: ' . getCalEventEtag($newRow));
    http_response_code(201);
    exit;
}

// 6. DELETE (Eigenen Termin über CalDAV löschen)
if ($method === 'DELETE') {
    $existing = findCalEventByResourceName($allEvents, $rawRelPath);
    if ($existing === null) {
        http_response_code(404);
        exit;
    }
    if ((int)$existing['user_id'] !== $userId) {
        http_response_code(403);
        echo 'Fremde Termine können nicht über CalDAV gelöscht werden.';
        exit;
    }

    $pdo->prepare("DELETE FROM `calendar_events` WHERE `id` = :id AND `user_id` = :uid")
        ->execute([':id' => (int)$existing['id'], ':uid' => $userId]);
    http_response_code(204);
    exit;
}

http_response_code(405);
header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, REPORT');
