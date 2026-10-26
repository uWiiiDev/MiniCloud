<?php
/**
 * uWiiiMiniCloud – Nativer CardDAV-Server-Endpunkt (carddav.php, RFC 6352)
 * Ermöglicht die direkte 2-Wege-Synchronisation des Adressbuchs mit:
 * - iPhone / iPad (iOS Kontakte -> CardDAV-Account)
 * - macOS Kontakte
 * - Mozilla Thunderbird (CardDAV-Adressbuch)
 * - Android (z. B. über DAVx⁵)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_contacts'] ?? '1') !== '1') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Das Adressbuch- & CardDAV-Modul ist durch den Administrator deaktiviert.';
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// CardDAV-fähige DAV-Header senden
header('DAV: 1, 2, 3, addressbook');
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

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/carddav.php';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/carddav.php', PHP_URL_PATH) ?: '/carddav.php';
$rawRelPath = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $rawRelPath = (string)$_SERVER['PATH_INFO'];
} elseif (str_starts_with($requestUri, $scriptName)) {
    $rawRelPath = substr($requestUri, strlen($scriptName));
}
$rawRelPath = trim(rawurldecode($rawRelPath), '/');

// Freundliche Info-Seite bei direktem Aufruf im Webbrowser
if ($method === 'GET' && $rawRelPath === '' && $authUser === null && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
    $carddavUrl = getBaseUrl() . '/carddav.php';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>CardDAV-Endpunkt – ' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</title>';
    echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}.box{max-width:580px;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.75rem}code{background:#0f172a;padding:.25rem .5rem;border-radius:6px;color:#38bdf8}a{color:#38bdf8}</style></head><body>';
    echo '<div class="box"><h2 style="margin-top:0">📇 CardDAV-Synchronisations-Endpunkt aktiv</h2>';
    echo '<p>Dies ist der native <strong>CardDAV-Server</strong> von <strong>' . e($appSettings['app_title'] ?? 'uWiiiMiniCloud') . '</strong> für iPhone/iPad, macOS Kontakte, Mozilla Thunderbird und Android (DAVx⁵).</p>';
    echo '<p>Server-Adresse für dein Gerät:</p>';
    echo '<p><code>' . e($carddavUrl) . '</code></p>';
    echo '<p style="margin-bottom:0"><a href="contacts.php?sync_modal=1">← Zurück zum Adressbuch &amp; Abo-Assistenten</a></p></div></body></html>';
    exit;
}

if ($authUser === null || $authPass === null || trim($authUser) === '') {
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud CardDAV"');
    http_response_code(401);
    echo 'CardDAV-Authentifizierung erforderlich.';
    exit;
}

$clientIp = getClientIp();
if (isLoginRateLimited($pdo, $clientIp)) {
    http_response_code(429);
    echo 'Zu viele fehlgeschlagene Anmeldeversuche.';
    exit;
}

$stmtUser = $pdo->prepare("SELECT `id`, `username`, `password_hash` FROM `users` WHERE `username` = :u LIMIT 1");
$stmtUser->execute([':u' => trim($authUser)]);
$userRow = $stmtUser->fetch();

if (!$userRow || !password_verify((string)$authPass, (string)$userRow['password_hash'])) {
    recordLoginFailure($pdo, $clientIp, trim($authUser));
    header('WWW-Authenticate: Basic realm="uWiiiMiniCloud CardDAV"');
    http_response_code(401);
    echo 'Ungültige Zugangsdaten.';
    exit;
}

$userId   = (int)$userRow['id'];
$username = (string)$userRow['username'];
$baseHref = rtrim($scriptName, '/');

$principalHref   = $baseHref . '/principals/' . rawurlencode($username) . '/';
$homeSetHref     = $baseHref . '/addressbooks/' . rawurlencode($username) . '/';
$addressbookHref = $baseHref . '/addressbooks/' . rawurlencode($username) . '/default/';

/**
 * Berechnet das ETag eines Kontakts.
 *
 * @param array<string, mixed> $c
 */
function getContactEtag(array $c): string
{
    $id  = (int)($c['id'] ?? 0);
    $upd = (string)($c['updated_at'] ?? $c['created_at'] ?? '');
    return '"' . md5('contact-' . $id . '-' . $upd . '-' . ($c['first_name'] ?? '') . '-' . ($c['last_name'] ?? '') . '-' . ($c['email'] ?? '')) . '"';
}

/**
 * Liefert den Dateinamen (<id>.vcf) für einen Kontakt innerhalb des CardDAV-Adressbuchs.
 *
 * @param array<string, mixed> $c
 */
function getContactResourceName(array $c): string
{
    return 'contact-' . (int)$c['id'] . '.vcf';
}

/**
 * Sucht einen für den Benutzer sichtbaren Kontakt anhand eines Dateinamens (z.B. "contact-12.vcf" oder "<uid>.vcf").
 *
 * @param array<int, array<string, mixed>> $contacts
 * @return array<string, mixed>|null
 */
function findContactByResourceName(array $contacts, string $resName): ?array
{
    $base = basename(trim($resName, '/'));
    if (preg_match('/^contact-(\d+)\.vcf$/i', $base, $m)) {
        $targetId = (int)$m[1];
        foreach ($contacts as $c) {
            if ((int)$c['id'] === $targetId) {
                return $c;
            }
        }
    }
    if (preg_match('/^(\d+)\.vcf$/i', $base, $m)) {
        $targetId = (int)$m[1];
        foreach ($contacts as $c) {
            if ((int)$c['id'] === $targetId) {
                return $c;
            }
        }
    }
    $uidCandidate = preg_replace('/\.vcf$/i', '', $base) ?? $base;
    foreach ($contacts as $c) {
        if (!empty($c['vcard_uid']) && strcasecmp((string)$c['vcard_uid'], $uidCandidate) === 0) {
            return $c;
        }
    }
    return null;
}

$allContacts = getAccessibleContactsForUser($pdo, $userId);

// CTag des gesamten Adressbuchs berechnen (ändert sich bei jeder Kontaktänderung)
$ctagSeed = 'count:' . count($allContacts);
foreach ($allContacts as $c) {
    $ctagSeed .= '|' . $c['id'] . ':' . ($c['updated_at'] ?? $c['created_at'] ?? '');
}
$addressbookCtag = '"' . md5($ctagSeed) . '"';

// 1. PROPPATCH (Clients wie iOS/Thunderbird versuchen manchmal Display-Props zu setzen -> 200 OK bestätigen)
if ($method === 'PROPPATCH') {
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
       . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
       . '<D:response><D:href>' . htmlspecialchars($requestUri, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:href>'
       . '<D:propstat><D:prop><D:displayname/></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
       . '</D:response></D:multistatus>';
    exit;
}

// 2. PROPFIND (Auto-Discovery für Principal, Home-Set, Adressbuch & Kontakt-Liste)
if ($method === 'PROPFIND') {
    $depthHeader = strtolower(trim($_SERVER['HTTP_DEPTH'] ?? '1'));
    $depth       = ($depthHeader === '0') ? 0 : 1;

    // Prüfen, ob direkt eine einzelne .vcf-Ressource abgefragt wird
    if (str_ends_with(strtolower($rawRelPath), '.vcf')) {
        $matched = findContactByResourceName($allContacts, $rawRelPath);
        if ($matched === null) {
            http_response_code(404);
            exit;
        }
        $etag  = getContactEtag($matched);
        $mtime = gmdate('D, d M Y H:i:s', strtotime((string)($matched['updated_at'] ?? $matched['created_at'] ?? 'now')) ?: time()) . ' GMT';
        $href  = $addressbookHref . getContactResourceName($matched);
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
           . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">' . "\n"
           . '  <D:response>' . "\n"
           . '    <D:href>' . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:href>' . "\n"
           . '    <D:propstat>' . "\n"
           . '      <D:prop>' . "\n"
           . '        <D:resourcetype/>' . "\n"
           . '        <D:getetag>' . htmlspecialchars($etag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</D:getetag>' . "\n"
           . '        <D:getcontenttype>text/vcard; charset=utf-8</D:getcontenttype>' . "\n"
           . '        <D:getlastmodified>' . $mtime . '</D:getlastmodified>' . "\n"
           . '      </D:prop>' . "\n"
           . '      <D:status>HTTP/1.1 200 OK</D:status>' . "\n"
           . '    </D:propstat>' . "\n"
           . '  </D:response>' . "\n"
           . '</D:multistatus>';
        exit;
    }

    $isAddressbookCollection = (
        str_ends_with($rawRelPath, '/default')
        || $rawRelPath === 'default'
        || str_starts_with($rawRelPath, 'addressbooks/' . $username . '/default')
    );
    $isHomeSetCollection = (
        $rawRelPath === 'addressbooks/' . $username
        || $rawRelPath === 'addressbooks'
    );

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
         . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav" xmlns:CS="http://calendarserver.org/ns/">' . "\n";

    $abDisplayName = ($appSettings['app_title'] ?? 'uWiiiMiniCloud') . ' Kontakte (' . $username . ')';

    // Hilfsfunktion für den Adressbuch-Collection-Knoten
    $buildAddressbookNode = static function (string $href) use ($abDisplayName, $principalHref, $homeSetHref, $addressbookCtag): string {
        return "  <D:response>\n"
             . "    <D:href>" . htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
             . "    <D:propstat>\n"
             . "      <D:prop>\n"
             . "        <D:resourcetype><D:collection/><C:addressbook/></D:resourcetype>\n"
             . "        <D:displayname>" . htmlspecialchars($abDisplayName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:displayname>\n"
             . "        <D:current-user-principal><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:current-user-principal>\n"
             . "        <C:addressbook-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></C:addressbook-home-set>\n"
             . "        <CS:getctag>" . htmlspecialchars($addressbookCtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</CS:getctag>\n"
             . "        <D:supported-report-set>\n"
             . "          <D:supported-report><D:report><C:addressbook-multiget/></D:report></D:supported-report>\n"
             . "          <D:supported-report><D:report><C:addressbook-query/></D:report></D:supported-report>\n"
             . "        </D:supported-report-set>\n"
             . "      </D:prop>\n"
             . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
             . "    </D:propstat>\n"
             . "  </D:response>\n";
    };

    if ($isAddressbookCollection) {
        $xml .= $buildAddressbookNode($addressbookHref);

        if ($depth >= 1) {
            foreach ($allContacts as $c) {
                $cHref = $addressbookHref . getContactResourceName($c);
                $cEtag = getContactEtag($c);
                $cTime = gmdate('D, d M Y H:i:s', strtotime((string)($c['updated_at'] ?? $c['created_at'] ?? 'now')) ?: time()) . ' GMT';
                $xml .= "  <D:response>\n"
                      . "    <D:href>" . htmlspecialchars($cHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
                      . "    <D:propstat>\n"
                      . "      <D:prop>\n"
                      . "        <D:resourcetype/>\n"
                      . "        <D:getetag>" . htmlspecialchars($cEtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getetag>\n"
                      . "        <D:getcontenttype>text/vcard; charset=utf-8</D:getcontenttype>\n"
                      . "        <D:getlastmodified>{$cTime}</D:getlastmodified>\n"
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
              . "        <C:addressbook-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></C:addressbook-home-set>\n"
              . "      </D:prop>\n"
              . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
              . "    </D:propstat>\n"
              . "  </D:response>\n";
        if ($depth >= 1) {
            $xml .= $buildAddressbookNode($addressbookHref);
        }
    } else {
        // Root oder Principal Discovery (/carddav.php oder /carddav.php/principals/<user>/)
        $selfHref = $rawRelPath === '' ? ($baseHref . '/') : ($baseHref . '/' . $rawRelPath . '/');
        $xml .= "  <D:response>\n"
              . "    <D:href>" . htmlspecialchars($selfHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
              . "    <D:propstat>\n"
              . "      <D:prop>\n"
              . "        <D:resourcetype><D:collection/><D:principal/></D:resourcetype>\n"
              . "        <D:displayname>" . htmlspecialchars($username, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:displayname>\n"
              . "        <D:current-user-principal><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:current-user-principal>\n"
              . "        <D:principal-URL><D:href>" . htmlspecialchars($principalHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></D:principal-URL>\n"
              . "        <C:addressbook-home-set><D:href>" . htmlspecialchars($homeSetHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href></C:addressbook-home-set>\n"
              . "      </D:prop>\n"
              . "      <D:status>HTTP/1.1 200 OK</D:status>\n"
              . "    </D:propstat>\n"
              . "  </D:response>\n";

        if ($depth >= 1) {
            $xml .= $buildAddressbookNode($addressbookHref);
        }
    }

    $xml .= '</D:multistatus>';
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml;
    exit;
}

// 3. REPORT (addressbook-multiget & addressbook-query für das Abrufen der vCard-Daten)
if ($method === 'REPORT') {
    $rawBody = (string)file_get_contents('php://input', false, null, 0, 524288);
    $requestedHrefs = [];
    if (preg_match_all('/<[a-zA-Z0-9_:-]*href[^>]*>(.*?)<\/[a-zA-Z0-9_:-]*href>/si', $rawBody, $mHrefs)) {
        foreach ($mHrefs[1] as $hRaw) {
            $requestedHrefs[] = rawurldecode(trim( html_entity_decode($hRaw, ENT_QUOTES | ENT_XML1, 'UTF-8') ));
        }
    }

    $contactsToReturn = [];
    if (!empty($requestedHrefs)) {
        foreach ($requestedHrefs as $reqHref) {
            $cMatch = findContactByResourceName($allContacts, $reqHref);
            if ($cMatch !== null) {
                $contactsToReturn[(int)$cMatch['id']] = $cMatch;
            }
        }
    } else {
        foreach ($allContacts as $c) {
            $contactsToReturn[(int)$c['id']] = $c;
        }
    }

    $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
         . '<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">' . "\n";

    foreach ($contactsToReturn as $c) {
        $cHref = $addressbookHref . getContactResourceName($c);
        $cEtag = getContactEtag($c);
        $vcard = buildVcardEntry($c);
        $xml .= "  <D:response>\n"
              . "    <D:href>" . htmlspecialchars($cHref, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:href>\n"
              . "    <D:propstat>\n"
              . "      <D:prop>\n"
              . "        <D:getetag>" . htmlspecialchars($cEtag, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</D:getetag>\n"
              . "        <C:address-data>" . htmlspecialchars($vcard, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</C:address-data>\n"
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

// 4. GET / HEAD (Einzelne .vcf-Ressource oder komplettes Adressbuch abrufen)
if ($method === 'GET' || $method === 'HEAD') {
    if (str_ends_with(strtolower($rawRelPath), '.vcf')) {
        $matched = findContactByResourceName($allContacts, $rawRelPath);
        if ($matched === null) {
            http_response_code(404);
            exit;
        }
        $vcard = buildVcardEntry($matched);
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Length: ' . strlen($vcard));
        header('ETag: ' . getContactEtag($matched));
        if ($method === 'HEAD') {
            http_response_code(200);
            exit;
        }
        echo $vcard;
        exit;
    }

    // Falls Client per GET das gesamte Adressbuch abruft, Sammel-vCard ausliefern
    $allVcf = '';
    foreach ($allContacts as $c) {
        $allVcf .= buildVcardEntry($c);
    }
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Length: ' . strlen($allVcf));
    header('ETag: ' . $addressbookCtag);
    if ($method === 'HEAD') {
        http_response_code(200);
        exit;
    }
    echo $allVcf;
    exit;
}

// 5. PUT (Kontakt vom Smartphone / Thunderbird anlegen oder aktualisieren)
if ($method === 'PUT') {
    $rawBody = (string)file_get_contents('php://input', false, null, 0, 524288);
    $parsed  = parseVcardFileContent($rawBody);
    if (empty($parsed)) {
        http_response_code(400);
        echo 'Ungültige vCard-Daten.';
        exit;
    }

    $item = $parsed[0];
    $resBase = basename($rawRelPath);
    $urlUid  = preg_replace('/\.vcf$/i', '', $resBase) ?: '';
    $vcardUid = $item['vcard_uid'] !== '' ? $item['vcard_uid'] : ($urlUid !== '' ? $urlUid : ('uwiiiminicloud-' . bin2hex(random_bytes(8))));

    $existing = findContactByResourceName($allContacts, $rawRelPath);
    if ($existing === null && $vcardUid !== '') {
        foreach ($allContacts as $c) {
            if (!empty($c['vcard_uid']) && strcasecmp((string)$c['vcard_uid'], $vcardUid) === 0) {
                $existing = $c;
                break;
            }
        }
    }

    if ($existing !== null) {
        $stmtUpd = $pdo->prepare(
            "UPDATE `contacts`
             SET `first_name` = :fn, `last_name` = :ln, `organization` = :org, `role_title` = :rt,
                 `email` = :em, `phone` = :ph, `mobile` = :mob, `street` = :str, `postal_code` = :pc,
                 `city` = :city, `country` = :cnt, `website` = :web, `birthday` = :bday, `notes` = :nt,
                 `vcard_uid` = :vuid
             WHERE `id` = :id"
        );
        $stmtUpd->execute([
            ':fn'   => mb_substr($item['first_name'], 0, 100),
            ':ln'   => mb_substr($item['last_name'], 0, 100),
            ':org'  => mb_substr($item['organization'], 0, 150),
            ':rt'   => mb_substr($item['role_title'], 0, 120),
            ':em'   => mb_substr($item['email'], 0, 190),
            ':ph'   => mb_substr($item['phone'], 0, 80),
            ':mob'  => mb_substr($item['mobile'], 0, 80),
            ':str'  => mb_substr($item['street'], 0, 190),
            ':pc'   => mb_substr($item['postal_code'], 0, 30),
            ':city' => mb_substr($item['city'], 0, 100),
            ':cnt'  => mb_substr($item['country'], 0, 100),
            ':web'  => mb_substr($item['website'], 0, 255),
            ':bday' => $item['birthday'],
            ':nt'   => $item['notes'] !== '' ? $item['notes'] : null,
            ':vuid' => mb_substr($vcardUid, 0, 120),
            ':id'   => (int)$existing['id'],
        ]);

        $stmtFresh = $pdo->prepare("SELECT * FROM `contacts` WHERE `id` = :id LIMIT 1");
        $stmtFresh->execute([':id' => (int)$existing['id']]);
        $freshRow = $stmtFresh->fetch() ?: $existing;
        header('ETag: ' . getContactEtag($freshRow));
        http_response_code(204);
        exit;
    }

    $stmtIns = $pdo->prepare(
        "INSERT INTO `contacts`
            (`user_id`, `first_name`, `last_name`, `organization`, `role_title`, `email`, `phone`, `mobile`,
             `street`, `postal_code`, `city`, `country`, `website`, `birthday`, `notes`, `is_favorite`, `vcard_uid`)
         VALUES
            (:uid, :fn, :ln, :org, :rt, :em, :ph, :mob, :str, :pc, :city, :cnt, :web, :bday, :nt, 0, :vuid)"
    );
    $stmtIns->execute([
        ':uid'  => $userId,
        ':fn'   => mb_substr($item['first_name'], 0, 100),
        ':ln'   => mb_substr($item['last_name'], 0, 100),
        ':org'  => mb_substr($item['organization'], 0, 150),
        ':rt'   => mb_substr($item['role_title'], 0, 120),
        ':em'   => mb_substr($item['email'], 0, 190),
        ':ph'   => mb_substr($item['phone'], 0, 80),
        ':mob'  => mb_substr($item['mobile'], 0, 80),
        ':str'  => mb_substr($item['street'], 0, 190),
        ':pc'   => mb_substr($item['postal_code'], 0, 30),
        ':city' => mb_substr($item['city'], 0, 100),
        ':cnt'  => mb_substr($item['country'], 0, 100),
        ':web'  => mb_substr($item['website'], 0, 255),
        ':bday' => $item['birthday'],
        ':nt'   => $item['notes'] !== '' ? $item['notes'] : null,
        ':vuid' => mb_substr($vcardUid, 0, 120),
    ]);

    $newId = (int)$pdo->lastInsertId();
    $stmtFresh = $pdo->prepare("SELECT * FROM `contacts` WHERE `id` = :id LIMIT 1");
    $stmtFresh->execute([':id' => $newId]);
    $freshRow = $stmtFresh->fetch() ?: ['id' => $newId, 'updated_at' => date('Y-m-d H:i:s')];
    header('ETag: ' . getContactEtag($freshRow));
    http_response_code(201);
    exit;
}

// 6. DELETE (Kontakt vom Smartphone / Thunderbird löschen)
if ($method === 'DELETE') {
    $existing = findContactByResourceName($allContacts, $rawRelPath);
    if ($existing === null) {
        http_response_code(404);
        exit;
    }
    if ((int)$existing['user_id'] !== $userId) {
        http_response_code(403);
        exit;
    }
    $pdo->prepare("DELETE FROM `contacts` WHERE `id` = :id")->execute([':id' => (int)$existing['id']]);
    http_response_code(204);
    exit;
}

http_response_code(405);
header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, REPORT');
