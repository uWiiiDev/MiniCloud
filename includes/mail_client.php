<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 📧 STATELESS LIVE-E-MAIL-CLIENT FÜR DAS OFFICE-MODUL (includes/mail_client.php)
 * ============================================================================
 * Oberste Prämisse (100 % Zero-Storage für Provider- & E-Mail-Daten):
 * - Es werden KEINERLEI E-Mails, Texte, Kontakte, Ordnerlisten oder Anhänge
 *   des E-Mail-Providers in der Datenbank oder auf der Server-Festplatte gespeichert.
 * - Alle Abrufe (IMAP & POP3) und Versendungen (SMTP) erfolgen ausschließlich
 *   live im flüchtigen Arbeitsspeicher (RAM) während des Seitenaufrufs.
 * - Bei POP3 erfolgt der Abruf strikt zerstörungsfrei („Nachrichten auf dem Server belassen“).
 * - Optional kann der Benutzer seine eigenen Zugangsdaten (mit AES-256-GCM verschlüsseltem Passwort)
 *   in seinen persönlichen Einstellungen (settings.php) hinterlegen oder jederzeit mit 1 Klick löschen.
 */

/**
 * Erstellt bei Bedarf die Tabelle `user_mail_accounts` (nur für die optional hinterlegten
 * Verbindungsdaten des Nutzers – niemals für E-Mail-Inhalte!).
 */
function ensureUserMailAccountSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_mail_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            account_label VARCHAR(120) NOT NULL DEFAULT '',
            email_address VARCHAR(190) NOT NULL DEFAULT '',
            sender_name VARCHAR(150) NOT NULL DEFAULT '',
            provider_key VARCHAR(40) NOT NULL DEFAULT 'auto',
            protocol VARCHAR(10) NOT NULL DEFAULT 'imap',
            incoming_host VARCHAR(190) NOT NULL DEFAULT '',
            incoming_port INT NOT NULL DEFAULT 993,
            incoming_ssl VARCHAR(12) NOT NULL DEFAULT 'ssl',
            smtp_host VARCHAR(190) NOT NULL DEFAULT '',
            smtp_port INT NOT NULL DEFAULT 587,
            smtp_ssl VARCHAR(12) NOT NULL DEFAULT 'tls',
            username VARCHAR(190) NOT NULL DEFAULT '',
            encrypted_password TEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_user_email (user_id, email_address),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Auto-Migration falls die Tabelle noch aus der Einzel-Postfach-Version (ohne id-Spalte) stammt
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM user_mail_accounts LIKE 'id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE user_mail_accounts DROP PRIMARY KEY");
            $pdo->exec("ALTER TABLE user_mail_accounts ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
            $pdo->exec("ALTER TABLE user_mail_accounts ADD COLUMN account_label VARCHAR(120) NOT NULL DEFAULT '' AFTER user_id");
            $pdo->exec("ALTER TABLE user_mail_accounts ADD UNIQUE KEY uniq_user_email (user_id, email_address)");
            $pdo->exec("ALTER TABLE user_mail_accounts ADD KEY idx_user (user_id)");
        }
    } catch (Throwable) {
    }
}

/**
 * Liefert die hinterlegten Serverdaten & Ports (IMAP, POP3 & SMTP) für alle gängigen E-Mail-Provider.
 *
 * @return array<string, array<string, mixed>>
 */
function getMailProviderPresets(): array
{
    return [
        'gmx' => [
            'label'      => 'GMX (gmx.de / gmx.net / gmx.at)',
            'domains'    => ['gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'gmx.com'],
            'imap_host'  => 'imap.gmx.net',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop.gmx.net',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'mail.gmx.net',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Hinweis für GMX: Im GMX-Webpostfach muss unter „Einstellungen → E-Mail → POP3/IMAP Abruf“ der Haken „POP3 und IMAP Zugriff erlauben“ gesetzt sein.',
        ],
        'webde' => [
            'label'      => 'WEB.DE (web.de / email.de)',
            'domains'    => ['web.de', 'email.de'],
            'imap_host'  => 'imap.web.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop3.web.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.web.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Hinweis für WEB.DE: Im WEB.DE-Postfach unter „Einstellungen → POP3/IMAP Abruf“ bitte „E-Mails per externem Programm (Outlook, Thunderbird) versenden und empfangen“ aktivieren.',
        ],
        'tonline' => [
            'label'      => 'T-Online / Telekom (t-online.de / magenta.de)',
            'domains'    => ['t-online.de', 'magenta.de'],
            'imap_host'  => 'secureimap.t-online.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'securepop.t-online.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'securesmtp.t-online.de',
            'smtp_port'  => 465,
            'smtp_ssl'   => 'ssl',
            'hint'       => 'Hinweis für T-Online: Für externe E-Mail-Programme verwendet T-Online ein separates „Passwort für E-Mail-Programme“ (im Telekom Kundencenter unter E-Mail-Einstellungen).',
        ],
        'strato' => [
            'label'      => 'STRATO (strato.de & eigene STRATO-Domains)',
            'domains'    => ['strato.de'],
            'imap_host'  => 'imap.strato.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop3.strato.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.strato.de',
            'smtp_port'  => 465,
            'smtp_ssl'   => 'ssl',
            'hint'       => 'Funktioniert für alle E-Mail-Postfächer eigener Domains, die bei STRATO gehostet werden.',
        ],
        'ionos' => [
            'label'      => 'IONOS / 1&1 (ionos.de / online.de & eigene Domains)',
            'domains'    => ['ionos.de', 'ionos.com', 'online.de', '1und1.de'],
            'imap_host'  => 'imap.ionos.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop.ionos.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.ionos.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Funktioniert für @online.de sowie alle eigenen Domains bei IONOS / 1&1.',
        ],
        'gmail' => [
            'label'      => 'Google Mail / Gmail (gmail.com / googlemail.com)',
            'domains'    => ['gmail.com', 'googlemail.com'],
            'imap_host'  => 'imap.gmail.com',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop.gmail.com',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.gmail.com',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Hinweis für Gmail: Google erfordert für IMAP/POP3 ein 16-stelliges „App-Passwort“ (im Google-Konto unter Sicherheit → 2-Faktor-Bestätigung → App-Passwörter).',
        ],
        'outlook' => [
            'label'      => 'Microsoft Outlook / Hotmail / Live (outlook.de / hotmail.com)',
            'domains'    => ['outlook.de', 'outlook.com', 'hotmail.de', 'hotmail.com', 'live.de', 'live.com', 'msn.com'],
            'imap_host'  => 'outlook.office365.com',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'outlook.office365.com',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.office365.com',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Standard-Server für Microsoft 365, Outlook.de, Hotmail und Live.',
        ],
        'icloud' => [
            'label'      => 'Apple iCloud Mail (icloud.com / me.com / mac.com)',
            'domains'    => ['icloud.com', 'me.com', 'mac.com'],
            'imap_host'  => 'imap.mail.me.com',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'imap.mail.me.com',
            'pop3_port'  => 993,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.mail.me.com',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Hinweis für Apple iCloud: Bitte unter appleid.apple.com ein „Anwendungsspezifisches Passwort“ generieren.',
        ],
        'freenet' => [
            'label'      => 'freenet Mail (freenet.de)',
            'domains'    => ['freenet.de'],
            'imap_host'  => 'mx.freenet.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'mx.freenet.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'mx.freenet.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Im freenet-Postfach muss unter Einstellungen → Sicherheit die Option „Übertragungsprotokolle POP3/IMAP/SMTP“ aktiviert sein.',
        ],
        'vodafone' => [
            'label'      => 'Vodafone / Arcor / Kabelmail (vodafone.de / arcor.de)',
            'domains'    => ['vodafone.de', 'vodafonemail.de', 'arcor.de', 'kabelmail.de'],
            'imap_host'  => 'imap.vodafonemail.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop3.vodafonemail.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.vodafonemail.de',
            'smtp_port'  => 465,
            'smtp_ssl'   => 'ssl',
            'hint'       => 'Gilt für Vodafone E-Mail, ehemalige Arcor- sowie Kabelmail-Adressen.',
        ],
        'yahoo' => [
            'label'      => 'Yahoo Mail (yahoo.de / yahoo.com)',
            'domains'    => ['yahoo.de', 'yahoo.com', 'ymail.com'],
            'imap_host'  => 'imap.mail.yahoo.com',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop.mail.yahoo.com',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.mail.yahoo.com',
            'smtp_port'  => 465,
            'smtp_ssl'   => 'ssl',
            'hint'       => 'Yahoo benötigt für Drittanbieter-Programme ein im Yahoo-Konto erzeugtes App-Passwort.',
        ],
        'posteo' => [
            'label'      => 'Posteo (posteo.de / posteo.net)',
            'domains'    => ['posteo.de', 'posteo.net', 'posteo.org'],
            'imap_host'  => 'posteo.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'posteo.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'posteo.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Posteo unterstützt IMAP (993), POP3 (995) und SMTP (587 STARTTLS) direkt.',
        ],
        'mailbox' => [
            'label'      => 'mailbox.org (mailbox.org)',
            'domains'    => ['mailbox.org'],
            'imap_host'  => 'imap.mailbox.org',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop3.mailbox.org',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.mailbox.org',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Standard-Zugangsdaten für mailbox.org.',
        ],
        'hetzner' => [
            'label'      => 'Hetzner Webhosting (mail.your-server.de)',
            'domains'    => ['your-server.de'],
            'imap_host'  => 'mail.your-server.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'mail.your-server.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'mail.your-server.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Für eigene Domains auf Hetzner Webhosting (KonsoleH).',
        ],
        'allinkl' => [
            'label'      => 'ALL-INKL.COM (kasserver.com)',
            'domains'    => ['kasserver.com'],
            'imap_host'  => 'kasserver.com',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'kasserver.com',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'kasserver.com',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Für eigene Domains bei ALL-INKL.COM (KAS).',
        ],
        'netcologne' => [
            'label'      => 'NetCologne (netcologne.de)',
            'domains'    => ['netcologne.de'],
            'imap_host'  => 'imap.netcologne.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop3.netcologne.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.netcologne.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Standard-Server für NetCologne-Postfächer.',
        ],
        'o2' => [
            'label'      => 'o2 / Alice Mail (o2online.de / alice.de)',
            'domains'    => ['o2online.de', 'alice.de', 'alice-dsl.net'],
            'imap_host'  => 'imap.o2online.de',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => 'pop.o2online.de',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => 'smtp.o2online.de',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Standard-Server für o2online.de.',
        ],
        'custom' => [
            'label'      => 'Eigener Mailserver / Manuelle Eingabe',
            'domains'    => [],
            'imap_host'  => '',
            'imap_port'  => 993,
            'imap_ssl'   => 'ssl',
            'pop3_host'  => '',
            'pop3_port'  => 995,
            'pop3_ssl'   => 'ssl',
            'smtp_host'  => '',
            'smtp_port'  => 587,
            'smtp_ssl'   => 'tls',
            'hint'       => 'Trage die IMAP-/POP3- und SMTP-Serverdaten deines Providers manuell ein.',
        ],
    ];
}

/**
 * Erkennt anhand der E-Mail-Adresse automatisch den passenden Provider-Schlüssel.
 */
function detectMailProviderByEmail(string $email): string
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return 'custom';
    }
    $domain = substr(strrchr($email, '@') ?: '', 1);
    foreach (getMailProviderPresets() as $key => $preset) {
        if (in_array($domain, $preset['domains'], true)) {
            return $key;
        }
    }
    return 'custom';
}

/**
 * Ver- und Entschlüsselung des hinterlegten E-Mail-Passworts (AES-256-GCM).
 */
function getMailAccountCryptoKey(int $userId): string
{
    $secret = (defined('DB_PASS') ? (string)DB_PASS : '') . '|' . (defined('DB_NAME') ? (string)DB_NAME : 'minicloud') . '|mail_u_' . $userId;
    return hash('sha256', $secret, true);
}

function encryptMailPassword(string $plainPassword, int $userId): string
{
    if ($plainPassword === '') {
        return '';
    }
    $key = getMailAccountCryptoKey($userId);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plainPassword, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        return '';
    }
    return base64_encode($iv . $tag . $ct);
}

function decryptMailPassword(string $encoded, int $userId): string
{
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        return '';
    }
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $key = getMailAccountCryptoKey($userId);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($pt) ? $pt : '';
}

/**
 * Lädt ALLE gespeicherten (und temporären Sitzungs-) E-Mail-Postfächer eines Benutzers.
 *
 * @return array<int, array<string, mixed>>
 */
function getUserMailAccounts(PDO $pdo, int $userId): array
{
    ensureUserMailAccountSchema($pdo);

    $palette = [
        'bg-indigo-600',
        'bg-emerald-600',
        'bg-amber-600',
        'bg-rose-600',
        'bg-sky-600',
        'bg-purple-600',
        'bg-teal-600',
    ];
    $accounts = [];
    $idx = 0;

    // 1. Dauerhaft in den Einstellungen hinterlegte Postfächer
    $stmt = $pdo->prepare("SELECT * FROM user_mail_accounts WHERE user_id = ? ORDER BY id ASC");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (empty($row['incoming_host']) || empty($row['email_address'])) {
            continue;
        }
        $password = decryptMailPassword((string)$row['encrypted_password'], $userId);
        if ($password === '') {
            continue;
        }
        $accId = (int)$row['id'];
        $label = trim((string)($row['account_label'] ?? ''));
        if ($label === '') {
            $label = (string)$row['email_address'];
        }
        $accounts[] = [
            'id'            => $accId,
            'account_label' => $label,
            'email_address' => (string)$row['email_address'],
            'sender_name'   => (string)$row['sender_name'],
            'provider_key'  => (string)$row['provider_key'],
            'protocol'      => in_array($row['protocol'], ['imap', 'pop3'], true) ? (string)$row['protocol'] : 'imap',
            'incoming_host' => (string)$row['incoming_host'],
            'incoming_port' => (int)$row['incoming_port'],
            'incoming_ssl'  => (string)$row['incoming_ssl'],
            'smtp_host'     => (string)$row['smtp_host'],
            'smtp_port'     => (int)$row['smtp_port'],
            'smtp_ssl'      => (string)$row['smtp_ssl'],
            'username'      => (string)($row['username'] ?: $row['email_address']),
            'password'      => $password,
            'storage_mode'  => 'db',
            'badge_color'   => $palette[$idx % count($palette)],
        ];
        $idx++;
    }

    // 2. Temporäre Sitzungs-Postfächer (im RAM)
    $sessionList = $_SESSION['temp_mail_accounts_' . $userId] ?? [];
    if (!empty($_SESSION['temp_mail_account_' . $userId]) && is_array($_SESSION['temp_mail_account_' . $userId])) {
        $sessionList[-1] = $_SESSION['temp_mail_account_' . $userId];
    }
    if (is_array($sessionList)) {
        foreach ($sessionList as $sKey => $sAcc) {
            if (!is_array($sAcc) || empty($sAcc['incoming_host']) || empty($sAcc['email_address'])) {
                continue;
            }
            $negId = ((int)$sKey < 0) ? (int)$sKey : (-100 - $idx);
            $sAcc['id']            = $negId;
            $sAcc['account_label'] = trim((string)($sAcc['account_label'] ?? '')) ?: ((string)$sAcc['email_address'] . ' (Sitzung)');
            $sAcc['storage_mode']  = 'session';
            $sAcc['badge_color']   = $palette[$idx % count($palette)];
            $accounts[] = $sAcc;
            $idx++;
        }
    }

    return $accounts;
}

/**
 * Liefert ein bestimmtes Postfach anhand seiner ID (oder das erste vorhandene Postfach, falls $accountId === 0).
 *
 * @return array<string, mixed>|null
 */
function getUserMailAccountById(PDO $pdo, int $userId, int $accountId = 0): ?array
{
    $all = getUserMailAccounts($pdo, $userId);
    if (empty($all)) {
        return null;
    }
    if ($accountId === 0) {
        return $all[0];
    }
    foreach ($all as $acc) {
        if ((int)$acc['id'] === $accountId) {
            return $acc;
        }
    }
    return $all[0];
}

/**
 * Abwärtskompatibler Wrapper: liefert das Standard-Postfach des Benutzers.
 *
 * @return array<string, mixed>|null
 */
function getUserMailAccountConfig(PDO $pdo, int $userId): ?array
{
    return getUserMailAccountById($pdo, $userId, 0);
}

/**
 * Ruft für die gemeinsame Ansicht („🌐 Alle Postfächer“) live die neuesten E-Mails
 * aus allen eingebundenen Konten im Arbeitsspeicher ab und sortiert sie chronologisch.
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array{ok: bool, total: int, messages: array<int, array<string, mixed>>, errors: array<int, string>, folders: array<string, string>}
 */
function fetchUnifiedMailboxesMessages(array $accounts, int $perAccount = 12): array
{
    $mergedMessages = [];
    $totalCount     = 0;
    $errors         = [];

    foreach ($accounts as $acc) {
        $res = fetchLiveMailboxMessages($acc, 'INBOX', 1, $perAccount);
        if (!$res['ok']) {
            $errors[] = (string)$acc['email_address'] . ': ' . ($res['error'] ?? 'Verbindungsfehler');
            continue;
        }
        $totalCount += (int)($res['total'] ?? 0);
        foreach ($res['messages'] as $msg) {
            $msg['account_id']    = (int)$acc['id'];
            $msg['account_email'] = (string)$acc['email_address'];
            $msg['account_label'] = (string)$acc['account_label'];
            $msg['account_color'] = (string)$acc['badge_color'];
            $mergedMessages[]     = $msg;
        }
    }

    usort($mergedMessages, static fn(array $a, array $b): int => ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0)));

    return [
        'ok'       => !empty($mergedMessages) || empty($errors),
        'total'    => $totalCount,
        'messages' => $mergedMessages,
        'errors'   => $errors,
        'folders'  => ['INBOX' => '🌐 Gemeinsamer Posteingang (' . count($accounts) . ' Postfächer)'],
    ];
}

/**
 * Prüft einen Mailserver-Hostnamen auf SSRF-Sicherheit (keine lokalen/privaten IPs).
 */
function isSafeMailServerHost(string $host, int $port): bool
{
    $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        return false;
    }
    $allowedMailPorts = [110, 143, 465, 587, 993, 995, 2525];
    if (!in_array($port, $allowedMailPorts, true)) {
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

// ============================================================================
// MIME- & HEADER-HILFSFUNKTIONEN (REIN IM ARBEITSSPEICHER)
// ============================================================================

/**
 * Dekodiert RFC-2047-kodierte E-Mail-Header (z. B. =?UTF-8?B?...?= oder =?ISO-8859-1?Q?...?=).
 */
function decodeMimeHeaderValue(string $raw): string
{
    $raw = trim(preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('iconv_mime_decode')) {
        $dec = @iconv_mime_decode($raw, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($dec) && $dec !== '') {
            return trim($dec);
        }
    }
    if (function_exists('mb_decode_mimeheader')) {
        $dec = @mb_decode_mimeheader($raw);
        if (is_string($dec) && $dec !== '') {
            return trim($dec);
        }
    }
    return preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/', static function (array $m): string {
        $charset = strtoupper($m[1]);
        $enc     = strtoupper($m[2]);
        $data    = $m[3];
        $decoded = $enc === 'B'
            ? (base64_decode($data, true) ?: $data)
            : quoted_printable_decode(str_replace('_', ' ', $data));
        if ($charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
        }
        return $decoded;
    }, $raw) ?? $raw;
}

/**
 * Konvertiert einen E-Mail-Text anhand seines Zeichensatzes sauber nach UTF-8.
 */
function convertMailCharsetToUtf8(string $text, string $charset = 'UTF-8'): string
{
    $charset = strtoupper(trim($charset, " \t\n\r\0\x0B\"'"));
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII') {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        return $text;
    }
    if ($charset === 'ISO-8859-15' || $charset === 'LATIN1' || $charset === 'WINDOWS-1252') {
        $res = @iconv($charset, 'UTF-8//IGNORE', $text);
        if (is_string($res)) {
            return $res;
        }
    }
    if (function_exists('mb_convert_encoding')) {
        $res = @mb_convert_encoding($text, 'UTF-8', $charset);
        if (is_string($res)) {
            return $res;
        }
    }
    return $text;
}

/**
 * Bereinigt HTML-E-Mail-Inhalte für die sichere Anzeige (entfernt Skripte/Event-Handler).
 */
function sanitizeMailHtmlBody(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form|meta|link|base)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|iframe|object|embed|meta|link|base)[^>]*>#is', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/isu', '', $html) ?? $html;
    $html = preg_replace('/javascript\s*:/isu', 'blocked:', $html) ?? $html;
    return trim($html);
}

/**
 * Zerlegt eine rohe RFC-822-E-Mail (Header + Body / Multipart) vollständig im Arbeitsspeicher.
 *
 * @return array{subject: string, from: string, to: string, cc: string, date: string, timestamp: int, text_body: string, html_body: string, attachments: array<int, array{filename: string, mime: string, size: int, content_b64: string}>}
 */
function parseRawEmailMessageInMemory(string $rawEmail): array
{
    $parts = preg_split("/\r?\n\r?\n/", $rawEmail, 2);
    $rawHeaderBlock = $parts[0] ?? '';
    $rawBodyBlock   = $parts[1] ?? '';

    // Header entfalten (Unfolding)
    $unfoldedHeaders = preg_replace("/\r?\n[ \t]+/", ' ', $rawHeaderBlock) ?? $rawHeaderBlock;
    $headers = [];
    foreach (preg_split("/\r?\n/", $unfoldedHeaders) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$hName, $hVal] = explode(':', $line, 2);
            $k = strtolower(trim($hName));
            if (!isset($headers[$k])) {
                $headers[$k] = trim($hVal);
            } else {
                $headers[$k] .= ', ' . trim($hVal);
            }
        }
    }

    $subject = decodeMimeHeaderValue($headers['subject'] ?? '(Kein Betreff)');
    $from    = decodeMimeHeaderValue($headers['from'] ?? '');
    $to      = decodeMimeHeaderValue($headers['to'] ?? '');
    $cc      = decodeMimeHeaderValue($headers['cc'] ?? '');
    $dateRaw = $headers['date'] ?? '';
    $ts      = $dateRaw !== '' ? (strtotime($dateRaw) ?: time()) : time();

    $textBody    = '';
    $htmlBody    = '';
    $attachments = [];

    $parseMimePart = static function (string $partHeaderRaw, string $partBodyRaw) use (&$parseMimePart, &$textBody, &$htmlBody, &$attachments): void {
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $partHeaderRaw) ?? $partHeaderRaw;
        $pHeaders = [];
        foreach (preg_split("/\r?\n/", $unfolded) ?: [] as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $pHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        $contentType = $pHeaders['content-type'] ?? 'text/plain; charset=UTF-8';
        $transferEnc = strtolower(trim($pHeaders['content-transfer-encoding'] ?? '7bit'));
        $disposition = $pHeaders['content-disposition'] ?? '';

        // Prüfen auf Multipart
        if ( stripos($contentType, 'multipart/') === 0 && preg_match('/boundary\s*=\s*["\']?([^"\';\s]+)["\']?/i', $contentType, $mBound) ) {
            $boundary = $mBound[1];
            $subParts = explode('--' . $boundary, $partBodyRaw);
            array_shift($subParts); // Präambel verwerfen
            foreach ($subParts as $sp) {
                $sp = ltrim($sp, "\r\n");
                if (str_starts_with($sp, '--')) {
                    break;
                }
                $spSplit = preg_split("/\r?\n\r?\n/", $sp, 2);
                if (count($spSplit) === 2) {
                    $parseMimePart($spSplit[0], rtrim($spSplit[1], "\r\n"));
                }
            }
            return;
        }

        // Inhalt dekodieren
        $decodedData = match ($transferEnc) {
            'base64'           => base64_decode(preg_replace('/\s+/', '', $partBodyRaw) ?? '', true) ?: '',
            'quoted-printable' => quoted_printable_decode($partBodyRaw),
            default            => $partBodyRaw,
        };

        // Dateiname prüfen (Anhang)
        $filename = '';
        if (preg_match('/filename\*?=\s*["\']?([^"\';\r\n]+)["\']?/i', $disposition, $mFn)) {
            $filename = decodeMimeHeaderValue(trim($mFn[1]));
        } elseif (preg_match('/name\*?=\s*["\']?([^"\';\r\n]+)["\']?/i', $contentType, $mFn2)) {
            $filename = decodeMimeHeaderValue(trim($mFn2[1]));
        }
        if (str_starts_with(strtolower($filename), "utf-8''")) {
            $filename = rawurldecode(substr($filename, 7));
        }

        $mimeMain = strtolower(trim(explode(';', $contentType, 2)[0]));
        $isAttachment = (stripos($disposition, 'attachment') !== false)
            || ($filename !== '' && !in_array($mimeMain, ['text/plain', 'text/html'], true));

        if ($isAttachment) {
            $safeName = sanitizeOriginalFilename($filename !== '' ? $filename : 'Anhang.bin');
            if (strlen($decodedData) > 0 && strlen($decodedData) <= 15 * 1024 * 1024) {
                $attachments[] = [
                    'filename'    => $safeName,
                    'mime'        => $mimeMain !== '' ? $mimeMain : 'application/octet-stream',
                    'size'        => strlen($decodedData),
                    'content_b64' => base64_encode($decodedData),
                ];
            }
            return;
        }

        $charset = 'UTF-8';
        if (preg_match('/charset\s*=\s*["\']?([^"\';\s]+)["\']?/i', $contentType, $mCs)) {
            $charset = $mCs[1];
        }

        if ($mimeMain === 'text/html') {
            $utf8 = convertMailCharsetToUtf8($decodedData, $charset);
            $htmlBody .= ($htmlBody !== '' ? "<hr>" : '') . sanitizeMailHtmlBody($utf8);
        } elseif ($mimeMain === 'text/plain' || str_starts_with($mimeMain, 'text/')) {
            $utf8 = convertMailCharsetToUtf8($decodedData, $charset);
            $textBody .= ($textBody !== '' ? "\n\n" : '') . trim($utf8);
        }
    };

    $parseMimePart($rawHeaderBlock, $rawBodyBlock);

    if ($textBody === '' && $htmlBody !== '') {
        $textBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?? $htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return [
        'subject'     => $subject !== '' ? $subject : '(Kein Betreff)',
        'from'        => $from,
        'to'          => $to,
        'cc'          => $cc,
        'date'        => date('d.m.Y H:i', $ts),
        'timestamp'   => $ts,
        'text_body'   => $textBody,
        'html_body'   => $htmlBody,
        'attachments' => $attachments,
    ];
}

// ============================================================================
// POP3-CLIENT (PORT 995 SSL / 110 STARTTLS – STRIKT OHNE LÖSCHEN BEIM ABRUF!)
// ============================================================================

/**
 * Öffnet und authentifiziert eine POP3-Verbindung.
 *
 * @return array{ok: bool, fp?: resource, error?: string, count?: int}
 */
function openPop3Connection(array $cfg): array
{
    $host = trim((string)($cfg['incoming_host'] ?? ''));
    $port = (int)($cfg['incoming_port'] ?? 995);
    $ssl  = strtolower((string)($cfg['incoming_ssl'] ?? 'ssl'));
    $user = (string)($cfg['username'] ?? $cfg['email_address'] ?? '');
    $pass = (string)($cfg['password'] ?? '');

    if (!isSafeMailServerHost($host, $port)) {
        return ['ok' => false, 'error' => 'Ungültiger POP3-Server oder nicht erlaubter Port.'];
    }

    $remote = ($ssl === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'error' => "POP3-Verbindung zu {$host}:{$port} fehlgeschlagen ({$errstr})."];
    }
    stream_set_timeout($fp, 8);

    $greeting = (string)@fgets($fp, 2048);
    if (!str_starts_with($greeting, '+OK')) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'POP3-Server hat die Verbindung abgelehnt.'];
    }

    if ($ssl === 'tls') {
        fwrite($fp, "STLS\r\n");
        $stlsResp = (string)@fgets($fp, 2048);
        if (!str_starts_with($stlsResp, '+OK') || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            return ['ok' => false, 'error' => 'POP3 STARTTLS-Verschlüsselung fehlgeschlagen.'];
        }
    }

    fwrite($fp, 'USER ' . str_replace(["\r", "\n"], '', $user) . "\r\n");
    $uResp = (string)@fgets($fp, 2048);
    if (!str_starts_with($uResp, '+OK')) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'POP3-Benutzername wurde vom Server abgelehnt.'];
    }

    fwrite($fp, 'PASS ' . str_replace(["\r", "\n"], '', $pass) . "\r\n");
    $pResp = (string)@fgets($fp, 2048);
    if (!str_starts_with($pResp, '+OK')) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'Anmeldung fehlgeschlagen (bitte Passwort bzw. POP3-Freigabe beim Provider prüfen).'];
    }

    fwrite($fp, "STAT\r\n");
    $statResp = (string)@fgets($fp, 2048);
    $msgCount = 0;
    if (preg_match('/^\+OK\s+(\d+)/', $statResp, $mStat)) {
        $msgCount = (int)$mStat[1];
    }

    return ['ok' => true, 'fp' => $fp, 'count' => $msgCount];
}

function readPop3Multiline($fp, int $maxBytes = 5242880): string
{
    $buf = '';
    while (!feof($fp)) {
        $line = @fgets($fp, 8192);
        if ($line === false) {
            break;
        }
        if ($line === ".\r\n" || $line === ".\n") {
            break;
        }
        if (str_starts_with($line, '..')) {
            $line = substr($line, 1);
        }
        if (strlen($buf) < $maxBytes) {
            $buf .= $line;
        }
    }
    return $buf;
}

// ============================================================================
// IMAP-CLIENT (PORT 993 SSL / 143 STARTTLS – REIN IM ARBEITSSPEICHER)
// ============================================================================

/**
 * Öffnet und authentifiziert eine IMAP-Verbindung.
 *
 * @return array{ok: bool, fp?: resource, error?: string}
 */
function openImapConnection(array $cfg): array
{
    $host = trim((string)($cfg['incoming_host'] ?? ''));
    $port = (int)($cfg['incoming_port'] ?? 993);
    $ssl  = strtolower((string)($cfg['incoming_ssl'] ?? 'ssl'));
    $user = (string)($cfg['username'] ?? $cfg['email_address'] ?? '');
    $pass = (string)($cfg['password'] ?? '');

    if (!isSafeMailServerHost($host, $port)) {
        return ['ok' => false, 'error' => 'Ungültiger IMAP-Server oder nicht erlaubter Port.'];
    }

    $remote = ($ssl === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'error' => "IMAP-Verbindung zu {$host}:{$port} fehlgeschlagen ({$errstr})."];
    }
    stream_set_timeout($fp, 8);

    $greeting = (string)@fgets($fp, 4096);
    if (!str_starts_with($greeting, '* OK')) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'IMAP-Server hat die Verbindung abgelehnt.'];
    }

    if ($ssl === 'tls') {
        fwrite($fp, "A01 STARTTLS\r\n");
        $tlsResp = readImapTaggedResponse($fp, 'A01');
        if (!$tlsResp['ok'] || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            return ['ok' => false, 'error' => 'IMAP STARTTLS-Verschlüsselung fehlgeschlagen.'];
        }
    }

    $quotedUser = '"' . addcslashes($user, '\\"') . '"';
    $quotedPass = '"' . addcslashes($pass, '\\"') . '"';
    fwrite($fp, "A02 LOGIN {$quotedUser} {$quotedPass}\r\n");
    $loginResp = readImapTaggedResponse($fp, 'A02');
    if (!$loginResp['ok']) {
        @fclose($fp);
        return ['ok' => false, 'error' => 'IMAP-Anmeldung fehlgeschlagen (bitte E-Mail/Passwort oder App-Passwort prüfen).'];
    }

    return ['ok' => true, 'fp' => $fp];
}

/**
 * Liest alle Zeilen einer IMAP-Antwort bis zum angegebenen Befehls-Tag (z. B. A02 OK / NO / BAD).
 *
 * @return array{ok: bool, raw: string, status_line: string}
 */
function readImapTaggedResponse($fp, string $tag, int $maxBytes = 8388608): array
{
    $raw        = '';
    $statusLine = '';
    $ok         = false;
    $tagPrefix  = $tag . ' ';

    while (!feof($fp)) {
        $line = @fgets($fp, 8192);
        if ($line === false) {
            break;
        }
        if (strlen($raw) < $maxBytes) {
            $raw .= $line;
        }
        if (str_starts_with($line, $tagPrefix)) {
            $statusLine = trim($line);
            $rest       = ltrim(substr($statusLine, strlen($tagPrefix)));
            $ok         = stripos($rest, 'OK') === 0;
            break;
        }
    }

    return ['ok' => $ok, 'raw' => $raw, 'status_line' => $statusLine];
}

/**
 * Testet die E-Mail-Verbindung (IMAP/POP3).
 *
 * @return array{ok: bool, message: string}
 */
function testMailAccountConnection(array $cfg): array
{
    $proto = strtolower((string)($cfg['protocol'] ?? 'imap'));
    if ($proto === 'pop3') {
        $res = openPop3Connection($cfg);
        if (!$res['ok']) {
            return ['ok' => false, 'message' => (string)$res['error']];
        }
        fwrite($res['fp'], "QUIT\r\n");
        @fclose($res['fp']);
        return [
            'ok'      => true,
            'message' => '✅ POP3-Verbindung erfolgreich! (' . (int)($res['count'] ?? 0) . ' Nachrichten im Posteingang – werden nicht vom Server gelöscht).',
        ];
    }

    $res = openImapConnection($cfg);
    if (!$res['ok']) {
        return ['ok' => false, 'message' => (string)$res['error']];
    }
    fwrite($res['fp'], "A03 SELECT \"INBOX\"\r\n");
    $sel = readImapTaggedResponse($res['fp'], 'A03');
    $msgCount = 0;
    if (preg_match('/\*\s+(\d+)\s+EXISTS/i', $sel['raw'], $mEx)) {
        $msgCount = (int)$mEx[1];
    }
    fwrite($res['fp'], "A99 LOGOUT\r\n");
    @fclose($res['fp']);

    return [
        'ok'      => true,
        'message' => "✅ IMAP-Verbindung erfolgreich! ({$msgCount} E-Mails im Posteingang verfügbar – 100 % Live-Ansicht ohne lokale Speicherung).",
    ];
}

/**
 * Ruft die Nachrichtenliste eines Ordners live ab (ohne lokale Speicherung).
 *
 * @return array{ok: bool, error?: string, total: int, messages: array<int, array<string, mixed>>, folders: array<string, string>}
 */
function fetchLiveMailboxMessages(array $cfg, string $folder = 'INBOX', int $page = 1, int $perPage = 20): array
{
    $proto   = strtolower((string)($cfg['protocol'] ?? 'imap'));
    $page    = max(1, $page);
    $perPage = max(5, min(50, $perPage));

    if ($proto === 'pop3') {
        $conn = openPop3Connection($cfg);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => (string)$conn['error'], 'total' => 0, 'messages' => [], 'folders' => ['INBOX' => '📥 Posteingang (POP3)']];
        }
        $fp    = $conn['fp'];
        $total = (int)($conn['count'] ?? 0);

        $messages = [];
        if ($total > 0) {
            $startSeq = max(1, $total - (($page - 1) * $perPage));
            $endSeq   = max(1, $startSeq - $perPage + 1);

            for ($seq = $startSeq; $seq >= $endSeq; $seq--) {
                // TOP <msg> 0 liest nur die Header und lässt die Nachricht unberührt auf dem Server!
                fwrite($fp, "TOP {$seq} 0\r\n");
                $status = (string)@fgets($fp, 2048);
                if (!str_starts_with($status, '+OK')) {
                    continue;
                }
                $rawHeaders = readPop3Multiline($fp, 65536);
                $parsed     = parseRawEmailMessageInMemory($rawHeaders . "\r\n\r\n");
                $messages[] = [
                    'uid'     => (string)$seq,
                    'subject' => $parsed['subject'],
                    'from'    => $parsed['from'],
                    'to'      => $parsed['to'],
                    'date'    => $parsed['date'],
                    'ts'      => $parsed['timestamp'],
                    'seen'    => true,
                ];
            }
        }

        fwrite($fp, "QUIT\r\n");
        @fclose($fp);

        return [
            'ok'       => true,
            'total'    => $total,
            'messages' => $messages,
            'folders'  => ['INBOX' => '📥 Posteingang (POP3)'],
        ];
    }

    // IMAP-Abruf
    $conn = openImapConnection($cfg);
    if (!$conn['ok']) {
        return ['ok' => false, 'error' => (string)$conn['error'], 'total' => 0, 'messages' => [], 'folders' => ['INBOX' => '📥 Posteingang']];
    }
    $fp = $conn['fp'];

    // Verfügbare Ordner ermitteln
    $folders = ['INBOX' => '📥 Posteingang'];
    fwrite($fp, "A03 LIST \"\" \"*\"\r\n");
    $listResp = readImapTaggedResponse($fp, 'A03');
    if (preg_match_all('/^\*\s+LIST\s+\(([^)]*)\)\s+"[^"]*"\s+"?([^"\r\n]+)"?/im', $listResp['raw'], $mList, PREG_SET_ORDER)) {
        foreach ($mList as $row) {
            $flags = strtolower($row[1]);
            $fName = trim($row[2]);
            if (str_contains($flags, '\\noselect') || $fName === '' || strtoupper($fName) === 'INBOX') {
                continue;
            }
            $fLower = strtolower($fName);
            if (str_contains($flags, '\\sent') || in_array($fLower, ['sent', 'sent messages', 'gesendet', 'inbox.sent'], true)) {
                $folders[$fName] = '📤 Gesendet';
            } elseif (str_contains($flags, '\\drafts') || in_array($fLower, ['drafts', 'entw&apw-rfe', 'entwürfe', 'inbox.drafts'], true)) {
                $folders[$fName] = '📝 Entwürfe';
            } elseif (str_contains($flags, '\\junk') || in_array($fLower, ['junk', 'spam', 'bulk', 'inbox.spam'], true)) {
                $folders[$fName] = '🛡️ Spam';
            } elseif (str_contains($flags, '\\trash') || in_array($fLower, ['trash', 'deleted', 'papierkorb', 'inbox.trash'], true)) {
                $folders[$fName] = '🗑️ Papierkorb';
            }
        }
    }

    if (!isset($folders[$folder])) {
        $folder = 'INBOX';
    }

    $quotedFolder = '"' . addcslashes($folder, '\\"') . '"';
    fwrite($fp, "A04 EXAMINE {$quotedFolder}\r\n");
    $selResp = readImapTaggedResponse($fp, 'A04');
    if (!$selResp['ok']) {
        fwrite($fp, "A99 LOGOUT\r\n");
        @fclose($fp);
        return ['ok' => false, 'error' => "Ordner „{$folder}“ konnte nicht geöffnet werden.", 'total' => 0, 'messages' => [], 'folders' => $folders];
    }

    $total = 0;
    if (preg_match('/\*\s+(\d+)\s+EXISTS/i', $selResp['raw'], $mEx)) {
        $total = (int)$mEx[1];
    }

    $messages = [];
    if ($total > 0) {
        $endSeq   = max(1, $total - (($page - 1) * $perPage));
        $startSeq = max(1, $endSeq - $perPage + 1);

        fwrite($fp, "A05 FETCH {$startSeq}:{$endSeq} (UID FLAGS BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO DATE)])\r\n");
        $fetchResp = readImapTaggedResponse($fp, 'A05', 2097152);

        $blocks = preg_split('/^\*\s+\d+\s+FETCH\s+\(/im', $fetchResp['raw']) ?: [];
        array_shift($blocks);

        foreach ($blocks as $blk) {
            $uid = '';
            if (preg_match('/\bUID\s+(\d+)/i', $blk, $mUid)) {
                $uid = $mUid[1];
            }
            if ($uid === '') {
                continue;
            }
            $seen = (bool)preg_match('/\\\\Seen/i', $blk);

            $hdrPart = '';
            if (preg_match('/\{(\d+)\}\r?\n(.*)$/is', $blk, $mLit)) {
                $litLen  = (int)$mLit[1];
                $hdrPart = substr($mLit[2], 0, $litLen);
            }

            $parsed = parseRawEmailMessageInMemory($hdrPart . "\r\n\r\n");
            $messages[] = [
                'uid'     => $uid,
                'subject' => $parsed['subject'],
                'from'    => $parsed['from'],
                'to'      => $parsed['to'],
                'date'    => $parsed['date'],
                'ts'      => $parsed['timestamp'],
                'seen'    => $seen,
            ];
        }

        usort($messages, static fn(array $a, array $b): int => ((int)$b['ts']) <=> ((int)$a['ts']));
    }

    fwrite($fp, "A99 LOGOUT\r\n");
    @fclose($fp);

    return [
        'ok'       => true,
        'total'    => $total,
        'messages' => $messages,
        'folders'  => $folders,
    ];
}

/**
 * Lädt eine einzelne E-Mail inkl. Anhängen live in den Arbeitsspeicher (ohne Speicherung auf dem Server).
 *
 * @return array{ok: bool, error?: string, mail?: array<string, mixed>}
 */
function fetchLiveSingleEmail(array $cfg, string $folder, string $uid): array
{
    $proto = strtolower((string)($cfg['protocol'] ?? 'imap'));
    $uidInt = max(1, (int)$uid);

    if ($proto === 'pop3') {
        $conn = openPop3Connection($cfg);
        if (!$conn['ok']) {
            return ['ok' => false, 'error' => (string)$conn['error']];
        }
        $fp = $conn['fp'];
        // RETR ruft die E-Mail ab, ohne DELE zu senden -> Nachricht bleibt auf dem POP3-Server erhalten!
        fwrite($fp, "RETR {$uidInt}\r\n");
        $status = (string)@fgets($fp, 2048);
        if (!str_starts_with($status, '+OK')) {
            fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            return ['ok' => false, 'error' => 'Nachricht konnte nicht vom POP3-Server abgerufen werden.'];
        }
        $rawEmail = readPop3Multiline($fp, 12 * 1024 * 1024);
        fwrite($fp, "QUIT\r\n");
        @fclose($fp);

        return ['ok' => true, 'mail' => parseRawEmailMessageInMemory($rawEmail)];
    }

    $conn = openImapConnection($cfg);
    if (!$conn['ok']) {
        return ['ok' => false, 'error' => (string)$conn['error']];
    }
    $fp = $conn['fp'];

    $quotedFolder = '"' . addcslashes($folder, '\\"') . '"';
    fwrite($fp, "A03 SELECT {$quotedFolder}\r\n");
    $sel = readImapTaggedResponse($fp, 'A03');
    if (!$sel['ok']) {
        fwrite($fp, "A99 LOGOUT\r\n");
        @fclose($fp);
        return ['ok' => false, 'error' => 'Ordner konnte nicht geöffnet werden.'];
    }

    fwrite($fp, "A04 UID FETCH {$uidInt} (BODY.PEEK[])\r\n");
    $fetchResp = readImapTaggedResponse($fp, 'A04', 15 * 1024 * 1024);
    fwrite($fp, "A99 LOGOUT\r\n");
    @fclose($fp);

    if (!$fetchResp['ok']) {
        return ['ok' => false, 'error' => 'E-Mail konnte nicht geladen werden.'];
    }

    $rawEmail = '';
    if (preg_match('/\{(\d+)\}\r?\n(.*)$/is', $fetchResp['raw'], $mLit)) {
        $litLen   = (int)$mLit[1];
        $rawEmail = substr($mLit[2], 0, $litLen);
    }

    if ($rawEmail === '') {
        return ['ok' => false, 'error' => 'Leere Antwort vom IMAP-Server.'];
    }

    return ['ok' => true, 'mail' => parseRawEmailMessageInMemory($rawEmail)];
}

/**
 * Löscht eine E-Mail auf ausdrücklichen Wunsch des Benutzers auf dem Mailserver.
 *
 * @return array{ok: bool, message: string}
 */
function deleteLiveEmailOnServer(array $cfg, string $folder, string $uid): array
{
    $proto  = strtolower((string)($cfg['protocol'] ?? 'imap'));
    $uidInt = max(1, (int)$uid);

    if ($proto === 'pop3') {
        $conn = openPop3Connection($cfg);
        if (!$conn['ok']) {
            return ['ok' => false, 'message' => (string)$conn['error']];
        }
        $fp = $conn['fp'];
        fwrite($fp, "DELE {$uidInt}\r\n");
        $resp = (string)@fgets($fp, 2048);
        fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return [
            'ok'      => str_starts_with($resp, '+OK'),
            'message' => str_starts_with($resp, '+OK') ? 'Nachricht wurde auf dem Server gelöscht.' : 'Nachricht konnte nicht gelöscht werden.',
        ];
    }

    $conn = openImapConnection($cfg);
    if (!$conn['ok']) {
        return ['ok' => false, 'message' => (string)$conn['error']];
    }
    $fp = $conn['fp'];
    $quotedFolder = '"' . addcslashes($folder, '\\"') . '"';
    fwrite($fp, "A03 SELECT {$quotedFolder}\r\n");
    readImapTaggedResponse($fp, 'A03');

    fwrite($fp, "A04 UID STORE {$uidInt} +FLAGS (\\Deleted)\r\n");
    readImapTaggedResponse($fp, 'A04');
    fwrite($fp, "A05 EXPUNGE\r\n");
    $exp = readImapTaggedResponse($fp, 'A05');
    fwrite($fp, "A99 LOGOUT\r\n");
    @fclose($fp);

    return [
        'ok'      => $exp['ok'],
        'message' => $exp['ok'] ? 'E-Mail wurde auf dem Server gelöscht.' : 'E-Mail konnte nicht gelöscht werden.',
    ];
}

/**
 * Führt eine Massen-Aktion (Massenlöschen, Als gelesen/ungelesen markieren) über eine oder mehrere
 * ausgewählte E-Mails aus – gebündelt pro Postfach & Ordner in einer einzigen Server-Verbindung.
 *
 * @param array<int, array{uid?: mixed, account_id?: mixed, folder?: mixed}> $items
 * @return array{ok: bool, message: string, affected: int, errors: array<int, string>}
 */
function executeBulkLiveMailAction(PDO $pdo, int $userId, string $op, array $items): array
{
    if (!in_array($op, ['delete', 'mark_seen', 'mark_unseen'], true)) {
        return ['ok' => false, 'message' => 'Ungültige Massen-Aktion.', 'affected' => 0, 'errors' => []];
    }

    // Gruppiere ausgewählte E-Mails nach (account_id, folder)
    $grouped = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $uidInt = max(0, (int)($it['uid'] ?? 0));
        if ($uidInt <= 0) {
            continue;
        }
        $accId  = (int)($it['account_id'] ?? 0);
        $folder = trim((string)($it['folder'] ?? 'INBOX')) ?: 'INBOX';
        $gKey   = $accId . '|' . $folder;
        if (!isset($grouped[$gKey])) {
            $grouped[$gKey] = [
                'account_id' => $accId,
                'folder'     => $folder,
                'uids'       => [],
            ];
        }
        $grouped[$gKey]['uids'][$uidInt] = $uidInt;
    }

    if (empty($grouped)) {
        return ['ok' => false, 'message' => 'Keine gültigen E-Mails ausgewählt.', 'affected' => 0, 'errors' => []];
    }

    $affected = 0;
    $errors   = [];

    foreach ($grouped as $grp) {
        $accCfg = getUserMailAccountById($pdo, $userId, (int)$grp['account_id']);
        if ($accCfg === null) {
            $errors[] = 'Postfach nicht gefunden.';
            continue;
        }

        $uids  = array_values($grp['uids']);
        $proto = strtolower((string)($accCfg['protocol'] ?? 'imap'));
        $lbl   = (string)($accCfg['account_label'] ?: $accCfg['email_address']);

        if ($proto === 'pop3') {
            if ($op !== 'delete') {
                // POP3 unterstützt technisch keine Server-Flags für gelesen/ungelesen
                $affected += count($uids);
                continue;
            }
            $conn = openPop3Connection($accCfg);
            if (!$conn['ok']) {
                $errors[] = "{$lbl}: " . (string)$conn['error'];
                continue;
            }
            $fp = $conn['fp'];
            // Bei POP3 absteigend löschen, falls Nummern sich verschieben
            rsort($uids, SORT_NUMERIC);
            foreach ($uids as $u) {
                fwrite($fp, "DELE {$u}\r\n");
                $r = (string)@fgets($fp, 2048);
                if (str_starts_with($r, '+OK')) {
                    $affected++;
                }
            }
            fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            continue;
        }

        // IMAP: Alle UIDs des Ordners in einem einzigen UID STORE Befehl verarbeiten
        $conn = openImapConnection($accCfg);
        if (!$conn['ok']) {
            $errors[] = "{$lbl}: " . (string)$conn['error'];
            continue;
        }
        $fp = $conn['fp'];
        $quotedFolder = '"' . addcslashes((string)$grp['folder'], '\\"') . '"';
        fwrite($fp, "A03 SELECT {$quotedFolder}\r\n");
        $sel = readImapTaggedResponse($fp, 'A03');
        if (!$sel['ok']) {
            fwrite($fp, "A99 LOGOUT\r\n");
            @fclose($fp);
            $errors[] = "{$lbl}: Ordner „{$grp['folder']}“ konnte nicht geöffnet werden.";
            continue;
        }

        $uidSet = implode(',', $uids);
        if ($op === 'delete') {
            fwrite($fp, "A04 UID STORE {$uidSet} +FLAGS.SILENT (\\Deleted)\r\n");
            $st = readImapTaggedResponse($fp, 'A04');
            fwrite($fp, "A05 EXPUNGE\r\n");
            $exp = readImapTaggedResponse($fp, 'A05');
            if ($st['ok'] && $exp['ok']) {
                $affected += count($uids);
            } else {
                $errors[] = "{$lbl}: Löschen fehlgeschlagen.";
            }
        } elseif ($op === 'mark_seen') {
            fwrite($fp, "A04 UID STORE {$uidSet} +FLAGS.SILENT (\\Seen)\r\n");
            $st = readImapTaggedResponse($fp, 'A04');
            if ($st['ok']) {
                $affected += count($uids);
            }
        } elseif ($op === 'mark_unseen') {
            fwrite($fp, "A04 UID STORE {$uidSet} -FLAGS.SILENT (\\Seen)\r\n");
            $st = readImapTaggedResponse($fp, 'A04');
            if ($st['ok']) {
                $affected += count($uids);
            }
        }

        fwrite($fp, "A99 LOGOUT\r\n");
        @fclose($fp);
    }

    $msg = match ($op) {
        'delete'      => "{$affected} E-Mail(s) wurden auf dem Mailserver gelöscht.",
        'mark_seen'   => "{$affected} E-Mail(s) als gelesen markiert.",
        'mark_unseen' => "{$affected} E-Mail(s) als ungelesen markiert.",
        default       => "{$affected} E-Mail(s) aktualisiert.",
    };

    return [
        'ok'       => $affected > 0 || empty($errors),
        'message'  => $msg,
        'affected' => $affected,
        'errors'   => $errors,
    ];
}

// ============================================================================
// SMTP-VERSAND (PORT 587 STARTTLS / 465 SSL – MIT OPTIONALEM CLOUD-ANHANG)
// ============================================================================

/**
 * Versendet eine E-Mail direkt über den SMTP-Server des Providers (ohne lokale Speicherung).
 *
 * @param array<int, array{filename: string, mime: string, content: string}> $attachments
 * @return array{ok: bool, message: string}
 */
function sendLiveEmailViaSmtp(array $cfg, string $to, string $cc, string $subject, string $bodyText, array $attachments = []): array
{
    $host      = trim((string)($cfg['smtp_host'] ?? ''));
    $port      = (int)($cfg['smtp_port'] ?? 587);
    $ssl       = strtolower((string)($cfg['smtp_ssl'] ?? 'tls'));
    $user      = (string)($cfg['username'] ?? $cfg['email_address'] ?? '');
    $pass      = (string)($cfg['password'] ?? '');
    $fromEmail = trim((string)($cfg['email_address'] ?? $user));
    $fromName  = trim((string)($cfg['sender_name'] ?? ''));

    if (!isSafeMailServerHost($host, $port)) {
        return ['ok' => false, 'message' => 'Ungültiger SMTP-Server oder Port.'];
    }

    // Empfänger extrahieren
    $recipients = [];
    foreach (preg_split('/[,;]+/', $to . ',' . $cc) ?: [] as $addrRaw) {
        $addrRaw = trim($addrRaw);
        if ($addrRaw === '') {
            continue;
        }
        if (preg_match('/<([^>]+)>/', $addrRaw, $mA)) {
            $addrRaw = trim($mA[1]);
        }
        if (filter_var($addrRaw, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $addrRaw;
        }
    }
    $recipients = array_values(array_unique($recipients));
    if (empty($recipients)) {
        return ['ok' => false, 'message' => 'Bitte gib mindestens eine gültige Empfänger-E-Mail-Adresse an.'];
    }

    $remote = ($ssl === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'message' => "SMTP-Verbindung zu {$host}:{$port} fehlgeschlagen ({$errstr})."];
    }
    stream_set_timeout($fp, 10);

    $readSmtp = static function () use ($fp): array {
        $resp = '';
        $code = 0;
        while (!feof($fp)) {
            $line = @fgets($fp, 2048);
            if ($line === false) {
                break;
            }
            $resp .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return ['code' => $code, 'resp' => trim($resp)];
    };

    $greet = $readSmtp();
    if ($greet['code'] !== 220) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'SMTP-Server nicht bereit: ' . $greet['resp']];
    }

    fwrite($fp, "EHLO minicloud.local\r\n");
    $ehlo = $readSmtp();

    if ($ssl === 'tls') {
        fwrite($fp, "STARTTLS\r\n");
        $tls = $readSmtp();
        if ($tls['code'] !== 220 || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            return ['ok' => false, 'message' => 'SMTP STARTTLS fehlgeschlagen.'];
        }
        fwrite($fp, "EHLO minicloud.local\r\n");
        $readSmtp();
    }

    // AUTH LOGIN
    fwrite($fp, "AUTH LOGIN\r\n");
    $auth1 = $readSmtp();
    if ($auth1['code'] !== 334) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'SMTP-Authentifizierung wird vom Server nicht unterstützt.'];
    }
    fwrite($fp, base64_encode($user) . "\r\n");
    $auth2 = $readSmtp();
    if ($auth2['code'] !== 334) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'SMTP-Benutzername abgelehnt.'];
    }
    fwrite($fp, base64_encode($pass) . "\r\n");
    $auth3 = $readSmtp();
    if ($auth3['code'] !== 235) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'SMTP-Passwort wurde abgelehnt.'];
    }

    fwrite($fp, "MAIL FROM:<{$fromEmail}>\r\n");
    $mf = $readSmtp();
    if ($mf['code'] < 200 || $mf['code'] >= 300) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'Absenderadresse wurde vom SMTP-Server abgelehnt.'];
    }

    foreach ($recipients as $rcpt) {
        fwrite($fp, "RCPT TO:<{$rcpt}>\r\n");
        $rc = $readSmtp();
        if ($rc['code'] < 200 || $rc['code'] >= 300) {
            @fclose($fp);
            return ['ok' => false, 'message' => "Empfänger {$rcpt} wurde vom SMTP-Server abgelehnt."];
        }
    }

    fwrite($fp, "DATA\r\n");
    $dt = $readSmtp();
    if ($dt['code'] !== 354) {
        @fclose($fp);
        return ['ok' => false, 'message' => 'SMTP DATA-Befehl fehlgeschlagen.'];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHeader = $fromName !== ''
        ? ('=?UTF-8?B?' . base64_encode($fromName) . "?= <{$fromEmail}>")
        : "<{$fromEmail}>";

    $boundary = '=_MiniCloudMail_' . bin2hex(random_bytes(12));
    $headers  = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . str_replace(["\r", "\n"], '', $to);
    if (trim($cc) !== '') {
        $headers[] = 'Cc: ' . str_replace(["\r", "\n"], '', $cc);
    }
    $headers[] = 'Subject: ' . $encodedSubject;
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode('@', $fromEmail)[1] ?? 'minicloud.local') . '>';
    $headers[] = 'MIME-Version: 1.0';

    if (empty($attachments)) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $mimeBody  = chunk_split(base64_encode($bodyText), 76, "\r\n");
    } else {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $mimeBody  = "--{$boundary}\r\n";
        $mimeBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mimeBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $mimeBody .= chunk_split(base64_encode($bodyText), 76, "\r\n") . "\r\n";

        foreach ($attachments as $att) {
            $attName = '=?UTF-8?B?' . base64_encode($att['filename']) . '?=';
            $mimeBody .= "--{$boundary}\r\n";
            $mimeBody .= "Content-Type: {$att['mime']}; name=\"{$attName}\"\r\n";
            $mimeBody .= "Content-Disposition: attachment; filename=\"{$attName}\"\r\n";
            $mimeBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $mimeBody .= chunk_split(base64_encode($att['content']), 76, "\r\n") . "\r\n";
        }
        $mimeBody .= "--{$boundary}--\r\n";
    }

    $fullMessage = implode("\r\n", $headers) . "\r\n\r\n" . $mimeBody;
    // Dot-Stuffing nach RFC 5321
    $fullMessage = preg_replace('/^\./m', '..', $fullMessage) ?? $fullMessage;

    fwrite($fp, $fullMessage . "\r\n.\r\n");
    $sendRes = $readSmtp();
    fwrite($fp, "QUIT\r\n");
    @fclose($fp);

    if ($sendRes['code'] >= 200 && $sendRes['code'] < 300) {
        return ['ok' => true, 'message' => '✉️ E-Mail wurde erfolgreich direkt über deinen Provider versendet!'];
    }

    return ['ok' => false, 'message' => 'Fehler beim Senden: ' . $sendRes['resp']];
}
