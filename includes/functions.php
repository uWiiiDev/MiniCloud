<?php
declare(strict_types=1);

/**
 * MiniCloud - Allgemeine Hilfsfunktionen, Berechtigungs- & Datei-Logik
 */

require_once __DIR__ . '/../config/database.php';

/**
 * XSS-sicheres Escaping für HTML-Ausgaben.
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ermittelt die Basis-URL der Anwendung für Freigabelinks (z.B. https://ihredomain.de).
 */
function getBaseUrl(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $scheme = $isHttps ? 'https' : 'http';
    $rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_match('/^[a-zA-Z0-9.-]+(?::\d{1,5})?$/', $rawHost) ? $rawHost : 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}

/**
 * Formatiert eine Byte-Anzahl in eine menschenlesbare Einheit (B, KB, MB, GB).
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    $value = $bytes / (1024 ** $pow);

    return number_format($value, $pow === 0 ? 0 : $precision, ',', '.') . ' ' . $units[$pow];
}

/**
 * Wandelt PHP-INI-Größenangaben (z.B. "64M", "128K", "2G") in Bytes um.
 */
function parseIniSize(string $sizeStr): int
{
    $sizeStr = trim($sizeStr);
    if ($sizeStr === '' || $sizeStr === '-1') {
        return PHP_INT_MAX;
    }

    $unit = strtolower(substr($sizeStr, -1));
    $value = (int)$sizeStr;

    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}

/**
 * Ermittelt die aktuellen PHP-Upload-Limits des Servers (z.B. STRATO Standard-Limits).
 *
 * @return array{upload_max_filesize: int, post_max_size: int, max_file_uploads: int, effective_max_bytes: int}
 */
function getPhpUploadLimits(): array
{
    $uploadMax = parseIniSize((string)ini_get('upload_max_filesize'));
    $postMax   = parseIniSize((string)ini_get('post_max_size'));
    $maxFiles  = (int)(ini_get('max_file_uploads') ?: 20);

    return [
        'upload_max_filesize' => $uploadMax,
        'post_max_size'       => $postMax,
        'max_file_uploads'    => $maxFiles > 0 ? $maxFiles : 20,
        'effective_max_bytes' => min($uploadMax, $postMax),
    ];
}

/**
 * Ermittelt den aktuell belegten Speicherplatz und das Kontingent eines Benutzers.
 *
 * @return array{used: int, quota: int, free: int, percent: float, file_count: int, trash_count: int}
 */
function getUserStorageStats(PDO $pdo, int $userId): array
{
    $stmtQuota = $pdo->prepare('SELECT storage_quota FROM users WHERE id = :id LIMIT 1');
    $stmtQuota->execute([':id' => $userId]);
    $userRow = $stmtQuota->fetch();

    $quota = $userRow ? (int)$userRow['storage_quota'] : DEFAULT_STORAGE_QUOTA;
    if ($quota <= 0) {
        $quota = DEFAULT_STORAGE_QUOTA;
    }

    $stmtUsed = $pdo->prepare(
        'SELECT COALESCE(SUM(file_size), 0) AS total_used,
                SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS trash_count
         FROM files
         WHERE user_id = :user_id'
    );
    $stmtUsed->execute([':user_id' => $userId]);
    $statsRow = $stmtUsed->fetch();

    $used = (int)($statsRow['total_used'] ?? 0);
    $fileCount = (int)($statsRow['active_count'] ?? 0);
    $trashCount = (int)($statsRow['trash_count'] ?? 0);
    $free = max(0, $quota - $used);
    $percent = $quota > 0 ? min(100.0, round(($used / $quota) * 100, 1)) : 0.0;

    return [
        'used'        => $used,
        'quota'       => $quota,
        'free'        => $free,
        'percent'     => $percent,
        'file_count'  => $fileCount,
        'trash_count' => $trashCount,
    ];
}

/**
 * Liefert alle Gruppenordner zurück, auf die der Benutzer Zugriff hat (direkt, über Gruppe oder als Admin).
 *
 * @return array<int, array<string, mixed>>
 */
function getAccessibleGroupFolders(PDO $pdo, int $userId, bool $isAdmin = false): array
{
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT gf.id, gf.name, gf.description, 1 AS can_write,
                    COUNT(f.id) AS file_count, COALESCE(SUM(f.file_size), 0) AS folder_size
             FROM group_folders gf
             LEFT JOIN files f ON f.group_folder_id = gf.id AND f.deleted_at IS NULL
             GROUP BY gf.id, gf.name, gf.description
             ORDER BY gf.name ASC"
        );
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT gf.id, gf.name, gf.description, MAX(gfp.can_write) AS can_write,
                (SELECT COUNT(*) FROM files f WHERE f.group_folder_id = gf.id AND f.deleted_at IS NULL) AS file_count,
                (SELECT COALESCE(SUM(f.file_size), 0) FROM files f WHERE f.group_folder_id = gf.id AND f.deleted_at IS NULL) AS folder_size
         FROM group_folders gf
         INNER JOIN group_folder_permissions gfp ON gfp.group_folder_id = gf.id
         LEFT JOIN group_members gm ON gm.group_id = gfp.group_id AND gm.user_id = :uid_gm
         WHERE gfp.user_id = :uid_direct OR gm.user_id IS NOT NULL
         GROUP BY gf.id, gf.name, gf.description
         ORDER BY gf.name ASC"
    );
    $stmt->execute([
        ':uid_gm'     => $userId,
        ':uid_direct' => $userId,
    ]);
    return $stmt->fetchAll();
}

/**
 * Prüft, ob ein Benutzer Lese- bzw. Schreibzugriff auf einen bestimmten Gruppenordner hat.
 */
function canUserAccessGroupFolder(PDO $pdo, int $userId, int $groupFolderId, bool $requireWrite = false, bool $isAdmin = false): bool
{
    if ($groupFolderId <= 0) {
        return false;
    }

    $stmtExists = $pdo->prepare('SELECT id FROM group_folders WHERE id = :id LIMIT 1');
    $stmtExists->execute([':id' => $groupFolderId]);
    if (!$stmtExists->fetch()) {
        return false;
    }

    if ($isAdmin) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT MAX(gfp.can_write) AS max_write, COUNT(gfp.id) AS perm_count
         FROM group_folder_permissions gfp
         LEFT JOIN group_members gm ON gm.group_id = gfp.group_id AND gm.user_id = :uid_gm
         WHERE gfp.group_folder_id = :gf_id
           AND (gfp.user_id = :uid_direct OR gm.user_id IS NOT NULL)"
    );
    $stmt->execute([
        ':uid_gm'     => $userId,
        ':gf_id'      => $groupFolderId,
        ':uid_direct' => $userId,
    ]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['perm_count'] === 0) {
        return false;
    }

    if ($requireWrite) {
        return (int)$row['max_write'] === 1;
    }

    return true;
}

/**
 * Prüft, ob ein Benutzer berechtigt ist, eine Datei herunterzuladen
 * (Eigene Datei, direkt per user_shares geteilt oder in einem berechtigten Gruppenordner).
 *
 * @return array<string, mixed>|null
 */
function getAuthorizedFileForDownload(PDO $pdo, int $userId, int $fileId, bool $isAdmin = false): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch();

    if (!$file) {
        return null;
    }

    // 1. Eigene Datei
    if ((int)$file['user_id'] === $userId) {
        return $file;
    }

    // 2. Direkt mit diesem Cloud-Benutzer geteilt (user_shares - Einzeldatei)
    $stmtShared = $pdo->prepare(
        'SELECT id FROM user_shares WHERE file_id = :file_id AND shared_with_user_id = :uid LIMIT 1'
    );
    $stmtShared->execute([
        ':file_id' => $fileId,
        ':uid'     => $userId,
    ]);
    if ($stmtShared->fetch()) {
        return $file;
    }

    // 2b. Über einen freigegebenen Ordner mit diesem Cloud-Benutzer geteilt (user_shares - Ordner)
    $fileFolderPath = (string)($file['folder_path'] ?? '');
    if ($fileFolderPath !== '' && empty($file['group_folder_id'])) {
        $stmtFolderShares = $pdo->prepare(
            'SELECT folder_path FROM user_shares
             WHERE owner_id = :owner_id AND shared_with_user_id = :uid AND folder_path IS NOT NULL'
        );
        $stmtFolderShares->execute([
            ':owner_id' => (int)$file['user_id'],
            ':uid'      => $userId,
        ]);
        foreach ($stmtFolderShares->fetchAll(PDO::FETCH_COLUMN) as $sharedFp) {
            $sharedFpStr = (string)$sharedFp;
            if ($sharedFpStr !== '' && ($fileFolderPath === $sharedFpStr || str_starts_with($fileFolderPath, $sharedFpStr . '/'))) {
                return $file;
            }
        }
    }

    // 3. Datei liegt in einem Gruppenordner, auf den der Benutzer Zugriff hat
    if (!empty($file['group_folder_id'])) {
        $gfId = (int)$file['group_folder_id'];
        if (canUserAccessGroupFolder($pdo, $userId, $gfId, false, $isAdmin)) {
            return $file;
        }
    }

    return null;
}

/**
 * Bereinigt einen Original-Dateinamen von Pfadbestandteilen und gefährlichen Steuerzeichen.
 */
function sanitizeOriginalFilename(string $filename): string
{
    $filename = str_replace('\\', '/', $filename);
    $basename = basename($filename);
    $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?? '';
    $basename = trim($basename);

    if ($basename === '' || $basename === '.' || $basename === '..') {
        $basename = 'unbenannte_datei';
    }

    return mb_substr($basename, 0, 250, 'UTF-8');
}

/**
 * Extrahiert und bereinigt den relativen Ordnerpfad aus einer Pfadangabe.
 * Verhindert Path-Traversal ("..").
 */
function extractSanitizedFolderPath(string $relativePath): string
{
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || !str_contains($relativePath, '/')) {
        return '';
    }

    $dirPart = dirname($relativePath);
    if ($dirPart === '.' || $dirPart === '/' || $dirPart === '') {
        return '';
    }

    $segments = explode('/', $dirPart);
    $cleanSegments = [];

    foreach ($segments as $segment) {
        $segment = preg_replace('/[\x00-\x1F\x7F%]/u', '', $segment) ?? '';
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $cleanSegments[] = mb_substr($segment, 0, 100, 'UTF-8');
    }

    $folderPath = implode('/', $cleanSegments);
    return mb_substr($folderPath, 0, 500, 'UTF-8');
}

/**
 * Bereinigt einen direkt eingegebenen Ordnernamen oder Ordnerpfad (z.B. "Projekte/2026").
 */
function sanitizeFolderPathInput(string $folderInput): string
{
    $folderInput = trim($folderInput);
    if ($folderInput === '') {
        return '';
    }
    return extractSanitizedFolderPath($folderInput . '/placeholder');
}

/**
 * Trägt einen Ordnerpfad (inklusive aller übergeordneten Teilpfade) in die Tabelle user_folders ein.
 */
function registerFolderHierarchy(PDO $pdo, int $userId, string $folderPath, ?int $groupFolderId = null): void
{
    $cleanPath = sanitizeFolderPathInput($folderPath);
    if ($cleanPath === '') {
        return;
    }

    $segments = explode('/', $cleanPath);
    $accumulated = '';

    $stmtCheck = $pdo->prepare(
        'SELECT id FROM user_folders
         WHERE folder_path = :path
           AND (
               (:gf_id_null IS NULL AND group_folder_id IS NULL AND user_id = :uid)
               OR (:gf_id_val IS NOT NULL AND group_folder_id = :gf_id_match)
           )
         LIMIT 1'
    );

    $stmtInsert = $pdo->prepare(
        'INSERT INTO user_folders (user_id, group_folder_id, folder_path)
         VALUES (:uid, :gf_id, :path)'
    );

    foreach ($segments as $seg) {
        $accumulated = $accumulated === '' ? $seg : $accumulated . '/' . $seg;
        $stmtCheck->execute([
            ':path'        => $accumulated,
            ':gf_id_null'  => $groupFolderId,
            ':uid'         => $userId,
            ':gf_id_val'   => $groupFolderId,
            ':gf_id_match' => $groupFolderId,
        ]);
        if (!$stmtCheck->fetch()) {
            $stmtInsert->execute([
                ':uid'   => $userId,
                ':gf_id' => $groupFolderId,
                ':path'  => $accumulated,
            ]);
        }
    }
}

/**
 * Liefert alle Ordner (sowohl explizit angelegte leere Ordner als auch Ordner mit Dateien)
 * inklusive Dateianzahl und Gesamtgröße (inkl. Unterordnern).
 *
 * @return array<int, array{folder_path: string, file_count: int, folder_size: int}>
 */
function getMergedFoldersList(PDO $pdo, int $userId, ?int $groupFolderId = null): array
{
    $folderMap = [];

    // 1. Explizit angelegte Ordner aus user_folders laden
    if ($groupFolderId !== null && $groupFolderId > 0) {
        $stmtExplicit = $pdo->prepare(
            'SELECT DISTINCT folder_path FROM user_folders WHERE group_folder_id = :gf_id ORDER BY folder_path ASC'
        );
        $stmtExplicit->execute([':gf_id' => $groupFolderId]);
    } else {
        $stmtExplicit = $pdo->prepare(
            'SELECT DISTINCT folder_path FROM user_folders WHERE user_id = :uid AND group_folder_id IS NULL ORDER BY folder_path ASC'
        );
        $stmtExplicit->execute([':uid' => $userId]);
    }

    foreach ($stmtExplicit->fetchAll(PDO::FETCH_COLUMN) as $fp) {
        $fpStr = (string)$fp;
        if ($fpStr !== '') {
            $folderMap[$fpStr] = [
                'folder_path' => $fpStr,
                'file_count'  => 0,
                'folder_size' => 0,
            ];
        }
    }

    // 2. Ordner und Dateistatistiken aus files aggregieren (nur nicht gelöschte Dateien)
    if ($groupFolderId !== null && $groupFolderId > 0) {
        $stmtFiles = $pdo->prepare(
            "SELECT folder_path, COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
             FROM files
             WHERE group_folder_id = :gf_id AND deleted_at IS NULL AND folder_path <> ''
             GROUP BY folder_path"
        );
        $stmtFiles->execute([':gf_id' => $groupFolderId]);
    } else {
        $stmtFiles = $pdo->prepare(
            "SELECT folder_path, COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
             FROM files
             WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path <> ''
             GROUP BY folder_path"
        );
        $stmtFiles->execute([':uid' => $userId]);
    }

    $fileStatsRows = $stmtFiles->fetchAll();

    // Sicherstellen, dass auch alle Zwischenordner im Map existieren
    foreach ($fileStatsRows as $r) {
        $path = (string)$r['folder_path'];
        $parts = explode('/', $path);
        $acc = '';
        foreach ($parts as $p) {
            $acc = $acc === '' ? $p : $acc . '/' . $p;
            if (!isset($folderMap[$acc])) {
                $folderMap[$acc] = [
                    'folder_path' => $acc,
                    'file_count'  => 0,
                    'folder_size' => 0,
                ];
            }
        }
    }

    // Größen und Dateianzahl auf den Ordner selbst sowie seine Elternordner aufaddieren
    foreach ($fileStatsRows as $r) {
        $path = (string)$r['folder_path'];
        $cnt  = (int)$r['cnt'];
        $sz   = (int)$r['sz'];

        foreach ($folderMap as $fKey => &$fEntry) {
            if ($path === $fKey || str_starts_with($path, $fKey . '/')) {
                $fEntry['file_count']  += $cnt;
                $fEntry['folder_size'] += $sz;
            }
        }
        unset($fEntry);
    }

    ksort($folderMap, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($folderMap);
}

/**
 * Erzeugt einen eindeutigen, nicht ausführbaren Dateinamen für das /uploads/-Verzeichnis.
 */
function generateSafeStoredFilename(): string
{
    $uniquePrefix = str_replace('.', '_', uniqid('mc_', true));
    $randomSuffix = bin2hex(random_bytes(8));
    return $uniquePrefix . '_' . $randomSuffix . '.bin';
}

/**
 * Liefert eine darstellbare Kategorie / Farbkodierung anhand von Dateiname und MIME-Typ.
 *
 * @return array{label: string, bg: string, text: string}
 */
function getFileTypeBadge(string $originalName, string $mimeType): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (str_starts_with($mimeType, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true)) {
        return ['label' => strtoupper($ext ?: 'IMG'), 'bg' => 'bg-purple-100 dark:bg-purple-950/70', 'text' => 'text-purple-700 dark:text-purple-300'];
    }
    if ($mimeType === 'application/pdf' || $ext === 'pdf') {
        return ['label' => 'PDF', 'bg' => 'bg-red-100 dark:bg-red-950/70', 'text' => 'text-red-700 dark:text-red-300'];
    }
    if (in_array($ext, ['zip', 'tar', 'gz', '7z', 'rar'], true)) {
        return ['label' => strtoupper($ext), 'bg' => 'bg-amber-100 dark:bg-amber-950/70', 'text' => 'text-amber-800 dark:text-amber-300'];
    }
    if (in_array($ext, ['doc', 'docx', 'odt', 'txt', 'md', 'rtf'], true)) {
        return ['label' => strtoupper($ext ?: 'DOC'), 'bg' => 'bg-blue-100 dark:bg-blue-950/70', 'text' => 'text-blue-700 dark:text-blue-300'];
    }
    if (in_array($ext, ['xls', 'xlsx', 'csv', 'ods'], true)) {
        return ['label' => strtoupper($ext), 'bg' => 'bg-emerald-100 dark:bg-emerald-950/70', 'text' => 'text-emerald-700 dark:text-emerald-300'];
    }
    if (in_array($ext, ['mp4', 'mkv', 'mov', 'webm', 'mp3', 'wav', 'ogg'], true)) {
        return ['label' => strtoupper($ext), 'bg' => 'bg-pink-100 dark:bg-pink-950/70', 'text' => 'text-pink-700 dark:text-pink-300'];
    }
    if (in_array($ext, ['js', 'ts', 'json', 'html', 'css', 'php', 'py', 'sql', 'xml', 'yml', 'yaml'], true)) {
        return ['label' => strtoupper($ext), 'bg' => 'bg-slate-200 dark:bg-slate-700', 'text' => 'text-slate-800 dark:text-slate-200'];
    }

    $label = $ext !== '' ? strtoupper(mb_substr($ext, 0, 5)) : 'DATEI';
    return ['label' => $label, 'bg' => 'bg-gray-100 dark:bg-slate-800', 'text' => 'text-gray-700 dark:text-slate-300'];
}

/**
 * Übersetzt PHP-Upload-Fehlercodes in verständliche deutsche Fehlermeldungen.
 */
function getUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE   => 'Die Datei überschreitet das Server-Limit (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'Die Datei überschreitet das Formular-Limit (MAX_FILE_SIZE).',
        UPLOAD_ERR_PARTIAL    => 'Die Datei wurde nur teilweise hochgeladen.',
        UPLOAD_ERR_NO_FILE    => 'Es wurde keine Datei zum Hochladen ausgewählt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Serverfehler: Temporäres Upload-Verzeichnis fehlt.',
        UPLOAD_ERR_CANT_WRITE => 'Serverfehler: Datei konnte nicht auf die Festplatte geschrieben werden.',
        UPLOAD_ERR_EXTENSION  => 'Der Upload wurde durch eine PHP-Erweiterung gestoppt.',
        default               => 'Unbekannter Fehler beim Datei-Upload.',
    };
}

/**
 * Lädt die Systemeinstellungen (Anwendungstitel, Untertitel, Logo, Impressum, Absender-E-Mail) aus der Datenbank.
 *
 * @return array{app_title: string, app_subtitle: string, logo_data_uri: string, imprint_text: string, mail_from: string}
 */
function getAppSettings(?PDO $pdo = null): array
{
    $defaults = [
        'app_title'     => APP_NAME,
        'app_subtitle'  => 'Private Cloud',
        'logo_data_uri' => '',
        'imprint_text'  => '',
        'mail_from'     => '',
    ];

    try {
        $pdo = $pdo ?? getDB();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $k = (string)$row['setting_key'];
            $v = (string)($row['setting_value'] ?? '');
            if (array_key_exists($k, $defaults)) {
                $defaults[$k] = $v;
            }
        }
    } catch (Throwable) {
        // Fallback auf Standardwerte falls Datenbank noch nicht erreichbar
    }

    if (trim($defaults['app_title']) === '') {
        $defaults['app_title'] = APP_NAME;
    }

    return $defaults;
}

/**
 * Speichert eine einzelne Systemeinstellung in der Tabelle settings.
 */
function saveAppSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         VALUES (:k, :v_ins)
         ON DUPLICATE KEY UPDATE setting_value = :v_upd'
    );
    $stmt->execute([
        ':k'     => $key,
        ':v_ins' => $value,
        ':v_upd' => $value,
    ]);
}

/**
 * Maskiert eine E-Mail-Adresse für die Anzeige beim 2FA-Login (z.B. "u***e@beispiel.de").
 */
function maskEmailAddress(string $email): string
{
    $email = trim($email);
    if (!str_contains($email, '@')) {
        return '***';
    }
    [$local, $domain] = explode('@', $email, 2);
    $len = mb_strlen($local, 'UTF-8');
    if ($len <= 2) {
        $maskedLocal = mb_substr($local, 0, 1, 'UTF-8') . '***';
    } else {
        $maskedLocal = mb_substr($local, 0, 1, 'UTF-8') . '***' . mb_substr($local, -1, 1, 'UTF-8');
    }
    return $maskedLocal . '@' . $domain;
}

/**
 * Versendet den 6-stelligen 2FA-Sicherheitscode per E-Mail an den Benutzer.
 */
function sendTwoFactorEmail(PDO $pdo, string $toEmail, string $username, string $code): bool
{
    $toEmail = trim($toEmail);
    if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $settings = getAppSettings($pdo);
    $appTitle = $settings['app_title'] ?: APP_NAME;

    $rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $domain  = preg_replace('/:\d+$/', '', $rawHost) ?: 'localhost';
    if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
        $hostname = trim((string)(gethostname() ?: ''));
        if ($hostname !== '' && str_contains($hostname, '.')) {
            $domain = $hostname;
        } else {
            $domain = 'runready.cloud';
        }
    }

    $configuredFrom = trim($settings['mail_from']);
    $fromEmail = (filter_var($configuredFrom, FILTER_VALIDATE_EMAIL) !== false)
        ? $configuredFrom
        : ('noreply@' . $domain);

    $cleanTitle = preg_replace('/[\r\n"]/', '', $appTitle) ?: APP_NAME;
    $encodedFromName = '=?UTF-8?B?' . base64_encode($cleanTitle) . '?=';
    $subject = '=?UTF-8?B?' . base64_encode(sprintf('[%s] Ihr 2FA-Anmeldecode: %s', $cleanTitle, $code)) . '?=';

    $body = implode("\r\n", [
        sprintf('Hallo %s,', $username),
        '',
        sprintf('für Ihre Anmeldung bei %s wurde ein einmaliger Bestätigungscode angefordert:', $cleanTitle),
        '',
        sprintf('    %s', $code),
        '',
        'Dieser Sicherheitscode ist 10 Minuten lang gültig.',
        '',
        'Falls Sie diese Anmeldung nicht selbst gestartet haben, ändern Sie bitte zeitnah Ihr Passwort.',
        '',
        '--',
        $cleanTitle . ' (' . getBaseUrl() . ')',
    ]);

    $headers = [
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: MiniCloud-2FA',
    ];
    $headerStr = implode("\r\n", $headers);

    $sent = @mail($toEmail, $subject, $body, $headerStr, '-f ' . escapeshellarg($fromEmail));
    if (!$sent) {
        $sent = @mail($toEmail, $subject, $body, $headerStr);
    }

    return (bool)$sent;
}

/**
 * Ermittelt vollautomatisch die aktuelle Anwendungs-Versionsnummer anhand des jüngsten
 * Änderungszeitpunkts und Fingerabdrucks aller PHP-Projektdateien (Option 1: Zero-Touch).
 *
 * @return array{version: string, build_date: string, hash: string, tooltip: string}
 */
function getAppVersionInfo(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $rootDir = dirname(__DIR__);
    $patterns = [
        $rootDir . '/*.php',
        $rootDir . '/includes/*.php',
        $rootDir . '/config/*.php',
    ];

    $latestMtime = 0;
    $fingerprintParts = [];

    foreach ($patterns as $pattern) {
        $files = glob($pattern) ?: [];
        foreach ($files as $file) {
            $mtime = (int)(@filemtime($file) ?: 0);
            $size  = (int)(@filesize($file) ?: 0);
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
            $fingerprintParts[] = basename($file) . ':' . $mtime . ':' . $size;
        }
    }

    if ($latestMtime <= 0) {
        $latestMtime = time();
    }

    sort($fingerprintParts);
    $shortHash = substr(hash('sha256', implode('|', $fingerprintParts)), 0, 6);
    $tz = new DateTimeZone('Europe/Berlin');
    $dt = (new DateTimeImmutable('@' . $latestMtime))->setTimezone($tz);
    $version   = 'v1.5.' . $dt->format('Ymd.Hi');
    $buildDate = $dt->format('d.m.Y H:i');

    $cached = [
        'version'    => $version,
        'build_date' => $buildDate,
        'hash'       => $shortHash,
        'tooltip'    => sprintf('Automatische Version %s • Stand: %s Uhr (Build #%s)', $version, $buildDate, $shortHash),
    ];

    return $cached;
}
